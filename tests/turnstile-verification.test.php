<?php
declare(strict_types=1);

putenv('SITESEE_REAL_ESTATE_SITE_URL=https://realestate.example.test');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=0123456789abcdef0123456789abcdef');
putenv('SITESEE_TURNSTILE_SITE_KEY=1x00000000000000000000AA');
putenv('SITESEE_TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA');

require dirname(__DIR__) . '/_private/real-estate-form-config.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$pass = static fn(string $url, array $fields): array => [
    'success' => true,
    'hostname' => 'realestate.example.test',
    'action' => 'contact_inquiry',
];
$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'contact_inquiry', $pass);
$assert($result['ok'] === true, 'valid token response should pass');

$result = real_estate_verify_turnstile('', '127.0.0.1', 'contact_inquiry', $pass);
$assert($result['ok'] === false && $result['code'] === 'missing-or-invalid-token', 'missing token should fail');

$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'pricing_access', $pass);
$assert($result['ok'] === false && $result['code'] === 'action-mismatch', 'wrong widget action should fail');

$wrongHost = static fn(string $url, array $fields): array => [
    'success' => true,
    'hostname' => 'attacker.example.test',
    'action' => 'contact_inquiry',
];
$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'contact_inquiry', $wrongHost);
$assert($result['ok'] === false && $result['code'] === 'hostname-mismatch', 'wrong hostname should fail');

$providerRejects = static fn(string $url, array $fields): array => [
    'success' => false,
    'error-codes' => ['invalid-input-response'],
];
$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'contact_inquiry', $providerRejects);
$assert($result['ok'] === false && $result['code'] === 'challenge-failed', 'provider rejection should fail');

echo "Turnstile verification tests passed ({$checks} checks).\n";

