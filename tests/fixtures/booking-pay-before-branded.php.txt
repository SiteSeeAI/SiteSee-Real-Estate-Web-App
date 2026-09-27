<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-confirmation.php';
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
session_name('sitesee_real_estate_agent_payment');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['booking_csrf'])) $_SESSION['booking_csrf'] = bin2hex(random_bytes(24));
$reference = (string)($_POST['reference'] ?? $_GET['reference'] ?? '');
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
if (isset($_GET['result'])) {
    $saved = $_SESSION['booking_return'][$reference] ?? null;
    if (is_array($saved)) $token = (string)($saved['token'] ?? '');
    else pay_page('<p class="notice">Stripe returned from test Checkout. Payment is recorded only after the signed Stripe webhook is verified. Your appointment is not yet confirmed.</p>');
}
try {
    $db = booking_db();
    $row = booking_agent_record($db, $reference, $token);
} catch (Throwable $error) {
    error_log('SiteSee test payment unavailable: ' . $error->getMessage());
    pay_page('<p>Test payment is temporarily unavailable.</p>', 503);
}
if (!$row) pay_page('<p>This test payment link is invalid or expired. Contact SiteSee for assistance.</p>', 404);
if ($row['status'] === 'deposit_paid_test') {
    $paidError = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!real_estate_same_origin() || !hash_equals($_SESSION['booking_csrf'], (string)($_POST['csrf'] ?? ''))) {
            pay_page('<p>Reload this booking page and try again.</p>', 403);
        }
        if (($_POST['action'] ?? '') === 'request_new_window') {
            try {
                booking_request_new_window($db, $reference, $token, ['date'=>(string)($_POST['date'] ?? ''), 'time'=>(string)($_POST['time'] ?? '')]);
                $row = booking_agent_record($db, $reference, $token);
            } catch (Throwable $exception) {
                $paidError = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The replacement window could not be saved. Please try again.';
            }
        }
    }
    $request = booking_request($row);
    $body = '<p class="notice"><strong>Test Deposit Recorded</strong></p><p>Reference: <strong>' . pay_escape($reference) . '</strong></p>';
    if ($paidError) $body .= '<p role="alert">' . pay_escape($paidError) . '</p>';
    if ($row['reschedule_required']) {
        $earliest = (new DateTimeImmutable('@' . (time() + 72 * 3600)))->setTimezone(new DateTimeZone('America/Chicago'));
        $body .= '<h2>Please Choose Another Arrival Window</h2><p>Your rush request was declined. No rush fee is charged. Your existing deposit will apply to the replacement appointment.</p>'
            . '<p>' . pay_escape((string)$row['rush_decision_reason']) . '</p>'
            . '<p>Choose a standard window starting on or after ' . pay_escape($earliest->format('Y-m-d g:i:s A')) . ' Central Time (72 hours after the current server time). The server checks this again when you submit.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . pay_escape($_SESSION['booking_csrf']) . '"><input type="hidden" name="action" value="request_new_window"><input type="hidden" name="reference" value="' . pay_escape($reference) . '"><input type="hidden" name="token" value="' . pay_escape($token) . '">'
            . '<label>Preferred date <input type="date" name="date" min="' . $earliest->format('Y-m-d') . '" required></label>'
            . '<label>Arrival window · Central Time <select name="time" required><option value="">Select A Window</option>';
        foreach (['07:00'=>'7–9 AM', '09:00'=>'9–11 AM', '11:00'=>'11 AM–1 PM', '13:00'=>'1–3 PM', '15:00'=>'3–5 PM', '17:00'=>'5–7 PM'] as $start => $label) {
            $body .= '<option value="' . $start . '">' . $label . '</option>';
        }
        $body .= '</select></label><button>Request New Window — No Additional Deposit</button></form>';
    } else {
        $body .= '<p>Requested: ' . pay_escape($request['appointment']['date'] . ' ' . $request['appointment']['time'] . (isset($request['appointment']['windowEnd']) ? '–' . $request['appointment']['windowEnd'] : '')) . ' Central Time.</p>';
        if ($row['rush_status'] === 'pending') $body .= '<p>Rush service is awaiting approval. No rush fee has been charged. If approved, $59 will be added to your remaining balance.</p>';
        if ($row['rush_status'] === 'approved') $body .= '<p>Rush service approved: $59 added to the remaining balance. This approval has not charged your card.</p>';
        $body .= '<p>Remaining job balance: <strong>$' . number_format(booking_remaining_cents($row) / 100, 2) . '</strong>.</p>';
        $confirmation = booking_confirmation_get($db, $reference);
        if ($confirmation && $confirmation['state'] === 'confirmed') {
            $body .= '<p class="notice"><strong>Test Appointment Confirmed</strong><br>SiteSee has confirmed the arrival window shown above. This remains an integration test; no live charge has been collected.</p>';
            if ($confirmation['invitation_state'] === 'sent') $body .= '<p>Your test calendar invitation was accepted by our mail server. Please check your inbox.</p>';
            elseif ($confirmation['invitation_state'] === 'none') $body .= '<p>Your calendar invitation has not yet been sent.</p>';
            else $body .= '<p>Your window is confirmed. Invitation delivery needs a staff check; please contact SiteSee if it has not arrived.</p>';
        } else $body .= '<p>We’ll be in contact in less than two hours. Your appointment is not yet confirmed. No invitation has been sent.</p>';
    }
    pay_page($body);
}
if (($_GET['result'] ?? '') === 'success') {
    pay_page('<p class="notice">Stripe returned successfully. We are waiting for the verified payment notification.</p><p>Reference: <strong>' . pay_escape($reference) . '</strong></p><p>Your appointment is not yet confirmed.</p><p><a href="booking-pay.php?result=success&amp;reference=' . rawurlencode($reference) . '">Check Payment Status</a></p>');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!real_estate_same_origin() || !hash_equals($_SESSION['booking_csrf'], (string)($_POST['csrf'] ?? ''))) {
        pay_page('<p>Reload this payment page and try again.</p>', 403);
    }
    if (($_POST['card_consent'] ?? '') !== 'yes') {
        $error = 'Please agree to the stated future card use before continuing.';
    } else {
        try {
            // Keep only a bounded set of bearer links in this private payment session.
            $_SESSION['booking_return'][$reference] = ['token'=>$token];
            while (count($_SESSION['booking_return']) > 20) array_shift($_SESSION['booking_return']);
            $url = booking_start_checkout($db, $reference, $token, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            header('Location: ' . $url, true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log('SiteSee test checkout failed: ' . $exception->getMessage());
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Test Checkout is unavailable. Please try again shortly.';
        }
    }
}
$request = booking_request($row);
$details = $request['details'];
$remaining = booking_remaining_cents($row);
$money = static fn(int $cents): string => '$' . number_format($cents / 100, 2);
$body = '<p class="notice"><strong>Test mode:</strong> no live charge, confirmed appointment or invitation will be created.</p>'
    . '<p>Property: ' . pay_escape($details['street'] . ' ' . $details['unit'] . ', ' . $details['city'] . ', ' . $details['state']) . '<br>'
    . 'Requested date/window: ' . pay_escape($request['appointment']['date'] . ' ' . $request['appointment']['time'] . (isset($request['appointment']['windowEnd']) ? '–' . $request['appointment']['windowEnd'] . ' (arrival window)' : '')) . ' Central Time<br>'
    . 'Photographer: ' . pay_escape((string)($row['photographer'] ?: 'Assigned after deposit and schedule review')) . '<br>Planned on-site time: ' . (int)($row['duration_minutes'] ?? $request['quote']['knownMinutesMax'] ?? $request['quote']['knownMinutes'] ?? 0) . ' minutes (separate from arrival window)</p>'
    . '<p>One-time price used for this deposit: <strong>' . $money((int)$row['approved_cents']) . '</strong><br>'
    . 'Test deposit due now: <strong>' . $money((int)$row['deposit_cents']) . '</strong><br>'
    . 'Remaining job balance after deposit: ' . $money($remaining) . '</p>';
if ($row['rush_status'] === 'pending') $body .= '<p class="notice">Rush requested: $59 only if SiteSee approves it. The fee is excluded from this deposit and will be added to your remaining balance only after approval. If declined, no rush fee is charged and you can request another window using this booking link.</p>';
if ($row['price_reason']) $body .= '<p>Price adjustment: ' . pay_escape((string)$row['price_reason']) . '</p>';
if ((int)$row['platform_monthly_cents'] > 0 && $row['market'] === 'residential') {
    $body .= '<p>Selected residential platform: ' . $money((int)$row['platform_monthly_cents']) . '/month, billed separately only after publication. No subscription starts with this deposit.</p>';
}
$body .= '<p class="notice">Your 50% test deposit comes before schedule review. Your preferred date and arrival window are not guaranteed. You must accept any proposed alternative before confirmation. If no mutually acceptable date is available, the deposit is refundable. Any scope or price change requires your agreement.</p><p>Request saved. Reference: <strong>' . pay_escape($reference) . '</strong></p><p class="policy">Cancellation policy: cancel at least 24 hours before a confirmed appointment for a refund of any deposit paid. Within 24 hours, the deposit remains a credit toward one rescheduled shoot.</p>';
if ($error) $body .= '<p role="alert">' . pay_escape($error) . '</p>';
$body .= '<form method="post"><input type="hidden" name="csrf" value="' . pay_escape($_SESSION['booking_csrf']) . '">'
    . '<input type="hidden" name="reference" value="' . pay_escape($reference) . '"><input type="hidden" name="token" value="' . pay_escape($token) . '">'
    . '<label><input type="checkbox" name="card_consent" value="yes" required><span>' . pay_escape(BOOKING_CONSENT_TEXT) . '</span></label>'
    . '<button>Continue To Stripe Test Checkout</button></form>';
pay_page($body);


