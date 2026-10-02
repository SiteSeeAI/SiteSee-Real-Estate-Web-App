<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-store.php';
require_once __DIR__ . '/booking-calendar-client.php';
require_once __DIR__ . '/booking-scheduling-provider.php';
require_once __DIR__ . '/booking-test-recipients.php';

/** The existing read-only connection remains untouched. Writes use separate credentials. */
function booking_confirmation_config(): array
{
    $config = booking_calendar_config(dirname(__DIR__) . '/zoho-confirmation.json');
    $reader = booking_calendar_config();
    if ($config['calendar_uid'] !== $reader['calendar_uid']
        || ($config['calendar_owner_id'] ?? '') !== ($reader['calendar_owner_id'] ?? '')
        || ($config['confirmation_stage'] ?? '') !== 'test'
        || !is_bool($config['confirmation_enabled'] ?? null)
        || !is_bool($config['invitations_enabled'] ?? null)
        || !in_array('ZohoCalendar.event.CREATE', $config['requested_scopes'] ?? [], true)
        || !filter_var($config['test_recipient_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        throw new BookingCalendarUnavailable('Calendar confirmation is not configured.');
    }
    return $config;
}

function booking_confirmation_get(PDO $db, string $reference): array|false
{
    $stmt = $db->prepare('SELECT * FROM booking_confirmations WHERE reference=?');
    $stmt->execute([$reference]);
    return $stmt->fetch();
}

function booking_confirmation_gate(array $config, array $row): void
{
    if (!booking_test_enabled() || ($config['confirmation_stage'] ?? '') !== 'test'
        || ($config['confirmation_enabled'] ?? false) !== true || ($config['enabled'] ?? false) !== true) {
        throw new InvalidArgumentException('Test appointment confirmation is disabled.');
    }
    if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)
        || !(booking_scheduling_is_microsoft($config)
            ? booking_test_recipient_matches($row['email'], (string)($config['test_recipient_email'] ?? ''))
            : strcasecmp($row['email'], (string)($config['test_recipient_email'] ?? '')) === 0)) {
        throw new InvalidArgumentException('This stage permits only the configured test recipient.');
    }
    if ($row['status'] !== 'deposit_paid_test' || !$row['deposit_paid_at'] || !$row['approved_at']
        || $row['reschedule_required'] || !in_array($row['rush_status'], ['approved', 'not_requested'], true)
        || (int)$row['duration_minutes'] < 15 || (int)$row['duration_minutes'] > 1440
        || !in_array(strtolower(trim((string)$row['photographer'])), ['david', 'david cro', 'david j cro', 'david j. cro'], true)) {
        throw new InvalidArgumentException('A paid, reviewed request assigned to David and an agreed arrival window are required.');
    }
}

/** Fixed-host event creation only. This transport never adds attendees or notifications. */
function booking_confirmation_http(string $method, string $url, ?array $form, ?string $token): array
{
    if ($method === 'GET' || $url === 'https://accounts.zoho.com/oauth/v2/token') {
        return booking_zoho_request($url, $form, $token);
    }
    if ($method !== 'POST' || !preg_match('~^https://calendar\.zoho\.com/api/v1/calendars/[a-f0-9]{32}/events$~D', $url)
        || !is_string($token) || $token === '' || preg_match('/[^\x21-\x7e]/', $token)
        || !is_array($form) || array_keys($form) !== ['eventdata'] || !function_exists('curl_init')) {
        throw new BookingCalendarUnavailable('Calendar creation is unavailable.');
    }
    $event = json_decode($form['eventdata'], true);
    if (!is_array($event) || ($event['notify_attendee'] ?? null) !== 0
        || array_key_exists('attendees', $event) || array_key_exists('group_attendees', $event)
        || array_key_exists('reminders', $event)
        || ($event['isprivate'] ?? null) !== true || ($event['calendar_alarm'] ?? null) !== false) {
        throw new BookingCalendarUnavailable('Unsafe calendar creation request refused.');
    }
    $body = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($form),
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>12,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Accept: application/json', 'Authorization: Zoho-oauthtoken ' . $token],
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = json_decode($body, true);
    if ($ok === false || !is_array($decoded)) throw new BookingCalendarUnavailable('Calendar creation result is uncertain.');
    return ['status'=>$status, 'body'=>$decoded];
}

/** A fresh token and fresh event read are used for final confirmation, never a busy cache. */
function booking_confirmation_connection(array $config, ?callable $transport = null): Closure
{
    $transport ??= 'booking_confirmation_http';
    $reply = $transport('POST', 'https://accounts.zoho.com/oauth/v2/token', [
        'grant_type'=>'refresh_token', 'client_id'=>$config['client_id'],
        'client_secret'=>$config['client_secret'], 'refresh_token'=>$config['refresh_token'],
    ], null);
    $token = $reply['body']['access_token'] ?? null;
    if (($reply['status'] ?? 0) !== 200 || !is_string($token) || $token === ''
        || preg_match('/[^\x21-\x7e]/', $token) || isset($reply['body']['error']) || isset($reply['body']['errors'])) {
        throw new BookingCalendarUnavailable('Calendar authorization could not be verified.');
    }
    return static fn(string $method, string $path, ?array $form = null): array =>
        $transport($method, 'https://calendar.zoho.com' . $path, $form, $token);
}

function booking_confirmation_lock(?string $path = null)
{
    $path ??= dirname(__DIR__) . '/booking-confirmation.lock';
    $info = @lstat($path);
    if ($info && (($info['mode'] & 0170000) !== 0100000 || ($info['mode'] & 0077) !== 0
        || $info['nlink'] !== 1 || $info['uid'] !== @fileowner(dirname($path)))) {
        throw new BookingCalendarUnavailable('Confirmation lock is unavailable.');
    }
    $mask = umask(0077);
    $handle = @fopen($path, 'c+');
    umask($mask);
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
        if ($handle) fclose($handle);
        throw new BookingCalendarUnavailable('Another confirmation is being checked. Try again shortly.');
    }
    return $handle;
}

function booking_confirmation_property(array $details): string
{
    return trim($details['street'] . ' ' . ($details['unit'] ?? '') . ', ' . $details['city'] . ', ' . $details['state'] . ' ' . $details['zip']);
}

function booking_confirmation_event(array $row, array $window, string $marker): array
{
    $request = booking_request($row);
    $location = booking_confirmation_property($request['details']);
    if (strlen($location) > 255) throw new InvalidArgumentException('The property address is too long for the calendar location.');
    return ['title'=>'SiteSee TEST Shoot ' . $row['reference'] . ' ' . $marker,
        'dateandtime'=>['start'=>gmdate('Ymd\THis\Z', strtotime($window['planned_start_utc'])),
            'end'=>gmdate('Ymd\THis\Z', strtotime($window['planned_end_utc'])), 'timezone'=>'America/Chicago'],
        'isallday'=>false, 'isprivate'=>true, 'isrep'=>false, 'transparency'=>0,
        // Zoho rejects empty optional arrays. Omission adds no guests or event reminders.
        'calendar_alarm'=>false, 'notify_attendee'=>0, 'conference'=>'none',
        'allowForwarding'=>false, 'location'=>$location,
        'description'=>'TEST appointment. Booking reference: ' . $row['reference'] . '. Customer arrival window: '
            . $window['date'] . ' ' . $window['time'] . '-' . $window['end_time']
            . ' America/Chicago. Internal block covers the reviewed shoot duration. Access details remain in staff booking review.'];
}

/** Require the saved event, exact interval, dedicated calendar and private/no-guest status. */
function booking_confirmation_verify(array $reply, array $expected, string $calendarUid): string
{
    $events = $reply['body']['events'] ?? null;
    if (($reply['status'] ?? 0) !== 200 || isset($reply['body']['error']) || isset($reply['body']['errors'])
        || !is_array($events) || !array_is_list($events) || count($events) !== 1) {
        throw new BookingCalendarUnavailable('The saved calendar event could not be verified.');
    }
    $event = $events[0];
    $uid = $event['uid'] ?? '';
    if (!is_string($uid) || !preg_match('/^[A-Za-z0-9@._-]{1,256}$/D', $uid)
        || ($event['caluid'] ?? null) !== $calendarUid || ($event['title'] ?? null) !== $expected['title']
        || ($event['isallday'] ?? null) !== false || ($event['isprivate'] ?? null) !== true
        || !in_array($event['transparency'] ?? null, [0, '0'], true)) {
        throw new BookingCalendarUnavailable('The saved calendar event does not match this booking.');
    }
    $dates = $event['dateandtime'] ?? [];
    $zone = new DateTimeZone($dates['timezone'] ?? 'UTC');
    foreach (['start', 'end'] as $key) {
        if (!is_string($dates[$key] ?? null) || booking_calendar_timestamp($dates[$key], $zone, false)
            !== booking_calendar_timestamp($expected['dateandtime'][$key], new DateTimeZone('UTC'), false)) {
            throw new BookingCalendarUnavailable('The saved calendar interval does not match this booking.');
        }
    }
    if (!is_array($event['attendees'] ?? [])) throw new BookingCalendarUnavailable('Unexpected calendar attendee data.');
    foreach (($event['attendees'] ?? []) as $attendee) {
        if (!is_array($attendee) || empty($event['organizer']) || ($attendee['email'] ?? null) !== $event['organizer']) {
            throw new BookingCalendarUnavailable('Unexpected calendar attendee. Review the event before proceeding.');
        }
    }
    return $uid;
}

function booking_confirmation_finish(PDO $db, string $reference, string $uid): void
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare("UPDATE booking_confirmations SET state='confirmed', event_uid=?, confirmed_at=? WHERE reference=? AND state IN ('creating','uncertain')");
        $stmt->execute([$uid, gmdate('c'), $reference]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Confirmation changed.');
        booking_schedule_event($db, $reference, 'calendar_confirmed', ['event_uid'=>$uid]);
        $db->exec('COMMIT');
    } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}

/** Catch an external calendar edit that raced the create; include the new event in a fresh list. */
function booking_confirmation_verify_clear(callable $connection, array $expected, string $calendarUid, string $uid): void
{
    $zone = new DateTimeZone('UTC');
    $start = booking_calendar_timestamp($expected['dateandtime']['start'], $zone, false);
    $end = booking_calendar_timestamp($expected['dateandtime']['end'], $zone, false);
    $reply = [];
    $range = ['start'=>$start, 'end'=>$end];
    booking_calendar_read_busy(static function ($path,$query) use ($connection,&$reply): array {
        $reply = $connection('GET', $path . '?' . http_build_query($query));
        return $reply;
    }, $calendarUid, $range);
    $own = array_values(array_filter($reply['body']['events'], static fn($event) => ($event['uid'] ?? null) === $uid));
    if (count($own) !== 1) throw new BookingCalendarUnavailable('The new appointment is not yet uniquely visible in the calendar list. Recheck the result shortly.');
    $reply['body']['events'] = array_values(array_filter($reply['body']['events'], static fn($event) => ($event['uid'] ?? null) !== $uid));
    $others = booking_calendar_read_busy(static fn($path,$query) => $reply, $calendarUid, $range);
    if ($others['busy'] !== []) throw new BookingCalendarUnavailable('A calendar conflict appeared during confirmation. Inspect the appointment before proceeding.');
}

function booking_confirm_appointment(PDO $db, string $reference, ?array $config = null,
    ?callable $transport = null, ?DateTimeImmutable $now = null, ?string $lockPath = null): array
{
    $config ??= booking_scheduling_config(booking_confirmation_get($db,$reference));
    if (booking_scheduling_is_microsoft($config)) return booking_scheduling_confirm_ms($db,$reference,$config,$transport,$now,$lockPath);
    $now ??= new DateTimeImmutable('now');
    $lock = booking_confirmation_lock($lockPath);
    try {
        $row = booking_get($db, $reference);
        if (!$row) throw new InvalidArgumentException('Booking not found.');
        booking_confirmation_gate($config, $row);
        booking_lifecycle_assert_active($db,$reference);
        $existing = booking_confirmation_get($db, $reference);
        if ($existing) {
            if ($existing['state'] === 'confirmed') return $existing;
            throw new InvalidArgumentException('A calendar creation was already attempted. Recheck its result; do not create another event.');
        }
        $appointment = booking_request($row)['appointment'];
        if (($appointment['windowMinutes'] ?? null) !== 120
            || !in_array($appointment['time'], ['07:00','09:00','11:00','13:00','15:00','17:00'], true)) {
            throw new InvalidArgumentException('A customer-agreed two-hour arrival window is required.');
        }
        $start = booking_calendar_date($appointment['date'])->setTime((int)substr($appointment['time'], 0, 2), 0);
        if ($start->getTimestamp() <= $now->getTimestamp()) throw new InvalidArgumentException('This arrival window has started. Agree a new window before confirmation.');
        // Notice belongs to the customer request, not to the later staff review time.
        $stmt = $db->prepare("SELECT recorded_at FROM booking_schedule_events WHERE reference=? AND action='replacement_window_requested' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$reference]);
        $requestedAt = new DateTimeImmutable($stmt->fetchColumn() ?: $row['created_at']);
        $range = booking_availability_range($appointment['date'], (int)$row['duration_minutes'], 1);
        $connection = booking_confirmation_connection($config, $transport);
        $snapshot = booking_calendar_read_busy(static fn($path, $query) => $connection('GET', $path . '?' . http_build_query($query)), $config['calendar_uid'], $range);
        // Local durable claims also block time if a provider reply was lost or is delayed.
        $snapshot=booking_lifecycle_busy($db,$snapshot);
        $windows = booking_available_windows($appointment['date'], $row['rush_status'] === 'approved',
            (int)$row['duration_minutes'], $snapshot, $requestedAt, 1);
        $matches = array_values(array_filter($windows, static fn($w) => $w['time'] === $appointment['time']));
        if (count($matches) !== 1) throw new InvalidArgumentException('The agreed window no longer fits this shoot. Review calendar alternatives with the customer; nothing was confirmed.');
        $window = $matches[0];
        $event = booking_confirmation_event($row, $window, bin2hex(random_bytes(8)));
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (booking_get($db, $reference) !== $row) throw new InvalidArgumentException('Booking changed during the calendar check. Reload and review it.');
            $db->prepare("INSERT INTO booking_confirmations (reference,state,calendar_uid,planned_start,planned_end,event_json,created_at) VALUES (?,'creating',?,?,?,?,?)")
                ->execute([$reference, $config['calendar_uid'], strtotime($window['planned_start_utc']), strtotime($window['planned_end_utc']), json_encode($event, JSON_THROW_ON_ERROR), gmdate('c')]);
            booking_schedule_event($db, $reference, 'calendar_creation_started');
            $db->exec('COMMIT');
        } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
        try {
            $path = '/api/v1/calendars/' . rawurlencode($config['calendar_uid']) . '/events';
            $reply = $connection('POST', $path, ['eventdata'=>json_encode($event, JSON_THROW_ON_ERROR)]);
            $uid = $reply['body']['events'][0]['uid'] ?? '';
            if (!in_array($reply['status'] ?? 0, [200,201], true) || !is_string($uid)
                || !preg_match('/^[A-Za-z0-9@._-]{1,256}$/D', $uid) || isset($reply['body']['error']) || isset($reply['body']['errors'])) {
                throw new BookingCalendarUnavailable('Creation response could not be verified.');
            }
            $db->prepare('UPDATE booking_confirmations SET event_uid=? WHERE reference=?')->execute([$uid, $reference]);
            $verified = booking_confirmation_verify($connection('GET', booking_calendar_event_path($config['calendar_uid'], $uid)), $event, $config['calendar_uid']);
            if ($uid !== $verified) throw new BookingCalendarUnavailable('Calendar identity mismatch.');
            booking_confirmation_verify_clear($connection, $event, $config['calendar_uid'], $uid);
            booking_confirmation_finish($db, $reference, $uid);
        } catch (Throwable) {
            $db->prepare("UPDATE booking_confirmations SET state='uncertain' WHERE reference=? AND state='creating'")->execute([$reference]);
            throw new BookingCalendarUnavailable('The calendar creation result is uncertain. Use Recheck Calendar Result; automatic recreation and invitations are blocked.');
        }
        return booking_confirmation_get($db, $reference);
    } finally { fclose($lock); }
}

/** Read-only recovery after timeout/crash. A missing event never authorizes an automatic retry. */
function booking_reconcile_confirmation(PDO $db, string $reference, ?array $config = null, ?callable $transport = null, ?string $lockPath = null): array
{
    $config ??= booking_scheduling_config(booking_confirmation_get($db,$reference));
    if (booking_scheduling_is_microsoft($config)) return booking_scheduling_reconcile_ms($db,$reference,$config,$transport,$lockPath);
    $lock = booking_confirmation_lock($lockPath);
    try {
        $row = booking_get($db, $reference);
        if (!$row) throw new InvalidArgumentException('Booking not found.');
        booking_confirmation_gate($config, $row);
        booking_lifecycle_assert_active($db,$reference);
        $claim = booking_confirmation_get($db, $reference);
        if (!$claim) throw new InvalidArgumentException('No calendar creation has been attempted.');
        if ($claim['state'] === 'confirmed') return $claim;
        if ($claim['calendar_uid'] !== $config['calendar_uid']) throw new BookingCalendarUnavailable('Calendar identity changed.');
        $connection = booking_confirmation_connection($config, $transport);
        $expected = json_decode($claim['event_json'], true, 32, JSON_THROW_ON_ERROR);
        $path = '/api/v1/calendars/' . rawurlencode($config['calendar_uid']) . '/events';
        if ($claim['event_uid']) {
            $reply = $connection('GET', booking_calendar_event_path($config['calendar_uid'], $claim['event_uid']));
        } else {
            $records = [];
            booking_calendar_read_busy(static function ($path, $query) use ($connection, &$records): array {
                $response = $connection('GET', $path . '?' . http_build_query($query));
                $records = $response['body']['events'] ?? [];
                return $response;
            }, $config['calendar_uid'], ['start'=>(int)$claim['planned_start'], 'end'=>(int)$claim['planned_end']]);
            $matches = array_values(array_filter($records, static fn($e) => ($e['title'] ?? '') === $expected['title']));
            if (count($matches) !== 1 || !is_string($matches[0]['uid'] ?? null)) {
                throw new BookingCalendarUnavailable('No unique matching event was found. Inspect Zoho manually; this booking remains blocked against duplicate creation.');
            }
            $reply = $connection('GET', booking_calendar_event_path($config['calendar_uid'], $matches[0]['uid']));
        }
        $uid = booking_confirmation_verify($reply, $expected, $config['calendar_uid']);
        if ($claim['event_uid'] && $uid !== $claim['event_uid']) throw new BookingCalendarUnavailable('Calendar identity mismatch.');
        booking_confirmation_verify_clear($connection, $expected, $config['calendar_uid'], $uid);
        booking_confirmation_finish($db, $reference, $uid);
        return booking_confirmation_get($db, $reference);
    } finally { fclose($lock); }
}
