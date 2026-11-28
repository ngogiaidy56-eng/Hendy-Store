import type { NextRequest } from "next/server";

async function proxy(request: NextRequest): Promise<Response> {
  const origin = String(process.env.HENDY_API_ORIGIN || "").replace(/\/$/, "");
  if (!origin.startsWith("https://")) {
    return Response.json({ ok: false, error: "HENDY_API_ORIGIN must be configured." }, { status: 503 });
  }
  const target = new URL(origin + "/api.php" + request.nextUrl.search);
  const headers = new Headers(request.headers);
  headers.set("X-Forwarded-Host", request.nextUrl.host);
  headers.set("X-Forwarded-Proto", "https");
  headers.delete("host");
  const upstream = await fetch(new Request(target, {
    method: request.method,
    headers,
    body: ["GET", "HEAD"].includes(request.method) ? undefined : new Uint8Array(await request.arrayBuffer()),
    redirect: "manual"
  }));
  const response = new Response(upstream.body, upstream);
  response.headers.set("Cache-Control", "no-store, private");
  response.headers.set("X-Content-Type-Options", "nosniff");
  return response;
}

export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
