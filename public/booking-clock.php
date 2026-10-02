<?php
declare(strict_types=1);
// Public time only. No application bootstrap, credentials, database or providers.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
echo json_encode(['now'=>microtime(true), 'timezone'=>'America/Chicago'], JSON_THROW_ON_ERROR);
