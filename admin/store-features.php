<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Store Ecosystem — HendyStore Admin</title>
<link rel="stylesheet" href="../g/fonts.css"><link rel="stylesheet" href="../css/custom.261126163517.css"><link rel="stylesheet" href="../features/storefront.css">
<script src="../js/neon-client.bundle.js" defer></script><script src="store-features.js" defer></script>
</head>
<body>
<header class="site-header"><a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a>
<nav class="main-nav"><a class="nav-link" href="../">Cửa hàng</a><a class="nav-link active" href="./">Quản trị</a></nav>
<div class="header-actions"><a class="account-button" href="./">← Bảng quản trị</a></div></header>
<main class="admin-shell">
<div class="admin-top"><div><span class="section-kicker">STORE ECOSYSTEM CONTROL</span><h1>Quản lý hệ sinh thái Store</h1><p>Phiên bản, nền tảng, ảnh, cấu hình, đánh giá và phản hồi.</p></div><span class="detail-tag" id="adminStatus">Đang kiểm tra quyền…</span></div>
<section class="admin-card" id="accessDenied" hidden><h2>Không có quyền truy cập</h2><p>Đăng nhập bằng tài khoản quản trị rồi mở lại trang này.</p><a class="button button-primary" href="../">Về cửa hàng</a></section>
<div id="featureAdmin" hidden>
<section class="admin-card">
<label>Chọn ứng dụng<select class="admin-select" id="productSelect"><option value="">Đang tải…</option></select></label>
</section>
<div class="store-admin-grid">
<section class="admin-card">
<h2>Phát hành phiên bản</h2>
<form id="versionForm">
<label>Phiên bản<input class="admin-input" name="version" required maxlength="60" placeholder="2.0.0"></label>
<div class="admin-actions">
<label>Nền tảng<select class="admin-select" name="platform"><option value="universal">Mọi nền tảng</option><option value="windows">Windows</option><option value="macos">macOS</option><option value="android">Android</option><option value="linux">Linux</option><option value="web">Web</option></select></label>
<label>Kiến trúc<select class="admin-select" name="architecture"><option value="universal">Universal</option><option value="x64">x64</option><option value="x86">x86</option><option value="arm64">ARM64</option><option value="armv7">ARMv7</option></select></label>
</div>
<label>Yêu cầu OS tối thiểu<input class="admin-input" name="min_os" placeholder="Windows 10 / Android 10 / macOS 13"></label>
<label>Gói cài đặt URL HTTPS hoặc tải file<input class="admin-input" name="package_url" required placeholder="https://... hoặc tải file phía dưới"></label>
<label>Tải gói cài đặt<input class="admin-input" id="versionPackage" type="file" accept=".apk,.apks,.xapk,.zip,.7z,.rar,.exe,.msi,.dmg,.pkg,.deb,.rpm,.tar,.gz,.pdf,.appx,.msix,.appimage"></label>
<label>Dung lượng (byte)<input class="admin-input" name="package_size_bytes" type="number" min="0" value="0"></label>
<label>Nhật ký thay đổi<textarea class="admin-textarea" name="release_notes" maxlength="12000" placeholder="• Tính năng mới&#10;• Sửa lỗi&#10;• Cải thiện hiệu suất"></textarea></label>
<label class="admin-check"><input type="checkbox" name="is_current" checked> Đặt làm bản mới nhất cho ứng dụng</label>
<button class="button button-primary" type="submit">Phát hành phiên bản</button><p class="admin-status" id="versionStatus"></p>
</form>
<h3>Danh sách phiên bản</h3><div id="versionList" class="store-version-list"></div>
</section>
<section class="admin-card">
<h2>Ảnh chụp màn hình</h2>
<form id="screenshotForm">
<label>Tệp ảnh JPG / PNG / WEBP<input class="admin-input" id="screenshotFile" type="file" accept="image/jpeg,image/png,image/webp" required></label>
<label>Chú thích<input class="admin-input" name="caption" maxlength="180"></label>
<button class="button button-primary" type="submit">Tải ảnh và thêm</button><p class="admin-status" id="screenshotStatus"></p>
</form><div id="screenshotList" class="store-admin-screens"></div>
</section>
<section class="admin-card">
<h2>Yêu cầu cấu hình & quyền riêng tư</h2>
<form id="specsForm">
<label>Video giới thiệu (URL HTTPS)<input class="admin-input" name="video_url" type="url"></label>
<label>Cấu hình tối thiểu<textarea class="admin-textarea" name="minimum_requirements" maxlength="8000" placeholder="CPU, RAM, dung lượng trống, hệ điều hành..."></textarea></label>
<label>Thông tin quyền riêng tư<textarea class="admin-textarea" name="privacy_details" maxlength="8000" placeholder="Dữ liệu thu thập, mục đích, bên chia sẻ, thời hạn lưu..."></textarea></label>
<button class="button button-primary" type="submit">Lưu thông tin</button><p class="admin-status" id="specsStatus"></p>
</form>
</section>
<section class="admin-card">
<h2>Đánh giá & phản hồi nhà phát triển</h2>
<p class="store-muted">Đánh giá mới ở trạng thái chờ duyệt. Chỉ nội dung được duyệt mới hiển thị công khai. Quản trị viên thực hiện vai trò nhà phát hành trong phiên bản này.</p>
<div id="reviewModeration"></div>
</section>
</div>
</div>
</main><div class="toast" id="toast" role="status"></div>
</body></html>
