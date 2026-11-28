import { getProducts, productSummary, publicAsset, type StoreProduct } from "@/lib/api";

type SearchParams = Promise<{ q?: string; sort?: string; category?: string }>;

function ProductCard({ product }: { product: StoreProduct }) {
  return (
    <a className="product-card" href={"/apps/" + encodeURIComponent(product.slug)}>
      <div className="product-top">
        <img className="product-icon" src={publicAsset(product.icon)} alt="" loading="lazy" />
        <div className="min-w-0">
          <h3 className="truncate">{product.title}</h3>
          <small>{product.developer} · {product.category}</small>
        </div>
      </div>
      <p>{productSummary(product).slice(0, 150)}</p>
      <div className="product-meta">
        <span>★ {Number(product.rating || 0).toFixed(1)}</span>
        <span>{Number(product.downloads || 0).toLocaleString("vi-VN")} lượt tải</span>
      </div>
    </a>
  );
}

export const dynamic = "force-dynamic";

export default async function Home({ searchParams }: { searchParams: SearchParams }) {
  const params = await searchParams;
  const q = (params.q || "").slice(0, 100);
  const products = await getProducts({ q, sort: params.sort || "popular", category: params.category, limit: 48 });
  const featured = products.filter((item) => Boolean(item.featured)).slice(0, 4);
  const shown = products.length ? products : [];

  return (
    <main className="page-shell">
      <section className="hero">
        <div>
          <span className="kicker">HENDY STORE · APP DISCOVERY</span>
          <h1>Khám phá ứng dụng làm nên khác biệt.</h1>
          <p>Những công cụ hữu ích, ứng dụng sáng tạo và trò chơi thú vị — được tuyển chọn trong một không gian duy nhất.</p>
          <a className="button" href="#apps">Khám phá cửa hàng ↗</a>
        </div>
        <div className="hero-art" aria-hidden="true">
          <div className="hero-art-inner"><strong>Hendy Store</strong><span>Discover tools. Build ideas. Get more done.</span></div>
        </div>
      </section>

      {featured.length > 0 && (
        <section aria-labelledby="featured-title">
          <div className="section-heading"><div><span className="kicker">CURATED FOR YOU</span><h2 id="featured-title">Ứng dụng nổi bật</h2></div></div>
          <div className="product-grid">{featured.map((product) => <ProductCard key={product.id} product={product} />)}</div>
        </section>
      )}

      <section id="apps" aria-labelledby="catalog-title">
        <div className="section-heading"><div><span className="kicker">STORE LIBRARY</span><h2 id="catalog-title">Khám phá cửa hàng</h2></div><span className="text-sm text-slate-500">{products.length} ứng dụng</span></div>
        <form className="search-form" action="/" method="get" role="search">
          <label className="sr-only" htmlFor="store-search">Tìm ứng dụng</label>
          <input id="store-search" type="search" name="q" defaultValue={q} placeholder="Tìm ứng dụng, nhà phát triển, từ khóa…" />
          <button type="submit">Tìm kiếm</button>
        </form>
        {shown.length ? <div className="product-grid">{shown.map((product) => <ProductCard key={product.id} product={product} />)}</div>
          : <div className="empty"><h3 className="font-semibold text-slate-800">Chưa có ứng dụng công khai</h3><p className="mt-2">Ứng dụng sẽ xuất hiện sau khi được kiểm duyệt và xuất bản.</p></div>}
      </section>
    </main>
  );
}
