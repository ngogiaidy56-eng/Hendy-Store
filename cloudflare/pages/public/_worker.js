export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (env.ASSETS) {
      const asset = await env.ASSETS.fetch(request);
      if (asset.status !== 404) return asset;
    }
    const origin = String(env.ORIGIN_URL || "").replace(/\/$/, "");
    if (!origin || !/^https:\/\//i.test(origin)) return new Response("Set ORIGIN_URL to an HTTPS PHP origin", {status:503});
    const headers = new Headers(request.headers);
    headers.set("X-Forwarded-Host", url.host);
    headers.set("X-Forwarded-Proto", "https");
    headers.delete("host");
    const upstream = await fetch(new Request(origin + url.pathname + url.search, {
      method: request.method, headers,
      body: ["GET","HEAD"].includes(request.method) ? undefined : request.body,
      redirect: "manual"
    }));
    const response = new Response(upstream.body, upstream);
    response.headers.set("X-Content-Type-Options","nosniff");
    response.headers.set("Referrer-Policy","strict-origin-when-cross-origin");
    if (url.pathname.startsWith("/api.php")) response.headers.set("Cache-Control","no-store");
    return response;
  }
};
