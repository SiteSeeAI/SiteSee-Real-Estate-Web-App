<?php
declare(strict_types=1);

/**
 * Read-only calendar reader and arrival-window planner.
 * The caller must provide an authenticated, timeout-limited Zoho GET transport.
 * No approval, reservation, event creation or invitation occurs here.
 */
final class BookingCalendarUnavailable extends RuntimeException {}

function booking_calendar_date(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('America/Chicago'));
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Choose a valid calendar date.');
    }
    return $date;
}

/** Includes a trailing shoot that starts near the end of the last arrival window. */
function booking_availability_range(string $date, int $duration, int $days = 14): array
{
    if ($duration < 15 || $duration > 1440 || $days < 1 || $days > 28) {
        throw new InvalidArgumentException('Invalid shoot duration or search range.');
    }
    $start = booking_calendar_date($date);
    return [
        'start' => $start->getTimestamp(),
        'end' => $start->modify('+' . $days . ' days')->getTimestamp() + $duration * 60,
    ];
}

/** Calendar event timestamps must have an offset or a known response timezone. */
function booking_calendar_timestamp(string $value, DateTimeZone $timezone, bool $allDay): int
{
    if ($allDay) {
        $format = '!Ymd';
        $roundtrip = 'Ymd';
        if (!preg_match('/^\d{8}$/D', $value)) throw new BookingCalendarUnavailable('Unexpected all-day date.');
    } elseif (preg_match('/^\d{8}T\d{6}Z$/D', $value)) {
        $format = '!Ymd\THis\Z';
        $roundtrip = 'Ymd\THis\Z';
        $timezone = new DateTimeZone('UTC');
    } elseif (preg_match('/^\d{8}T\d{6}[+-]\d{4}$/D', $value)) {
        $format = '!Ymd\THisO';
        $roundtrip = 'Ymd\THisO';
    } elseif (preg_match('/^\d{8}T\d{6}$/D', $value)) {
        $format = '!Ymd\THis';
        $roundtrip = 'Ymd\THis';
    } else {
        throw new BookingCalendarUnavailable('Unexpected calendar timestamp.');
    }
    $parsed = DateTimeImmutable::createFromFormat($format, $value, $timezone);
    if (!$parsed || $parsed->format($roundtrip) !== $value) {
        throw new BookingCalendarUnavailable('Invalid calendar timestamp.');
    }
    return $parsed->getTimestamp();
}

/**
 * $get(path, query) returns ['status'=>HTTP status, 'body'=>decoded JSON object].
 * byinstance expands recurring events. The date range is explicit and <31 days.
 * All events block time conservatively. Event names/details never leave this reader.
 */
function booking_calendar_read_busy(callable $get, string $calendarUid, array $range): array
{
    if ($calendarUid === '' || strlen($calendarUid) > 256 || preg_match('/[\x00-\x20\x7f]/', $calendarUid)
        || !isset($range['start'], $range['end']) || !is_int($range['start']) || !is_int($range['end'])
        || $range['end'] <= $range['start'] || $range['end'] - $range['start'] > 31 * 86400) {
        throw new InvalidArgumentException('Invalid calendar or calendar range.');
    }
    try {
        $response = $get('/api/v1/calendars/' . rawurlencode($calendarUid) . '/events', [
            'range' => json_encode([
                'start' => gmdate('Ymd\THis\Z', $range['start']),
                'end' => gmdate('Ymd\THis\Z', $range['end']),
            ], JSON_THROW_ON_ERROR),
            'byinstance' => 'true',
            'timezone' => 'UTC',
        ]);
    } catch (Throwable $error) {
        // Do not expose transport messages: they may contain credentials or event data.
        throw new BookingCalendarUnavailable('Calendar could not be checked.');
    }
    if (!is_array($response)) throw new BookingCalendarUnavailable('Calendar response could not be verified.');
    $body = $response['body'] ?? null;
    if (($response['status'] ?? null) !== 200 || !is_array($body)
        || isset($body['error']) || isset($body['errors'])
        || !isset($body['events']) || !is_array($body['events']) || !array_is_list($body['events'])) {
        throw new BookingCalendarUnavailable('Calendar response could not be verified.');
    }
    // A new or paginated response shape must be reviewed, never mistaken for complete availability.
    foreach (['next', 'next_page', 'next_page_token', 'next_token', 'more_records', 'has_more', 'pagination', 'info'] as $key) {
        if (!empty($body[$key])) throw new BookingCalendarUnavailable('Calendar coverage is incomplete.');
    }
    // Observed Zoho empty-calendar response. Do not treat other messages or a
    // sentinel mixed with event records as evidence that the calendar is free.
    if ($body['events'] === [['message' => 'No events found.']]) {
        return ['start' => $range['start'], 'end' => $range['end'], 'busy' => []];
    }
    $busy = [];
    foreach ($body['events'] as $event) {
        if (!is_array($event) || array_key_exists('message', $event)) throw new BookingCalendarUnavailable('Invalid calendar event.');
        $allDay = $event['isallday'] ?? false;
        if (!is_bool($allDay)) throw new BookingCalendarUnavailable('Invalid all-day indicator.');
        $dates = $event['dateandtime'] ?? $event;
        if (!is_array($dates) || !is_string($dates['start'] ?? null) || !is_string($dates['end'] ?? null)) {
            throw new BookingCalendarUnavailable('Calendar event dates are missing.');
        }
        try {
            $timezone = new DateTimeZone($dates['timezone'] ?? ($allDay ? 'America/Chicago' : 'UTC'));
        } catch (Throwable $error) {
            throw new BookingCalendarUnavailable('Calendar timezone could not be verified.');
        }
        $start = booking_calendar_timestamp($dates['start'], $timezone, $allDay);
        $end = booking_calendar_timestamp($dates['end'], $timezone, $allDay);
        if ($end <= $start) throw new BookingCalendarUnavailable('Invalid calendar event interval.');
        if ($start < $range['end'] && $end > $range['start']) $busy[] = [$start, $end];
    }
    usort($busy, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    return ['start' => $range['start'], 'end' => $range['end'], 'busy' => $busy];
}

/**
 * Finds a full-duration gap with a planned start inside each two-hour arrival window.
 * Internal planned starts are for staff; customers should receive window labels only.
 * A result is a suggestion, never a reservation or approval.
 */
function booking_available_windows(string $date, bool $rush, int $duration, array $snapshot, DateTimeImmutable $now, int $days = 14): array
{
    $range = booking_availability_range($date, $duration, $days);
    if (!is_int($snapshot['start'] ?? null) || !is_int($snapshot['end'] ?? null)
        || $snapshot['start'] > $range['start'] || $snapshot['end'] < $range['end']
        || !isset($snapshot['busy']) || !is_array($snapshot['busy']) || !array_is_list($snapshot['busy'])) {
        throw new BookingCalendarUnavailable('Calendar coverage is incomplete.');
    }
    $busy = $snapshot['busy'];
    foreach ($busy as $interval) {
        if (!is_array($interval) || count($interval) !== 2 || !is_int($interval[0] ?? null)
            || !is_int($interval[1] ?? null) || $interval[1] <= $interval[0]) {
            throw new BookingCalendarUnavailable('Invalid busy interval.');
        }
    }
    usort($busy, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $cutoff = $now->getTimestamp() + ($rush ? 12 : 72) * 3600;
    $day = booking_calendar_date($date);
    $windows = [];
    for ($n = 0; $n < $days; ++$n, $day = $day->modify('+1 day')) {
        foreach ([7, 9, 11, 13, 15, 17] as $hour) {
            $start = $day->setTime($hour, 0)->getTimestamp();
            $windowEnd = $day->setTime($hour + 2, 0)->getTimestamp();
            if ($start < $cutoff) continue;
            $candidate = $start;
            foreach ($busy as [$busyStart, $busyEnd]) {
                if ($busyEnd <= $candidate) continue;
                if ($busyStart >= $candidate + $duration * 60) break;
                $candidate = $busyEnd;
                if ($candidate >= $windowEnd) break;
            }
            if ($candidate < $windowEnd && $candidate + $duration * 60 <= $snapshot['end']) {
                $windows[] = [
                    'date' => $day->format('Y-m-d'),
                    'time' => sprintf('%02d:00', $hour),
                    'end_time' => sprintf('%02d:00', $hour + 2),
                    'timezone' => 'America/Chicago',
                    'planned_start_utc' => gmdate('Y-m-d\TH:i:s\Z', $candidate),
                    'planned_end_utc' => gmdate('Y-m-d\TH:i:s\Z', $candidate + $duration * 60),
                ];
            }
        }
    }
    return $windows;
}
