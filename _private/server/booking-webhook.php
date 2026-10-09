<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-store.php';
require_once __DIR__ . '/portal-billing.php';
if (!booking_test_enabled()) {
    http_response_code(503);
    exit('Test payments are disabled.');
}
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('POST required');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
    http_response_code(413);
    exit('Payload too large');
}
$raw = file_get_contents('php://input');
try {
    booking_test_key();
    $event = booking_verify_stripe_event((string)$raw, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    $db = booking_db();
    if (!booking_job_event($db,$event) && !portal_balance_event($db, $event)) booking_process_stripe_event($db, $event);
    $object=$event['data']['object']??[];
    $financeReference=$object['metadata']['booking_reference']??$object['client_reference_id']??'';
    if(is_string($financeReference)&&preg_match('/^[A-F0-9]{10,32}$/D',$financeReference))booking_finance_process($db,$financeReference);
    echo 'ok';
} catch (InvalidArgumentException | JsonException $error) {
    http_response_code(400);
    echo 'Invalid event';
} catch (Throwable $error) {
    error_log('SiteSee Stripe test webhook requires retry: ' . $error->getMessage());
    http_response_code(503);
    echo 'Retry later';
}
