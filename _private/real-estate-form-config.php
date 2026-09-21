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
define('SITESEE_REAL_ESTATE_RATE_DIR', __DIR__ . '/real-estate-rate');

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
            return real_estate_send_via_smtp($to, $subject, $headers, $body);
        }
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    } catch (Throwable $error) {
        error_log('SiteSee Real Estate quote mail failure: ' . $error->getMessage());
        return false;
    }
}

