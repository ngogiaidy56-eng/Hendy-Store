export interface Env {
  /** HTTPS URL of the existing PHP API origin; Neon remains behind this origin. */
  HENDY_API_ORIGIN: string;
  ALLOWED_ORIGINS?: string;
  ALLOWED_ORIGIN?: string;
  /** "origin" probes the PHP/Neon API. "d1" probes the separate Worker-local D1 database. */
  DATABASE_PROVIDER?: "origin" | "d1";
  DB?: D1Database;
  R2?: R2Bucket;
  CACHE?: KVNamespace;

  OBJECT_STORAGE_PROVIDER?: "neon" | "cloudfly" | "r2" | "s3";
  OBJECT_STORAGE_ENDPOINT?: string;
  OBJECT_STORAGE_REGION?: string;
  OBJECT_STORAGE_JURISDICTION?: "eu" | "us" | "fedramp" | "";
  OBJECT_STORAGE_BUCKET?: string;
  OBJECT_STORAGE_ACCESS_KEY_ID?: string;
  OBJECT_STORAGE_SECRET_ACCESS_KEY?: string;
  OBJECT_STORAGE_SESSION_TOKEN?: string;
  CLOUDFLARE_ACCOUNT_ID?: string;
  OBJECT_STORAGE_FORCE_PATH_STYLE?: string;

  /** Optional server-side token for internal monitoring; never returned to clients. */
  PLATFORM_HEALTH_TOKEN?: string;
}
