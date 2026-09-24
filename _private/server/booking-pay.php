<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-store.php';
if (!booking_test_enabled()) {
    http_response_code(503);
    exit('Test payments are disabled.');
}
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('Content-Type: text/html; charset=utf-8');

function pay_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function pay_page(string $body, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SiteSee | Test Deposit</title><style>body{margin:0;background:#01111e;color:#12212e;font:17px/1.6 Inter,Arial,sans-serif}main{box-sizing:border-box;width:min(760px,92vw);margin:6vw auto;padding:40px;background:#fff;border-top:8px solid #ffc107}h1{font-family:Poppins,Arial,sans-serif}button{background:#ffc107;color:#01111e;padding:15px 22px;border:0;font-weight:800;cursor:pointer}.notice{padding:16px;background:#fff7db}.policy{font-size:.9em}label{display:flex;gap:10px;align-items:start;margin:24px 0}input[type=checkbox]{margin-top:8px}</style></head><body><main><h1>SiteSee Test Deposit</h1>' . $body . '</main></body></html>';
    exit;
}
if (isset($_GET['result'])) {
    $message = $_GET['result'] === 'success'
        ? 'Stripe returned from test Checkout. Payment is recorded only after the signed Stripe webhook is verified. Your appointment is not yet confirmed.'
        : 'Test Checkout was canceled. Your requested appointment remains pending; no invitation was sent.';
    pay_page('<p class="notice">' . pay_escape($message) . '</p>');
}
session_name('sitesee_real_estate_agent_payment');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['booking_csrf'])) $_SESSION['booking_csrf'] = bin2hex(random_bytes(24));
$reference = (string)($_POST['reference'] ?? $_GET['reference'] ?? '');
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
try {
    $db = booking_db();
    $row = booking_agent_record($db, $reference, $token);
} catch (Throwable $error) {
    error_log('SiteSee test payment unavailable: ' . $error->getMessage());
    pay_page('<p>Test payment is temporarily unavailable.</p>', 503);
}
if (!$row) pay_page('<p>This test payment link is invalid or expired. Contact SiteSee for assistance.</p>', 404);
if ($row['status'] === 'deposit_paid_test') pay_page('<p>We have recorded the test deposit. SiteSee will review the appointment separately. No invitation has been sent.</p>');

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!real_estate_same_origin() || !hash_equals($_SESSION['booking_csrf'], (string)($_POST['csrf'] ?? ''))) {
        pay_page('<p>Reload this payment page and try again.</p>', 403);
    }
    if (($_POST['card_consent'] ?? '') !== 'yes') {
        $error = 'Please agree to the stated future card use before continuing.';
    } else {
        try {
            $url = booking_start_checkout($db, $reference, $token, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            header('Location: ' . $url, true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log('SiteSee test checkout failed: ' . $exception->getMessage());
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Test Checkout is unavailable. Please try again shortly.';
        }
    }
}
$request = json_decode($row['request_json'], true);
$details = $request['details'];
$remaining = (int)$row['approved_cents'] - (int)$row['deposit_cents'];
$money = static fn(int $cents): string => '$' . number_format($cents / 100, 2);
$body = '<p class="notice"><strong>Test mode:</strong> no live charge, confirmed appointment or invitation will be created.</p>'
    . '<p>Property: ' . pay_escape($details['street'] . ' ' . $details['unit'] . ', ' . $details['city'] . ', ' . $details['state']) . '<br>'
    . 'Requested time: ' . pay_escape($request['appointment']['date'] . ' ' . $request['appointment']['time']) . ' Central Time<br>'
    . 'Photographer: ' . pay_escape((string)$row['photographer']) . '<br>Planned on-site time: ' . (int)$row['duration_minutes'] . ' minutes</p>'
    . '<p>Agreed one-time price: <strong>' . $money((int)$row['approved_cents']) . '</strong><br>'
    . 'Test deposit due now: <strong>' . $money((int)$row['deposit_cents']) . '</strong><br>'
    . 'Remaining job balance after deposit: ' . $money($remaining) . '</p>';
if ($row['price_reason']) $body .= '<p>Price adjustment: ' . pay_escape((string)$row['price_reason']) . '</p>';
if ((int)$row['platform_monthly_cents'] > 0 && $row['market'] === 'residential') {
    $body .= '<p>Selected residential platform: ' . $money((int)$row['platform_monthly_cents']) . '/month, billed separately only after publication. No subscription starts with this deposit.</p>';
}
$body .= '<p class="policy">Cancellation policy: cancel at least 24 hours before a confirmed appointment for a refund of any deposit paid. Within 24 hours, the deposit remains a credit toward one rescheduled shoot.</p>';
if ($error) $body .= '<p role="alert">' . pay_escape($error) . '</p>';
$body .= '<form method="post"><input type="hidden" name="csrf" value="' . pay_escape($_SESSION['booking_csrf']) . '">'
    . '<input type="hidden" name="reference" value="' . pay_escape($reference) . '"><input type="hidden" name="token" value="' . pay_escape($token) . '">'
    . '<label><input type="checkbox" name="card_consent" value="yes" required><span>I authorize SiteSee to save the card used for this test deposit for the remaining approved job balance and any on-site services I separately approve. If I selected the residential platform, I authorize its separate monthly billing only after publication until I notify SiteSee the property is sold. I understand later charges require their own approved scope and that a saved card may require further authentication.</span></label>'
    . '<button>Continue To Stripe Test Checkout</button></form>';
pay_page($body);
