<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private/real-estate-form-config.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function pricing_access_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $ok = !empty($payload['ok']);
    $title = $ok ? 'Pricing Request Received' : 'Pricing Access Error';
    $message = htmlspecialchars((string)($payload['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>' . $title . ' | SiteSee Real Estate</title><link rel="stylesheet" href="assets/css/site.css"></head><body><main style="min-height:100vh;display:grid;place-items:center;background:#01111e;padding:5vw"><section style="width:min(760px,90vw);box-sizing:border-box;background:#fff;padding:48px;border-top:8px solid #ffc107"><img src="assets/images/sitesee-logo.png" alt="SiteSee" style="width:190px"><h1 style="font-size:clamp(2.2rem,5vw,3.8rem);margin-top:32px">' . ($ok ? 'Request Received.' : 'We Could Not Process The Request.') . '</h1><p style="font-size:19px;line-height:1.65;margin-top:20px">' . $message . '</p><p style="margin-top:28px"><a href="pricing-request.html#pricing-access" style="font-weight:700">Return To Pricing Access</a></p></section></main></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    pricing_access_respond(['ok'=>false,'message'=>'Submit the pricing-access form to continue.'], 405);
}
if (!real_estate_same_origin()) {
    pricing_access_respond(['ok'=>false,'message'=>'The request did not originate from the SiteSee Real Estate website.'], 403);
}
if (!empty($_POST['company_fax'] ?? '')) {
    pricing_access_respond(['ok'=>true,'message'=>'Thank you. Your request has been received for review.']);
}

$first = trim((string)($_POST['first_name'] ?? ''));
$last = trim((string)($_POST['last_name'] ?? ''));
$website = trim((string)($_POST['website'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$phone = trim((string)($_POST['phone'] ?? ''));
$source = trim((string)($_POST['source_page'] ?? 'real-estate-pricing-access'));

$errors = [];
if ($first === '' || strlen($first) > 80) $errors[] = 'Enter your first name.';
if ($last === '' || strlen($last) > 80) $errors[] = 'Enter your last name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) $errors[] = 'Enter a valid business email address.';
if ($phone === '' || strlen($phone) > 50) $errors[] = 'Enter your telephone number.';
if (!preg_match('~^https?://~i', $website)) $website = 'https://' . $website;
if (!filter_var($website, FILTER_VALIDATE_URL) || strlen($website) > 255) $errors[] = 'Enter a valid company or brokerage website.';
if (strlen($source) > 80) $errors[] = 'Refresh the page and try again.';
if ($errors) pricing_access_respond(['ok'=>false,'message'=>implode(' ', $errors)], 422);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!real_estate_rate_allowed($ip, $email)) {
    pricing_access_respond(['ok'=>false,'message'=>'Too many access requests were received. Please wait and try again, or email sales@sitesee.ai.'], 429);
}

$leadId = bin2hex(random_bytes(16));
$lead = [
    'lead_id' => $leadId,
    'status' => 'pending_review',
    'created_at' => gmdate('c'),
    'first_name' => $first,
    'last_name' => $last,
    'email' => $email,
    'phone' => $phone,
    'website' => $website,
    'source_page' => $source,
    'ip_hash' => hash('sha256', $ip),
];
if (!real_estate_pricing_save_lead($leadId, $lead)) {
    pricing_access_respond(['ok'=>false,'message'=>'We could not save the request. Please email sales@sitesee.ai.'], 500);
}
real_estate_pricing_log(SITESEE_REAL_ESTATE_PRICING_LEAD_LOG, ['event'=>'pricing_access_requested'] + $lead);

$safeFirst = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
$acknowledgementHtml = '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:640px;margin:auto"><div style="background:#01111e;padding:24px"><strong style="color:#fff;font-size:24px">SiteSee Real Estate</strong></div><div style="padding:34px;border:1px solid #d9dadb;border-top:0"><h1 style="font-size:30px">We received your pricing-access request.</h1><p style="font-size:17px;line-height:1.6">Hello ' . $safeFirst . ',</p><p style="font-size:17px;line-height:1.6">Our team will review the business information you provided. No pricing has been released yet.</p><p style="font-size:17px;line-height:1.6">If your request is approved, we will email you a secure, time-limited link to the residential and commercial pricing tools.</p><p style="font-size:15px;line-height:1.6">Questions: <a href="mailto:sales@sitesee.ai">sales@sitesee.ai</a></p></div></div>';
$acknowledgementPlain = "Hello {$first},\n\nWe received your SiteSee Real Estate pricing-access request. Our team will review the business information you provided. No pricing has been released yet. If approved, we will email you a secure, time-limited pricing link.\n\nQuestions: sales@sitesee.ai";

$approvalToken = real_estate_pricing_create_approval_token($leadId);
$approvalLink = SITESEE_REAL_ESTATE_SITE_URL . '/pricing-approve.php?token=' . rawurlencode($approvalToken);
$safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$fullName = trim($first . ' ' . $last);
$salesHtml = '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:700px"><h1>Real Estate Pricing Access Request — Manual Review Required</h1><table cellpadding="8" cellspacing="0" style="border-collapse:collapse"><tr><th align="left">Name</th><td>' . $safe($fullName) . '</td></tr><tr><th align="left">Email</th><td>' . $safe($email) . '</td></tr><tr><th align="left">Phone</th><td>' . $safe($phone) . '</td></tr><tr><th align="left">Website</th><td><a href="' . $safe($website) . '">' . $safe($website) . '</a></td></tr><tr><th align="left">Source</th><td>' . $safe($source) . '</td></tr></table><p><strong>No pricing link has been sent.</strong></p><p>Verify the person and organization. If legitimate, open the review page and explicitly approve release of the pricing link.</p><p style="margin:28px 0"><a href="' . $safe($approvalLink) . '" style="display:inline-block;background:#ffc107;color:#01111e;padding:15px 22px;text-decoration:none;font-weight:bold">Review &amp; Approve Pricing Access</a></p><p style="font-size:14px;color:#596267">This internal approval link expires in ' . SITESEE_REAL_ESTATE_PRICING_APPROVAL_HOURS . ' hours. Opening it does not release pricing; the review page requires a second approval action.</p></div>';
$salesPlain = "REAL ESTATE PRICING ACCESS REQUEST — MANUAL REVIEW REQUIRED\nName: {$fullName}\nEmail: {$email}\nPhone: {$phone}\nWebsite: {$website}\nSource: {$source}\n\nNo pricing link has been sent. Verify the prospect, then review and approve here:\n{$approvalLink}\n";

$userSent = real_estate_send_mail($email, SITESEE_REAL_ESTATE_SALES_EMAIL, 'We received your SiteSee Real Estate pricing request', $acknowledgementHtml, $acknowledgementPlain);
$salesSent = real_estate_send_mail(SITESEE_REAL_ESTATE_SALES_EMAIL, $email, 'Review Real Estate pricing request: ' . $fullName, $salesHtml, $salesPlain);
if (!$salesSent) {
    pricing_access_respond(['ok'=>false,'message'=>'Your request was saved, but our sales notification could not be delivered. Please email sales@sitesee.ai.'], 503);
}
if (!$userSent) {
    real_estate_pricing_log(SITESEE_REAL_ESTATE_PRICING_MAIL_LOG, ['event'=>'prospect_acknowledgement_failed','lead_id'=>$leadId,'email'=>$email]);
}
pricing_access_respond(['ok'=>true,'message'=>'Your request was received. SiteSee will review it and email a secure pricing link if access is approved.']);
