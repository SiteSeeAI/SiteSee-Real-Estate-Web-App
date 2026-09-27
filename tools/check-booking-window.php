<?php
declare(strict_types=1);
/** CLI-only evidence collection. No booking, calendar, payment or mail writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function bw_time(int $timestamp): string
{
    return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d g:i A T');
}

function bw_appointment(array $row): array
{
    if (!empty($row['schedule_appointment_json'])) return json_decode($row['schedule_appointment_json'],true,32,JSON_THROW_ON_ERROR);
    return json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR)['appointment'];
}

function bw_report(PDO $db, array $row, array $remote, ?DateTimeImmutable $now = null): void
{
    $now ??= new DateTimeImmutable('now');
    $appointment = bw_appointment($row);
    $date = $appointment['date']; $time = $appointment['time'];
    $duration = (int)$row['duration_minutes'];
    $start = booking_calendar_date($date)->setTime((int)substr($time,0,2),(int)substr($time,3,2))->getTimestamp();
    $end = $start + 7200;
    $query = $db->prepare("SELECT recorded_at FROM booking_schedule_events WHERE reference=? AND action='replacement_window_requested' ORDER BY id DESC LIMIT 1");
    $query->execute([$row['reference']]);
    $requestedAt = new DateTimeImmutable($query->fetchColumn() ?: $row['created_at']);
    $rush = $row['rush_status'] === 'approved';
    $claims = $db->query('SELECT reference,state,planned_start,planned_end FROM booking_confirmations')->fetchAll();
    $local = $remote; $local['busy'] = [];
    foreach ($claims as $claim) $local['busy'][] = [(int)$claim['planned_start'],(int)$claim['planned_end']];
    $combined = $remote; $combined['busy'] = array_merge($remote['busy'],$local['busy']);
    $empty = $remote; $empty['busy'] = [];
    echo 'Booking: '.$row['reference']."\nMode: READ ONLY\n";
    echo 'Payment: '.($row['status'] === 'deposit_paid_test' ? 'TEST deposit recorded' : 'not recorded as paid')."\n";
    echo 'Staff review: '.(!empty($row['approved_at']) ? 'recorded' : 'missing')."\n";
    echo 'Reviewed shoot duration: '.$duration." minutes\n";
    echo 'Arrival window: '.bw_time($start).' to '.bw_time($end)."\n";
    echo 'Notice measured from: '.bw_time($requestedAt->getTimestamp())."\n";
    echo 'Required notice: '.($rush ? '12' : '72')." hours\n";
    echo 'Window still in future: '.($start > $now->getTimestamp() ? 'YES' : 'NO')."\n";
    echo "A full shoot may end after the arrival window, but must start before it ends.\n";
    $all = [];
    foreach (['Notice only'=>$empty,'Zoho only'=>$remote,'Stored reservations only'=>$local,'Combined confirmation check'=>$combined] as $label=>$snapshot) {
        $windows = booking_available_windows($date,$rush,$duration,$snapshot,$requestedAt,1);
        $match = array_values(array_filter($windows,static fn($w)=>$w['time']===$time));
        echo $label.': '.(count($match)===1 ? 'FITS' : 'BLOCKED')."\n";
        if ($label === 'Combined confirmation check') $all = $windows;
    }
    echo "Nearby Zoho busy intervals (titles and private details omitted):\n";
    $count = 0;
    foreach ($remote['busy'] as [$a,$b]) if ($a < $end+$duration*60 && $b > $start) {
        echo '  '.bw_time($a).' to '.bw_time($b)."\n"; $count++;
    }
    if (!$count) echo "  NONE\n";
    echo "Nearby stored booking reservations:\n"; $count = 0;
    foreach ($claims as $claim) if ((int)$claim['planned_start'] < $end+$duration*60 && (int)$claim['planned_end'] > $start) {
        $ref = preg_match('/^[A-F0-9]{10,32}$/D',$claim['reference']) ? $claim['reference'] : '[invalid reference]';
        $state = in_array($claim['state'],['creating','uncertain','confirmed'],true) ? $claim['state'] : '[other state]';
        echo '  '.$ref.' / '.$state.' / '.bw_time((int)$claim['planned_start']).' to '.bw_time((int)$claim['planned_end'])."\n"; $count++;
    }
    if (!$count) echo "  NONE\n";
    echo "Available arrival windows on the same date (suggestions, not reservations):\n";
    foreach ($all as $window) echo '  '.$window['date'].' '.$window['time'].'-'.$window['end_time']." Central\n";
    if (!$all) echo "  NONE\n";
    echo "No booking, payment, calendar event, invitation, credential or application setting was changed.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $stage = 'startup';
    ini_set('display_errors','0');
    try {
        if (count($argv)!==2 || !preg_match('/^[A-F0-9]{10,32}$/D',$argv[1])) throw new RuntimeException();
        $root = '/home/sitesee/.sitesee-real-estate';
        $stage = 'load existing application';
        // The calendar reader has no dependency on PHP-FPM-only payment or
        // pricing environment variables, and creates no application tables.
        require_once $root.'/server/booking-calendar-client.php';
        $stage = 'open existing database read-only';
        $db = new PDO('sqlite:file:'.$root.'/data/bookings.sqlite?mode=ro',null,null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5]);
        $db->exec('PRAGMA query_only=ON');
        $stage = 'read booking';
        $query = $db->prepare("SELECT b.*,COALESCE(s.rush_status,'not_requested') AS rush_status,
            s.appointment_json AS schedule_appointment_json FROM bookings b
            LEFT JOIN booking_scheduling s ON b.reference=s.reference WHERE b.reference=?");
        $query->execute([$argv[1]]); $row = $query->fetch();
        if (!$row) throw new RuntimeException();
        $appointment = bw_appointment($row);
        $range = booking_availability_range($appointment['date'],(int)$row['duration_minutes'],1);
        $stage = 'read existing calendar configuration';
        $config = booking_calendar_config($root.'/zoho-confirmation.json');
        $reader = booking_calendar_config($root.'/zoho-calendar.json');
        if (!$config['enabled'] || !$reader['enabled'] || ($config['confirmation_stage'] ?? '') !== 'test'
            || $config['calendar_uid'] !== $reader['calendar_uid']
            || ($config['calendar_owner_id'] ?? '') !== ($reader['calendar_owner_id'] ?? '')) throw new RuntimeException();
        $stage = 'authorize calendar read';
        $reply = booking_zoho_request('https://accounts.zoho.com/oauth/v2/token',[
            'grant_type'=>'refresh_token','client_id'=>$config['client_id'],
            'client_secret'=>$config['client_secret'],'refresh_token'=>$config['refresh_token']]);
        $token = $reply['body']['access_token'] ?? null;
        if (($reply['status'] ?? 0)!==200 || !is_string($token) || $token==='' || preg_match('/[^\x21-\x7e]/',$token)
            || isset($reply['body']['error']) || isset($reply['body']['errors'])) throw new RuntimeException();
        $stage = 'read fresh Zoho busy intervals';
        $snapshot = booking_calendar_read_busy(static fn($p,$q)=>booking_zoho_request('https://calendar.zoho.com'.$p.'?'.http_build_query($q),null,$token),$config['calendar_uid'],$range);
        $stage = 'compare window and reservations';
        bw_report($db,$row,$snapshot);
    } catch (Throwable $error) {
        fwrite(STDERR,'STOP at '.$stage.". No calendar event, payment or invitation was created.\n");
        exit(1);
    }
}
