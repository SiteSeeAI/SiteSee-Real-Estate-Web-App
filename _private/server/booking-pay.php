<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-confirmation.php';
require_once __DIR__ . '/booking-checkout.php';
require_once dirname(__DIR__) . '/views/booking-payment.php';
if (!booking_test_enabled()) {
    http_response_code(503);
    exit('Test payments are disabled.');
}
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$nonce = base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce' https://js.stripe.com https://*.js.stripe.com https://checkout.stripe.com; style-src 'unsafe-inline'; font-src 'self'; img-src 'self' data: https://*.stripe.com https://*.link.com; connect-src 'self' https://api.stripe.com https://checkout.stripe.com https://link.com https://*.link.com; frame-src https://checkout.stripe.com https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com https://link.com https://*.link.com; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function pay_page(string $body, int $status = 200, array $options = []): never
{
    global $row, $nonce;
    http_response_code($status);
    echo pay_render($body, $options + ['nonce'=>$nonce, 'row'=>is_array($row ?? null) ? $row : null,
        'client'=>['reference'=>is_array($row ?? null) ? $row['reference'] : '']]);
    exit;
}
function pay_json(array $data, int $status=200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}
session_name('sitesee_real_estate_agent_payment');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['booking_csrf'])) $_SESSION['booking_csrf'] = bin2hex(random_bytes(24));
$reference = (string)($_POST['reference'] ?? $_GET['reference'] ?? '');
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
if ($token === '' || isset($_GET['result'])) {
    $saved = $_SESSION['booking_return'][$reference] ?? null;
    if (is_array($saved)) $token = (string)$saved['token'];
}
$isJson = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
try {
    $db = booking_db();
    $row = booking_agent_record($db, $reference, $token);
} catch (Throwable $error) {
    error_log('SiteSee test payment unavailable: ' . $error->getMessage());
    if ($isJson) pay_json(['error'=>'Payment is temporarily unavailable. Please try again.'],503);
    pay_page('<p>Test payment is temporarily unavailable.</p>', 503);
}
if (!$row) {
    if ($isJson) pay_json(['error'=>'This payment link is invalid or expired. Please reopen your booking link.'],404);
    pay_page('<h2>Let’s Find Your Booking.</h2><p>This payment link is invalid or expired. Please reopen your booking link or contact SiteSee at <a href="tel:18002222053">800 222-2053</a>.</p>',404,['title'=>'Your Booking Link','intro'=>'We’re here to help you take the next step.']);
}
$_SESSION['booking_return'][$reference] = ['token'=>$token];
while (count($_SESSION['booking_return']) > 20) array_shift($_SESSION['booking_return']);
$csrf = (string)$_SESSION['booking_csrf'];
session_write_close();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (!real_estate_same_origin() || !hash_equals($csrf,(string)($_POST['csrf']??'')))) {
    if ($isJson) pay_json(['error'=>'Reload this payment page and try again.'],403);
    pay_page('<p>Reload this payment page and try again.</p>',403);
}
if ($isJson && ($_POST['action']??'') === 'payment_status') {
    pay_json(['paid'=>$row['status']==='deposit_paid_test']);
}
if ($isJson && $row['status']==='deposit_paid_test') pay_json(['mode'=>'paid']);
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
    $body = '<span class="status-mark" aria-hidden="true">✓</span><h2>Your Deposit Is Recorded.</h2><p class="payment-note">Thank you. You can follow the status of your appointment below.</p>';
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
    pay_page($body,200,['title'=>'Thank You. You’re One Step Closer.','intro'=>'Your test deposit has been verified. Appointment status is shown below.','step'=>'review']);
}
$returnPending = ($_GET['result'] ?? '') === 'success';
if (($_GET['result'] ?? '') === 'return') {
    try { $returnPending = !in_array(booking_checkout_return($db,$row),['open','expired'],true); }
    catch (Throwable $error) { $returnPending = true; }
}
if ($returnPending) {
    pay_page('<span class="status-mark waiting" aria-hidden="true">···</span><h2>Checking Your Payment.</h2>'
        .'<p class="payment-note">We’re waiting for Stripe to verify your deposit. This page will update when it is recorded.</p>'
        .'<div class="notice">Your appointment will be confirmed separately after schedule review.</div>'
        .'<a class="button" href="booking-pay.php?result=return&amp;reference='.rawurlencode($reference).'">Check Payment Status</a>',
        200,['title'=>'Your Payment Status','intro'=>'We’ll show your verified deposit here as soon as it is received.',
        'client'=>['reference'=>$reference,'csrf'=>$csrf,'poll'=>true]]);
}
$error = '';
$config = ['enabled'=>false,'publishable_key'=>''];
try { $config = booking_checkout_config(); }
catch (Throwable $exception) { $error = 'The payment form is temporarily unavailable. Please contact SiteSee.'; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['card_consent'] ?? '') !== 'yes') {
        $error = 'Please agree to the stated future card use before continuing.';
    } elseif ($error === '') {
        try {
            if ($config['enabled']) {
                if (!$isJson) throw new InvalidArgumentException('Please enable JavaScript to load the secure payment form, then try again.');
                pay_json(booking_checkout_start($db,$reference,$token,(string)($_SERVER['REMOTE_ADDR']??'unknown')));
            }
            // Do not convert an active embedded attempt into a second hosted charge.
            $exists=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='booking_checkout_ui'")->fetchColumn();
            if ($exists) {
                $owned=$db->prepare('SELECT attempt FROM booking_checkout_ui WHERE reference=?');$owned->execute([$reference]);
                $attempt=$owned->fetchColumn();
                if ($attempt!==false && (int)$attempt===(int)$row['checkout_attempt'] && in_array($row['checkout_state'],['creating','open'],true)) {
                    throw new RuntimeException('Embedded checkout configuration is unavailable.');
                }
            }
            $url=booking_start_checkout($db,$reference,$token,(string)($_SERVER['REMOTE_ADDR']??'unknown'));
            if ($isJson) pay_json(['mode'=>'hosted','url'=>$url]);
            header('Location: '.$url,true,303);exit;
        } catch (Throwable $exception) {
            error_log('SiteSee payment form could not be prepared.');
            $error=$exception instanceof InvalidArgumentException ? $exception->getMessage()
                : 'The payment form could not be prepared. Please try again shortly. If the problem continues, call 800 222-2053.';
        }
    }
    if ($isJson) pay_json(['error'=>$error],409);
}
$body='<p class="section-marker">Your Next Step</p><h2>Complete Your Deposit.</h2>'
    .'<p class="payment-note">Your payment details are collected securely by Stripe.</p>'
    .'<div class="security-line"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="4" y="8" width="12" height="10" rx="2"/><path d="M6 8V5a4 4 0 018 0v3"/></svg>Secure Payment · Powered By Stripe</div>'
    .'<div class="notice"><strong>What Happens Next</strong><br>Your deposit starts our schedule review. Your preferred date and arrival window are not guaranteed. We’ll contact you in less than two hours. You must accept any alternative before confirmation. If no mutually acceptable date is available, the deposit is refundable. Any scope or price change requires your agreement.</div>'
    .'<div id="payment-error" class="error" role="alert" tabindex="-1"'.($error?'':' hidden').'>'.pay_escape($error).'</div>'
    .'<form id="payment-consent" method="post" action="booking-pay.php"><input type="hidden" name="action" value="checkout">'
    .'<input type="hidden" name="csrf" value="'.pay_escape($csrf).'"><input type="hidden" name="reference" value="'.pay_escape($reference).'">'
    .'<input type="hidden" name="token" value="'.pay_escape($token).'">'
    .'<label class="consent"><input type="checkbox" name="card_consent" value="yes" required><span>'.pay_escape(BOOKING_CONSENT_TEXT).'</span></label>'
    .'<button class="primary-action" type="submit">Continue To Secure Payment <span aria-hidden="true">→</span></button></form>'
    .'<p id="checkout-status" class="checkout-status" aria-live="polite"></p><div id="stripe-checkout" class="checkout-mount"></div>'
    .'<p class="policy">This is a test payment. No live charge will be collected. Only the deposit is due today; the remaining balance is shown in your booking summary.</p>';
if ($config['enabled']) $body.='<noscript><p class="notice">Please enable JavaScript to use the secure payment form.</p></noscript>';
pay_page($body,200,['client'=>['enabled'=>$config['enabled'],'publishableKey'=>$config['publishable_key'],'reference'=>$reference]]);
