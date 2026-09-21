<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private/real-estate-form-config.php';
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$token = (string)($_GET['token'] ?? '');
$payload = real_estate_pricing_validate_access_token($token);
if (!$payload) {
    http_response_code(400);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Pricing Link Expired | SiteSee Real Estate</title><link rel="stylesheet" href="assets/css/site.css"></head><body><main style="min-height:100vh;display:grid;place-items:center;background:#01111e;padding:5vw"><section style="width:min(760px,90vw);box-sizing:border-box;background:#fff;padding:48px;border-top:8px solid #ffc107"><img src="assets/images/sitesee-logo.png" alt="SiteSee" style="width:190px"><h1 style="margin-top:32px">This pricing-access link is invalid or has expired.</h1><p style="margin-top:20px">Request a new link or contact <a href="mailto:sales@sitesee.ai">sales@sitesee.ai</a>.</p><p style="margin-top:28px"><a class="button primary" href="pricing.html#pricing-access">Request New Access</a></p></section></main></body></html><?php
    exit;
}

real_estate_pricing_start_session();
session_regenerate_id(true);
$_SESSION['real_estate_pricing_email'] = $payload['email'];
$_SESSION['real_estate_pricing_expires'] = time() + SITESEE_REAL_ESTATE_PRICING_SESSION_HOURS * 3600;
real_estate_pricing_log(SITESEE_REAL_ESTATE_PRICING_LEAD_LOG, [
    'event' => 'pricing_access_link_opened',
    'email' => $payload['email'],
]);

$plain = "Real Estate pricing access link opened\nEmail: {$payload['email']}\nTime: " . gmdate('c');
$html = '<div style="font-family:Arial,sans-serif"><h1>Real Estate pricing access link opened</h1><p><strong>Email:</strong> ' . htmlspecialchars((string)$payload['email'], ENT_QUOTES, 'UTF-8') . '</p><p>The approved contact opened the secure pricing link and was granted access to the current pricing tools.</p></div>';
real_estate_send_mail(SITESEE_REAL_ESTATE_SALES_EMAIL, (string)$payload['email'], 'SiteSee Real Estate pricing access opened: ' . (string)$payload['email'], $html, $plain);

header('Location: pricing.php');
exit;
