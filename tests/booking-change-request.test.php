<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/sitesee-change-request-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('q',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__.'/../_private/server/booking-lifecycle-ui.php';
$completed=false;register_shutdown_function(static function()use(&$completed):void{if(!$completed){fwrite(STDERR,"Request approval assertions did not complete.\n");exit(1);}});
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
function paid(string $ref,string $time='09:00',string $email='sales@re.sitesee.ai'):array{
    global$db,$day,$api,$deps;
    $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
        'details'=>['first'=>'Test','last'=>'Agent','company'=>'Example','email'=>$email,'phone'=>'5555550100','street'=>'123 Main','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],
        'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
        'appointment'=>['date'=>$day,'time'=>$time,'windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'0123456789','cancellationAccepted'=>true]]);
    booking_capture($db,$s,$ref,true);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,stripe_session_id=?,stripe_payment_intent_id=?,consent_at='original',consent_version='test-card-reuse-v1' WHERE reference=?")->execute([gmdate('c'),'cs_test_'.$ref,'pi_'.$ref,$ref]);
    booking_review_paid($db,$ref,95,'David',true);booking_confirm_appointment($db,$ref,booking_scheduling_ms_config(),$api,null,$deps['lock_path']);
    $db->prepare("UPDATE booking_confirmations SET invitation_state='sent',invitation_sent_at='original-sent',invitation_recipient=? WHERE reference=?")->execute([$email,$ref]);
    return booking_get($db,$ref);
}
function done_notice(string $ref):void{global$db;$s=booking_lifecycle_state($db,$ref);booking_communication_update($db,'lifecycle-'.$s['revision'].':'.$ref,['submission_state'=>'sent_observed']);}

$messages=[];$mailWrites=0;$sends=[];$mimeCopies=[];$lostDraft=false;$lostSend=false;$badDraft=false;$graphPaths=[];
$graph=static function($method,$path,$body=null)use($db,&$messages,&$mailWrites,&$sends,&$mimeCopies,&$lostDraft,&$lostSend,&$badDraft,&$graphPaths){
    $graphPaths[]=[$method,$path];
    if($method==='POST'&&!str_ends_with($path,'/send')){
        ++$mailWrites;$mime=base64_decode($body,true);ok(is_string($mime),'MIME is base64 encoded.');
        preg_match('/X-SiteSee-Communication: ([^\r\n]+)/',$mime,$match);$key=$match[1]??'';
        $saved=booking_communication_get($db,$key);ok((bool)$saved,'Saved communication precedes provider creation.');
        $mimeCopies[$key]=$mime;$id='message-'.count($messages);
        $m=['id'=>$id,'isDraft'=>true,'internetMessageId'=>'<'.$id.'@synthetic.test>','sentDateTime'=>gmdate('c'),
            'from'=>['emailAddress'=>['address'=>$saved['sender']]],'toRecipients'=>[['emailAddress'=>['address'=>$saved['recipient']]]],
            'ccRecipients'=>[],'bccRecipients'=>[],'replyTo'=>[['emailAddress'=>['address'=>$saved['sender']]]],'subject'=>$saved['subject']];
        $messages[$id]=$m;if($badDraft)$messages[$id]['ccRecipients']=[['emailAddress'=>['address'=>'unapproved@example.test']]];
        if($lostDraft)throw new RuntimeException('Lost draft creation response');return ['status'=>201,'body'=>$m];
    }
    if($method==='POST'){
        ++$mailWrites;$id=rawurldecode(basename(dirname($path)));ok(isset($messages[$id]),'Send reuses one saved draft.');
        $messages[$id]['isDraft']=false;$sends[]=$messages[$id]['subject'];if($lostSend)throw new RuntimeException('Lost send response');return ['status'=>202,'body'=>[]];
    }
    ok($method==='GET','Recovery reads mail only.');
    if(str_contains($path,'/inbox/messages?'))return ['status'=>200,'body'=>['value'=>array_map(static fn($m)=>$m+['receivedDateTime'=>gmdate('c')],array_values(array_filter($messages,static fn($m)=>!$m['isDraft'])))]];
    $id=rawurldecode(basename(parse_url($path,PHP_URL_PATH)));return ['status'=>200,'body'=>$messages[$id]??[]];
};
$crmWrites=0;$crm=static function($method,$path,$body=null)use(&$crmWrites){
    if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>'100']]]];
    if($path==='/users?type=CurrentUser')return ['status'=>200,'body'=>['users'=>[['id'=>'200']]]];
    if($path==='/Contacts/300')return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>'sales@re.sitesee.ai']]]];
    if($method==='POST'){
        if(str_contains($body['Emails'][0]['subject']??'','Appointment Change Declined')){
            ok(is_string($body['Emails'][0]['content']??null)&&str_contains($body['Emails'][0]['content'],'Your requested appointment change was declined.'),'CRM association contains the saved nonempty decline message.');
            ok($body['Emails'][0]['mail_format']==='html'&&str_contains($body['Emails'][0]['content'],'<br'),'CRM gets escaped HTML representation of the plain email.');
        }
        ++$crmWrites;return ['status'=>200,'body'=>['Emails'=>[['code'=>'SUCCESS','details'=>['message_id'=>'crm-'.$crmWrites]]]]];
    }
    throw new RuntimeException('Unexpected CRM path');
};
$deps+=['graph'=>$graph,'crm'=>$crm,'crm_config'=>['org_id'=>'100','user_id'=>'200','sync_mode'=>'api','original_sync_mode'=>'api']];
$ref='BBB0000001';$before=paid($ref);$claim=booking_confirmation_get($db,$ref);
$db->prepare('INSERT INTO booking_contact_links VALUES(?,?,?,?,?)')->execute([$ref,'100','300','sales@re.sitesee.ai',gmdate('c')]);
$choices=booking_lifecycle_windows($db,$ref,$day,$deps);$w=array_values(array_filter($choices,static fn($w)=>$w['time']==='13:00'))[0];$fp=booking_lifecycle_fingerprint($db,$ref);$n=$writes;
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$w['date'],$w['time'],$deps);
ok($writes===$n&&booking_get($db,$ref)===$before&&booking_confirmation_get($db,$ref)===$claim,'Customer request leaves event/window/financial and invitation evidence exact.');
ok($r['state']==='pending'&&!booking_lifecycle_pending($db,$ref),'Request is not a prepared provider mutation.');
ok(!booking_communication_get($db,'lifecycle-1:'.$ref),'No RSVP exists before manager approval.');
$key='change-request-'.$r['request_id'].':'.$ref;$m=booking_communication_get($db,$key);
ok($m['recipient']===BOOKING_MAIL_SENDER&&$m['submission_state']==='sent_observed'&&$m['delivery_state']==='recipient_copy_observed','Division receives independently tracked plain notification.');
ok($m['crm_state']==='not_applicable'&&$crmWrites===0,'Internal request needs no customer CRM mail association.');
$mime=$mimeCopies[$key];ok(!str_contains($mime,'text/calendar')&&!str_contains($mime,'RSVP=')&&!str_contains($mime,'METHOD:REQUEST'),'Division request contains no RSVP/calendar attachment.');
ok(str_contains($mime,'Confirmed window:')&&str_contains($mime,'Requested window:')&&str_contains($mime,'staff-bookings.php?reference='.$ref),'Division sees both windows and staff review link.');
$html=booking_lifecycle_html($db,booking_get($db,$ref),'csrf',true);
ok(str_contains($html,'Approve Requested Window')&&str_contains($html,'Decline Requested Window'),'Manager gets explicit review actions.');
$html=booking_lifecycle_html($db,booking_get($db,$ref),'csrf',false);
ok(str_contains($html,'Awaiting manager approval')&&!str_contains($html,'Approve Requested Window')&&!str_contains($html,'Confirm Cancellation'),'Customer pending view cannot approve or bypass review.');
$mw=$mailWrites;$same=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$w['date'],$w['time'],$deps);
ok($same['request_id']===$r['request_id']&&$writes===$n&&$mailWrites===$mw,'Browser retry preserves one request, notification and unchanged event.');
no(fn()=>booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'15:00',$deps),'Second different request cannot replace pending approval.');
no(fn()=>booking_lifecycle_change($db,$ref,'cancel',$fp,'customer','','',$deps),'Pending reschedule cannot be bypassed via cancellation.');
no(fn()=>booking_lifecycle_change($db,$ref,'reschedule',$fp,'staff',$w['date'],$w['time'],$deps),'Generic staff change cannot bypass saved-request approval.');
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],false,$deps),'Fresh manager agreement required.');
no(fn()=>booking_change_request_approve($db,'BBB0000009',$r['request_id'],true,$deps),'Request is bound to its booking.');
booking_lifecycle_sync($db,$ref,$deps);ok(hash_equals($r['fingerprint'],booking_lifecycle_fingerprint($db,$ref)),'Unchanged scheduled/staff reconciliation preserves pending request approval.');
$busyId='unrelated';$events[$busyId]=array_replace($events[$claim['event_uid']],['id'=>$busyId,'start'=>['dateTime'=>$w['planned_start_utc'],'timeZone'=>'UTC'],'end'=>['dateTime'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($w['planned_end_utc'])+7200),'timeZone'=>'UTC']]);
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],true,$deps),'Manager approval rechecks availability.');
ok($writes===$n&&booking_change_request_get($db,$ref,$r['request_id'])['state']==='pending','Blocked window retains request and original reservation.');unset($events[$busyId]);
booking_change_request_approve($db,$ref,$r['request_id'],true,$deps);
ok($writes===$n+1&&booking_change_request_get($db,$ref,$r['request_id'])['state']==='approved','Explicit approval applies one provider PATCH.');
$after=booking_get($db,$ref);foreach(array_keys($before)as$k)if(!in_array($k,['requested_utc','schedule_appointment_json'],true))ok($before[$k]===$after[$k],'Approval preserves ledger field '.$k);
$c=booking_confirmation_get($db,$ref);foreach(['event_uid','calendar_uid','invitation_state','invitation_sent_at','invitation_recipient']as$k)ok($c[$k]===$claim[$k],'Approval preserves original identity/evidence '.$k);
$approvedMail=booking_communication_get($db,'lifecycle-1:'.$ref);ok($approvedMail['submission_state']==='sent_observed'&&$approvedMail['crm_state']==='associated','Only approved window sends revised customer RSVP and CRM history.');
ok(count($sends)===2,'One division request notification and one approved customer notice.');
$mw=$mailWrites;booking_change_request_approve($db,$ref,$r['request_id'],true,$deps);ok($writes===$n+1&&$mailWrites===$mw,'Repeated approval cannot duplicate calendar or RSVP.');
// A new request can be declined without changing confirmed data or sending an RSVP.
$fp=booking_lifecycle_fingerprint($db,$ref);$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'15:00',$deps);$before=booking_get($db,$ref);$c=booking_confirmation_get($db,$ref);$n=$writes;$mw=$mailWrites;
$report=booking_change_request_reject($db,$ref,$r['request_id'],true,$deps);
ok(!booking_change_request_pending($db,$ref)&&booking_get($db,$ref)===$before&&booking_confirmation_get($db,$ref)===$c&&$writes===$n,'Declining leaves confirmed event/ledger/original RSVP unchanged.');
$declineKey='change-declined-'.$r['request_id'].':'.$ref;$declineMail=booking_communication_get($db,$declineKey);
ok($mailWrites===$mw+2&&$declineMail['recipient']===$before['email']&&$declineMail['submission_state']==='sent_observed'&&$declineMail['crm_state']==='associated','Decline sends one saved customer notice with sent-copy and CRM evidence.');
$declineMime=$mimeCopies[$declineKey];
ok(str_contains($declineMime,'Appointment Change Declined')&&str_contains($declineMime,'Declined requested window: '.$day.' 15:00–17:00')&&str_contains($declineMime,'Confirmed window when this request was declined: '.$day.' 13:00–15:00'),'Decline notice distinguishes requested and retained windows.');
ok(!str_contains($declineMime,'text/calendar')&&!str_contains($declineMime,'RSVP=')&&!str_contains($declineMime,'METHOD:CANCEL'),'Decline email contains no calendar attachment, RSVP or cancellation.');
$mw=$mailWrites;$cw=$crmWrites;
no(fn()=>booking_change_request_reject($db,$ref,$r['request_id'],true,$deps),'Duplicate decline cannot repeat the decision or its mail.');
booking_change_request_decline_notice($db,$ref,$r['request_id'],true,$deps);
ok($mailWrites===$mw&&$crmWrites===$cw&&$writes===$n,'Repeated decline recovery cannot duplicate mail, CRM association or calendar writes.');
no(fn()=>booking_change_request_decline_notice($db,'BBB0000009',$r['request_id'],true,$deps),'Decline notice is bound to its booking.');
no(fn()=>booking_change_request_decline_notice($db,$ref,str_repeat('e',32),true,$deps),'Unknown request cannot create a notice.');
ok(!str_contains(booking_lifecycle_html($db,booking_get($db,$ref),'csrf',false),'Send Saved Decline Notice'),'Customer cannot access staff decline-mail controls.');
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],true,$deps),'Declined request cannot be approved from stale form.');
// Lost calendar response records manager approval before the call and recovers by GET only.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'15:00',$deps);$mode='lost';$n=$writes;
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],true,$deps),'Lost approval response stops for recovery.');$mode='ok';
ok(booking_change_request_get($db,$ref,$r['request_id'])['state']==='applying'&&booking_lifecycle_pending($db,$ref),'Approval and uncertain operation remain durably linked.');
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],true,$deps),'Uncertain approval cannot repeat mutation.');
$mw=$mailWrites;booking_lifecycle_sync($db,$ref,$deps);
ok($writes===$n+1&&$mailWrites===$mw&&booking_change_request_get($db,$ref,$r['request_id'])['state']==='approved','GET recovery finishes approval without PATCH or mail replay.');
booking_change_request_approve($db,$ref,$r['request_id'],true,$deps);$mw=$mailWrites;
booking_change_request_approve($db,$ref,$r['request_id'],true,$deps);ok($writes===$n+1&&$mailWrites===$mw,'Recovered approval sends its saved RSVP at most once.');
// Ambiguous internal notification is visible and never retried as a new draft.
$fp=booking_lifecycle_fingerprint($db,$ref);$lostDraft=true;$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$lostDraft=false;
$key='change-request-'.$r['request_id'].':'.$ref;ok(booking_communication_get($db,$key)['submission_state']==='uncertain','Lost division mail response is retained as uncertain.');
$mw=$mailWrites;$n=$writes;booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);
ok($mailWrites===$mw&&$writes===$n,'Duplicate request cannot replay ambiguous division notification.');
booking_change_request_reject($db,$ref,$r['request_id'],true,$deps);
// A failure while recording a known refusal must not orphan an applying request.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$mode='precondition';
$db->exec("CREATE TRIGGER refuse_revision BEFORE UPDATE OF revision ON booking_lifecycle BEGIN SELECT RAISE(FAIL,'synthetic interruption'); END");
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],true,$deps),'Interruption within refusal bookkeeping is recoverable.');
ok(booking_change_request_get($db,$ref,$r['request_id'])['state']==='applying'&&booking_lifecycle_pending($db,$ref),'Refusal bookkeeping rolls back atomically; operation remains recoverable.');
$db->exec('DROP TRIGGER refuse_revision');$mode='ok';$n=$writes;
no(fn()=>booking_lifecycle_sync($db,$ref,$deps),'Unapplied provider result stays unresolved until explicit unchanged-version review.');booking_lifecycle_resolve_unchanged($db,$ref,array_replace($deps,['now'=>time()+180]));
ok(!booking_lifecycle_pending($db,$ref)&&!booking_change_request_pending($db,$ref)&&$writes===$n,'Unapplied approved operation resolves by reads without an orphaned request.');
$fp=booking_lifecycle_fingerprint($db,$ref);$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$mode='precondition';
no(fn()=>booking_change_request_approve($db,$ref,$r['request_id'],true,$deps),'Known provider refusal is recorded without calendar overwrite.');$mode='ok';
ok(!booking_lifecycle_pending($db,$ref)&&booking_change_request_get($db,$ref,$r['request_id'])['state']==='stale','Refusal completes journal/revision/request together.');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');no(fn()=>booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps),'TEST gate cannot be bypassed by request adapter.');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
$fp=booking_lifecycle_fingerprint($db,$ref);
// Decline remains durable even when the CRM prerequisite prevents the email send.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$id=$r['request_id'];$key='change-declined-'.$id.':'.$ref;
$mw=$mailWrites;$n=$writes;$before=booking_get($db,$ref);$beforeClaim=booking_confirmation_get($db,$ref);
no(fn()=>booking_change_request_reject($db,$ref,$id,false,$deps),'Unchecked decline cannot change state or queue mail.');
ok(booking_change_request_get($db,$ref,$id)['state']==='pending'&&!booking_communication_get($db,$key),'Fresh consent precedes both decline and outbox.');
$outage=array_replace($deps,['crm'=>static function(){throw new RuntimeException('Synthetic CRM outage');}]);
booking_change_request_reject($db,$ref,$id,true,$outage);
ok(booking_change_request_get($db,$ref,$id)['state']==='rejected'&&booking_communication_get($db,$key)['submission_state']==='prepared'&&$mailWrites===$mw&&$writes===$n,'Mail prerequisite outage preserves declined state and prepared notice without provider mutation.');
ok(booking_get($db,$ref)===$before&&booking_confirmation_get($db,$ref)===$beforeClaim,'Decline outage preserves all financial and confirmed-event fields.');
ok(str_contains(booking_lifecycle_html($db,booking_get($db,$ref),'csrf',true),'Send Saved Decline Notice'),'Prepared notice has explicit staff recovery control.');
booking_change_request_decline_notice($db,$ref,$id,false,$deps);
ok($mailWrites===$mw,'Read-only recovery never submits a prepared notice.');
booking_change_request_decline_notice($db,$ref,$id,true,$deps);
ok(booking_communication_get($db,$key)['submission_state']==='sent_observed'&&$mailWrites===$mw+2,'Explicit staff send submits the saved prepared notice once after prerequisites recover.');

// Lost draft response cannot create a second draft, even on an explicit send retry.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$id=$r['request_id'];$key='change-declined-'.$id.':'.$ref;$lostDraft=true;
booking_change_request_reject($db,$ref,$id,true,$deps);$lostDraft=false;$mw=$mailWrites;$n=$writes;
ok(booking_communication_get($db,$key)['submission_state']==='uncertain','Lost decline draft response is durably uncertain.');
booking_change_request_decline_notice($db,$ref,$id,true,$deps);
ok($mailWrites===$mw&&$writes===$n,'Uncertain draft is never recreated or sent blindly.');
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);
$older=booking_change_request_decline_notices($db,$ref);
ok(count(array_filter($older,static fn($v)=>$v['request']['request_id']===$id&&$v['needs_recovery']))===1,'Older unresolved decline remains discoverable after a newer customer request.');

// A draft blocked before sending may continue using only its verified original provider ID.
$id=$r['request_id'];$key='change-declined-'.$id.':'.$ref;$badDraft=true;
booking_change_request_reject($db,$ref,$id,true,$deps);$badDraft=false;$mail=booking_communication_get($db,$key);$mw=$mailWrites;
ok($mail['submission_state']==='draft_blocked'&&$mail['submission_attempted_at']===null,'Wrong draft recipient envelope stops before sending.');
$messages[$mail['provider_message_id']]['ccRecipients']=[];
booking_change_request_decline_notice($db,$ref,$id,true,$deps);
ok($mailWrites===$mw+1&&booking_communication_get($db,$key)['provider_message_id']===$mail['provider_message_id'],'Reviewed blocked-draft recovery sends the same verified message, no new draft.');

// A lost send acknowledgement recovers the observed sent copy, never retries send.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$id=$r['request_id'];$key='change-declined-'.$id.':'.$ref;$lostSend=true;
booking_change_request_reject($db,$ref,$id,true,$deps);$lostSend=false;$mw=$mailWrites;
ok(booking_communication_get($db,$key)['submission_state']==='uncertain','Lost send acknowledgement is preserved as uncertain.');
booking_change_request_decline_notice($db,$ref,$id,false,$deps);
ok(booking_communication_get($db,$key)['submission_state']==='sent_observed'&&$mailWrites===$mw,'GET recovery verifies the existing sent copy without another send.');

// Earlier deployed declines had no outbox: GET does not backfill; explicit staff action can.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$id=$r['request_id'];$key='change-declined-'.$id.':'.$ref;
$db->prepare("UPDATE booking_change_requests SET state='rejected',resolved_at=? WHERE request_id=?")->execute([gmdate('c'),$id]);$mw=$mailWrites;
booking_lifecycle_html($db,booking_get($db,$ref),'csrf',true);
ok(!booking_communication_get($db,$key)&&$mailWrites===$mw,'Rendering an earlier declined request cannot create or send its missing notice.');
no(fn()=>booking_change_request_decline_notice($db,$ref,$id,false,$deps),'Recovery cannot fabricate missing historical mail.');
no(fn()=>booking_change_request_decline_notice($db,$ref,$id,true,$deps),'Historical notice requires an explicit reviewed preparation flag.');
booking_change_request_decline_notice($db,$ref,$id,true,array_replace($deps,['prepare_missing'=>true]));
ok(booking_communication_get($db,$key)['submission_state']==='sent_observed'&&$mailWrites===$mw+2,'Explicit staff send prepares one missing historical notice for an unchanged latest decline.');

// State and queued notice commit together; an outbox failure must preserve pending review.
$r=booking_lifecycle_change($db,$ref,'reschedule',$fp,'customer',$day,'17:00',$deps);$id=$r['request_id'];$key='change-declined-'.$id.':'.$ref;
$db->exec("CREATE TRIGGER refuse_decline_mail BEFORE INSERT ON booking_communications WHEN NEW.kind LIKE 'change-declined-%' BEGIN SELECT RAISE(FAIL,'synthetic outbox interruption'); END");$mw=$mailWrites;
no(fn()=>booking_change_request_reject($db,$ref,$id,true,$deps),'Outbox failure rolls the decision back atomically.');
ok(booking_change_request_get($db,$ref,$id)['state']==='pending'&&!booking_communication_get($db,$key)&&$mailWrites===$mw,'No orphaned declined decision or provider call after transaction interruption.');
$db->exec('DROP TRIGGER refuse_decline_mail');
// The other already-approved TEST recipient receives its own plain notice, without inbox access.
$external='CCC0000001';$before=paid($external,'07:00','info@1789media.com');$beforeClaim=booking_confirmation_get($db,$external);
$db->prepare('INSERT INTO booking_contact_links VALUES(?,?,?,?,?)')->execute([$external,'100','400','info@1789media.com',gmdate('c')]);
$externalCrm=static function($method,$path,$body=null)use($crm){
    if($path==='/Contacts/400')return ['status'=>200,'body'=>['data'=>[['id'=>'400','Email'=>'info@1789media.com']]]];
    if($method==='POST')ok($body['Emails'][0]['to'][0]['email']==='info@1789media.com','CRM decline is associated only with the linked external TEST customer.');
    return $crm($method,$path,$body);
};
$externalDeps=array_replace($deps,['crm'=>$externalCrm]);$r=booking_lifecycle_change($db,$external,'reschedule',booking_lifecycle_fingerprint($db,$external),'customer',$day,'11:00',$externalDeps);
$id=$r['request_id'];$key='change-declined-'.$id.':'.$external;$n=$writes;$pathStart=count($graphPaths);
booking_change_request_reject($db,$external,$id,true,$externalDeps);$mail=booking_communication_get($db,$key);
ok($mail['recipient']==='info@1789media.com'&&$mail['submission_state']==='sent_observed'&&$mail['crm_state']==='associated','Customer decline uses the approved external recipient with saved sent-copy and CRM proof.');
ok($mail['delivery_state']==='unverified'&&!booking_communication_receipt_available($mail),'External inbox receipt is not invented.');
ok(count(array_filter(array_slice($graphPaths,$pathStart),static fn($p)=>str_contains($p[1],'/inbox/')))===0,'Decline sender never reads an external recipient mailbox.');
ok($writes===$n&&booking_get($db,$external)===$before&&booking_confirmation_get($db,$external)===$beforeClaim,'External decline preserves the entire booking and original appointment identity.');
$db->prepare("UPDATE booking_communications SET recipient='wrong@example.test' WHERE communication_key=?")->execute([$key]);$mw=$mailWrites;
no(fn()=>booking_change_request_decline_notice($db,$external,$id,true,$externalDeps),'Changed saved recipient cannot be submitted or recovered as this customer notice.');
ok($mailWrites===$mw,'Identity mismatch performs no provider write.');
// A later approved move cannot make recovery of an older saved decline look like current status.
$pending=booking_change_request_pending($db,$ref);$oldId=$pending['request_id'];$oldKey='change-declined-'.$oldId.':'.$ref;
booking_change_request_reject($db,$ref,$oldId,true,$outage);
$oldMessage=booking_communication_get($db,$oldKey)['message_json'];
$new=booking_lifecycle_change($db,$ref,'reschedule',booking_lifecycle_fingerprint($db,$ref),'customer',$day,'17:00',$deps);
booking_change_request_approve($db,$ref,$new['request_id'],true,$deps);$n=$writes;
booking_change_request_decline_notice($db,$ref,$oldId,true,$deps);
ok($writes===$n&&booking_communication_get($db,$oldKey)['message_json']===$oldMessage,'Delayed decline recovery preserves the saved historical message and later approved calendar.');
ok(str_contains($mimeCopies[$oldKey],'Confirmed window when this request was declined: '.$day.' 15:00–17:00')&&str_contains($mimeCopies[$oldKey],'Check your current appointment status'),'Delayed decline notice qualifies historical window and directs customer to current status.');
ok(!str_contains($mimeCopies[$oldKey],'Your confirmed appointment remains unchanged.'),'Delayed decline does not incorrectly claim its old window is still current.');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');
no(fn()=>booking_change_request_decline_notice($db,$ref,$id,true,$deps),'Decline notice cannot bypass TEST activation.');
$completed=true;echo "Manager-approved appointment requests: $checks checks passed\n";
