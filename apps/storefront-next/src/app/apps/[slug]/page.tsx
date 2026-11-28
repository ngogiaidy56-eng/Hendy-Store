import type { Metadata } from "next";
import { getProduct, getProductExtras, productSummary, publicAsset } from "@/lib/api";

type Params = Promise<{ slug: string }>;

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { slug } = await params;
  const product = await getProduct(slug);
  if (!product) return { title: "Không tìm thấy ứng dụng", robots: { index: false, follow: true } };
  return {
    title: product.title,
    description: productSummary(product).slice(0, 160),
    alternates: { canonical: "/apps/" + encodeURIComponent(product.slug) },
    openGraph: {
      type: "website",
      title: product.title,
      description: productSummary(product).slice(0, 200),
      images: [publicAsset(product.cover || product.icon)]
    }
  };
}

export default async function AppDetail({ params }: { params: Params }) {
  const { slug } = await params;
  const product = await getProduct(slug);
  if (!product) {
    return <main className="page-shell"><div className="empty"><h1 className="text-2xl font-bold">Không tìm thấy ứng dụng</h1><a className="button" href="/">Về cửa hàng</a></div></main>;
  }
  const extras = await getProductExtras(slug);
  const screenshots = extras?.screenshots || [];
  const versions = extras?.versions || [];
  const specs = extras?.specs || {};
  const structuredData = {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    name: product.title,
    applicationCategory: product.category,
    operatingSystem: product.platform,
    softwareVersion: product.version,
    description: productSummary(product),
    aggregateRating: extras?.reviewStats && extras.reviewStats.review_count > 0 ? {
      "@type": "AggregateRating",
      ratingValue: extras.reviewStats.average_rating,
      ratingCount: extras.reviewStats.review_count
    } : undefined
  };
  return (
    <main className="page-shell">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(structuredData).replace(/</g, "\\u003c") }} />
      <article className="detail">
        <div className="detail-header">
          <img className="detail-icon" src={publicAsset(product.icon)} alt={product.title} />
          <div className="min-w-0">
            <span className="kicker">{product.category}</span>
            <h1>{product.title}</h1>
            <p className="muted text-sm">Phát triển bởi {product.developer} · Phiên bản {product.version}</p>
          </div>
        </div>
        <div className="detail-description">{product.description || productSummary(product)}</div>
        <div className="mt-8 flex flex-wrap gap-3 text-sm text-slate-500">
          <span>Hệ điều hành: {product.platform}</span>
          <span>·</span>
          <span>★ {Number(product.rating || 0).toFixed(1)}</span>
          <span>·</span>
          <span>{Number(product.downloads || 0).toLocaleString("vi-VN")} lượt tải</span>
        </div>
        {screenshots.length > 0 && (
          <section className="mt-10">
            <h2 className="mb-4 text-xl font-bold">Ảnh chụp màn hình</h2>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
              {screenshots.map((shot) => <figure key={shot.id} className="overflow-hidden rounded-xl border border-slate-200">
                <img src={publicAsset(shot.image_url)} alt={shot.caption || product.title} loading="lazy" className="aspect-video w-full object-cover" />
                {shot.caption && <figcaption className="p-3 text-xs text-slate-500">{shot.caption}</figcaption>}
              </figure>)}
            </div>
          </section>
        )}
        {versions.length > 0 && (
          <section className="mt-10">
            <h2 className="mb-4 text-xl font-bold">Phiên bản Production</h2>
            <div className="space-y-3">
              {versions.map((version) => <div key={version.id} className="rounded-xl border border-slate-200 p-4">
                <div className="flex flex-wrap justify-between gap-2"><strong>v{version.version}</strong><span className="text-xs text-slate-500">{version.platform} · {version.architecture}</span></div>
                {version.release_notes && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{version.release_notes}</p>}
                {version.package_size_bytes ? <small className="text-slate-400">{Number(version.package_size_bytes).toLocaleString("vi-VN")} bytes</small> : null}
              </div>)}
            </div>
          </section>
        )}
        {(specs.minimum_requirements || specs.privacy_details || specs.video_url) && (
          <section className="mt-10 space-y-4">
            <h2 className="text-xl font-bold">Thông tin ứng dụng</h2>
            {specs.minimum_requirements && <div><h3 className="font-semibold">Cấu hình tối thiểu</h3><p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{specs.minimum_requirements}</p></div>}
            {specs.privacy_details && <div><h3 className="font-semibold">Quyền riêng tư</h3><p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{specs.privacy_details}</p></div>}
            {specs.video_url && <a className="text-sm font-semibold text-indigo-600 underline" href={specs.video_url} rel="nofollow noopener noreferrer" target="_blank">Xem video giới thiệu ↗</a>}
          </section>
        )}
        {extras?.integrity?.verified && <section className="mt-10 rounded-xl bg-slate-50 p-4">
          <h2 className="font-semibold">Xác thực tính toàn vẹn</h2><p className="mt-2 break-all font-mono text-xs text-slate-600">SHA-256: {extras.integrity.sha256}</p>
          <p className="mt-1 text-xs text-slate-500">Dung lượng: {Number(extras.integrity.size).toLocaleString("vi-VN")} bytes</p>
        </section>}
        <a className="button mt-8" href={"https://hendystore.onrender.com/apps/" + encodeURIComponent(product.slug)}>Xem trang tải và cài đặt</a>
      </article>
    </main>
  );
}
