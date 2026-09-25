<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/real-estate-form-config.php';
require_once __DIR__ . '/booking-calendar-client.php';
require_once __DIR__ . '/booking-feedback.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
function booking_feedback_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Use the booking form to check availability.'], 405);
}
if (!real_estate_pricing_has_access()) {
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Your pricing session expired. Refresh the page to continue.'], 403);
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
$csrf = (string)($_SERVER['HTTP_X_SITESEE_AVAILABILITY'] ?? '');
if (($origin !== '' && $origin !== SITESEE_REAL_ESTATE_SITE_URL)
    || !in_array($fetchSite, ['', 'same-origin', 'none'], true)
    || !is_string($_SESSION['calendar_feedback_csrf'] ?? null)
    || !hash_equals($_SESSION['calendar_feedback_csrf'], $csrf)) {
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Refresh the pricing page before checking availability.'], 403);
}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Refresh the pricing page and try again.'], 415);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Availability request is too large.'], 413);
}
$raw = file_get_contents('php://input', false, null, 0, 8193);
$payload = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true, 16) : null;
if (!is_array($payload)) booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Choose your services and date again.'], 400);
$now = time();
$recent = array_values(array_filter($_SESSION['calendar_feedback_requests'] ?? [], static fn($time): bool => is_int($time) && $time > $now - 60));
if (count($recent) >= 20) {
    header('Retry-After: 60');
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'Please wait one minute before checking availability again.'], 429);
}
$recent[] = $now;
$_SESSION['calendar_feedback_requests'] = $recent;
session_write_close();
try {
    $config = booking_calendar_config();
    if (!$config['enabled']) throw new BookingCalendarUnavailable('Calendar feedback is disabled.');
    $result = booking_feedback($payload, static fn(array $range): array => booking_calendar_snapshot($config, $range));
    booking_feedback_respond($result);
} catch (InvalidArgumentException $error) {
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>$error->getMessage()], 422);
} catch (Throwable) {
    booking_feedback_respond(['ok'=>false, 'state'=>'unknown', 'message'=>'We could not check the calendar. You can still submit your preferred window for SiteSee to review.'], 503);
}
