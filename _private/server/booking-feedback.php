<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/real-estate-pricing.php';
require_once __DIR__ . '/booking-availability.php';

/** Recalculate duration from service choices, never from a browser duration/price. */
function booking_feedback(array $payload, callable $read, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('America/Chicago'));
    if (!is_string($payload['date'] ?? null) || !is_bool($payload['rush'] ?? null)
        || !is_string($payload['time'] ?? null) || !is_array($payload['state'] ?? null)
        || !in_array($payload['market'] ?? null, ['residential', 'commercial'], true)) {
        throw new InvalidArgumentException('Choose your services and preferred date to check availability.');
    }
    $date = booking_calendar_date($payload['date']);
    $today = booking_calendar_date($now->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d'));
    if ($date < $today
        || !in_array($payload['time'], ['', '07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '13:30', '15:30', '17:30'], true)) {
        throw new InvalidArgumentException('Choose a future date and a listed arrival window.');
    }
    $quote = $payload['market'] === 'residential'
        ? real_estate_residential_quote($payload['state']) : real_estate_commercial_quote($payload['state']);
    $duration = max(15, (int)($quote['knownMinutesMax'] ?? $quote['knownMinutes']));
    if ($quote['additionalCapture'] !== [] || $duration > 1440) {
        return ['ok' => true, 'state' => 'manual_review', 'message' => 'Your selected services need a shoot-duration review. You can still request your preferred window; SiteSee will confirm availability.'];
    }
    $range = booking_availability_range($payload['date'], $duration);
    $snapshot = $read($range);
    $windows = booking_available_windows($payload['date'], $payload['rush'], $duration, $snapshot, $now);
    $public = static fn(array $window): array => array_intersect_key($window, array_flip(['date', 'time', 'end_time']));
    $sameDay = array_values(array_filter($windows, static fn(array $window): bool => $window['date'] === $payload['date']));
    $alternatives = array_values(array_filter($windows, static fn(array $window): bool =>
        $window['date'] > $payload['date'] || $window['time'] > $payload['time']));
    return [
        'ok' => true, 'state' => 'checked', 'date' => $payload['date'], 'timezone' => 'America/Chicago',
        'duration_minutes' => $duration, 'checked_at' => $now->getTimestamp(),
        'date_windows' => array_map($public, $sameDay),
        'selected_available' => $payload['time'] === '' ? null : in_array($payload['time'], array_column($sameDay, 'time'), true),
        'alternatives' => array_map($public, array_slice($alternatives, 0, 6)),
        'search_days' => 14,
    ];
}
