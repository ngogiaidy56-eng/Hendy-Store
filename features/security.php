<?php
declare(strict_types=1);

/**
 * Additive Trust & Safety and Developer Console module.
 * Private packages stay quarantined until VirusTotal reports a clean result
 * and an administrator explicitly approves the release.
 */
function hendystore_security_schema(PDO $db): void
{
    static $ready = false;
    if ($ready) return;

    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

    $db->exec("CREATE TABLE IF NOT EXISTS app_developer_profiles (
        user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        display_name VARCHAR(160) NOT NULL,
        website_url TEXT NOT NULL DEFAULT '',
        support_email VARCHAR(190) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        review_note TEXT NOT NULL DEFAULT '',
        reviewed_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
        reviewed_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL,
        updated_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_security_packages (
        object_key VARCHAR(220) PRIMARY KEY,
        uploader_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        original_name VARCHAR(255) NOT NULL,
        file_size_bytes BIGINT NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL DEFAULT '',
        scan_status VARCHAR(32) NOT NULL DEFAULT 'awaiting_upload',
        moderation_status VARCHAR(24) NOT NULL DEFAULT 'pending',
        scan_provider VARCHAR(60) NOT NULL DEFAULT '',
        analysis_id TEXT NOT NULL DEFAULT '',
        scan_summary TEXT NOT NULL DEFAULT '',
        upload_method VARCHAR(20) NOT NULL DEFAULT 'presigned',
        scanned_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL,
        updated_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_release_security (
        product_id BIGINT PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        developer_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        object_key VARCHAR(220) NOT NULL DEFAULT '',
        moderation_status VARCHAR(24) NOT NULL DEFAULT 'pending',
        scan_status VARCHAR(32) NOT NULL DEFAULT 'pending',
        sha256 CHAR(64) NOT NULL DEFAULT '',
        package_size_bytes BIGINT NOT NULL DEFAULT 0,
        release_track VARCHAR(20) NOT NULL DEFAULT 'production',
        submitted_at TIMESTAMP NOT NULL,
        reviewed_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
        review_note TEXT NOT NULL DEFAULT '',
        reviewed_at TIMESTAMP NULL,
        updated_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_store_analytics (
        id $id,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        event_type VARCHAR(32) NOT NULL,
        platform VARCHAR(24) NOT NULL DEFAULT 'unknown',
        ip_hash CHAR(64) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_crash_reports (
        id $id,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
        version VARCHAR(60) NOT NULL DEFAULT '',
        platform VARCHAR(24) NOT NULL DEFAULT 'unknown',
        error_message TEXT NOT NULL,
        stack_trace TEXT NOT NULL DEFAULT '',
        status VARCHAR(24) NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_trust_audit (
        id $id,
        actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
        action VARCHAR(80) NOT NULL,
        target_type VARCHAR(40) NOT NULL,
        target_id VARCHAR(100) NOT NULL,
        note TEXT NOT NULL DEFAULT '',
        metadata_json TEXT NOT NULL DEFAULT '{}',
        created_at TIMESTAMP NOT NULL
    )");

    try {
        $db->exec("ALTER TABLE app_versions ADD COLUMN release_track VARCHAR(20) NOT NULL DEFAULT 'production'");
    } catch (Throwable $error) {
        // Existing installations already have this additive column.
    }
    try {
        if ($driver === 'pgsql') {
            $db->exec("ALTER TABLE app_security_packages ADD COLUMN IF NOT EXISTS upload_method VARCHAR(20) NOT NULL DEFAULT 'presigned'");
        } else {
            $db->exec("ALTER TABLE app_security_packages ADD COLUMN upload_method VARCHAR(20) NOT NULL DEFAULT 'presigned'");
        }
    } catch (Throwable $error) {
        // Existing SQLite/MySQL installations already have this additive column.
    }
    try {
        if ($driver === 'pgsql') {
            $db->exec("ALTER TABLE app_security_packages ADD COLUMN IF NOT EXISTS moderation_status VARCHAR(24) NOT NULL DEFAULT 'pending'");
        } else {
            $db->exec("ALTER TABLE app_security_packages ADD COLUMN moderation_status VARCHAR(24) NOT NULL DEFAULT 'pending'");
        }
    } catch (Throwable $error) {
        // Existing installations already have package-level moderation status.
    }
    $db->exec("UPDATE app_security_packages SET moderation_status='approved' WHERE moderation_status='pending' AND EXISTS (
        SELECT 1 FROM app_release_security s WHERE s.object_key=app_security_packages.object_key AND s.moderation_status='approved' AND s.scan_status='clean'
    )");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_security_release_status ON app_release_security(moderation_status, scan_status, submitted_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_security_packages_uploader ON app_security_packages(uploader_id, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_store_analytics_product ON app_store_analytics(product_id, event_type, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_store_crashes_product ON app_crash_reports(product_id, status, created_at)');

    $ready = true;
}

function hendystore_security_user(PDO $db): array
{
    $user = current_user($db);
    if (!$user) json_response(['ok' => false, 'error' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
    return $user;
}

function hendystore_security_developer(PDO $db): array
{
    $user = hendystore_security_user($db);
    $stmt = $db->prepare("SELECT * FROM app_developer_profiles WHERE user_id = :user_id AND status = 'approved' LIMIT 1");
    $stmt->execute([':user_id' => (int)$user['id']]);
    $profile = $stmt->fetch();
    if (!$profile) json_response(['ok' => false, 'error' => 'Hồ sơ nhà phát triển chưa được quản trị viên phê duyệt.'], 403);
    return [$user, $profile];
}

function hendystore_security_audit(PDO $db, ?int $actorId, string $action, string $targetType, string $targetId, string $note = '', array $metadata = []): void
{
    $stmt = $db->prepare('INSERT INTO app_trust_audit(actor_user_id,action,target_type,target_id,note,metadata_json,created_at) VALUES(:actor,:action,:type,:target,:note,:metadata,:created)');
    $stmt->execute([
        ':actor' => $actorId,
        ':action' => $action,
        ':type' => $targetType,
        ':target' => $targetId,
        ':note' => mb_substr_safe($note, 2000),
        ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ':created' => date('Y-m-d H:i:s'),
    ]);
}

function hendystore_security_package_row(PDO $db, string $objectKey): ?array
{
    $stmt = $db->prepare('SELECT * FROM app_security_packages WHERE object_key = :key LIMIT 1');
    $stmt->execute([':key' => $objectKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hendystore_security_package_downloadable(PDO $db, int $productId, string $packageUrl, bool $checkObject = true): bool
{
    if (!hendystore_feature_safe_package_url($packageUrl)) return false;
    if (!str_starts_with($packageUrl, 'file:')) {
        $managed = $db->prepare('SELECT 1 FROM app_release_security WHERE product_id=:id LIMIT 1');
        $managed->execute([':id' => $productId]);
        return !$managed->fetchColumn();
    }

    $token = substr($packageUrl, 5);
    if (!valid_package_token($token)) return false;
    $objectKey = 'packages/' . $token;
    $package = hendystore_security_package_row($db, $objectKey);
    if ($package) {
        return ($package['moderation_status'] ?? 'pending') === 'approved'
            && ($package['scan_status'] ?? '') === 'clean'
            && preg_match('/^[a-f0-9]{64}$/', (string)($package['sha256'] ?? '')) === 1
            && (!$checkObject || neon_storage_exists($objectKey));
    }

    // Preserve compatibility for legacy packages that predate the moderation tables.
    $managed = $db->prepare('SELECT 1 FROM app_release_security WHERE product_id=:id LIMIT 1');
    $managed->execute([':id' => $productId]);
    return !$managed->fetchColumn() && (!$checkObject || neon_storage_exists($objectKey));
}

function hendystore_security_save_scan(PDO $db, string $objectKey, array $scan, string $sha256 = '', int $size = 0): void
{
    $stmt = $db->prepare('UPDATE app_security_packages SET sha256 = :sha, file_size_bytes = :size, scan_status = :status, scan_provider = :provider, analysis_id = :analysis, scan_summary = :summary, scanned_at = :scanned, updated_at = :updated WHERE object_key = :key');
    $stmt->execute([
        ':sha' => $sha256,
        ':size' => $size,
        ':status' => (string)($scan['status'] ?? 'error'),
        ':provider' => (string)($scan['provider'] ?? ''),
        ':analysis' => (string)($scan['analysis_id'] ?? ''),
        ':summary' => (string)($scan['summary'] ?? ''),
        ':scanned' => !empty($scan['scanned']) ? date('Y-m-d H:i:s') : null,
        ':updated' => date('Y-m-d H:i:s'),
        ':key' => $objectKey,
    ]);
}

function hendystore_security_vt_request(string $url, string $apiKey, ?string $filePath = null): array
{
    if (!function_exists('curl_init')) return ['http' => 0, 'json' => null];
    $ch = curl_init($url);
    $headers = ['x-apikey: ' . $apiKey, 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => $filePath === null ? 20 : 180,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($filePath !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($filePath)]);
    }
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['http' => $http, 'json' => is_array($json) ? $json : null];
}

function hendystore_security_stats_status(array $stats): string
{
    $malicious = (int)($stats['malicious'] ?? 0);
    $suspicious = (int)($stats['suspicious'] ?? 0);
    $harmless = (int)($stats['harmless'] ?? 0);
    $undetected = (int)($stats['undetected'] ?? 0);
    $failed = (int)($stats['failure'] ?? 0);
    $timedOut = (int)($stats['timeout'] ?? 0) + (int)($stats['confirmed-timeout'] ?? 0);

    // Fail closed: detection is a rejection; engine failures/timeouts or an
    // empty scan remain pending and can never be treated as a clean result.
    if ($malicious > 0 || $suspicious > 0) return 'malicious';
    if ($failed > 0 || $timedOut > 0) return 'pending';
    return ($harmless + $undetected) > 0 ? 'clean' : 'pending';
}

function hendystore_security_scan_file(string $filePath, string $sha256, int $size): array
{
    $apiKey = (string)env_value('VIRUSTOTAL_API_KEY', '');
    if ($apiKey === '') return ['status' => 'missing_config', 'provider' => 'VirusTotal', 'summary' => 'VIRUSTOTAL_API_KEY chưa được cấu hình.'];
    if (!function_exists('curl_init') || !class_exists(CURLFile::class)) {
        return ['status' => 'unavailable', 'provider' => 'VirusTotal', 'summary' => 'PHP cURL chưa khả dụng.'];
    }

    $lookup = hendystore_security_vt_request('https://www.virustotal.com/api/v3/files/' . rawurlencode($sha256), $apiKey);
    if ($lookup['http'] === 200) {
        $stats = $lookup['json']['data']['attributes']['last_analysis_stats'] ?? [];
        $status = hendystore_security_stats_status(is_array($stats) ? $stats : []);
        return [
            'status' => $status,
            'provider' => 'VirusTotal',
            'summary' => 'malicious=' . (int)($stats['malicious'] ?? 0) . '; suspicious=' . (int)($stats['suspicious'] ?? 0) . '; undetected=' . (int)($stats['undetected'] ?? 0),
            'scanned' => $status === 'clean' || $status === 'malicious',
        ];
    }

    if ($lookup['http'] !== 404) {
        return ['status' => 'error', 'provider' => 'VirusTotal', 'summary' => 'VirusTotal không trả về kết quả tra cứu hợp lệ (HTTP ' . $lookup['http'] . ').'];
    }

    $uploadUrl = 'https://www.virustotal.com/api/v3/files';
    if ($size > 32 * 1024 * 1024) {
        $urlResponse = hendystore_security_vt_request('https://www.virustotal.com/api/v3/files/upload_url', $apiKey);
        $candidate = $urlResponse['json']['data'] ?? null;
        if ($urlResponse['http'] !== 200 || !is_string($candidate) || !str_starts_with($candidate, 'https://')) {
            return ['status' => 'error', 'provider' => 'VirusTotal', 'summary' => 'Không lấy được URL upload lớn từ VirusTotal.'];
        }
        $uploadUrl = $candidate;
    }
    $upload = hendystore_security_vt_request($uploadUrl, $apiKey, $filePath);
    $analysisId = (string)($upload['json']['data']['id'] ?? '');
    if ($upload['http'] < 200 || $upload['http'] >= 300 || $analysisId === '') {
        return ['status' => 'error', 'provider' => 'VirusTotal', 'summary' => 'VirusTotal từ chối gói tải (HTTP ' . $upload['http'] . ').'];
    }
    return [
        'status' => 'pending',
        'provider' => 'VirusTotal',
        'analysis_id' => $analysisId,
        'summary' => 'Đã gửi mẫu đến VirusTotal; cần chờ hoàn tất phân tích.',
    ];
}

function hendystore_security_scan_object(PDO $db, string $objectKey, string $expectedHash = '', int $expectedSize = 0): array
{
    @set_time_limit(0);
    if (!preg_match('#^packages/[a-f0-9]{32}\.[a-z0-9]{2,8}$#i', $objectKey) || !neon_storage_exists($objectKey)) {
        return ['status' => 'missing_file', 'provider' => 'VirusTotal', 'summary' => 'Không tìm thấy gói trong kho riêng tư.'];
    }
    $tempPath = tempnam(sys_get_temp_dir(), 'hendy-scan-');
    if (!is_string($tempPath)) return ['status' => 'error', 'provider' => 'VirusTotal', 'summary' => 'Không tạo được tệp tạm để quét.'];

    try {
        $object = neon_storage_get($objectKey);
        $body = $object['Body'] ?? null;
        $handle = fopen($tempPath, 'wb');
        if (!$handle) throw new RuntimeException('Unable to write temporary scan file.');
        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                $chunk = $body->read(1024 * 1024);
                if ($chunk === '') break;
                fwrite($handle, $chunk);
            }
        } elseif (is_object($body) && method_exists($body, 'getContents')) {
            fwrite($handle, (string)$body->getContents());
        } else {
            throw new RuntimeException('Object Storage returned an unsupported stream.');
        }
        fclose($handle);

        $size = (int)filesize($tempPath);
        $hash = (string)hash_file('sha256', $tempPath);
        if ($size < 1 || ($expectedSize > 0 && $size !== $expectedSize) || ($expectedHash !== '' && !hash_equals(strtolower($expectedHash), $hash))) {
            $scan = ['status' => 'integrity_error', 'provider' => 'SHA-256', 'summary' => 'Dung lượng hoặc SHA-256 không khớp với dữ liệu gửi lên.'];
        } else {
            $scan = hendystore_security_scan_file($tempPath, $hash, $size);
        }
        hendystore_security_save_scan($db, $objectKey, $scan, $hash, $size);
        return $scan + ['sha256' => $hash, 'size' => $size];
    } catch (Throwable $error) {
        error_log('[Hendy Store security scan] ' . get_class($error));
        $scan = ['status' => 'error', 'provider' => 'VirusTotal', 'summary' => 'Không thể hoàn tất bước kiểm tra tệp.'];
        hendystore_security_save_scan($db, $objectKey, $scan);
        return $scan;
    } finally {
        @unlink($tempPath);
    }
}

function hendystore_security_register_release(PDO $db, int $productId, int $ownerId, string $downloadUrl, string $track = 'production'): void
{
    $track = in_array($track, ['alpha', 'beta', 'production'], true) ? $track : 'production';
    $objectKey = str_starts_with($downloadUrl, 'file:') ? 'packages/' . substr($downloadUrl, 5) : '';
    $package = $objectKey !== '' ? hendystore_security_package_row($db, $objectKey) : null;
    $scanStatus = (string)($package['scan_status'] ?? 'not_scanned');
    $sha256 = (string)($package['sha256'] ?? '');
    $size = (int)($package['file_size_bytes'] ?? 0);
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO app_release_security(product_id,developer_user_id,object_key,moderation_status,scan_status,sha256,package_size_bytes,release_track,submitted_at,updated_at)
        VALUES(:product,:owner,:object,'pending',:scan,:sha,:size,:track,:submitted,:updated)
        ON CONFLICT(product_id) DO UPDATE SET developer_user_id=EXCLUDED.developer_user_id,object_key=EXCLUDED.object_key,moderation_status='pending',scan_status=EXCLUDED.scan_status,sha256=EXCLUDED.sha256,package_size_bytes=EXCLUDED.package_size_bytes,release_track=EXCLUDED.release_track,submitted_at=EXCLUDED.submitted_at,reviewed_by=NULL,review_note='',reviewed_at=NULL,updated_at=EXCLUDED.updated_at");
    $stmt->execute([
        ':product' => $productId, ':owner' => $ownerId, ':object' => $objectKey,
        ':scan' => $scanStatus, ':sha' => $sha256, ':size' => $size,
        ':track' => $track, ':submitted' => $now, ':updated' => $now,
    ]);
}

function hendystore_security_api(PDO $db, string $action, string $method): bool
{
    $routes = [
        'developer_profile', 'developer_profile_save', 'developer_apps',
        'developer_upload_intent', 'developer_upload_finalize', 'developer_upload_status', 'developer_scan_refresh', 'developer_submit',
        'developer_analytics', 'developer_crash_report', 'developer_reviews', 'developer_review_reply', 'developer_versions', 'developer_rollback',
        'trust_queue', 'trust_developer_review', 'trust_release_review', 'trust_scan_refresh',
    ];
    if (!in_array($action, $routes, true)) return false;
    hendystore_security_schema($db);

    switch ($action) {
        case 'developer_profile': {
            require_method('GET');
            $user = hendystore_security_user($db);
            $stmt = $db->prepare('SELECT display_name,website_url,support_email,status,review_note,created_at,updated_at FROM app_developer_profiles WHERE user_id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$user['id']]);
            json_response(['ok' => true, 'data' => ['profile' => $stmt->fetch() ?: null]]);
        }

        case 'developer_profile_save': {
            require_method('POST');
            require_csrf();
            $user = hendystore_security_user($db);
            $input = request_data();
            $display = trim((string)($input['display_name'] ?? ''));
            $email = strtolower(trim((string)($input['support_email'] ?? '')));
            $website = trim((string)($input['website_url'] ?? ''));
            if (unicode_length($display) < 2 || unicode_length($display) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                json_response(['ok' => false, 'error' => 'Tên hiển thị hoặc email hỗ trợ không hợp lệ.'], 422);
            }
            if ($website !== '') {
                $parts = parse_url($website);
                if (!filter_var($website, FILTER_VALIDATE_URL) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                    json_response(['ok' => false, 'error' => 'Website nhà phát triển phải là URL HTTPS hợp lệ.'], 422);
                }
            }
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("INSERT INTO app_developer_profiles(user_id,display_name,website_url,support_email,status,created_at,updated_at)
                VALUES(:id,:display,:website,:email,'pending',:created,:updated)
                ON CONFLICT(user_id) DO UPDATE SET display_name=EXCLUDED.display_name,website_url=EXCLUDED.website_url,support_email=EXCLUDED.support_email,
                status='pending',review_note='',reviewed_by=NULL,reviewed_at=NULL,updated_at=EXCLUDED.updated_at");
            $stmt->execute([':id' => (int)$user['id'], ':display' => $display, ':website' => $website, ':email' => $email, ':created' => $now, ':updated' => $now]);
            hendystore_security_audit($db, (int)$user['id'], 'developer_profile_submitted', 'developer', (string)$user['id']);
            json_response(['ok' => true, 'data' => ['status' => 'pending']]);
        }

        case 'developer_apps': {
            require_method('GET');
            [$user] = hendystore_security_developer($db);
            $stmt = $db->prepare("SELECT p.id,p.slug,p.title,p.version,p.status,p.downloads,p.updated_at,
                    s.moderation_status,s.scan_status,s.sha256,s.package_size_bytes,s.release_track,s.review_note
                FROM products p LEFT JOIN app_release_security s ON s.product_id=p.id
                WHERE s.developer_user_id=:user ORDER BY p.updated_at DESC");
            $stmt->execute([':user' => (int)$user['id']]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        case 'developer_upload_intent': {
            require_method('POST');
            require_csrf();
            [$user] = hendystore_security_developer($db);
            $input = request_data();
            $name = basename(trim((string)($input['file_name'] ?? '')));
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $size = (int)($input['size'] ?? 0);
            $allowed = ['apk','apks','xapk','zip','7z','rar','exe','msi','dmg','pkg','deb','rpm','tar','gz','pdf','appx','msix','appimage','ipa'];
            $max = min(2 * 1024 * 1024 * 1024, max(1, (int)env_value('MAX_DIRECT_UPLOAD_BYTES', (string)(2 * 1024 * 1024 * 1024))));
            if ($name === '' || strlen($name) > 255 || !in_array($extension, $allowed, true) || $size < 1 || $size > $max) {
                json_response(['ok' => false, 'error' => 'Tệp không đúng định dạng hoặc vượt quá giới hạn 2 GB.'], 422);
            }
            $mime = trim((string)($input['content_type'] ?? 'application/octet-stream'));
            if (!preg_match('#^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$#i', $mime)) $mime = 'application/octet-stream';
            $token = bin2hex(random_bytes(16)) . '.' . $extension;
            $objectKey = 'packages/' . $token;
            try {
                $client = neon_s3_client();
                $command = $client->getCommand('PutObject', ['Bucket' => neon_storage_bucket(), 'Key' => $objectKey, 'ContentType' => $mime]);
                $request = $client->createPresignedRequest($command, '+5 minutes');
            } catch (Throwable $error) {
                error_log('[Hendy Store direct upload] Presign failed: ' . get_class($error));
                json_response(['ok' => false, 'error' => 'Không thể khởi tạo tải trực tiếp lên kho cloud. Kiểm tra cấu hình Object Storage.'], 503);
            }
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("INSERT INTO app_security_packages(object_key,uploader_id,original_name,file_size_bytes,scan_status,upload_method,created_at,updated_at)
                VALUES(:key,:user,:name,:size,'awaiting_upload','presigned',:created,:updated)");
            $stmt->execute([':key' => $objectKey, ':user' => (int)$user['id'], ':name' => $name, ':size' => $size, ':created' => $now, ':updated' => $now]);
            json_response(['ok' => true, 'data' => ['upload_url' => (string)$request->getUri(), 'object_token' => $token, 'content_type' => $mime, 'expires_in' => 300]]);
        }

        case 'developer_upload_finalize': {
            require_method('POST');
            require_csrf();
            [$user] = hendystore_security_developer($db);
            $input = request_data();
            $token = trim((string)($input['object_token'] ?? ''));
            $clientHash = strtolower(trim((string)($input['sha256'] ?? '')));
            if (!valid_package_token($token) || ($clientHash !== '' && !preg_match('/^[a-f0-9]{64}$/', $clientHash))) {
                json_response(['ok' => false, 'error' => 'Mã gói hoặc SHA-256 không hợp lệ.'], 422);
            }
            $objectKey = 'packages/' . $token;
            $package = hendystore_security_package_row($db, $objectKey);
            if (!$package || (int)$package['uploader_id'] !== (int)$user['id']) json_response(['ok' => false, 'error' => 'Không có quyền xác nhận gói tải này.'], 403);
            if (!neon_storage_exists($objectKey)) json_response(['ok' => false, 'error' => 'Cloud chưa nhận được tệp. Hãy kiểm tra lại tải lên.'], 409);
            $scan = hendystore_security_scan_object($db, $objectKey, $clientHash, (int)$package['file_size_bytes']);
            json_response(['ok' => true, 'data' => ['scan' => $scan, 'sha256' => $scan['sha256'] ?? '', 'status' => $scan['status'] ?? 'error']]);
        }

        case 'developer_upload_status': {
            require_method('GET');
            [$user] = hendystore_security_developer($db);
            $token = trim((string)($_GET['object_token'] ?? ''));
            if (!valid_package_token($token)) json_response(['ok' => false, 'error' => 'Mã gói không hợp lệ.'], 422);
            $objectKey = 'packages/' . $token;
            $package = hendystore_security_package_row($db, $objectKey);
            if (!$package || (int)$package['uploader_id'] !== (int)$user['id']) json_response(['ok' => false, 'error' => 'Không có quyền xem gói tải này.'], 403);
            json_response(['ok' => true, 'data' => [
                'status' => (string)$package['scan_status'],
                'sha256' => (string)$package['sha256'],
                'size' => (int)$package['file_size_bytes'],
                'scan_provider' => (string)$package['scan_provider'],
                'scan_summary' => (string)$package['scan_summary'],
                'scanned_at' => $package['scanned_at'],
            ]]);
        }

        case 'developer_scan_refresh': {
            require_method('POST');
            require_csrf();
            [$user] = hendystore_security_developer($db);
            $input = request_data();
            $token = trim((string)($input['object_token'] ?? ''));
            if (!valid_package_token($token)) json_response(['ok' => false, 'error' => 'Mã gói không hợp lệ.'], 422);
            $objectKey = 'packages/' . $token;
            $package = hendystore_security_package_row($db, $objectKey);
            if (!$package || (int)$package['uploader_id'] !== (int)$user['id']) json_response(['ok' => false, 'error' => 'Không có quyền kiểm tra gói tải này.'], 403);
            $apiKey = (string)env_value('VIRUSTOTAL_API_KEY', '');
            if ($apiKey !== '' && $package['scan_status'] === 'pending' && $package['analysis_id'] !== '') {
                $result = hendystore_security_vt_request('https://www.virustotal.com/api/v3/analyses/' . rawurlencode((string)$package['analysis_id']), $apiKey);
                $stats = $result['json']['data']['attributes']['stats'] ?? [];
                $status = $result['http'] === 200 && is_array($stats) ? hendystore_security_stats_status($stats) : 'pending';
                $scan = [
                    'status' => $status,
                    'provider' => 'VirusTotal',
                    'analysis_id' => (string)$package['analysis_id'],
                    'summary' => 'malicious=' . (int)($stats['malicious'] ?? 0) . '; suspicious=' . (int)($stats['suspicious'] ?? 0) . '; undetected=' . (int)($stats['undetected'] ?? 0),
                    'scanned' => in_array($status, ['clean','malicious'], true),
                ];
                hendystore_security_save_scan($db, $objectKey, $scan, (string)$package['sha256'], (int)$package['file_size_bytes']);
            } elseif ($package['scan_status'] !== 'clean') {
                $scan = hendystore_security_scan_object($db, $objectKey, (string)$package['sha256'], (int)$package['file_size_bytes']);
            } else {
                $scan = ['status' => 'clean', 'provider' => (string)$package['scan_provider'], 'summary' => (string)$package['scan_summary'], 'sha256' => (string)$package['sha256']];
            }
            $fresh = hendystore_security_package_row($db, $objectKey);
            json_response(['ok' => true, 'data' => [
                'status' => (string)($fresh['scan_status'] ?? 'error'),
                'sha256' => (string)($fresh['sha256'] ?? ''),
                'size' => (int)($fresh['file_size_bytes'] ?? 0),
                'scan_summary' => (string)($fresh['scan_summary'] ?? ''),
            ]]);
        }

        case 'developer_submit': {
            require_method('POST');
            require_csrf();
            [$user, $profile] = hendystore_security_developer($db);
            $input = request_data();
            $token = trim((string)($input['object_token'] ?? ''));
            if (!valid_package_token($token)) json_response(['ok' => false, 'error' => 'Gói cài đặt không hợp lệ.'], 422);
            $objectKey = 'packages/' . $token;
            $package = hendystore_security_package_row($db, $objectKey);
            if (!$package || (int)$package['uploader_id'] !== (int)$user['id'] || ($package['moderation_status'] ?? 'pending') !== 'pending' || $package['scan_status'] !== 'clean' || !preg_match('/^[a-f0-9]{64}$/', (string)$package['sha256'])) {
                json_response(['ok' => false, 'error' => 'Ứng dụng chỉ được gửi duyệt khi gói mới đã quét sạch, chưa từng được duyệt và có SHA-256 hợp lệ.'], 409);
            }
            $video = trim((string)($input['video_url'] ?? ''));
            if ($video !== '') {
                $videoParts = parse_url($video);
                if (!filter_var($video, FILTER_VALIDATE_URL) || strtolower((string)($videoParts['scheme'] ?? '')) !== 'https' || empty($videoParts['host']) || isset($videoParts['user']) || isset($videoParts['pass'])) {
                    json_response(['ok' => false, 'error' => 'Video giới thiệu phải là URL HTTPS hợp lệ.'], 422);
                }
            }
            $payload = array_merge($input, [
                'developer' => (string)$profile['display_name'],
                'downloadUrl' => 'file:' . $token,
                'status' => 'pending',
                'featured' => false,
                'priceType' => 'Free',
            ]);
            $product = validate_product_input($payload);
            $product[':status'] = 'pending';
            $product[':featured'] = 0;
            $now = date('Y-m-d H:i:s');
            $releasePlatform = strtolower((string)($input['release_platform'] ?? 'universal'));
            if (!in_array($releasePlatform, ['windows','macos','android','ios','linux','web','universal'], true)) $releasePlatform = 'universal';
            $track = (string)($input['release_track'] ?? 'production');
            if (!in_array($track, ['alpha','beta','production'], true)) $track = 'production';

            $existingStmt = $db->prepare('SELECT p.id,p.status,s.developer_user_id,s.moderation_status FROM products p LEFT JOIN app_release_security s ON s.product_id=p.id WHERE p.slug=:slug LIMIT 1');
            $existingStmt->execute([':slug' => (string)$product[':slug']]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                if ((int)($existing['developer_user_id'] ?? 0) !== (int)$user['id']) {
                    json_response(['ok' => false, 'error' => 'Slug đã được sử dụng hoặc ứng dụng không thuộc hồ sơ nhà phát triển của bạn.'], 409);
                }
                if (($existing['moderation_status'] ?? '') === 'pending') {
                    json_response(['ok' => false, 'error' => 'Ứng dụng này đang có một bản phát hành chờ kiểm duyệt. Hãy hoàn tất bản đó trước khi gửi phiên bản mới.'], 409);
                }
                $productId = (int)$existing['id'];
                $updateProduct = $db->prepare("UPDATE products SET title=:title,developer=:developer,category=:category,short_description=:short_description,description=:description,
                    version=CASE WHEN status='published' THEN version ELSE :version END,
                    platform=:platform,icon=:icon,cover=:cover,screenshots_json=:screenshots_json,
                    download_url=CASE WHEN status='published' THEN download_url ELSE :download_url END,
                    status=CASE WHEN status='published' THEN 'published' ELSE 'pending' END,
                    updated_at=:updated_at WHERE id=:id");
                $updateProduct->execute([
                    ':title' => $product[':title'], ':developer' => $product[':developer'], ':category' => $product[':category'],
                    ':short_description' => $product[':short_description'], ':description' => $product[':description'],
                    ':version' => $product[':version'], ':platform' => $product[':platform'], ':icon' => $product[':icon'],
                    ':cover' => $product[':cover'], ':screenshots_json' => $product[':screenshots_json'],
                    ':download_url' => $product[':download_url'], ':updated_at' => $now, ':id' => $productId,
                ]);
            } else {
                $sql = 'INSERT INTO products(slug,title,developer,category,short_description,description,version,platform,price_type,price_amount_vnd,icon,cover,screenshots_json,download_url,downloads,rating,featured,status,created_at,updated_at)
                    VALUES(:slug,:title,:developer,:category,:short_description,:description,:version,:platform,:price_type,:price_amount_vnd,:icon,:cover,:screenshots_json,:download_url,0,:rating,:featured,:status,:created_at,:updated_at)';
                if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') $sql .= ' RETURNING id';
                $stmt = $db->prepare($sql);
                $stmt->execute($product + [':created_at' => $now, ':updated_at' => $now]);
                $productId = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$db->lastInsertId();
            }

            // A new build stays non-current until an administrator approves it.
            $versionStmt = $db->prepare("INSERT INTO app_versions(product_id,version,release_notes,platform,architecture,min_os,package_url,package_size_bytes,is_current,release_track,created_at)
                VALUES(:product,:version,:notes,:platform,:architecture,:minos,:url,:size,0,:track,:created)");
            $versionStmt->execute([
                ':product' => $productId, ':version' => (string)$product[':version'],
                ':notes' => mb_substr_safe(trim((string)($input['release_notes'] ?? '')), 12000),
                ':platform' => $releasePlatform, ':architecture' => 'universal',
                ':minos' => mb_substr_safe(trim((string)($input['minimum_requirements'] ?? '')), 120),
                ':url' => 'file:' . $token, ':size' => (int)$package['file_size_bytes'],
                ':track' => $track, ':created' => $now,
            ]);
            hendystore_security_register_release($db, $productId, (int)$user['id'], 'file:' . $token, $track);
            $specStmt = $db->prepare('INSERT INTO app_product_specs(product_id,video_url,minimum_requirements,privacy_details,updated_at) VALUES(:id,:video,:minimum,:privacy,:updated) ON CONFLICT(product_id) DO UPDATE SET video_url=EXCLUDED.video_url,minimum_requirements=EXCLUDED.minimum_requirements,privacy_details=EXCLUDED.privacy_details,updated_at=EXCLUDED.updated_at');
            $specStmt->execute([
                ':id' => $productId,
                ':video' => $video,
                ':minimum' => mb_substr_safe(trim((string)($input['minimum_requirements'] ?? '')), 8000),
                ':privacy' => mb_substr_safe(trim((string)($input['privacy_details'] ?? '')), 8000),
                ':updated' => $now,
            ]);
            hendystore_security_audit($db, (int)$user['id'], 'release_submitted', 'product', (string)$productId, 'New release submitted for review.');
            json_response(['ok' => true, 'data' => ['product_id' => $productId, 'status' => 'pending', 'moderation_status' => 'pending']], 201);
        }

        case 'developer_analytics': {
            require_method('GET');
            [$user] = hendystore_security_developer($db);
            $productId = (int)($_GET['product_id'] ?? 0);
            $check = $db->prepare('SELECT id,title,downloads,rating FROM products p JOIN app_release_security s ON s.product_id=p.id WHERE p.id=:id AND s.developer_user_id=:user LIMIT 1');
            $check->execute([':id' => $productId, ':user' => (int)$user['id']]);
            $product = $check->fetch();
            if (!$product) json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng thuộc hồ sơ nhà phát triển.'], 404);
            $events = $db->prepare('SELECT event_type,platform,COUNT(*) AS total FROM app_store_analytics WHERE product_id=:id GROUP BY event_type,platform ORDER BY event_type,platform');
            $events->execute([':id' => $productId]);
            $since = date('Y-m-d H:i:s', strtotime('-30 days'));
            $daily = $db->prepare('SELECT DATE(created_at) AS day,event_type,COUNT(*) AS total FROM app_store_analytics WHERE product_id=:id AND created_at>=:since GROUP BY DATE(created_at),event_type ORDER BY day ASC,event_type');
            $daily->execute([':id' => $productId, ':since' => $since]);
            $crashes = $db->prepare('SELECT id,version,platform,error_message,status,created_at FROM app_crash_reports WHERE product_id=:id ORDER BY created_at DESC LIMIT 50');
            $crashes->execute([':id' => $productId]);
            json_response(['ok' => true, 'data' => ['product' => $product, 'events' => $events->fetchAll(), 'daily' => $daily->fetchAll(), 'crashes' => $crashes->fetchAll()]]);
        }

        case 'developer_versions': {
            require_method('GET');
            [$user] = hendystore_security_developer($db);
            $productId = (int)($_GET['product_id'] ?? 0);
            $owned = $db->prepare('SELECT p.id FROM products p JOIN app_release_security s ON s.product_id=p.id WHERE p.id=:id AND s.developer_user_id=:user LIMIT 1');
            $owned->execute([':id' => $productId, ':user' => (int)$user['id']]);
            if (!$owned->fetchColumn()) json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng thuộc hồ sơ nhà phát triển.'], 404);
            $stmt = $db->prepare("SELECT v.id,v.version,v.platform,v.architecture,v.package_size_bytes,v.is_current,v.created_at,v.release_notes,
                    p.sha256,p.moderation_status,p.scan_status
                FROM app_versions v
                JOIN app_security_packages p ON p.object_key='packages/' || SUBSTR(v.package_url,6)
                WHERE v.product_id=:product AND v.release_track='production'
                    AND p.moderation_status='approved' AND p.scan_status='clean'
                ORDER BY v.platform ASC,v.created_at DESC,v.id DESC");
            $stmt->execute([':product' => $productId]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        case 'developer_rollback': {
            require_method('POST');
            require_csrf();
            [$user] = hendystore_security_developer($db);
            $input = request_data();
            $productId = (int)($input['product_id'] ?? 0);
            $versionId = (int)($input['version_id'] ?? 0);
            if ($productId < 1 || $versionId < 1) json_response(['ok' => false, 'error' => 'Ứng dụng hoặc phiên bản không hợp lệ.'], 422);
            $owned = $db->prepare("SELECT p.id,s.moderation_status FROM products p JOIN app_release_security s ON s.product_id=p.id WHERE p.id=:id AND s.developer_user_id=:user LIMIT 1");
            $owned->execute([':id' => $productId, ':user' => (int)$user['id']]);
            $app = $owned->fetch();
            if (!$app) json_response(['ok' => false, 'error' => 'Không tìm thấy ứng dụng thuộc hồ sơ nhà phát triển.'], 404);
            if ($app['moderation_status'] === 'pending') json_response(['ok' => false, 'error' => 'Hãy hoàn tất kiểm duyệt phiên bản đang chờ trước khi khôi phục bản cũ.'], 409);
            $stmt = $db->prepare("SELECT v.id,v.version,v.platform,v.package_url,v.package_size_bytes,p.object_key,p.sha256,p.scan_status,p.moderation_status
                FROM app_versions v
                JOIN app_security_packages p ON p.object_key='packages/' || SUBSTR(v.package_url,6)
                WHERE v.id=:version AND v.product_id=:product AND v.release_track='production'
                    AND p.moderation_status='approved' AND p.scan_status='clean' LIMIT 1");
            $stmt->execute([':version' => $versionId, ':product' => $productId]);
            $version = $stmt->fetch();
            if (!$version || !preg_match('/^[a-f0-9]{64}$/', (string)$version['sha256']) || !neon_storage_exists((string)$version['object_key'])) {
                json_response(['ok' => false, 'error' => 'Chỉ được khôi phục phiên bản Production đã quét sạch và được duyệt.'], 409);
            }
            $now = date('Y-m-d H:i:s');
            $clear = $db->prepare("UPDATE app_versions SET is_current=0 WHERE product_id=:product AND platform=:platform AND release_track='production'");
            $clear->execute([':product' => $productId, ':platform' => (string)$version['platform']]);
            $set = $db->prepare('UPDATE app_versions SET is_current=1 WHERE id=:id AND product_id=:product');
            $set->execute([':id' => $versionId, ':product' => $productId]);
            $productUpdate = $db->prepare("UPDATE products SET status='published',version=:version,download_url=:url,updated_at=:now WHERE id=:id");
            $productUpdate->execute([':version' => (string)$version['version'], ':url' => (string)$version['package_url'], ':now' => $now, ':id' => $productId]);
            $security = $db->prepare("UPDATE app_release_security SET object_key=:key,moderation_status='approved',scan_status='clean',sha256=:sha,package_size_bytes=:size,release_track='production',submitted_at=:now,updated_at=:now WHERE product_id=:id");
            $security->execute([':key' => (string)$version['object_key'], ':sha' => (string)$version['sha256'], ':size' => (int)$version['package_size_bytes'], ':now' => $now, ':id' => $productId]);
            hendystore_security_audit($db, (int)$user['id'], 'production_version_rollback', 'product', (string)$productId, 'Restored release version #' . $versionId);
            json_response(['ok' => true, 'data' => ['version_id' => $versionId, 'version' => (string)$version['version']]]);
        }

        case 'developer_reviews': {
            require_method('GET');
            [$user] = hendystore_security_developer($db);
            $stmt = $db->prepare("SELECT r.id,r.product_id,r.rating,r.review_text,r.status,r.developer_reply,r.developer_reply_at,r.created_at,
                    p.title AS product_title,u.name AS author_name
                FROM app_product_reviews r
                JOIN products p ON p.id=r.product_id
                JOIN users u ON u.id=r.user_id
                JOIN app_release_security s ON s.product_id=r.product_id
                WHERE s.developer_user_id=:user
                ORDER BY r.created_at DESC LIMIT 100");
            $stmt->execute([':user' => (int)$user['id']]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        case 'developer_review_reply': {
            require_method('POST');
            require_csrf();
            [$user] = hendystore_security_developer($db);
            rate_limit_or_fail($db, 'developer-review-reply', 30, 3600);
            $input = request_data();
            $reviewId = (int)($input['review_id'] ?? 0);
            $reply = trim((string)($input['reply'] ?? ''));
            if ($reviewId < 1 || $reply === '' || strlen($reply) > 2000) {
                json_response(['ok' => false, 'error' => 'Nội dung phản hồi phải từ 1 đến 2.000 ký tự.'], 422);
            }
            $stmt = $db->prepare("UPDATE app_product_reviews SET developer_reply=:reply,developer_reply_at=:now,updated_at=:now
                WHERE id=:id AND status='approved'
                AND product_id IN (SELECT product_id FROM app_release_security WHERE developer_user_id=:user)");
            $now = date('Y-m-d H:i:s');
            $stmt->execute([':reply' => $reply, ':now' => $now, ':id' => $reviewId, ':user' => (int)$user['id']]);
            if ($stmt->rowCount() < 1) {
                json_response(['ok' => false, 'error' => 'Chỉ có thể phản hồi đánh giá đã duyệt của ứng dụng thuộc hồ sơ nhà phát triển.'], 404);
            }
            hendystore_security_audit($db, (int)$user['id'], 'developer_review_replied', 'review', (string)$reviewId);
            json_response(['ok' => true]);
        }

        case 'developer_crash_report': {
            require_method('POST');
            require_csrf();
            $user = hendystore_security_user($db);
            rate_limit_or_fail($db, 'developer-crash-report', 10, 3600);
            $input = request_data();
            $slug = trim((string)($input['slug'] ?? ''));
            $productStmt = $db->prepare("SELECT id FROM products WHERE slug=:slug AND status='published' LIMIT 1");
            $productStmt->execute([':slug' => $slug]);
            $productId = (int)$productStmt->fetchColumn();
            $message = trim((string)($input['error_message'] ?? ''));
            if (!$productId || $message === '' || strlen($message) > 8000) json_response(['ok' => false, 'error' => 'Báo cáo lỗi không hợp lệ.'], 422);
            $stack = mb_substr_safe(trim((string)($input['stack_trace'] ?? '')), 12000);
            $version = mb_substr_safe(trim((string)($input['version'] ?? '')), 60);
            $platform = strtolower((string)($input['platform'] ?? 'unknown'));
            if (!in_array($platform, ['windows','macos','android','ios','linux','web','unknown'], true)) $platform = 'unknown';
            $stmt = $db->prepare("INSERT INTO app_crash_reports(product_id,user_id,version,platform,error_message,stack_trace,created_at)
                VALUES(:product,:user,:version,:platform,:message,:stack,:created)");
            $stmt->execute([':product' => $productId, ':user' => (int)$user['id'], ':version' => $version, ':platform' => $platform, ':message' => $message, ':stack' => $stack, ':created' => date('Y-m-d H:i:s')]);
            json_response(['ok' => true]);
        }

        case 'trust_queue': {
            require_method('GET');
            $admin = require_admin($db);
            $developers = $db->query("SELECT d.user_id,d.display_name,d.website_url,d.support_email,d.status,d.created_at,u.name AS account_name,u.email AS account_email
                FROM app_developer_profiles d JOIN users u ON u.id=d.user_id WHERE d.status='pending' ORDER BY d.created_at ASC LIMIT 200")->fetchAll();
            $releases = $db->query("SELECT s.product_id,s.developer_user_id,s.object_key,s.moderation_status,s.scan_status,s.sha256,s.package_size_bytes,s.release_track,s.submitted_at,s.review_note,
                    p.title,p.slug,p.developer,p.category,p.version,p.download_url,u.name AS submitter_name,u.email AS submitter_email
                FROM app_release_security s JOIN products p ON p.id=s.product_id JOIN users u ON u.id=s.developer_user_id
                WHERE s.moderation_status='pending' ORDER BY s.submitted_at ASC LIMIT 300")->fetchAll();
            json_response(['ok' => true, 'data' => ['developers' => $developers, 'releases' => $releases, 'admin' => ['id' => (int)$admin['id']]]]);
        }

        case 'trust_developer_review': {
            require_method('POST');
            require_csrf();
            $admin = require_admin($db);
            $input = request_data();
            $userId = (int)($input['user_id'] ?? 0);
            $status = (string)($input['status'] ?? '');
            $note = mb_substr_safe(trim((string)($input['note'] ?? '')), 2000);
            if ($userId < 1 || !in_array($status, ['approved','rejected'], true)) json_response(['ok' => false, 'error' => 'Quyết định hồ sơ nhà phát triển không hợp lệ.'], 422);
            $stmt = $db->prepare('UPDATE app_developer_profiles SET status=:status,review_note=:note,reviewed_by=:admin,reviewed_at=:now,updated_at=:now WHERE user_id=:id');
            $stmt->execute([':status' => $status, ':note' => $note, ':admin' => (int)$admin['id'], ':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
            if ($stmt->rowCount() < 1) json_response(['ok' => false, 'error' => 'Không tìm thấy hồ sơ nhà phát triển.'], 404);
            hendystore_security_audit($db, (int)$admin['id'], 'developer_profile_' . $status, 'developer', (string)$userId, $note);
            json_response(['ok' => true]);
        }

        case 'trust_release_review': {
            require_method('POST');
            require_csrf();
            $admin = require_admin($db);
            $input = request_data();
            $productId = (int)($input['product_id'] ?? 0);
            $status = (string)($input['status'] ?? '');
            $note = mb_substr_safe(trim((string)($input['note'] ?? '')), 2000);
            if ($productId < 1 || !in_array($status, ['approved','rejected'], true)) json_response(['ok' => false, 'error' => 'Quyết định kiểm duyệt không hợp lệ.'], 422);
            $stmt = $db->prepare('SELECT object_key,scan_status,sha256,release_track FROM app_release_security WHERE product_id=:id LIMIT 1');
            $stmt->execute([':id' => $productId]);
            $release = $stmt->fetch();
            if (!$release) json_response(['ok' => false, 'error' => 'Không tìm thấy hồ sơ phát hành.'], 404);
            if ($status === 'approved') {
                $packageRow = hendystore_security_package_row($db, (string)$release['object_key']);
                if (!$packageRow) {
                    json_response(['ok' => false, 'error' => 'Không tìm thấy hồ sơ gói tải riêng tư để xác minh.'], 409);
                }
                if (($packageRow['upload_method'] ?? 'presigned') === 'presigned' && strtotime((string)$packageRow['created_at']) > time() - 360) {
                    json_response(['ok' => false, 'error' => 'Chờ ít nhất 6 phút sau khi tải tệp lên để URL upload hết hạn hoàn toàn trước khi xuất bản.'], 409);
                }
                if ($release['scan_status'] !== 'clean' || !preg_match('/^[a-f0-9]{64}$/', (string)$release['sha256']) || !neon_storage_exists((string)$release['object_key'])) {
                    json_response(['ok' => false, 'error' => 'Không thể xuất bản: bắt buộc có kết quả quét sạch, SHA-256 hợp lệ và gói còn tồn tại trong kho riêng tư.'], 409);
                }
                // A presigned PUT URL can remain valid briefly after upload. Re-hash
                // and rescan the exact stored object at approval time to prevent
                // an overwrite between the first scan and manual review.
                $verified = hendystore_security_scan_object($db, (string)$release['object_key'], (string)$release['sha256'], (int)$release['package_size_bytes']);
                if (($verified['status'] ?? '') !== 'clean' || !isset($verified['sha256']) || !hash_equals((string)$release['sha256'], (string)$verified['sha256'])) {
                    $scanUpdate = $db->prepare('UPDATE app_release_security SET scan_status=:status,sha256=:sha,package_size_bytes=:size,updated_at=:now WHERE product_id=:id');
                    $scanUpdate->execute([':status' => (string)($verified['status'] ?? 'integrity_error'), ':sha' => (string)($verified['sha256'] ?? ''), ':size' => (int)($verified['size'] ?? 0), ':now' => date('Y-m-d H:i:s'), ':id' => $productId]);
                    $packageUpdate = $db->prepare("UPDATE app_security_packages SET moderation_status='pending' WHERE object_key=:key");
                    $packageUpdate->execute([':key' => (string)$release['object_key']]);
                    json_response(['ok' => false, 'error' => 'Gói đã thay đổi hoặc chưa vượt qua lần quét xác minh cuối. Bản phát hành mới vẫn bị khóa; phiên bản đã duyệt trước đó không bị ảnh hưởng.'], 409);
                }
                $packageApprove = $db->prepare("UPDATE app_security_packages SET moderation_status='approved' WHERE object_key=:key AND scan_status='clean' AND sha256=:sha");
                $packageApprove->execute([':key' => (string)$release['object_key'], ':sha' => (string)$verified['sha256']]);
                if ($packageApprove->rowCount() !== 1) {
                    json_response(['ok' => false, 'error' => 'Không thể gắn trạng thái duyệt vào đúng gói cài đặt.'], 409);
                }
                if (($release['release_track'] ?? 'production') === 'production') {
                    $packageUrl = 'file:' . basename((string)$release['object_key']);
                    $versionStmt = $db->prepare("SELECT id,version,platform,package_url FROM app_versions WHERE product_id=:product AND package_url=:url AND release_track='production' ORDER BY created_at DESC,id DESC LIMIT 1");
                    $versionStmt->execute([':product' => $productId, ':url' => $packageUrl]);
                    $approvedVersion = $versionStmt->fetch();
                    if (!$approvedVersion) {
                        json_response(['ok' => false, 'error' => 'Không tìm thấy bản ghi phiên bản production tương ứng với gói đã duyệt.'], 409);
                    }
                    $clearCurrent = $db->prepare('UPDATE app_versions SET is_current=0 WHERE product_id=:product AND platform=:platform AND release_track=\'production\'');
                    $clearCurrent->execute([':product' => $productId, ':platform' => (string)$approvedVersion['platform']]);
                    $setCurrent = $db->prepare('UPDATE app_versions SET is_current=1 WHERE id=:id AND product_id=:product');
                    $setCurrent->execute([':id' => (int)$approvedVersion['id'], ':product' => $productId]);
                    $publish = $db->prepare("UPDATE products SET status='published',version=:version,download_url=:url,updated_at=:now WHERE id=:id");
                    $publish->execute([':version' => (string)$approvedVersion['version'], ':url' => $packageUrl, ':now' => date('Y-m-d H:i:s'), ':id' => $productId]);
                }
            } else {
                $packageReject = $db->prepare("UPDATE app_security_packages SET moderation_status='rejected' WHERE object_key=:key");
                $packageReject->execute([':key' => (string)$release['object_key']]);
                $stillPublished = $db->prepare("SELECT COUNT(*) FROM app_versions v JOIN app_security_packages p ON p.object_key='packages/' || SUBSTR(v.package_url,6)
                    WHERE v.product_id=:id AND v.release_track='production' AND p.moderation_status='approved' AND p.scan_status='clean'");
                $stillPublished->execute([':id' => $productId]);
                if ((int)$stillPublished->fetchColumn() < 1) {
                    $reject = $db->prepare("UPDATE products SET status='rejected',featured=0,updated_at=:now WHERE id=:id");
                    $reject->execute([':now' => date('Y-m-d H:i:s'), ':id' => $productId]);
                }
            }
            $update = $db->prepare('UPDATE app_release_security SET moderation_status=:status,review_note=:note,reviewed_by=:admin,reviewed_at=:now,updated_at=:now WHERE product_id=:id');
            $update->execute([':status' => $status, ':note' => $note, ':admin' => (int)$admin['id'], ':now' => date('Y-m-d H:i:s'), ':id' => $productId]);
            hendystore_security_audit($db, (int)$admin['id'], 'release_' . $status, 'product', (string)$productId, $note);
            json_response(['ok' => true]);
        }

        case 'trust_scan_refresh': {
            require_method('POST');
            require_csrf();
            require_admin($db);
            $input = request_data();
            $productId = (int)($input['product_id'] ?? 0);
            $stmt = $db->prepare('SELECT object_key,developer_user_id FROM app_release_security WHERE product_id=:id LIMIT 1');
            $stmt->execute([':id' => $productId]);
            $release = $stmt->fetch();
            if (!$release || $release['object_key'] === '') json_response(['ok' => false, 'error' => 'Không tìm thấy gói nội bộ để quét.'], 404);
            $package = hendystore_security_package_row($db, (string)$release['object_key']);
            if (!$package) json_response(['ok' => false, 'error' => 'Thiếu hồ sơ gói tải.'], 404);
            if ($package['scan_status'] === 'pending' && $package['analysis_id'] !== '') {
                $apiKey = (string)env_value('VIRUSTOTAL_API_KEY', '');
                $result = hendystore_security_vt_request('https://www.virustotal.com/api/v3/analyses/' . rawurlencode((string)$package['analysis_id']), $apiKey);
                $stats = $result['json']['data']['attributes']['stats'] ?? [];
                $status = $result['http'] === 200 && is_array($stats) ? hendystore_security_stats_status($stats) : 'pending';
                $scan = ['status' => $status, 'provider' => 'VirusTotal', 'analysis_id' => (string)$package['analysis_id'], 'summary' => 'malicious=' . (int)($stats['malicious'] ?? 0) . '; suspicious=' . (int)($stats['suspicious'] ?? 0) . '; undetected=' . (int)($stats['undetected'] ?? 0), 'scanned' => in_array($status, ['clean','malicious'], true)];
                hendystore_security_save_scan($db, (string)$release['object_key'], $scan, (string)$package['sha256'], (int)$package['file_size_bytes']);
            } else {
                $scan = hendystore_security_scan_object($db, (string)$release['object_key'], (string)$package['sha256'], (int)$package['file_size_bytes']);
            }
            $fresh = hendystore_security_package_row($db, (string)$release['object_key']);
            $update = $db->prepare('UPDATE app_release_security SET scan_status=:scan,sha256=:sha,package_size_bytes=:bytes,updated_at=:now WHERE product_id=:id');
            $update->execute([':scan' => (string)($fresh['scan_status'] ?? 'error'), ':sha' => (string)($fresh['sha256'] ?? ''), ':bytes' => (int)($fresh['file_size_bytes'] ?? 0), ':now' => date('Y-m-d H:i:s'), ':id' => $productId]);
            json_response(['ok' => true, 'data' => ['scan' => $scan]]);
        }
    }
    return true;
}

function hendystore_security_track_event(PDO $db, int $productId, string $eventType): void
{
    if (!in_array($eventType, ['product_view','download_prepare','download_completed'], true)) return;
    hendystore_security_schema($db);
    $platform = strtolower((string)($_SERVER['HTTP_X_HENDY_PLATFORM'] ?? 'unknown'));
    if (!in_array($platform, ['windows','macos','android','linux','web','unknown'], true)) $platform = 'unknown';
    $secret = (string)env_value('APP_KEY', hash('sha256', __FILE__));
    $ipHash = hash_hmac('sha256', request_ip(), $secret);
    $stmt = $db->prepare('INSERT INTO app_store_analytics(product_id,event_type,platform,ip_hash,created_at) VALUES(:product,:event,:platform,:ip,:created)');
    $stmt->execute([':product' => $productId, ':event' => $eventType, ':platform' => $platform, ':ip' => $ipHash, ':created' => date('Y-m-d H:i:s')]);
}

function hendystore_security_track_product_view(PDO $db, int $productId): void
{
    hendystore_security_track_event($db, $productId, 'product_view');
}
