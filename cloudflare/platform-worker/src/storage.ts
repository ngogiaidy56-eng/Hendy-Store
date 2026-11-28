import {
  HeadBucketCommand,
  PutObjectCommand,
  S3Client
} from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";
import type { Env } from "./types.js";

export type StorageProvider = "neon" | "cloudfly" | "r2" | "s3";

export function resolveStorageProvider(env: Env): StorageProvider {
  const provider = String(env.OBJECT_STORAGE_PROVIDER || "neon").toLowerCase();
  if (["neon", "cloudfly", "r2", "s3"].includes(provider)) return provider as StorageProvider;
  throw new Error("Unsupported OBJECT_STORAGE_PROVIDER.");
}

function s3Options(env: Env): { endpoint: string; region: string; bucket: string; accessKeyId: string; secretAccessKey: string; sessionToken?: string; pathStyle: boolean } {
  const provider = resolveStorageProvider(env);
  const rawEndpoint = String(env.OBJECT_STORAGE_ENDPOINT || "");
  let endpoint = rawEndpoint.endsWith("/") ? rawEndpoint.slice(0, -1) : rawEndpoint;
  let region = String(env.OBJECT_STORAGE_REGION || "");
  if (provider === "cloudfly") {
    endpoint ||= "https://s3.cloudfly.vn";
    region ||= "hn";
  } else if (provider === "r2") {
    const accountId = String(env.CLOUDFLARE_ACCOUNT_ID || "");
    const jurisdiction = String(env.OBJECT_STORAGE_JURISDICTION || "").toLowerCase();
    const suffix = jurisdiction === "eu" ? ".eu" : jurisdiction === "us" ? ".us" : jurisdiction === "fedramp" ? ".fedramp" : "";
    endpoint ||= accountId ? `https://${accountId}${suffix}.r2.cloudflarestorage.com` : "";
    region ||= "auto";
  } else if (provider === "s3") {
    region ||= "us-east-1";
  }
  const bucket = String(env.OBJECT_STORAGE_BUCKET || "");
  const accessKeyId = String(env.OBJECT_STORAGE_ACCESS_KEY_ID || "");
  const secretAccessKey = String(env.OBJECT_STORAGE_SECRET_ACCESS_KEY || "");
  if (!endpoint.startsWith("https://") || !bucket || !accessKeyId || !secretAccessKey || !region) {
    throw new Error("Selected S3 storage provider is missing endpoint, bucket or credentials.");
  }
  const url = new URL(endpoint);
  if (url.username || url.password || url.search || url.hash || url.pathname !== "/") {
    throw new Error("Storage endpoint must be an HTTPS origin only.");
  }
  return {
    endpoint,
    region,
    bucket,
    accessKeyId,
    secretAccessKey,
    sessionToken: env.OBJECT_STORAGE_SESSION_TOKEN,
    pathStyle: String(env.OBJECT_STORAGE_FORCE_PATH_STYLE || "true") === "true"
  };
}

export function createStorageClient(env: Env): { client: S3Client; bucket: string } {
  const config = s3Options(env);
  return {
    bucket: config.bucket,
    client: new S3Client({
      region: config.region,
      endpoint: config.endpoint,
      forcePathStyle: config.pathStyle,
      credentials: {
        accessKeyId: config.accessKeyId,
        secretAccessKey: config.secretAccessKey,
        ...(config.sessionToken ? { sessionToken: config.sessionToken } : {})
      }
    })
  };
}

export async function probeStorage(env: Env): Promise<{ provider: StorageProvider; ok: boolean }> {
  const provider = resolveStorageProvider(env);
  if (provider === "neon") {
    // Neon is probed by the PHP origin so the check uses its real server-side secrets.
    const rawOrigin = String(env.HENDY_API_ORIGIN || "");
    const origin = rawOrigin.endsWith("/") ? rawOrigin.slice(0, -1) : rawOrigin;
    if (!origin.startsWith("https://")) return { provider, ok: false };
    const response = await fetch(origin + "/api.php?action=health", { headers: { Accept: "application/json" } });
    if (!response.ok) return { provider, ok: false };
    const health = await response.json() as { storage?: string };
    return { provider, ok: Boolean(health.storage && health.storage !== "unavailable") };
  }
  if (provider === "r2" && env.R2) {
    // R2 binding confirms the bucket is attached to this Worker. Use S3 credentials
    // separately when a browser needs a presigned PUT URL.
    await env.R2.head("__hendy_storage_probe__");
    return { provider, ok: true };
  }
  const { client, bucket } = createStorageClient(env);
  await client.send(new HeadBucketCommand({ Bucket: bucket }));
  return { provider, ok: true };
}

/**
 * Server-only presigned PUT helper. The API route that calls this function
 * must authenticate the uploader and authorize the object key before signing.
 */
export async function createPresignedPutUrl(env: Env, key: string, contentType: string, expiresIn = 300): Promise<string> {
  if (!/^(media|packages)\/[a-f0-9]{32}\.[a-z0-9]{2,8}$/i.test(key)) {
    throw new Error("Invalid private storage object key.");
  }
  const safeType = /^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i.test(contentType)
    ? contentType
    : "application/octet-stream";
  const { client, bucket } = createStorageClient(env);
  return getSignedUrl(client, new PutObjectCommand({
    Bucket: bucket,
    Key: key,
    ContentType: safeType
  }), { expiresIn: Math.min(900, Math.max(60, expiresIn)) });
}