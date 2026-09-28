<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/sitesee-lifecycle-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('q',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__.'/../_private/server/booking-lifecycle-ui.php';
$checks=0;
function ok(bool $yes,string $why):void{global $checks;++$checks;if(!$yes)throw new RuntimeException($why);}
function no(callable $f,string $why):void{try{$f();}catch(Throwable){ok(true,$why);return;}throw new RuntimeException($why);}
$db=booking_db();booking_communication_schema($db);$now=time();$day=(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');
$events=[];$writes=0;$mode='ok';$reads=0;$otherCalendar=false;
$api=static function($method,$path,$body=null,$etag=null)use(&$events,&$writes,&$mode,&$reads,&$otherCalendar){
    if($mode==='outage')return ['status'=>503,'body'=>[]];
    if($method==='POST'){$id='event-'.(++$writes);$e=$body+['id'=>$id,'@odata.etag'=>'W/"v1"','organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'isCancelled'=>false,'type'=>'singleInstance'];$events[$id]=$e;return ['status'=>201,'body'=>$e];}
    if(in_array($method,['PATCH','DELETE'],true)){
        ++$writes;$id=rawurldecode(basename($path));ok($etag===$events[$id]['@odata.etag'],'Fresh version submitted.');
        if($mode==='precondition')return ['status'=>412,'body'=>[]];
        if($method==='PATCH'){$events[$id]=array_replace($events[$id],$body);$events[$id]['@odata.etag']='W/"v'.$writes.'"';$r=['status'=>200,'body'=>$events[$id]];}
        else{unset($events[$id]);$r=['status'=>204,'body'=>[]];}
        if($mode==='lost')throw new RuntimeException('lost-response');return $r;
    }
    ok($method==='GET','Recovery makes GET requests only.');++$reads;
    if(str_contains($path,'calendarView')){parse_str(parse_url($path,PHP_URL_QUERY),$q);$a=strtotime($q['startDateTime']);$b=strtotime($q['endDateTime']);
        return ['status'=>200,'body'=>['value'=>array_values(array_filter($events,static fn($e)=>booking_ms_timestamp($e['start'])<$b&&booking_ms_timestamp($e['end'])>$a))]];}
    if(str_contains($path,'/events/')){$id=rawurldecode(basename($path));if($otherCalendar&&str_contains($path,'/calendars/'))return ['status'=>404,'body'=>['error'=>['code'=>'ErrorItemNotFound']]];
        return isset($events[$id])?['status'=>200,'body'=>$events[$id]]:['status'=>404,'body'=>['error'=>['code'=>'ErrorItemNotFound']]];}
    return ['status'=>200,'body'=>['id'=>BOOKING_MS_CALENDAR,'canEdit'=>true,'isDefaultCalendar'=>true,'owner'=>['address'=>BOOKING_MS_MAILBOX]]];
};
$deps=['calendar'=>$api,'lock_path'=>$tmp.'/lock','now'=>$now];
function paid(string $ref,string $time='09:00'):array{
    global$db,$day,$api,$deps;
    $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
        'details'=>['first'=>'Test','last'=>'Agent','company'=>'Example','email'=>'cro@sitesee.ai','phone'=>'5555550100','street'=>'123 Main','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],
        'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
        'appointment'=>['date'=>$day,'time'=>$time,'windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'0123456789','cancellationAccepted'=>true]]);
    booking_capture($db,$s,$ref,true);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,stripe_session_id=?,stripe_payment_intent_id=?,consent_at='original',consent_version='test-card-reuse-v1' WHERE reference=?")->execute([gmdate('c'),'cs_test_'.$ref,'pi_'.$ref,$ref]);
    booking_review_paid($db,$ref,95,'David',true);booking_confirm_appointment($db,$ref,booking_scheduling_ms_config(),$api,null,$deps['lock_path']);
    $db->prepare("UPDATE booking_confirmations SET invitation_state='sent',invitation_sent_at='original-sent',invitation_recipient='cro@sitesee.ai' WHERE reference=?")->execute([$ref]);
    return booking_get($db,$ref);
}
function done_notice(string $ref):void{global$db;$s=booking_lifecycle_state($db,$ref);booking_communication_update($db,'lifecycle-'.$s['revision'].':'.$ref,['submission_state'=>'sent_observed']);}
$ref='AAA0000001';$before=paid($ref);$claim=booking_confirmation_get($db,$ref);$link=booking_management_issue($db,$ref);$token=explode('.',parse_url($link,PHP_URL_FRAGMENT),2)[1];
ok(!str_contains($link,'?')&&booking_management_auth($db,$ref,$token),'Fragment link authenticates.');
ok(!booking_management_auth($db,'AAA0000002',$token)&&!booking_management_auth($db,$ref,str_repeat('0',64)),'Wrong booking/token denied.');
$link2=booking_management_issue($db,$ref);ok(!booking_management_auth($db,$ref,$token),'Rotation revokes old links and sessions.');
$token=explode('.',parse_url($link2,PHP_URL_FRAGMENT),2)[1];booking_lifecycle_set($db,$ref,['token_expires'=>time()-1]);ok(!booking_management_auth($db,$ref,$token),'Expired token denied.');
$choices=booking_lifecycle_windows($db,$ref,$day,$deps);ok(count($choices)>0,'Own reservation excluded from alternatives.');
$w=array_values(array_filter($choices,static fn($w)=>$w['time']==='13:00'))[0];$fp=booking_lifecycle_fingerprint($db,$ref);
no(fn()=>booking_lifecycle_change($db,$ref,'reschedule','stale','customer',$w['date'],$w['time'],$deps),'Stale form denied.');
$n=$writes;booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$w['date'],$w['time'],$deps);ok($writes===$n+1,'One PATCH updates existing event.');
$c=booking_confirmation_get($db,$ref);ok($c['event_uid']===$claim['event_uid']&&$c['calendar_uid']===$claim['calendar_uid'],'Calendar and event identities preserved.');
foreach(['invitation_state','invitation_sent_at','invitation_recipient']as$k)ok($c[$k]===$claim[$k],'Original invitation preserved: '.$k);
$after=booking_get($db,$ref);foreach(array_keys($before)as$k)if(!in_array($k,['requested_utc','schedule_appointment_json'],true))ok($before[$k]===$after[$k],'Preserved: '.$k);
$m=booking_communication_get($db,'lifecycle-1:'.$ref);$message=json_decode($m['message_json'],true);
ok(str_contains($message['ical'],'SEQUENCE:1')&&str_contains($message['ical'],'METHOD:REQUEST')&&str_contains($message['ical'],'UID:sitesee-arrival-test-'.$ref),'Update ICS stable UID and increased sequence.');
ok(!str_contains(json_encode($message),'0123456789'),'No access codes in notice.');
$unfolded=str_replace(["\r\n ","\r\n\t"],'',$message['ical']);
ok(preg_match('~https://re\.sitesee\.ai/manage-appointment\.php#'.$ref.'\.([a-f0-9]{64})~',$message['plain'],$linkMatch)===1,'Update email retains management link.');
ok(str_contains($unfolded,$linkMatch[0])&&booking_management_auth($db,$ref,$linkMatch[1]),'Calendar DESCRIPTION retains an authentic link.');
$hash=booking_lifecycle_state($db,$ref)['token_hash'];
ok(booking_management_notice_link($db,$ref)===$linkMatch[0]&&booking_lifecycle_state($db,$ref)['token_hash']===$hash,'Valid saved link reused without revoking customer session.');
booking_lifecycle_set($db,$ref,['token_expires'=>time()-1]);$renewed=booking_management_notice_link($db,$ref);
ok($renewed!==$linkMatch[0]&&!booking_management_auth($db,$ref,$linkMatch[1]),'Expired saved link replaced.');

no(fn()=>booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$w['date'],$w['time'],$deps),'Repeated reschedule denied without second PATCH.');
no(fn()=>booking_send_invitation($db,$ref),'Original invitation cannot be sent after update.');
no(fn()=>booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'customer','','',$deps),'Unfinished earlier notice blocks another change.');
done_notice($ref);$fp=booking_lifecycle_fingerprint($db,$ref);$mode='lost';
no(fn()=>booking_lifecycle_change($db,$ref,'cancel',$fp,'customer','','',$deps),'Lost deletion response is recoverable.');
ok(booking_lifecycle_pending($db,$ref)!==false,'Durable uncertain operation saved.');$n=$writes;$mode='ok';
booking_lifecycle_sync($db,$ref,$deps);ok($writes===$n&&booking_lifecycle_state($db,$ref)['state']==='cancelled','Read-only recovery completes cancellation without another delete.');
booking_lifecycle_change($db,$ref,'cancel',$fp,'customer','','',$deps);ok($writes===$n,'Repeated cancel no-op.');
ok(booking_get($db,$ref)===$after,'Cancellation preserves complete paid booking.');
$m=json_decode(booking_communication_get($db,'lifecycle-2:'.$ref)['message_json'],true);
ok(str_contains($m['ical'],'METHOD:CANCEL')&&str_contains($m['ical'],'STATUS:CANCELLED')&&str_contains($m['ical'],'SEQUENCE:2'),'Cancellation ICS correct.');
ok(booking_lifecycle_busy($db,['busy'=>[]])['busy']===[],'Cancelled reservation released.');
$recover=booking_workflow_recover($db,$ref,['calendar'=>$api]);
ok(str_contains($recover['items']['Calendar'],'Cancellation verified'),'Original recovery recognizes verified cancellation.');
ok($writes===$n&&booking_lifecycle_busy($db,['busy'=>[]])['busy']===[],'Cancelled recovery neither writes calendar nor recreates hold.');
$reappeared=json_decode(booking_confirmation_get($db,$ref)['event_json'],true)+['id'=>$c['event_uid'],'@odata.etag'=>'W/"v99"','organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'isCancelled'=>false,'type'=>'singleInstance'];
$present=static fn($method,$path)=>str_contains($path,'/events/')?['status'=>200,'body'=>$reappeared]:$api($method,$path);
$recover=booking_workflow_recover($db,$ref,['calendar'=>$present]);
ok(str_contains($recover['items']['Calendar'],'an event exists for this cancelled booking'),'Reappeared event is flagged, never declared absent.');
$status=booking_workflow_status($db,booking_get($db,$ref));
ok(str_contains(booking_workflow_html(booking_get($db,$ref),$status,'csrf'),'<dd>cancelled</dd>'),'Top calendar status reflects cancellation without changing original confirmation evidence.');

$mode='outage';$recover=booking_workflow_recover($db,$ref,['calendar'=>$api]);$mode='ok';
ok(str_contains($recover['items']['Calendar'],'CHECK REQUIRED')&&!str_contains($recover['items']['Calendar'],'remains held'),'Outage does not claim cancellation is freshly verified or a reservation is held.');

$ref='AAA0000002';paid($ref);$c=booking_confirmation_get($db,$ref);$old=$c['planned_start'];
$events[$c['event_uid']]['start']['dateTime']=gmdate('Y-m-d\TH:i:s',$old+86400);$events[$c['event_uid']]['end']['dateTime']=gmdate('Y-m-d\TH:i:s',$old+86400+5700);
booking_lifecycle_sync($db,$ref,$deps);$s=booking_lifecycle_state($db,$ref);
ok($s['state']==='calendar_changed','Manual move flagged.');$busy=booking_lifecycle_busy($db,['busy'=>[]])['busy'];
ok($busy===[[(int)$old+86400,(int)$old+86400+5700]],'Only actual interval held after a verified move.');
no(fn()=>booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'customer','','',$deps),'Customer cannot overwrite external move.');
$otherCalendar=true;no(fn()=>booking_lifecycle_sync($db,$ref,$deps),'Moved calendar is not deletion.');$otherCalendar=false;
$mode='outage';no(fn()=>booking_lifecycle_sync($db,$ref,$deps),'Provider failure not absence.');$mode='ok';
ok(booking_lifecycle_state($db,$ref)['state']==='calendar_changed','Failure preserves holds.');
$adoptDate=(new DateTimeImmutable('@'.($old+86400)))->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d');$n=$writes;
booking_lifecycle_change($db,$ref,'adopt',booking_lifecycle_fingerprint($db,$ref),'staff',$adoptDate,'09:00',$deps);
ok($writes===$n&&booking_request(booking_get($db,$ref))['appointment']['date']===$adoptDate,'Staff adopts agreed manual move without provider write.');done_notice($ref);
unset($events[$c['event_uid']]);booking_lifecycle_sync($db,$ref,$deps);ok(booking_lifecycle_state($db,$ref)['state']==='active','First missing read keeps hold.');
booking_lifecycle_sync($db,$ref,array_replace($deps,['now'=>$now+61]));ok(booking_lifecycle_state($db,$ref)['state']==='calendar_missing','Second authoritative read releases stale hold.');
ok(booking_lifecycle_busy($db,['busy'=>[]])['busy']===[],'Deleted event stops blocking availability.');
booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'staff','','',array_replace($deps,['now'=>$now+62]));ok($writes===$n,'Staff finalizes verified deletion without extra DELETE.');
$ref='AAA0000003';paid($ref);$fp=booking_lifecycle_fingerprint($db,$ref);$mode='precondition';
no(fn()=>booking_lifecycle_change($db,$ref,'cancel',$fp,'customer','','',$deps),'412 never overwrites concurrent edit.');$mode='ok';
ok(!booking_lifecycle_pending($db,$ref)&&booking_lifecycle_state($db,$ref)['state']==='active','412 retains original appointment without uncertain hold.');
$held=booking_confirmation_lock($deps['lock_path']);no(fn()=>booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'customer','','',$deps),'Concurrent operation blocked.');fclose($held);
// Wire contract is independent of orchestration mocks.
$wireCalls=[];$http=static function($method,$url,$headers,$body)use(&$wireCalls){$wireCalls[]=[$method,$url,$headers,$body];return str_contains($url,'/token')?['status'=>200,'body'=>['access_token'=>'fixture']]:['status'=>204,'body'=>[]];};
$wire=booking_lifecycle_ms_transport(['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>BOOKING_MS_APP,'client_secret'=>'fixture'],'saved',$http);
no(fn()=>$wire('DELETE',booking_ms_event_path('different'),null,'W/"v1"'),'Different event write blocked.');
no(fn()=>$wire('DELETE',booking_ms_event_path('saved'),null,null),'Unversioned deletion blocked.');
no(fn()=>$wire('POST',booking_ms_calendar_path().'/events',[]),'Creation unavailable.');
no(fn()=>$wire('PATCH',booking_ms_event_path('saved'),['attendees'=>[]],'W/"v1"'),'Attendee mutation unavailable.');
$wire('DELETE',booking_ms_event_path('saved'),null,'W/"v1"');ok(count($wireCalls)===2&&in_array('If-Match: W/"v1"',$wireCalls[1][2],true),'Versioned exact event DELETE only.');
$html=booking_lifecycle_html($db,booking_get($db,$ref),'csrf',true);ok(str_contains($html,'Reconcile Calendar')&&str_contains($html,'agreed'),'Staff controls require agreement.');
// Lost PATCH retains both holds; recovery must never submit a second PATCH.
$ref='AAA0000006';paid($ref,'17:00');$old=booking_confirmation_get($db,$ref);$fp=booking_lifecycle_fingerprint($db,$ref);
$next=(new DateTimeImmutable($day))->modify('+3 days')->format('Y-m-d');$choices=booking_lifecycle_windows($db,$ref,$next,$deps);$w=$choices[0];$mode='lost';
no(fn()=>booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$w['date'],$w['time'],$deps),'Lost PATCH recorded before recovery.');$mode='ok';
$pending=booking_lifecycle_pending($db,$ref);$p=json_decode($pending['payload_json'],true);$busy=booking_lifecycle_busy($db,['busy'=>[]])['busy'];
ok(in_array($p['old_interval'],$busy,true)&&in_array($p['new_interval'],$busy,true),'Old and proposed intervals both held after lost PATCH.');
$n=$writes;booking_lifecycle_sync($db,$ref,$deps);ok($writes===$n&&!booking_lifecycle_pending($db,$ref),'Lost PATCH recovered with GET only.');
ok(booking_confirmation_get($db,$ref)['event_uid']===$old['event_uid'],'Recovered move retains event identity.');

// Change notice uses the existing verified sender/contact; delivery and CRM remain independent.
$ref='AAA0000004';paid($ref,'13:00');booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'staff','','',$deps);
$db->prepare('INSERT INTO booking_contact_links VALUES(?,?,?,?,?)')->execute([$ref,'100','300','cro@sitesee.ai',gmdate('c')]);
$key='lifecycle-1:'.$ref;$subject=booking_communication_get($db,$key)['subject'];$draft=true;$mailWrites=0;$crmWrites=0;$crmFail=true;
$mail=static function($method,$path,$body=null)use(&$draft,&$mailWrites,$subject){
    $message=['id'=>'notice-1','isDraft'=>$draft,'internetMessageId'=>'<notice-1@example.test>','sentDateTime'=>gmdate('c'),
        'from'=>['emailAddress'=>['address'=>BOOKING_MAIL_SENDER]],'toRecipients'=>[['emailAddress'=>['address'=>'cro@sitesee.ai']]],
        'replyTo'=>[['emailAddress'=>['address'=>BOOKING_MAIL_SENDER]]],'subject'=>$subject];
    if($method==='POST'){++$mailWrites;if(str_ends_with($path,'/send')){$draft=false;return ['status'=>202,'body'=>[]];}
        ok(str_contains(base64_decode($body),'X-SiteSee-Communication: lifecycle-1:AAA0000004'),'Mail uniquely identifies change.');return ['status'=>201,'body'=>$message];}
    if(str_contains($path,'%24filter='))return ['status'=>200,'body'=>['value'=>[$message+['receivedDateTime'=>gmdate('c')]]]];
    return ['status'=>200,'body'=>$message];
};
$crm=static function($method,$path,$body=null)use(&$crmWrites,&$crmFail){
    if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>'100']]]];
    if($path==='/users?type=CurrentUser')return ['status'=>200,'body'=>['users'=>[['id'=>'200']]]];
    if($path==='/Contacts/300')return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>'cro@sitesee.ai']]]];
    if($method==='POST'){++$crmWrites;return $crmFail?['status'=>503,'body'=>[]]:['status'=>200,'body'=>['Emails'=>[['code'=>'SUCCESS','details'=>['message_id'=>'crm-1']]]]];}
    throw new RuntimeException('unexpected CRM path');
};
$mailDeps=['graph'=>$mail,'crm'=>$crm,'crm_config'=>['org_id'=>'100','user_id'=>'200','sync_mode'=>'api','original_sync_mode'=>'api'],'lock_path'=>$tmp.'/lock'];
booking_lifecycle_notice($db,$ref,true,$mailDeps);$m=booking_communication_get($db,$key);
ok($m['submission_state']==='sent_observed'&&$m['delivery_state']==='recipient_copy_observed'&&$m['crm_state']==='retry_pending','CRM outage does not undo actual sent/recipient evidence.');
$n=$mailWrites;$crmFail=false;booking_lifecycle_notice($db,$ref,false,$mailDeps);
ok($mailWrites===$n&&booking_communication_get($db,$key)['crm_state']==='associated','CRM recovery never sends again.');
booking_lifecycle_notice($db,$ref,true,$mailDeps);ok($mailWrites===$n,'Repeated send control cannot duplicate an attempted notice.');
// Legacy release requires an explicit verified deletion plus a working complete calendar read.
$ref='AAA0000005';paid($ref,'15:00');$c=booking_confirmation_get($db,$ref);unset($events[$c['event_uid']]);
$db->prepare('UPDATE booking_confirmations SET calendar_uid=?,event_uid=? WHERE reference=?')->execute([str_repeat('a',32),'old@zoho',$ref]);
$legacy=static function($method,$path,$body=null){ok($method==='GET','Legacy recovery never changes the provider.');return str_contains($path,'old@zoho')?['status'=>404,'body'=>['error'=>['message'=>'not found']]]:['status'=>200,'body'=>['events'=>[['message'=>'No events found.']]]];};
$legacyDeps=['calendar'=>$legacy,'lock_path'=>$tmp.'/lock','now'=>$now];$original=booking_confirmation_get($db,$ref);
booking_lifecycle_legacy_deleted($db,$ref,booking_lifecycle_fingerprint($db,$ref),$legacyDeps);
booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'staff','','',$legacyDeps);
ok(booking_lifecycle_state($db,$ref)['state']==='cancelled'&&booking_confirmation_get($db,$ref)===$original,'Legacy cancellation retains original calendar/event/invitation identities.');

putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');no(fn()=>booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'customer','','',$deps),'Global TEST gate mandatory.');
$db=null;foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);echo "Appointment lifecycle: $checks checks passed\n";
