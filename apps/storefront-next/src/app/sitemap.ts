import type { MetadataRoute } from "next";
import { getProducts } from "@/lib/api";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = (process.env.NEXT_PUBLIC_SITE_URL || "https://hendystore.onrender.com").replace(/\/$/, "");
  const products = await getProducts({ limit: 48 });
  return [
    { url: base, changeFrequency: "hourly", priority: 1 },
    ...products.map((product) => ({
      url: base + "/apps/" + encodeURIComponent(product.slug),
      lastModified: product.updatedAt ? new Date(product.updatedAt) : undefined,
      changeFrequency: "daily" as const,
      priority: 0.7
    }))
  ];
}
