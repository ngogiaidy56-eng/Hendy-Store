<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';

$slug=trim((string)($_GET['slug']??''));
$product=null;
if ($slug!=='' && strlen($slug)<=180 && preg_match('/^[a-z0-9-]+$/',$slug)) {
    try {
        $db=store_db();
        $stmt=$db->prepare("SELECT * FROM products WHERE slug=:slug AND status='published' LIMIT 1");
        $stmt->execute([':slug'=>$slug]);
        $product=$stmt->fetch()?:null;
    } catch(Throwable $error) {
        error_log('[Hendy Store detail] '.$error->getMessage());
    }
}
if (!$product) http_response_code(404);
$base=app_base_url();
$escape=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$title=$product?(string)$product['title']:'Không tìm thấy ứng dụng';
$description=$product?(string)$product['short_description']:'Ứng dụng không tồn tại hoặc hiện không được công khai.';
function detail_asset_url(string $value): string {
    if (str_starts_with($value,'media:')) return '../api.php?action=media&file='.rawurlencode(substr($value,6));
    if (preg_match('#^https://#i',$value)) return $value;
    return '../'.ltrim($value,'/');
}
$icon=$product?detail_asset_url((string)$product['icon']):'../images/dummy.png';
$download=(string)($product['download_url']??'');
$downloadUrl='';
if ($product && $download!=='') {
    $downloadUrl='../api.php?action=download_redirect&slug='.rawurlencode($slug);
}
$canonical=$base.'/apps/'.rawurlencode($slug);
$schema=$product?[
    '@context'=>'https://schema.org',
    '@type'=>'SoftwareApplication',
    'name'=>$title,
    'applicationCategory'=>(string)$product['category'],
    'operatingSystem'=>(string)$product['platform'],
    'description'=>$description,
    'softwareVersion'=>(string)$product['version']
]:null;
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="<?= $product?'index,follow':'noindex,follow' ?>">
<title><?= $escape($title) ?> — Hendy Store</title><meta name="description" content="<?= $escape($description) ?>">
<link rel="canonical" href="<?= $escape($canonical) ?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?= $escape($title) ?>"><meta property="og:description" content="<?= $escape($description) ?>"><meta property="og:image" content="<?= $escape($base.'/images/dummy.png') ?>">
<link rel="stylesheet" href="../g/fonts.css"><link rel="stylesheet" href="../css/custom.261126163517.css"><link rel="stylesheet" href="../features/storefront.css">
</head>
<body>
<header class="site-header">
<a class="brand" href="../"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span class="brand-name">Hendy <b>Store</b></span></a>
<nav class="main-nav"><a class="nav-link" href="../">Trang chủ</a><a class="nav-link active" href="../#apps">Ứng dụng</a></nav>
<div class="header-actions"><a class="account-button" href="../">← Về cửa hàng</a></div>
</header>
<main class="page-shell detail-shell">
<div class="breadcrumbs"><a href="../">Trang chủ</a><span>›</span><?php if($product): ?><a href="../#apps"><?= $escape((string)$product['category']) ?></a><span>›</span><b><?= $escape($title) ?></b><?php else: ?><b>Không tìm thấy</b><?php endif; ?></div>
<?php if(!$product): ?>
<section class="detail-panel"><h1>Không tìm thấy ứng dụng</h1><p>Ứng dụng không tồn tại hoặc đã được gỡ khỏi cửa hàng.</p><a class="button button-primary" href="../">Quay lại cửa hàng</a></section>
<?php else: ?>
<section class="detail-hero">
<img class="detail-icon" src="<?= $escape($icon) ?>" alt="<?= $escape($title) ?>">
<div><h1 class="detail-title"><?= $escape($title) ?></h1>
<div class="detail-developer">Phát triển bởi <?= $escape((string)$product['developer']) ?></div>
<div class="detail-tags"><span class="detail-tag"><?= $escape((string)$product['category']) ?></span><span class="detail-tag"><?= $escape((string)$product['version']) ?></span><span class="detail-tag"><?= $escape((string)$product['platform']) ?></span></div>
<div class="detail-meta"><div><b><?= number_format((int)$product['downloads']) ?></b><span>Lượt tải</span></div><div><b><?= number_format((float)$product['rating'],1) ?>/5</b><span>Điểm hiển thị</span></div><div><b><?= $product['price_type']==='Paid'?'Trả phí':($product['price_type']==='Freemium'?'Freemium':'Miễn phí') ?></b><span>Giá</span></div></div>
</div>
<div class="detail-actions">
<?php if($downloadUrl!=='' && $product['price_type']!=='Paid'): ?>
<a class="button button-primary" href="#storeEcosystem">Chọn bộ cài phù hợp <span>↓</span></a>
<?php else: ?><button class="button button-soft" type="button" disabled>Chưa có liên kết tải</button><?php endif; ?>
<small>Kiểm tra nguồn phát hành và tệp trước khi cài đặt.</small>
</div>
</section>
<div class="detail-description">
<article class="detail-panel"><h2>Giới thiệu</h2><p><?= $escape((string)$product['description']) ?></p></article>
<aside class="detail-aside"><h3>Thông tin ứng dụng</h3><dl>
<div><dt>Nhà phát triển</dt><dd><?= $escape((string)$product['developer']) ?></dd></div>
<div><dt>Danh mục</dt><dd><?= $escape((string)$product['category']) ?></dd></div>
<div><dt>Phiên bản</dt><dd><?= $escape((string)$product['version']) ?></dd></div>
<div><dt>Nền tảng</dt><dd><?= $escape((string)$product['platform']) ?></dd></div>
<div><dt>Giá</dt><dd><?= $escape((string)$product['price_type']) ?></dd></div>
</dl></aside>
</div>
<?php endif; ?>

<div class="store-ecosystem" id="storeEcosystem" data-slug="<?= $escape($slug) ?>">
<section class="store-feature-card">
  <h2>Tải đúng phiên bản cho thiết bị</h2>
  <div class="store-build-picker"><label class="store-device-label" for="storePlatform">Thiết bị / hệ điều hành</label><select id="storePlatform" aria-label="Chọn hệ điều hành"></select><a class="button button-primary" id="storeDownload" href="#" rel="nofollow">Đang kiểm tra bộ cài…</a></div>
  <p class="store-muted" id="storeBuildMessage">Đang nhận diện thiết bị và kiểm tra bộ cài…</p>
</section>
<section class="store-feature-card"><h2>Ảnh chụp màn hình</h2><div id="storeScreenshots"><p class="store-muted">Đang tải ảnh…</p></div></section>
<section class="store-feature-card"><h2>Video giới thiệu</h2><div class="store-video" id="storeVideo"><span class="store-muted">Đang tải video…</span></div></section>
<section class="store-feature-card"><h2>Nhật ký thay đổi</h2><div id="storeChangelog"><p class="store-muted">Đang tải phiên bản…</p></div></section>
<section class="store-feature-card"><h2>Kiểm tra tính toàn vẹn tệp</h2><div class="store-specs" id="storeIntegrity">Đang xác minh SHA-256…</div></section>
<section class="store-feature-card"><h2>Ứng dụng tương tự</h2><div id="storeRelated" class="store-related-grid"><p class="store-muted">Đang tìm ứng dụng cùng danh mục…</p></div></section>
<div class="detail-description">
  <section class="store-feature-card"><h2>Cấu hình tối thiểu</h2><div class="store-specs" id="storeRequirements">Đang tải thông tin…</div></section>
  <section class="store-feature-card"><h2>Quyền riêng tư ứng dụng</h2><div class="store-specs" id="storePrivacy">Đang tải thông tin…</div></section>
</div>
<section class="store-feature-card">
  <h2>Đánh giá & phản hồi</h2>
  <div id="storeReviewStats" class="store-review-stats"><strong>—</strong><span>Đang tải đánh giá…</span></div>
  <div id="storeReviews" class="store-reviews"></div>
  <form id="storeReviewForm" class="store-review-form">
    <label>Điểm đánh giá
      <select name="rating" required><option value="5">5 sao — Rất tốt</option><option value="4">4 sao — Tốt</option><option value="3">3 sao — Bình thường</option><option value="2">2 sao — Chưa tốt</option><option value="1">1 sao — Kém</option></select>
    </label>
    <label>Nhận xét<textarea name="review_text" minlength="5" maxlength="3000" required placeholder="Chia sẻ trải nghiệm thực tế của bạn…"></textarea></label>
    <button class="button button-primary" type="submit">Gửi đánh giá</button><p class="store-muted" id="storeReviewMessage">Đánh giá sẽ được duyệt trước khi xuất hiện công khai.</p>
  </form>
</section>
</div>

</main>
<footer class="site-footer"><div class="footer-bottom"><span>© <?= date('Y') ?> Hendy Store</span><a href="../legal-notice/">Thông tin pháp lý</a><a href="../privacy/">Quyền riêng tư</a></div></footer>
<?php if($schema): ?><script type="application/ld+json"><?= json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script><?php endif; ?>
<script src="../js/neon-client.bundle.js" defer></script><script src="../features/storefront.js" defer></script>
</body></html>