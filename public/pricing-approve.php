<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private/real-estate-form-config.php';
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function pricing_approval_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pricing_approval_page(string $title, string $heading, string $body, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer"><title>' . pricing_approval_escape($title) . ' | SiteSee Real Estate</title><link rel="stylesheet" href="assets/css/site.css"></head><body><main style="min-height:100vh;background:#01111e;padding:5vw;display:grid;place-items:center"><section style="width:min(820px,90vw);box-sizing:border-box;background:#fff;padding:48px;border-top:8px solid #ffc107"><img src="assets/images/sitesee-logo.png" alt="SiteSee" style="width:190px"><h1 style="font-size:clamp(2.2rem,5vw,3.8rem);margin:30px 0 18px">' . pricing_approval_escape($heading) . '</h1>' . $body . '</section></main></body></html>';
    exit;
}

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$payload = real_estate_pricing_validate_approval_token($token);
if (!$payload) {
    pricing_approval_page('Approval Link Invalid', 'This approval link is invalid or has expired.', '<p style="font-size:19px;line-height:1.65">Ask the prospect to submit a new pricing-access request.</p>', 400);
}

$lead = real_estate_pricing_load_lead((string)$payload['lead_id']);
if (!$lead) {
    pricing_approval_page('Pricing Request Not Found', 'The pending pricing request could not be found.', '<p style="font-size:19px;line-height:1.65">The record may have been removed or already processed.</p>', 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (($lead['status'] ?? '') === 'approved') {
        pricing_approval_page('Already Approved', 'Pricing access was already approved.', '<p style="font-size:19px;line-height:1.65">The secure pricing-access email was previously sent to <strong>' . pricing_approval_escape((string)$lead['email']) . '</strong>.</p>');
    }

    $accessToken = real_estate_pricing_create_access_token((string)$lead['email']);
    $pricingLink = SITESEE_REAL_ESTATE_SITE_URL . '/pricing-confirm.php?token=' . rawurlencode($accessToken);
    $safePricingLink = pricing_approval_escape($pricingLink);
    $safeFirst = pricing_approval_escape((string)$lead['first_name']);
    $userHtml = '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:640px;margin:auto"><div style="background:#01111e;padding:24px"><strong style="color:#fff;font-size:24px">SiteSee Real Estate</strong></div><div style="padding:34px;border:1px solid #d9dadb;border-top:0"><h1 style="font-size:30px">Your SiteSee Real Estate pricing access is ready.</h1><p style="font-size:17px;line-height:1.6">Hello ' . $safeFirst . ',</p><p style="font-size:17px;line-height:1.6">Your request has been reviewed and approved. Use the secure link below to open the current residential and commercial pricing tools.</p><p style="margin:28px 0"><a href="' . $safePricingLink . '" style="display:inline-block;background:#ffc107;color:#01111e;padding:15px 22px;text-decoration:none;font-weight:bold">View SiteSee Real Estate Pricing</a></p><p style="font-size:15px;line-height:1.6;color:#596267">This access link expires in ' . SITESEE_REAL_ESTATE_PRICING_ACCESS_HOURS . ' hours. The verified browser session lasts up to ' . SITESEE_REAL_ESTATE_PRICING_SESSION_HOURS . ' hours.</p><p style="font-size:15px;line-height:1.6">Questions: <a href="mailto:sales@sitesee.ai">sales@sitesee.ai</a></p></div></div>';
    $userPlain = "Hello {$lead['first_name']},\n\nYour SiteSee Real Estate pricing request has been reviewed and approved. Open current residential and commercial pricing here:\n{$pricingLink}\n\nThis access link expires in " . SITESEE_REAL_ESTATE_PRICING_ACCESS_HOURS . " hours.\nQuestions: sales@sitesee.ai";

    if (!real_estate_send_mail((string)$lead['email'], SITESEE_REAL_ESTATE_SALES_EMAIL, 'Your SiteSee Real Estate Pricing Access — Expires in 36 Hours', $userHtml, $userPlain)) {
        pricing_approval_page('Pricing Email Not Sent', 'The request was approved, but the pricing email could not be sent.', '<p style="font-size:19px;line-height:1.65">No access status was changed. Check the mail configuration and try again.</p>', 503);
    }

    $lead['status'] = 'approved';
    $lead['approved_at'] = gmdate('c');
    real_estate_pricing_save_lead((string)$payload['lead_id'], $lead);
    real_estate_pricing_log(SITESEE_REAL_ESTATE_PRICING_LEAD_LOG, [
        'event' => 'pricing_access_approved',
        'lead_id' => $lead['lead_id'],
        'email' => $lead['email'],
        'approved_at' => $lead['approved_at'],
    ]);

    $salesHtml = '<div style="font-family:Arial,sans-serif"><h1>Real Estate pricing access approved and sent</h1><p><strong>Name:</strong> ' . pricing_approval_escape(trim((string)$lead['first_name'] . ' ' . (string)$lead['last_name'])) . '</p><p><strong>Email:</strong> ' . pricing_approval_escape((string)$lead['email']) . '</p><p>The secure pricing-access email has been sent.</p></div>';
    $salesPlain = "REAL ESTATE PRICING ACCESS APPROVED AND SENT\nName: {$lead['first_name']} {$lead['last_name']}\nEmail: {$lead['email']}\n";
    real_estate_send_mail(SITESEE_REAL_ESTATE_SALES_EMAIL, (string)$lead['email'], 'Real Estate pricing access approved: ' . (string)$lead['email'], $salesHtml, $salesPlain);

    pricing_approval_page('Pricing Access Sent', 'Pricing access approved and sent.', '<p style="font-size:19px;line-height:1.65">A secure, time-limited pricing link has been emailed to <strong>' . pricing_approval_escape((string)$lead['email']) . '</strong>.</p>');
}

$status = pricing_approval_escape((string)($lead['status'] ?? 'pending_review'));
$body = '<p style="font-size:19px;line-height:1.65">Review the prospect below. <strong>Opening this page has not released pricing.</strong> Pricing is sent only after you press the approval button.</p><table cellpadding="10" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:18px;margin:28px 0"><tr><th align="left">Name</th><td>' . pricing_approval_escape(trim((string)$lead['first_name'] . ' ' . (string)$lead['last_name'])) . '</td></tr><tr><th align="left">Email</th><td>' . pricing_approval_escape((string)$lead['email']) . '</td></tr><tr><th align="left">Telephone</th><td>' . pricing_approval_escape((string)$lead['phone']) . '</td></tr><tr><th align="left">Website</th><td><a target="_blank" rel="noopener noreferrer" href="' . pricing_approval_escape((string)$lead['website']) . '">' . pricing_approval_escape((string)$lead['website']) . '</a></td></tr><tr><th align="left">Source</th><td>' . pricing_approval_escape((string)$lead['source_page']) . '</td></tr><tr><th align="left">Status</th><td>' . $status . '</td></tr></table><form method="post"><input type="hidden" name="token" value="' . pricing_approval_escape($token) . '"><button type="submit" style="background:#ffc107;color:#01111e;border:0;padding:16px 24px;font-size:18px;font-weight:800;cursor:pointer">Approve &amp; Send Pricing Access</button></form><p style="font-size:14px;color:#596267;margin-top:24px">If the request is not legitimate, do not approve it. No pricing link will be sent.</p>';
pricing_approval_page('Review Pricing Request', 'Review this pricing request.', $body);
