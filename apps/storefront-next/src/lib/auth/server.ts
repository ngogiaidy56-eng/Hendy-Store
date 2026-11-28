import { createNeonAuth } from "@neon/auth/next/server";

let cachedAuth: ReturnType<typeof createNeonAuth> | null = null;

/** Lazy initialization keeps the optional app's Docker build secret-free. */
export function getNeonAuth(): ReturnType<typeof createNeonAuth> {
  const baseUrl = process.env.NEON_AUTH_BASE_URL?.trim();
  const cookieSecret = process.env.NEON_AUTH_COOKIE_SECRET?.trim();
  if (!baseUrl || !cookieSecret || cookieSecret.length < 32) {
    throw new Error("NEON_AUTH_BASE_URL and a 32+ character NEON_AUTH_COOKIE_SECRET are required.");
  }
  if (!baseUrl.startsWith("https://")) throw new Error("NEON_AUTH_BASE_URL must use HTTPS.");
  if (cachedAuth) return cachedAuth;
  cachedAuth = createNeonAuth({ baseUrl, cookies: { secret: cookieSecret } });
  return cachedAuth;
}
