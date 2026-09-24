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
$assert(booking_get($db, $reference)['consent_version'] === 'test-card-reuse-v1', 'Consent version is recorded.');

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

unset($db);
foreach (glob($temp . '/*') ?: [] as $file) unlink($file);
rmdir($temp);
echo "Booking flow: $checks assertions passed.\n";
