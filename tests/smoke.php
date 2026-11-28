<?php
declare(strict_types=1);
$tempRoot=sys_get_temp_dir().'/hendy-store-test-'.bin2hex(random_bytes(6));
if (!mkdir($tempRoot,0700,true)) throw new RuntimeException('Unable to create test directory.');
putenv('APP_ENV=development'); putenv('STORAGE_PATH='.$tempRoot.'/storage'); putenv('SQLITE_PATH='.$tempRoot.'/store.sqlite');
putenv('ADMIN_EMAIL=admin@example.test'); putenv('ADMIN_PASSWORD=Hendy-Store12!');
require_once dirname(__DIR__).'/config/bootstrap.php';

// Domain auto-detection: explicit APP_URL overrides, while auto mode follows
// a validated host supplied by the trusted reverse proxy.
$domainEnvKeys = ['APP_ENV','APP_URL','APP_AUTO_DETECT_DOMAINS','APP_ALLOWED_HOSTS','TRUST_PROXY_HEADERS','RENDER_EXTERNAL_URL'];
$domainEnvBefore = [];
foreach ($domainEnvKeys as $key) {
    $domainEnvBefore[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null, getenv($key)];
}
$serverBefore = $_SERVER;
$testDomainEnv = [
    'APP_ENV' => 'production',
    'APP_URL' => 'auto',
    'APP_AUTO_DETECT_DOMAINS' => 'true',
    'APP_ALLOWED_HOSTS' => 'store.example.test,hendystore.onrender.com,hendy-store.onrender.com',
    'TRUST_PROXY_HEADERS' => 'true',
    'RENDER_EXTERNAL_URL' => 'https://hendy-store.onrender.com',
];
foreach ($testDomainEnv as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'store.example.test';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_HOST'] = 'hendy-store.onrender.com';
if (app_base_url() !== 'https://store.example.test' || !request_is_https()) {
    throw new RuntimeException('Trusted proxy domain auto-detection failed.');
}

$_ENV['APP_ALLOWED_HOSTS'] = '*.hendystore.test';
putenv('APP_ALLOWED_HOSTS=*.hendystore.test');
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'cdn.hendystore.test';
if (app_base_url() !== 'https://cdn.hendystore.test') {
    throw new RuntimeException('Wildcard APP_ALLOWED_HOSTS entry was not accepted.');
}

$_SERVER['HTTP_X_FORWARDED_HOST'] = 'untrusted.example.test';
if (app_base_url() !== 'https://hendy-store.onrender.com') {
    throw new RuntimeException('A host outside APP_ALLOWED_HOSTS must use the trusted Render fallback.');
}

$_ENV['APP_ALLOWED_HOSTS'] = '';
putenv('APP_ALLOWED_HOSTS=');
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'bad host/path';
if (app_base_url() !== 'https://hendy-store.onrender.com') {
    throw new RuntimeException('Malformed forwarded host must be rejected.');
}

$_ENV['APP_URL'] = 'https://canonical.example.test';
putenv('APP_URL=https://canonical.example.test');
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'another.example.test';
if (app_base_url() !== 'https://canonical.example.test') {
    throw new RuntimeException('An explicit APP_URL must remain the canonical override.');
}

foreach ($domainEnvBefore as $key => [$wasSet, $oldValue, $processValue]) {
    if ($wasSet) $_ENV[$key] = $oldValue;
    else unset($_ENV[$key]);
    if ($processValue === false) putenv($key);
    else putenv($key . '=' . $processValue);
}
$_SERVER = $serverBefore;

$db=store_db();
[$mysqlDsn, $mysqlUser, $mysqlPassword] = database_url_to_pdo('mysql://hendy_user:secret%40value@mysql.example.test:3306/hendy_store');
if ($mysqlDsn !== 'mysql:host=mysql.example.test;port=3306;dbname=hendy_store;charset=utf8mb4'
    || $mysqlUser !== 'hendy_user' || $mysqlPassword !== 'secret@value') {
    throw new RuntimeException('MySQL DATABASE_URL parsing must preserve URL-encoded credentials.');
}
[$postgresDsn, $postgresUser, $postgresPassword] = database_url_to_pdo('postgresql://neondb_owner:encoded%40password@ep-test-pooler.example.test:5432/Hendy_Store?sslmode=require&channel_binding=require');
if (!str_contains($postgresDsn, 'sslmode=require') || !str_contains($postgresDsn, 'channel_binding=require')
    || $postgresUser !== 'neondb_owner' || $postgresPassword !== 'encoded@password') {
    throw new RuntimeException('PostgreSQL DATABASE_URL parsing must enforce TLS and retain channel binding.');
}
if (!is_subclass_of(NeonPostgresSessionHandler::class, SessionUpdateTimestampHandlerInterface::class)) {
    throw new RuntimeException('Neon session handler must implement PHP strict session ID validation and timestamp renewal.');
}
$sessionTestDb = new PDO('sqlite::memory:');
$sessionTestDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sessionTestDb->exec('CREATE TABLE app_sessions(session_id TEXT PRIMARY KEY,session_data TEXT NOT NULL,expires_at INTEGER NOT NULL)');
$sessionHandler = new NeonPostgresSessionHandler($sessionTestDb);
$sessionPayload = 'csrf_token|s:32:"session-test-token";';
if (!$sessionHandler->write('session-test-id', $sessionPayload)
    || !$sessionHandler->validateId('session-test-id')
    || $sessionHandler->read('session-test-id') !== $sessionPayload
    || !$sessionHandler->updateTimestamp('session-test-id', $sessionPayload)
    || !$sessionHandler->validateId('session-test-id')) {
    throw new RuntimeException('Neon Postgres session read/write/strict-validation/timestamp renewal failed.');
}
if (!$sessionHandler->destroy('session-test-id') || $sessionHandler->validateId('session-test-id')) {
    throw new RuntimeException('Neon session destruction must invalidate the previous session ID.');
}
$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users','products','rate_limits','app_meta'] as $table) if (!in_array($table,$tables,true)) throw new RuntimeException('Missing table: '.$table);
$q=$db->prepare("SELECT password_hash FROM users WHERE email=:email AND role='admin'"); $q->execute([':email'=>'admin@example.test']);
$hash=(string)$q->fetchColumn();
if ($hash===''||!password_verify('Hendy-Store12!',$hash)) throw new RuntimeException('Environment administrator bootstrap failed.');

// A changed Render secret must update an already-created admin account.
$rotatedPassword = 'Hendy-Store12!Rotated';
putenv('ADMIN_PASSWORD='.$rotatedPassword);
$_ENV['ADMIN_PASSWORD'] = $rotatedPassword;
ensure_environment_admin($db);
$q=$db->prepare("SELECT password_hash FROM users WHERE lower(email)=:email AND role='admin'");
$q->execute([':email'=>'admin@example.test']);
$rotatedHash=(string)$q->fetchColumn();
if ($rotatedHash==='' || !password_verify($rotatedPassword,$rotatedHash)) {
    throw new RuntimeException('Existing administrator password synchronization failed.');
}
$adminCount=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if ($adminCount !== 1) throw new RuntimeException('Administrator bootstrap created a duplicate admin account.');
$storage=storage_root(); if (!is_dir($storage)||!is_writable($storage)) throw new RuntimeException('Storage is not writable.');
if (unicode_length('Hendy-Store12!')<12) throw new RuntimeException('Password length validation failed.');
echo "Smoke test passed: schema, admin bootstrap, password hash and storage.\n";
