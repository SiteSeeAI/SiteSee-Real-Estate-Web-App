<?php
declare(strict_types=1);
$temp=sys_get_temp_dir().'/sitesee-window-move-'.bin2hex(random_bytes(6));mkdir($temp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('q',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$temp.'/bookings.sqlite');
require_once __DIR__.'/../_private/server/booking-confirmation.php';
require_once __DIR__.'/../tools/move-test-booking-window.php';
$db=booking_db();$now=new DateTimeImmutable('2026-09-27T01:00:00Z');$date='2026-09-30';
$config=['confirmation_stage'=>'test','confirmation_enabled'=>true,'enabled'=>true,'test_recipient_email'=>'agent@example.test'];
$make=static function(string $ref)use($db,$date):void{
    booking_capture($db,['action'=>'request_appointment','market'=>'residential',
        'details'=>['email'=>'agent@example.test'],'appointment'=>['date'=>$date,'time'=>'07:00','windowEnd'=>'09:00','windowMinutes'=>120],
        'quote'=>['totalCents'=>25690,'platformMonthlyCents'=>0]],$ref,true);
    $db->prepare("UPDATE bookings SET status='deposit_paid_test',checkout_state='paid',deposit_paid_at=?,approved_at=?,
        availability_checked_at=?,duration_minutes=95,photographer='David J Cro',stripe_session_id=?,stripe_payment_intent_id=? WHERE reference=?")
        ->execute(['2026-09-27T00:35:00Z','2026-09-27T00:40:00Z','2026-09-27T00:40:00Z','cs_test_'.$ref,'pi_'.$ref,$ref]);
};
$checks=0;$check=static function(bool $ok,string $why)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $fn,string $why)use($check):void{try{$fn();}catch(Throwable $e){$check(true,$why);return;}throw new RuntimeException($why);};
$calls=0;$free=static function($range)use(&$calls):array{$calls++;return $range+['busy'=>[]];};
$run=static fn($ref,$read=null,$clock=null)=>mw_apply($db,$ref,$date,'07:00','09:00',$config,$read??$free,$clock??$now);
$make('AABBCC0011');$make('AABBCC0022');
$old=mw_row($db,'AABBCC0011');$other=mw_row($db,'AABBCC0022');
$t=static fn($clock)=>(new DateTimeImmutable($date.' '.$clock,new DateTimeZone('America/Chicago')))->getTimestamp();
$db->prepare("INSERT INTO booking_confirmations (reference,state,calendar_uid,planned_start,planned_end,event_json,created_at) VALUES (?,'confirmed','fixture',?,?,?,?)")
    ->execute(['AABBCC0022',$t('07:00'),$t('09:00'),'{}',$now->format('c')]);
$claim=$db->query('SELECT * FROM booking_confirmations')->fetchAll();
$check(str_contains($run('AABBCC0011'),'Arrival window updated'),'Move to the next clear window succeeds.');
$new=mw_row($db,'AABBCC0011');$expected=$old;$expected['requested_utc']='2026-09-30T14:00:00Z';$expected['approved_at']=null;$expected['availability_checked_at']=null;
$check($new===$expected,'All price, deposit, session, token, original request and other booking fields are preserved.');
$appointment=json_decode(mw_schedule($db,'AABBCC0011')['appointment_json'],true);
$check($appointment['time']==='09:00'&&$appointment['windowEnd']==='11:00'&&$appointment['windowMinutes']===120,'New two-hour arrival window is derived correctly.');
$check(mw_row($db,'AABBCC0022')===$other&&$db->query('SELECT * FROM booking_confirmations')->fetchAll()===$claim,'Older booking and reservation remain identical.');
$check($db->query("SELECT COUNT(*) FROM booking_schedule_events WHERE reference='AABBCC0011'")->fetchColumn()===2,'Replacement notice and operator-selected window are audited.');
$callsBefore=$calls;$check(str_contains($run('AABBCC0011'),'Already moved'),'Rerun is a no-op.');
$check($calls===$callsBefore,'Rerun makes no provider call.');
$reject(static fn()=>$run('AABBCC0022'),'Already-confirmed booking is refused.');
$make('AABBCC0033');$before=mw_row($db,'AABBCC0033');$sched=mw_schedule($db,'AABBCC0033');
$blocked=static fn($range)=>$range+['busy'=>[[$t('09:00'),$t('11:00')]]];
$reject(static fn()=>$run('AABBCC0033',$blocked),'New Zoho conflict blocks the move.');
$check(mw_row($db,'AABBCC0033')===$before&&mw_schedule($db,'AABBCC0033')===$sched,'Blocked move rolls back unchanged.');
$reject(static fn()=>$run('AABBCC0033',null,new DateTimeImmutable('2026-09-29T00:00:00Z')),'Replacement window must satisfy current 72-hour notice.');
$db->exec("CREATE TRIGGER audit_failure BEFORE INSERT ON booking_schedule_events WHEN NEW.reference='AABBCC0033' BEGIN SELECT RAISE(ABORT,'simulated write failure'); END");
$reject(static fn()=>$run('AABBCC0033'),'Audit write failure stops the operation.');
$check(mw_row($db,'AABBCC0033')===$before&&mw_schedule($db,'AABBCC0033')===$sched,'Audit failure rolls back both updated records.');
$db->exec('DROP TRIGGER audit_failure');
$db->exec("UPDATE bookings SET status='awaiting_deposit_test' WHERE reference='AABBCC0033'");
$callsBefore=$calls;$reject(static fn()=>$run('AABBCC0033'),'Unpaid request refused.');$check($calls===$callsBefore,'Ineligible request makes no provider call.');
$db=null;foreach(glob($temp.'/*')as$f)unlink($f);rmdir($temp);
echo "TEST booking window move: $checks checks passed.\n";
