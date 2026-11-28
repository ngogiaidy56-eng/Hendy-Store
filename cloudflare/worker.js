export default {
  async fetch(request, env) {
    const origin = String(env.ORIGIN_URL || "").replace(/\/$/, "");
    if (!origin || !/^https:\/\//i.test(origin)) return new Response("Set ORIGIN_URL to an HTTPS PHP origin", {status:503});
    const incoming = new URL(request.url);
    const headers = new Headers(request.headers);
    headers.set("X-Forwarded-Host", incoming.host);
    headers.set("X-Forwarded-Proto", "https");
    headers.delete("host");
    const upstream = await fetch(new Request(origin + incoming.pathname + incoming.search, {
      method: request.method, headers,
      body: ["GET","HEAD"].includes(request.method) ? undefined : request.body,
      redirect: "manual"
    }));
    const response = new Response(upstream.body, upstream);
    response.headers.set("X-Content-Type-Options","nosniff");
    response.headers.set("Referrer-Policy","strict-origin-when-cross-origin");
    if (incoming.pathname.startsWith("/api.php")) response.headers.set("Cache-Control","no-store");
    return response;
  }
};
