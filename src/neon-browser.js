import { createAuthClient } from "@neon/auth";
import { createRealtimeClient } from "@neon/realtime/client";

const apiUrl = new URL("api.php", document.baseURI);

async function loadNeonClientConfig() {
  const url = new URL(apiUrl);
  url.searchParams.set("action", "client_config");
  const response = await fetch(url, {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });
  const result = await response.json();
  if (!response.ok || !result.ok) {
    throw new Error(result.error || "Neon client configuration is unavailable.");
  }
  return result.data;
}

const ready = (async () => {
  const config = await loadNeonClientConfig();
  const auth = config.authEnabled && config.authUrl ? createAuthClient(config.authUrl) : null;
  let realtimeClient = null;
  let liveSubscription = null;
  let renewTimer = null;
  let subscribed = false;

  async function getToken() {
    if (!auth) return null;
    try {
      const result = await auth.token();
      if (result?.error) return null;
      const token = result?.data?.token ?? null;
      return typeof token === "string" && token.length > 0 ? token : null;
    } catch {
      return null;
    }
  }

  async function getSession() {
    if (!auth) return { user: null, session: null };
    try {
      const result = await auth.getSession?.();
      if (result?.error) return { user: null, session: null };
      const data = result?.data ?? result ?? {};
      return {
        user: data?.user ?? data?.session?.user ?? null,
        session: data?.session ?? null,
      };
    } catch {
      return { user: null, session: null };
    }
  }

  async function getSealedCatalog() {
    const base = String(config.functionUrl || "").replace(/\/+$/, "");
    if (!base) throw new Error("Neon Function URL is not configured.");
    const response = await fetch(base + "/catalog-live", {
      credentials: "omit",
      headers: { Accept: "application/json" },
    });
    const payload = await response.json();
    if (!response.ok || !payload.sealed) {
      throw new Error(payload.error || "Unable to seal the live catalog query.");
    }
    return payload.sealed;
  }

  async function subscribeCatalog() {
    if (subscribed) return true;
    if (!config.realtimeEnabled || !config.realtimeUrl || !config.functionUrl) return false;

    try {
      realtimeClient = createRealtimeClient({ url: config.realtimeUrl });
      const sealed = await getSealedCatalog();
      liveSubscription = realtimeClient.subscribe(sealed);

      const snapshot = await liveSubscription.getSnapshot();
      window.dispatchEvent(new CustomEvent("hendy:catalog-live", {
        detail: snapshot?.data ?? snapshot,
      }));

      liveSubscription.onChange(({ data }) => {
        window.dispatchEvent(new CustomEvent("hendy:catalog-live", { detail: data }));
      });
      liveSubscription.onStateChange?.((state) => {
        if (state?.status === "error" || state?.error) {
          window.dispatchEvent(new CustomEvent("hendy:catalog-live-error"));
        }
      });

      // Neon sealed-query tokens expire. Renew with the exact same sealed query
      // before expiry; the subscription reports a final error if renewal fails.
      renewTimer = window.setInterval(async () => {
        try {
          await liveSubscription?.renew();
        } catch {
          window.clearInterval(renewTimer);
          subscribed = false;
          window.dispatchEvent(new CustomEvent("hendy:catalog-live-error"));
        }
      }, 40000);

      subscribed = true;
      return true;
    } catch (error) {
      console.warn("[Hendy Store] Neon Realtime is unavailable:", error?.message || "connection error");
      window.dispatchEvent(new CustomEvent("hendy:catalog-live-error"));
      return false;
    }
  }

  async function listPublicCatalogFromPhp({ page = 1, limit = 18, category = "", query = "", sort = "popular" } = {}) {
    const url = new URL(apiUrl);
    url.searchParams.set("action", "products");
    url.searchParams.set("page", String(Math.max(1, Math.min(100000, Number.parseInt(page, 10) || 1))));
    url.searchParams.set("limit", String(Math.max(1, Math.min(48, Number.parseInt(limit, 10) || 18))));
    url.searchParams.set("sort", String(sort || "popular").slice(0, 32));
    if (category && category !== "Tất cả") url.searchParams.set("category", String(category).slice(0, 100));
    if (query) url.searchParams.set("q", String(query).slice(0, 100));
    const response = await fetch(url, {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    let payload;
    try { payload = await response.json(); }
    catch { throw new Error("PHP catalogue API returned an invalid response."); }
    if (!response.ok || !payload?.ok || !Array.isArray(payload?.data)) {
      throw new Error(payload?.error || "Unable to read the public catalogue.");
    }
    return payload;
  }

  async function getDataApiToken() {
    if (!auth) throw new Error("Neon Auth is required to access the Data API.");
    const token = await getToken();
    if (!token) throw new Error("Sign in is required for direct Data API queries.");
    return token;
  }

  async function listPublicCatalog({ page = 1, limit = 18, category = "", query = "", sort = "popular" } = {}) {
    const base = String(config.dataApiUrl || "").trim();
    if (!base) throw new Error("NEON_DATA_API_URL is not configured.");
    const endpoint = new URL("api_public_catalog", base.replace(/\/+$/, "") + "/");
    const safePage = Math.max(1, Math.min(100000, Number.parseInt(page, 10) || 1));
    const safeLimit = Math.max(1, Math.min(48, Number.parseInt(limit, 10) || 18));
    endpoint.searchParams.set("select", "id,slug,title,developer,category,short_description,version,platform,icon,cover,downloads,rating,featured,updated_at,price_type,price_amount_vnd");
    endpoint.searchParams.set("limit", String(safeLimit));
    endpoint.searchParams.set("offset", String((safePage - 1) * safeLimit));
    endpoint.searchParams.set("order", ({
      newest: "updated_at.desc",
      rating: "rating.desc,downloads.desc",
      name: "title.asc",
      "free-popular": "downloads.desc,rating.desc,title.asc",
      popular: "featured.desc,downloads.desc,rating.desc"
    })[sort] || "featured.desc,downloads.desc,rating.desc");

    const safeCategory = String(category || "").replace(/[^\p{L}\p{N}\s_-]/gu, " ").trim().slice(0, 100);
    if (safeCategory && safeCategory !== "Tất cả") {
      endpoint.searchParams.set("category", "eq." + safeCategory);
    }
    const safeQuery = String(query || "").replace(/[^\p{L}\p{N}\s_-]/gu, " ").trim().slice(0, 100);
    if (safeQuery) {
      endpoint.searchParams.set("or", "(title.ilike.*" + safeQuery + "*,developer.ilike.*" + safeQuery + "*,short_description.ilike.*" + safeQuery + "*)");
    }

    const token = await getToken();
    if (!token) return listPublicCatalogFromPhp({ page: safePage, limit: safeLimit, category, query, sort });

    let response;
    try {
      response = await fetch(endpoint, {
      method: "GET",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        Authorization: "Bearer " + token,
        Prefer: "count=exact"
      }
      });
    } catch {
      return listPublicCatalogFromPhp({ page: safePage, limit: safeLimit, category, query, sort });
    }
    let rows;
    try { rows = await response.json(); }
    catch { return listPublicCatalogFromPhp({ page: safePage, limit: safeLimit, category, query, sort }); }
    if (!response.ok || !Array.isArray(rows)) {
      return listPublicCatalogFromPhp({ page: safePage, limit: safeLimit, category, query, sort });
    }
    const range = response.headers.get("Content-Range") || "";
    const totalPart = range.includes("/") ? range.split("/").pop() : "";
    const total = totalPart && totalPart !== "*" && Number.isFinite(Number(totalPart))
      ? Number(totalPart)
      : rows.length;
    return {
      ok: true,
      data: rows.map(row => ({
        id: Number(row.id),
        slug: row.slug,
        title: row.title,
        developer: row.developer,
        category: row.category,
        shortDescription: row.short_description,
        version: row.version,
        platform: row.platform,
        priceType: row.price_type || "Free",
        priceAmountVnd: Number(row.price_amount_vnd || 0),
        icon: row.icon,
        cover: row.cover,
        downloads: Number(row.downloads || 0),
        rating: Number(row.rating || 0),
        featured: Boolean(row.featured),
        updatedAt: row.updated_at
      })),
      meta: {
        page: safePage,
        limit: safeLimit,
        total,
        pages: Math.max(1, Math.ceil(total / safeLimit))
      }
    };
  }

  return {
    config,
    auth,
    listPublicCatalog,
    getDataApiToken,
    getToken,
    getSession,
    async signIn(email, password) {
      if (!auth) throw new Error("Neon Auth chưa được cấu hình. Kiểm tra NEON_AUTH_BASE_URL trên Render.");
      const result = await auth.signIn.email({ email, password });
      if (result?.error) throw new Error(result.error.message || "Đăng nhập thất bại.");
      return result?.data;
    },
    async signUp(name, email, password) {
      if (!auth) throw new Error("Neon Auth chưa được cấu hình. Kiểm tra NEON_AUTH_BASE_URL trên Render.");
      const result = await auth.signUp.email({ name, email, password });
      if (result?.error) throw new Error(result.error.message || "Không thể tạo tài khoản.");
      return result?.data;
    },
    async signInGoogle(callbackURL = window.location.origin + "/") {
      if (!auth) throw new Error("Neon Auth chưa được cấu hình.");
      if (!config.googleOAuthEnabled) throw new Error("Đăng nhập Google chưa được bật cho HendyStore.");
      const result = await auth.signIn.social({ provider: "google", callbackURL });
      if (result?.error) throw new Error(result.error.message || "Không thể đăng nhập bằng Google.");
      return result?.data;
    },
    async signOut() {
      if (!auth) return;
      const result = await auth.signOut();
      if (result?.error) throw new Error(result.error.message || "Đăng xuất thất bại.");
    },
    subscribeCatalog,
  };
})();

window.HendyNeon = {
  ready,
  async getToken() {
    const client = await ready;
    return client.getToken();
  },
  async getSession() {
    const client = await ready;
    return client.getSession();
  },
  async signIn(email, password) {
    const client = await ready;
    return client.signIn(email, password);
  },
  async signUp(name, email, password) {
    const client = await ready;
    return client.signUp(name, email, password);
  },
  async signInGoogle(callbackURL) {
    const client = await ready;
    return client.signInGoogle(callbackURL);
  },
  async signOut() {
    const client = await ready;
    return client.signOut();
  },
  async listPublicCatalog(options) {
    const client = await ready;
    return client.listPublicCatalog(options);
  },
  async getDataApiToken() {
    const client = await ready;
    return client.getDataApiToken();
  },
  async subscribeCatalog() {
    const client = await ready;
    return client.subscribeCatalog();
  },
};
