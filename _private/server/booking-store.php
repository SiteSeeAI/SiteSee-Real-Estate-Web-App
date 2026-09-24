<?php
declare(strict_types=1);

/** Private, test-only booking ledger. Never serve this file or its SQLite database. */
require_once dirname(__DIR__) . '/real-estate-form-config.php';
require_once dirname(__DIR__) . '/real-estate-pricing.php';

function booking_test_enabled(): bool
{
    return getenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED') === '1';
}

function booking_db(): PDO
{
    $path = getenv('SITESEE_REAL_ESTATE_BOOKING_DB') ?: dirname(__DIR__) . '/data/bookings.sqlite';
    if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
        throw new RuntimeException('Booking database path must be absolute.');
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Booking database directory is unavailable.');
    }
    $privateRoot = realpath(dirname(__DIR__));
    $dataRoot = realpath($dir);
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($dataRoot === false || ($documentRoot !== false && ($dataRoot === $documentRoot || str_starts_with($dataRoot, $documentRoot . '/')))) {
        throw new RuntimeException('Booking database must remain outside the document root.');
    }
    if (getenv('SITESEE_REAL_ESTATE_BOOKING_DB') === false && !str_starts_with($dataRoot . '/', $privateRoot . '/')) {
        throw new RuntimeException('Booking database is outside the private application.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS bookings (
        reference TEXT PRIMARY KEY, created_at TEXT NOT NULL, status TEXT NOT NULL,
        market TEXT NOT NULL, email TEXT NOT NULL, request_json TEXT NOT NULL,
        requested_utc TEXT NOT NULL, quote_cents INTEGER NOT NULL,
        approved_cents INTEGER, deposit_cents INTEGER, platform_monthly_cents INTEGER NOT NULL,
        approved_at TEXT, photographer TEXT, duration_minutes INTEGER, availability_checked_at TEXT,
        price_reason TEXT, crm_contact_id TEXT, agent_token_hash TEXT, agent_token_expires INTEGER,
        consent_at TEXT, consent_version TEXT, consent_ip_hash TEXT,
        checkout_state TEXT NOT NULL DEFAULT \'ready\', checkout_attempt INTEGER NOT NULL DEFAULT 0,
        checkout_started INTEGER, stripe_session_id TEXT UNIQUE, stripe_checkout_url TEXT,
        stripe_customer_id TEXT, stripe_payment_intent_id TEXT, deposit_paid_at TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS stripe_events (event_id TEXT PRIMARY KEY, event_type TEXT NOT NULL, reference TEXT NOT NULL, processed_at TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS staff_login_attempts (ip_hash TEXT NOT NULL, at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS staff_login_ip_time ON staff_login_attempts(ip_hash, at)');
    @chmod($path, 0600);
    return $db;
}

function booking_capture(PDO $db, array $submission, string $reference): void
{
    if ($submission['action'] !== 'request_appointment' || !preg_match('/^[A-F0-9]{10,32}$/D', $reference)) {
        throw new InvalidArgumentException('Invalid booking request.');
    }
    $zone = new DateTimeZone('America/Chicago');
    $local = $submission['appointment']['date'] . ' ' . $submission['appointment']['time'];
    // Validated by real_estate_prepare_submission, including both clock-change boundaries.
    $utc = (new DateTimeImmutable($local, $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $cents = $submission['quote']['totalCents'];
    if (!is_int($cents) || $cents < 50 || $cents > 100000000) {
        throw new InvalidArgumentException('This quote requires manual review before booking.');
    }
    $stmt = $db->prepare('INSERT INTO bookings (reference,created_at,status,market,email,request_json,requested_utc,quote_cents,platform_monthly_cents)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $reference, gmdate('c'), 'pending_review', $submission['market'], $submission['details']['email'],
        json_encode($submission, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        $utc, $cents, (int)($submission['quote']['platformMonthlyCents'] ?? 0),
    ]);
}

function booking_get(PDO $db, string $reference): array|false
{
    if (!preg_match('/^[A-F0-9]{10,32}$/D', $reference)) {
        return false;
    }
    $stmt = $db->prepare('SELECT * FROM bookings WHERE reference=?');
    $stmt->execute([$reference]);
    return $stmt->fetch();
}

function booking_recent(PDO $db): array
{
    return $db->query('SELECT reference,created_at,status,market,email,quote_cents,approved_cents,deposit_paid_at
        FROM bookings ORDER BY created_at DESC LIMIT 100')->fetchAll();
}

function booking_approve(PDO $db, string $reference, int $finalCents, int $duration, string $photographer, bool $available, string $reason = '', string $contactId = ''): string
{
    $photographer = trim($photographer);
    $reason = trim($reason);
    $contactId = trim($contactId);
    if (!$available || $photographer === '' || strlen($photographer) > 120 || $duration < 15 || $duration > 1440
        || $finalCents < 50 || $finalCents > 100000000 || strlen($reason) > 500
        || ($contactId !== '' && !preg_match('/^[0-9]{1,30}$/D', $contactId))) {
        throw new InvalidArgumentException('Review the photographer, time, availability and final price.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_get($db, $reference);
        if (!$row || $row['status'] !== 'pending_review') {
            throw new InvalidArgumentException('This request has already been reviewed.');
        }
        if ($finalCents !== (int)$row['quote_cents'] && $reason === '') {
            throw new InvalidArgumentException('Explain any change to the quoted price.');
        }
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('UPDATE bookings SET status=\'approved_test\', approved_cents=?, deposit_cents=?,
            approved_at=?, photographer=?, duration_minutes=?, availability_checked_at=?, price_reason=?,
            crm_contact_id=?, agent_token_hash=?, agent_token_expires=? WHERE reference=? AND status=\'pending_review\'');
        $stmt->execute([
            $finalCents, intdiv($finalCents + 1, 2), gmdate('c'), $photographer, $duration,
            gmdate('c'), $reason, $contactId, hash('sha256', $token), time() + 7 * 86400, $reference,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The booking changed during review.');
        }
        $db->commit();
        return $token;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function booking_agent_record(PDO $db, string $reference, string $token): array|false
{
    $row = booking_get($db, $reference);
    if (!$row || !in_array($row['status'], ['approved_test', 'deposit_paid_test'], true)
        || (int)$row['agent_token_expires'] < time() || strlen($token) !== 64
        || !hash_equals((string)$row['agent_token_hash'], hash('sha256', $token))) {
        return false;
    }
    return $row;
}

function booking_rotate_test_link(PDO $db, string $reference): string
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_get($db, $reference);
        if (!$row || $row['status'] !== 'approved_test' || !in_array($row['checkout_state'], ['ready', 'expired'], true)) {
            throw new InvalidArgumentException('A test link cannot be replaced while Checkout is active or after payment.');
        }
        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE bookings SET agent_token_hash=?, agent_token_expires=? WHERE reference=?')
            ->execute([hash('sha256', $token), time() + 7 * 86400, $reference]);
        $db->commit();
        return $token;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function booking_test_key(): string
{
    $key = (string)getenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET');
    if (!preg_match('/^sk_test_[A-Za-z0-9_]{12,}$/D', $key)) {
        throw new RuntimeException('Stripe test mode is not configured. No payment link was created.');
    }
    return $key;
}

/** $transport is injectable so test runs never call Stripe. */
function booking_start_checkout(PDO $db, string $reference, string $token, string $ip, ?callable $transport = null): string
{
    if (!booking_test_enabled()) {
        throw new RuntimeException('Test bookings are disabled.');
    }
    $key = booking_test_key(); // Fail closed before changing any booking state.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_agent_record($db, $reference, $token);
        if (!$row || $row['status'] !== 'approved_test') {
            throw new InvalidArgumentException('This test payment link is invalid or has already been used.');
        }
        if ($row['checkout_state'] === 'open' && $row['stripe_checkout_url']) {
            $db->commit();
            return $row['stripe_checkout_url'];
        }
        if ($row['checkout_state'] === 'creating' && time() - (int)$row['checkout_started'] < 120) {
            throw new RuntimeException('A Checkout session is being created. Try again shortly.');
        }
        $attempt = $row['checkout_state'] === 'expired' ? (int)$row['checkout_attempt'] + 1 : max(1, (int)$row['checkout_attempt']);
        $stmt = $db->prepare('UPDATE bookings SET checkout_state=\'creating\', checkout_attempt=?, checkout_started=?,
            consent_at=?, consent_version=?, consent_ip_hash=? WHERE reference=?');
        $stmt->execute([$attempt, time(), gmdate('c'), 'test-card-reuse-v1', hash('sha256', $ip), $reference]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }

    $body = [
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'customer_creation' => 'always',
        'customer_email' => $row['email'],
        'client_reference_id' => $reference,
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string)$row['deposit_cents'],
        'line_items[0][price_data][product_data][name]' => 'SiteSee ' . ucfirst($row['market']) . ' test shoot deposit',
        'line_items[0][quantity]' => '1',
        'payment_intent_data[setup_future_usage]' => 'off_session',
        'payment_intent_data[metadata][booking_reference]' => $reference,
        'metadata[booking_reference]' => $reference,
        'success_url' => SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?result=success',
        'cancel_url' => SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?result=canceled',
    ];
    try {
        $session = $transport ? $transport($body, 'sitesee-deposit-test-' . $reference . '-' . $attempt, $key)
            : booking_stripe_create_session($body, 'sitesee-deposit-test-' . $reference . '-' . $attempt, $key);
        if (!is_array($session) || !preg_match('/^cs_test_[A-Za-z0-9_]+$/D', (string)($session['id'] ?? ''))
            || !preg_match('~^https://checkout\.stripe\.com/~', (string)($session['url'] ?? ''))
            || ($session['livemode'] ?? null) !== false) {
            throw new RuntimeException('Stripe returned an invalid test Checkout session.');
        }
        $stmt = $db->prepare('UPDATE bookings SET stripe_session_id=?, stripe_checkout_url=?, checkout_state=\'open\'
            WHERE reference=? AND checkout_state=\'creating\' AND checkout_attempt=?');
        $stmt->execute([$session['id'], $session['url'], $reference, $attempt]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Checkout state changed during Stripe setup.');
        }
        return $session['url'];
    } catch (Throwable $error) {
        // Reuse the same idempotency key on retry: Stripe may have accepted the request.
        $db->prepare('UPDATE bookings SET checkout_started=? WHERE reference=? AND checkout_state=\'creating\' AND checkout_attempt=?')
            ->execute([time() - 121, $reference, $attempt]);
        throw $error;
    }
}

function booking_stripe_create_session(array $body, string $idempotency, string $key): array
{
    $curl = curl_init('https://api.stripe.com/v1/checkout/sessions');
    if ($curl === false) {
        throw new RuntimeException('Stripe is unavailable.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Idempotency-Key: ' . $idempotency, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    try {
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($curl);
    }
    if (!is_string($response) || strlen($response) > 131072 || $status !== 200) {
        throw new RuntimeException('Stripe could not create the test Checkout session.');
    }
    return json_decode($response, true, 16, JSON_THROW_ON_ERROR);
}

function booking_verify_stripe_event(string $raw, string $signature, ?int $now = null): array
{
    $secret = (string)getenv('SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET');
    if (!preg_match('/^whsec_[A-Za-z0-9_]{12,}$/D', $secret) || $raw === '' || strlen($raw) > 1048576) {
        throw new RuntimeException('Stripe test webhook is not configured or the payload is invalid.');
    }
    $pieces = [];
    foreach (explode(',', $signature) as $part) {
        [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $pieces[$name][] = $value;
    }
    $stamp = $pieces['t'][0] ?? '';
    if (!preg_match('/^[0-9]{10,}$/D', $stamp) || abs(($now ?? time()) - (int)$stamp) > 300) {
        throw new InvalidArgumentException('Stripe webhook timestamp is invalid.');
    }
    $digest = hash_hmac('sha256', $stamp . '.' . $raw, $secret);
    $verified = false;
    foreach ($pieces['v1'] ?? [] as $candidate) {
        if (preg_match('/^[a-f0-9]{64}$/D', $candidate) && hash_equals($digest, $candidate)) {
            $verified = true;
        }
    }
    if (!$verified) {
        throw new InvalidArgumentException('Stripe webhook signature is invalid.');
    }
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($event) || ($event['livemode'] ?? null) !== false
        || !preg_match('/^evt_[A-Za-z0-9_]+$/D', (string)($event['id'] ?? ''))
        || !is_array($event['data']['object'] ?? null)) {
        throw new InvalidArgumentException('Expected a Stripe test event.');
    }
    return $event;
}

/** A paid deposit never sends an invitation. Calendar/CRM reconciliation is a later stage. */
function booking_process_stripe_event(PDO $db, array $event): string
{
    if (!booking_test_enabled()) {
        throw new RuntimeException('Test bookings are disabled.');
    }
    $type = (string)($event['type'] ?? '');
    if (!in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        return 'ignored';
    }
    $object = $event['data']['object'] ?? [];
    $reference = (string)($object['client_reference_id'] ?? '');
    if (($object['livemode'] ?? null) !== false || ($object['mode'] ?? '') !== 'payment'
        || !preg_match('/^cs_test_[A-Za-z0-9_]+$/D', (string)($object['id'] ?? ''))
        || !preg_match('/^[A-F0-9]{10,32}$/D', $reference)
        || ($object['metadata']['booking_reference'] ?? '') !== $reference) {
        throw new InvalidArgumentException('Stripe test session does not match a booking.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare('SELECT 1 FROM stripe_events WHERE event_id=?');
        $stmt->execute([$event['id']]);
        if ($stmt->fetch()) {
            $db->commit();
            return 'duplicate';
        }
        $row = booking_get($db, $reference);
        if (!$row) {
            throw new InvalidArgumentException('Booking was not found.');
        }
        if ($type === 'checkout.session.expired'
            && !hash_equals((string)$row['stripe_session_id'], (string)$object['id'])) {
            $db->prepare('INSERT INTO stripe_events (event_id,event_type,reference,processed_at) VALUES (?,?,?,?)')
                ->execute([$event['id'], $type, $reference, gmdate('c')]);
            $db->commit();
            return 'stale_expired';
        }
        if (!hash_equals((string)$row['stripe_session_id'], (string)$object['id'])) {
            throw new InvalidArgumentException('Stripe session ID does not match the active booking.');
        }
        if ($type === 'checkout.session.completed') {
            if (($object['payment_status'] ?? '') !== 'paid' || ($object['currency'] ?? '') !== 'usd'
                || (int)($object['amount_total'] ?? -1) !== (int)$row['deposit_cents']
                || !preg_match('/^pi_[A-Za-z0-9_]+$/D', (string)($object['payment_intent'] ?? ''))
                || !preg_match('/^cus_[A-Za-z0-9_]+$/D', (string)($object['customer'] ?? ''))) {
                throw new InvalidArgumentException('Stripe paid amount or payment identifiers do not match.');
            }
            if ($row['status'] === 'approved_test') {
                $stmt = $db->prepare('UPDATE bookings SET status=\'deposit_paid_test\', checkout_state=\'paid\',
                    stripe_customer_id=?, stripe_payment_intent_id=?, deposit_paid_at=? WHERE reference=? AND status=\'approved_test\'');
                $stmt->execute([$object['customer'], $object['payment_intent'], gmdate('c'), $reference]);
            } elseif ($row['status'] !== 'deposit_paid_test' || $row['stripe_payment_intent_id'] !== $object['payment_intent']) {
                throw new InvalidArgumentException('Conflicting Stripe payment for booking.');
            }
        } elseif ($row['status'] === 'approved_test') {
            $db->prepare('UPDATE bookings SET checkout_state=\'expired\', stripe_checkout_url=NULL
                WHERE reference=? AND checkout_state=\'open\'')->execute([$reference]);
        }
        $db->prepare('INSERT INTO stripe_events (event_id,event_type,reference,processed_at) VALUES (?,?,?,?)')
            ->execute([$event['id'], $type, $reference, gmdate('c')]);
        $db->commit();
        return $type === 'checkout.session.completed' ? 'deposit_paid_test' : 'expired';
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
