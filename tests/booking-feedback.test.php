<?php
declare(strict_types=1);
require_once __DIR__ . '/../_private/server/booking-feedback.php';
require_once __DIR__ . '/../_private/server/booking-calendar-client.php';
$checks = 0;
function feedback_check(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($why); }
function feedback_throws(callable $fn, string $class = BookingCalendarUnavailable::class): void {
    try { $fn(); } catch (Throwable $error) { feedback_check($error instanceof $class, 'Unexpected exception: ' . get_class($error)); return; }
    throw new RuntimeException('Expected an exception.');
}
$now = new DateTimeImmutable('2026-09-25T09:00:00-05:00');
$payload = ['market'=>'residential', 'date'=>'2026-09-30', 'time'=>'09:00', 'rush'=>false,
    'state'=>['category'=>'average','package'=>'custom','sqft'=>2000,'selected'=>['photo']]];
$allDay = static fn(array $range): array => booking_calendar_read_busy(fn()=>['status'=>200,'body'=>['events'=>[
    ['isallday'=>true,'dateandtime'=>['start'=>'20260930','end'=>'20261001']],
]]], 'fixture', $range);
$result = booking_feedback($payload, $allDay, $now);
feedback_check($result['duration_minutes'] === 70, 'Server calculates full residential duration.');
feedback_check($result['date_windows'] === [] && $result['selected_available'] === false, 'Whole day is unavailable.');
feedback_check($result['alternatives'][0] === ['date'=>'2026-10-01','time'=>'07:00','end_time'=>'09:00'], 'Offers next available day.');
feedback_check(!str_contains(json_encode($result), 'planned_') && !str_contains(json_encode($result), 'busy'), 'Public output excludes planned starts and raw calendar intervals.');
$tampered = booking_feedback($payload + ['duration_minutes'=>1, 'total'=>1], $allDay, $now);
feedback_check($tampered === $result, 'Browser duration and price cannot shorten the shoot.');
$commercial = $payload;
$commercial['market'] = 'commercial';
$commercial['state'] = ['category'=>'mid','selected'=>['photo']];
feedback_check(booking_feedback($commercial, $allDay, $now)['duration_minutes'] === 75, 'Commercial uses the upper end of the current photography duration range.');
$manual = $payload;
$manual['state']['package'] = 'silver';
$manualResult = booking_feedback($manual, function(){ throw new RuntimeException('Calendar must not be called for unknown duration.'); }, $now);
feedback_check($manualResult['state'] === 'manual_review' && !isset($manualResult['selected_available']), 'Packages with unknown duration require review, never appear free.');
$unknown = $payload;
$unknown['state']['selected'][] = 'floor';
feedback_check(booking_feedback($unknown, fn()=>throw new RuntimeException('Must not read'), $now)['state'] === 'manual_review', 'Additional capture time requires review.');
foreach (['2026-10-02','2026-10-03','2026-10-05'] as $date) {
    $repeat = $payload;
    $repeat['date'] = $date;
    $read = static fn(array $range): array => booking_calendar_read_busy(fn()=>['status'=>200,'body'=>['events'=>array_map(
        static fn(string $day): array => ['isallday'=>false,'dateandtime'=>['start'=>$day.'T140000+0000','end'=>$day.'T150000+0000','timezone'=>'UTC']],
        ['20261002','20261003','20261005'])]], 'fixture', $range);
    $answer = booking_feedback($repeat, $read, $now);
    feedback_check($answer['selected_available'] === true, 'Each recurring occurrence leaves a later arrival inside 9–11 available for a 70-minute shoot.');
    $snapshot = $read(booking_availability_range($date, 70));
    $planned = booking_available_windows($date, false, 70, $snapshot, $now);
    $nine = array_values(array_filter($planned, fn($w)=>$w['date']===$date && $w['time']==='09:00'));
    feedback_check($nine[0]['planned_start_utc'] === $date.'T15:00:00Z', 'Every recurring 9–10 block moves internal arrival to 10.');
}
$empty = static fn(array $range): array => $range + ['busy'=>[]];
$notice = $payload; $notice['date']='2026-09-26';
feedback_check(booking_feedback($notice,$empty,$now)['selected_available'] === false, 'Standard notice remains 72 hours.');
$notice['rush']=true;
feedback_check(booking_feedback($notice,$empty,$now)['selected_available'] === true, 'Rush notice remains 12 hours.');
feedback_throws(fn()=>booking_feedback($payload,fn()=>throw new BookingCalendarUnavailable('Network failure'),$now));
foreach ([array_replace($payload,['market'=>'invalid']), array_replace($payload,['date'=>'2026-02-30']), array_replace($payload,['rush'=>'yes'])] as $bad) {
    feedback_throws(fn()=>booking_feedback($bad,$empty,$now), InvalidArgumentException::class);
}

$directory = sys_get_temp_dir() . '/sitesee-calendar-test-' . bin2hex(random_bytes(6));
mkdir($directory,0700);
$config = ['schema_version'=>1,'setup'=>'sitesee-calendar-readonly','enabled'=>true,'timezone'=>'America/Chicago',
    'accounts_base'=>'https://accounts.zoho.com','calendar_base'=>'https://calendar.zoho.com',
    'connection_verified_at'=>time(),'client_id'=>'fixture-client','client_secret'=>'fixture-secret','refresh_token'=>'fixture-refresh','calendar_uid'=>'fixture+calendar'];
$path = $directory.'/config.json';
file_put_contents($path,json_encode($config)); chmod($path,0600);
feedback_check(booking_calendar_config($path)===$config, 'Private connection configuration is accepted.');
chmod($path,0644);clearstatcache();
feedback_throws(fn()=>booking_calendar_config($path));
chmod($path,0600);clearstatcache();
$calls = [];
$transport = static function (string $url, ?array $form, ?string $token) use (&$calls): array {
    $calls[] = [$url,$form,$token];
    if ($form !== null) return ['status'=>200,'body'=>['access_token'=>'fixture-access','expires_in'=>3600]];
    return ['status'=>200,'body'=>['events'=>[['isallday'=>false,'title'=>'PRIVATE-TITLE','attendees'=>['PRIVATE-EMAIL'],
        'dateandtime'=>['start'=>'20261002T140000+0000','end'=>'20261002T150000+0000','timezone'=>'UTC']]]]];
};
$range = booking_availability_range('2026-10-02',70);
$cache = $directory.'/cache.json';
$snapshot = booking_calendar_snapshot($config,$range,$transport,$cache);
feedback_check(count($calls)===2 && $calls[0][0]==='https://accounts.zoho.com/oauth/v2/token', 'Only OAuth refresh and calendar GET run.');
feedback_check($calls[0][1]['grant_type']==='refresh_token' && $calls[1][1]===null && $calls[1][2]==='fixture-access', 'Credentials stay in OAuth body and calendar authorization.');
feedback_check(str_contains($calls[1][0],'/fixture%2Bcalendar/events?') && str_contains($calls[1][0],'byinstance=true'), 'Owned calendar UID is encoded and recurring instances requested.');
feedback_check(booking_calendar_snapshot($config,$range,$transport,$cache)===$snapshot && count($calls)===2, 'Recent busy snapshot is reused.');
$otherRange=booking_availability_range('2026-10-03',70);
booking_calendar_snapshot($config,$otherRange,$transport,$cache);
feedback_check(count($calls)===3, 'Range change reuses OAuth token but reads new event coverage.');
$saved=file_get_contents($cache);
feedback_check(!str_contains($saved,'PRIVATE-TITLE') && !str_contains($saved,'PRIVATE-EMAIL') && !str_contains($saved,'fixture-secret'), 'Cache excludes event details and refresh credentials.');
feedback_check((fileperms($cache)&0077)===0, 'Runtime token cache is private.');
feedback_throws(fn()=>booking_calendar_snapshot(array_replace($config,['enabled'=>false]),$range,$transport,$cache));
$failing = static fn()=>['status'=>401,'body'=>['error'=>'fixture-secret']];
feedback_throws(fn()=>booking_calendar_snapshot($config,$range,$failing,$cache));
feedback_check(!isset(json_decode(file_get_contents($cache),true)['access_token']), 'Rejected token is removed for a later safe refresh.');
feedback_throws(fn()=>booking_zoho_request('https://example.com/events',null,'private-token'));
feedback_throws(fn()=>booking_zoho_request('https://calendar.zoho.com/api/v1/calendars/x/events',['wrong'=>'post'],null));
foreach (glob($directory.'/*') as $file) unlink($file);
rmdir($directory);
echo 'Calendar feedback: '.$checks." checks passed.\n";
