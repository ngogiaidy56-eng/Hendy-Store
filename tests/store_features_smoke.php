<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../features/api.php';
require_once __DIR__ . '/../features/security.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "PDO SQLite is required for this test.\n");
    exit(1);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, password_hash TEXT, role TEXT, created_at TEXT)');
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, title TEXT)');
$db->exec('CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL)');
hendystore_feature_schema($db);
hendystore_feature_schema($db);
hendystore_security_schema($db);
hendystore_security_schema($db);

$expected = [
    'app_product_specs', 'app_product_screenshots', 'app_versions',
    'app_product_reviews', 'app_user_library', 'app_download_history',
    'app_user_notifications', 'app_download_grants',
    'app_developer_profiles', 'app_security_packages', 'app_release_security',
    'app_store_analytics', 'app_crash_reports', 'app_trust_audit',
];
$actual = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($expected as $table) {
    if (!in_array($table, $actual, true)) {
        throw new RuntimeException('Missing table: ' . $table);
    }
}
$columns = $db->query('PRAGMA table_info(app_user_library)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('last_platform', $columns, true)) {
    throw new RuntimeException('The user library must track the last platform.');
}
$packageColumns = $db->query('PRAGMA table_info(app_security_packages)')->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (['upload_method','moderation_status','sha256','scan_status'] as $column) {
    if (!in_array($column, $packageColumns, true)) {
        throw new RuntimeException('Package security records must store ' . $column . '.');
    }
}
$securityColumns = $db->query('PRAGMA table_info(app_release_security)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('package_size_bytes', $securityColumns, true)) {
    throw new RuntimeException('Release security records must store the verified package size.');
}
$versionColumns = $db->query('PRAGMA table_info(app_versions)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('release_track', $versionColumns, true)) {
    throw new RuntimeException('App versions must store Alpha/Beta/Production tracks.');
}
// CloudFly S3 settings must be resolvable without making a network request.
$storageTestKeys = [
    'OBJECT_STORAGE_PROVIDER', 'OBJECT_STORAGE_ENDPOINT', 'OBJECT_STORAGE_REGION',
    'OBJECT_STORAGE_BUCKET', 'OBJECT_STORAGE_ACCESS_KEY_ID', 'OBJECT_STORAGE_SECRET_ACCESS_KEY',
    'OBJECT_STORAGE_SESSION_TOKEN', 'OBJECT_STORAGE_FORCE_PATH_STYLE',
    'OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK', 'CLOUDFLARE_ACCOUNT_ID', 'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY', 'AWS_ENDPOINT_URL_S3', 'AWS_REGION', 'NEON_STORAGE_BUCKET',
    'NEON_STORAGE_FORCE_PATH_STYLE',
];
$storageTestOriginal = [];
foreach ($storageTestKeys as $key) {
    $storageTestOriginal[$key] = $_ENV[$key] ?? null;
}
$_ENV['OBJECT_STORAGE_PROVIDER'] = 'cloudfly';
$_ENV['OBJECT_STORAGE_ENDPOINT'] = 'https://s3.cloudfly.vn';
$_ENV['OBJECT_STORAGE_REGION'] = 'hn';
$_ENV['OBJECT_STORAGE_BUCKET'] = 'hendy-test-bucket';
$_ENV['OBJECT_STORAGE_ACCESS_KEY_ID'] = 'test-access-key';
$_ENV['OBJECT_STORAGE_SECRET_ACCESS_KEY'] = 'test-secret-key';
$_ENV['OBJECT_STORAGE_SESSION_TOKEN'] = '';
$_ENV['OBJECT_STORAGE_FORCE_PATH_STYLE'] = 'true';
$_ENV['OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK'] = 'true';
$_ENV['AWS_ACCESS_KEY_ID'] = 'legacy-test-access';
$_ENV['AWS_SECRET_ACCESS_KEY'] = 'legacy-test-secret';
$_ENV['AWS_ENDPOINT_URL_S3'] = 'https://neon-test.example';
$_ENV['AWS_REGION'] = 'us-east-2';
$_ENV['NEON_STORAGE_BUCKET'] = 'hendy-legacy';
$_ENV['NEON_STORAGE_FORCE_PATH_STYLE'] = 'true';
$cloudflyConfig = object_storage_connection_config('cloudfly');
if (object_storage_provider() !== 'cloudfly' || $cloudflyConfig['endpoint'] !== 'https://s3.cloudfly.vn'
    || $cloudflyConfig['region'] !== 'hn' || $cloudflyConfig['bucket'] !== 'hendy-test-bucket'
    || $cloudflyConfig['path_style'] !== true || !neon_object_storage_enabled()
    || neon_storage_bucket() !== 'hendy-test-bucket' || !object_storage_legacy_neon_fallback_enabled()) {
    throw new RuntimeException('CloudFly S3 configuration or legacy Neon fallback resolution failed.');
}
$cloudflyClient = neon_s3_client();
if (!($cloudflyClient instanceof \Aws\S3\S3Client)) {
    throw new RuntimeException('CloudFly provider must instantiate the AWS-compatible S3 SDK client.');
}
$_ENV['OBJECT_STORAGE_BUCKET'] = 'invalid.bucket.name';
try {
    object_storage_connection_config('cloudfly');
    throw new RuntimeException('CloudFly bucket names with dots must be rejected.');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'OBJECT_STORAGE_BUCKET')) throw $expected;
}
// Cloudflare R2 uses the account endpoint, region=auto and SigV4.
$_ENV['OBJECT_STORAGE_PROVIDER'] = 'r2';
$_ENV['CLOUDFLARE_ACCOUNT_ID'] = '0123456789abcdef0123456789abcdef';
$_ENV['OBJECT_STORAGE_ENDPOINT'] = '';
$_ENV['OBJECT_STORAGE_REGION'] = 'auto';
$_ENV['OBJECT_STORAGE_BUCKET'] = 'hendy-r2-test';
$_ENV['OBJECT_STORAGE_ACCESS_KEY_ID'] = 'r2-test-key';
$_ENV['OBJECT_STORAGE_SECRET_ACCESS_KEY'] = 'r2-test-secret';
$r2Config = object_storage_connection_config('r2');
if ($r2Config['endpoint'] !== 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com'
    || $r2Config['region'] !== 'auto' || $r2Config['bucket'] !== 'hendy-r2-test'
    || $r2Config['path_style'] !== true || $r2Config['signature_version'] !== 'v4') {
    throw new RuntimeException('Cloudflare R2 connection configuration is invalid.');
}
$r2Client = neon_s3_client();
if (!($r2Client instanceof \Aws\S3\S3Client)) {
    throw new RuntimeException('Cloudflare R2 must use the AWS-compatible S3 SDK client.');
}

// Generic S3 endpoint support uses the same private bucket adapter.
$_ENV['OBJECT_STORAGE_PROVIDER'] = 's3';
$_ENV['OBJECT_STORAGE_ENDPOINT'] = 'https://s3.example.test';
$_ENV['OBJECT_STORAGE_REGION'] = 'us-west-2';
$_ENV['OBJECT_STORAGE_BUCKET'] = 'hendy-s3-test';
$s3Config = object_storage_connection_config('s3');
if ($s3Config['endpoint'] !== 'https://s3.example.test' || $s3Config['region'] !== 'us-west-2') {
    throw new RuntimeException('Generic S3 provider configuration is invalid.');
}

foreach ($storageTestOriginal as $key => $value) {
    if ($value === null) unset($_ENV[$key]); else $_ENV[$key] = $value;
}

if (hendystore_security_stats_status(['failure' => 12]) !== 'pending') {
    throw new RuntimeException('VirusTotal engine failures must never be treated as a clean scan.');
}
if (hendystore_security_stats_status(['timeout' => 4, 'harmless' => 20]) !== 'pending') {
    throw new RuntimeException('VirusTotal timeouts must keep a package quarantined.');
}
if (hendystore_security_stats_status(['harmless' => 18, 'undetected' => 7]) !== 'clean') {
    throw new RuntimeException('A completed scan with harmless/undetected results should be clean.');
}
if (hendystore_security_stats_status(['malicious' => 1, 'undetected' => 30]) !== 'malicious') {
    throw new RuntimeException('Any malware detection must reject the package.');
}
if (hendystore_security_stats_status([]) !== 'pending') {
    throw new RuntimeException('An empty VirusTotal result must remain pending.');
}
if (!valid_package_token(str_repeat('a', 32) . '.ipa')) {
    throw new RuntimeException('Signed iOS IPA packages should be accepted for private review.');
}
if (!hendystore_feature_safe_package_url('https://downloads.example.org/app.apk')) {
    throw new RuntimeException('HTTPS package URL should be accepted.');
}
if (hendystore_feature_safe_package_url('http://downloads.example.org/app.apk')) {
    throw new RuntimeException('Non-HTTPS package URL must be rejected.');
}
echo "Store Ecosystem schema smoke test passed: additive tables, repeatable schema setup and HTTPS package validation.\n";
