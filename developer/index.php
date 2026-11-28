<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="description" content="Hendy Store Developer Console">
<title>Developer Console — Hendy Store</title>
<link rel="stylesheet" href="../g/fonts.css">
<link rel="stylesheet" href="../css/custom.261126163517.css">
<script src="../js/neon-client.bundle.js" defer></script>
<script src="console.js" defer></script>
<style>
:root{--dev-ink:#172033;--dev-muted:#677389;--dev-line:#e6eaf1;--dev-primary:#5969dc;--dev-bg:#f6f8fc}
.dev-shell{max-width:1180px;margin:0 auto;padding:42px 24px 80px}
.dev-hero{padding:36px;border-radius:24px;background:linear-gradient(130deg,#172442,#303d70);color:#fff;display:flex;justify-content:space-between;gap:24px;align-items:center}
.dev-hero h1{margin:8px 0;font-size:clamp(30px,4vw,44px);letter-spacing:-.04em}
.dev-hero p{margin:0;color:#d8def3;line-height:1.7;max-width:660px}
.dev-kicker{font-size:11px;font-weight:800;letter-spacing:.16em;color:#9daaff}
.dev-pill{display:inline-flex;align-items:center;border:1px solid #ffffff36;border-radius:999px;padding:8px 12px;font-size:12px;color:#fff;white-space:nowrap}
.dev-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:22px}
.dev-card{background:#fff;border:1px solid var(--dev-line);border-radius:18px;padding:22px;box-shadow:0 8px 32px #26365b0a}
.dev-card h2{margin:4px 0 8px;font-size:21px;color:var(--dev-ink)}
.dev-card p{color:var(--dev-muted);line-height:1.65}
.dev-card-full{grid-column:1/-1}
.dev-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.dev-field{display:flex;flex-direction:column;gap:7px;color:#4d5a70;font-size:13px;font-weight:650}
.dev-field.wide{grid-column:1/-1}
.dev-field input,.dev-field textarea,.dev-field select{width:100%;border:1px solid #dfe5ef;border-radius:10px;background:#fbfcfe;padding:12px 13px;color:#172033;font:inherit;font-weight:450;outline:none}
.dev-field textarea{min-height:94px;resize:vertical}
.dev-field input:focus,.dev-field textarea:focus,.dev-field select:focus{border-color:#7d89e5;box-shadow:0 0 0 3px #5969dc17}
.dev-button{border:0;border-radius:11px;background:var(--dev-primary);color:#fff;padding:12px 17px;font-weight:750;cursor:pointer}
.dev-button:disabled{opacity:.55;cursor:wait}
.dev-button.subtle{background:#eef1fa;color:#394773}
.dev-note{border-left:3px solid #d4a348;background:#fff8e9;color:#77551d;padding:12px 14px;border-radius:0 10px 10px 0;font-size:13px;line-height:1.6}
.dev-state{padding:13px 14px;background:#f3f5fb;border-radius:10px;color:#58657b;font-size:13px;line-height:1.6;white-space:pre-wrap}
.dev-state.success{background:#eaf8ef;color:#17653b}
.dev-state.error{background:#fff0ef;color:#a4332d}
.dev-list{display:grid;gap:10px}
.dev-row{padding:14px;border:1px solid var(--dev-line);border-radius:12px;display:flex;justify-content:space-between;align-items:center;gap:16px}
.dev-row strong{display:block;color:#1d2940}
.dev-row small{display:block;margin-top:5px;color:#6d788b;line-height:1.5}
.dev-badge{padding:5px 9px;border-radius:20px;background:#edf0f6;color:#546078;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap}
.dev-badge.clean,.dev-badge.approved{background:#e6f7ed;color:#187342}
.dev-badge.pending{background:#fff4df;color:#8c6116}
.dev-gate{text-align:center;padding:34px 18px}
.dev-gate[hidden],.dev-panel[hidden]{display:none}
.dev-analytics-row{display:grid;grid-template-columns:105px minmax(60px,1fr) 42px;align-items:center;gap:12px;font-size:12px;color:#69758a;padding:5px 0}.dev-analytics-track{height:10px;background:#edf0f7;border-radius:99px;overflow:hidden}.dev-analytics-track i{display:block;height:100%;background:#6676df;border-radius:99px}.dev-review-reply{display:grid;gap:10px;margin-top:12px}.dev-review-reply .dev-field textarea{min-height:70px}.dev-meta{color:#7a8497;font-size:12px;margin-top:5px;line-height:1.5}
@media(max-width:760px){.dev-shell{padding:20px 14px 50px}.dev-hero{padding:25px;display:block}.dev-pill{margin-top:18px}.dev-grid{grid-template-columns:1fr}.dev-card-full{grid-column:auto}.dev-form{grid-template-columns:1fr}.dev-field.wide{grid-column:auto}.dev-row{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<header class="site-header">
<a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a>
<nav class="main-nav"><a class="nav-link" href="../">Cửa hàng</a><a class="nav-link" href="../library/">Thư viện</a><a class="nav-link active" href="./">Nhà phát triển</a></nav>
<div class="header-actions"><a class="account-button" href="../">← Về cửa hàng</a></div>
</header>
<main class="dev-shell">
<section class="dev-hero"><div><span class="dev-kicker">HENDY STORE / PARTNER PORTAL</span><h1>Developer Console</h1><p>Quản lý hồ sơ nhà phát triển, tải gói ứng dụng trực tiếp lên kho cloud, gửi phát hành và theo dõi trạng thái kiểm duyệt, phiên bản cùng số liệu.</p></div><span class="dev-pill" id="developerIdentity">Đang xác thực…</span></section>

<section class="dev-card dev-gate" id="developerGate" hidden>
<h2>Đăng nhập để tiếp tục</h2><p>Bạn cần đăng nhập tài khoản Hendy Store trước khi tạo hồ sơ nhà phát triển.</p><a class="button button-primary" href="../">Đăng nhập tài khoản</a>
</section>

<div id="developerWorkspace" class="dev-panel" hidden>
<div class="dev-grid">
<section class="dev-card" id="profileCard">
<span class="dev-kicker">DEVELOPER ACCOUNT</span><h2>Hồ sơ nhà phát triển</h2><p>Hồ sơ cần được quản trị viên duyệt trước khi gửi ứng dụng lên cửa hàng.</p>
<form id="developerProfileForm" class="dev-form">
<label class="dev-field wide">Tên hiển thị / Tổ chức<input name="display_name" minlength="2" maxlength="160" required></label>
<label class="dev-field">Email hỗ trợ<input name="support_email" type="email" maxlength="190" required></label>
<label class="dev-field">Website HTTPS<input name="website_url" type="url" placeholder="https://example.com"></label>
<div class="dev-field wide"><button class="dev-button" type="submit">Gửi hồ sơ xét duyệt</button></div>
</form><div id="profileStatus" class="dev-state" style="margin-top:14px">Chưa có hồ sơ.</div>
</section>

<section class="dev-card">
<span class="dev-kicker">RELEASE SECURITY</span><h2>Kiểm tra phát hành</h2>
<p>Gói tải được upload lên bucket riêng bằng Presigned URL. Server tính SHA-256, đối chiếu tính toàn vẹn và gọi VirusTotal khi đã cấu hình khóa.</p>
<div class="dev-note">Không đưa mật khẩu, token API hoặc chứng chỉ ký số riêng tư lên website. Gói chỉ được gửi duyệt khi trạng thái quét là CLEAN; thiếu khóa quét sẽ khóa xuất bản.</div>
<div class="dev-state" id="uploadStatus" style="margin-top:14px">Chưa có gói tải nào.</div><button class="dev-button subtle" id="refreshPackageScan" type="button" hidden style="margin-top:10px">Kiểm tra lại kết quả quét & gửi duyệt</button>
</section>

<section class="dev-card dev-card-full" id="releaseCard" hidden>
<span class="dev-kicker">NEW RELEASE</span><h2>Đăng tải ứng dụng</h2><p>Chọn gói cài đặt. Trình duyệt sẽ tải thẳng lên Neon Object Storage, không chuyển file dung lượng lớn qua PHP server.</p>
<form id="releaseForm" class="dev-form">
<label class="dev-field">Tên ứng dụng<input name="title" maxlength="180" required></label>
<label class="dev-field">Slug<input name="slug" maxlength="180" pattern="[a-z0-9-]+" placeholder="ten-ung-dung" required></label>
<label class="dev-field">Danh mục<input name="category" maxlength="100" required></label>
<label class="dev-field">Phiên bản<input name="version" maxlength="40" value="1.0.0" required></label>
<label class="dev-field wide">Mô tả ngắn<input name="short_description" maxlength="255" required></label>
<label class="dev-field wide">Mô tả chi tiết<textarea name="description" maxlength="20000" required></textarea></label>
<label class="dev-field">Nền tảng<input name="platform" value="Windows, Android, macOS, Linux, Web"></label>
<label class="dev-field">Kênh phát hành<select name="release_track"><option value="alpha">Alpha — nội bộ</option><option value="beta">Beta — thử nghiệm</option><option value="production" selected>Production — chính thức</option></select></label>
<label class="dev-field">Hệ điều hành mục tiêu<select name="release_platform"><option value="windows">Windows</option><option value="macos">macOS</option><option value="android">Android</option><option value="ios">iOS (IPA)</option><option value="linux">Linux</option><option value="web">Web</option><option value="universal">Đa nền tảng</option></select></label>
<label class="dev-field">Video giới thiệu (URL HTTPS)<input name="video_url" type="url" placeholder="https://..."></label>
<label class="dev-field wide">Nhật ký thay đổi<textarea name="release_notes" placeholder="Các thay đổi trong phiên bản này"></textarea></label>
<label class="dev-field wide">Ảnh chụp màn hình (mỗi URL HTTPS một dòng, tối đa 12)<textarea name="screenshots_urls" placeholder="https://cdn.example.com/screenshot-1.png&#10;https://cdn.example.com/screenshot-2.png"></textarea></label>
<label class="dev-field wide">Cấu hình tối thiểu<textarea name="minimum_requirements" placeholder="Ví dụ: Windows 10 64-bit, RAM 4 GB..."></textarea></label>
<label class="dev-field wide">Thông tin quyền riêng tư<textarea name="privacy_details" placeholder="Dữ liệu ứng dụng thu thập và mục đích sử dụng"></textarea></label>
<label class="dev-field wide">Gói cài đặt (APK, EXE, MSI, DMG, ZIP...)<input id="releasePackage" type="file" accept=".ipa,.apk,.apks,.xapk,.zip,.7z,.rar,.exe,.msi,.dmg,.pkg,.deb,.rpm,.tar,.gz,.pdf,.appx,.msix,.appimage" required></label>
<div class="dev-field wide"><button class="dev-button" id="submitRelease" type="submit">Tải lên cloud & kiểm tra bảo mật</button></div>
</form>
<div class="dev-state" id="releaseResult" style="margin-top:14px">Chưa gửi phát hành.</div>
</section>

<section class="dev-card dev-card-full" id="myAppsCard">
<span class="dev-kicker">MY RELEASES</span><h2>Ứng dụng & trạng thái phát hành</h2><div id="developerApps" class="dev-list">Đang tải…</div>
</section>

<section class="dev-card dev-card-full" id="analyticsCard">
<span class="dev-kicker">PRODUCT ANALYTICS</span><h2>Thống kê ứng dụng</h2><div id="analyticsContent" class="dev-list"><p>Chọn ứng dụng đã phát hành để xem thống kê.</p></div>
</section>

<section class="dev-card dev-card-full" id="developerReviewsCard">
<span class="dev-kicker">CUSTOMER FEEDBACK</span><h2>Đánh giá & phản hồi</h2><div id="developerReviews" class="dev-list"><p>Đánh giá đã duyệt từ người dùng sẽ xuất hiện tại đây.</p></div>
</section>

<section class="dev-card">
<span class="dev-kicker">CODE SIGNING</span><h2>Ký số ứng dụng</h2><p>Chứng chỉ .p12, .pfx và .keystore là khóa riêng tư. HendyStore không nhận lưu chúng trong kho package công khai.</p><div class="dev-note">Kết nối dịch vụ ký số bảo mật / HSM / Key Vault cần được cấu hình riêng. Hiện trạng tích hợp được hiển thị ở đây, không giả lập chữ ký đã hoàn tất.</div><div id="signingStatus" class="dev-state" style="margin-top:12px">Đang kiểm tra cấu hình…</div>
</section>
<section class="dev-card">
<span class="dev-kicker">RELEASE TRACKS</span><h2>Alpha · Beta · Production</h2><p>Chọn kênh phát hành cho từng bản build. Bản cũ được giữ trong lịch sử phiên bản để có thể chọn lại sau khi quản trị viên duyệt.</p><div class="dev-state">Hỗ trợ luồng phát hành theo kênh. Cơ chế tự ký và hạ cấp chỉ hoạt động với phiên bản/gói đã được duyệt và còn tồn tại trong kho private.</div>
</section>
</div>
</div>
</main>
<footer class="site-footer"><div class="footer-bottom"><span>© <?= date('Y') ?> Hendy Store</span><span>Developer Console</span></div></footer>
<div class="toast" id="toast" role="status"></div>
</body></html>