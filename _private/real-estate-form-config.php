<?php
declare(strict_types=1);

/**
 * SiteSee Real Estate quote-request delivery configuration.
 *
 * Keep this directory outside the public document root. The variable names are
 * intentionally compatible with the corporate SiteSee mail configuration.
 */

define('SITESEE_REAL_ESTATE_SALES_EMAIL', getenv('SITESEE_REAL_ESTATE_SALES_EMAIL') ?: 'sales@sitesee.ai');
define('SITESEE_FROM_EMAIL', getenv('SITESEE_FROM_EMAIL') ?: 'sales@sitesee.ai');
define('SITESEE_SMTP_HOST', getenv('SITESEE_SMTP_HOST') ?: '');
define('SITESEE_SMTP_PORT', (int)(getenv('SITESEE_SMTP_PORT') ?: 587));
define('SITESEE_SMTP_USERNAME', getenv('SITESEE_SMTP_USERNAME') ?: '');
define('SITESEE_SMTP_PASSWORD', getenv('SITESEE_SMTP_PASSWORD') ?: '');
define('SITESEE_SMTP_ENCRYPTION', strtolower(getenv('SITESEE_SMTP_ENCRYPTION') ?: 'tls'));
define('SITESEE_TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
define('SITESEE_REAL_ESTATE_TURNSTILE_HOSTNAME', 'realestate.sitesee.ai');
if (!defined('SITESEE_REAL_ESTATE_TURNSTILE_CONFIG_PATH')) {
    define('SITESEE_REAL_ESTATE_TURNSTILE_CONFIG_PATH', '/home/sitesee/.sitesee-audit-guard/config.json');
}
if (!defined('SITESEE_REAL_ESTATE_TURNSTILE_RATE_FILE')) {
    define('SITESEE_REAL_ESTATE_TURNSTILE_RATE_FILE', __DIR__ . '/real-estate-turnstile-rate.json');
}
define('SITESEE_REAL_ESTATE_RATE_DIR', __DIR__ . '/real-estate-rate');
define('SITESEE_REAL_ESTATE_PRICING_ACCESS_HOURS', 36);
define('SITESEE_REAL_ESTATE_PRICING_APPROVAL_HOURS', 168);
define('SITESEE_REAL_ESTATE_PRICING_SESSION_HOURS', 12);
define('SITESEE_REAL_ESTATE_PRICING_LEAD_LOG', __DIR__ . '/real-estate-pricing-leads.ndjson');
define('SITESEE_REAL_ESTATE_PRICING_MAIL_LOG', __DIR__ . '/real-estate-pricing-mail.ndjson');

$realEstateSiteUrl = rtrim((string)getenv('SITESEE_REAL_ESTATE_SITE_URL'), '/');
if (!preg_match('~^https://[A-Za-z0-9.-]+(?::\d+)?$~', $realEstateSiteUrl)) {
    error_log('SiteSee Real Estate pricing gate is unavailable: SITESEE_REAL_ESTATE_SITE_URL is not configured.');
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Pricing access is temporarily unavailable.');
}
define('SITESEE_REAL_ESTATE_SITE_URL', $realEstateSiteUrl);
unset($realEstateSiteUrl);

$realEstatePricingSecret = getenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET');
if (!is_string($realEstatePricingSecret) || strlen($realEstatePricingSecret) < 32) {
    error_log('SiteSee Real Estate pricing gate is unavailable: SITESEE_REAL_ESTATE_PRICING_GATE_SECRET is not configured.');
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Pricing access is temporarily unavailable.');
}
define('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET', $realEstatePricingSecret);
unset($realEstatePricingSecret);

function real_estate_same_origin(): bool
{
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
        return false;
    }

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }

    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return $originHost !== '' && $requestHost !== '' && hash_equals($requestHost, $originHost);
}

function real_estate_turnstile_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $raw = @file_get_contents(SITESEE_REAL_ESTATE_TURNSTILE_CONFIG_PATH);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('SiteSee Audit configuration is unavailable.');
    }
    $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)
        || !is_string($decoded['secret'] ?? null)
        || !is_string($decoded['salt'] ?? null)
        || strlen($decoded['secret']) < 10
        || strlen($decoded['salt']) < 16
    ) {
        throw new RuntimeException('SiteSee Audit configuration is invalid.');
    }
    $config = $decoded;
    return $config;
}

function real_estate_turnstile_rate(array $limits, int $now): bool
{
    $handle = @fopen(SITESEE_REAL_ESTATE_TURNSTILE_RATE_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Turnstile rate storage is unavailable.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Turnstile rate lock is unavailable.');
        }
        $raw = stream_get_contents($handle, 4194305);
        if ($raw === false || strlen($raw) > 4194304) {
            throw new RuntimeException('Turnstile rate storage is invalid.');
        }
        $state = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new RuntimeException('Turnstile rate state is invalid.');
        }
        foreach ($state as $key => $times) {
            if (!is_array($times)) {
                throw new RuntimeException('Turnstile rate record is invalid.');
            }
            $state[$key] = array_values(array_filter(
                $times,
                static fn(mixed $time): bool => is_int($time) && $time > $now - 3600
            ));
            if (!$state[$key]) {
                unset($state[$key]);
            }
        }
        $allowed = true;
        foreach ($limits as [$key, $maximum, $window]) {
            $recent = array_filter($state[$key] ?? [], static fn(int $time): bool => $time > $now - $window);
            if (count($recent) >= $maximum) {
                $allowed = false;
            }
        }
        if ($allowed) {
            foreach ($limits as [$key, $maximum, $window]) {
                $state[$key][] = $now;
            }
        }
        if (count($state) > 20000) {
            throw new RuntimeException('Turnstile rate capacity was reached.');
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4194304) {
            throw new RuntimeException('Turnstile rate capacity was reached.');
        }
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Turnstile rate update failed.');
        }
        @chmod(SITESEE_REAL_ESTATE_TURNSTILE_RATE_FILE, 0600);
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function real_estate_turnstile_key(string $kind, string $value): string
{
    return hash_hmac('sha256', $kind . ':' . $value, real_estate_turnstile_config()['salt']);
}

function real_estate_form_delivery_allowed(string $action, string $ip, string $email): bool
{
    return real_estate_turnstile_rate([
        [real_estate_turnstile_key($action . ':delivery-ip', $ip), 6, 3600],
        [real_estate_turnstile_key($action . ':delivery-email', strtolower(trim($email))), 3, 3600],
    ], time());
}

/**
 * Validate one Cloudflare Turnstile token using the Corporate SiteSee Audit
 * configuration stored outside every public document root.
 *
 * @return array{ok:bool,code:string}
 */
function real_estate_verify_turnstile(
    string $token,
    string $ip,
    string $expectedAction,
    ?callable $transport = null
): array {
    if (!in_array($expectedAction, ['real_estate_pricing', 'real_estate_contact'], true)) {
        return ['ok'=>false, 'code'=>'configuration-error'];
    }
    $token = trim($token);
    if ($token === '' || strlen($token) > 2048) {
        return ['ok'=>false, 'code'=>'missing-or-invalid-token'];
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['ok'=>false, 'code'=>'configuration-error'];
    }

    try {
        $config = real_estate_turnstile_config();
        if (!real_estate_turnstile_rate([
            [real_estate_turnstile_key('real-estate:attempt-ip', $ip), 30, 600],
        ], time())) {
            return ['ok'=>false, 'code'=>'attempt-rate-limit'];
        }
        $fields = ['secret'=>$config['secret'], 'response'=>$token, 'remoteip'=>$ip];

        if ($transport !== null) {
            $verification = $transport(SITESEE_TURNSTILE_VERIFY_URL, $fields);
        } else {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('PHP cURL is unavailable.');
            }
            $curl = curl_init(SITESEE_TURNSTILE_VERIFY_URL);
            if ($curl === false) {
                throw new RuntimeException('Turnstile verification is unavailable.');
            }
            $body = '';
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 16384) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            try {
                $sent = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            } finally {
                curl_close($curl);
            }
            if ($sent === false || $status !== 200) {
                throw new RuntimeException('Turnstile verification is unavailable.');
            }
            $verification = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        }
    } catch (Throwable $error) {
        error_log('SiteSee Real Estate Turnstile verification unavailable.');
        return ['ok'=>false, 'code'=>'verification-unavailable'];
    }

    if (!is_array($verification) || ($verification['success'] ?? false) !== true) {
        return ['ok'=>false, 'code'=>'challenge-failed'];
    }
    if (!hash_equals($expectedAction, (string)($verification['action'] ?? ''))) {
        return ['ok'=>false, 'code'=>'action-mismatch'];
    }

    $verifiedHost = strtolower((string)($verification['hostname'] ?? ''));
    if ($verifiedHost === '' || !hash_equals(SITESEE_REAL_ESTATE_TURNSTILE_HOSTNAME, $verifiedHost)) {
        return ['ok'=>false, 'code'=>'hostname-mismatch'];
    }

    return ['ok'=>true, 'code'=>'verified'];
}

function real_estate_rate_allowed(string $ip, string $email): bool
{
    $dir = SITESEE_REAL_ESTATE_RATE_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    $key = hash('sha256', $ip . '|' . strtolower($email));
    $file = $dir . '/' . $key . '.json';
    $rate = ['start' => time(), 'count' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $rate = $decoded;
        }
    }
    if (time() - (int)($rate['start'] ?? 0) > 3600) {
        $rate = ['start' => time(), 'count' => 0];
    }

    $rate['count'] = (int)($rate['count'] ?? 0) + 1;
    if (@file_put_contents($file, json_encode($rate), LOCK_EX) === false) {
        return false;
    }
    @chmod($file, 0600);
    return $rate['count'] <= 8;
}

function real_estate_pricing_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('sitesee_real_estate_pricing');
    session_set_cookie_params([
        'lifetime' => SITESEE_REAL_ESTATE_PRICING_SESSION_HOURS * 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function real_estate_pricing_has_access(): bool
{
    real_estate_pricing_start_session();
    return !empty($_SESSION['real_estate_pricing_email'])
        && !empty($_SESSION['real_estate_pricing_expires'])
        && (int)$_SESSION['real_estate_pricing_expires'] >= time();
}

function real_estate_pricing_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function real_estate_pricing_base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function real_estate_pricing_signed_token(array $payload): string
{
    $encoded = real_estate_pricing_base64url_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = real_estate_pricing_base64url_encode(
        hash_hmac('sha256', $encoded, SITESEE_REAL_ESTATE_PRICING_GATE_SECRET, true)
    );
    return $encoded . '.' . $signature;
}

function real_estate_pricing_validate_signed_token(string $token, string $purpose): array|false
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }
    [$encoded, $signature] = $parts;
    $expected = real_estate_pricing_base64url_encode(
        hash_hmac('sha256', $encoded, SITESEE_REAL_ESTATE_PRICING_GATE_SECRET, true)
    );
    if (!hash_equals($expected, $signature)) {
        return false;
    }
    $decoded = real_estate_pricing_base64url_decode($encoded);
    if ($decoded === false) {
        return false;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload) || ($payload['purpose'] ?? '') !== $purpose || empty($payload['exp'])) {
        return false;
    }
    if ((int)$payload['exp'] < time()) {
        return false;
    }
    return $payload;
}

function real_estate_pricing_create_access_token(string $email): string
{
    return real_estate_pricing_signed_token([
        'purpose' => 'real_estate_pricing_access',
        'email' => strtolower($email),
        'exp' => time() + SITESEE_REAL_ESTATE_PRICING_ACCESS_HOURS * 3600,
        'nonce' => bin2hex(random_bytes(12)),
    ]);
}

function real_estate_pricing_validate_access_token(string $token): array|false
{
    $payload = real_estate_pricing_validate_signed_token($token, 'real_estate_pricing_access');
    if (!$payload || !filter_var((string)($payload['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return $payload;
}

function real_estate_pricing_create_approval_token(string $leadId): string
{
    return real_estate_pricing_signed_token([
        'purpose' => 'real_estate_pricing_approval',
        'lead_id' => $leadId,
        'exp' => time() + SITESEE_REAL_ESTATE_PRICING_APPROVAL_HOURS * 3600,
        'nonce' => bin2hex(random_bytes(12)),
    ]);
}

function real_estate_pricing_validate_approval_token(string $token): array|false
{
    $payload = real_estate_pricing_validate_signed_token($token, 'real_estate_pricing_approval');
    if (!$payload || !preg_match('/^[a-f0-9]{32}$/', (string)($payload['lead_id'] ?? ''))) {
        return false;
    }
    return $payload;
}

function real_estate_pricing_pending_dir(): string
{
    $dir = __DIR__ . '/real-estate-pricing-pending';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function real_estate_pricing_save_lead(string $leadId, array $lead): bool
{
    if (!preg_match('/^[a-f0-9]{32}$/', $leadId)) {
        return false;
    }
    $file = real_estate_pricing_pending_dir() . '/' . $leadId . '.json';
    $saved = @file_put_contents(
        $file,
        json_encode($lead, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
    if ($saved === false) {
        return false;
    }
    @chmod($file, 0600);
    return true;
}

function real_estate_pricing_load_lead(string $leadId): array|false
{
    if (!preg_match('/^[a-f0-9]{32}$/', $leadId)) {
        return false;
    }
    $file = real_estate_pricing_pending_dir() . '/' . $leadId . '.json';
    if (!is_file($file)) {
        return false;
    }
    $decoded = json_decode((string)file_get_contents($file), true);
    return is_array($decoded) ? $decoded : false;
}

function real_estate_pricing_log(string $file, array $record): void
{
    $record['logged_at'] = gmdate('c');
    @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function real_estate_smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 8192)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function real_estate_smtp_command($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    $response = real_estate_smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP command failed: ' . trim($response));
    }
    return $response;
}

function real_estate_send_via_smtp(string $to, string $subject, array $headers, string $body): bool
{
    $transport = SITESEE_SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . SITESEE_SMTP_HOST . ':' . SITESEE_SMTP_PORT,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
    }
    stream_set_timeout($socket, 20);
    $greeting = real_estate_smtp_read($socket);
    if ((int)substr($greeting, 0, 3) !== 220) {
        throw new RuntimeException('SMTP greeting failed: ' . trim($greeting));
    }

    $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'sitesee.ai')) ?: 'sitesee.ai';
    real_estate_smtp_command($socket, 'EHLO ' . $hostname, [250]);
    if (SITESEE_SMTP_ENCRYPTION === 'tls') {
        real_estate_smtp_command($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP TLS negotiation failed.');
        }
        real_estate_smtp_command($socket, 'EHLO ' . $hostname, [250]);
    }
    if (SITESEE_SMTP_USERNAME !== '') {
        real_estate_smtp_command($socket, 'AUTH LOGIN', [334]);
        real_estate_smtp_command($socket, base64_encode(SITESEE_SMTP_USERNAME), [334]);
        real_estate_smtp_command($socket, base64_encode(SITESEE_SMTP_PASSWORD), [235]);
    }

    real_estate_smtp_command($socket, 'MAIL FROM:<' . SITESEE_FROM_EMAIL . '>', [250]);
    real_estate_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    real_estate_smtp_command($socket, 'DATA', [354]);
    $messageHeaders = array_merge([
        'To: ' . $to,
        'Subject: ' . $subject,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@sitesee.ai>',
    ], $headers);
    $message = implode("\r\n", $messageHeaders) . "\r\n\r\n" . $body;
    $message = (string)preg_replace('/(?m)^\./', '..', $message);
    fwrite($socket, $message . "\r\n.\r\n");
    $response = real_estate_smtp_read($socket);
    if ((int)substr($response, 0, 3) !== 250) {
        throw new RuntimeException('SMTP message rejected: ' . trim($response));
    }
    real_estate_smtp_command($socket, 'QUIT', [221]);
    fclose($socket);
    return true;
}

function real_estate_send_mail(string $to, string $replyTo, string $subject, string $html, string $plain): bool
{
    foreach ([$to, $replyTo, SITESEE_FROM_EMAIL] as $address) {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
            return false;
        }
    }
    if (preg_match('/[\r\n]/', $subject)) {
        return false;
    }

    $boundary = 'real_estate_' . bin2hex(random_bytes(10));
    $headers = [
        'From: SiteSee Real Estate <' . SITESEE_FROM_EMAIL . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: SiteSee-Real-Estate-Quote/1.0',
    ];
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$plain}\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n";
    $body .= "--{$boundary}--\r\n";

    try {
        if (SITESEE_SMTP_HOST !== '') {
            $sent = real_estate_send_via_smtp($to, $subject, $headers, $body);
            $method = 'smtp';
        } else {
            $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
            $method = 'mail';
        }
        real_estate_pricing_log(SITESEE_REAL_ESTATE_PRICING_MAIL_LOG, [
            'to' => $to,
            'subject' => $subject,
            'sent' => $sent,
            'method' => $method,
        ]);
        return $sent;
    } catch (Throwable $error) {
        real_estate_pricing_log(SITESEE_REAL_ESTATE_PRICING_MAIL_LOG, [
            'to' => $to,
            'subject' => $subject,
            'sent' => false,
            'method' => SITESEE_SMTP_HOST !== '' ? 'smtp' : 'mail',
            'error' => $error->getMessage(),
        ]);
        return false;
    }
}
