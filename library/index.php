<?php
declare(strict_types=1);
?><!doctype html><html lang="vi"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow">
<title>Thư viện của tôi — HendyStore</title>
<link rel="stylesheet" href="../g/fonts.css"><link rel="stylesheet" href="../css/custom.261126163517.css"><link rel="stylesheet" href="../features/storefront.css">
<script src="../js/neon-client.bundle.js" defer></script><script src="library.js" defer></script></head><body>
<header class="site-header"><a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a><nav class="main-nav"><a class="nav-link" href="../">Cửa hàng</a><a class="nav-link active" href="./">Thư viện</a></nav><div class="header-actions"><a class="account-button" href="../">← Cửa hàng</a></div></header>
<main class="admin-shell">
<div class="admin-top"><div><span class="section-kicker">YOUR STORE LIBRARY</span><h1>Thư viện của tôi</h1><p>Ứng dụng đã tải, phiên bản mới và lịch sử tải xuống.</p></div><span class="detail-tag" id="libraryIdentity">Đang tải…</span></div>
<section id="libraryLogin" class="admin-card" hidden><h2>Đăng nhập để xem thư viện</h2><p>Thư viện cá nhân và lịch sử tải được liên kết với tài khoản của bạn.</p><a class="button button-primary" href="../">Đến trang đăng nhập</a></section>
<div id="libraryContent" hidden>
<section class="admin-card"><div class="section-heading"><div><span class="section-kicker">MY APPS</span><h2>Ứng dụng của tôi</h2></div></div><div id="libraryItems" class="store-library-grid"></div></section>
<section class="admin-card"><div class="section-heading"><div><span class="section-kicker">UPDATES & ALERTS</span><h2>Thông báo cập nhật</h2></div><button class="button button-soft" id="readAll" type="button">Đánh dấu đã đọc</button></div><div id="libraryNotifications"></div></section>
<section class="admin-card"><span class="section-kicker">DOWNLOAD LOG</span><h2>Lịch sử tải xuống</h2><div id="libraryHistory"></div></section>
</div></main><div class="toast" id="toast" role="status"></div></body></html>
