<?php
declare(strict_types=1);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

/* Environment, session, database and validation helpers for Hendy Store. */
function load_local_env(): void {
    $file = dirname(__DIR__) . '/.env';
    if (!is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if ($key === '' || getenv($key) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) $value = substr($value, 1, -1);
        $_ENV[$key] = $value;
    }
}
load_local_env();

function env_value(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string)$value;
}
function env_bool(string $key, bool $default = false): bool {
    $value = env_value($key);
    return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
}
function request_is_https(): bool {
    if (env_bool('TRUST_PROXY_HEADERS')) {
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
        if (in_array($forwardedProto, ['http', 'https'], true)) {
            return $forwardedProto === 'https';
        }

        $cloudflareVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
        if (is_array($cloudflareVisitor) && strtolower((string)($cloudflareVisitor['scheme'] ?? '')) === 'https') {
            return true;
        }
    }

    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

/**
 * Normalize an incoming Host/X-Forwarded-Host value before using it in an
 * absolute URL. Reject URL syntax, credentials, paths, malformed DNS labels
 * and invalid ports instead of reflecting arbitrary request input.
 */
function normalize_request_host(string $candidate): ?string {
    $candidate = trim(explode(',', $candidate, 2)[0]);
    if ($candidate === '' || strlen($candidate) > 300
        || preg_match('/[\x00-\x20\/\\\\?#@]/', $candidate)) {
        return null;
    }

    $parts = parse_url('https://' . $candidate);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    $bareHost = trim($host, '[]');
    if ($bareHost === '') {
        return null;
    }

    if (filter_var($bareHost, FILTER_VALIDATE_IP) === false) {
        $label = '(?:[a-z0-9]|[a-z0-9][a-z0-9-]{0,61}[a-z0-9])';
        if (strlen($bareHost) > 253 || preg_match('/^' . $label . '(?:\.' . $label . ')*$/iD', $bareHost) !== 1) {
            return null;
        }
    }

    $port = $parts['port'] ?? null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }

    $normalized = str_contains($bareHost, ':') ? '[' . $bareHost . ']' : $bareHost;
    if ($port !== null && !in_array((int)$port, [80, 443], true)) {
        $normalized .= ':' . $port;
    }

    return $normalized;
}

function request_hostname(string $host): string {
    $parts = parse_url('https://' . $host);
    return strtolower(trim((string)($parts['host'] ?? ''), '[]'));
}

/**
 * APP_ALLOWED_HOSTS accepts comma-separated exact hosts and wildcard
 * entries such as *.example.com. An empty value trusts only RENDER_EXTERNAL_URL.
 */
function app_request_host_is_allowed(string $host): bool {
    $configured = trim((string)env_value('APP_ALLOWED_HOSTS', ''));
    if ($configured === '') {
        $renderUrl = trim((string)env_value('RENDER_EXTERNAL_URL', ''));
        $renderHost = $renderUrl !== '' ? normalize_request_host((string)parse_url($renderUrl, PHP_URL_HOST)) : null;
        return $renderHost !== null && request_hostname($renderHost) === request_hostname($host);
    }

    $hostname = request_hostname($host);
    foreach (explode(',', $configured) as $entry) {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            continue;
        }

        if (str_starts_with($entry, '*.')) {
            $suffix = request_hostname(substr($entry, 2));
            if ($suffix !== '' && $hostname !== $suffix && str_ends_with($hostname, '.' . $suffix)) {
                return true;
            }
            continue;
        }

        if (str_starts_with($entry, '.')) {
            $suffix = request_hostname(substr($entry, 1));
            if ($suffix !== '' && ($hostname === $suffix || str_ends_with($hostname, '.' . $suffix))) {
                return true;
            }
            continue;
        }

        $normalizedEntry = normalize_request_host($entry);
        if ($normalizedEntry !== null && request_hostname($normalizedEntry) === $hostname) {
            return true;
        }
    }

    return false;
}

function validate_public_base_url(string $url, bool $production, string $setting): string {
    $parts = parse_url($url);
    $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
    $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');
    $port = is_array($parts) && isset($parts['port']) ? ':' . (string)$parts['port'] : '';
    $normalizedHost = $host !== '' ? normalize_request_host($host . $port) : null;
    $path = (string)(is_array($parts) ? ($parts['path'] ?? '') : '');

    if ($normalizedHost === null || ($scheme !== 'https' && !(!$production && $scheme === 'http'))
        || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || str_contains($path, '..')) {
        throw new RuntimeException($setting . ' must be a valid public HTTPS base URL without credentials, query, fragment or traversal path.');
    }

    return rtrim($url, '/');
}

function app_base_url(): string {
    $configuredUrl = trim((string)env_value('APP_URL', ''));
    $production = env_value('APP_ENV', 'development') === 'production';
    $autoMode = $configuredUrl === '' || strtolower($configuredUrl) === 'auto';

    // An explicit URL remains the canonical override for single-domain installs.
    if (!$autoMode) {
        return validate_public_base_url($configuredUrl, $production, 'APP_URL');
    }

    // Render/Cloudflare proxy deployments can serve several domains from one
    // origin. In auto mode, derive the origin from the trusted proxy host for
    // each request so canonical links and redirects follow the requested domain.
    if (env_bool('APP_AUTO_DETECT_DOMAINS', $production) && env_bool('TRUST_PROXY_HEADERS')) {
        $candidates = [
            (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''),
            (string)($_SERVER['HTTP_HOST'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $host = normalize_request_host($candidate);
            if ($host !== null && app_request_host_is_allowed($host)) {
                $scheme = ($production || request_is_https()) ? 'https' : 'http';
                return $scheme . '://' . $host;
            }
        }
    }

    // Render supplies this automatically. It is also a safe fallback for CLI
    // tasks and requests that do not carry a usable trusted proxy hostname.
    $renderUrl = trim((string)env_value('RENDER_EXTERNAL_URL', ''));
    if ($renderUrl !== '') {
        return validate_public_base_url($renderUrl, $production, 'RENDER_EXTERNAL_URL');
    }

    if (!$production) {
        $host = normalize_request_host((string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? 'localhost';
        return (request_is_https() ? 'https://' : 'http://') . $host;
    }

    throw new RuntimeException(
        'Cannot determine the public HTTPS origin. Set APP_URL, or enable APP_AUTO_DETECT_DOMAINS with TRUST_PROXY_HEADERS behind a trusted proxy; RENDER_EXTERNAL_URL is used automatically on Render.'
    );
}
function enforce_https_request(): void {
    if (PHP_SAPI === 'cli' || env_value('APP_ENV', 'development') !== 'production' || !env_bool('FORCE_HTTPS', true) || request_is_https()) return;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    $query = []; parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?: ''), $query);
    if (str_ends_with($path, '/api.php') && ($query['action'] ?? '') === 'health') return;
    $base = app_base_url();
    $basePath = rtrim((string)(parse_url($base, PHP_URL_PATH) ?: ''), '/');
    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) $path = substr($path, strlen($basePath)) ?: '/';
    $target = $base . '/' . ltrim($path, '/');
    if ($path === '/') $target = $base . '/';
    if (!empty($query)) $target .= '?' . http_build_query($query);
    header('Cache-Control: no-store'); header('Location: ' . $target, true, 308); exit;
}
function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function request_data(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}
function require_method(string $expected): void {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
        header('Allow: ' . $expected);
        json_response(['ok'=>false,'error'=>'Phương thức không được hỗ trợ.'],405);
    }
}
function storage_root(): string {
    $path = (string)env_value('STORAGE_PATH', dirname(__DIR__) . '/storage');
    if (!is_dir($path) && !mkdir($path,0770,true) && !is_dir($path)) throw new RuntimeException('Cannot create private storage.');
    if (!is_writable($path)) throw new RuntimeException('Private storage is not writable.');
    return rtrim($path, DIRECTORY_SEPARATOR);
}
function store_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secureCookie = env_value('APP_ENV', 'development') === 'production'
        || request_is_https()
        || env_bool('FORCE_SECURE_COOKIES');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    $basePath = (string)(parse_url(app_base_url(), PHP_URL_PATH) ?: '/');
    $path = rtrim($basePath, '/') . '/';
    if ($path === '') $path = '/';
    session_name('hendy_store_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $db = store_db();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        session_set_save_handler(new NeonPostgresSessionHandler($db), true);
    }
    session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/** Normalize an Origin or Referer value to an exact scheme/host/port origin. */
function normalize_security_origin(string $value): ?string
{
    $parts = parse_url(trim($value));
    if (!is_array($parts)) return null;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['https', 'http'], true) || $host === ''
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array((string)($parts['path'] ?? ''), ['', '/'], true)) {
        return null;
    }
    if ($scheme !== 'https' && env_value('APP_ENV', 'development') === 'production') return null;
    $normalizedHost = normalize_request_host($host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''));
    if ($normalizedHost === null) return null;
    return $scheme . '://' . strtolower($normalizedHost);
}

function app_security_origin_allowed(string $origin): bool
{
    $normalized = normalize_security_origin($origin);
    if ($normalized === null) return false;

    $baseOrigin = normalize_security_origin(app_base_url());
    if ($baseOrigin !== null && hash_equals($baseOrigin, $normalized)) return true;

    foreach (explode(',', (string)env_value('APP_ALLOWED_ORIGINS', '')) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') continue;
        $allowed = normalize_security_origin($candidate);
        if ($allowed !== null && hash_equals($allowed, $normalized)) return true;
    }
    return false;
}

function require_csrf(): void
{
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite === 'cross-site') {
        json_response(['ok' => false, 'error' => 'Yêu cầu từ nguồn không được phép.'], 403);
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origin = (string)$parts['scheme'] . '://' . (string)$parts['host']
                    . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
            } else {
                json_response(['ok' => false, 'error' => 'Nguồn yêu cầu không hợp lệ.'], 403);
            }
        }
    }
    if ($origin !== '' && !app_security_origin_allowed($origin)) {
        json_response(['ok' => false, 'error' => 'Nguồn yêu cầu không được phép.'], 403);
    }

    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        json_response(['ok' => false, 'error' => 'Phiên bảo mật đã hết hạn. Hãy tải lại trang.'], 419);
    }
}

function database_url_to_pdo(string $url): array
{
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!is_array($parts) || !in_array($scheme, ['postgres', 'postgresql', 'mysql', 'mariadb'], true)) {
        throw new RuntimeException('DATABASE_URL must use PostgreSQL or MySQL/MariaDB.');
    }
    $host = (string)($parts['host'] ?? '');
    $database = rawurldecode(ltrim((string)($parts['path'] ?? ''), '/'));
    $username = rawurldecode((string)($parts['user'] ?? ''));
    $password = rawurldecode((string)($parts['pass'] ?? ''));
    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('DATABASE_URL is missing its host, database, or username.');
    }
    $isMysql = in_array($scheme, ['mysql', 'mariadb'], true);
    $port = (int)($parts['port'] ?? ($isMysql ? 3306 : 5432));
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('DATABASE_URL contains an invalid port.');
    }
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    // Credentials never enter logs. Require TLS on network PostgreSQL connections.
    if ($isMysql) {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
        return [$dsn, $username, $password];
    }
    $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';sslmode=require';
    if (($query['channel_binding'] ?? '') === 'require') $dsn .= ';channel_binding=require';
    return [$dsn, $username, $password];
}

function store_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $configured = env_value('DATABASE_URL', env_value('DB_DSN'));
    $user = env_value('DB_USER', '');
    $pass = env_value('DB_PASSWORD', '');
    $driver = 'sqlite';

    if ($configured !== null && preg_match('#^(?:postgres|postgresql|mysql|mariadb)://#i', $configured)) {
        [$dsn, $user, $pass] = database_url_to_pdo($configured);
        $driver = str_starts_with($dsn, 'mysql:') ? 'mysql' : 'pgsql';
    } elseif ($configured !== null) {
        $dsn = $configured;
        $driver = str_starts_with($dsn, 'pgsql:') ? 'pgsql' : (str_starts_with($dsn, 'mysql:') ? 'mysql' : 'sqlite');
    } elseif (env_value('APP_ENV', 'development') === 'production') {
        throw new RuntimeException('DATABASE_URL is required in production. Configure the selected PostgreSQL or MySQL connection string.');
    } else {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO SQLite is unavailable. Configure DATABASE_URL for PostgreSQL.');
        }
        $sqlitePath = (string) env_value('SQLITE_PATH', storage_root() . '/hendy-store.sqlite');
        $parent = dirname($sqlitePath);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create SQLite directory.');
        }
        $dsn = 'sqlite:' . $sqlitePath;
        $driver = 'sqlite';
    }

    $requiredDriver = ['pgsql' => 'pgsql', 'mysql' => 'mysql', 'sqlite' => 'sqlite'][$driver];
    if (!in_array($requiredDriver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Required PDO driver "' . $requiredDriver . '" is not installed in this PHP runtime.');
    }

    if (env_value('APP_ENV', 'development') === 'production' && strlen((string) env_value('APP_KEY', '')) < 32) {
        throw new RuntimeException('APP_KEY must be a random secret containing at least 32 characters in production.');
    }

    $db = new PDO($dsn, (string) $user, (string) $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);

    if ($driver === 'sqlite') {
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 8000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
    }
    if ($driver === 'pgsql') {
        $db->exec("SET TIME ZONE 'UTC'");
    }

    initialize_schema($db, $driver);
    ensure_environment_admin($db);
    return $db;
}

function initialize_schema(PDO $db, string $driver): void
{
    if ($driver === 'pgsql') {
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'customer',
            neon_auth_subject TEXT NULL,
            created_at TIMESTAMP NOT NULL
        )");
        $db->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS neon_auth_subject TEXT NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_neon_auth_subject ON users(neon_auth_subject) WHERE neon_auth_subject IS NOT NULL');
        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id BIGSERIAL PRIMARY KEY,
            slug VARCHAR(180) NOT NULL UNIQUE,
            title VARCHAR(180) NOT NULL,
            developer VARCHAR(160) NOT NULL,
            category VARCHAR(100) NOT NULL,
            short_description VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
            platform VARCHAR(180) NOT NULL DEFAULT 'Windows, Android, Web',
            price_type VARCHAR(30) NOT NULL DEFAULT 'Free',
            price_amount_vnd BIGINT NOT NULL DEFAULT 0,
            icon VARCHAR(500) NOT NULL,
            cover VARCHAR(500) NOT NULL,
            screenshots_json TEXT NOT NULL DEFAULT '[]',
            download_url TEXT NOT NULL DEFAULT '',
            downloads BIGINT NOT NULL DEFAULT 0,
            rating NUMERIC(2,1) NOT NULL DEFAULT 0,
            featured SMALLINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_category_status ON products(category,status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_downloads ON products(downloads)');
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            bucket_key VARCHAR(64) PRIMARY KEY,
            hits INTEGER NOT NULL DEFAULT 0,
            window_started_at BIGINT NOT NULL
        )");
        $db->exec('CREATE TABLE IF NOT EXISTS app_meta (meta_key VARCHAR(100) PRIMARY KEY, meta_value TEXT NOT NULL)');
        $db->exec("CREATE TABLE IF NOT EXISTS app_sessions (
            session_id VARCHAR(128) PRIMARY KEY,
            session_data TEXT NOT NULL,
            expires_at BIGINT NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_app_sessions_expires ON app_sessions(expires_at)');
    } elseif ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'customer',
            neon_auth_subject TEXT UNIQUE,
            created_at TEXT NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            developer TEXT NOT NULL,
            category TEXT NOT NULL,
            short_description TEXT NOT NULL,
            description TEXT NOT NULL,
            version TEXT NOT NULL DEFAULT '1.0.0',
            platform TEXT NOT NULL DEFAULT 'Windows, Android, Web',
            price_type TEXT NOT NULL DEFAULT 'Free',
            icon TEXT NOT NULL,
            cover TEXT NOT NULL,
            screenshots_json TEXT NOT NULL DEFAULT '[]',
            download_url TEXT NOT NULL DEFAULT '',
            downloads INTEGER NOT NULL DEFAULT 0,
            rating REAL NOT NULL DEFAULT 0,
            featured INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'draft',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_category_status ON products(category,status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_downloads ON products(downloads)');
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            bucket_key TEXT PRIMARY KEY,
            hits INTEGER NOT NULL DEFAULT 0,
            window_started_at INTEGER NOT NULL
        )");
        $db->exec('CREATE TABLE IF NOT EXISTS app_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL)');
        $db->exec("CREATE TABLE IF NOT EXISTS app_sessions (
            session_id TEXT PRIMARY KEY,
            session_data TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )");
    } elseif ($driver === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'customer',
            neon_auth_subject VARCHAR(255) NULL UNIQUE,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(180) NOT NULL UNIQUE,
            title VARCHAR(180) NOT NULL,
            developer VARCHAR(160) NOT NULL,
            category VARCHAR(100) NOT NULL,
            short_description VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
            platform VARCHAR(180) NOT NULL DEFAULT 'Windows, Android, Web',
            price_type VARCHAR(30) NOT NULL DEFAULT 'Free',
            price_amount_vnd BIGINT NOT NULL DEFAULT 0,
            icon VARCHAR(500) NOT NULL,
            cover VARCHAR(500) NOT NULL,
            screenshots_json TEXT NOT NULL,
            download_url TEXT NOT NULL,
            downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
            rating DECIMAL(2,1) NOT NULL DEFAULT 0,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_products_category_status(category,status),
            INDEX idx_products_downloads(downloads)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            bucket_key CHAR(64) PRIMARY KEY,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS app_meta (
            meta_key VARCHAR(100) PRIMARY KEY,
            meta_value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        throw new RuntimeException('Unsupported database driver.');
    }
    migrate_legacy_demo_products($db);
}

final class NeonPostgresSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private PDO $db) {}
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false
    {
        $stmt = $this->db->prepare('SELECT session_data FROM app_sessions WHERE session_id = :id AND expires_at > :now');
        $stmt->execute([':id' => $id, ':now' => time()]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') return '';
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }
    public function write(string $id, string $data): bool
    {
        $lifetime = max(300, (int) ini_get('session.gc_maxlifetime'));
        $stmt = $this->db->prepare("INSERT INTO app_sessions(session_id,session_data,expires_at)
            VALUES(:id,:data,:expires)
            ON CONFLICT(session_id) DO UPDATE SET session_data = EXCLUDED.session_data, expires_at = EXCLUDED.expires_at");
        return $stmt->execute([':id' => $id, ':data' => base64_encode($data), ':expires' => time() + $lifetime]);
    }
    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM app_sessions WHERE session_id = :id');
        return $stmt->execute([':id' => $id]);
    }
    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->db->prepare('DELETE FROM app_sessions WHERE expires_at <= :now');
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }
    public function validateId(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM app_sessions WHERE session_id = :id AND expires_at > :now');
        $stmt->execute([':id' => $id, ':now' => time()]);
        return (bool) $stmt->fetchColumn();
    }
    public function updateTimestamp(string $id, string $data): bool
    {
        $lifetime = max(300, (int) ini_get('session.gc_maxlifetime'));
        $stmt = $this->db->prepare('UPDATE app_sessions SET expires_at = :expires WHERE session_id = :id');
        $stmt->execute([':id' => $id, ':expires' => time() + $lifetime]);
        if ($stmt->rowCount() > 0) return true;
        // If the row expired or disappeared between validateId and timestamp
        // renewal, recreate it with the current serialized session data.
        return $this->write($id, $data);
    }
}

function object_storage_provider(): string
{
    $provider = strtolower(trim((string) env_value('OBJECT_STORAGE_PROVIDER', 'neon')));
    if (!in_array($provider, ['neon', 'cloudfly', 'r2', 's3'], true)) {
        throw new RuntimeException('OBJECT_STORAGE_PROVIDER must be one of neon, cloudfly, r2 or s3.');
    }
    return $provider;
}

/**
 * Resolve one S3-compatible provider without exposing credentials to callers.
 * CloudFly's official endpoint/region/signature settings are documented at
 * https://cloudfly.vn/docs/object-storage/huong-dan-su-dung-api-de-ket-noi-object-storage
 */
function object_storage_connection_config(string $provider): array
{
    if ($provider === 'neon') {
        $endpoint = rtrim((string) env_value('AWS_ENDPOINT_URL_S3', ''), '/');
        $region = trim((string) env_value('AWS_REGION', ''));
        $bucket = trim((string) env_value('NEON_STORAGE_BUCKET', ''));
        $accessKey = trim((string) env_value('AWS_ACCESS_KEY_ID', ''));
        $secretKey = (string) env_value('AWS_SECRET_ACCESS_KEY', '');
        $sessionToken = '';
        $pathStyle = env_bool('NEON_STORAGE_FORCE_PATH_STYLE', true);
        $signature = 'v4';
    } else {
        $endpoint = rtrim((string) env_value('OBJECT_STORAGE_ENDPOINT', ''), '/');
        $region = trim((string) env_value('OBJECT_STORAGE_REGION', ''));
        $bucket = trim((string) env_value('OBJECT_STORAGE_BUCKET', ''));
        $accessKey = trim((string) env_value('OBJECT_STORAGE_ACCESS_KEY_ID', ''));
        $secretKey = (string) env_value('OBJECT_STORAGE_SECRET_ACCESS_KEY', '');
        $sessionToken = (string) env_value('OBJECT_STORAGE_SESSION_TOKEN', '');
        $pathStyle = env_bool('OBJECT_STORAGE_FORCE_PATH_STYLE', true);
        $signature = 'v4';

        if ($provider === 'cloudfly') {
            $endpoint = $endpoint !== '' ? $endpoint : 'https://s3.cloudfly.vn';
            $region = $region !== '' ? $region : 'hn';
        } elseif ($provider === 'r2') {
            $accountId = trim((string) env_value('CLOUDFLARE_ACCOUNT_ID', ''));
            $endpoint = $endpoint !== '' ? $endpoint : ($accountId !== '' ? 'https://' . $accountId . '.r2.cloudflarestorage.com' : '');
            $region = $region !== '' ? $region : 'auto';
        } elseif ($provider === 's3') {
            $region = $region !== '' ? $region : 'us-east-1';
        }
    }

    $parts = parse_url($endpoint);
    if ($endpoint === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || trim((string)($parts['path'] ?? ''), '/') !== '' || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Object Storage endpoint must be an HTTPS origin without credentials, path, query or fragment.');
    }
    $bucketPattern = $provider === 'neon'
        ? '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'
        : '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/';
    if ($bucket === '' || !preg_match($bucketPattern, $bucket)) {
        throw new RuntimeException('OBJECT_STORAGE_BUCKET is invalid for the selected provider.');
    }
    if ($accessKey === '' || $secretKey === '' || $region === '') {
        throw new RuntimeException(ucfirst($provider) . ' Object Storage credentials are not fully configured.');
    }

    return [
        'provider' => $provider,
        'endpoint' => $endpoint,
        'region' => $region,
        'bucket' => $bucket,
        'access_key' => $accessKey,
        'secret_key' => $secretKey,
        'session_token' => $sessionToken,
        'path_style' => $pathStyle,
        'signature_version' => $signature,
    ];
}

function object_storage_configured(string $provider): bool
{
    try {
        object_storage_connection_config($provider);
        return class_exists(\Aws\S3\S3Client::class);
    } catch (Throwable $error) {
        return false;
    }
}

function object_storage_s3_client(string $provider): \Aws\S3\S3Client
{
    static $clients = [];
    if (isset($clients[$provider]) && $clients[$provider] instanceof \Aws\S3\S3Client) return $clients[$provider];
    if (!object_storage_configured($provider)) {
        throw new RuntimeException(ucfirst($provider) . ' Object Storage is not configured. Check the provider endpoint, bucket and Render secrets.');
    }
    $config = object_storage_connection_config($provider);
    $credentials = ['key' => $config['access_key'], 'secret' => $config['secret_key']];
    if ($config['session_token'] !== '') $credentials['token'] = $config['session_token'];

    $clientOptions = [
        'version' => 'latest',
        'region' => $config['region'],
        'endpoint' => $config['endpoint'],
        'credentials' => $credentials,
        'use_path_style_endpoint' => $config['path_style'],
        'http' => ['connect_timeout' => 5, 'timeout' => 20],
    ];
    // CloudFly uses region `hn`; Cloudflare R2 uses `auto`; both use SigV4.
    $clientOptions['signature_version'] = (string) $config['signature_version'];
    $clients[$provider] = new \Aws\S3\S3Client($clientOptions);
    return $clients[$provider];
}

function object_storage_legacy_neon_fallback_enabled(): bool
{
    return object_storage_provider() !== 'neon'
        && env_bool('OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK', true)
        && object_storage_configured('neon');
}

function object_storage_is_missing(\Aws\Exception\AwsException $error): bool
{
    return $error->getStatusCode() === 404
        || in_array($error->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
}

function neon_object_storage_enabled(): bool
{
    return object_storage_configured(object_storage_provider());
}

function neon_s3_client(): \Aws\S3\S3Client
{
    return object_storage_s3_client(object_storage_provider());
}

function neon_storage_bucket(): string
{
    $config = object_storage_connection_config(object_storage_provider());
    return (string) $config['bucket'];
}

function object_storage_config_diagnostics(): array
{
    try {
        $provider = object_storage_provider();
    } catch (Throwable $error) {
        return ['provider' => 'invalid', 'missing' => [], 'sdk_available' => class_exists(\Aws\S3\S3Client::class)];
    }
    $keys = $provider === 'neon'
        ? ['AWS_ENDPOINT_URL_S3', 'AWS_REGION', 'NEON_STORAGE_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY']
        : ['OBJECT_STORAGE_ENDPOINT', 'OBJECT_STORAGE_REGION', 'OBJECT_STORAGE_BUCKET', 'OBJECT_STORAGE_ACCESS_KEY_ID', 'OBJECT_STORAGE_SECRET_ACCESS_KEY'];
    $missing = [];
    foreach ($keys as $key) {
        if (env_value($key) === null) $missing[] = $key;
    }
    return [
        'provider' => $provider,
        'missing' => $missing,
        'sdk_available' => class_exists(\Aws\S3\S3Client::class),
    ];
}

function neon_storage_probe(): bool
{
    $diagnostics = object_storage_config_diagnostics();
    try {
        neon_s3_client()->headBucket(['Bucket' => neon_storage_bucket()]);
        return true;
    } catch (Throwable $error) {
        $details = $error instanceof \Aws\Exception\AwsException
            ? ' code=' . (string) $error->getAwsErrorCode() . ' status=' . (string) $error->getStatusCode()
            : '';
        $missing = implode(',', $diagnostics['missing'] ?? []);
        error_log('[Hendy Store storage] probe_failed provider=' . (string)($diagnostics['provider'] ?? 'unknown')
            . ' missing_config=' . ($missing !== '' ? $missing : 'none')
            . ' sdk_available=' . (!empty($diagnostics['sdk_available']) ? 'yes' : 'no')
            . ' exception=' . get_class($error) . $details);
        return false;
    }
}

function neon_storage_put(string $key, string $filename, string $contentType): void
{
    if (!preg_match('#^(media|packages)/[a-f0-9]{32}\.[a-z0-9]{2,8}$#', $key)) {
        throw new RuntimeException('Invalid storage object key.');
    }
    neon_s3_client()->putObject([
        'Bucket' => neon_storage_bucket(),
        'Key' => $key,
        'SourceFile' => $filename,
        'ContentType' => $contentType,
        'Metadata' => ['uploaded-at' => gmdate('c')],
    ]);
}

function neon_storage_exists(string $key): bool
{
    if (!preg_match('#^(media|packages)/[a-f0-9]{32}\.[a-z0-9]{2,8}$#', $key)) return false;
    try {
        neon_s3_client()->headObject(['Bucket' => neon_storage_bucket(), 'Key' => $key]);
        return true;
    } catch (\Aws\Exception\AwsException $error) {
        if (!object_storage_is_missing($error)) throw $error;
        if (!object_storage_legacy_neon_fallback_enabled()) return false;
        try {
            object_storage_s3_client('neon')->headObject([
                'Bucket' => (string) env_value('NEON_STORAGE_BUCKET', ''),
                'Key' => $key,
            ]);
            return true;
        } catch (\Aws\Exception\AwsException $legacyError) {
            if (object_storage_is_missing($legacyError)) return false;
            throw $legacyError;
        }
    }
}

function neon_storage_get(string $key): \Aws\Result
{
    if (!preg_match('#^(media|packages)/[a-f0-9]{32}\.[a-z0-9]{2,8}$#', $key)) {
        throw new RuntimeException('Invalid storage object key.');
    }
    try {
        return neon_s3_client()->getObject(['Bucket' => neon_storage_bucket(), 'Key' => $key]);
    } catch (\Aws\Exception\AwsException $error) {
        if (!object_storage_is_missing($error) || !object_storage_legacy_neon_fallback_enabled()) throw $error;
        return object_storage_s3_client('neon')->getObject([
            'Bucket' => (string) env_value('NEON_STORAGE_BUCKET', ''),
            'Key' => $key,
        ]);
    }
}

function neon_auth_authorization_header(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strtolower((string)$name) === 'authorization') {
                $header = (string)$value;
                break;
            }
        }
    }
    return trim($header);
}

function neon_auth_bearer_token(): ?string
{
    $header = neon_auth_authorization_header();
    return preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', $header, $matches) === 1
        ? $matches[1]
        : null;
}

function neon_auth_jwks(bool $forceRefresh = false): ?array
{
    static $keys = null;
    static $loadedAt = 0;
    if (!$forceRefresh && is_array($keys) && (time() - $loadedAt) < 300) {
        return $keys;
    }

    $authBase = trim((string)env_value('NEON_AUTH_BASE_URL', ''));
    $jwksUrl = trim((string)env_value('NEON_AUTH_JWKS_URL', ''));
    $authParts = parse_url($authBase);
    $jwksParts = parse_url($jwksUrl);
    if (!is_array($authParts) || !is_array($jwksParts)
        || strtolower((string)($authParts['scheme'] ?? '')) !== 'https'
        || strtolower((string)($jwksParts['scheme'] ?? '')) !== 'https'
        || empty($authParts['host']) || empty($jwksParts['host'])
        || strtolower((string)$authParts['host']) !== strtolower((string)$jwksParts['host'])
        || isset($jwksParts['user']) || isset($jwksParts['pass'])
        || isset($jwksParts['query']) || isset($jwksParts['fragment'])) {
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($jwksUrl, false, $context);
    if (!is_string($body) || $body === '') return null;
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) return null;

    try {
        $parsed = \Firebase\JWT\JWK::parseKeySet($decoded);
        if (!is_array($parsed) || $parsed === []) return null;
        $keys = $parsed;
        $loadedAt = time();
        return $keys;
    } catch (Throwable $error) {
        return null;
    }
}

function neon_auth_claims(): ?object
{
    $token = neon_auth_bearer_token();
    if ($token === null || !class_exists(\Firebase\JWT\JWT::class)
        || !class_exists(\Firebase\JWT\JWK::class)) {
        return null;
    }

    // Neon Managed Auth issues EdDSA JWTs. Reject unsupported algorithms before
    // key lookup instead of accepting an algorithm selected by an untrusted header.
    $jwtParts = explode('.', $token);
    if (count($jwtParts) !== 3) return null;
    $headerSegment = strtr($jwtParts[0], '-_', '+/');
    $headerSegment .= str_repeat('=', (4 - strlen($headerSegment) % 4) % 4);
    $headerJson = base64_decode($headerSegment, true);
    $jwtHeader = is_string($headerJson) ? json_decode($headerJson, true) : null;
    if (!is_array($jwtHeader) || ($jwtHeader['alg'] ?? '') !== 'EdDSA') return null;

    $authBase = rtrim((string)env_value('NEON_AUTH_BASE_URL', ''), '/');
    $baseParts = parse_url($authBase);
    if (!is_array($baseParts) || strtolower((string)($baseParts['scheme'] ?? '')) !== 'https'
        || empty($baseParts['host'])) {
        return null;
    }

    // Managed Auth's issuer is the origin of its branch URL, not its /neondb/auth path.
    $issuerScheme = strtolower((string)$baseParts['scheme']);
    $issuerHost = strtolower((string)$baseParts['host']);
    $issuerPort = $baseParts['port'] ?? null;
    if (($issuerScheme === 'https' && $issuerPort === 443)
        || ($issuerScheme === 'http' && $issuerPort === 80)) {
        $issuerPort = null;
    }
    $expectedIssuer = $issuerScheme . '://' . $issuerHost
        . ($issuerPort !== null ? ':' . (int)$issuerPort : '');

    \Firebase\JWT\JWT::$leeway = 5;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $keySet = neon_auth_jwks($attempt === 1);
        if ($keySet === null) return null;
        try {
            $claims = \Firebase\JWT\JWT::decode($token, $keySet);
            if (!isset($claims->sub, $claims->exp, $claims->iss)
                || trim((string)$claims->sub) === ''
                || (int)$claims->exp <= time()
                || !hash_equals($expectedIssuer, (string)$claims->iss)) {
                return null;
            }
            if (isset($claims->iat) && (int)$claims->iat > time() + 60) return null;
            $expectedAudience = trim((string)env_value('NEON_AUTH_AUDIENCE', ''));
            if ($expectedAudience !== '') {
                $audiences = is_array($claims->aud ?? null)
                    ? $claims->aud
                    : [(string)($claims->aud ?? '')];
                if (!in_array($expectedAudience, array_map('strval', $audiences), true)) return null;
            }
            return $claims;
        } catch (Throwable $error) {
            if ($attempt === 1) return null;
            // Retry once with fresh JWKS to tolerate signing-key rotation.
        }
    }
    return null;
}

function neon_auth_user(PDO $db): ?array
{
    $claims = neon_auth_claims();
    if (!$claims) return null;

    $subject = trim((string)$claims->sub);
    $email = strtolower(trim((string)($claims->email ?? '')));
    $name = trim((string)($claims->name ?? $email));
    $emailVerified = $claims->email_verified ?? $claims->emailVerified ?? null;
    $verified = in_array($emailVerified, [true, 1, 'true'], true);

    if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    // Stable subject lookup always wins. A Neon identity may represent a customer only.
    $bySubject = $db->prepare('SELECT id,name,email,role,neon_auth_subject FROM users WHERE neon_auth_subject = :subject LIMIT 1');
    $bySubject->execute([':subject' => $subject]);
    $account = $bySubject->fetch();
    if ($account) {
        if (($account['role'] ?? '') !== 'customer') return null;
        return public_user($account);
    }

    // Email-based linking and first-time provisioning require an explicit verified claim.
    if (!$verified) return null;

    $byEmail = $db->prepare('SELECT id,name,email,role,neon_auth_subject FROM users WHERE LOWER(email) = :email LIMIT 1');
    $byEmail->execute([':email' => $email]);
    $account = $byEmail->fetch();
    if ($account) {
        $linkedSubject = trim((string)($account['neon_auth_subject'] ?? ''));
        if (($account['role'] ?? '') !== 'customer' || ($linkedSubject !== '' && !hash_equals($linkedSubject, $subject))) {
            return null;
        }
        if ($linkedSubject === '') {
            $link = $db->prepare('UPDATE users SET neon_auth_subject = :subject WHERE id = :id AND (neon_auth_subject IS NULL OR neon_auth_subject = :empty)');
            try {
                $link->execute([':subject' => $subject, ':id' => (int)$account['id'], ':empty' => '']);
            } catch (PDOException $error) {
                // A concurrent link may have won; re-read by subject below.
            }
        }
        $bySubject->execute([':subject' => $subject]);
        $linked = $bySubject->fetch();
        return $linked && ($linked['role'] ?? '') === 'customer' ? public_user($linked) : null;
    }

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $insertSql = $driver === 'pgsql'
        ? 'INSERT INTO users(name,email,password_hash,role,neon_auth_subject,created_at) VALUES(:name,:email,:hash,:role,:subject,:created) ON CONFLICT(email) DO NOTHING'
        : ($driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO users(name,email,password_hash,role,neon_auth_subject,created_at) VALUES(:name,:email,:hash,:role,:subject,:created)'
            : 'INSERT IGNORE INTO users(name,email,password_hash,role,neon_auth_subject,created_at) VALUES(:name,:email,:hash,:role,:subject,:created)');
    $insert = $db->prepare($insertSql);
    try {
        $insert->execute([
            ':name' => mb_substr_safe($name !== '' ? $name : $email, 80),
            ':email' => $email,
            ':hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            ':role' => 'customer',
            ':subject' => $subject,
            ':created' => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $error) {
        // Do not let a racing insert turn into an identity takeover.
    }

    $bySubject->execute([':subject' => $subject]);
    $created = $bySubject->fetch();
    return $created && ($created['role'] ?? '') === 'customer' ? public_user($created) : null;
}

function neon_ai_completion(string $systemPrompt, string $userPrompt, int $maxTokens = 700): string
{
    $base = rtrim((string) env_value('NEON_AI_GATEWAY_BASE_URL', ''), '/');
    $token = (string) env_value('NEON_AI_GATEWAY_TOKEN', '');
    if ($base === '' || $token === '' || !function_exists('curl_init')) {
        throw new RuntimeException('Neon AI Gateway is not configured.');
    }
    $url = $base . '/v1/chat/completions';
    $payload = json_encode([
        'model' => env_value('NEON_AI_MODEL', 'gpt-5-mini'),
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'max_completion_tokens' => max(100, min(1200, $maxTokens)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw) || $status < 200 || $status >= 300) {
        error_log('[Hendy Store AI Gateway] HTTP ' . $status . ($error !== '' ? ' transport_error' : ''));
        throw new RuntimeException('AI Gateway request failed. Check model availability and Neon AI Gateway credits.');
    }
    $result = json_decode($raw, true);
    $content = $result['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('AI Gateway returned no text.');
    }
    return trim($content);
}

function migrate_legacy_demo_products(PDO $db): void {
    $key='legacy-demo-cleanup-v1';
    $q=$db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=:key'); $q->execute([':key'=>$key]);
    if ($q->fetchColumn() !== false) return;
    $slugs=['hendy-toolkit','pixel-note','orbit-player','focus-flow','color-match'];
    $marks=implode(',',array_fill(0,count($slugs),'?'));
    $q=$db->prepare("UPDATE products SET downloads=0,rating=0 WHERE slug IN ($marks)"); $q->execute($slugs);
    $q=$db->prepare("UPDATE products SET status='draft',featured=0 WHERE slug IN ($marks) AND download_url=''"); $q->execute($slugs);
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql' || $driver === 'sqlite') $q=$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(:key,:value) ON CONFLICT(meta_key) DO NOTHING');
    else $q=$db->prepare('INSERT IGNORE INTO app_meta(meta_key,meta_value) VALUES(:key,:value)');
    $q->execute([':key'=>$key,':value'=>date('c')]);
}
function unicode_length(string $value): int {
    if (function_exists('mb_strlen')) return mb_strlen($value,'UTF-8');
    $n=preg_match_all('/./us',$value,$matches); return $n===false ? strlen($value) : $n;
}
function ensure_environment_admin(PDO $db): void {
    $email = strtolower(trim((string) env_value('ADMIN_EMAIL', '')));
    $password = (string) env_value('ADMIN_PASSWORD', '');

    // Both values are optional for local development, but must be supplied together.
    if ($email === '' && $password === '') return;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || unicode_length($password) < 12) {
        throw new RuntimeException('Set valid ADMIN_EMAIL and ADMIN_PASSWORD (12+ characters).');
    }
    // PASSWORD_DEFAULT may use bcrypt, which only accepts the first 72 bytes.
    if (strlen($password) > 72) {
        throw new RuntimeException('ADMIN_PASSWORD must not exceed 72 bytes.');
    }

    // Match case-insensitively so an old mixed-case account is not duplicated.
    $find = $db->prepare('SELECT id, role, password_hash FROM users WHERE LOWER(email) = :email LIMIT 1');
    $find->execute([':email' => $email]);
    $old = $find->fetch();

    if ($old) {
        if (($old['role'] ?? '') !== 'admin') {
            throw new RuntimeException('ADMIN_EMAIL belongs to a non-admin account. Choose a dedicated admin email.');
        }

        // Render's ADMIN_PASSWORD is the deployment bootstrap credential.
        // Sync it when changed; never log the password or its hash.
        if (!password_verify($password, (string) ($old['password_hash'] ?? ''))) {
            $update = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id AND role = :role');
            $update->execute([
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => (int) $old['id'],
                ':role' => 'admin',
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Unable to synchronize the configured administrator password.');
            }
        }
        return;
    }

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'pgsql'
        ? 'INSERT INTO users(name,email,password_hash,role,created_at) VALUES(:name,:email,:hash,:role,:created) ON CONFLICT(email) DO NOTHING'
        : ($driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO users(name,email,password_hash,role,created_at) VALUES(:name,:email,:hash,:role,:created)'
            : 'INSERT IGNORE INTO users(name,email,password_hash,role,created_at) VALUES(:name,:email,:hash,:role,:created)');
    $insert = $db->prepare($sql);
    $insert->execute([
        ':name' => 'Store Administrator',
        ':email' => $email,
        ':hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':created' => date('Y-m-d H:i:s'),
    ]);

    // A concurrent first boot may have inserted the same email first.
    $verify = $db->prepare('SELECT role, password_hash FROM users WHERE LOWER(email) = :email LIMIT 1');
    $verify->execute([':email' => $email]);
    $created = $verify->fetch();
    if (!$created || $created['role'] !== 'admin') {
        throw new RuntimeException('Administrator bootstrap did not create an admin account.');
    }
    if (!password_verify($password, (string) $created['password_hash'])) {
        $sync = $db->prepare('UPDATE users SET password_hash = :hash WHERE LOWER(email) = :email AND role = :role');
        $sync->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':email' => $email, ':role' => 'admin']);
    }
}
function public_user(array $user): array { return ['id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']]; }
function php_session_user(PDO $db): ?array
{
    // PHP sessions are reserved exclusively for the separately authenticated Admin console.
    if (($_SESSION['auth_mode'] ?? '') !== 'admin_password') return null;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) return null;
    $stmt = $db->prepare('SELECT id,name,email,role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['auth_mode']);
        return null;
    }
    return public_user($user);
}

function current_user(PDO $db): ?array
{
    $authorization = neon_auth_authorization_header();
    if (preg_match('/^Bearer\b/i', $authorization)) {
        // A malformed or invalid Bearer token must not fall back to another cookie identity.
        if (neon_auth_bearer_token() === null) return null;
        return neon_auth_user($db);
    }
    return php_session_user($db);
}

function require_admin(PDO $db): array
{
    // The Admin console intentionally uses a separate PHP email/password session.
    // A Neon Auth customer token or a matching Google email can never grant Admin.
    $user = php_session_user($db);
    if (!env_bool('ADMIN_SESSION_FALLBACK_ENABLED', true)
        || preg_match('/^Bearer\b/i', neon_auth_authorization_header())
        || !$user || ($user['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'error' => 'Bạn cần đăng nhập bằng tài khoản quản trị riêng.'], 403);
    }
    return $user;
}

function request_ip(): string {
    if (env_bool('TRUST_PROXY_HEADERS')) {
        $ip=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??''))[0]);
        if (filter_var($ip,FILTER_VALIDATE_IP)) return $ip;
    }
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown'); return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'unknown';
}
function rate_limit_or_fail(PDO $db,string $scope,int $maxHits,int $windowSeconds): void {
    $secret=(string)env_value('APP_KEY',hash('sha256',__FILE__));
    $key=hash_hmac('sha256',$scope.'|'.request_ip(),$secret); $now=time();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $insert = ($driver === 'sqlite' || $driver === 'pgsql')
        ? 'INSERT INTO rate_limits(bucket_key,hits,window_started_at) VALUES(:key,0,:now) ON CONFLICT(bucket_key) DO NOTHING'
        : 'INSERT IGNORE INTO rate_limits(bucket_key,hits,window_started_at) VALUES(:key,0,:now)';
    $q=$db->prepare($insert); $q->execute([':key'=>$key,':now'=>$now]);
    $q=$db->prepare('UPDATE rate_limits SET hits=CASE WHEN window_started_at < :cutoff1 THEN 1 ELSE hits+1 END,window_started_at=CASE WHEN window_started_at < :cutoff2 THEN :now ELSE window_started_at END WHERE bucket_key=:key');
    $q->execute([':cutoff1'=>$now-$windowSeconds,':cutoff2'=>$now-$windowSeconds,':now'=>$now,':key'=>$key]);
    $q=$db->prepare('SELECT hits FROM rate_limits WHERE bucket_key=:key'); $q->execute([':key'=>$key]); $hits=(int)$q->fetchColumn();
    if (random_int(1,100)===1) { $q=$db->prepare('DELETE FROM rate_limits WHERE window_started_at < :cutoff'); $q->execute([':cutoff'=>$now-86400]); }
    if ($hits>$maxHits) { header('Retry-After: '.$windowSeconds); json_response(['ok'=>false,'error'=>'Bạn thao tác quá nhanh. Vui lòng thử lại sau.'],429); }
}
function valid_media_token(string $token): bool { return (bool)preg_match('/^[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$/i',$token); }
function valid_package_token(string $token): bool { return (bool)preg_match('/^[a-f0-9]{32}\\.(?:apk|apks|xapk|zip|7z|rar|exe|msi|dmg|pkg|deb|rpm|tar|gz|pdf|appx|msix|appimage|ipa)$/i',$token); }
function mb_substr_safe(string $value,int $max): string { return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max); }
function validate_asset_reference(string $value): string {
    $value=trim($value); if ($value==='') return 'images/dummy.png';
    if (str_starts_with($value,'media:')) {
        $token=substr($value,6);
        if (valid_media_token($token) && neon_storage_exists('media/' . $token)) return 'media:'.$token;
        json_response(['ok'=>false,'error'=>'Không tìm thấy ảnh trong Neon Object Storage. Hãy tải lại ảnh.'],422);
    }
    if (preg_match('/^https:\/\//i',$value) && filter_var($value,FILTER_VALIDATE_URL)) {
        $p=parse_url($value); if (!empty($p['host']) && !isset($p['user']) && !isset($p['pass'])) return $value;
    }
    if (preg_match('#^images/[A-Za-z0-9._/-]+$#',$value) && !str_contains($value,'..') && is_file(dirname(__DIR__).'/'.$value)) return $value;
    json_response(['ok'=>false,'error'=>'Hình ảnh phải là tệp tải lên, ảnh trong images hoặc URL HTTPS.'],422);
}
function validate_product_input(array $input): array {
    $title=trim((string)($input['title']??'')); $slug=strtolower(trim((string)($input['slug']??'')));
    $slug=trim(preg_replace('/[^a-z0-9-]+/','-',$slug)??'','-');
    $developer=trim((string)($input['developer']??'')); $category=trim((string)($input['category']??''));
    $short=trim((string)($input['shortDescription']??'')); $description=trim((string)($input['description']??''));
    if ($title===''||$slug===''||$developer===''||$category===''||$short===''||$description==='') json_response(['ok'=>false,'error'=>'Vui lòng nhập đầy đủ thông tin ứng dụng.'],422);
    if (unicode_length($title)>180||strlen($slug)>180||unicode_length($developer)>160||unicode_length($category)>100||unicode_length($short)>255||unicode_length($description)>20000) json_response(['ok'=>false,'error'=>'Một hoặc nhiều trường vượt quá độ dài cho phép.'],422);
    $status=trim((string)($input['status']??'draft'));
    if (!in_array($status, ['draft','pending','published','rejected'], true)) $status='draft';
    $download=trim((string)($input['downloadUrl']??''));
    if ($download!=='' && !str_starts_with($download,'file:')) {
        $p=parse_url($download);
        if (!filter_var($download,FILTER_VALIDATE_URL)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])) json_response(['ok'=>false,'error'=>'Liên kết tải ngoài phải là URL HTTPS hợp lệ.'],422);
    } elseif ($download!=='' && !valid_package_token(substr($download,5))) json_response(['ok'=>false,'error'=>'Mã gói tải không hợp lệ.'],422);
    // Free-only catalog. Reject legacy Paid submissions.
    if (isset($input['priceType']) && !in_array((string)$input['priceType'], ['Free','Freemium'], true)) json_response(['ok'=>false,'error'=>'Hendy Store hiện chỉ hỗ trợ sản phẩm miễn phí.'],422);
    $price='Free';
    $priceAmount=0;
    if ($status==='published' && $download==='') json_response(['ok'=>false,'error'=>'Sản phẩm công khai cần URL HTTPS hoặc gói tải hợp lệ.'],422);
    if (str_starts_with($download,'file:') && !neon_storage_exists('packages/'.substr($download,5))) json_response(['ok'=>false,'error'=>'Không tìm thấy gói cài đặt trong Neon Object Storage. Hãy tải lại gói.'],422);
    $screens=$input['screenshots']??[]; if (!is_array($screens)) $screens=[];
    if (count($screens)>12) json_response(['ok'=>false,'error'=>'Tối đa 12 ảnh chụp màn hình.'],422);
    $safe=[]; foreach ($screens as $s) if (is_string($s)&&trim($s)!=='') $safe[]=validate_asset_reference($s);
    return [
        ':slug'=>$slug,':title'=>$title,':developer'=>$developer,':category'=>$category,':short_description'=>$short,
        ':description'=>$description,':version'=>mb_substr_safe(trim((string)($input['version']??'1.0.0'))?:'1.0.0',40),
        ':platform'=>mb_substr_safe(trim((string)($input['platform']??'Web'))?:'Web',180),':price_type'=>$price,':price_amount_vnd'=>$priceAmount,
        ':icon'=>validate_asset_reference((string)($input['icon']??'')),':cover'=>validate_asset_reference((string)($input['cover']??'')),
        ':screenshots_json'=>json_encode(array_values(array_unique($safe)),JSON_UNESCAPED_SLASHES),
        ':download_url'=>$download,':rating'=>min(5,max(0,(float)($input['rating']??0))),
        ':featured'=>!empty($input['featured'])?1:0,':status'=>$status
    ];
}
function present_product(array $p,bool $full=false): array {
    $url=(string)($p['download_url']??'');
    $out=['id'=>(int)$p['id'],'slug'=>$p['slug'],'title'=>$p['title'],'developer'=>$p['developer'],'category'=>$p['category'],
        'shortDescription'=>$p['short_description'],'version'=>$p['version'],'platform'=>$p['platform'],'priceType'=>(string)($p['price_type']??'Free'),'priceAmountVnd'=>(int)($p['price_amount_vnd']??0),
        'icon'=>$p['icon'],'cover'=>$p['cover'],'downloads'=>(int)$p['downloads'],'rating'=>(float)$p['rating'],'featured'=>(bool)$p['featured']];
    if ($full) { $out['description']=$p['description']; $out['screenshots']=json_decode((string)$p['screenshots_json'],true)?:[];
        $out['downloadUrlConfigured']=$url!==''; $out['canDownload']=$url!==''; $out['status']=$p['status']; }
    return $out;
}
enforce_https_request();
