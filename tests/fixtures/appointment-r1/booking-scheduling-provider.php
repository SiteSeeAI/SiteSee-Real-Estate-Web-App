<?php
declare(strict_types=1);
/** Provider routing is durable per confirmation; old Zoho identities are never rewritten. */
require_once __DIR__ . '/booking-microsoft-calendar.php';

function booking_scheduling_is_microsoft(array $config): bool { return ($config['provider'] ?? 'zoho') === 'microsoft'; }
function booking_scheduling_ms_config(): array
{
    return ['schema'=>1, 'stage'=>'test', 'provider'=>'microsoft', 'enabled'=>true,
        'confirmation_stage'=>'test', 'confirmation_enabled'=>true, 'invitations_enabled'=>true,
        'test_recipient_email'=>'cro@sitesee.ai', 'calendar_uid'=>'microsoft:' . BOOKING_MS_CALENDAR];
}
function booking_scheduling_valid_config(array $config): bool
{
    $expected = booking_scheduling_ms_config();
    return count($config) === count($expected) && array_replace($expected,$config) === $expected;
}
function booking_scheduling_config(array|false|null $claim = null): array
{
    if ($claim && !str_starts_with($claim['calendar_uid'], 'microsoft:')) return booking_confirmation_config();
    $path = dirname(__DIR__) . '/microsoft-scheduling.json';
    if (!$claim && !file_exists($path) && !is_link($path)) return booking_confirmation_config();
    $config = booking_ms_private_json($path);
    booking_ms_need(booking_scheduling_valid_config($config), 'Microsoft TEST scheduling configuration differs.');
    if ($claim) booking_ms_need($claim['calendar_uid'] === $config['calendar_uid'], 'Saved calendar identity differs.');
    return $config;
}

/** Separate transport: permits reads and private appointment creation, never mail or deletion. */
function booking_scheduling_ms_connection(array $config, ?callable $http = null): Closure
{
    booking_ms_need(booking_scheduling_valid_config($config), 'Microsoft TEST scheduling gates differ.');
    $connection = booking_ms_private_json(dirname(__DIR__) . '/microsoft-calendar.json');
    booking_ms_validate_config($connection);
    $secret = booking_ms_private_json($connection['graph_credentials']);
    return booking_scheduling_ms_transport($secret, $http);
}
function booking_scheduling_ms_transport(array $secret, ?callable $http = null): Closure
{
    booking_ms_need(strtolower((string)($secret['client_id'] ?? '')) === BOOKING_MS_APP
        && (bool)preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', (string)($secret['tenant_id'] ?? ''))
        && is_string($secret['client_secret'] ?? null) && $secret['client_secret'] !== '', 'Existing Microsoft identity differs.');
    $http ??= 'booking_provider_http';
    $reply = $http('POST', 'https://login.microsoftonline.com/' . $secret['tenant_id'] . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id'=>$secret['client_id'],
        'client_secret'=>$secret['client_secret'], 'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']));
    $token = $reply['body']['access_token'] ?? null;
    booking_ms_need(($reply['status'] ?? 0) === 200 && is_string($token) && (bool)preg_match('/^[\x21-\x7e]{1,32768}$/D', $token), 'Microsoft authentication failed.');
    return static function(string $method, string $path, ?array $body = null) use ($http,$token): array {
        $safe = booking_ms_path($path);
        $allowed = $method === 'GET' && $body === null;
        if ($method === 'POST' && $safe === booking_ms_calendar_path() . '/events' && is_array($body)) {
            booking_scheduling_ms_payload_check($body);
            $allowed = true;
        }
        booking_ms_need($allowed, 'Microsoft scheduling operation is outside this TEST release.');
        return $http($method, 'https://graph.microsoft.com' . $safe,
            ['Authorization: Bearer ' . $token, 'Prefer: IdType="ImmutableId", outlook.timezone="UTC"',
            'Accept: application/json', 'Content-Type: application/json'], $body === null ? null : json_encode($body,JSON_THROW_ON_ERROR));
    };
}
function booking_scheduling_ms_payload_check(array $event): void
{
    $keys = array_keys($event); sort($keys);
    $expected = ['subject','body','start','end','transactionId','attendees','sensitivity','showAs','isReminderOn',
        'isOnlineMeeting','isAllDay','responseRequested','allowNewTimeProposals','location']; sort($expected);
    booking_ms_need($keys === $expected && ($event['attendees'] ?? null) === [] && $event['sensitivity'] === 'private'
        && $event['showAs'] === 'busy' && $event['isReminderOn'] === false && $event['isOnlineMeeting'] === false
        && $event['isAllDay'] === false && $event['responseRequested'] === false && $event['allowNewTimeProposals'] === false
        && is_string($event['transactionId']) && (bool)preg_match('/^[a-f0-9]{32}$/D',$event['transactionId'])
        && (bool)preg_match('/^SiteSee TEST Shoot [A-F0-9]{10,32} [a-f0-9]{32}$/D',(string)$event['subject'])
        && ($event['body']['contentType'] ?? '') === 'text' && is_string($event['body']['content'] ?? null)
        && is_string($event['location']['displayName'] ?? null), 'Unsafe Microsoft appointment payload refused.');
    $duration = booking_ms_timestamp($event['end']) - booking_ms_timestamp($event['start']);
    booking_ms_need($duration >= 900 && $duration <= 86400, 'Microsoft appointment duration differs.');
}
function booking_scheduling_ms_event(array $row, array $window, string $transaction): array
{
    $legacy = booking_confirmation_event($row, $window, $transaction);
    $event = ['subject'=>$legacy['title'], 'body'=>['contentType'=>'text','content'=>$legacy['description']],
        'start'=>['dateTime'=>gmdate('Y-m-d\TH:i:s',strtotime($window['planned_start_utc'])),'timeZone'=>'UTC'],
        'end'=>['dateTime'=>gmdate('Y-m-d\TH:i:s',strtotime($window['planned_end_utc'])),'timeZone'=>'UTC'],
        'transactionId'=>$transaction,'attendees'=>[], 'sensitivity'=>'private','showAs'=>'busy',
        'isReminderOn'=>false,'isOnlineMeeting'=>false,'isAllDay'=>false,'responseRequested'=>false,
        'allowNewTimeProposals'=>false, 'location'=>['displayName'=>$legacy['location']]];
    booking_scheduling_ms_payload_check($event);
    return $event;
}
function booking_scheduling_ms_verify(array $reply, array $expected): string
{
    $event = booking_ms_ok($reply, 'Appointment readback');
    $id = $event['id'] ?? '';
    booking_ms_need(is_string($id) && $id !== '', 'Microsoft appointment ID is missing.');
    booking_ms_event_path($id);
    booking_ms_need(($event['subject'] ?? '') === $expected['subject']
        && ($event['transactionId'] ?? '') === $expected['transactionId']
        && ($event['attendees'] ?? null) === [] && ($event['sensitivity'] ?? '') === 'private'
        && ($event['showAs'] ?? '') === 'busy' && ($event['isCancelled'] ?? null) === false
        && ($event['isAllDay'] ?? null) === false && ($event['isOnlineMeeting'] ?? null) === false
        && ($event['type'] ?? '') === 'singleInstance'
        && strtolower($event['organizer']['emailAddress']['address'] ?? '') === BOOKING_MS_MAILBOX
        && ($event['location']['displayName'] ?? '') === $expected['location']['displayName']
        && booking_ms_timestamp($event['start'] ?? []) === booking_ms_timestamp($expected['start'])
        && booking_ms_timestamp($event['end'] ?? []) === booking_ms_timestamp($expected['end']),
        'Microsoft appointment was moved, changed, or has unexpected recipients. Review it before continuing.');
    return $id;
}
function booking_scheduling_ms_clear(callable $connection, array $expected, string $uid): void
{
    $range = ['start'=>booking_ms_timestamp($expected['start']), 'end'=>booking_ms_timestamp($expected['end'])];
    $found = 0;
    foreach (booking_ms_view($connection,$range,'id,start,end,showAs,isCancelled,type,subject,transactionId,attendees,sensitivity,isAllDay,isOnlineMeeting,organizer,location') as $event) {
        if ($event['id'] === $uid) {
            booking_scheduling_ms_verify(['status'=>200,'body'=>$event],$expected);
            ++$found; continue;
        }
        booking_ms_need(is_bool($event['isCancelled'] ?? null) && in_array($event['showAs'] ?? '',['free','busy','tentative','oof','workingElsewhere','unknown'],true), 'Microsoft availability is incomplete.');
        if ($event['isCancelled'] || $event['showAs'] === 'free') continue;
        if (booking_ms_timestamp($event['start'] ?? []) < $range['end'] && booking_ms_timestamp($event['end'] ?? []) > $range['start'])
            throw new BookingCalendarUnavailable('Another calendar event now overlaps this shoot. Review it; no invitation was sent.');
    }
    booking_ms_need($found === 1, 'Microsoft appointment is missing from its calendar.');
}
function booking_scheduling_local(PDO $db, array $snapshot): array
{
    return booking_lifecycle_busy($db,$snapshot);
}
function booking_scheduling_snapshot(PDO $db, array $config, array $range, ?callable $connection = null): array
{
    if (booking_scheduling_is_microsoft($config)) {
        $connection ??= booking_scheduling_ms_connection($config);
        booking_ms_verify_calendar($connection);
        $snapshot = booking_ms_snapshot($connection,$range);
        $snapshot['busy'] = array_map(static fn($b)=>[$b['start'],$b['end']],$snapshot['busy']);
    } else {
        $connection ??= booking_confirmation_connection($config);
        $snapshot = booking_calendar_read_busy(static fn($path,$query)=>$connection('GET',$path.'?'.http_build_query($query)),$config['calendar_uid'],$range);
    }
    return booking_scheduling_local($db,$snapshot);
}
function booking_scheduling_notice(PDO $db, array $row): DateTimeImmutable
{
    $q = $db->prepare("SELECT recorded_at FROM booking_schedule_events WHERE reference=? AND action='replacement_window_requested' ORDER BY id DESC LIMIT 1");
    $q->execute([$row['reference']]);
    return new DateTimeImmutable($q->fetchColumn() ?: $row['created_at']);
}
function booking_scheduling_verify_saved(array $config, array $saved, ?callable $transport = null): void
{
    if ($saved['calendar_uid'] !== $config['calendar_uid']) throw new BookingCalendarUnavailable('Calendar identity changed.');
    $expected = json_decode($saved['event_json'],true,32,JSON_THROW_ON_ERROR);
    if (booking_scheduling_is_microsoft($config)) {
        $connection = $transport ?? booking_scheduling_ms_connection($config);
        booking_ms_verify_calendar($connection);
        $uid = booking_scheduling_ms_verify($connection('GET',booking_ms_event_path($saved['event_uid'])),$expected);
        booking_ms_need($uid === $saved['event_uid'], 'Microsoft appointment identity changed.');
        booking_scheduling_ms_clear($connection,$expected,$uid);
    } else {
        $connection = booking_confirmation_connection($config,$transport);
        $uid = booking_confirmation_verify($connection('GET',booking_calendar_event_path($config['calendar_uid'],$saved['event_uid'])),$expected,$config['calendar_uid']);
        if ($uid !== $saved['event_uid']) throw new BookingCalendarUnavailable('Calendar identity changed.');
        booking_confirmation_verify_clear($connection,$expected,$config['calendar_uid'],$uid);
    }
}
function booking_scheduling_confirm_ms(PDO $db,string $reference,array $config,?callable $connection,?DateTimeImmutable $now,?string $lockPath): array
{
    booking_ms_need(booking_scheduling_valid_config($config), 'Microsoft TEST scheduling gates differ.');
    $now ??= new DateTimeImmutable('now');
    $lock = booking_confirmation_lock($lockPath);
    try {
        $row = booking_get($db,$reference);
        if (!$row) throw new InvalidArgumentException('Booking not found.');
        booking_confirmation_gate($config,$row);
        booking_lifecycle_assert_active($db,$reference);
        $claim = booking_confirmation_get($db,$reference);
        if ($claim) {
            booking_ms_need($claim['calendar_uid'] === $config['calendar_uid'],'Saved calendar identity differs.');
            if ($claim['state'] === 'confirmed') return $claim;
            throw new InvalidArgumentException('A calendar creation was already attempted. Use Recheck Calendar Result.');
        }
        $appointment = booking_request($row)['appointment'];
        if (($appointment['windowMinutes'] ?? 0) !== 120 || !in_array($appointment['time'],['07:00','09:00','11:00','13:00','15:00','17:00'],true)) throw new InvalidArgumentException('An agreed two-hour arrival window is required.');
        $start = booking_calendar_date($appointment['date'])->setTime((int)substr($appointment['time'],0,2),0);
        if ($start <= $now) throw new InvalidArgumentException('This arrival window has started. Agree a new window before confirmation.');
        $connection ??= booking_scheduling_ms_connection($config);
        $snapshot = booking_scheduling_snapshot($db,$config,booking_availability_range($appointment['date'],(int)$row['duration_minutes'],1),$connection);
        $windows = booking_available_windows($appointment['date'],$row['rush_status']==='approved',(int)$row['duration_minutes'],$snapshot,booking_scheduling_notice($db,$row),1);
        $matches = array_values(array_filter($windows,static fn($w)=>$w['time'] === $appointment['time']));
        if (count($matches) !== 1) throw new InvalidArgumentException('The agreed window no longer fits this shoot. Available alternatives are shown below; nothing was confirmed.');
        $window = $matches[0]; $event = booking_scheduling_ms_event($row,$window,bin2hex(random_bytes(16)));
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (booking_get($db,$reference) !== $row) throw new InvalidArgumentException('Booking changed. Reload and review it.');
            $db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,planned_start,planned_end,event_json,created_at) VALUES(?,'creating',?,?,?,?,?)")
                ->execute([$reference,$config['calendar_uid'],strtotime($window['planned_start_utc']),strtotime($window['planned_end_utc']),json_encode($event,JSON_THROW_ON_ERROR),gmdate('c')]);
            booking_schedule_event($db,$reference,'calendar_creation_started',['provider'=>'microsoft']);
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        try {
            $reply = booking_ms_ok($connection('POST',booking_ms_calendar_path().'/events',$event),'Appointment creation',201);
            $uid = $reply['id'] ?? ''; booking_ms_need(is_string($uid) && $uid !== '', 'Created appointment ID is missing.'); booking_ms_event_path($uid);
            $db->prepare('UPDATE booking_confirmations SET event_uid=? WHERE reference=?')->execute([$uid,$reference]);
            booking_scheduling_verify_saved($config,booking_confirmation_get($db,$reference),$connection);
            booking_confirmation_finish($db,$reference,$uid);
        } catch (Throwable) {
            $db->prepare("UPDATE booking_confirmations SET state='uncertain' WHERE reference=? AND state='creating'")->execute([$reference]);
            throw new BookingCalendarUnavailable('Calendar creation needs verification. Use Recheck Calendar Result; another event and invitations are blocked.');
        }
        return booking_confirmation_get($db,$reference);
    } finally { fclose($lock); }
}
function booking_scheduling_reconcile_ms(PDO $db,string $reference,array $config,?callable $connection,?string $lockPath): array
{
    $lock = booking_confirmation_lock($lockPath);
    try {
        $row = booking_get($db,$reference); if (!$row) throw new InvalidArgumentException('Booking not found.');
        booking_confirmation_gate($config,$row);
        booking_lifecycle_assert_active($db,$reference);
        $claim = booking_confirmation_get($db,$reference);
        if (!$claim) throw new InvalidArgumentException('No calendar creation has been attempted.');
        booking_ms_need(booking_scheduling_valid_config($config) && $claim['calendar_uid'] === $config['calendar_uid'],'Saved calendar identity differs.');
        $connection ??= booking_scheduling_ms_connection($config);
        booking_ms_verify_calendar($connection);
        $expected = json_decode($claim['event_json'],true,32,JSON_THROW_ON_ERROR);
        if (!$claim['event_uid']) {
            $events = booking_ms_view($connection,['start'=>(int)$claim['planned_start'],'end'=>(int)$claim['planned_end']],'id,subject,transactionId');
            $matches = array_values(array_filter($events,static fn($e)=>($e['subject']??'') === $expected['subject'] && ($e['transactionId']??'') === $expected['transactionId']));
            if (count($matches) !== 1) throw new BookingCalendarUnavailable('No unique matching Microsoft event was found. Inspect the calendar; the reservation remains held and no second event was created.');
            $claim['event_uid'] = $matches[0]['id'];
            $db->prepare('UPDATE booking_confirmations SET event_uid=? WHERE reference=?')->execute([$claim['event_uid'],$reference]);
        }
        booking_scheduling_verify_saved($config,$claim,$connection);
        if ($claim['state'] !== 'confirmed') booking_confirmation_finish($db,$reference,$claim['event_uid']);
        return booking_confirmation_get($db,$reference);
    } finally { fclose($lock); }
}

/** Suggestions are fresh reads and never reserve time. New proposals use notice from now. */
function booking_scheduling_alternatives(PDO $db,string $reference,?array $config=null,?callable $connection=null,?DateTimeImmutable $now=null): array
{
    $row = booking_get($db,$reference); if (!$row) throw new InvalidArgumentException('Booking not found.');
    if (booking_confirmation_get($db,$reference)) throw new InvalidArgumentException('This booking already has a calendar attempt; review that appointment first.');
    $config ??= booking_scheduling_config(); booking_confirmation_gate($config,$row);
    $now ??= new DateTimeImmutable('now',new DateTimeZone('America/Chicago'));
    $date = max(booking_request($row)['appointment']['date'],$now->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d'));
    $range = booking_availability_range($date,(int)$row['duration_minutes'],14);
    $snapshot = booking_scheduling_snapshot($db,$config,$range,$connection);
    return array_slice(booking_available_windows($date,$row['rush_status']==='approved',(int)$row['duration_minutes'],$snapshot,$now,14),0,12);
}
function booking_scheduling_change_window(PDO $db,string $reference,string $date,string $time,string $fingerprint,
    ?array $config=null,?callable $connection=null,?DateTimeImmutable $now=null,?string $lockPath=null): void
{
    $lock = booking_confirmation_lock($lockPath);
    try {
        $row = booking_get($db,$reference); if (!$row) throw new InvalidArgumentException('Booking not found.');
        if (!hash_equals(hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),$fingerprint)) throw new InvalidArgumentException('This booking changed. Reload it before choosing another window.');
        $windows = booking_scheduling_alternatives($db,$reference,$config,$connection,$now);
        $matches = array_values(array_filter($windows,static fn($w)=>$w['date']===$date && $w['time']===$time));
        if (count($matches)!==1) throw new InvalidArgumentException('That alternative is no longer available. Check again.');
        $old = booking_request($row)['appointment'];
        if ($old['date']===$date && $old['time']===$time) return;
        $appointment = array_replace($old,['date'=>$date,'time'=>$time,'windowMinutes'=>120,'windowEnd'=>$matches[0]['end_time']]);
        $utc = booking_calendar_date($date)->setTime((int)substr($time,0,2),0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (booking_get($db,$reference)!==$row || booking_confirmation_get($db,$reference)) throw new InvalidArgumentException('Booking changed during the check. Reload it.');
            $db->prepare('UPDATE booking_scheduling SET appointment_json=? WHERE reference=?')->execute([json_encode($appointment,JSON_THROW_ON_ERROR),$reference]);
            $db->prepare('UPDATE bookings SET requested_utc=?,approved_at=NULL,availability_checked_at=NULL WHERE reference=?')->execute([$utc,$reference]);
            $at = ($now ?? new DateTimeImmutable('now'))->format('c');
            $db->prepare('INSERT INTO booking_schedule_events(reference,action,recorded_at,detail_json) VALUES(?,?,?,?)')
                ->execute([$reference,'replacement_window_requested',$at,json_encode(['date'=>$date,'time'=>$time,'windowMinutes'=>120,'staff_recorded_customer_agreement'=>true],JSON_THROW_ON_ERROR)]);
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    } finally { fclose($lock); }
}
