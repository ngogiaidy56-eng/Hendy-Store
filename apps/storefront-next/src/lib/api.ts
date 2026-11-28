export interface StoreProduct {
  id: number;
  slug: string;
  title: string;
  developer: string;
  category: string;
  shortDescription?: string;
  short_description?: string;
  description?: string;
  version: string;
  platform: string;
  icon: string;
  cover: string;
  downloads: number;
  rating: number;
  priceType?: string;
  price_type?: string;
  featured?: boolean | number;
  updatedAt?: string;
}

export interface ProductResponse {
  ok: boolean;
  data?: StoreProduct[];
  meta?: { page: number; pages: number; total: number };
}

function apiOrigin(): string {
  const origin = process.env.HENDY_API_ORIGIN || "https://hendy-store.onrender.com";
  return origin.endsWith("/") ? origin.slice(0, -1) : origin;
}

export async function getProducts(params: {
  q?: string;
  category?: string;
  sort?: string;
  limit?: number;
  page?: number;
} = {}): Promise<StoreProduct[]> {
  const url = new URL("/api.php", apiOrigin());
  url.searchParams.set("action", "products");
  url.searchParams.set("page", String(Math.max(1, params.page ?? 1)));
  url.searchParams.set("limit", String(Math.min(48, Math.max(1, params.limit ?? 24))));
  url.searchParams.set("sort", params.sort || "popular");
  if (params.q) url.searchParams.set("q", params.q.slice(0, 100));
  if (params.category) url.searchParams.set("category", params.category.slice(0, 100));

  try {
    const response = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
    if (!response.ok) return [];
    const payload = await response.json() as ProductResponse;
    return payload.ok && Array.isArray(payload.data) ? payload.data : [];
  } catch {
    return [];
  }
}

export async function getProduct(slug: string): Promise<StoreProduct | null> {
  const url = new URL("/api.php", apiOrigin());
  url.searchParams.set("action", "product");
  url.searchParams.set("slug", slug);
  try {
    const response = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
    if (!response.ok) return null;
    const payload = await response.json() as { ok?: boolean; data?: StoreProduct };
    return payload.ok && payload.data ? payload.data : null;
  } catch {
    return null;
  }
}

export interface ProductExtras {
  screenshots?: Array<{ id: number; image_url: string; caption?: string }>;
  versions?: Array<{ id: number; version: string; platform: string; architecture?: string; release_notes?: string; package_size_bytes?: number; is_current?: number; created_at?: string }>;
  specs?: { video_url?: string; minimum_requirements?: string; privacy_details?: string };
  integrity?: { sha256: string; size: number; verified: boolean } | null;
  reviewStats?: { review_count: number; average_rating: number };
}

export async function getProductExtras(slug: string): Promise<ProductExtras | null> {
  const url = new URL("/api.php", apiOrigin());
  url.searchParams.set("action", "store_product_extras");
  url.searchParams.set("slug", slug);
  try {
    const response = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
    if (!response.ok) return null;
    const payload = await response.json() as { ok?: boolean; data?: ProductExtras };
    return payload.ok ? payload.data || null : null;
  } catch {
    return null;
  }
}

export function publicAsset(value?: string): string {
  if (!value) return "/images/dummy.png";
  if (/^https:\/\//i.test(value)) return value;
  if (value.startsWith("media:")) return "/api.php?action=media&file=" + encodeURIComponent(value.slice(6));
  if (value.startsWith("/")) return value;
  return "/" + (value.startsWith("./") ? value.slice(2) : value);
}

export function productSummary(product: StoreProduct): string {
  return product.shortDescription || product.short_description || product.description || "Khám phá ứng dụng trên Hendy Store.";
}
