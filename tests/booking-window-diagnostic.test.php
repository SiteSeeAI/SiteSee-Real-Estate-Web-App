<?php
declare(strict_types=1);
require_once __DIR__.'/../_private/server/booking-calendar-client.php';
require_once __DIR__.'/../tools/check-booking-window.php';
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE booking_schedule_events (id INTEGER,reference TEXT,action TEXT,recorded_at TEXT)');
$db->exec('CREATE TABLE booking_confirmations (reference TEXT,state TEXT,planned_start INTEGER,planned_end INTEGER)');
$appointment = ['date'=>'2026-09-30','time'=>'07:00','windowEnd'=>'09:00','windowMinutes'=>120];
$row = ['reference'=>'AABBCC0011','duration_minutes'=>95,'created_at'=>'2026-09-27T00:21:00Z',
    'status'=>'deposit_paid_test','approved_at'=>'2026-09-27T00:40:00Z','rush_status'=>'not_requested',
    'request_json'=>json_encode(['appointment'=>$appointment]),'schedule_appointment_json'=>null];
$range = booking_availability_range('2026-09-30',95,1);
$now = new DateTimeImmutable('2026-09-27T00:47:00Z');
$t = static fn($clock)=>(new DateTimeImmutable('2026-09-30 '.$clock,new DateTimeZone('America/Chicago')))->getTimestamp();
$run = static function(array $busy)use($db,$row,$range,$now):string{
    ob_start();bw_report($db,$row,$range+['busy'=>$busy],$now);return ob_get_clean();
};
$checks = 0;
$check = static function(bool $ok,string $message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($message);};
$out=$run([]);
$check(str_contains($out,'Combined confirmation check: FITS'),'Clear calendar fits.');
$out=$run([[$t('07:00'),$t('09:00')]]);
$check(str_contains($out,'Zoho only: BLOCKED')&&str_contains($out,'Combined confirmation check: BLOCKED'),'Zoho conflict distinguished.');
$check(str_contains($out,'2026-09-30 09:00-11:00 Central'),'Alternatives shown.');
$db->prepare('INSERT INTO booking_confirmations VALUES (?,?,?,?)')->execute(['AABBCC0022','uncertain',$t('07:00'),$t('09:00')]);
$out=$run([]);
$check(str_contains($out,'Zoho only: FITS')&&str_contains($out,'Stored reservations only: BLOCKED'),'Uncertain local reservation independently blocks.');
$check(str_contains($out,'AABBCC0022 / uncertain'),'Blocking local reference identified.');
$db->exec('DELETE FROM booking_confirmations');
$db->prepare('INSERT INTO booking_confirmations VALUES (?,?,?,?)')->execute(['AABBCC0033','confirmed',$t('08:40'),$t('09:00')]);
$out=$run([[$t('07:30'),$t('08:00')]]);
$check(str_contains($out,'Zoho only: FITS')&&str_contains($out,'Stored reservations only: FITS')&&str_contains($out,'Combined confirmation check: BLOCKED'),'Combined fragmented gap diagnosed.');
$db->exec('PRAGMA query_only=ON');
$before=$db->query('SELECT * FROM booking_confirmations')->fetchAll();
$run([]);
$check($before===$db->query('SELECT * FROM booking_confirmations')->fetchAll(),'Report works with database writes prohibited.');
echo "Booking window diagnostic: $checks checks passed.\n";
