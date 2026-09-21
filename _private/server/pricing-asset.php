<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/real-estate-form-config.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

if (!real_estate_pricing_has_access()) {
    http_response_code(404);
    exit;
}

$assets = [
    'quote-engine' => 'pricing-assets/quote-engine.js',
    'residential-form' => 'pricing-assets/pricing.js',
    'commercial-quote-engine' => 'pricing-assets/commercial-quote-engine.js',
    'commercial-form' => 'pricing-assets/commercial-pricing.js',
    'market-selector' => 'pricing-assets/pricing-market.js',
];
$key = (string)($_GET['asset'] ?? '');
if (!isset($assets[$key])) {
    http_response_code(404);
    exit;
}
$file = dirname(__DIR__) . '/' . $assets[$key];
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/javascript; charset=UTF-8');
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline');
readfile($file);
exit;
