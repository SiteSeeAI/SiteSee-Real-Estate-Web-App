<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private/real-estate-form-config.php';

header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok'=>false, 'message'=>'Method not allowed.']);
    exit;
}

if (SITESEE_TURNSTILE_SITE_KEY === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'message'=>'Secure form protection is temporarily unavailable.']);
    exit;
}

echo json_encode(['ok'=>true, 'sitekey'=>SITESEE_TURNSTILE_SITE_KEY], JSON_UNESCAPED_SLASHES);

