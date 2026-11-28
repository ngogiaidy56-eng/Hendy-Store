export async function GET(): Promise<Response> {
  const rawOrigin = String(process.env.HENDY_API_ORIGIN || "https://hendystore.onrender.com");
  const origin = rawOrigin.endsWith("/") ? rawOrigin.slice(0, -1) : rawOrigin;
  try {
    const response = await fetch(origin + "/api.php?action=health", { cache: "no-store" });
    const body = await response.text();
    return new Response(body, {
      status: response.status,
      headers: { "Content-Type": response.headers.get("Content-Type") || "application/json", "Cache-Control": "no-store" }
    });
  } catch {
    return Response.json({ ok: false, error: "Origin health check unavailable." }, { status: 502 });
  }
}
