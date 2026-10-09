<?php
declare(strict_types=1);

$temp = sys_get_temp_dir() . '/sitesee-booking-test-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=' . str_repeat('q', 48));
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_' . str_repeat('a', 24));
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET=whsec_' . str_repeat('b', 24));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB=' . $temp . '/bookings.sqlite');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__ . '/../_private/server/booking-store.php';

$checks = 0;
$assert = static function (bool $condition, string $why) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($why);
};
$reject = static function (callable $callback, string $why) use (&$checks): void {
    $checks++;
    try { $callback(); } catch (InvalidArgumentException | RuntimeException $error) { return; }
    throw new RuntimeException($why);
};
$staffPassword = 'test-only-staff-password-2026';
$staffHash = password_hash($staffPassword, PASSWORD_DEFAULT);
putenv('SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=' . $staffHash);
$assert(booking_staff_password_hash() === $staffHash, 'Raw hashes remain supported outside PHP-FPM.');
$encodedStaffHash = 'base64:' . base64_encode($staffHash);
$assert($encodedStaffHash[0] !== '$', 'Encoded hash avoids PHP-FPM environment reference expansion.');
putenv('SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=' . $encodedStaffHash);
$assert(booking_staff_password_hash() === $staffHash, 'Encoded hash restores the exact original hash.');
$assert(password_verify($staffPassword, booking_staff_password_hash()), 'Existing staff password verifies with encoded configuration.');
$assert(!password_verify('wrong-password', booking_staff_password_hash()), 'Incorrect password remains rejected.');
putenv('SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=base64:***invalid***');
$assert(booking_staff_password_hash() === '', 'Malformed encoded configuration fails closed.');
putenv('SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=base64:');
$assert(booking_staff_password_hash() === '', 'Empty encoded configuration fails closed.');
putenv('SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH');
$assert(booking_staff_password_hash() === '', 'Missing configuration remains disabled.');

$now = new DateTimeImmutable('2026-09-24 10:00', new DateTimeZone('America/Chicago'));
$details = [
    'first'=>'Ava','last'=>'Agent','company'=>'Example Realty','email'=>'ava@example.com',
    'phone'=>'555-555-0100','street'=>'123 Main St','unit'=>'Unit 2','propertyId'=>'MLS123',
    'city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes',
];
$state = ['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo','platform'],'videoSeconds'=>60,'images'=>1];
$appointment = [
    'date'=>'2026-10-15','time'=>'10:00','meetPhotographer'=>'No',
    'accessType'=>'Lockbox','lockboxCode'=>'0123456789','cancellationAccepted'=>true,
];
$submission = real_estate_prepare_submission([
    'version'=>1,'action'=>'request_appointment','market'=>'residential',
    'details'=>$details,'state'=>$state,'appointment'=>$appointment,
], $now);
$db = booking_db();
$reference = 'ABCDEF1234';
booking_capture($db, $submission, $reference);
$row = booking_get($db, $reference);
$assert($row['status'] === 'pending_review', 'Request remains pending before staff review.');
$assert($row['requested_utc'] === '2026-10-15T15:00:00Z', 'Central requested slot is stored as UTC.');
$assert((int)$row['platform_monthly_cents'] === 4900, 'Monthly residential platform stays separate.');
$assert(str_contains($row['request_json'], '0123456789'), 'Access details reach the private booking store.');
$reject(static fn() => booking_approve($db, $reference, 26000, 75, 'Pat', false), 'Availability is mandatory.');
$reject(static fn() => booking_approve($db, $reference, 26000, 75, 'Pat', true), 'A changed price requires a reason.');
$token = booking_approve($db, $reference, 26000, 75, 'Pat Photographer', true, 'Additional setup discussed with agent.');
$assert(strlen($token) === 64, 'Test payment link has a strong bearer token.');
$reject(static fn() => booking_approve($db, $reference, 26000, 75, 'Pat', true), 'Staff cannot approve twice.');
$assert(booking_agent_record($db, $reference, str_repeat('f', 64)) === false, 'Invalid link rejected.');
$replacement = booking_rotate_test_link($db, $reference);
$assert(booking_agent_record($db, $reference, $token) === false, 'Replacing a lost link revokes the old one.');
$token = $replacement;
$assert((int)booking_get($db, $reference)['deposit_cents'] === 13000, 'Deposit is half the agreed job price.');

$called = 0;
$transport = static function (array $body, string $key, string $secret) use (&$called, $assert): array {
    $called++;
    $assert($body['line_items[0][price_data][unit_amount]'] === '13000', 'Stripe uses locked deposit amount.');
    $assert($body['payment_intent_data[setup_future_usage]'] === 'off_session', 'Test card is optimized for later authorized payments.');
    $assert($body['customer_creation'] === 'always', 'Checkout creates a Stripe Customer.');
    $assert(str_starts_with($secret, 'sk_test_') && str_contains($key, '-1'), 'Only test credentials and first idempotency key used.');
    return ['id'=>'cs_test_first','url'=>'https://checkout.stripe.com/c/pay/first','livemode'=>false];
};
$reject(static function () use ($db, $reference, $token, $transport): void {
    putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');
    try { booking_start_checkout($db, $reference, $token, '127.0.0.1', $transport); }
    finally { putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1'); }
}, 'Default-off test switch blocks Checkout before transport runs.');
$reject(static function () use ($db, $reference, $token, $transport): void {
    putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_live_forbidden');
    try { booking_start_checkout($db, $reference, $token, '127.0.0.1', $transport); }
    finally { putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_' . str_repeat('a', 24)); }
}, 'Live key is refused before Stripe transport runs.');
$assert($called === 0, 'No attempt with live key.');
$url = booking_start_checkout($db, $reference, $token, '127.0.0.1', $transport);
$assert($url === 'https://checkout.stripe.com/c/pay/first', 'Agent is redirected to the test session.');
$assert(booking_start_checkout($db, $reference, $token, '127.0.0.1', $transport) === $url && $called === 1, 'Repeated submission reuses same session.');
$reject(static fn() => booking_rotate_test_link($db, $reference), 'An open Checkout cannot be replaced with a new link.');
$assert(booking_get($db, $reference)['consent_version'] === BOOKING_CONSENT_VERSION && BOOKING_CONSENT_VERSION === 'test-card-reuse-v2', 'Current consent version is recorded.');

$event = [
    'id'=>'evt_first','livemode'=>false,'type'=>'checkout.session.completed','data'=>['object'=>[
        'id'=>'cs_test_first','livemode'=>false,'mode'=>'payment','client_reference_id'=>$reference,
        'metadata'=>['booking_reference'=>$reference],'payment_status'=>'paid','currency'=>'usd',
        'amount_total'=>13000,'payment_intent'=>'pi_test_first','customer'=>'cus_test_first',
    ]],
];
$sign = static function (array $value, int $timestamp): array {
    $raw = json_encode($value, JSON_THROW_ON_ERROR);
    $sig = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $raw, (string)getenv('SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET'));
    return [$raw, $sig];
};
[$raw, $sig] = $sign($event, time());
$reject(static fn() => booking_verify_stripe_event($raw, $sig . 'deadbeef', time() + 301), 'Stale webhook rejected.');
$reject(static fn() => booking_verify_stripe_event($raw, 't=' . time() . ',v1=' . str_repeat('0', 64)), 'Bad signature rejected.');
$liveEvent = $event; $liveEvent['livemode'] = true;
[$liveRaw, $liveSig] = $sign($liveEvent, time());
$reject(static fn() => booking_verify_stripe_event($liveRaw, $liveSig), 'Even a correctly signed live event is rejected.');
$assert(booking_verify_stripe_event($raw, $sig)['id'] === 'evt_first', 'Signed test event accepted.');
$wrong = $event;
$wrong['data']['object']['amount_total'] = 1;
$reject(static fn() => booking_process_stripe_event($db, $wrong), 'Amount mismatch cannot mark deposit paid.');
$assert(booking_get($db, $reference)['status'] === 'approved_test', 'Failed event leaves booking pending.');
$assert(booking_process_stripe_event($db, $event) === 'deposit_paid_test', 'Verified paid event records deposit.');
$assert(booking_process_stripe_event($db, $event) === 'duplicate', 'Duplicate webhook is idempotent.');
$row = booking_get($db, $reference);
$assert($row['status'] === 'deposit_paid_test' && $row['stripe_payment_intent_id'] === 'pi_test_first', 'Payment ID persisted without confirmation event.');
$assert(booking_agent_record($db, $reference, $token)['status'] === 'deposit_paid_test', 'Agent sees test payment status.');
$reject(static fn() => booking_start_checkout($db, $reference, $token, '127.0.0.1', $transport), 'Paid booking cannot create another session.');
$second = $event; $second['id'] = 'evt_conflict'; $second['data']['object']['payment_intent'] = 'pi_other';
$reject(static fn() => booking_process_stripe_event($db, $second), 'Conflicting second payment rejected.');

$nextRef = 'ABCDEF1235';
booking_capture($db, $submission, $nextRef);
$nextToken = booking_approve($db, $nextRef, 24501, 70, 'Pat', true, 'Approved one-cent adjustment.');
$retryTransport = static function (array $body, string $key) use ($assert): array {
    $assert($body['line_items[0][price_data][unit_amount]'] === '12251', 'Odd cent deposit rounds up.');
    return ['id'=>str_contains($key, '-2') ? 'cs_test_retry' : 'cs_test_expiring',
        'url'=>'https://checkout.stripe.com/c/pay/retry','livemode'=>false];
};
booking_start_checkout($db, $nextRef, $nextToken, '127.0.0.1', $retryTransport);
$expired = ['id'=>'evt_expired','type'=>'checkout.session.expired','data'=>['object'=>[
    'id'=>'cs_test_expiring','livemode'=>false,'mode'=>'payment','client_reference_id'=>$nextRef,
    'metadata'=>['booking_reference'=>$nextRef],
]]];
$assert(booking_process_stripe_event($db, $expired) === 'expired', 'Expired test session allows retry.');
$assert(booking_get($db, $nextRef)['status'] === 'approved_test', 'Expired session does not confirm booking.');
booking_start_checkout($db, $nextRef, $nextToken, '127.0.0.1', $retryTransport);
$assert(booking_get($db, $nextRef)['stripe_session_id'] === 'cs_test_retry', 'Retry gets new session and idempotency key.');
$lateExpired = $expired; $lateExpired['id'] = 'evt_late_expired';
$assert(booking_process_stripe_event($db, $lateExpired) === 'stale_expired', 'Late expiry cannot invalidate a new active session.');
$assert(booking_get($db, $nextRef)['checkout_state'] === 'open', 'New Checkout remains open after late expiry.');

$failedRef = 'ABCDEF1236';
booking_capture($db, $submission, $failedRef);
$failedToken = booking_approve($db, $failedRef, 24500, 70, 'Pat', true);
$attemptKeys = [];
$flaky = static function (array $body, string $key) use (&$attemptKeys): array {
    $attemptKeys[] = $key;
    if (count($attemptKeys) === 1) throw new RuntimeException('Simulated Stripe timeout after acceptance.');
    return ['id'=>'cs_test_recovered','url'=>'https://checkout.stripe.com/c/pay/recovered','livemode'=>false];
};
$reject(static fn() => booking_start_checkout($db, $failedRef, $failedToken, '127.0.0.1', $flaky), 'Stripe transport failure is retryable.');
$assert(booking_get($db, $failedRef)['status'] === 'approved_test', 'Transport failure never marks the deposit paid.');
booking_start_checkout($db, $failedRef, $failedToken, '127.0.0.1', $flaky);
$assert(count($attemptKeys) === 2 && $attemptKeys[0] === $attemptKeys[1], 'Retry uses the original Stripe idempotency key.');
$reject(static fn() => real_estate_validate_appointment(['date'=>'2027-03-14','time'=>'02:30'], $now), 'Spring DST gap rejected.');
$reject(static fn() => real_estate_validate_appointment(['date'=>'2027-11-07','time'=>'01:30'], $now), 'Ambiguous fall DST hour rejected.');

// Version 2: deposit is available before any staff approval, for both markets.
foreach (['residential', 'commercial'] as $market) {
    $payload = [
        'version'=>2, 'action'=>'request_appointment', 'market'=>$market,
        'details'=>$details, 'state'=>$state, 'appointment'=>$appointment,
    ];
    if ($market === 'commercial') $payload['state'] = [
        'category'=>'mid', 'photoCount'=>'45', 'selected'=>['photo'], 'videoSeconds'=>60,
        'videos'=>'1', 'aerialImages'=>'1', 'delivery'=>'files', 'platformMonths'=>'6',
        'plans'=>'1', 'licenseType'=>'term', 'licenseMonths'=>'6', 'hostingMonths'=>'6', 'hostingPrepaid'=>false,
    ];
    $payload['appointment']['time'] = '09:00';
    $payload['appointment']['windowEnd'] = '23:59'; // tampered end is ignored
    $new = real_estate_prepare_submission($payload, $now);
    $assert($new['appointment']['windowEnd'] === '11:00', 'Server derives a two-hour window.');
    $assert(str_contains($new['plain'], '09:00–11:00'), 'Email includes the window.');
    $ref = $market === 'residential' ? 'ABCDEF1240' : 'ABCDEF1241';
    $token = booking_capture($db, $new, $ref, true);
    $row = booking_get($db, $ref);
    $deposit = intdiv($new['quote']['totalCents'] + 1, 2);
    $assert($row['status'] === 'awaiting_deposit_test' && $row['approved_at'] === null, 'New booking awaits deposit before approval.');
    $assert((int)$row['deposit_cents'] === $deposit, 'Deposit uses server one-time total.');
    $reject(static fn() => booking_review_paid($db, $ref, 90, 'David', true), 'Cannot review unpaid request.');
    $reject(static fn() => booking_approve($db, $ref, 25000, 90, 'David', true), 'Legacy approval cannot bypass deposit-first flow.');
    $newTransport = static fn() => ['id'=>'cs_test_' . $market, 'url'=>'https://checkout.stripe.com/c/pay/test', 'livemode'=>false];
    booking_start_checkout($db, $ref, $token, '127.0.0.1', $newTransport);
    $paid = ['id'=>'evt_' . $market, 'type'=>'checkout.session.completed', 'data'=>['object'=>[
        'id'=>'cs_test_' . $market, 'livemode'=>false, 'mode'=>'payment', 'client_reference_id'=>$ref,
        'metadata'=>['booking_reference'=>$ref], 'payment_status'=>'paid', 'currency'=>'usd',
        'amount_total'=>$deposit, 'payment_intent'=>'pi_' . $market, 'customer'=>'cus_' . $market,
    ]]];
    $assert(booking_process_stripe_event($db, $paid) === 'deposit_paid_test', 'Payment unlocks review.');
    $reject(static fn() => booking_review_paid($db, $ref, 90, 'David', false), 'Availability check still required after payment.');
    booking_review_paid($db, $ref, 90, 'David J Cro', true);
    $assert(booking_get($db, $ref)['photographer'] === 'David J Cro', 'Paid review saves assigned photographer.');
    $assert(booking_get($db, $ref)['status'] === 'deposit_paid_test', 'Review does not claim appointment confirmation.');
    $reject(static fn() => booking_review_paid($db, $ref, 90, 'Someone else', true), 'Duplicate review cannot overwrite assignment.');
    $assert(booking_process_stripe_event($db, $paid) === 'duplicate', 'Duplicate webhook after review stays idempotent.');
    $reject(static fn() => booking_start_checkout($db, $ref, $token, '127.0.0.1', $newTransport), 'Reviewed paid request cannot be charged again.');
}
$reject(static fn() => real_estate_arrival_window(['date'=>'2027-03-14','time'=>'00:30']), 'Window spanning spring clock change rejected.');
$reject(static fn() => real_estate_arrival_window(['date'=>'2027-11-07','time'=>'00:30']), 'Window spanning fall clock change rejected.');
$reject(static fn() => real_estate_arrival_window(['date'=>'2027-03-15','time'=>'23:00']), 'Window crossing midnight rejected.');
$assert(real_estate_arrival_window(['date'=>'2027-03-14','time'=>'13:30'])['windowEnd'] === '15:30', 'Daytime DST date preserves two-hour local window.');

foreach (['07:00'=>'09:00', '09:00'=>'11:00', '11:00'=>'13:00', '13:00'=>'15:00', '15:00'=>'17:00', '17:00'=>'19:00'] as $start => $end) {
    $assert(real_estate_arrival_window(['date'=>'2027-04-01', 'time'=>$start])['windowEnd'] === $end, 'Every approved block derives its correct end.');
}
foreach (['06:00', '08:00', '09:15', '10:00', '18:00', '19:00'] as $start) {
    $reject(static fn() => real_estate_arrival_window(['date'=>'2027-04-01', 'time'=>$start]), 'Unlisted exact times are rejected.');
}
// Rolling notice uses the server instant and is unaffected by default PHP timezone.
$serverNow = new DateTimeImmutable('2026-09-25T09:00:00-05:00');
$at72 = ['date'=>'2026-09-28', 'time'=>'09:00'];
$assert(real_estate_validate_lead_time($at72, [], $serverNow)['leadHours'] === 72, 'Exactly 72 hours is accepted.');
$reject(static fn() => real_estate_validate_lead_time($at72, [], $serverNow->modify('+1 second')), 'One second under 72 hours is rejected.');
$at12 = ['date'=>'2026-09-26', 'time'=>'07:00'];
$rushNow = new DateTimeImmutable('2026-09-25T19:00:00-05:00');
$assert(real_estate_validate_lead_time($at12, ['rushRequested'=>true], $rushNow)['leadHours'] === 12, 'Exactly 12 hours is accepted for rush.');
$reject(static fn() => real_estate_validate_lead_time($at12, ['rushRequested'=>true], $rushNow->modify('+1 second')), 'One second under 12 hours is rejected.');
$reject(static fn() => real_estate_validate_lead_time($at12, [], $rushNow), 'Rush notice is not available without explicit opt-in.');
$reject(static fn() => real_estate_validate_lead_time($at12, ['rushRequested'=>'yes'], $rushNow), 'Rush flag type is enforced.');
$previousTimezone = date_default_timezone_get(); date_default_timezone_set('Asia/Tokyo');
$assert(real_estate_validate_lead_time($at72, [], $serverNow)['leadHours'] === 72, 'Server-local timezone does not alter elapsed notice.');
date_default_timezone_set($previousTimezone);
$springNow = new DateTimeImmutable('2027-03-11T09:00:00-06:00');
$reject(static fn() => real_estate_validate_lead_time(['date'=>'2027-03-14','time'=>'09:00'], [], $springNow), 'Spring clock change is not rounded to three calendar days.');
$assert(real_estate_validate_lead_time(['date'=>'2027-03-14','time'=>'11:00'], [], $springNow)['leadHours'] === 72, 'First eligible listed spring window passes.');
$fallNow = new DateTimeImmutable('2027-11-04T09:00:00-05:00');
$assert(real_estate_validate_lead_time(['date'=>'2027-11-07','time'=>'09:00'], [], $fallNow)['leadHours'] === 72, 'Fall window has at least 72 actual hours.');

foreach (['approve', 'decline'] as $decision) {
    $payload['appointment']['rushRequested'] = true;
    $rushSubmission = real_estate_prepare_submission($payload, $now);
    $ref = $decision === 'approve' ? 'ABCDEF1250' : 'ABCDEF1251';
    $rushToken = booking_capture($db, $rushSubmission, $ref, true);
    $initial = booking_get($db, $ref);
    $assert($initial['rush_status'] === 'pending' && (int)$initial['rush_fee_cents'] === 0, 'Rush opt-in does not approve or collect a fee.');
    $deposit = intdiv($rushSubmission['quote']['totalCents'] + 1, 2);
    $assert((int)$initial['deposit_cents'] === $deposit, 'Initial deposit excludes rush fee.');
    $reject(static fn() => booking_decline_rush($db, $ref, 'Unavailable'), 'Cannot decide before deposit is recorded.');
    $transport = static function ($body) use ($assert, $deposit, $decision) {
        $assert((int)$body['line_items[0][price_data][unit_amount]'] === $deposit, 'Stripe receives no pending rush surcharge.');
        return ['id'=>'cs_test_rush_' . $decision, 'url'=>'https://checkout.stripe.com/c/pay/rush', 'livemode'=>false];
    };
    booking_start_checkout($db, $ref, $rushToken, '127.0.0.1', $transport);
    $paid = ['id'=>'evt_rush_' . $decision, 'type'=>'checkout.session.completed', 'data'=>['object'=>[
        'id'=>'cs_test_rush_' . $decision, 'livemode'=>false, 'mode'=>'payment', 'client_reference_id'=>$ref,
        'metadata'=>['booking_reference'=>$ref], 'payment_status'=>'paid', 'currency'=>'usd',
        'amount_total'=>$deposit, 'payment_intent'=>'pi_rush_' . $decision, 'customer'=>'cus_rush_' . $decision,
    ]]];
    booking_process_stripe_event($db, $paid);
    $reject(static fn() => booking_review_paid($db, $ref, 90, 'David', true), 'Rush cannot pass normal review without explicit approval.');
    if ($decision === 'approve') {
        booking_review_paid($db, $ref, 90, 'David', true, 'approve');
        $approved = booking_get($db, $ref);
        $assert($approved['rush_status'] === 'approved' && (int)$approved['rush_fee_cents'] === 5900, 'Explicit approval records exactly $59.');
        $assert(booking_remaining_cents($approved) === $rushSubmission['quote']['totalCents'] - $deposit + 5900, 'Only approved rush adds to remaining balance.');
        $reject(static fn() => booking_review_paid($db, $ref, 90, 'David', true, 'approve'), 'Repeat approval cannot add fee twice.');
        $reject(static fn() => booking_decline_rush($db, $ref, 'Unavailable'), 'Stale decline cannot overwrite approval.');
    } else {
        booking_decline_rush($db, $ref, 'Requested rush window is unavailable.');
        $declined = booking_get($db, $ref);
        $assert($declined['reschedule_required'] == 1 && (int)$declined['rush_fee_cents'] === 0, 'Declined rush requires new window with zero fee.');
        $reject(static fn() => booking_review_paid($db, $ref, 90, 'David', true, 'approve'), 'Declined window cannot be approved by a stale form.');
        $reject(static fn() => booking_request_new_window($db, $ref, str_repeat('0',64), $at72, $serverNow), 'Rescheduling requires bearer authorization.');
        $reject(static fn() => booking_request_new_window($db, $ref, $rushToken, $at12, $rushNow), 'Replacement standard window must meet 72 hours.');
        $replacementToken = booking_reschedule_link($db, $ref);
        $assert(booking_agent_record($db, $ref, $rushToken) === false, 'Replacement link revokes lost bearer token.');
        booking_request_new_window($db, $ref, $replacementToken, $at72 + ['rushRequested'=>true], $serverNow);
        $rescheduled = booking_get($db, $ref);
        $assert($rescheduled['status'] === 'deposit_paid_test' && (int)$rescheduled['deposit_cents'] === $deposit, 'Replacement keeps the original paid deposit.');
        $assert($rescheduled['request_json'] === $initial['request_json'], 'Original submitted request remains immutable.');
        $effective = booking_request($rescheduled);
        $assert($effective['appointment']['date'] === '2026-09-28' && !$effective['appointment']['rushRequested'], 'Replacement is a standard request even with a tampered rush flag.');
        $assert(str_contains($effective['salesPlain'], '2026-09-28'), 'Staff summary uses replacement window.');
        $reject(static fn() => booking_request_new_window($db, $ref, $replacementToken, $at72, $serverNow), 'Repeated replacement cannot overwrite the pending request.');
        booking_review_paid($db, $ref, 90, 'David', true);
        $assert((int)booking_get($db, $ref)['rush_fee_cents'] === 0, 'Standard review after declined rush still has no fee.');
    }
    $assert(booking_process_stripe_event($db, $paid) === 'duplicate', 'Repeat webhook preserves rush decision.');
}

unset($db);
foreach (glob($temp . '/*') ?: [] as $file) unlink($file);
rmdir($temp);
echo "Booking flow: $checks assertions passed.\n";


