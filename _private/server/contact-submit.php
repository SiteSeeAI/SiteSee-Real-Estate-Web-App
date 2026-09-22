<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/real-estate-form-config.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function contact_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $ok = !empty($payload['ok']);
    $message = htmlspecialchars((string)($payload['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    $reference = htmlspecialchars((string)($payload['reference'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>'
        . ($ok ? 'Inquiry Received' : 'Inquiry Error')
        . ' | SiteSee Real Estate</title><link rel="stylesheet" href="assets/css/site.css"></head><body><main style="min-height:100vh;display:grid;place-items:center;background:#01111e;padding:5vw"><section style="width:min(760px,90vw);box-sizing:border-box;background:#fff;padding:48px;border-top:8px solid #ffc107"><img src="assets/images/sitesee-logo.png" alt="SiteSee" style="width:190px"><h1 style="font-size:clamp(2.2rem,5vw,3.8rem);margin-top:32px">'
        . ($ok ? 'Inquiry Received.' : 'We Could Not Send The Inquiry.')
        . '</h1><p style="font-size:19px;line-height:1.65;margin-top:20px">' . $message . '</p>'
        . ($reference !== '' ? '<p style="font-size:15px;color:#596267">Reference: ' . $reference . '</p>' : '')
        . '<p style="margin-top:28px"><a href="contact.html#project-inquiry" style="font-weight:700">Return To Contact</a></p></section></main></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    contact_respond(['ok'=>false, 'message'=>'Submit the contact form to continue.'], 405);
}
if (!real_estate_same_origin()) {
    contact_respond(['ok'=>false, 'message'=>'The request did not originate from the SiteSee Real Estate website.'], 403);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    contact_respond(['ok'=>false, 'message'=>'The inquiry is too large. Refresh the page and try again.'], 413);
}
if (trim((string)($_POST['company_fax'] ?? '')) !== '') {
    contact_respond(['ok'=>true, 'message'=>'Thank you. Your inquiry has been received.']);
}

$clean = static function (mixed $value): string {
    $value = trim((string)$value);
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
};
$first = $clean($_POST['first_name'] ?? '');
$last = $clean($_POST['last_name'] ?? '');
$company = $clean($_POST['company_name'] ?? '');
$email = strtolower($clean($_POST['email'] ?? ''));
$phone = $clean($_POST['phone'] ?? '');
$preference = $clean($_POST['preferred_communication'] ?? '');
$message = $clean($_POST['message'] ?? '');
$source = $clean($_POST['source_page'] ?? 'real-estate-contact');

$errors = [];
if ($first === '' || strlen($first) > 80) $errors[] = 'Enter your first name.';
if ($last === '' || strlen($last) > 80) $errors[] = 'Enter your last name.';
if ($company !== '' && strlen($company) > 140) $errors[] = 'Enter a valid company name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) $errors[] = 'Enter a valid email address.';
if (strlen($phone) > 50) $errors[] = 'Enter a valid phone number.';
if (!in_array($preference, ['', 'Call', 'Text', 'Email'], true)) $errors[] = 'Select a valid communication preference.';
if (in_array($preference, ['Call', 'Text'], true) && $phone === '') $errors[] = 'Enter a phone number for your selected communication preference.';
if (strlen($message) < 10 || strlen($message) > 2000) $errors[] = 'Tell us about your project in 10 to 2,000 characters.';
if (strlen($source) > 80) $errors[] = 'Refresh the page and try again.';
if ($errors) contact_respond(['ok'=>false, 'message'=>implode(' ', $errors)], 422);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$turnstile = real_estate_verify_turnstile(
    (string)($_POST['cf-turnstile-response'] ?? ''),
    $ip,
    'contact_inquiry'
);
if (!$turnstile['ok']) {
    contact_respond(['ok'=>false, 'message'=>'The secure form check expired or could not be verified. Please complete it again.'], 422);
}
if (!real_estate_rate_allowed($ip, $email)) {
    contact_respond(['ok'=>false, 'message'=>'Too many inquiries were received. Please wait and try again, or email sales@sitesee.ai.'], 429);
}

$reference = strtoupper(bin2hex(random_bytes(5)));
$safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$fullName = trim($first . ' ' . $last);
$details = [
    'Reference' => $reference,
    'Name' => $fullName,
    'Company' => $company !== '' ? $company : 'Not provided',
    'Email' => $email,
    'Phone' => $phone !== '' ? $phone : 'Not provided',
    'Preferred communication' => $preference !== '' ? $preference : 'Not selected',
    'Source' => $source,
    'Project details' => $message,
];
$plainLines = [];
$htmlRows = '';
foreach ($details as $label => $value) {
    $plainLines[] = $label . ': ' . $value;
    $htmlRows .= '<tr><th align="left" valign="top" style="padding:8px 12px 8px 0">' . $safe($label) . '</th><td style="padding:8px 0;white-space:pre-wrap">' . $safe($value) . '</td></tr>';
}
$plain = implode("\n", $plainLines);
$salesHtml = '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:700px"><h1>New SiteSee Real Estate Inquiry</h1><table cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $htmlRows . '</table><p>Reply to this message to contact ' . $safe($fullName) . '.</p></div>';
$ackHtml = '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:640px;margin:auto"><div style="background:#01111e;padding:24px"><strong style="color:#fff;font-size:24px">SiteSee Real Estate</strong></div><div style="padding:34px;border:1px solid #d9dadb;border-top:0"><h1 style="font-size:30px">We received your inquiry.</h1><p style="font-size:17px;line-height:1.6">Hello ' . $safe($first) . ',</p><p style="font-size:17px;line-height:1.6">Thank you for contacting SiteSee. We received your project information and will respond using your preferred method when possible.</p><p style="font-size:14px;color:#596267">Reference: ' . $reference . '</p><p style="font-size:15px;line-height:1.6">Questions: <a href="mailto:sales@sitesee.ai">sales@sitesee.ai</a></p></div></div>';

$salesSent = real_estate_send_mail(
    SITESEE_REAL_ESTATE_SALES_EMAIL,
    $email,
    'New SiteSee Real Estate inquiry: ' . $fullName,
    $salesHtml,
    $plain
);
if (!$salesSent) {
    contact_respond(['ok'=>false, 'message'=>'We could not deliver your inquiry. Please try again or email sales@sitesee.ai.'], 503);
}

$copySent = real_estate_send_mail(
    $email,
    SITESEE_REAL_ESTATE_SALES_EMAIL,
    'We received your SiteSee Real Estate inquiry',
    $ackHtml,
    "Reference: {$reference}\n\nHello {$first},\n\nThank you for contacting SiteSee. We received your project information and will respond soon."
);

contact_respond([
    'ok'=>true,
    'reference'=>$reference,
    'copy_sent'=>$copySent,
    'message'=>$copySent
        ? 'Your inquiry was delivered. We also emailed you a confirmation.'
        : 'Your inquiry was delivered. We could not send the confirmation copy, but SiteSee received your request.',
]);
