<?php
declare(strict_types=1);

// Lightweight process-level probe for Render. It intentionally avoids loading
// the database, object storage, sessions or application bootstrap so an
// optional dependency outage does not make the web process appear offline.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

http_response_code(200);
if ($method !== 'HEAD') {
    echo json_encode([
        'ok' => true,
        'status' => 'alive',
        'service' => 'hendy-store',
    ], JSON_UNESCAPED_SLASHES);
}
