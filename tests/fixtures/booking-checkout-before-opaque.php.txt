<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-store.php';

// Request-scoped version: never changes the Stripe account or webhook version.
const BOOKING_CHECKOUT_API_VERSION = '2026-08-26.dahlia';

function booking_checkout_config(): array
{
    $path = dirname(__DIR__) . '/booking-checkout.json';
    if (!is_file($path)) return ['enabled'=>false, 'publishable_key'=>''];
    if (is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 4096) {
        throw new RuntimeException('Payment page configuration needs attention.');
    }
    $config = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($config) || ($config['stage'] ?? '') !== 'TEST'
        || !is_bool($config['enabled'] ?? null)
        || !preg_match('/^pk_test_[A-Za-z0-9]{12,512}$/D', (string)($config['publishable_key'] ?? ''))) {
        throw new RuntimeException('Expected a Stripe test publishable key.');
    }
    return $config;
}

function booking_checkout_schema(PDO $db): void
{
    // No client secrets or card details are stored in this table.
    $db->exec('CREATE TABLE IF NOT EXISTS booking_checkout_ui (
        reference TEXT PRIMARY KEY, attempt INTEGER NOT NULL, api_version TEXT NOT NULL
    )');
}

/** Validate every retrieved/created session against the authenticated booking. */
function booking_checkout_validate(array $session, array $row, ?string $id = null): string
{
    if (!preg_match('/^cs_test_[A-Za-z0-9_]+$/D', (string)($session['id'] ?? ''))
        || ($id !== null && !hash_equals($id, (string)$session['id']))
        || ($session['livemode'] ?? null) !== false || ($session['mode'] ?? '') !== 'payment'
        || !in_array($session['ui_mode'] ?? '', ['embedded_page', 'embedded'], true)
        || ($session['client_reference_id'] ?? '') !== $row['reference']
        || ($session['metadata']['booking_reference'] ?? '') !== $row['reference']
        || ($session['currency'] ?? '') !== 'usd'
        || !is_int($session['amount_total'] ?? null)
        || $session['amount_total'] !== (int)$row['deposit_cents']
        || !in_array($session['status'] ?? '', ['open','complete','expired'], true)) {
        throw new RuntimeException('Stripe session did not match the booking.');
    }
    return $session['status'];
}

function booking_checkout_result(array $session, array $row, ?string $id = null): array
{
    $state = booking_checkout_validate($session, $row, $id);
    if ($state !== 'open') return ['mode'=>$state === 'complete' ? 'pending' : 'expired'];
    $secret = (string)($session['client_secret'] ?? '');
    if (!preg_match('/^' . preg_quote($session['id'], '/') . '_secret_[A-Za-z0-9]+$/D', $secret)) {
        throw new RuntimeException('Stripe did not return a valid embedded session.');
    }
    return ['mode'=>'embedded', 'clientSecret'=>$secret];
}

/** Uses the existing booking ledger and never opens a second payable session. */
function booking_checkout_start(PDO $db, string $reference, string $token, string $ip, ?callable $transport = null, ?callable $hostedTransport = null): array
{
    if (!booking_test_enabled()) throw new RuntimeException('Test bookings are disabled.');
    $key = booking_test_key();
    booking_checkout_schema($db);
    $call = $transport ?? 'booking_checkout_request';
    $db->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;
    try {
        $row = booking_agent_record($db, $reference, $token);
        if (!$row) throw new InvalidArgumentException('This payment link is invalid or expired.');
        if ($row['status'] === 'deposit_paid_test') {
            $db->exec('COMMIT'); $transactionOpen = false;
            return ['mode'=>'paid'];
        }
        $stmt = $db->prepare('SELECT * FROM booking_checkout_ui WHERE reference=?');
        $stmt->execute([$reference]);
        $ui = $stmt->fetch();
        $owned = $ui && (int)$ui['attempt'] === (int)$row['checkout_attempt'];
        // Preserve previously issued hosted links and ambiguous hosted requests.
        if (in_array($row['checkout_state'], ['open','creating'], true) && !$owned) {
            $db->exec('COMMIT'); $transactionOpen = false;
            return ['mode'=>'hosted', 'url'=>booking_start_checkout($db, $reference, $token, $ip, $hostedTransport)];
        }
        if ($row['checkout_state'] === 'open') {
            $id = (string)$row['stripe_session_id'];
            $db->exec('COMMIT'); $transactionOpen = false;
            $session = $call('GET', $id, [], '', $key);
            $result = booking_checkout_result($session, $row, $id);
            if ($result['mode'] === 'expired') {
                // Only a verified expiry of this exact active session permits replacement.
                $db->prepare("UPDATE bookings SET checkout_state='expired', stripe_checkout_url=NULL
                    WHERE reference=? AND stripe_session_id=? AND checkout_state='open'
                    AND status IN ('awaiting_deposit_test','approved_test')")->execute([$reference,$id]);
            }
            return $result;
        }
        if ($row['checkout_state'] === 'creating' && time() - (int)$row['checkout_started'] < 120) {
            throw new RuntimeException('Payment form is being prepared. Please try again shortly.');
        }
        if (!in_array($row['checkout_state'], ['ready','expired','creating'], true)) {
            throw new RuntimeException('This booking cannot open another payment session.');
        }
        $attempt = $row['checkout_state'] === 'expired'
            ? (int)$row['checkout_attempt'] + 1 : max(1, (int)$row['checkout_attempt']);
        $db->prepare("UPDATE bookings SET checkout_state='creating', checkout_attempt=?, checkout_started=?,
            consent_at=?, consent_version=?, consent_ip_hash=? WHERE reference=?")
            ->execute([$attempt,time(),gmdate('c'),BOOKING_CONSENT_VERSION,hash('sha256',$ip),$reference]);
        $db->prepare('INSERT OR REPLACE INTO booking_checkout_ui (reference,attempt,api_version) VALUES (?,?,?)')
            ->execute([$reference,$attempt,BOOKING_CHECKOUT_API_VERSION]);
        $db->exec('COMMIT'); $transactionOpen = false;
    } catch (Throwable $error) {
        if ($transactionOpen) $db->exec('ROLLBACK');
        throw $error;
    }
    $body = [
        'mode'=>'payment', 'ui_mode'=>'embedded_page', 'payment_method_types[0]'=>'card',
        'customer_creation'=>'always', 'customer_email'=>$row['email'],
        'client_reference_id'=>$reference, 'metadata[booking_reference]'=>$reference,
        'line_items[0][price_data][currency]'=>'usd',
        'line_items[0][price_data][unit_amount]'=>(string)$row['deposit_cents'],
        'line_items[0][price_data][product_data][name]'=>'SiteSee ' . ucfirst($row['market']) . ' shoot deposit',
        'line_items[0][quantity]'=>'1',
        'payment_intent_data[setup_future_usage]'=>'off_session',
        'payment_intent_data[metadata][booking_reference]'=>$reference,
        'return_url'=>SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?result=return&reference=' . rawurlencode($reference),
        'redirect_on_completion'=>'always',
        'branding_settings[display_name]'=>'SiteSee',
        'branding_settings[font_family]'=>'inter',
        'branding_settings[border_style]'=>'rectangular',
        'branding_settings[background_color]'=>'#ffffff',
        'branding_settings[button_color]'=>'#ffc107',
    ];
    try {
        $session = $call('POST', '', $body, 'sitesee-embedded-test-' . $reference . '-' . $attempt, $key);
        $result = booking_checkout_result($session, $row);
        $stmt = $db->prepare("UPDATE bookings SET stripe_session_id=?, stripe_checkout_url=NULL, checkout_state='open'
            WHERE reference=? AND checkout_state='creating' AND checkout_attempt=?");
        $stmt->execute([$session['id'],$reference,$attempt]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Booking changed while preparing payment.');
        if ($result['mode'] === 'expired') {
            $db->prepare("UPDATE bookings SET checkout_state='expired' WHERE reference=? AND stripe_session_id=? AND checkout_state='open'")
                ->execute([$reference,$session['id']]);
        }
        return $result;
    } catch (Throwable $error) {
        $db->prepare("UPDATE bookings SET checkout_started=? WHERE reference=? AND checkout_state='creating' AND checkout_attempt=?")
            ->execute([time()-121,$reference,$attempt]);
        throw $error;
    }
}

/** Read-only return lookup. The signed webhook remains the payment authority. */
function booking_checkout_return(PDO $db, array $row, ?callable $transport = null): string
{
    if (!booking_test_enabled()) throw new RuntimeException('Test bookings are disabled.');
    if ($row['status'] === 'deposit_paid_test') return 'paid';
    if ($row['checkout_state'] === 'expired') return 'expired';
    if ($row['checkout_state'] !== 'open' || !$row['stripe_session_id']) return 'pending';
    $call = $transport ?? 'booking_checkout_request';
    $session = $call('GET', $row['stripe_session_id'], [], '', booking_test_key());
    return booking_checkout_validate($session, $row, $row['stripe_session_id']);
}

/** No provider bodies, client secrets, API keys or payment details are logged. */
function booking_checkout_request(string $method, string $id, array $body, string $idempotency, string $key): array
{
    if (!preg_match('/^sk_test_[A-Za-z0-9_]{12,}$/D',$key)
        || !in_array($method,['GET','POST'],true)
        || ($method === 'GET' && !preg_match('/^cs_test_[A-Za-z0-9_]+$/D',$id))) {
        throw new RuntimeException('Invalid Stripe test request.');
    }
    $curl = curl_init('https://api.stripe.com/v1/checkout/sessions' . ($method === 'GET' ? '/' . rawurlencode($id) : ''));
    if ($curl === false) throw new RuntimeException('Stripe is unavailable.');
    $headers = ['Authorization: Bearer '.$key, 'Stripe-Version: '.BOOKING_CHECKOUT_API_VERSION];
    if ($method === 'POST') {
        $headers[] = 'Idempotency-Key: '.$idempotency;
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($curl,CURLOPT_POST,true);
        curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($body,'','&',PHP_QUERY_RFC3986));
    }
    $response = '';
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function ($curl,string $chunk) use (&$response): int {
            if (strlen($response)+strlen($chunk)>131072) return 0;
            $response.=$chunk;return strlen($chunk);
        }]);
    try {
        $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    } finally { curl_close($curl); }
    if ($ok === false || $status !== 200) throw new RuntimeException('Stripe payment form is temporarily unavailable.');
    $data=json_decode($response,true,24,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Unexpected Stripe response.');
    return $data;
}
