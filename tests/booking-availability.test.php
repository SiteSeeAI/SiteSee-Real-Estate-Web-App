<?php
declare(strict_types=1);
require_once __DIR__ . '/../_private/server/booking-availability.php';

$checks = 0;
function calendar_check(bool $ok, string $message): void {
    global $checks;
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
}
function calendar_throws(callable $run, string $class = BookingCalendarUnavailable::class): void {
    try { $run(); } catch (Throwable $error) {
        calendar_check($error instanceof $class, 'Wrong exception: ' . get_class($error));
        return;
    }
    throw new RuntimeException('Expected an exception.');
}
function calendar_epoch(string $value): int { return (new DateTimeImmutable($value))->getTimestamp(); }
function calendar_snapshot(string $date, int $duration = 60, int $days = 2, array $busy = []): array {
    return booking_availability_range($date, $duration, $days) + ['busy' => $busy];
}

$now = new DateTimeImmutable('2026-09-25T09:00:00-05:00');
$empty = calendar_snapshot('2026-09-28');
$windows = booking_available_windows('2026-09-28', false, 60, $empty, $now, 2);
calendar_check(count($windows) === 11, 'Standard cutoff excludes the 7 AM window.');
calendar_check($windows[0]['time'] === '09:00', 'Exactly 72 hours is eligible.');
calendar_check($windows[0]['planned_start_utc'] === '2026-09-28T14:00:00Z', 'Central time becomes UTC.');
calendar_check($windows[4]['time'] === '17:00' && $windows[5]['time'] === '07:00', 'Evening and next-day morning windows are included.');
$after = booking_available_windows('2026-09-28', false, 60, $empty, $now->modify('+1 second'), 2);
calendar_check($after[0]['time'] === '11:00', 'One second short of notice is excluded.');

$rushNow = new DateTimeImmutable('2026-09-27T21:00:00-05:00');
$rush = booking_available_windows('2026-09-28', true, 60, $empty, $rushNow, 2);
calendar_check($rush[0]['time'] === '09:00', 'Rush uses exactly 12 elapsed hours.');
calendar_check(booking_available_windows('2026-09-28', false, 60, $empty, $rushNow, 2) === [], 'Standard notice is independent of rush.');

$fullDay = [calendar_epoch('2026-09-28T00:00:00-05:00'), calendar_epoch('2026-09-29T00:00:00-05:00')];
$blocked = booking_available_windows('2026-09-28', false, 60, calendar_snapshot('2026-09-28', 60, 2, [$fullDay]), $now, 2);
calendar_check(count($blocked) === 6 && $blocked[0]['date'] === '2026-09-29', 'A fully booked day yields the next available day.');
$allBlocked = [$fullDay[0], calendar_epoch('2026-10-01T00:00:00-05:00')];
calendar_check(booking_available_windows('2026-09-28', false, 60, calendar_snapshot('2026-09-28', 60, 2, [$allBlocked]), $now, 2) === [], 'A full search range returns no suggestions.');

$busy = [[calendar_epoch('2026-09-28T09:00:00-05:00'), calendar_epoch('2026-09-28T10:00:00-05:00')]];
$later = booking_available_windows('2026-09-28', false, 90, calendar_snapshot('2026-09-28', 90, 2, $busy), $now, 2);
calendar_check($later[0]['time'] === '09:00' && $later[0]['planned_start_utc'] === '2026-09-28T15:00:00Z', 'Later arrival within the same window can fit a 90-minute shoot.');
calendar_check($later[0]['planned_end_utc'] === '2026-09-28T16:30:00Z', 'Shoot duration may extend beyond the arrival window.');
$busy[] = [calendar_epoch('2026-09-28T11:00:00-05:00'), calendar_epoch('2026-09-28T12:00:00-05:00')];
$noFit = booking_available_windows('2026-09-28', false, 90, calendar_snapshot('2026-09-28', 90, 2, $busy), $now, 2);
calendar_check($noFit[0]['time'] === '11:00', 'A long shoot cannot fit in a short gap before another event.');
$adjacent = booking_available_windows('2026-09-28', false, 60, calendar_snapshot('2026-09-28', 60, 2, $busy), $now, 2);
calendar_check($adjacent[0]['time'] === '09:00', 'An event ending at 10 allows a 10–11 shoot before an 11 start.');
$busyToEnd = [[calendar_epoch('2026-09-28T09:00:00-05:00'), calendar_epoch('2026-09-28T11:00:00-05:00')]];
calendar_check(booking_available_windows('2026-09-28', false, 60, calendar_snapshot('2026-09-28', 60, 2, $busyToEnd), $now, 2)[0]['time'] === '11:00', 'Arrival at the end boundary belongs to the next window.');

$fallNow = new DateTimeImmutable('2026-10-29T08:00:00-05:00');
$fall = booking_available_windows('2026-11-01', false, 60, calendar_snapshot('2026-11-01'), $fallNow, 2);
calendar_check($fall[0]['time'] === '07:00' && $fall[0]['planned_start_utc'] === '2026-11-01T13:00:00Z', 'Fall DST uses 72 actual hours.');
$springNow = new DateTimeImmutable('2026-03-05T07:00:00-06:00');
$spring = booking_available_windows('2026-03-08', false, 60, calendar_snapshot('2026-03-08'), $springNow, 2);
calendar_check($spring[0]['time'] === '09:00' && $spring[0]['planned_start_utc'] === '2026-03-08T14:00:00Z', 'Spring DST excludes the window only 71 hours away.');
calendar_throws(fn() => booking_availability_range('2026-02-30', 60), InvalidArgumentException::class);
calendar_throws(fn() => booking_availability_range('2026-09-28', 0), InvalidArgumentException::class);
calendar_throws(fn() => booking_availability_range('2026-09-28', 60, 29), InvalidArgumentException::class);
calendar_throws(fn() => booking_available_windows('2026-09-28', false, 60, ['start'=>$empty['start'], 'end'=>$empty['end']-1, 'busy'=>[]], $now, 2));
calendar_throws(fn() => booking_available_windows('2026-09-28', false, 60, calendar_snapshot('2026-09-28', 60, 2, [[1, 1]]), $now, 2));

$range = booking_availability_range('2026-09-28', 60, 2);
$get = static function (string $path, array $query) use ($range): array {
    calendar_check($path === '/api/v1/calendars/test%2Buid%3D%3D/events', 'Calendar UID is encoded in a fixed API path.');
    calendar_check($query['byinstance'] === 'true' && $query['timezone'] === 'UTC', 'Recurrence expansion and timezone are explicit.');
    calendar_check(json_decode($query['range'], true)['start'] === gmdate('Ymd\THis\Z', $range['start']), 'API range uses an explicit UTC boundary.');
    return ['status'=>200, 'body'=>['events'=>[
        ['isallday'=>true, 'dateandtime'=>['start'=>'20260928', 'end'=>'20260929', 'timezone'=>'America/Chicago'], 'title'=>'Private property details'],
        ['isallday'=>false, 'dateandtime'=>['start'=>'20260929T140000Z', 'end'=>'20260929T160000Z']],
        ['isallday'=>false, 'start'=>'20260929T160000', 'end'=>'20260929T170000'],
    ]]];
};
$read = booking_calendar_read_busy($get, 'test+uid==', $range);
calendar_check($read['busy'][0] === $fullDay, 'All-day interval uses the event timezone.');
calendar_check(count($read['busy']) === 3 && !str_contains(json_encode($read), 'Private'), 'Only busy intervals are retained.');
$fromApi = booking_available_windows('2026-09-28', false, 60, $read, $now, 2);
calendar_check($fromApi[0]['date'] === '2026-09-29', 'Reader and planner skip a fully blocked day.');
$goodEmpty = booking_calendar_read_busy(fn()=>['status'=>200, 'body'=>['events'=>[]]], 'calendar', $range);
calendar_check(count(booking_available_windows('2026-09-28', false, 60, $goodEmpty, $now, 2)) === 11, 'An explicit successful empty calendar is valid.');
$emptyMessage = ['message'=>'No events found.'];
$messageEmpty = booking_calendar_read_busy(fn()=>['status'=>200, 'body'=>['events'=>[$emptyMessage]]], 'calendar', $range);
calendar_check($messageEmpty === $goodEmpty, 'Observed Zoho empty sentinel produces an empty busy list.');
$liveEvent = ['isallday'=>false, 'dateandtime'=>['start'=>'20260929T140000+0000', 'end'=>'20260929T150000+0000', 'timezone'=>'UTC']];
$liveRead = booking_calendar_read_busy(fn()=>['status'=>200, 'body'=>['events'=>[$liveEvent]]], 'calendar', $range);
calendar_check($liveRead['busy'] === [[calendar_epoch('2026-09-29T09:00:00-05:00'), calendar_epoch('2026-09-29T10:00:00-05:00')]], 'Observed timed event blocks exactly 9–10 AM Chicago.');
$liveWindows = booking_available_windows('2026-09-28', false, 60, $liveRead, $now, 2);
$liveNine = array_values(array_filter($liveWindows, fn($window)=>$window['date']==='2026-09-29' && $window['time']==='09:00'));
calendar_check($liveNine[0]['planned_start_utc'] === '2026-09-29T15:00:00Z', 'The observed busy interval pushes planned arrival to 10 AM.');
$allDayRange = booking_availability_range('2026-09-30', 60, 2);
foreach ([['timezone'=>null], []] as $zoneFields) {
    $allDayEvent = ['isallday'=>true, 'dateandtime'=>['start'=>'20260930', 'end'=>'20261001'] + $zoneFields];
    $allDayRead = booking_calendar_read_busy(fn()=>['status'=>200, 'body'=>['events'=>[$allDayEvent]]], 'calendar', $allDayRange);
    calendar_check($allDayRead['busy'] === [[calendar_epoch('2026-09-30T00:00:00-05:00'), calendar_epoch('2026-10-01T00:00:00-05:00')]], 'Observed date-only all-day block uses Chicago midnights when timezone is absent or null.');
    $allDayWindows = booking_available_windows('2026-09-30', false, 60, $allDayRead, $now, 2);
    calendar_check(count($allDayWindows) === 6 && array_unique(array_column($allDayWindows, 'date')) === ['2026-10-01'], 'Observed all-day block removes every September 30 window and leaves October 1 available.');
}
foreach ([
    ['status'=>200,'body'=>['events'=>[['message'=>'Permission denied']]]],
    ['status'=>200,'body'=>['events'=>[$emptyMessage, $liveEvent]]],
    ['status'=>200,'body'=>['events'=>[$emptyMessage, $emptyMessage]]],
    ['status'=>200,'body'=>['events'=>[$emptyMessage], 'error'=>'denied']],
    ['status'=>200,'body'=>['events'=>[$emptyMessage], 'next_page_token'=>'more']],
    ['status'=>200,'body'=>['events'=>[$liveEvent + ['message'=>'Partial data']]]],
    ['status'=>401,'body'=>['events'=>[]]],
    ['status'=>429,'body'=>['events'=>[]]],
    ['status'=>200,'body'=>[]],
    ['status'=>200,'body'=>['events'=>[], 'error'=>'permission denied']],
    ['status'=>200,'body'=>['events'=>[], 'next_page_token'=>'more']],
    ['status'=>200,'body'=>['events'=>[['start'=>'invalid','end'=>'invalid']]]],
    ['status'=>200,'body'=>['events'=>[['start'=>'20260929T250000Z','end'=>'20260929T260000Z']]]],
    ['status'=>200,'body'=>['events'=>[['start'=>'20260929T170000Z','end'=>'20260929T160000Z']]]],
    'invalid transport response',
] as $bad) calendar_throws(fn()=>booking_calendar_read_busy(fn()=>$bad, 'calendar', $range));
try {
    booking_calendar_read_busy(function(){ throw new RuntimeException('secret token and customer details'); }, 'calendar', $range);
    throw new RuntimeException('Expected transport failure.');
} catch (BookingCalendarUnavailable $error) {
    calendar_check(!str_contains($error->getMessage(), 'secret') && $error->getPrevious() === null, 'Transport failure does not expose secrets.');
}
calendar_throws(fn()=>booking_calendar_timestamp('20260230T080000Z', new DateTimeZone('UTC'), false));
calendar_check(booking_calendar_timestamp('20260928T090000-0500', new DateTimeZone('UTC'), false) === calendar_epoch('2026-09-28T09:00:00-05:00'), 'Numeric offsets are supported.');
echo 'Calendar availability: ' . $checks . " checks passed.\n";
