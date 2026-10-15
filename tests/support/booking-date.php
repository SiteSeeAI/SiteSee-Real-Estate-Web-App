<?php
declare(strict_types=1);

/** Positive test bookings need ordinary windows on this date and the next day. */
function sitesee_fixture_booking_date(int $minimumDays = 10, ?DateTimeImmutable $now = null): string
{
    if ($minimumDays < 4) throw new InvalidArgumentException('Fixture bookings must retain standard notice.');
    $zone = new DateTimeZone('America/Chicago');
    $day = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)
        ->modify('+' . $minimumDays . ' days')->setTime(0, 0);
    // Tuesday/Wednesday avoid Sunday and all weekday-based/Christian closures.
    // Fixed-date closures can fall on either day, including across New Year.
    $fixed = ['01-01', '07-04', '12-25'];
    for ($attempt = 0; $attempt < 21; ++$attempt, $day = $day->modify('+1 day')) {
        if ($day->format('N') === '2' && !in_array($day->format('m-d'), $fixed, true)
            && !in_array($day->modify('+1 day')->format('m-d'), $fixed, true)) return $day->format('Y-m-d');
    }
    throw new RuntimeException('No ordinary fixture date found.');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo sitesee_fixture_booking_date(), PHP_EOL;
}
