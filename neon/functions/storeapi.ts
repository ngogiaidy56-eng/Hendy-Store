import { createRealtime, rawSql } from "@neon/realtime/server";
import { HeadBucketCommand, S3Client } from "@aws-sdk/client-s3";
import { Pool } from "pg";

const databaseUrl = process.env.DATABASE_URL;
const bucket = process.env.NEON_STORAGE_BUCKET || "hendy-store-files";
const authBaseUrl = process.env.NEON_AUTH_BASE_URL || "";
const aiBaseUrl = process.env.NEON_AI_GATEWAY_BASE_URL || "";
const aiToken = process.env.NEON_AI_GATEWAY_TOKEN || "";
const realtimeSecret = process.env.NEON_REALTIME_SECRET || "";
const appUrl = process.env.APP_URL || "";

if (!databaseUrl) throw new Error("DATABASE_URL was not injected by Neon Functions.");
if (!realtimeSecret) throw new Error("NEON_REALTIME_SECRET was not injected by Neon.");

const parsedDbUrl = new URL(databaseUrl);
const databaseName = decodeURIComponent(parsedDbUrl.pathname.replace(/^\//, ""));
const pool = new Pool({
  connectionString: databaseUrl,
  max: 3,
  idleTimeoutMillis: 10000,
  connectionTimeoutMillis: 5000,
});
const realtime = createRealtime({ secret: realtimeSecret, db: databaseName });
const storage = new S3Client({
  region: process.env.AWS_REGION || "us-east-2",
  endpoint: process.env.AWS_ENDPOINT_URL_S3,
  forcePathStyle: (process.env.NEON_STORAGE_FORCE_PATH_STYLE || "true").toLowerCase() !== "false",
});

const allowedOrigins = new Set(
  (process.env.APP_ALLOWED_ORIGINS || "https://hendystore.onrender.com,https://hendy-store.onrender.com,https://hendystore-pages.pages.dev")
    .split(",")
    .map((value) => {
      try { return new URL(value.trim()).origin; } catch { return ""; }
    })
    .filter(Boolean),
);

const headersFor = (origin: string | null): HeadersInit => {
  const acceptedOrigin = origin && allowedOrigins.has(origin) ? origin : null;
  return {
    ...(acceptedOrigin ? { "Access-Control-Allow-Origin": acceptedOrigin } : {}),
    "Vary": "Origin",
    "Access-Control-Allow-Methods": "GET, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Cache-Control": "no-store",
    "Content-Type": "application/json; charset=utf-8",
  };
};

async function sealCatalog() {
  return realtime.seal({
    query: rawSql(
      'SELECT id, slug, title, developer, category, short_description AS "shortDescription", version, platform, price_type AS "priceType", price_amount_vnd AS "priceAmountVnd", icon, cover, downloads, rating, featured FROM products WHERE status = $1 ORDER BY featured DESC, downloads DESC, rating DESC LIMIT 100',
      ["published"],
    ),
  });
}

async function checkHealth() {
  const checks: Record<string, boolean> = {
    postgres: false,
    objectStorage: false,
    auth: false,
    aiGateway: false,
    realtime: false,
  };
  const errors: string[] = [];

  try {
    await pool.query("SELECT 1");
    checks.postgres = true;
  } catch {
    errors.push("postgres");
  }

  try {
    if (!process.env.AWS_ENDPOINT_URL_S3 || !process.env.AWS_ACCESS_KEY_ID || !process.env.AWS_SECRET_ACCESS_KEY) {
      throw new Error("missing object storage credentials");
    }
    await storage.send(new HeadBucketCommand({ Bucket: bucket }));
    checks.objectStorage = true;
  } catch {
    errors.push("objectStorage");
  }

  try {
    if (!authBaseUrl) throw new Error("missing auth URL");
    const response = await fetch(authBaseUrl.replace(/\/$/, "") + "/api/auth/get-session", {
      method: "GET",
      signal: AbortSignal.timeout(8000),
      headers: { Accept: "application/json" },
    });
    checks.auth = response.status < 500;
  } catch {
    errors.push("auth");
  }

  try {
    if (!aiBaseUrl || !aiToken) throw new Error("missing AI Gateway credentials");
    const response = await fetch(aiBaseUrl.replace(/\/$/, "") + "/v1/models", {
      headers: { Authorization: "Bearer " + aiToken, Accept: "application/json" },
      signal: AbortSignal.timeout(10000),
    });
    checks.aiGateway = response.ok;
  } catch {
    errors.push("aiGateway");
  }

  try {
    await sealCatalog();
    checks.realtime = true;
  } catch {
    errors.push("realtime");
  }

  return {
    ok: checks.postgres && checks.objectStorage && checks.auth && checks.realtime,\n    warnings: checks.aiGateway ? [] : ["aiGateway_unavailable_or_credits_not_configured"],
    service: "hendy-store-neon",
    region: process.env.AWS_REGION || "us-east-2",
    checks,
    errors,
  };
}

export default {
  async fetch(request: Request): Promise<Response> {
    const origin = request.headers.get("Origin");
    const headers = headersFor(origin);
    if (request.method === "OPTIONS") return new Response(null, { status: 204, headers });

    const path = new URL(request.url).pathname.replace(/\/$/, "");
    try {
      if (path.endsWith("/health") || path === "") {
        const health = await checkHealth();
        return new Response(JSON.stringify(health), { status: health.ok ? 200 : 503, headers });
      }
      if (path.endsWith("/catalog-live")) {
        const sealed = await sealCatalog();
        return new Response(JSON.stringify({ sealed }), { status: 200, headers });
      }
      return new Response(JSON.stringify({ ok: false, error: "Not found" }), { status: 404, headers });
    } catch (error) {
      console.error("[Hendy Store Function] request failed:", error instanceof Error ? error.name : "unknown");
      return new Response(JSON.stringify({ ok: false, error: "Neon Store Function failed." }), { status: 503, headers });
    }
  },
};
