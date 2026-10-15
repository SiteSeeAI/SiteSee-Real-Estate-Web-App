<?php
declare(strict_types=1);
require_once __DIR__ . '/support/booking-date.php';
require_once __DIR__ . '/../_private/server/booking-hours.php';
$checks = 0;
function fixture_check(bool $condition, string $label): void {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($label);
}
$zone = new DateTimeZone('America/Chicago');
$examples = [
    ['2026-10-14T23:30:00-05:00', 10, '2026-10-27'], // Saturday/Sunday + manual-move setup.
    ['2026-10-15T10:00:00-05:00', 10, '2026-10-27'], // Original failing Sunday fixture.
    ['2026-10-17T10:00:00-05:00', 10, '2026-10-27'], // Tuesday cutoff is retained.
    ['2026-10-27T23:00:00-05:00', 8, '2026-11-10'],  // Fall DST crossing.
    ['2026-02-27T23:00:00-06:00', 10, '2026-03-10'], // Spring DST crossing.
    ['2024-02-19T23:00:00-06:00', 10, '2024-03-05'], // Leap day crossing.
    ['2025-12-20T10:00:00-06:00', 10, '2025-12-30'], // Year boundary, ordinary pair.
    ['2024-12-15T10:00:00-06:00', 10, '2025-01-07'], // Wednesday New Year.
    ['2018-12-15T10:00:00-06:00', 10, '2019-01-08'], // Tuesday Christmas then New Year.
    ['2023-06-24T10:00:00-05:00', 10, '2023-07-11'], // Tuesday July 4.
    ['2029-06-23T10:00:00-05:00', 10, '2029-07-10'], // Following Wednesday July 4.
];
foreach ($examples as [$reference, $minimum, $expected]) {
    $now = new DateTimeImmutable($reference);
    $date = sitesee_fixture_booking_date($minimum, $now);
    fixture_check($date === $expected, 'Stable fixture date for ' . $reference);
    fixture_check(booking_arrival_start($date, '09:00')->getTimestamp() - $now->getTimestamp() >= 72 * 3600,
        'Standard lead time remains satisfied.');
    foreach ([0, 1] as $offset) {
        $day = booking_hours_date($date)->modify('+' . $offset . ' days')->format('Y-m-d');
        fixture_check(in_array('09:00', booking_window_times($day), true), 'Original/manual-move morning is valid.');
    }
}
fixture_check(sitesee_fixture_booking_date(10, new DateTimeImmutable('2026-10-15T04:30:00Z')) === '2026-10-27',
    'The reference is converted to Central before calendar arithmetic.');
foreach (range(0, 6) as $offset) {
    $now = (new DateTimeImmutable('2026-10-11T23:30:00', $zone))->modify('+' . $offset . ' days');
    fixture_check(booking_hours_date(sitesee_fixture_booking_date(10, $now)) >= $now->modify('+10 days')->setTime(0, 0),
        'Every reference weekday retains its minimum calendar offset.');
}
try { sitesee_fixture_booking_date(0); throw new LogicException('Missing minimum notice guard.'); }
catch (InvalidArgumentException) { fixture_check(true, 'An unsafe short fixture offset is rejected.'); }
fixture_check(!in_array('09:00', booking_window_times('2026-10-25'), true), 'Sunday morning remains blocked.');
fixture_check(booking_window_times('2026-12-25') === [], 'Christmas remains blocked.');
echo 'Fixture calendar: ', $checks, ' checks passed.', PHP_EOL;
