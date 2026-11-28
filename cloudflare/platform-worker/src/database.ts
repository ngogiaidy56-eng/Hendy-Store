import type { Env } from "./types.js";

export type DatabaseProvider = "origin" | "d1";

export function resolveDatabaseProvider(env: Env): DatabaseProvider {
  const configured = String(env.DATABASE_PROVIDER || "origin").toLowerCase();
  if (configured === "origin") return "origin";
  if (configured === "d1" && env.DB) return "d1";
  throw new Error("DATABASE_PROVIDER must be origin or a configured D1 binding.");
}

export async function probeDatabase(env: Env): Promise<{ provider: DatabaseProvider; ok: boolean }> {
  const provider = resolveDatabaseProvider(env);
  if (provider === "d1") {
    await env.DB!.prepare("SELECT 1 AS ok").first();
    return { provider, ok: true };
  }

  // The Worker does not connect directly to Neon. The established PHP origin
  // owns Neon credentials, queries, sessions, schema and business logic.
  const rawOrigin = String(env.HENDY_API_ORIGIN || "");
  const origin = rawOrigin.endsWith("/") ? rawOrigin.slice(0, -1) : rawOrigin;
  if (!origin.startsWith("https://")) return { provider, ok: false };
  try {
    const response = await fetch(origin + "/api.php?action=health", {
      headers: { Accept: "application/json" },
      redirect: "manual"
    });
    const health = await response.json() as {
      database?: string;
      components?: { database?: string };
    };
    return {
      provider,
      // A storage-only 503 must not be misreported as a Neon database failure.
      ok: health.database === "postgresql" || health.components?.database === "healthy"
    };
  } catch {
    return { provider, ok: false };
  }
}
