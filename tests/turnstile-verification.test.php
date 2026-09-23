<?php
declare(strict_types=1);

$testDir = sys_get_temp_dir() . '/sitesee-turnstile-' . bin2hex(random_bytes(6));
if (!mkdir($testDir, 0700, true)) {
    throw new RuntimeException('Could not create the Turnstile test directory.');
}
$configPath = $testDir . '/config.json';
$ratePath = $testDir . '/rate.json';
file_put_contents($configPath, json_encode([
    'secret' => '1x0000000000000000000000000000000AA',
    'salt' => '0123456789abcdef0123456789abcdef',
], JSON_THROW_ON_ERROR));
define('SITESEE_REAL_ESTATE_TURNSTILE_CONFIG_PATH', $configPath);
define('SITESEE_REAL_ESTATE_TURNSTILE_RATE_FILE', $ratePath);

putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=0123456789abcdef0123456789abcdef');

require dirname(__DIR__) . '/_private/real-estate-form-config.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$verifiedFields = [];
$pass = static function (string $url, array $fields) use (&$verifiedFields): array {
    $verifiedFields = $fields;
    return [
        'success' => true,
        'hostname' => 're.sitesee.ai',
        'action' => 'real_estate_contact',
    ];
};
$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'real_estate_contact', $pass);
$assert($result['ok'] === true, 'valid token response should pass');
$assert(($verifiedFields['remoteip'] ?? '') === '127.0.0.1', 'validated client IP should be sent to Siteverify');

$result = real_estate_verify_turnstile('', '127.0.0.1', 'real_estate_contact', $pass);
$assert($result['ok'] === false && $result['code'] === 'missing-or-invalid-token', 'missing token should fail');

$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'real_estate_pricing', $pass);
$assert($result['ok'] === false && $result['code'] === 'action-mismatch', 'wrong widget action should fail');

$wrongHost = static fn(string $url, array $fields): array => [
    'success' => true,
    'hostname' => 'attacker.example.test',
    'action' => 'real_estate_contact',
];
$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'real_estate_contact', $wrongHost);
$assert($result['ok'] === false && $result['code'] === 'hostname-mismatch', 'wrong hostname should fail');

$providerRejects = static fn(string $url, array $fields): array => [
    'success' => false,
    'error-codes' => ['invalid-input-response'],
];
$result = real_estate_verify_turnstile('test-token', '127.0.0.1', 'real_estate_contact', $providerRejects);
$assert($result['ok'] === false && $result['code'] === 'challenge-failed', 'provider rejection should fail');

$now = time();
for ($i = 0; $i < 6; $i++) {
    $assert(real_estate_turnstile_rate([
        [real_estate_turnstile_key('real_estate_contact:delivery-ip', '192.0.2.1'), 6, 3600],
        [real_estate_turnstile_key('real_estate_contact:delivery-email', "person{$i}@example.com"), 3, 3600],
    ], $now), 'contact IP allowance should accept request ' . ($i + 1));
}
$assert(!real_estate_turnstile_rate([
    [real_estate_turnstile_key('real_estate_contact:delivery-ip', '192.0.2.1'), 6, 3600],
    [real_estate_turnstile_key('real_estate_contact:delivery-email', 'new@example.com'), 3, 3600],
], $now), 'changing email must not reset the contact IP allowance');

for ($i = 0; $i < 3; $i++) {
    $assert(real_estate_turnstile_rate([
        [real_estate_turnstile_key('real_estate_pricing:delivery-ip', '192.0.2.' . ($i + 10)), 6, 3600],
        [real_estate_turnstile_key('real_estate_pricing:delivery-email', 'shared@example.com'), 3, 3600],
    ], $now), 'pricing email allowance should accept request ' . ($i + 1));
}
$assert(!real_estate_turnstile_rate([
    [real_estate_turnstile_key('real_estate_pricing:delivery-ip', '192.0.2.20'), 6, 3600],
    [real_estate_turnstile_key('real_estate_pricing:delivery-email', strtolower('SHARED@example.com')), 3, 3600],
], $now), 'changing IP must not reset the pricing email allowance');

$assert(real_estate_turnstile_rate([
    [real_estate_turnstile_key('real_estate_contact:delivery-ip', '192.0.2.21'), 6, 3600],
    [real_estate_turnstile_key('real_estate_contact:delivery-email', 'shared@example.com'), 3, 3600],
], $now), 'pricing and contact limits must remain isolated');

@unlink($ratePath);
@unlink($configPath);
@rmdir($testDir);
echo "Corporate-pattern Turnstile tests passed ({$checks} checks).\n";
