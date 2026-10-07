<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/sitesee-ms-schedule-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('q',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__.'/../_private/server/booking-invitation.php';
$checks=0;
function ok(bool $yes,string $why):void{global $checks;++$checks;if(!$yes)throw new RuntimeException($why);}
function no(callable $fn,string $why):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$why);return;}throw new RuntimeException($why);}
$db=booking_db();$now=new DateTimeImmutable('now',new DateTimeZone('America/Chicago'));$day=$now->modify('+10 days')->format('Y-m-d');
$config=booking_scheduling_ms_config();$lock=$tmp.'/confirm.lock';
function paid_ms(string $ref,string $time='09:00'):array{
 global $db,$now,$day;
 $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
 'details'=>['first'=>'Test','last'=>'Agent','company'=>'Example','email'=>'sales@re.sitesee.ai','phone'=>'5555550100','street'=>'123 Main St','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],
 'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
 'appointment'=>['date'=>$day,'time'=>$time,'windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'0123456789','cancellationAccepted'=>true]],$now);
 booking_capture($db,$s,$ref,true);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,checkout_state='paid' WHERE reference=?")->execute([gmdate('c'),$ref]);
 return booking_get($db,$ref);
}
$events=[];$writes=0;$reads=0;$timeout=false;$race=false;$mailCalls=0;
$connection=static function($method,$path,$body=null)use(&$events,&$writes,&$reads,&$timeout,&$race):array{
 if($method==='POST'){
  ++$writes;booking_scheduling_ms_payload_check($body);ok(!str_contains(json_encode($body),'0123456789'),'Access code excluded.');
  $event=$body+['id'=>'immutable-'.$writes,'organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'isCancelled'=>false,'type'=>'singleInstance'];
  $events[$event['id']]=$event;if($timeout)throw new RuntimeException('Lost response fixture');return ['status'=>201,'body'=>$event];
 }
 ok($method==='GET','Read/creation only.');++$reads;
 if(str_contains($path,'calendarView')){
  parse_str(parse_url($path,PHP_URL_QUERY),$q);$a=strtotime($q['startDateTime']);$b=strtotime($q['endDateTime']);
  $v=array_values(array_filter($events,static fn($e)=>booking_ms_timestamp($e['start'])<$b && booking_ms_timestamp($e['end'])>$a));
  if($race&&$v){$e=$v[0];$e['id']='external';$v[]=$e;}return ['status'=>200,'body'=>['value'=>$v]];
 }
 if(str_contains($path,'/events/')){ $id=rawurldecode(basename($path));return ['status'=>isset($events[$id])?200:404,'body'=>$events[$id]??[]]; }
 return ['status'=>200,'body'=>['id'=>BOOKING_MS_CALENDAR,'canEdit'=>true,'isDefaultCalendar'=>true,'owner'=>['address'=>BOOKING_MS_MAILBOX]]];
};
$legacyStart=strtotime($day.' 07:00 America/Chicago');
$db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at,confirmed_at,invitation_state) VALUES('LEGACY0001','confirmed',?,'old@zoho',?,?,'{}',?,?,'sent')")
 ->execute([str_repeat('a',32),$legacyStart,$legacyStart+7200,gmdate('c'),gmdate('c')]);
$confirm=static fn($ref)=>booking_confirm_appointment($db,$ref,$config,$connection,$now,$lock);
paid_ms('BBB0000005','07:00');booking_review_paid($db,'BBB0000005',95,'David',true);no(fn()=>$confirm('BBB0000005'),'Legacy Zoho reservation blocks new Microsoft booking.');ok($writes===0,'No write for legacy conflict.');$reads=0;
paid_ms('BBB0000001');no(fn()=>$confirm('BBB0000001'),'Unreviewed cannot confirm.');ok($writes===0&&$reads===0,'Guard precedes provider call.');
booking_review_paid($db,'BBB0000001',150,'David',true);
$before=booking_get($db,'BBB0000001');$c=$confirm('BBB0000001');ok($c['state']==='confirmed'&&$writes===1,'Microsoft confirmation verified.');
ok($c['calendar_uid']==='microsoft:'.BOOKING_MS_CALENDAR,'Provider preserved explicitly.');ok(booking_get($db,'BBB0000001')===$before,'No payment/pricing/booking change.');
$count=$reads;$confirm('BBB0000001');ok($writes===1&&$reads===$count,'Duplicate click makes no calls.');
$ical=booking_invitation_message($before,$c)['ical'];$start=strtotime($day.' 09:00 America/Chicago');
ok(str_contains($ical,'DTSTART:'.gmdate('Ymd\THis\Z',$start))&&str_contains($ical,'DTEND:'.gmdate('Ymd\THis\Z',$start+7200)),'Invitation retains arrival window and stable ICS identity.');
$mail=static function($message)use(&$mailCalls){++$mailCalls;return true;};
$events[$c['event_uid']]['start']['dateTime']=gmdate('Y-m-d\TH:i:s',$start+60);
no(fn()=>booking_send_invitation($db,'BBB0000001',$config,$mail,$connection),'Manual event edit blocks invitation.');ok($mailCalls===0,'No stale invitation.');
$events[$c['event_uid']]['start']['dateTime']=gmdate('Y-m-d\TH:i:s',$start);
booking_send_invitation($db,'BBB0000001',$config,$mail,$connection);booking_send_invitation($db,'BBB0000001',$config,$mail,$connection);ok($mailCalls===1,'Exactly one explicit invitation.');
paid_ms('BBB0000002');booking_review_paid($db,'BBB0000002',95,'David',true);no(fn()=>$confirm('BBB0000002'),'Full shoot overlapping local reservation blocked.');
// Provider deletion does not silently free a stored reservation.
$events=[];no(fn()=>$confirm('BBB0000002'),'Deleted provider event does not free local reservation.');
$alts=booking_scheduling_alternatives($db,'BBB0000002',$config,$connection,$now);ok(count($alts)>0,'Alternatives available.');
$choices=array_filter($alts,static fn($w)=>$w['date']===$day&&$w['time']==='09:00');ok(!$choices,'Blocked window not offered.');
$row=booking_get($db,'BBB0000002');$a=$alts[0];
no(fn()=>booking_scheduling_change_window($db,'BBB0000002',$a['date'],$a['time'],'stale',$config,$connection,$now,$lock),'Stale form blocked.');
booking_scheduling_change_window($db,'BBB0000002',$a['date'],$a['time'],hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),$config,$connection,$now,$lock);
$after=booking_get($db,'BBB0000002');ok($after['approved_at']===null&&booking_request($after)['appointment']['windowEnd']===$a['end_time'],'Changing window resets review and updates window end.');
foreach(['deposit_cents','deposit_paid_at','stripe_session_id','stripe_payment_intent_id','approved_cents','quote_cents','rush_fee_cents','checkout_state']as$k)ok($after[$k]===$row[$k],'Financial value preserved:'.$k);
no(fn()=>$confirm('BBB0000002'),'Changed window requires review again.');
paid_ms('BBB0000003','15:00');booking_review_paid($db,'BBB0000003',95,'David',true);$timeout=true;no(fn()=>$confirm('BBB0000003'),'Lost create response is uncertain.');$timeout=false;
$wc=$writes;no(fn()=>$confirm('BBB0000003'),'Cannot recreate after timeout.');ok($writes===$wc,'No duplicate write.');
$claim=booking_reconcile_confirmation($db,'BBB0000003',$config,$connection,$lock);ok($claim['state']==='confirmed'&&$writes===$wc,'Read-only recovery finds same transaction.');
paid_ms('BBB0000004','17:00');booking_review_paid($db,'BBB0000004',95,'David',true);$race=true;no(fn()=>$confirm('BBB0000004'),'Concurrent external conflict blocks finalization.');$race=false;
ok(booking_confirmation_get($db,'BBB0000004')['state']==='uncertain','Conflict retains reservation and requires review.');
// Exact transport guard, not just mocked orchestration.
$httpCalls=[];$http=static function($method,$url,$headers,$body)use(&$httpCalls){$httpCalls[]=[$method,$url,$headers,$body];return str_contains($url,'/token')?['status'=>200,'body'=>['access_token'=>'fixture']]:['status'=>201,'body'=>['id'=>'fixture']];};
$wire=booking_scheduling_ms_transport(['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>BOOKING_MS_APP,'client_secret'=>'fixture'],$http);
$expected=json_decode($claim['event_json'],true);$wire('POST',booking_ms_calendar_path().'/events',$expected);
$bad=$expected;$bad['attendees']=[['emailAddress'=>['address'=>'other@example.com']]];no(fn()=>$wire('POST',booking_ms_calendar_path().'/events',$bad),'Transport forbids attendees.');
no(fn()=>$wire('DELETE',booking_ms_event_path('fixture')),'Transport forbids deletion.');no(fn()=>$wire('PATCH',booking_ms_event_path('fixture'),$expected),'Transport forbids patch.');
no(fn()=>$wire('GET','https://example.com/calendar'),'Foreign host forbidden.');
ok(count($httpCalls)===2&&str_contains(implode(' ',$httpCalls[1][2]),'ImmutableId'),'Unsafe operations make no HTTP call; immutable IDs requested.');
$sorted=$config;ksort($sorted);ok(booking_scheduling_valid_config($sorted),'JSON property order irrelevant.');$sorted['enabled']=1;ok(!booking_scheduling_valid_config($sorted),'Configuration types remain strict.');
$db=null;foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);echo "Microsoft TEST scheduling: $checks checks passed\n";
