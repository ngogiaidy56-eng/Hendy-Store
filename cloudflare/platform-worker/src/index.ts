import type { Env } from "./types.js";
import { probeDatabase, resolveDatabaseProvider } from "./database.js";
import { probeStorage, resolveStorageProvider } from "./storage.js";

function json(data: unknown, status = 200, headers: HeadersInit = {}): Response {
  const resultHeaders = new Headers(headers);
  resultHeaders.set("Content-Type", "application/json; charset=utf-8");
  resultHeaders.set("Cache-Control", "no-store, private");
  resultHeaders.set("X-Content-Type-Options", "nosniff");
  return new Response(JSON.stringify(data), { status, headers: resultHeaders });
}

function allowedOrigin(request: Request, env: Env): string {
  const origin = request.headers.get("Origin") || "";
  const allowed = String(env.ALLOWED_ORIGINS || env.ALLOWED_ORIGIN || "")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);
  return origin && allowed.includes(origin) ? origin : "";
}

function withCors(request: Request, env: Env, response: Response): Response {
  const origin = allowedOrigin(request, env);
  if (!origin) return response;
  const headers = new Headers(response.headers);
  headers.set("Access-Control-Allow-Origin", origin);
  headers.set("Access-Control-Allow-Credentials", "true");
  headers.set("Vary", "Origin");
  headers.set("Access-Control-Allow-Methods", "GET,POST,PUT,DELETE,OPTIONS");
  headers.set("Access-Control-Allow-Headers", "Authorization,Content-Type,X-CSRF-Token");
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

async function proxyToPhp(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
  const rawOrigin = String(env.HENDY_API_ORIGIN || "");
  const origin = rawOrigin.endsWith("/") ? rawOrigin.slice(0, -1) : rawOrigin;
  if (!origin.startsWith("https://")) return json({ ok: false, error: "HENDY_API_ORIGIN must be an HTTPS origin." }, 503);
  const incoming = new URL(request.url);
  const target = new URL(origin + incoming.pathname + incoming.search);
  const isPublicCatalog = request.method === "GET"
    && incoming.pathname === "/api.php"
    && incoming.searchParams.get("action") === "products"
    && !request.headers.has("Authorization")
    && !request.headers.has("Cookie");
  const cacheKey = "public-catalog:" + incoming.search;
  if (isPublicCatalog && env.CACHE) {
    const cached = await env.CACHE.get(cacheKey);
    if (cached !== null) {
      return new Response(cached, {
        status: 200,
        headers: {
          "Content-Type": "application/json; charset=utf-8",
          "Cache-Control": "public, max-age=15, s-maxage=30",
          "X-Content-Type-Options": "nosniff",
          "X-Hendy-Edge-Cache": "HIT"
        }
      });
    }
  }
  const headers = new Headers(request.headers);
  headers.set("X-Forwarded-Host", incoming.host);
  headers.set("X-Forwarded-Proto", "https");
  headers.delete("host");
  const upstream = await fetch(new Request(target, {
    method: request.method,
    headers,
    body: ["GET", "HEAD"].includes(request.method) ? undefined : request.body,
    redirect: "manual"
  }));
  const response = new Response(upstream.body, upstream);
  response.headers.set("Cache-Control", "no-store, private");
  response.headers.set("X-Content-Type-Options", "nosniff");
  if (isPublicCatalog && env.CACHE && response.ok) {
    try {
      const payload = await response.clone().json() as { ok?: boolean };
      if (payload?.ok === true) {
        ctx.waitUntil(env.CACHE!.put(cacheKey, JSON.stringify(payload), { expirationTtl: 30 }));
        response.headers.set("Cache-Control", "public, max-age=15, s-maxage=30");
        response.headers.set("X-Hendy-Edge-Cache", "MISS");
      }
    } catch {
      // Invalid or unexpected origin payloads remain private and are not cached.
    }
  }
  return response;
}

async function platformHealth(env: Env): Promise<Response> {
  const databaseProvider = resolveDatabaseProvider(env);
  const storageProvider = resolveStorageProvider(env);
  const checks: Record<string, unknown> = {
    databaseProvider,
    storageProvider,
    bindings: {
      r2: Boolean(env.R2),
      kv: Boolean(env.CACHE),
      d1: Boolean(env.DB)
    }
  };

  try {
    checks.database = await probeDatabase(env);
  } catch {
    checks.database = { provider: databaseProvider, ok: false };
  }

  if (env.DB) {
    try {
      await env.DB.prepare("SELECT 1 AS ok").first();
      checks.d1 = { provider: "d1", ok: true, readOnly: true };
    } catch {
      checks.d1 = { provider: "d1", ok: false, readOnly: true };
    }
  } else {
    checks.d1 = { provider: "d1", ok: false, error: "binding_missing" };
  }

  if (env.CACHE) {
    try {
      await env.CACHE.get("__hendy_health__/read-only-probe");
      checks.kv = { provider: "kv", ok: true, readOnly: true };
    } catch {
      checks.kv = { provider: "kv", ok: false, readOnly: true };
    }
  } else {
    checks.kv = { provider: "kv", ok: false, error: "binding_missing" };
  }

  if (storageProvider === "r2" && env.R2) {
    try {
      await env.R2.head("__hendy_health__/read-only-probe");
      checks.storage = { provider: "r2", ok: true, binding: true, readOnly: true };
    } catch {
      checks.storage = { provider: "r2", ok: false, binding: true, readOnly: true };
    }
  } else if (storageProvider === "r2") {
    checks.storage = { provider: "r2", ok: false, error: "binding_missing" };
  } else {
    try {
      checks.storage = await probeStorage(env);
    } catch {
      checks.storage = { provider: storageProvider, ok: false, readOnly: true };
    }
  }

  const databaseOk = Boolean((checks.database as { ok?: boolean })?.ok);
  const storageOk = Boolean((checks.storage as { ok?: boolean })?.ok);
  return json({
    ok: databaseOk && storageOk,
    service: "hendy-store-platform-worker",
    mode: "read-only",
    checks
  }, databaseOk && storageOk ? 200 : 503);
}

function constantTimeEquals(left: string, right: string): boolean {
  if (!left || left.length !== right.length) return false;
  let mismatch = 0;
  for (let index = 0; index < left.length; index += 1) {
    mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
  }
  return mismatch === 0;
}

async function platformWriteProbe(env: Env): Promise<Response> {
  const checks: Record<string, unknown> = {};
  try {
    checks.database = await probeDatabase(env);
  } catch {
    checks.database = { provider: resolveDatabaseProvider(env), ok: false };
  }

  if (env.DB) {
    try {
      await env.DB.prepare("SELECT 1 AS ok").first();
      checks.d1 = { provider: "d1", ok: true, readOnly: true };
    } catch {
      checks.d1 = { provider: "d1", ok: false, readOnly: true };
    }
  } else {
    checks.d1 = { provider: "d1", ok: false, error: "binding_missing" };
  }

  if (env.CACHE) {
    const key = "__hendy_health__/" + crypto.randomUUID();
    let roundTrip = false;
    let cleanup = false;
    try {
      await env.CACHE.put(key, "hendy-kv-health-probe", { expirationTtl: 60 });
      roundTrip = (await env.CACHE.get(key)) === "hendy-kv-health-probe";
    } catch {
      roundTrip = false;
    } finally {
      try {
        await env.CACHE.delete(key);
        cleanup = true;
      } catch {
        cleanup = false;
      }
    }
    checks.kv = { provider: "kv", ok: roundTrip && cleanup, roundTrip, cleanup };
  } else {
    checks.kv = { provider: "kv", ok: false, error: "binding_missing" };
  }

  if (env.R2) {
    const key = "__hendy_health__/" + crypto.randomUUID() + ".txt";
    let roundTrip = false;
    let cleanup = false;
    try {
      await env.R2.put(key, "hendy-r2-health-probe", {
        httpMetadata: { contentType: "text/plain; charset=utf-8" }
      });
      const object = await env.R2.get(key);
      roundTrip = Boolean(object && (await object.text()) === "hendy-r2-health-probe");
    } catch {
      roundTrip = false;
    } finally {
      try {
        await env.R2.delete(key);
        cleanup = true;
      } catch {
        cleanup = false;
      }
    }
    checks.storage = { provider: "r2", ok: roundTrip && cleanup, roundTrip, cleanup };
  } else {
    checks.storage = { provider: "r2", ok: false, error: "binding_missing" };
  }

  const healthy = Boolean(
    (checks.database as { ok?: boolean })?.ok &&
    (checks.d1 as { ok?: boolean })?.ok &&
    (checks.kv as { ok?: boolean })?.ok &&
    (checks.storage as { ok?: boolean })?.ok
  );
  return json({
    ok: healthy,
    service: "hendy-store-platform-worker",
    mode: "authenticated-write-probe",
    checks
  }, healthy ? 200 : 503);
}

export default {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const url = new URL(request.url);
    if (request.method === "OPTIONS") {
      return withCors(request, env, new Response(null, { status: 204 }));
    }
    if (url.pathname === "/api/v2/platform/health" && request.method === "GET") {
      return withCors(request, env, await platformHealth(env));
    }
    if (url.pathname === "/api/v2/platform/probe" && request.method === "POST") {
      const expected = String(env.PLATFORM_HEALTH_TOKEN || "");
      const supplied = String(request.headers.get("Authorization") || "").replace(/^Bearer\s+/i, "");
      if (!expected) {
        return withCors(request, env, json({ ok: false, error: "Protected write probe is not configured." }, 503));
      }
      if (!constantTimeEquals(supplied, expected)) {
        return withCors(request, env, json({ ok: false, error: "Unauthorized." }, 401));
      }
      return withCors(request, env, await platformWriteProbe(env));
    }
    if (url.pathname === "/api/v2/platform/capabilities" && request.method === "GET") {
      return withCors(request, env, json({
        ok: true,
        databaseProviders: ["origin", "d1"],
        configuredDatabaseProvider: env.DATABASE_PROVIDER || "origin",
        storageProviders: ["neon", "cloudfly", "r2", "s3"],
        configuredStorageProvider: env.OBJECT_STORAGE_PROVIDER || "neon",
        bindings: {
          d1: Boolean(env.DB),
          r2: Boolean(env.R2),
          kv: Boolean(env.CACHE),
        }
      }));
    }

    // Preserve the existing PHP business API and its authentication/CSRF
    // checks while the new Workers adapter is introduced incrementally.
    if (url.pathname === "/api.php" || url.pathname.startsWith("/api.php/")) {
      try {
        return withCors(request, env, await proxyToPhp(request, env, ctx));
      } catch {
        return withCors(request, env, json({ ok: false, error: "Origin API is temporarily unavailable." }, 502));
      }
    }

    return json({ ok: false, error: "Route not found." }, 404);
  }
};