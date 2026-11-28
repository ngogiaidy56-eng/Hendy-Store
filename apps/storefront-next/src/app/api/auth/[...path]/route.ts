import { getNeonAuth } from "@/lib/auth/server";

type AuthMethod = "GET" | "POST" | "PUT" | "DELETE" | "PATCH";
type AuthRouteHandler = (request: Request) => Response | Promise<Response>;
type AuthHandlerMap = Partial<Record<AuthMethod, AuthRouteHandler>>;

let cachedHandlers: AuthHandlerMap | null = null;

function getHandlers(): AuthHandlerMap {
  if (!cachedHandlers) cachedHandlers = getNeonAuth().handler() as unknown as AuthHandlerMap;
  return cachedHandlers;
}

function dispatch(method: AuthMethod) {
  return async (request: Request): Promise<Response> => {
    try {
      const handler = getHandlers()[method];
      if (!handler) return Response.json({ error: "Managed Auth route method is unavailable." }, { status: 405 });
      return await handler(request);
    } catch (error) {
      console.error("[Hendy Store Next Auth]", error instanceof Error ? error.message : "Auth route failed");
      return Response.json({ error: "Managed Auth is not configured on this deployment." }, { status: 503 });
    }
  };
}

export const GET = dispatch("GET");
export const POST = dispatch("POST");
export const PUT = dispatch("PUT");
export const DELETE = dispatch("DELETE");
export const PATCH = dispatch("PATCH");
