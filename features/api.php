<?php
declare(strict_types=1);

/**
 * HendyStore Store Ecosystem API.
 * Additive tables only: the existing users/products/session schema is not rewritten.
 */
function hendystore_feature_schema(PDO $db): void
{
    static $ready = false;
    if ($ready) return;

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $marker = $db->prepare("SELECT meta_value FROM app_meta WHERE meta_key = :key");
        $marker->execute([':key' => 'store_ecosystem_v1']);
        if ($marker->fetchColumn() === '1') {
            $ready = true;
            return;
        }
    } catch (Throwable $error) {
        // The core bootstrap normally creates app_meta; continue with idempotent DDL if not.
    }
    $id = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

    $db->exec("CREATE TABLE IF NOT EXISTS app_product_specs (
        product_id BIGINT PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        video_url TEXT NOT NULL DEFAULT '',
        minimum_requirements TEXT NOT NULL DEFAULT '',
        privacy_details TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_product_screenshots (
        id $id,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        image_url TEXT NOT NULL,
        caption VARCHAR(180) NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_versions (
        id $id,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        version VARCHAR(60) NOT NULL,
        release_notes TEXT NOT NULL DEFAULT '',
        platform VARCHAR(40) NOT NULL DEFAULT 'universal',
        architecture VARCHAR(40) NOT NULL DEFAULT 'universal',
        min_os VARCHAR(120) NOT NULL DEFAULT '',
        package_url TEXT NOT NULL,
        package_size_bytes BIGINT NOT NULL DEFAULT 0,
        is_current SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_product_reviews (
        id $id,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
        review_text TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        developer_reply TEXT NOT NULL DEFAULT '',
        developer_reply_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL,
        updated_at TIMESTAMP NOT NULL,
        UNIQUE(product_id, user_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_user_library (
        id $id,
        user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        source VARCHAR(20) NOT NULL DEFAULT 'download',
        last_version_id BIGINT NULL,
        last_platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
        created_at TIMESTAMP NOT NULL,
        updated_at TIMESTAMP NOT NULL,
        UNIQUE(user_id, product_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_download_history (
        id $id,
        user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        version_id BIGINT NULL,
        platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
        package_name VARCHAR(180) NOT NULL DEFAULT '',
        ip_hash VARCHAR(64) NOT NULL DEFAULT '',
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_user_notifications (
        id $id,
        user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        product_id BIGINT NULL REFERENCES products(id) ON DELETE CASCADE,
        version_id BIGINT NULL,
        notification_type VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL DEFAULT '',
        read_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_download_grants (
        token_hash VARCHAR(64) PRIMARY KEY,
        user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        version_id BIGINT NULL,
        platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
        expires_at BIGINT NOT NULL,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_app_versions_product ON app_versions(product_id, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_app_reviews_product ON app_product_reviews(product_id, status, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_app_library_user ON app_user_library(user_id, updated_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_app_download_user ON app_download_history(user_id, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_app_notifications_user ON app_user_notifications(user_id, read_at, created_at)');
    try {
        $mark = $db->prepare("INSERT INTO app_meta(meta_key,meta_value) VALUES('store_ecosystem_v1','1') ON CONFLICT(meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value");
        $mark->execute();
    } catch (Throwable $error) {
        error_log('[Hendy Store schema] Could not persist migration marker: ' . get_class($error));
    }
    $ready = true;
}

function hendystore_feature_user(PDO $db): array
{
    $user = current_user($db);
    if (!$user) json_response(['ok' => false, 'error' => 'Vui lòng đăng nhập để sử dụng tính năng này.'], 401);
    return $user;
}

function hendystore_feature_slug(PDO $db, string $slug, bool $published = true): array
{
    if ($slug === '' || strlen($slug) > 180) json_response(['ok' => false, 'error' => 'Ứng dụng không hợp lệ.'], 422);
    $sql = 'SELECT * FROM products WHERE slug = :slug' . ($published ? " AND status = 'published'" : '') . ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([':slug' => $slug]);
    $product = $stmt->fetch();
    if (!$product) json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng.'], 404);
    return $product;
}

function hendystore_feature_safe_package_url(string $url): bool
{
    if (str_starts_with($url, 'file:')) return valid_package_token(substr($url, 5));
    $parts = parse_url($url);
    return filter_var($url, FILTER_VALIDATE_URL)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function hendystore_feature_add_library(PDO $db, int $userId, int $productId, ?int $versionId, string $platform = 'unknown'): void
{
    $sql = "INSERT INTO app_user_library(user_id, product_id, source, last_version_id, last_platform, created_at, updated_at)
            VALUES(:user_id, :product_id, 'download', :version_id, :platform, :created_at, :updated_at)
            ON CONFLICT(user_id, product_id) DO UPDATE SET
                last_version_id = COALESCE(EXCLUDED.last_version_id, app_user_library.last_version_id),
                last_platform = CASE WHEN EXCLUDED.last_version_id IS NOT NULL THEN EXCLUDED.last_platform ELSE app_user_library.last_platform END,
                updated_at = EXCLUDED.updated_at";
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':product_id' => $productId,
        ':version_id' => $versionId,
        ':platform' => $platform,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function hendystore_feature_update_rating(PDO $db, int $productId): void
{
    $stmt = $db->prepare("SELECT AVG(rating) AS avg_rating FROM app_product_reviews WHERE product_id = :id AND status = 'approved'");
    $stmt->execute([':id' => $productId]);
    $rating = (float)($stmt->fetchColumn() ?: 0);
    $update = $db->prepare('UPDATE products SET rating = :rating WHERE id = :id');
    $update->execute([':rating' => round($rating, 1), ':id' => $productId]);
}

function hendystore_feature_download(PDO $db): never
{
    require_method('GET');
    $grantToken = trim((string)($_GET['grant'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $grantToken)) {
        http_response_code(403);
        exit;
    }

    $grantHash = hash('sha256', $grantToken);
    $grantStmt = $db->prepare('SELECT token_hash,user_id,product_id,version_id,platform,expires_at,used_at FROM app_download_grants WHERE token_hash = :hash LIMIT 1');
    $grantStmt->execute([':hash' => $grantHash]);
    $grant = $grantStmt->fetch();
    if (!$grant || !empty($grant['used_at']) || (int)$grant['expires_at'] < time()) {
        http_response_code(410);
        header('Cache-Control: no-store');
        exit('Phiếu tải đã hết hạn hoặc đã được sử dụng.');
    }

    $productStmt = $db->prepare("SELECT * FROM products WHERE id = :id AND status = 'published' LIMIT 1");
    $productStmt->execute([':id' => (int)$grant['product_id']]);
    $product = $productStmt->fetch();
    if (!$product) { http_response_code(404); exit; }
    if (($product['price_type'] ?? '') === 'Paid') {
        http_response_code(403);
        header('Cache-Control: no-store');
        exit('Sản phẩm trả phí không thể tải qua phiếu tải miễn phí.');
    }
    $versionId = (int)($grant['version_id'] ?? 0);
    $version = null;
    if ($versionId > 0) {
        $versionStmt = $db->prepare("SELECT * FROM app_versions WHERE id = :id AND product_id = :product_id AND release_track = 'production' LIMIT 1");
        $versionStmt->execute([':id' => $versionId, ':product_id' => (int)$product['id']]);
        $version = $versionStmt->fetch() ?: null;
        if (!$version) {
            http_response_code(404);
            exit;
        }
    }
    $packageUrl = (string)($version['package_url'] ?? $product['download_url'] ?? '');
    if ($packageUrl === '' || !hendystore_security_package_downloadable($db, (int)$product['id'], $packageUrl)) {
        http_response_code(404);
        exit;
    }
    $packageToken = str_starts_with($packageUrl, 'file:') ? substr($packageUrl, 5) : '';
    if ($packageToken !== '' && !neon_storage_exists('packages/' . $packageToken)) {
        http_response_code(404);
        exit;
    }

    // Apply the per-IP limit before consuming the one-time ticket so a
    // throttled attempt cannot burn an otherwise valid download grant.
    rate_limit_or_fail($db, 'store-version-download', 80, 3600);

    $consume = $db->prepare('UPDATE app_download_grants SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL AND expires_at >= :now_epoch');
    $now = date('Y-m-d H:i:s');
    $consume->execute([':now' => $now, ':hash' => $grantHash, ':now_epoch' => time()]);
    if ($consume->rowCount() !== 1) {
        http_response_code(410);
        header('Cache-Control: no-store');
        exit('Phiếu tải đã hết hạn hoặc đã được sử dụng.');
    }
    $userId = $grant['user_id'] !== null ? (int)$grant['user_id'] : null;
    $platform = strtolower(trim((string)$grant['platform']));
    if (!in_array($platform, ['windows', 'macos', 'android', 'ios', 'linux', 'web', 'unknown'], true)) $platform = 'unknown';
    $ipSecret = (string)env_value('APP_KEY', hash('sha256', __FILE__));
    $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $history = $db->prepare('INSERT INTO app_download_history(user_id,product_id,version_id,platform,package_name,ip_hash,user_agent,created_at) VALUES(:user_id,:product_id,:version_id,:platform,:package_name,:ip_hash,:user_agent,:created_at)');
    $history->execute([
        ':user_id' => $userId,
        ':product_id' => (int)$product['id'],
        ':version_id' => $versionId > 0 ? $versionId : null,
        ':platform' => $platform,
        ':package_name' => basename((string)parse_url($packageUrl, PHP_URL_PATH)),
        ':ip_hash' => hash_hmac('sha256', request_ip(), $ipSecret),
        ':user_agent' => $agent,
        ':created_at' => $now,
    ]);
    hendystore_security_track_event($db, (int)$product['id'], 'download_completed');
    $update = $db->prepare('UPDATE products SET downloads = downloads + 1 WHERE id = :id');
    $update->execute([':id' => (int)$product['id']]);
    if ($userId !== null) {
        hendystore_feature_add_library($db, $userId, (int)$product['id'], $versionId > 0 ? $versionId : null, $platform);
    }

    header('Referrer-Policy: no-referrer');
    if ($packageToken !== '') {
        try {
            $object = neon_storage_get('packages/' . $packageToken);
            $body = $object['Body'];
            $length = $object['ContentLength'] ?? (is_object($body) && method_exists($body, 'getSize') ? $body->getSize() : null);
            $mime = (string)($object['ContentType'] ?? 'application/octet-stream');
            if (!preg_match('#^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$#i', $mime)) $mime = 'application/octet-stream';
            @set_time_limit(0);
            header('Content-Type: ' . $mime);
            if (is_numeric($length)) header('Content-Length: ' . (string)$length);
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '-', (string)$product['slug']) . '.' . pathinfo($packageToken, PATHINFO_EXTENSION) . '"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-Accel-Buffering: no');
            while (is_object($body) && method_exists($body, 'eof') && !$body->eof()) {
                echo $body->read(1024 * 1024);
                if (connection_aborted()) break;
            }
            exit;
        } catch (Throwable $error) {
            error_log('[Hendy Store version download] ' . get_class($error));
            http_response_code(503);
            exit;
        }
    }

    header('Cache-Control: no-store');
    header('Location: ' . $packageUrl, true, 302);
    exit;
}

function hendystore_feature_api(PDO $db, string $action, string $method): bool
{
    $routes = [
        'store_product_extras', 'store_reviews', 'store_review', 'store_review_reply',
        'store_review_moderate', 'store_library', 'store_notifications',
        'store_notification_read', 'store_download_history', 'store_library_add',
        'store_download_prepare', 'store_version_download', 'store_admin_versions', 'store_admin_screenshots',
        'store_admin_specs', 'store_admin_reviews',
    ];
    if (!in_array($action, $routes, true)) return false;

    hendystore_feature_schema($db);

    switch ($action) {
        case 'store_product_extras': {
            require_method('GET');
            $product = hendystore_feature_slug($db, trim((string)($_GET['slug'] ?? '')));
            $specStmt = $db->prepare('SELECT video_url, minimum_requirements, privacy_details FROM app_product_specs WHERE product_id = :id LIMIT 1');
            $specStmt->execute([':id' => (int)$product['id']]);
            $specs = $specStmt->fetch() ?: ['video_url' => '', 'minimum_requirements' => '', 'privacy_details' => ''];
            $shotStmt = $db->prepare('SELECT id, image_url, caption FROM app_product_screenshots WHERE product_id = :id ORDER BY sort_order ASC, id ASC LIMIT 24');
            $shotStmt->execute([':id' => (int)$product['id']]);
            $shots = $shotStmt->fetchAll();
            if (!$shots) {
                $legacy = json_decode((string)($product['screenshots_json'] ?? '[]'), true);
                if (is_array($legacy)) {
                    foreach ($legacy as $item) if (is_string($item) && $item !== '') $shots[] = ['id' => 0, 'image_url' => $item, 'caption' => ''];
                }
            }
            $versionStmt = $db->prepare("SELECT id, version, release_notes, platform, architecture, min_os, package_url, package_size_bytes, is_current, created_at FROM app_versions WHERE product_id = :id AND release_track = 'production' ORDER BY is_current DESC, created_at DESC, id DESC");
            $versionStmt->execute([':id' => (int)$product['id']]);
            $versionRows = $versionStmt->fetchAll();
            $versions = [];
            foreach ($versionRows as $candidate) {
                if (hendystore_security_package_downloadable($db, (int)$product['id'], (string)$candidate['package_url'], false)) {
                    unset($candidate['package_url']);
                    $versions[] = $candidate;
                }
            }
            if (!$versions && !empty($product['download_url'])) {
                $versions[] = [
                    'id' => 0, 'version' => $product['version'], 'release_notes' => '',
                    'platform' => 'universal', 'architecture' => 'universal', 'min_os' => '',
                    'package_size_bytes' => 0, 'is_current' => 1, 'created_at' => $product['updated_at'],
                ];
            }
            $stats = $db->prepare("SELECT COUNT(*) AS review_count, COALESCE(AVG(rating),0) AS average_rating FROM app_product_reviews WHERE product_id = :id AND status = 'approved'");
            $stats->execute([':id' => (int)$product['id']]);
            $integrity = null;
            foreach ($versionRows as $candidate) {
                if (empty($candidate['is_current'])) continue;
                $packageUrl = (string)$candidate['package_url'];
                if (str_starts_with($packageUrl, 'file:')) {
                    $package = hendystore_security_package_row($db, 'packages/' . substr($packageUrl, 5));
                    if ($package && ($package['moderation_status'] ?? '') === 'approved' && ($package['scan_status'] ?? '') === 'clean' && preg_match('/^[a-f0-9]{64}$/', (string)$package['sha256'])) {
                        $integrity = ['sha256' => $package['sha256'], 'package_size_bytes' => (int)$package['file_size_bytes']];
                        break;
                    }
                }
            }
            json_response(['ok' => true, 'data' => [
                'screenshots' => $shots,
                'versions' => $versions,
                'specs' => $specs,
                'reviewStats' => $stats->fetch() ?: ['review_count' => 0, 'average_rating' => 0],
                'integrity' => $integrity ? ['sha256' => $integrity['sha256'], 'size' => (int)$integrity['package_size_bytes'], 'verified' => true] : null,
                'canDownload' => true,
            ]]);
        }

        case 'store_reviews': {
            require_method('GET');
            $product = hendystore_feature_slug($db, trim((string)($_GET['slug'] ?? '')));
            $stmt = $db->prepare("SELECT r.id, r.rating, r.review_text, r.developer_reply, r.developer_reply_at, r.created_at, u.name AS author_name
                FROM app_product_reviews r JOIN users u ON u.id = r.user_id
                WHERE r.product_id = :product_id AND r.status = 'approved'
                ORDER BY r.created_at DESC LIMIT 50");
            $stmt->execute([':product_id' => (int)$product['id']]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        case 'store_review': {
            require_method('POST');
            require_csrf();
            $user = hendystore_feature_user($db);
            rate_limit_or_fail($db, 'store-review', 10, 3600);
            $input = request_data();
            $product = hendystore_feature_slug($db, trim((string)($input['slug'] ?? '')));
            $rating = (int)($input['rating'] ?? 0);
            $reviewText = trim((string)($input['review_text'] ?? ''));
            if ($rating < 1 || $rating > 5 || strlen($reviewText) < 5 || strlen($reviewText) > 3000) {
                json_response(['ok' => false, 'error' => 'Đánh giá cần 1–5 sao và nhận xét từ 5 đến 3.000 ký tự.'], 422);
            }
            $existing = $db->prepare('SELECT id FROM app_product_reviews WHERE product_id = :product_id AND user_id = :user_id LIMIT 1');
            $existing->execute([':product_id' => (int)$product['id'], ':user_id' => (int)$user['id']]);
            $id = $existing->fetchColumn();
            $now = date('Y-m-d H:i:s');
            if ($id) {
                $stmt = $db->prepare("UPDATE app_product_reviews SET rating = :rating, review_text = :review_text, status = 'pending', developer_reply = '', developer_reply_at = NULL, updated_at = :updated_at WHERE id = :id");
                $stmt->execute([':rating' => $rating, ':review_text' => $reviewText, ':updated_at' => $now, ':id' => (int)$id]);
            } else {
                $stmt = $db->prepare("INSERT INTO app_product_reviews(product_id,user_id,rating,review_text,status,created_at,updated_at) VALUES(:product_id,:user_id,:rating,:review_text,'pending',:created_at,:updated_at)");
                $stmt->execute([':product_id' => (int)$product['id'], ':user_id' => (int)$user['id'], ':rating' => $rating, ':review_text' => $reviewText, ':created_at' => $now, ':updated_at' => $now]);
            }
            json_response(['ok' => true, 'message' => 'Đánh giá đã gửi và đang chờ duyệt.']);
        }

        case 'store_review_reply': {
            require_method('POST');
            require_admin($db);
            require_csrf();
            $input = request_data();
            $reviewId = (int)($input['review_id'] ?? 0);
            $reply = trim((string)($input['reply'] ?? ''));
            if ($reviewId < 1 || $reply === '' || strlen($reply) > 2000) json_response(['ok' => false, 'error' => 'Nội dung phản hồi không hợp lệ.'], 422);
            $stmt = $db->prepare("UPDATE app_product_reviews SET developer_reply = :reply, developer_reply_at = :now, updated_at = :now WHERE id = :id AND status = 'approved'");
            $stmt->execute([':reply' => $reply, ':now' => date('Y-m-d H:i:s'), ':id' => $reviewId]);
            if ($stmt->rowCount() < 1) json_response(['ok' => false, 'error' => 'Không tìm thấy đánh giá đã duyệt.'], 404);
            json_response(['ok' => true]);
        }

        case 'store_review_moderate': {
            require_method('POST');
            require_admin($db);
            require_csrf();
            $input = request_data();
            $reviewId = (int)($input['review_id'] ?? 0);
            $status = (string)($input['status'] ?? '');
            if ($reviewId < 1 || !in_array($status, ['approved', 'rejected'], true)) json_response(['ok' => false, 'error' => 'Trạng thái duyệt không hợp lệ.'], 422);
            $stmt = $db->prepare('SELECT product_id FROM app_product_reviews WHERE id = :id');
            $stmt->execute([':id' => $reviewId]);
            $productId = (int)$stmt->fetchColumn();
            if (!$productId) json_response(['ok' => false, 'error' => 'Không tìm thấy đánh giá.'], 404);
            $update = $db->prepare('UPDATE app_product_reviews SET status = :status, updated_at = :now WHERE id = :id');
            $update->execute([':status' => $status, ':now' => date('Y-m-d H:i:s'), ':id' => $reviewId]);
            hendystore_feature_update_rating($db, $productId);
            json_response(['ok' => true]);
        }

        case 'store_library': {
            require_method('GET');
            $user = hendystore_feature_user($db);
            $stmt = $db->prepare("SELECT p.id AS product_id, p.slug, p.title, p.developer, p.icon, p.version AS current_version,
                l.source, l.created_at AS added_at, l.updated_at,
                l.last_version_id, v.version AS downloaded_version,
                (SELECT id FROM app_versions av WHERE av.product_id = p.id AND av.is_current = 1 AND (av.platform = l.last_platform OR av.platform = 'universal') ORDER BY CASE WHEN av.platform = l.last_platform THEN 0 ELSE 1 END, av.created_at DESC LIMIT 1) AS latest_version_id,
                (SELECT version FROM app_versions av WHERE av.product_id = p.id AND av.is_current = 1 AND (av.platform = l.last_platform OR av.platform = 'universal') ORDER BY CASE WHEN av.platform = l.last_platform THEN 0 ELSE 1 END, av.created_at DESC LIMIT 1) AS latest_version
                FROM app_user_library l JOIN products p ON p.id = l.product_id
                LEFT JOIN app_versions v ON v.id = l.last_version_id
                WHERE l.user_id = :user_id AND p.status = 'published'
                ORDER BY l.updated_at DESC");
            $stmt->execute([':user_id' => (int)$user['id']]);
            $items = $stmt->fetchAll();
            foreach ($items as &$item) {
                $item['update_available'] = !empty($item['latest_version_id'])
                    && (int)$item['latest_version_id'] !== (int)($item['last_version_id'] ?? 0);
            }
            unset($item);
            json_response(['ok' => true, 'data' => $items]);
        }

        case 'store_notifications': {
            require_method('GET');
            $user = hendystore_feature_user($db);
            $stmt = $db->prepare('SELECT id, notification_type, title, message, product_id, version_id, read_at, created_at FROM app_user_notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 60');
            $stmt->execute([':user_id' => (int)$user['id']]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        case 'store_notification_read': {
            require_method('POST');
            require_csrf();
            $user = hendystore_feature_user($db);
            $input = request_data();
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE app_user_notifications SET read_at = :now WHERE id = :id AND user_id = :user_id');
                $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id, ':user_id' => (int)$user['id']]);
            } else {
                $stmt = $db->prepare('UPDATE app_user_notifications SET read_at = :now WHERE user_id = :user_id AND read_at IS NULL');
                $stmt->execute([':now' => date('Y-m-d H:i:s'), ':user_id' => (int)$user['id']]);
            }
            json_response(['ok' => true]);
        }

        case 'store_download_history': {
            require_method('GET');
            $user = hendystore_feature_user($db);
            $stmt = $db->prepare("SELECT h.id, h.platform, h.package_name, h.created_at, p.title, p.slug, p.icon, v.version
                FROM app_download_history h JOIN products p ON p.id = h.product_id
                LEFT JOIN app_versions v ON v.id = h.version_id
                WHERE h.user_id = :user_id ORDER BY h.created_at DESC LIMIT 100");
            $stmt->execute([':user_id' => (int)$user['id']]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        case 'store_library_add': {
            require_method('POST');
            require_csrf();
            $user = hendystore_feature_user($db);
            $input = request_data();
            $product = hendystore_feature_slug($db, trim((string)($input['slug'] ?? '')));
            $platform = strtolower((string)($input['platform'] ?? 'unknown'));
            if (!in_array($platform, ['windows','macos','android','ios','linux','web','unknown'], true)) $platform = 'unknown';
            hendystore_feature_add_library($db, (int)$user['id'], (int)$product['id'], null, $platform);
            json_response(['ok' => true]);
        }

        case 'store_download_prepare': {
            require_method('POST');
            require_csrf();
            $input = request_data();
            $product = hendystore_feature_slug($db, trim((string)($input['slug'] ?? '')));
            if (($product['price_type'] ?? '') === 'Paid') {
                json_response(['ok' => false, 'error' => 'Sản phẩm trả phí không được tải qua luồng miễn phí.'], 403);
            }
            $versionId = max(0, (int)($input['version_id'] ?? 0));
            $version = null;
            if ($versionId > 0) {
                $versionStmt = $db->prepare("SELECT * FROM app_versions WHERE id = :id AND product_id = :product_id AND release_track = 'production' LIMIT 1");
                $versionStmt->execute([':id' => $versionId, ':product_id' => (int)$product['id']]);
                $version = $versionStmt->fetch() ?: null;
                if (!$version) json_response(['ok' => false, 'error' => 'Phiên bản này không còn khả dụng.'], 404);
            }
            $packageUrl = (string)($version['package_url'] ?? $product['download_url'] ?? '');
            if ($packageUrl === '' || !hendystore_security_package_downloadable($db, (int)$product['id'], $packageUrl)) {
                json_response(['ok' => false, 'error' => 'Phiên bản chưa vượt qua quét bảo mật/kiểm duyệt hoặc gói tải hiện không khả dụng.'], 404);
            }
            $platform = strtolower(trim((string)($input['platform'] ?? 'unknown')));
            if (!in_array($platform, ['windows', 'macos', 'android', 'ios', 'linux', 'web', 'unknown'], true)) $platform = 'unknown';
            if ($platform === 'ios') {
                json_response(['ok' => false, 'error' => 'iOS cần phân phối qua App Store, TestFlight hoặc MDM; không hỗ trợ sideload IPA trực tiếp.'], 422);
            }
            $user = current_user($db);
            $token = bin2hex(random_bytes(32));
            $insert = $db->prepare('INSERT INTO app_download_grants(token_hash,user_id,product_id,version_id,platform,expires_at,created_at) VALUES(:token_hash,:user_id,:product_id,:version_id,:platform,:expires_at,:created_at)');
            $insert->execute([
                ':token_hash' => hash('sha256', $token),
                ':user_id' => $user ? (int)$user['id'] : null,
                ':product_id' => (int)$product['id'],
                ':version_id' => $versionId > 0 ? $versionId : null,
                ':platform' => $platform,
                ':expires_at' => time() + 600,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            hendystore_security_track_event($db, (int)$product['id'], 'download_prepare');
            json_response(['ok' => true, 'data' => [
                'url' => app_base_url() . '/api.php?action=store_version_download&grant=' . rawurlencode($token),
                'expiresIn' => 600,
            ]]);
        }

        case 'store_version_download':
            hendystore_feature_download($db);

        case 'store_admin_versions': {
            require_admin($db);
            if ($method === 'GET') {
                $productId = (int)($_GET['product_id'] ?? 0);
                if ($productId > 0) {
                    $stmt = $db->prepare('SELECT id,product_id,version,release_notes,platform,architecture,min_os,package_size_bytes,is_current,created_at FROM app_versions WHERE product_id = :product_id ORDER BY created_at DESC,id DESC');
                    $stmt->execute([':product_id' => $productId]);
                } else {
                    $stmt = $db->query('SELECT id,product_id,version,release_notes,platform,architecture,min_os,package_size_bytes,is_current,created_at FROM app_versions ORDER BY created_at DESC,id DESC LIMIT 300');
                }
                json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
            }
            require_method('POST');
            require_csrf();
            $input = request_data();
            $productId = (int)($input['product_id'] ?? 0);
            $productStmt = $db->prepare('SELECT id,title FROM products WHERE id = :id');
            $productStmt->execute([':id' => $productId]);
            $product = $productStmt->fetch();
            $version = trim((string)($input['version'] ?? ''));
            $notes = trim((string)($input['release_notes'] ?? ''));
            $platform = strtolower((string)($input['platform'] ?? 'universal'));
            $architecture = strtolower((string)($input['architecture'] ?? 'universal'));
            $minOs = trim((string)($input['min_os'] ?? ''));
            $packageUrl = trim((string)($input['package_url'] ?? ''));
            $size = max(0, (int)($input['package_size_bytes'] ?? 0));
            $allowedPlatforms = ['windows', 'macos', 'android', 'ios', 'linux', 'web', 'universal'];
            $allowedArch = ['x64', 'x86', 'arm64', 'armv7', 'universal'];
            if (!$product || $version === '' || strlen($version) > 60 || strlen($notes) > 12000 || strlen($minOs) > 120 || !in_array($platform, $allowedPlatforms, true) || !in_array($architecture, $allowedArch, true) || $packageUrl === '' || !hendystore_feature_safe_package_url($packageUrl)) {
                json_response(['ok' => false, 'error' => 'Thông tin phiên bản hoặc gói cài đặt không hợp lệ.'], 422);
            }
            if (str_starts_with($packageUrl, 'file:') && !neon_storage_exists('packages/' . substr($packageUrl, 5))) json_response(['ok' => false, 'error' => 'Không tìm thấy gói trong Object Storage.'], 422);
            if ($size > 10 * 1024 * 1024 * 1024) json_response(['ok' => false, 'error' => 'Dung lượng gói vượt quá 10 GB.'], 422);
            $current = !empty($input['is_current']);
            if ($current) {
                $clear = $db->prepare('UPDATE app_versions SET is_current = 0 WHERE product_id = :product_id AND platform = :platform');
                $clear->execute([':product_id' => $productId, ':platform' => $platform]);
            }
            $now = date('Y-m-d H:i:s');
            $params = [':product_id' => $productId, ':version' => $version, ':release_notes' => $notes, ':platform' => $platform, ':architecture' => $architecture, ':min_os' => $minOs, ':package_url' => $packageUrl, ':package_size_bytes' => $size, ':is_current' => $current ? 1 : 0, ':created_at' => $now];
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $insert = $db->prepare('INSERT INTO app_versions(product_id,version,release_notes,platform,architecture,min_os,package_url,package_size_bytes,is_current,created_at) VALUES(:product_id,:version,:release_notes,:platform,:architecture,:min_os,:package_url,:package_size_bytes,:is_current,:created_at) RETURNING id');
                $insert->execute($params);
                $newId = (int)$insert->fetchColumn();
            } else {
                $insert = $db->prepare('INSERT INTO app_versions(product_id,version,release_notes,platform,architecture,min_os,package_url,package_size_bytes,is_current,created_at) VALUES(:product_id,:version,:release_notes,:platform,:architecture,:min_os,:package_url,:package_size_bytes,:is_current,:created_at)');
                $insert->execute($params);
                $newId = (int)$db->lastInsertId();
            }
            if ($current) {
                $update = $db->prepare('UPDATE products SET version = :version, updated_at = :updated_at WHERE id = :id');
                $update->execute([':version' => $version, ':updated_at' => $now, ':id' => $productId]);
            }
            if ($current) {
                $notify = $db->prepare('SELECT user_id FROM app_user_library WHERE product_id = :product_id');
                $notify->execute([':product_id' => $productId]);
                $notification = $db->prepare("INSERT INTO app_user_notifications(user_id,product_id,version_id,notification_type,title,message,created_at) VALUES(:user_id,:product_id,:version_id,'version_update',:title,:message,:created_at)");
                foreach ($notify->fetchAll(PDO::FETCH_COLUMN) as $notifyUserId) {
                    $notification->execute([':user_id' => (int)$notifyUserId, ':product_id' => $productId, ':version_id' => $newId, ':title' => 'Có phiên bản mới: ' . (string)$product['title'], ':message' => 'Phiên bản ' . $version . ' đã được phát hành.', ':created_at' => $now]);
                }
            }
            json_response(['ok' => true, 'data' => ['id' => $newId]]);
        }

        case 'store_admin_screenshots': {
            require_admin($db);
            if ($method === 'GET') {
                $productId = (int)($_GET['product_id'] ?? 0);
                if ($productId > 0) {
                    $stmt = $db->prepare('SELECT id,product_id,image_url,caption,sort_order FROM app_product_screenshots WHERE product_id = :product_id ORDER BY sort_order,id');
                    $stmt->execute([':product_id' => $productId]);
                } else {
                    $stmt = $db->query('SELECT id,product_id,image_url,caption,sort_order FROM app_product_screenshots ORDER BY product_id,sort_order,id LIMIT 500');
                }
                json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
            }
            require_method('POST');
            require_csrf();
            $input = request_data();
            if (($input['delete'] ?? false) === true) {
                $delete = $db->prepare('DELETE FROM app_product_screenshots WHERE id = :id');
                $delete->execute([':id' => (int)($input['id'] ?? 0)]);
                json_response(['ok' => true]);
            }
            $productId = (int)($input['product_id'] ?? 0);
            $image = trim((string)($input['image_url'] ?? ''));
            $caption = trim((string)($input['caption'] ?? ''));
            if ($productId < 1 || $image === '' || strlen($caption) > 180) json_response(['ok' => false, 'error' => 'Ảnh chụp màn hình không hợp lệ.'], 422);
            $safe = str_starts_with($image, 'media:')
                ? valid_media_token(substr($image, 6))
                : (hendystore_feature_safe_package_url($image) && preg_match('/\\.(?:png|jpe?g|webp)(?:\\?.*)?$/i', $image));
            if (!$safe) json_response(['ok' => false, 'error' => 'Ảnh phải là token media đã tải lên hoặc URL HTTPS ảnh hợp lệ.'], 422);
            $count = $db->prepare('SELECT COUNT(*) FROM app_product_screenshots WHERE product_id = :id');
            $count->execute([':id' => $productId]);
            if ((int)$count->fetchColumn() >= 24) json_response(['ok' => false, 'error' => 'Mỗi ứng dụng tối đa 24 ảnh chụp màn hình.'], 422);
            $insert = $db->prepare('INSERT INTO app_product_screenshots(product_id,image_url,caption,sort_order,created_at) VALUES(:product_id,:image_url,:caption,:sort_order,:created_at)');
            $insert->execute([':product_id' => $productId, ':image_url' => $image, ':caption' => $caption, ':sort_order' => (int)($input['sort_order'] ?? 0), ':created_at' => date('Y-m-d H:i:s')]);
            json_response(['ok' => true]);
        }

        case 'store_admin_specs': {
            require_admin($db);
            if ($method === 'GET') {
                $productId = (int)($_GET['product_id'] ?? 0);
                $stmt = $db->prepare('SELECT video_url,minimum_requirements,privacy_details FROM app_product_specs WHERE product_id = :id LIMIT 1');
                $stmt->execute([':id' => $productId]);
                json_response(['ok' => true, 'data' => $stmt->fetch() ?: ['video_url' => '', 'minimum_requirements' => '', 'privacy_details' => '']]);
            }
            require_method('POST');
            require_csrf();
            $input = request_data();
            $productId = (int)($input['product_id'] ?? 0);
            $video = trim((string)($input['video_url'] ?? ''));
            $minimum = trim((string)($input['minimum_requirements'] ?? ''));
            $privacy = trim((string)($input['privacy_details'] ?? ''));
            if ($productId < 1 || strlen($minimum) > 8000 || strlen($privacy) > 8000) json_response(['ok' => false, 'error' => 'Yêu cầu hệ thống hoặc quyền riêng tư không hợp lệ.'], 422);
            if ($video !== '' && (!filter_var($video, FILTER_VALIDATE_URL) || strtolower((string)parse_url($video, PHP_URL_SCHEME)) !== 'https' || isset(parse_url($video)['user']))) {
                json_response(['ok' => false, 'error' => 'Video phải dùng URL HTTPS.'], 422);
            }
            $check = $db->prepare('SELECT id FROM products WHERE id = :id');
            $check->execute([':id' => $productId]);
            if (!$check->fetchColumn()) json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng.'], 404);
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare('INSERT INTO app_product_specs(product_id,video_url,minimum_requirements,privacy_details,updated_at) VALUES(:product_id,:video_url,:minimum_requirements,:privacy_details,:updated_at) ON CONFLICT(product_id) DO UPDATE SET video_url = EXCLUDED.video_url, minimum_requirements = EXCLUDED.minimum_requirements, privacy_details = EXCLUDED.privacy_details, updated_at = EXCLUDED.updated_at');
            $stmt->execute([':product_id' => $productId, ':video_url' => $video, ':minimum_requirements' => $minimum, ':privacy_details' => $privacy, ':updated_at' => $now]);
            json_response(['ok' => true]);
        }

        case 'store_admin_reviews': {
            require_admin($db);
            require_method('GET');
            $stmt = $db->query('SELECT r.id,r.product_id,r.rating,r.review_text,r.status,r.developer_reply,r.created_at,u.name AS author_name,p.title AS product_title FROM app_product_reviews r JOIN users u ON u.id=r.user_id JOIN products p ON p.id=r.product_id ORDER BY r.created_at DESC LIMIT 200');
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }
    }
    return true;
}
