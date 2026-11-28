<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
?><!doctype html>
<html lang="vi"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Kết nối & Hạ tầng — Hendy Store</title>
<link rel="stylesheet" href="../g/fonts.css"><link rel="stylesheet" href="../css/custom.261126163517.css">
<script src="infrastructure.js" defer></script>
<style>
.infra-shell{max-width:1200px;margin:0 auto;padding:34px 24px 80px}
.infra-hero{padding:30px;border-radius:22px;background:linear-gradient(130deg,#172442,#303d70);color:#fff}
.infra-hero h1{font-size:clamp(28px,4vw,40px);margin:8px 0}.infra-hero p{color:#dce2f5;line-height:1.7;margin:0;max-width:720px}
.infra-kicker{font-size:11px;letter-spacing:.16em;font-weight:800;color:#aab5ff}
.infra-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:20px}
.infra-card{background:#fff;border:1px solid #e6eaf1;border-radius:17px;padding:21px}
.infra-card h2{margin:4px 0 9px;font-size:20px}.infra-muted{color:#68758b;font-size:13px;line-height:1.65}
.infra-badge{font-size:11px;font-weight:800;border-radius:20px;padding:5px 9px;background:#eef1f7;color:#59657a}
.infra-badge.ok{background:#e8f7ed;color:#176d40}.infra-badge.warn{background:#fff4df;color:#895f15}.infra-badge.err{background:#ffedeb;color:#a3312a}
.infra-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #eef0f5;font-size:13px}.infra-row:last-child{border-bottom:0}
.infra-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.infra-btn{border:0;border-radius:9px;padding:10px 13px;font-weight:750;cursor:pointer;background:#5969dc;color:white}.infra-btn.alt{background:#eef1fa;color:#35436f}
.infra-code{white-space:pre;overflow:auto;padding:15px;background:#101827;color:#dce7fa;border-radius:12px;font:12px/1.75 ui-monospace,SFMono-Regular,Consolas,monospace}
.infra-full{grid-column:1/-1}
@media(max-width:760px){.infra-shell{padding:18px 14px 50px}.infra-grid{grid-template-columns:1fr}.infra-full{grid-column:auto}}
</style></head><body>
<header class="site-header">
<a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a>
<nav class="main-nav"><a class="nav-link" href="./">Quản trị</a><a class="nav-link" href="security.php">Kiểm duyệt & Bảo mật</a><a class="nav-link active" href="infrastructure.php">Kết nối & Hạ tầng</a></nav>
<div class="header-actions"><a class="account-button" href="../">← Về website</a></div>
</header>
<main id="infraDenied" class="infra-shell" hidden><section class="infra-card"><h1>Yêu cầu quyền quản trị</h1><p class="infra-muted">Vui lòng đăng nhập tài khoản Admin để truy cập Trung tâm Kết nối & Hạ tầng.</p><a class="infra-btn" href="./">Đến Admin Console</a></section></main>
<main class="infra-shell" id="infraWorkspace" hidden>
<section class="infra-hero"><span class="infra-kicker">PLATFORM CONNECTION CENTER</span><h1>Kết nối & Hạ tầng</h1><p>Quản lý định hướng kết nối Database, Object Storage, Render và Cloudflare. Secret luôn được lưu ở Environment/Secrets của nhà cung cấp, không lưu trong trình duyệt hoặc Git.</p><div class="infra-actions"><a class="infra-btn" href="https://dashboard.render.com" target="_blank" rel="noopener">Render Environment ↗</a><a class="infra-btn alt" href="https://dash.cloudflare.com/" target="_blank" rel="noopener">Cloudflare Dashboard ↗</a><a class="infra-btn alt" href="https://console.neon.tech/" target="_blank" rel="noopener">Neon Console ↗</a></div></section>
<div class="infra-grid">
<section class="infra-card"><span class="infra-kicker">DATABASE ROUTER</span><h2>Database & Query Engine</h2><p class="infra-muted">Chọn đúng engine ở từng runtime. Neon PostgreSQL hiện vẫn là nguồn dữ liệu HendyStore production.</p>
<div class="infra-row"><span>PostgreSQL / Neon</span><span class="infra-badge ok">Nguồn dữ liệu hiện tại</span></div>
<div class="infra-row"><span>SQLite local / Render disk</span><span class="infra-badge warn">Dev / một instance có persistent disk</span></div>
<div class="infra-row"><span>Cloudflare D1</span><span class="infra-badge warn">Binding riêng cho edge data; không thay Neon</span></div>
<div class="infra-row"><span>Cloudflare Workers → Neon</span><span class="infra-badge ok">Gọi PHP origin trên Render · không cần Hyperdrive</span></div>
<div class="infra-actions"><button class="infra-btn alt" type="button" data-copy="database">Sao chép mẫu cấu hình DB</button></div></section>

<section class="infra-card"><span class="infra-kicker">OBJECT STORAGE ROUTER</span><h2>Storage Providers</h2><p class="infra-muted">Production lưu object riêng tư trên Neon Object Storage. Cloudflare R2, CloudFly và S3 chung là provider thay thế khi được cấu hình và kiểm tra riêng.</p>
<div class="infra-row"><span>Neon Object Storage</span><span class="infra-badge ok">ACTIVE · PRIVATE</span></div>
<div class="infra-row"><span>Cloudflare R2</span><span class="infra-badge">Provider thay thế</span></div>
<div class="infra-row"><span>CloudFly S3</span><span class="infra-badge">Provider thay thế</span></div>
<div class="infra-row"><span>Generic S3</span><span class="infra-badge warn">Adapter trong nhánh nâng cấp</span></div>
<div class="infra-actions"><button class="infra-btn" type="button" data-copy="r2">Mẫu R2 thay thế</button><button class="infra-btn alt" type="button" data-copy="cloudfly">Mẫu CloudFly thay thế</button></div></section>

<section class="infra-card"><span class="infra-kicker">RUNTIME</span><h2>Render & Cloudflare</h2><p class="infra-muted">Một bộ mã nguồn, nhiều adapter triển khai. Không tự ghi secret vào tài khoản khi chưa có API token và quyền phù hợp.</p>
<div class="infra-row"><span>Render / PHP 8.4 API</span><span class="infra-badge ok">Origin hiện tại</span></div>
<div class="infra-row"><span>Render / Next.js Docker</span><span class="infra-badge warn">Blueprint riêng, chưa tạo service</span></div>
<div class="infra-row"><span>Cloudflare API Worker · hendy-platform-api</span><span class="infra-badge warn">Chờ GitHub secret và deploy thủ công</span></div>
<div class="infra-row"><span>Next.js SSR Worker</span><span class="infra-badge warn">Cần Workers Admin để tạo Worker mới</span></div>
<div class="infra-row"><span>Cloudflare Pages · hendystore-pages</span><span class="infra-badge warn">Chờ GitHub secret và deploy thủ công</span></div>
<div class="infra-actions"><button class="infra-btn alt" id="checkHealth" type="button">Kiểm tra API origin</button><span class="infra-badge" id="originHealth">Chưa kiểm tra</span></div></section>

<section class="infra-card"><span class="infra-kicker">SECRET MANAGEMENT</span><h2>Biến môi trường & Secrets</h2><p class="infra-muted">Biến thường có thể khai báo trong Render Blueprint/Wrangler vars. API key, password, secret access key phải dùng Render Environment, Cloudflare Secrets hoặc GitHub Actions Secrets.</p>
<div class="infra-row"><span>Render Blueprint</span><span class="infra-badge ok">render.yaml</span></div>
<div class="infra-row"><span>Cloudflare vars/bindings</span><span class="infra-badge warn">Wrangler config + Dashboard</span></div>
<div class="infra-row"><span>Cloudflare encrypted secrets</span><span class="infra-badge warn">Wrangler secret bulk / Dashboard</span></div>
<div class="infra-row"><span>GitHub Actions secrets</span><span class="infra-badge warn">Cần nhập thủ công một lần</span></div>
<p class="infra-muted">Không có nút đọc lại secret đã lưu. Công cụ chỉ có thể kiểm tra tên biến và kết quả kết nối, không hiển thị giá trị bí mật.</p></section>

<pre class="infra-code" id="configPreview">OBJECT_STORAGE_PROVIDER=neon
AWS_ENDPOINT_URL_S3=https://br-frosty-wildflower-bevhhzlb.storage.c-9.us-east-2.aws.neon.tech
AWS_REGION=us-east-2
NEON_STORAGE_BUCKET=hendy-store-files
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
NEON_STORAGE_FORCE_PATH_STYLE=true
OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK=false</pre>
<div class="infra-actions"><button class="infra-btn" id="copyCurrentConfig" type="button">Sao chép cấu hình đang hiển thị</button><a class="infra-btn alt" href="https://github.com/hendyngo-servers/HendyStore/blob/main/docs/architecture/target-architecture.md" target="_blank" rel="noopener">Kiến trúc R2 / Cloudflare ↗</a></div>
<p class="infra-muted" style="margin-top:12px">Tự động tạo/binding resource trên Cloudflare yêu cầu tài khoản đã đăng nhập và API token có quyền đúng scope. Mã nguồn không được tự tạo bucket/database hoặc ghi đè secret production chỉ bằng cách mở trang này.</p>
</section>
</div></main><div class="toast" id="toast" role="status"></div></body></html>