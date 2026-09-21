<?php
declare(strict_types=1);

putenv('SITESEE_REAL_ESTATE_SITE_URL=https://real-estate.example.test');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
$_SERVER['HTTPS'] = 'on';

require_once __DIR__ . '/../_private/real-estate-form-config.php';

$count = 0;
$assert = static function (bool $condition, string $label) use (&$count): void {
    $count++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
};

$access = real_estate_pricing_create_access_token('agent@example.com');
$accessPayload = real_estate_pricing_validate_access_token($access);
$assert(is_array($accessPayload), 'A signed pricing-access token validates.');
$assert(($accessPayload['email'] ?? '') === 'agent@example.com', 'The access token retains the approved email.');
$assert(real_estate_pricing_validate_access_token($access . 'tampered') === false, 'A modified access token is rejected.');

$leadId = str_repeat('a', 32);
$approval = real_estate_pricing_create_approval_token($leadId);
$approvalPayload = real_estate_pricing_validate_approval_token($approval);
$assert(is_array($approvalPayload), 'A signed manual-approval token validates.');
$assert(($approvalPayload['lead_id'] ?? '') === $leadId, 'The approval token retains the pending lead identifier.');

$expired = real_estate_pricing_signed_token([
    'purpose' => 'real_estate_pricing_access',
    'email' => 'agent@example.com',
    'exp' => time() - 1,
]);
$assert(real_estate_pricing_validate_access_token($expired) === false, 'An expired access token is rejected.');

real_estate_pricing_start_session();
$_SESSION['real_estate_pricing_email'] = 'agent@example.com';
$_SESSION['real_estate_pricing_expires'] = time() + 60;
$assert(real_estate_pricing_has_access(), 'A current verified session opens protected pricing.');
$_SESSION['real_estate_pricing_expires'] = time() - 1;
$assert(!real_estate_pricing_has_access(), 'An expired verified session cannot open protected pricing.');
session_destroy();

fwrite(STDOUT, "Passed {$count} pricing-gate assertions.\n");
