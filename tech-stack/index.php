<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
$storeName = (string) env_value('STORE_NAME', 'Hendy Store');
$base = app_base_url();
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="Công nghệ và kiến trúc đang sử dụng tại Hendy Store.">
  <link rel="canonical" href="<?= $e($base . '/tech-stack/') ?>">
  <link rel="icon" href="../images/dummy.png">
  <link rel="stylesheet" href="../g/fonts.css">
  <link rel="stylesheet" href="../css/custom.261126163517.css">
  <title>Tech Stack — <?= $e($storeName) ?></title>
  <style>
    .tech-shell{max-width:1120px;margin:0 auto;padding:42px 24px 76px}
    .tech-hero{padding:48px 42px;border:1px solid #e5e8ef;border-radius:24px;background:linear-gradient(135deg,#f8fbff,#f5f7ff);margin:30px 0 36px}
    .tech-hero h1{font-size:clamp(32px,5vw,52px);line-height:1.08;margin:12px 0}
    .tech-hero p{max-width:760px;line-height:1.75;color:#596579}
    .tech-kicker{font-size:12px;letter-spacing:.16em;font-weight:800;color:#5867a7}
    .tech-section{margin:34px 0}
    .tech-section h2{font-size:25px;margin-bottom:8px}
    .tech-section>p{color:#647084;line-height:1.7}
    .tech-table-wrap{overflow-x:auto;border:1px solid #e5e8ef;border-radius:16px;background:#fff}
    .tech-table{border-collapse:collapse;width:100%;min-width:760px}
    .tech-table th,.tech-table td{padding:16px 18px;text-align:left;border-bottom:1px solid #edf0f4;vertical-align:top;line-height:1.6}
    .tech-table th{background:#f8f9fc;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#697386}
    .tech-table tr:last-child td{border-bottom:0}
    .tech-name{font-weight:750;color:#172033}
    .tech-status{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:#eef2f7;color:#46536a}
    .tech-status.live{background:#e8f7ee;color:#167342}
    .tech-status.plan{background:#fff4df;color:#93600a}
    .tech-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}
    .tech-card{border:1px solid #e5e8ef;border-radius:16px;padding:20px;background:#fff}
    .tech-card h3{margin:0 0 8px;font-size:17px}
    .tech-card p{margin:0 0 12px;color:#657084;line-height:1.65}
    .tech-callout{margin-top:22px;padding:18px 20px;border-left:3px solid #6574c5;background:#f6f7fc;border-radius:0 12px 12px 0;line-height:1.7;color:#4c5870}
    @media(max-width:700px){.tech-shell{padding:22px 16px 48px}.tech-hero{padding:28px 22px}.tech-cards{grid-template-columns:1fr}}
  </style>
</head>
<body>
<header class="site-header">
  <a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a>
  <nav class="main-nav"><a class="nav-link" href="../">Trang chủ</a><a class="nav-link" href="../library/">Thư viện</a><a class="nav-link active" href="./">Công nghệ</a></nav>
  <div class="header-actions"><a class="account-button" href="../admin/">Quản trị</a></div>
</header>
<main class="tech-shell">
  <section class="tech-hero">
    <span class="tech-kicker">HENDY STORE / ENGINEERING</span>
    <h1>Bộ công cụ &amp; công nghệ</h1>
    <p>Kiến trúc hiện tại ưu tiên PostgreSQL trên Neon, lưu trữ tệp riêng tư tương thích S3 và backend PHP chạy trên Render. Những công nghệ chưa tích hợp được ghi rõ là kế hoạch nâng cấp, không phải tính năng đang hoạt động.</p>
  </section>
  <section class="tech-section">
    <h2>Tech Stack đang sử dụng</h2>
    <p>Trạng thái bên dưới phản ánh mã nguồn hiện tại của HendyStore.</p>
    <div class="tech-table-wrap"><table class="tech-table">
      <thead><tr><th>Lớp</th><th>Công nghệ</th><th>Vai trò</th><th>Trạng thái</th></tr></thead>
      <tbody>
        <tr><td class="tech-name">Database</td><td>PostgreSQL · Neon</td><td>Tài khoản, ứng dụng, phiên bản, thư viện, đánh giá và lịch sử tải.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">File storage</td><td>Neon Object Storage · S3 API</td><td>Bucket private lưu package cài đặt, ảnh và tệp sản phẩm.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">Backend / API</td><td>PHP 8.4 · Apache · PDO</td><td>API, phân quyền, upload, cấp quyền tải tạm thời và xử lý dữ liệu.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">Frontend</td><td>HTML · CSS · JavaScript</td><td>Website responsive, danh mục, tìm kiếm, trang chi tiết và Admin.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">Build tooling</td><td>Node.js 22 · npm · esbuild</td><td>Build bundle tích hợp Neon cho trình duyệt và kiểm thử frontend.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">Authentication</td><td>Email/password · Neon Auth (Better Auth/JWKS)</td><td>Đăng nhập, session, CSRF và phân quyền customer/admin.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">Deployment</td><td>Docker · Render Web Service</td><td>Chạy PHP app trên Render; deploy tự động từ nhánh main.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
        <tr><td class="tech-name">CI</td><td>GitHub Actions</td><td>PHP lint, Composer, smoke tests, Node build/test và kiểm tra secrets.</td><td><span class="tech-status live">Đang dùng</span></td></tr>
      </tbody>
    </table></div>
  </section>
  <section class="tech-section">
    <h2>Các lớp bảo mật và mở rộng</h2>
    <div class="tech-cards">
      <article class="tech-card"><h3>Virus scanning</h3><p>VirusTotal API chưa được tích hợp. Lộ trình là đưa package upload vào trạng thái chờ/quarantine, quét hash hoặc tệp, lưu kết quả và chỉ cho phép công khai khi đạt chính sách.</p><span class="tech-status plan">Cần tích hợp</span></article>
      <article class="tech-card"><h3>Cloudflare CDN / WAF</h3><p>Đã có mã Worker proxy và Pages Advanced Mode trong repository. DNS, WAF rules, cache policy và production deployment vẫn cần cấu hình riêng.</p><span class="tech-status plan">Chưa xác nhận production</span></article>
      <article class="tech-card"><h3>Cloudflare R2</h3><p>R2 là phương án thay thế hoặc mở rộng kho S3-compatible. Chưa chuyển dữ liệu từ Neon Object Storage; không đổi bucket production khi chưa kiểm kê và thử tải.</p><span class="tech-status plan">Tùy chọn mở rộng</span></article>
      <article class="tech-card"><h3>React / Next.js và Node API</h3><p>Đây là hướng tái cấu trúc frontend/backend trong tương lai. Runtime hiện tại vẫn là HTML/CSS/JavaScript và PHP 8.4; Workers hiện chỉ là edge proxy, không chạy PHP.</p><span class="tech-status plan">Lộ trình nâng cấp</span></article>
      <article class="tech-card"><h3>Auth provider</h3><p>Clerk, Auth0 và Supabase Auth là lựa chọn thay thế, không bật song song khi chưa thiết kế đồng bộ user ID và role. Hiện hệ thống giữ Neon Auth cùng đăng nhập email/password.</p><span class="tech-status plan">Không cần thêm ngay</span></article>
      <article class="tech-card"><h3>Observability &amp; backup</h3><p>Tiếp tục theo dõi Render logs, GitHub Actions và Neon quota; cần bổ sung cảnh báo uptime, lỗi ứng dụng, backup/restore định kỳ và theo dõi trạng thái quét tệp.</p><span class="tech-status plan">Cần hoàn thiện</span></article>
    </div>
    <div class="tech-callout"><strong>Nguyên tắc triển khai:</strong> không ghi nhận một công nghệ là đang hoạt động chỉ vì đã thêm thư viện hoặc cấu hình mẫu vào repository. Chỉ chuyển trạng thái sau khi cấu hình secrets, triển khai và kiểm thử end-to-end thành công.</div>
  </section>
</main>
<footer class="site-footer"><div class="footer-main"><a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a><p>Không gian khám phá ứng dụng và công cụ số dành cho mọi người.</p><div class="footer-links"><a href="../privacy/">Quyền riêng tư</a><a href="../terms-of-use/">Điều khoản sử dụng</a><a href="../developer-policy/">Chính sách nhà phát triển</a><a href="./">Tech Stack</a><a href="../admin/">Quản trị</a></div></div><div class="footer-bottom"><span>© <?= date('Y') ?> Hendy Store. All rights reserved.</span><span>Designed for discovery ✦</span></div></footer>
</body></html>
