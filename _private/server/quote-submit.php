<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/real-estate-form-config.php';
require_once dirname(__DIR__) . '/real-estate-pricing.php';
require_once __DIR__ . '/booking-store.php';
require_once __DIR__ . '/quote-receipts.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json; charset=utf-8');

function real_estate_respond(array $payload, int $status = 200, ?string $receiptKey = null): never
{
    if ($receiptKey !== null && $status === 200) real_estate_quote_save_receipt($receiptKey, $payload);
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    real_estate_respond(['ok'=>false,'message'=>'Submit the pricing form to continue.'], 405);
}
if (!real_estate_same_origin()) {
    real_estate_respond(['ok'=>false,'message'=>'The request did not originate from the SiteSee Real Estate website.'], 403);
}
if (!real_estate_pricing_has_access()) {
    real_estate_respond(['ok'=>false,'message'=>'Your verified pricing session has expired. Request pricing access again to continue.'], 403);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
    real_estate_respond(['ok'=>false,'message'=>'Refresh the pricing page and try again.'], 415);
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 65536) {
    real_estate_respond(['ok'=>false,'message'=>'The quote request is too large. Refresh the page and try again.'], 413);
}
$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
    real_estate_respond(['ok'=>false,'message'=>'The quote request could not be read. Refresh the page and try again.'], 400);
}
try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    real_estate_respond(['ok'=>false,'message'=>'The quote request was not valid. Refresh the page and try again.'], 400);
}
if (!is_array($payload)) {
    real_estate_respond(['ok'=>false,'message'=>'The quote request was not valid. Refresh the page and try again.'], 400);
}
if (real_estate_clean((string)($payload['companyFax'] ?? '')) !== '') {
    real_estate_respond(['ok'=>true,'message'=>'Your request was received.']);
}

try {
    $submission = real_estate_prepare_submission($payload);
} catch (InvalidArgumentException $error) {
    real_estate_respond(['ok'=>false,'message'=>$error->getMessage()], 422);
}

// The pricing session lock serializes repeated requests from this browser session.
$receiptKey = real_estate_quote_receipt_key($submission);
$cachedReceipt = real_estate_quote_cached_receipt($receiptKey);
if ($cachedReceipt !== null) real_estate_respond($cachedReceipt);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!real_estate_rate_allowed($ip, $submission['details']['email'])) {
    real_estate_respond(['ok'=>false,'message'=>'Too many quote requests have been received. Please wait before trying again, or email sales@sitesee.ai.'], 429);
}

$reference = strtoupper(bin2hex(random_bytes(5)));
$bookingDb = null;
if ($submission['action'] === 'request_appointment' && booking_test_enabled()) {
    try {
        $bookingDb = booking_db();
        booking_capture($bookingDb, $submission, $reference);
    } catch (Throwable $error) {
        error_log('SiteSee booking request could not be stored: ' . $error->getMessage());
        real_estate_respond(['ok'=>false,'message'=>'We could not safely save your preferred-date request. Please try again later or email sales@sitesee.ai.'], 503);
    }
}
$marketLabel = $submission['market'] === 'residential' ? 'Residential' : 'Commercial';
$emailShell = static function (string $heading, string $intro, string $plain) use ($reference): string {
    $escapedBody = nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8'));
    return '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:760px;margin:auto">'
        . '<div style="background:#01111e;padding:24px"><strong style="color:#fff;font-size:24px">SiteSee Real Estate</strong></div>'
        . '<div style="padding:34px;border:1px solid #d9dadb;border-top:0"><h1 style="font-size:30px">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="font-size:17px;line-height:1.6">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:14px;color:#596267">Reference: ' . $reference . '</p>'
        . '<div style="font:15px/1.65 Arial,sans-serif;white-space:normal">' . $escapedBody . '</div>'
        . '<p style="font-size:14px;color:#596267;margin-top:28px">This estimate was recalculated and validated by the SiteSee server. A preferred date and time are not confirmed until SiteSee responds.</p>'
        . '</div></div>';
};

$details = $submission['details'];
$copySent = false;
if ($submission['action'] === 'email_quote') {
    $copySent = real_estate_send_mail(
        $details['email'],
        SITESEE_REAL_ESTATE_SALES_EMAIL,
        $submission['subject'],
        $emailShell('Your ' . $marketLabel . ' SiteSee Real Estate Quote', 'Here is the quote you asked us to email to you.', $submission['plain']),
        "Reference: {$reference}\n\n" . $submission['plain']
    );
    if (!$copySent) {
        real_estate_respond(['ok'=>false,'message'=>'We could not email your quote. Please try again or email sales@sitesee.ai.'], 503);
    }
    real_estate_respond([
        'ok'=>true,
        'action'=>'email_quote',
        'reference'=>$reference,
        'message'=>'Your quote was sent to ' . $details['email'] . '.',
    ], 200, $receiptKey);
}

$salesPlain = "NEW PREFERRED-DATE REQUEST\nReference: {$reference}\n\n" . $submission['salesPlain'];
$salesSent = real_estate_send_mail(
    SITESEE_REAL_ESTATE_SALES_EMAIL,
    $details['email'],
    $submission['subject'],
    $emailShell('New ' . $marketLabel . ' Preferred-Date Request', 'Reply to this message to contact ' . $details['first'] . ' ' . $details['last'] . '.', $submission['salesPlain']),
    $salesPlain
);
if (!$salesSent) {
    real_estate_respond(['ok'=>false,'reference'=>$reference,'message'=>'Your request was saved as ' . $reference . ', but the sales email was not delivered. Please contact sales@sitesee.ai with that reference.'], 503);
}

$copySent = real_estate_send_mail(
    $details['email'],
    SITESEE_REAL_ESTATE_SALES_EMAIL,
    $submission['subject'],
    $emailShell('We Received Your Preferred-Date Request', 'Your request has been delivered to SiteSee. We will confirm availability with you.', $submission['plain']),
    "Reference: {$reference}\n\n" . $submission['plain']
);

if ($copySent) {
    // The request copy already contains this quote. Do not email it again on Back/reload.
    $emailSubmission = $submission;
    $emailSubmission['action'] = 'email_quote';
    real_estate_quote_save_receipt(real_estate_quote_receipt_key($emailSubmission), [
        'ok'=>true, 'action'=>'email_quote', 'reference'=>$reference,
        'message'=>'A copy of this quote was already emailed with your preferred-date request.',
    ]);
}

real_estate_respond([
    'ok'=>true,
    'action'=>'request_appointment',
    'reference'=>$reference,
    'copy_sent'=>$copySent,
    'message'=>$copySent
        ? 'Your preferred-date request was delivered. We also emailed you a copy.'
        : 'Your preferred-date request was delivered. We could not send the confirmation copy, but SiteSee received your request.',
], 200, $receiptKey);
