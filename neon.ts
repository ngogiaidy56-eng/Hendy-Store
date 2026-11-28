import { defineConfig } from "@neon/config/v1";

const defaultRenderOrigin = "https://hendy-store.onrender.com";

function httpsOrigin(value: string | undefined, fallback: string): string {
  try {
    const candidate = new URL(value || fallback);
    if (candidate.protocol === "https:") return candidate.origin;
  } catch {
    // APP_URL=auto is a Render sentinel, not a URL.
  }
  return fallback;
}

const renderOrigin = httpsOrigin(process.env.RENDER_EXTERNAL_URL, defaultRenderOrigin);
const appUrl = (process.env.APP_URL || "").trim();
const primaryOrigin = appUrl !== "" && appUrl.toLowerCase() !== "auto"
  ? httpsOrigin(appUrl, renderOrigin)
  : renderOrigin;
const allowedOrigins = new Set<string>([primaryOrigin]);
for (const value of (process.env.APP_ALLOWED_ORIGINS || "").split(",")) {
  const origin = httpsOrigin(value.trim(), "");
  if (origin) allowedOrigins.add(origin);
}

export default defineConfig({
  auth: true,
  aiGateway: true,
  buckets: {
    "hendy-store-files": { access: "private" },
  },
  functions: {
    storeapi: {
      name: "Hendy Store health and live catalog",
      source: "./neon/functions/storeapi.ts",
      env: {
        NEON_REALTIME_SECRET: {
          value: process.env.NEON_REALTIME_SECRET,
          required: true,
        },
        APP_ALLOWED_ORIGINS: {
          value: process.env.APP_ALLOWED_ORIGINS,
          required: false,
        },
      },
    },
  },
  preview: {
    realtime: {
      allowedOrigins: [...allowedOrigins],
    },
  },
});
