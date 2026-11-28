<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'bootstrap');

function serve_media_file(): never
{
    require_method('GET');
    $token = (string) ($_GET['file'] ?? '');
    if (!valid_media_token($token)) {
        http_response_code(404);
        exit;
    }
    try {
        $object = neon_storage_get('media/' . $token);
        $mime = (string) ($object['ContentType'] ?? 'application/octet-stream');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            http_response_code(415);
            exit;
        }
        $body = $object['Body'];
        $length = $object['ContentLength'] ?? (is_object($body) && method_exists($body, 'getSize') ? $body->getSize() : null);
        header('Content-Type: ' . $mime);
        if (is_numeric($length)) header('Content-Length: ' . (string) $length);
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        while (is_object($body) && method_exists($body, 'eof') && !$body->eof()) {
            echo $body->read(1024 * 1024);
            if (connection_aborted()) break;
        }
        exit;
    } catch (Throwable $error) {
        error_log('[Hendy Store media] Storage read failed: ' . get_class($error));
        http_response_code(404);
        exit;
    }
}

function serve_package(PDO $db): never
{
    require_method('GET');
    http_response_code(410);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit('Liên kết tải trực tiếp đã ngừng hỗ trợ. Hãy tạo phiếu tải mới từ trang ứng dụng.');
}

function admin_upload(PDO $db): never
{
    require_method('POST');
    $admin = require_admin($db);
    require_csrf();

    $kind = (string) ($_POST['kind'] ?? '');
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        json_response(['ok' => false, 'error' => 'Không nhận được tệp tải lên hợp lệ.'], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    $originalName = (string) ($file['name'] ?? 'file');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $tmp = (string) $file['tmp_name'];

    if ($kind === 'media') {
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            json_response(['ok' => false, 'error' => 'Ảnh phải có dung lượng tối đa 5 MB.'], 422);
        }
        $image = @getimagesize($tmp);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (!$image || !isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
            json_response(['ok' => false, 'error' => 'Chỉ chấp nhận ảnh JPG, PNG hoặc WEBP hợp lệ.'], 422);
        }
        if (($image[0] ?? 0) > 8000 || ($image[1] ?? 0) > 8000 || (($image[0] ?? 0) * ($image[1] ?? 0)) > 30000000) {
            json_response(['ok' => false, 'error' => 'Kích thước ảnh vượt giới hạn an toàn.'], 422);
        }
        $token = bin2hex(random_bytes(16)) . '.' . $extension;
        try {
            neon_storage_put('media/' . $token, $tmp, $mime);
        } catch (Throwable $error) {
            error_log('[Hendy Store upload] Image upload failed: ' . get_class($error));
            json_response(['ok' => false, 'error' => 'Không thể lưu ảnh vào Neon Object Storage. Kiểm tra cấu hình bucket.'], 503);
        }
        json_response(['ok' => true, 'data' => ['path' => 'media:' . $token, 'size' => $size]]);
    }

    if ($kind === 'package') {
        $allowedExtensions = ['apk','apks','xapk','zip','7z','rar','exe','msi','dmg','pkg','deb','rpm','tar','gz','pdf','appx','msix','appimage','ipa'];
        $maxBytes = min(200 * 1024 * 1024, (int) env_value('MAX_UPLOAD_BYTES', (string) (200 * 1024 * 1024)));
        if (!in_array($extension, $allowedExtensions, true) || $size < 1 || $size > $maxBytes) {
            json_response(['ok' => false, 'error' => 'Gói tải phải thuộc định dạng được hỗ trợ và không vượt quá 200 MB.'], 422);
        }
        $token = bin2hex(random_bytes(16)) . '.' . $extension;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        if ($mime === 'text/plain' || $mime === 'application/x-empty') $mime = 'application/octet-stream';
        try {
            neon_storage_put('packages/' . $token, $tmp, $mime);
        } catch (Throwable $error) {
            error_log('[Hendy Store upload] Package upload failed: ' . get_class($error));
            json_response(['ok' => false, 'error' => 'Không thể lưu gói cài đặt vào Neon Object Storage. Kiểm tra cấu hình bucket.'], 503);
        }
        $sha256 = (string)hash_file('sha256', $tmp);
        $objectKey = 'packages/' . $token;
        $now = date('Y-m-d H:i:s');
        $record = $db->prepare("INSERT INTO app_security_packages(object_key,uploader_id,original_name,file_size_bytes,sha256,scan_status,upload_method,created_at,updated_at)
            VALUES(:key,:user,:name,:size,:sha,'pending','server',:created,:updated)
            ON CONFLICT(object_key) DO UPDATE SET uploader_id=EXCLUDED.uploader_id,original_name=EXCLUDED.original_name,file_size_bytes=EXCLUDED.file_size_bytes,sha256=EXCLUDED.sha256,scan_status='pending',updated_at=EXCLUDED.updated_at");
        $record->execute([':key' => $objectKey, ':user' => (int)$admin['id'], ':name' => mb_substr_safe($originalName,255), ':size' => $size, ':sha' => $sha256, ':created' => $now, ':updated' => $now]);
        $scan = hendystore_security_scan_object($db, $objectKey, $sha256, $size);
        hendystore_security_save_scan($db, $objectKey, $scan, $sha256, $size);
        json_response(['ok' => true, 'data' => ['path' => 'file:' . $token, 'size' => $size, 'sha256' => $sha256, 'scan' => $scan['status'] ?? 'error']]);
    }

    json_response(['ok' => false, 'error' => 'Loại tệp tải lên không hợp lệ.'], 422);
}

try {
    if ($action === 'media') {
        serve_media_file();
    }

    $db = store_db();
    // Free-only store: payment endpoints are not loaded.
    if ($action === 'health') {
        require_method('GET');
        try {
            app_base_url();
        } catch (Throwable $configurationError) {
            json_response(['ok' => false, 'status' => 'configuration_required', 'error' => 'Không xác định được public HTTPS origin. Đặt APP_URL/RENDER_EXTERNAL_URL hoặc bật tự nhận diện domain qua trusted proxy.'], 503);
        }
        $db->query('SELECT 1')->fetchColumn();

        // Send non-sensitive diagnostics to Render logs. Never include email, password, hashes or DSNs.
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $databaseName = $driver === 'pgsql'
            ? (string) $db->query('SELECT current_database()')->fetchColumn()
            : $driver;
        $seedEmail = strtolower(trim((string) env_value('ADMIN_EMAIL', '')));
        $seedPassword = (string) env_value('ADMIN_PASSWORD', '');
        $seedAccount = null;
        if ($seedEmail !== '') {
            $seedCheck = $db->prepare('SELECT role, password_hash FROM users WHERE LOWER(email) = :email LIMIT 1');
            $seedCheck->execute([':email' => $seedEmail]);
            $seedAccount = $seedCheck->fetch() ?: null;
        }
        $seedConfigured = $seedEmail !== '' && $seedPassword !== '';
        $seedPasswordMatches = $seedAccount
            && ($seedAccount['role'] ?? '') === 'admin'
            && $seedPassword !== ''
            && password_verify($seedPassword, (string) ($seedAccount['password_hash'] ?? ''));
        $runtimeUserCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $runtimeProductCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        error_log('[Hendy Store health] driver=' . $driver
            . ' database=' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $databaseName)
            . ' app_env=' . (string) env_value('APP_ENV', 'development')
            . ' admin_env=' . ($seedConfigured ? 'configured' : 'missing')
            . ' admin_record=' . ($seedAccount && ($seedAccount['role'] ?? '') === 'admin' ? 'present' : 'missing_or_wrong_role')
            . ' admin_password=' . ($seedPasswordMatches ? 'matches' : 'does_not_match')
            . ' users=' . $runtimeUserCount
            . ' products=' . $runtimeProductCount);

        $storage = neon_storage_probe();
        $databaseStatus = $driver === 'pgsql' ? 'postgresql' : $driver;
        $components = [
            'database' => 'healthy',
            'storage' => $storage ? 'healthy' : 'unavailable',
        ];
        $adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount < 1) {
            json_response(['ok' => false, 'status' => 'setup_required', 'database' => $databaseStatus, 'components' => $components, 'error' => 'Configure ADMIN_EMAIL and ADMIN_PASSWORD before opening the store.'], 503);
        }
        if (!$storage) {
            json_response([
                'ok' => false,
                'status' => 'storage_unavailable',
                'database' => $databaseStatus,
                'components' => $components,
                'error' => 'Object Storage đang chọn không phản hồi. Kiểm tra endpoint, bucket và credentials trong Render.',
            ], 503);
        }
        $published = (int) $db->query("SELECT COUNT(*) FROM products WHERE status = 'published'")->fetchColumn();
        header('Cache-Control: no-store');
        json_response(['ok' => true, 'status' => 'healthy', 'service' => 'hendy-store', 'database' => $databaseStatus, 'storage' => neon_object_storage_enabled() ? ('s3-compatible:' . object_storage_provider()) : 'unavailable', 'components' => $components, 'auth' => env_value('NEON_AUTH_BASE_URL') !== null, 'administrators' => $adminCount, 'publishedProducts' => $published]);
    }
    if ($action === 'download_file') {
        serve_package($db);
    }

    store_session();

    require_once __DIR__ . '/features/api.php';
    hendystore_feature_schema($db);
    require_once __DIR__ . '/features/security.php';
    hendystore_security_schema($db);
    if (hendystore_security_api($db, $action, $method)) {
        exit;
    }
    if (hendystore_feature_api($db, $action, $method)) {
        exit;
    }

    switch ($action) {
        case 'client_config':
            require_method('GET');
            json_response(['ok' => true, 'data' => [
                'authUrl' => env_value('NEON_AUTH_BASE_URL'),
                'dataApiUrl' => env_value('NEON_DATA_API_URL'),
                'realtimeUrl' => env_value('NEON_REALTIME_URL'),
                'functionUrl' => env_value('NEON_STORE_FUNCTION_URL'),
                'authEnabled' => env_value('NEON_AUTH_BASE_URL') !== null,
                'googleOAuthEnabled' => env_bool('GOOGLE_OAUTH_ENABLED', false) && env_value('NEON_AUTH_BASE_URL') !== null,
                'realtimeEnabled' => env_value('NEON_REALTIME_URL') !== null && env_value('NEON_STORE_FUNCTION_URL') !== null,
            ]]);
            break;

        case 'bootstrap':
            require_method('GET');
            $user = current_user($db);
            $categories = $db->query("SELECT DISTINCT category FROM products WHERE status = 'published' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
            $featured = $db->query("SELECT * FROM products WHERE status = 'published' AND featured = 1 ORDER BY updated_at DESC LIMIT 4")->fetchAll();
            json_response([
                'ok' => true,
                'data' => [
                    'categories' => $categories,
                    'featured' => array_map('present_product', $featured),
                    'user' => $user,
                    'csrfToken' => csrf_token(),
                    'storeName' => env_value('STORE_NAME', 'Hendy Store'),
                    'registrationEnabled' => false,
                    'adminSessionFallbackEnabled' => env_bool('ADMIN_SESSION_FALLBACK_ENABLED', true),
                    'neonAuthEnabled' => env_value('NEON_AUTH_BASE_URL') !== null,
                    'neonAuthPublicSignup' => env_bool('NEON_AUTH_PUBLIC_SIGNUP', false),
                    'googleOAuthEnabled' => env_bool('GOOGLE_OAUTH_ENABLED', false) && env_value('NEON_AUTH_BASE_URL') !== null,
                    'realtimeEnabled' => env_value('NEON_REALTIME_URL') !== null && env_value('NEON_STORE_FUNCTION_URL') !== null,
                ],
            ]);
            break;

        case 'products':
            require_method('GET');
            $q = trim((string) ($_GET['q'] ?? ''));
            $q = mb_substr_safe($q, 100);
            $category = mb_substr_safe(trim((string) ($_GET['category'] ?? '')), 100);
            $sort = (string) ($_GET['sort'] ?? 'popular');
            $page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
            $limit = min(48, max(1, (int) ($_GET['limit'] ?? 24)));
            $where = ["status = 'published'"];
            $params = [];
            if ($q !== '') {
                $where[] = '(title LIKE :q1 OR developer LIKE :q2 OR short_description LIKE :q3 OR description LIKE :q4)';
                $needle = '%' . $q . '%';
                $params[':q1'] = $needle;
                $params[':q2'] = $needle;
                $params[':q3'] = $needle;
                $params[':q4'] = $needle;
            }
            if ($category !== '' && $category !== 'Tất cả') {
                $where[] = 'category = :category';
                $params[':category'] = $category;
            }
            $order = match ($sort) {
                'newest' => 'created_at DESC',
                'rating' => 'rating DESC, downloads DESC',
                'name' => 'title ASC',
                'free-popular' => 'downloads DESC, rating DESC, title ASC',
                default => 'featured DESC, downloads DESC, rating DESC',
            };
            $whereSql = implode(' AND ', $where);
            $count = $db->prepare("SELECT COUNT(*) FROM products WHERE $whereSql");
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $stmt = $db->prepare("SELECT * FROM products WHERE $whereSql ORDER BY $order LIMIT :limit OFFSET :offset");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
            $stmt->execute();
            json_response([
                'ok' => true,
                'data' => array_map('present_product', $stmt->fetchAll()),
                'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
            ]);
            break;

        case 'product':
            require_method('GET');
            $slug = trim((string) ($_GET['slug'] ?? ''));
            if ($slug === '' || strlen($slug) > 180) {
                json_response(['ok' => false, 'error' => 'Mã ứng dụng không hợp lệ.'], 400);
            }
            $stmt = $db->prepare("SELECT * FROM products WHERE slug = :slug AND status = 'published' LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $product = $stmt->fetch();
            if (!$product) {
                json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng.'], 404);
            }
            hendystore_security_track_product_view($db, (int)$product['id']);
            $related = $db->prepare("SELECT * FROM products WHERE category = :category AND slug != :slug AND status = 'published' ORDER BY downloads DESC LIMIT 4");
            $related->execute([':category' => $product['category'], ':slug' => $slug]);
            json_response(['ok' => true, 'data' => present_product($product, true), 'related' => array_map('present_product', $related->fetchAll())]);
            break;

        case 'login':
            require_method('POST');
            require_csrf();
            if (!env_bool('ADMIN_SESSION_FALLBACK_ENABLED', true)) {
                json_response(['ok' => false, 'error' => 'Đăng nhập quản trị qua PHP session đang bị tắt.'], 403);
            }
            $input = request_data();
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) > 1024) {
                json_response(['ok' => false, 'error' => 'Email hoặc mật khẩu không chính xác.'], 401);
            }
            rate_limit_or_fail($db, 'admin-login-ip', 20, 900);
            rate_limit_or_fail($db, 'admin-login-account-' . $email, 8, 900);
            $stmt = $db->prepare("SELECT id,name,email,password_hash,role FROM users WHERE LOWER(email) = :email AND role = 'admin' LIMIT 1");
            $stmt->execute([':email' => $email]);
            $account = $stmt->fetch();
            if (!$account || !password_verify($password, (string)($account['password_hash'] ?? ''))) {
                password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                json_response(['ok' => false, 'error' => 'Email hoặc mật khẩu không chính xác.'], 401);
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$account['id'];
            $_SESSION['auth_mode'] = 'admin_password';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            json_response(['ok' => true, 'data' => ['user' => public_user($account), 'csrfToken' => csrf_token()]]);

        case 'register':
            require_method('POST');
            require_csrf();
            json_response([
                'ok' => false,
                'error' => 'Đăng ký khách hàng qua PHP đã ngừng hỗ trợ. Hãy sử dụng Neon Auth Managed Better Auth.',
            ], 410);



        case 'logout':
            require_method('POST');
            require_csrf();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
            json_response(['ok' => true]);
            break;

        case 'download':
            require_method('POST');
            require_csrf();
            rate_limit_or_fail($db, 'download', 80, 3600);
            $input = request_data();
            $slug = trim((string) ($input['slug'] ?? ''));
            $stmt = $db->prepare("SELECT id, price_type, download_url FROM products WHERE slug = :slug AND status = 'published' LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $product = $stmt->fetch();
            if (!$product || trim((string)($product['download_url'] ?? '')) === '') {
                json_response(['ok' => false, 'error' => 'Ứng dụng chưa có liên kết tải miễn phí hợp lệ.'], 404);
            }
            if (($product['price_type'] ?? '') === 'Paid') {
                json_response(['ok' => false, 'error' => 'Sản phẩm trả phí không được tải qua luồng miễn phí.'], 403);
            }
            $externalUrl = (string) $product['download_url'];
            if (str_starts_with($externalUrl, 'file:')) {
                json_response(['ok' => false, 'error' => 'Gói riêng tư cần phiếu tải một lần. Hãy sử dụng store_download_prepare.'], 410);
            }
            $parts = parse_url($externalUrl);
            if (!hendystore_security_package_downloadable($db, (int)$product['id'], $externalUrl, false)
                || !filter_var($externalUrl, FILTER_VALIDATE_URL)
                || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                json_response(['ok' => false, 'error' => 'Liên kết tải của sản phẩm chưa được duyệt hoặc không an toàn.'], 409);
            }
            $update = $db->prepare('UPDATE products SET downloads = downloads + 1 WHERE id = :id');
            $update->execute([':id' => $product['id']]);
            json_response(['ok' => true, 'data' => ['url' => $product['download_url']]]);
            break;


        case 'download_redirect':
            require_method('GET');
            rate_limit_or_fail($db, 'download-redirect', 80, 3600);
            $slug = trim((string) ($_GET['slug'] ?? ''));
            $stmt = $db->prepare("SELECT id, price_type, download_url FROM products WHERE slug = :slug AND status = 'published' LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $product = $stmt->fetch();
            if (!$product || ($product['price_type'] ?? '') === 'Paid' || trim((string)($product['download_url'] ?? '')) === '') {
                http_response_code(404);
                header('Cache-Control: no-store');
                exit;
            }
            $downloadUrl = (string) $product['download_url'];
            if (str_starts_with($downloadUrl, 'file:')) {
                http_response_code(410);
                header('Cache-Control: no-store');
                exit('Gói riêng tư yêu cầu phiếu tải một lần từ trang ứng dụng.');
            }
            $parts = parse_url($downloadUrl);
            if (!hendystore_security_package_downloadable($db, (int)$product['id'], $downloadUrl, false)
                || !filter_var($downloadUrl, FILTER_VALIDATE_URL)
                || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                http_response_code(409);
                exit;
            }
            $update = $db->prepare('UPDATE products SET downloads = downloads + 1 WHERE id = :id');
            $update->execute([':id' => $product['id']]);
            header('Cache-Control: no-store');
            header('Location: ' . $downloadUrl, true, 302);
            exit;

        case 'ai_product_copy':
            require_method('POST');
            require_admin($db);
            require_csrf();
            $input = request_data();
            $title = trim((string) ($input['title'] ?? ''));
            $developer = trim((string) ($input['developer'] ?? ''));
            $category = trim((string) ($input['category'] ?? ''));
            $short = trim((string) ($input['shortDescription'] ?? ''));
            if ($title === '' || unicode_length($title) > 180 || unicode_length($short) > 255 || unicode_length($developer) > 160 || unicode_length($category) > 100) {
                json_response(['ok' => false, 'error' => 'Thông tin ứng dụng không hợp lệ.'], 422);
            }
            try {
                $description = neon_ai_completion(
                    'Bạn là biên tập viên cửa hàng ứng dụng. Viết phần giới thiệu tiếng Việt trung thực, không bịa tính năng, không khẳng định an toàn/chứng nhận khi không có chứng cứ. Viết 2-4 đoạn, rõ công dụng, đối tượng và lưu ý cài đặt.',
                    "Tên ứng dụng: {$title}\nNhà phát triển: {$developer}\nDanh mục: {$category}\nMô tả ngắn đã được quản trị viên cung cấp: {$short}\nHãy tạo mô tả chi tiết phù hợp từ dữ kiện này. Nếu thiếu thông tin, diễn đạt thận trọng và không tự bịa."
                );
                json_response(['ok' => true, 'data' => ['description' => $description]]);
            } catch (Throwable $error) {
                error_log('[Hendy Store AI copy] ' . $error->getMessage());
                json_response(['ok' => false, 'error' => $error->getMessage()], 503);
            }

        case 'admin_upload':
            admin_upload($db);
            break;

        case 'admin_products':
            $admin = require_admin($db);
            if ($method === 'GET') {
                $rows = $db->query('SELECT * FROM products ORDER BY updated_at DESC')->fetchAll();
                $adminRows = array_map(static function (array $product): array {
                    $row = present_product($product, true);
                    $row['downloadUrl'] = $product['download_url'];
                    return $row;
                }, $rows);
                json_response(['ok' => true, 'data' => $adminRows]);
            }
            require_csrf();
            $input = request_data();
            if ($method === 'POST') {
                $product = validate_product_input($input);
                // A newly uploaded package must always enter the moderation queue.
                $product[':status'] = 'pending';
                $product[':featured'] = 0;
                $now = date('Y-m-d H:i:s');
                try {
                    $sql = 'INSERT INTO products (slug,title,developer,category,short_description,description,version,platform,price_type,price_amount_vnd,icon,cover,screenshots_json,download_url,downloads,rating,featured,status,created_at,updated_at) VALUES (:slug,:title,:developer,:category,:short_description,:description,:version,:platform,:price_type,:price_amount_vnd,:icon,:cover,:screenshots_json,:download_url,0,:rating,:featured,:status,:created_at,:updated_at)';
                    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') $sql .= ' RETURNING id';
                    $stmt = $db->prepare($sql);
                    $stmt->execute($product + [':created_at' => $now, ':updated_at' => $now]);
                    $productId = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$db->lastInsertId();
                } catch (PDOException $error) {
                    if ((string) $error->getCode() === '23000' || str_contains(strtolower($error->getMessage()), 'unique')) {
                        json_response(['ok' => false, 'error' => 'Slug này đã tồn tại. Hãy chọn slug khác.'], 409);
                    }
                    throw $error;
                }
                hendystore_security_register_release($db, $productId, (int)$admin['id'], (string)$product[':download_url']);
                json_response(['ok' => true, 'data' => ['id' => $productId, 'status' => 'pending']], 201);
            }
            if ($method === 'PUT') {
                $id = (int) ($input['id'] ?? 0);
                if ($id < 1) {
                    json_response(['ok' => false, 'error' => 'ID sản phẩm không hợp lệ.'], 422);
                }
                $product = validate_product_input($input);
                $currentStmt = $db->prepare('SELECT status,download_url,featured FROM products WHERE id=:id LIMIT 1');
                $currentStmt->execute([':id' => $id]);
                $current = $currentStmt->fetch();
                if (!$current) json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng cần cập nhật.'], 404);

                // Editing descriptive metadata must not unpublish an already approved
                // release. Only a changed package URL enters a new moderation cycle.
                $downloadChanged = !hash_equals((string)$current['download_url'], (string)$product[':download_url']);
                $product[':status'] = $downloadChanged ? 'pending' : (string)$current['status'];
                $product[':featured'] = $downloadChanged ? 0 : (int)$current['featured'];
                $product[':id'] = $id;
                $product[':updated_at'] = date('Y-m-d H:i:s');
                try {
                    $stmt = $db->prepare('UPDATE products SET slug=:slug,title=:title,developer=:developer,category=:category,short_description=:short_description,description=:description,version=:version,platform=:platform,price_type=:price_type,price_amount_vnd=:price_amount_vnd,icon=:icon,cover=:cover,screenshots_json=:screenshots_json,download_url=:download_url,rating=:rating,featured=:featured,status=:status,updated_at=:updated_at WHERE id=:id');
                    $stmt->execute($product);
                } catch (PDOException $error) {
                    if ((string) $error->getCode() === '23000' || str_contains(strtolower($error->getMessage()), 'unique')) {
                        json_response(['ok' => false, 'error' => 'Slug này đã tồn tại. Hãy chọn slug khác.'], 409);
                    }
                    throw $error;
                }
                if ($downloadChanged) {
                    hendystore_security_register_release($db, $id, (int)$admin['id'], (string)$product[':download_url']);
                }
                json_response(['ok' => true, 'data' => [
                    'status' => $product[':status'],
                    'moderationRequired' => $downloadChanged,
                ]]);
            }
            if ($method === 'DELETE') {
                $id = (int) ($input['id'] ?? 0);
                if ($id < 1) {
                    json_response(['ok' => false, 'error' => 'ID sản phẩm không hợp lệ.'], 422);
                }
                $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
                $stmt->execute([':id' => $id]);
                json_response(['ok' => true, 'data' => ['deleted' => $stmt->rowCount() > 0]]);
            }
            header('Allow: GET, POST, PUT, DELETE');
            json_response(['ok' => false, 'error' => 'Phương thức không được hỗ trợ.'], 405);
            break;

        case 'me':
            require_method('GET');
            json_response(['ok' => true, 'data' => ['user' => current_user($db), 'csrfToken' => csrf_token()]]);
            break;

        default:
            json_response(['ok' => false, 'error' => 'API action không tồn tại.'], 404);
    }
} catch (Throwable $error) {
    error_log('[Hendy Store API] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Máy chủ gặp sự cố. Vui lòng thử lại sau.'], 500);
}
