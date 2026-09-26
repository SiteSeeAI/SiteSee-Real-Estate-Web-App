<?php
declare(strict_types=1);
/** CLI-only, default-read-only diagnosis; an explicitly acknowledged retry is single-use. */

function cd_stop(string $message): never { throw new RuntimeException($message); }

/** No raw provider body, URL, request payload or credential is written to the ledger/output. */
function cd_summary(array $reply, array $secrets): array
{
    $out = ['http_status'=>(int)($reply['status'] ?? 0), 'curl_errno'=>(int)($reply['errno'] ?? 0),
        'json_response'=>is_array($reply['body'] ?? null)];
    $errors = $reply['body']['error'] ?? $reply['body']['errors'] ?? [];
    if (is_string($errors)) $errors = [['code'=>$errors]];
    if (is_array($errors) && !array_is_list($errors)) $errors = [$errors];
    $out['errors'] = [];
    if (is_array($errors)) foreach (array_slice($errors, 0, 3) as $error) {
        if (!is_array($error)) continue;
        $safe = [];
        foreach (['error_code','code','message','description'] as $key) {
            if (!is_string($error[$key] ?? null)) continue;
            $value = str_replace(array_filter($secrets, 'strlen'), '[REDACTED]', $error[$key]);
            $value = preg_replace('/1000\.[A-Za-z0-9._-]+/', '[REDACTED]', $value);
            $value = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value);
            $safe[$key] = substr($value, 0, 300);
        }
        if ($safe) $out['errors'][] = $safe;
    }
    return $out;
}

function cd_http(string $method, string $url, ?array $form, ?string $token): array
{
    $oauth = $url === 'https://accounts.zoho.com/oauth/v2/token';
    if ($oauth) {
        if ($method !== 'POST' || $token !== null) cd_stop('Unexpected authorization request.');
    } elseif (!preg_match('~^https://calendar\.zoho\.com/api/v1/calendars/[a-f0-9]{32}/events(?:/[A-Za-z0-9%._@-]+|\?[^#]*)?$~D', $url)
        || !in_array($method, ['GET','POST'], true) || !is_string($token) || $token === ''
        || preg_match('/[^\x21-\x7e]/', $token)) cd_stop('Unexpected calendar request.');
    if (!$oauth && $method === 'POST') {
        if (!str_ends_with($url, '/events') || !is_array($form) || array_keys($form) !== ['eventdata']) cd_stop('Unexpected creation request.');
        $event = json_decode($form['eventdata'], true);
        if (!is_array($event) || ($event['isprivate'] ?? null) !== true || ($event['notify_attendee'] ?? null) !== 0
            || ($event['calendar_alarm'] ?? null) !== false || ($event['attendees'] ?? null) !== []
            || ($event['reminders'] ?? null) !== [] || ($event['conference'] ?? null) !== 'none') cd_stop('Unsafe event payload refused.');
    }
    $body = '';
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Zoho-oauthtoken ' . $token;
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>12,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) return 0;
            $body .= $chunk; return strlen($chunk);
        }]);
    if ($method === 'POST') { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form)); }
    curl_exec($curl);
    $reply = ['status'=>(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'errno'=>curl_errno($curl), 'body'=>json_decode($body, true)];
    curl_close($curl);
    return $reply;
}

function cd_audit(PDO $db, string $reference, string $action, array $details = []): void
{
    $db->prepare('INSERT INTO booking_schedule_events (reference,action,recorded_at,detail_json) VALUES (?,?,?,?)')
        ->execute([$reference, $action, gmdate('c'), json_encode($details, JSON_THROW_ON_ERROR)]);
}

function cd_claim(PDO $db, string $reference): array
{
    $q = $db->prepare('SELECT * FROM booking_confirmations WHERE reference=?'); $q->execute([$reference]);
    return $q->fetch() ?: [];
}

function cd_booking(PDO $db, string $reference): array
{
    $q = $db->prepare('SELECT b.*,s.rush_status,s.reschedule_required,s.appointment_json FROM bookings b
        LEFT JOIN booking_scheduling s ON b.reference=s.reference WHERE b.reference=?');
    $q->execute([$reference]); return $q->fetch() ?: [];
}

function cd_verify(array $reply, array $expected, string $calendar, string $uid): void
{
    $events = $reply['body']['events'] ?? null;
    if (($reply['status'] ?? 0) !== 200 || isset($reply['body']['error']) || isset($reply['body']['errors'])
        || !is_array($events) || !array_is_list($events) || count($events) !== 1) cd_stop('Saved event details could not be verified.');
    $e = $events[0];
    if (($e['uid'] ?? null) !== $uid || ($e['caluid'] ?? null) !== $calendar || ($e['title'] ?? null) !== $expected['title']
        || ($e['isallday'] ?? null) !== false || ($e['isprivate'] ?? null) !== true
        || !in_array($e['transparency'] ?? null, [0,'0'], true)) cd_stop('Saved event identity or privacy does not match.');
    $zone = new DateTimeZone($e['dateandtime']['timezone'] ?? 'UTC');
    foreach (['start','end'] as $key) if (!is_string($e['dateandtime'][$key] ?? null)
        || booking_calendar_timestamp($e['dateandtime'][$key], $zone, false)
        !== booking_calendar_timestamp($expected['dateandtime'][$key], new DateTimeZone('UTC'), false)) cd_stop('Saved event interval does not match.');
    if (!is_array($e['attendees'] ?? [])) cd_stop('Unexpected attendee data.');
    foreach (($e['attendees'] ?? []) as $attendee) if (!is_array($attendee) || empty($e['organizer'])
        || ($attendee['email'] ?? null) !== $e['organizer']) cd_stop('Unexpected attendee on saved event.');
}

function cd_run(string $root, string $reference, bool $retry, ?callable $transport = null, ?callable $acknowledge = null): void
{
    if (!preg_match('/^[A-F0-9]{10,32}$/D', $reference)) cd_stop('Invalid booking reference.');
    require_once $root . '/server/booking-calendar-client.php';
    $reader = booking_calendar_config($root . '/zoho-calendar.json');
    $cfg = booking_calendar_config($root . '/zoho-confirmation.json');
    $configHash = hash_file('sha256', $root . '/zoho-confirmation.json');
    if (!$cfg['enabled'] || !$reader['enabled'] || ($cfg['confirmation_stage'] ?? '') !== 'test'
        || ($cfg['confirmation_enabled'] ?? null) !== true || ($cfg['invitations_enabled'] ?? null) !== false
        || $cfg['calendar_uid'] !== $reader['calendar_uid'] || ($cfg['calendar_owner_id'] ?? '') !== ($reader['calendar_owner_id'] ?? '')
        || !preg_match('/^[a-f0-9]{32}$/D', $cfg['calendar_uid'])
        || !in_array('ZohoCalendar.event.CREATE', $cfg['requested_scopes'] ?? [], true)) cd_stop('Test controls or calendar identity do not match.');
    $dbPath = $root . '/data/bookings.sqlite';
    $lockPath = $root . '/booking-confirmation.lock';
    foreach ([$dbPath, $lockPath] as $path) {
        $info = @lstat($path);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || ($info['mode'] & 0077) !== 0
            || $info['uid'] !== fileowner($root) || $info['nlink'] !== 1) cd_stop('Private ledger or existing confirmation lock is unavailable.');
    }
    $lock = fopen($lockPath, 'rb');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) cd_stop('Another calendar operation is running.');
    try {
        $db = new PDO('sqlite:file:' . $dbPath . '?mode=' . ($retry ? 'rw' : 'ro'), null, null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>5]);
        if (!$retry) $db->exec('PRAGMA query_only=ON');
        $row = cd_booking($db, $reference); $claim = cd_claim($db, $reference);
        if (!$row || !$claim) cd_stop('Existing booking and confirmation attempt are required.');
        if (($row['status'] ?? '') !== 'deposit_paid_test' || !$row['deposit_paid_at'] || !$row['approved_at']
            || $row['reschedule_required'] || !in_array($row['rush_status'], ['approved','not_requested'], true)
            || !filter_var($row['email'], FILTER_VALIDATE_EMAIL) || strcasecmp($row['email'], (string)($cfg['test_recipient_email'] ?? '')) !== 0
            || !in_array(strtolower(trim($row['photographer'])), ['david','david cro','david j cro','david j. cro'], true)
            || (int)$row['duration_minutes'] < 15 || (int)$row['duration_minutes'] > 1440) cd_stop('Paid test booking or staff review does not qualify.');
        if ($claim['state'] !== 'uncertain' || $claim['event_uid'] || $claim['invitation_state'] !== 'none'
            || $claim['calendar_uid'] !== $cfg['calendar_uid']) cd_stop('Only an uncertain attempt without an event ID or invitation qualifies.');
        $event = json_decode($claim['event_json'], true, 32, JSON_THROW_ON_ERROR);
        $request = json_decode($row['request_json'], true, 32, JSON_THROW_ON_ERROR);
        $appointment = $row['appointment_json'] ? json_decode($row['appointment_json'], true, 16, JSON_THROW_ON_ERROR) : $request['appointment'];
        $start = (int)$claim['planned_start']; $end = (int)$claim['planned_end'];
        $window = booking_calendar_date($appointment['date'])->setTime((int)substr($appointment['time'],0,2), (int)substr($appointment['time'],3,2))->getTimestamp();
        if (($appointment['windowMinutes'] ?? null) !== 120 || !in_array($appointment['time'], ['07:00','09:00','11:00','13:00','15:00','17:00'], true)
            || $start <= time() || $window <= time() || $start < $window || $start >= $window + 7200
            || $end - $start !== (int)$row['duration_minutes'] * 60
            || ($event['dateandtime']['start'] ?? '') !== gmdate('Ymd\THis\Z', $start)
            || ($event['dateandtime']['end'] ?? '') !== gmdate('Ymd\THis\Z', $end)
            || ($event['isprivate'] ?? null) !== true || ($event['notify_attendee'] ?? null) !== 0
            || ($event['calendar_alarm'] ?? null) !== false || ($event['attendees'] ?? null) !== []
            || ($event['reminders'] ?? null) !== [] || ($event['conference'] ?? null) !== 'none'
            || !str_contains($event['title'] ?? '', $reference)) cd_stop('Saved event, arrival window, duration or privacy controls do not match.');
        $previous = $db->prepare("SELECT detail_json FROM booking_schedule_events WHERE reference=? AND action='diagnostic_retry_started_v1'");
        $previous->execute([$reference]);
        if ($previous->fetch()) cd_stop('This single-use diagnostic retry was already attempted. Do not retry again.');
        $transport ??= 'cd_http';
        $auth = $transport('POST', 'https://accounts.zoho.com/oauth/v2/token', [
            'grant_type'=>'refresh_token','client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'refresh_token'=>$cfg['refresh_token']], null);
        $token = $auth['body']['access_token'] ?? null;
        if (($auth['status'] ?? 0) !== 200 || !is_string($token) || $token === '' || preg_match('/[^\x21-\x7e]/', $token)) cd_stop('Writer authorization failed.');
        $secrets = [$cfg['client_id'],$cfg['client_secret'],$cfg['refresh_token'],$token];
        $call = static fn(string $method, string $path, ?array $form = null): array => $transport($method, 'https://calendar.zoho.com'.$path, $form, $token);
        $path = '/api/v1/calendars/' . $cfg['calendar_uid'] . '/events';
        $records = [];
        $snapshot = booking_calendar_read_busy(static function ($p,$q) use ($call,&$records): array {
            $reply=$call('GET',$p.'?'.http_build_query($q)); $records=$reply['body']['events'] ?? []; return $reply;
        }, $cfg['calendar_uid'], ['start'=>$start-129600,'end'=>$end+129600]);
        foreach ($records as $record) {
            if (isset($record['message'])) continue;
            if (str_contains(strtoupper((string)($record['title'] ?? '').' '.(string)($record['description'] ?? '')), $reference)
                || ($record['title'] ?? '') === $event['title']) cd_stop('An existing booking event is visible. Use Recheck Calendar Result; no retry is allowed.');
        }
        foreach ($snapshot['busy'] as [$a,$b]) if ($a < $end && $b > $start) cd_stop('Zoho now blocks this shoot interval. No retry is allowed.');
        $q=$db->prepare('SELECT reference FROM booking_confirmations WHERE reference<>? AND planned_start<? AND planned_end>?');
        $q->execute([$reference,$end,$start]); if ($q->fetch()) cd_stop('Another local booking claims this interval.');
        $checkedAt=time();
        echo "Booking: $reference\nPaid test booking and saved staff review: PASS\nSaved event privacy and interval: PASS\n";
        echo "Fresh Zoho read (36 hours either side): PASS\nMatching booking event in that range: NONE\nShoot interval conflicts: NONE\nInvitations: DISABLED\n";
        if (!$retry) { echo "READ-ONLY CHECK COMPLETE. No calendar creation or booking change was attempted.\n"; return; }
        if (!is_callable($acknowledge) || $acknowledge($reference) !== 'RETRY '.$reference) cd_stop('Retry was not acknowledged. Nothing was changed.');
        if (time()-$checkedAt>60 || $window<=time()) cd_stop('Calendar check expired before acknowledgement. No retry was attempted.');
        if (!hash_equals($configHash, hash_file('sha256',$root.'/zoho-confirmation.json'))) cd_stop('Confirmation settings changed during the check.');
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (cd_booking($db,$reference) !== $row || cd_claim($db,$reference) !== $claim) cd_stop('Booking changed during the check.');
            cd_audit($db,$reference,'diagnostic_retry_started_v1',['event_payload_sha256'=>hash('sha256',$claim['event_json'])]);
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        $stage='create';
        try {
            $reply=$call('POST',$path,['eventdata'=>$claim['event_json']]);
            $safe=cd_summary($reply,$secrets); cd_audit($db,$reference,'diagnostic_create_response_v1',$safe);
            echo 'Creation response: '.json_encode($safe,JSON_UNESCAPED_SLASHES)."\n";
            $uid=$reply['body']['events'][0]['uid'] ?? '';
            if (!in_array($reply['status'] ?? 0,[200,201],true) || !is_string($uid) || !preg_match('/^[A-Za-z0-9@._-]{1,256}$/D',$uid)
                || isset($reply['body']['error']) || isset($reply['body']['errors'])) cd_stop('Creation did not return a verifiable event ID. Booking remains blocked.');
            $db->prepare('UPDATE booking_confirmations SET event_uid=? WHERE reference=?')->execute([$uid,$reference]);
            $stage='verify_event'; $detail=$call('GET',$path.'/'.rawurlencode($uid));
            cd_audit($db,$reference,'diagnostic_detail_response_v1',cd_summary($detail,$secrets));
            cd_verify($detail,$event,$cfg['calendar_uid'],$uid);
            $stage='verify_conflicts'; $listed=[];
            booking_calendar_read_busy(static function($p,$q)use($call,&$listed):array{
                $r=$call('GET',$p.'?'.http_build_query($q));$listed=$r;return $r;
            },$cfg['calendar_uid'],['start'=>$start,'end'=>$end]);
            $own=array_filter($listed['body']['events'],static fn($e)=>($e['uid']??null)===$uid);
            if(count($own)!==1)cd_stop('Created event is not uniquely visible. Use Recheck Calendar Result.');
            $listed['body']['events']=array_values(array_filter($listed['body']['events'],static fn($e)=>($e['uid']??null)!==$uid));
            $others=booking_calendar_read_busy(static fn($p,$q)=>$listed,$cfg['calendar_uid'],['start'=>$start,'end'=>$end]);
            if($others['busy'])cd_stop('A calendar conflict appeared. Booking remains blocked.');
            $stage='save_confirmation'; $db->exec('BEGIN IMMEDIATE');
            try {
                if(cd_booking($db,$reference)!==$row)cd_stop('Booking changed while the event was being verified.');
                $save=$db->prepare("UPDATE booking_confirmations SET state='confirmed',confirmed_at=? WHERE reference=? AND state='uncertain' AND event_uid=?");
                $save->execute([gmdate('c'),$reference,$uid]);
                if($save->rowCount()!==1)cd_stop('Confirmation changed while the event was being verified.');
                cd_audit($db,$reference,'calendar_confirmed',['event_uid'=>$uid]);$db->exec('COMMIT');
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
            echo "Calendar event created and verified: PASS\nBooking marked confirmed. No invitation or payment was sent.\n";
        }catch(Throwable $e){
            cd_audit($db,$reference,'diagnostic_retry_stopped_v1',['stage'=>$stage]);
            echo "STOP at stage: $stage. Booking remains protected; do not repeat the retry.\n";
            throw $e;
        }
    } finally { fclose($lock); }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (!in_array(count($argv),[2,3],true) || (isset($argv[2]) && $argv[2] !== '--retry-once')) cd_stop('Usage: diagnose-calendar-confirmation.php REFERENCE [--retry-once]');
        $root=is_dir(dirname(__DIR__).'/_private/server') ? dirname(__DIR__).'/_private' : dirname(__DIR__);
        $effectiveUid=function_exists('posix_geteuid') ? posix_geteuid() : null;
        if($effectiveUid===null && preg_match('/^Uid:\s+\d+\s+(\d+)/m',(string)@file_get_contents('/proc/self/status'),$uidMatch))$effectiveUid=(int)$uidMatch[1];
        if ($effectiveUid !== fileowner($root)) cd_stop('Run this tool as the sitesee account, not root.');
        cd_run($root,$argv[1],isset($argv[2]),null,static function(string $ref):string{
            echo "This permits ONE calendar creation retry for the SAME saved test booking.\n";
            echo "Only proceed after manually checking SiteSee Photography and finding no matching event.\n";
            echo "Type RETRY $ref to acknowledge that check and authorize the single retry: ";
            return trim((string)fgets(STDIN));
        });
    }catch(Throwable $e){
        // Only fixed messages from this file are suitable for display.
        echo 'STOP: '.($e instanceof RuntimeException && $e->getFile()===__FILE__ ? $e->getMessage() : 'Diagnostic check stopped; no raw exception details displayed.')."\n";
        exit(1);
    }
} elseif (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
