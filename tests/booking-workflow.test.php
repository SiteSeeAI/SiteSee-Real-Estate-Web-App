<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/sitesee-workflow-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('x',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__.'/../_private/server/booking-workflow.php';
$checks=0;
function ok(bool $yes,string $why):void{global $checks;++$checks;if(!$yes)throw new RuntimeException($why);}
function no(callable $f,string $why):void{try{$f();}catch(Throwable){ok(true,$why);return;}throw new RuntimeException($why);}
$db=booking_db();booking_communication_schema($db);$day=(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');
function paid(string $ref,string $time='09:00'):array{
 global $db,$day;
 $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
 'details'=>['first'=>'David','last'=>'Cro','company'=>'SiteSee','email'=>'sales@re.sitesee.ai','phone'=>'5555550100','street'=>'123 Main','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],
 'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
 'appointment'=>['date'=>$day,'time'=>$time,'windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'Yes','cancellationAccepted'=>true]]);
 booking_capture($db,$s,$ref,true);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=? WHERE reference=?")->execute([gmdate('c'),$ref]);
 return booking_get($db,$ref);
}
$events=[];$calendarWrites=0;$calendarFail=false;
$calendar=static function($method,$path,$body=null)use(&$events,&$calendarWrites,&$calendarFail){
 if($calendarFail)throw new RuntimeException('secret-provider-payload');
 if($method==='POST'){++$calendarWrites;$e=$body+['id'=>'event-'.$calendarWrites,'organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'type'=>'singleInstance','isCancelled'=>false];$events[$e['id']]=$e;return ['status'=>201,'body'=>$e];}
 ok($method==='GET','Calendar recovery never writes.');
 if(str_contains($path,'calendarView'))return ['status'=>200,'body'=>['value'=>array_values($events)]];
 if(str_contains($path,'/events/'))return ['status'=>200,'body'=>$events[rawurldecode(basename($path))]??[]];
 return ['status'=>200,'body'=>['id'=>BOOKING_MS_CALENDAR,'canEdit'=>true,'isDefaultCalendar'=>true,'owner'=>['address'=>BOOKING_MS_MAILBOX]]];
};
$crmFail=false;$crmWrites=0;$wrongEmail=false;$wrongOrg=false;$history=[];$crmDuplicate=false;$changeDuringRead=false;
$crm=static function($method,$path,$body=null)use(&$crmFail,&$crmWrites,&$wrongEmail,&$wrongOrg,&$history,&$crmDuplicate,&$changeDuringRead,$db){
 if($crmFail)throw new RuntimeException('secret CRM credentials');
 if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>$wrongOrg?'999':'100']]]];
 if($path==='/users?type=CurrentUser')return ['status'=>200,'body'=>['users'=>[['id'=>'200']]]];
 if(str_starts_with($path,'/Contacts/search'))return ['status'=>200,'body'=>['data'=>[
  ['id'=>'300','Full_Name'=>'<script>bad</script>','Email'=>'sales@re.sitesee.ai'],['id'=>'301','Full_Name'=>'Other exact match','Email'=>'sales@re.sitesee.ai']], 'info'=>['more_records'=>false]]];
 if($path==='/Contacts/300'){
  if($changeDuringRead)$db->exec("UPDATE bookings SET crm_contact_id='888' WHERE reference='CCD0000002'");
  return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>$wrongEmail?'different@example.com':'sales@re.sitesee.ai']]]];
 }
 if(str_ends_with($path,'/Emails'))return ['status'=>200,'body'=>['Emails'=>$history,'info'=>['more_records'=>false]]];
 if($method==='POST'){
  ++$crmWrites;ok(str_ends_with($path,'/actions/associate_email'),'Recovery only writes CRM association.');
  if($crmDuplicate)return ['status'=>400,'body'=>['Emails'=>[['code'=>'DUPLICATE_DATA']]]];
  $e=$body['Emails'][0];$history=[$e+['message_id'=>'crm-message','time'=>$e['date_time']]];
  return ['status'=>200,'body'=>['Emails'=>[['code'=>'SUCCESS','details'=>['message_id'=>'crm-message']]]]];
 }
 throw new RuntimeException('Unexpected CRM route.');
};
$graphCalls=0;$mailFail=false;$deliveryFail=false;$sent=null;
$graph=static function($method,$path,$body=null)use(&$graphCalls,&$mailFail,&$deliveryFail,&$sent){
 ++$graphCalls;ok($method==='GET','Readiness/recovery never creates or sends mail.');
 if($mailFail)throw new RuntimeException('secret mailbox token');
 if(str_contains($path,'sentitems'))return ['status'=>200,'body'=>['value'=>[]]];
 if(str_contains($path,'%24filter='))return ['status'=>$deliveryFail?403:200,'body'=>['value'=>$sent?[$sent+['receivedDateTime'=>gmdate('c')]]:[]]];
 return ['status'=>$sent?200:404,'body'=>$sent??[]];
};
$deps=['calendar_config'=>booking_scheduling_ms_config(),'calendar'=>$calendar,'lock_path'=>$tmp.'/calendar.lock',
 'crm_config'=>['org_id'=>'100','user_id'=>'200','sync_mode'=>'api','original_sync_mode'=>'api'],'crm'=>$crm,'mail_config'=>[],'graph'=>$graph];
function ticket(array $row):array{return ['reference'=>$row['reference'],'fingerprint'=>booking_workflow_fingerprint($row),'ids'=>['300','301'],'expires'=>time()+900];}
$row=paid('CCD0000001');$q=$db->query('SELECT COUNT(*) FROM booking_contact_links')->fetchColumn();
$report=booking_workflow_check($db,$row['reference'],$deps);
ok(count($report['candidates'])===2,'Ambiguous exact-email matches are both offered, never auto-selected.');
ok(booking_get($db,$row['reference'])===$row && $db->query('SELECT COUNT(*) FROM booking_contact_links')->fetchColumn()===$q,'Readiness does not save a contact or change booking.');
ok($calendarWrites===0&&$crmWrites===0,'Readiness makes no provider writes.');
no(fn()=>booking_workflow_link($db,$row['reference'],'300',[],$deps),'No session selection cannot link.');
$expired=ticket($row);$expired['expires']=time()-1;no(fn()=>booking_workflow_link($db,$row['reference'],'300',$expired,$deps),'Expired selection blocked.');
$wrong=ticket($row);$wrong['reference']='CCD0000002';no(fn()=>booking_workflow_link($db,$row['reference'],'300',$wrong,$deps),'Cross-booking selection blocked.');
no(fn()=>booking_workflow_link($db,$row['reference'],'999',ticket($row),$deps),'Unlisted contact cannot link.');
$wrongEmail=true;no(fn()=>booking_workflow_link($db,$row['reference'],'300',ticket($row),$deps),'Changed provider email blocks linking.');$wrongEmail=false;
$wrongOrg=true;no(fn()=>booking_workflow_link($db,$row['reference'],'300',ticket($row),$deps),'Wrong organization blocked.');$wrongOrg=false;
booking_workflow_link($db,$row['reference'],'300',ticket($row),$deps);
$expected=$row;$expected['crm_contact_id']='300';ok(booking_get($db,$row['reference'])===$expected,'Contact link preserves all payment/review/scheduling fields.');
booking_review_paid($db,$row['reference'],150,'David',true);
no(fn()=>booking_workflow_link($db,$row['reference'],'300',ticket($row),$deps),'Stale booking snapshot blocked.');
$r=booking_workflow_check($db,$row['reference'],$deps);ok(str_contains($r['items']['Calendar'],'currently fits'),'Requested window checked against reviewed duration.');
$claim=booking_confirm_appointment($db,$row['reference'],$deps['calendar_config'],$calendar,null,$tmp.'/calendar.lock');
$writes=$calendarWrites;$r=booking_workflow_recover($db,$row['reference'],$deps);ok($calendarWrites===$writes&&str_contains($r['items']['Invitation'],'No invitation attempt'),'Recovery never creates missing invitation.');
$db->exec("UPDATE booking_confirmations SET state='uncertain',event_uid=NULL WHERE reference='CCD0000001'");
$r=booking_workflow_recover($db,$row['reference'],$deps);
ok(booking_confirmation_get($db,$row['reference'])['state']==='confirmed'&&$calendarWrites===$writes,'Combined recovery reconciles a lost calendar response without creation.');
$message=booking_invitation_message(booking_get($db,$row['reference']),$claim);$key=booking_communication_enqueue($db,$row['reference'],'invitation',$message,$claim);
$db->exec("UPDATE booking_confirmations SET invitation_state='uncertain' WHERE reference='CCD0000001'");
booking_communication_update($db,$key,['submission_state'=>'uncertain']);
$r=booking_workflow_recover($db,$row['reference'],$deps);ok(str_contains($r['items']['Delivery / CRM'],'Waiting')&&$crmWrites===0,'Lost draft ID never triggers resend or CRM false sent history.');
booking_communication_update($db,$key,['provider_message_id'=>'message-1']);
$sent=['id'=>'message-1','isDraft'=>false,'internetMessageId'=>'<actual-message@example.com>','sentDateTime'=>gmdate('c'),
 'from'=>['emailAddress'=>['address'=>BOOKING_MAIL_SENDER]],'toRecipients'=>[['emailAddress'=>['address'=>'sales@re.sitesee.ai']]],
 'ccRecipients'=>[],'bccRecipients'=>[],'replyTo'=>[['emailAddress'=>['address'=>BOOKING_MAIL_SENDER]]],'subject'=>$message['subject']];
$deliveryFail=true;$r=booking_workflow_recover($db,$row['reference'],$deps);
ok(booking_confirmation_get($db,$row['reference'])['invitation_state']==='sent','Saved uncertain attempt recovered by actual sent copy.');
ok(booking_communication_get($db,$key)['crm_state']==='associated'&&str_contains($r['items']['Recipient mailbox'],'not verified'),'Delivery access failure does not hide successful CRM association.');
$deliveryFail=false;$w=$crmWrites;$r=booking_workflow_recover($db,$row['reference'],$deps);
ok(booking_communication_get($db,$key)['delivery_state']==='recipient_copy_observed'&&$crmWrites===$w&&$calendarWrites===$writes,'Repeated recovery finishes delivery without duplicating calendar or CRM writes.');
// Multiple independent problems are returned, never raw provider secrets.
$calendarFail=$mailFail=$crmFail=true;$r=booking_workflow_check($db,$row['reference'],$deps);
ok(count(array_filter($r['items'],static fn($v)=>str_contains($v,'CHECK REQUIRED')))===3,'All independent connection blockers shown together.');
ok(!str_contains(json_encode($r),'secret'),'Provider payloads redacted.');$calendarFail=$mailFail=$crmFail=false;
$second=paid('CCD0000002');$changeDuringRead=true;no(fn()=>booking_workflow_link($db,$second['reference'],'300',ticket($second),$deps),'Concurrent local contact change preserved.');$changeDuringRead=false;
ok(booking_get($db,$second['reference'])['crm_contact_id']==='888','Concurrent change was not overwritten.');
booking_review_paid($db,$second['reference'],95,'David',true);$r=booking_workflow_check($db,$second['reference'],$deps);
ok(str_contains($r['items']['Calendar'],'blocked')&&count($r['alternatives'])>0,'Blocked window includes alternatives in same check.');
ok(!array_filter($r['alternatives'],static fn($w)=>$w['date']===$day&&$w['time']==='09:00'),'Held window not offered.');
// Existing CRM duplicate with a precise original ID can be recovered without insertion.
booking_communication_update($db,$key,['crm_state'=>'provider_duplicate','crm_message_id'=>null]);$w=$crmWrites;
$r=booking_workflow_recover($db,$row['reference'],$deps);ok(booking_communication_get($db,$key)['crm_state']==='associated'&&$crmWrites===$w,'Exact existing CRM Message-ID recovered without a write.');
booking_communication_update($db,$key,['crm_state'=>'provider_duplicate','crm_message_id'=>null]);unset($history[0]['original_message_id']);
$r=booking_workflow_recover($db,$row['reference'],$deps);ok(count($r['history'])===1&&booking_communication_get($db,$key)['crm_state']==='provider_duplicate','Ambiguous CRM email requires explicit staff review.');
// UI rendering escapes provider-supplied names and keeps local evidence distinct.
$status=['calendar'=>'Microsoft','claim'=>$claim,'mail'=>false,'contact'=>'Not linked','link'=>false,'can_send'=>false];
$html=booking_workflow_html($row,$status,'csrf',$report);
ok(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Provider contact names escaped.');
ok(str_contains($html,'Check Booking Readiness')&&str_contains($html,'Recover Booking Status')&&str_contains($html,'contact_verified'),'Unified controls and explicit selection present.');
no(fn()=>booking_workflow_reader($graph)('POST','/users/sales%40re.sitesee.ai/messages','data'),'Graph write guard blocks any accidental send.');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');no(fn()=>booking_workflow_check($db,$row['reference'],$deps),'Global TEST gate remains mandatory.');
$db=null;foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);echo "Unified booking workflow: $checks checks passed\n";
