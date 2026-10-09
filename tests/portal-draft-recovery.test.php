<?php
declare(strict_types=1);
$testEmail=getenv('PORTAL_FIXTURE_AGENT_EMAIL')?:'sales@re.sitesee.ai';
if(!in_array($testEmail,['sales@re.sitesee.ai','info@1789media.com'],true))throw new RuntimeException('Isolated approved-recipient fixture required.');
$completed=false;register_shutdown_function(static function()use(&$completed):void{if(!$completed){fwrite(STDERR,"Service assertions did not complete.\n");exit(1);}});
$dir=sys_get_temp_dir().'/sitesee-service-'.bin2hex(random_bytes(8));mkdir($dir,0700);$tmp=$dir;$private=$dir.'/private';mkdir($private,0700);mkdir($private.'/server',0700);
foreach(glob(__DIR__.'/../_private/server/*.php') as $file)copy($file,$private.'/server/'.basename($file));
foreach(glob(__DIR__.'/../_private/*.php') as $file)copy($file,$private.'/'.basename($file));
file_put_contents($private.'/booking-lifecycle.json',json_encode(['schema'=>1,'stage'=>'test','enabled'=>true,'recipient'=>'sales@re.sitesee.ai']));chmod($private.'/booking-lifecycle.json',0600);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$dir.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-service-test-secret-1234567890');putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_1234567890123456');putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET=whsec_1234567890123456');
require $private.'/server/portal-billing.php';require $private.'/server/portal-purchase.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,string $label):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Accepted: '.$label);}
$db=booking_db();portal_access_schema($db);portal_purchase_schema($db);portal_billing_schema($db);booking_communication_schema($db);$now=time();
$one=str_repeat('a',32);$two=str_repeat('b',32);foreach([[$one,$testEmail],[$two,'two@example.com']] as [$id,$email])$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,time()]);
$payload=['market'=>'residential','details'=>['first'=>'Test','last'=>'Customer','company'=>'Synthetic','phone'=>'3125550100','street'=>'101 Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],'appointment'=>['date'=>(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]];
portal_profile_schema($db);foreach($db->query('SELECT id FROM portal_accounts')->fetchAll(PDO::FETCH_COLUMN) as $profileId)portal_save_profile($db,$profileId,['first_name'=>'Fixture','last_name'=>'Agent','company'=>'Synthetic','phone'=>'3125550100']);
$review=portal_purchase_review($db,$one,$payload);$ref=portal_purchase_submit($db,$one,$review['review']);
$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,stripe_session_id='cs_test_deposit',stripe_payment_intent_id='pi_deposit',stripe_customer_id='cus_owned' WHERE reference=?")->execute([gmdate('c'),$ref]);booking_review_paid($db,$ref,95,'David',true);
$events=[];$writes=0;$mode='ok';$reads=0;$otherCalendar=false;
$api=static function($method,$path,$body=null,$etag=null)use(&$events,&$writes,&$mode,&$reads,&$otherCalendar){
    if($mode==='outage')return ['status'=>503,'body'=>[]];
    if($method==='POST'){$id='event-'.(++$writes);$e=$body+['id'=>$id,'@odata.etag'=>'W/"v1"','organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'isCancelled'=>false,'type'=>'singleInstance'];$events[$id]=$e;return ['status'=>201,'body'=>$e];}
    if(in_array($method,['PATCH','DELETE'],true)){
        ++$writes;$id=rawurldecode(basename($path));check($etag===$events[$id]['@odata.etag'],'Fresh version submitted.');
        if($mode==='precondition')return ['status'=>412,'body'=>[]];
        if($method==='PATCH'){$events[$id]=array_replace($events[$id],$body);$events[$id]['@odata.etag']='W/"v'.$writes.'"';$r=['status'=>200,'body'=>$events[$id]];}
        else{unset($events[$id]);$r=['status'=>204,'body'=>[]];}
        if($mode==='lost')throw new RuntimeException('lost-response');return $r;
    }
    check($method==='GET','Recovery makes GET requests only.');++$reads;
    if(str_contains($path,'calendarView')){parse_str(parse_url($path,PHP_URL_QUERY),$q);$a=strtotime($q['startDateTime']);$b=strtotime($q['endDateTime']);
        return ['status'=>200,'body'=>['value'=>array_values(array_filter($events,static fn($e)=>booking_ms_timestamp($e['start'])<$b&&booking_ms_timestamp($e['end'])>$a))]];}
    if(str_contains($path,'/events/')){$id=rawurldecode(basename($path));if($otherCalendar&&str_contains($path,'/calendars/'))return ['status'=>404,'body'=>['error'=>['code'=>'ErrorItemNotFound']]];
        return isset($events[$id])?['status'=>200,'body'=>$events[$id]]:['status'=>404,'body'=>['error'=>['code'=>'ErrorItemNotFound']]];}
    return ['status'=>200,'body'=>['id'=>BOOKING_MS_CALENDAR,'canEdit'=>true,'isDefaultCalendar'=>true,'owner'=>['address'=>BOOKING_MS_MAILBOX]]];
};
$deps=['calendar'=>$api,'lock_path'=>$tmp.'/lock','now'=>$now];

// Full booking fixture uses only injected synthetic providers.
booking_confirm_appointment($db,$ref,booking_scheduling_ms_config(),$api,null,$deps['lock_path']);
$calendarWrites=$writes;
$crmConfig=['org_id'=>'123','user_id'=>'456','sync_mode'=>'api'];
$db->prepare('INSERT INTO booking_contact_links VALUES (?,?,?,?,?)')->execute([$ref,'123','789',$testEmail,gmdate('c')]);
$db->prepare('UPDATE bookings SET crm_contact_id=? WHERE reference=?')->execute(['789',$ref]);
$crmMismatch=false;
$crm=static function($method,$path,$body=null)use(&$crmMismatch,$testEmail):array {
    if($method!=='GET')return ['status'=>503,'body'=>[]];
    if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>'123']]]];
    if($path==='/users?type=CurrentUser')return ['status'=>200,'body'=>['users'=>[['id'=>'456']]]];
    if($path==='/Contacts/789')return ['status'=>200,'body'=>['data'=>[['id'=>'789','Email'=>$crmMismatch?'sales@re.sitesee.ai.invalid':$testEmail]]]];
    return ['status'=>503,'body'=>[]];
};
$draft=[];$mailCalls=[];$mailMode='normal';$createdCount=0;$sentCount=0;$patchCount=0;
$graph=static function($method,$path,$body=null,$etag=null)use(&$draft,&$mailCalls,&$mailMode,&$createdCount,&$sentCount,&$patchCount,$testEmail):array {
    check(str_starts_with($path,'/users/sales%40re.sitesee.ai/messages'),'All Graph operations remain in the sender mailbox');
    $mailCalls[]=$method;
    if($method==='POST'&&!str_ends_with($path,'/send')) {
        ++$createdCount;$mime=base64_decode($body,true);
        check(str_contains($mime,'To: '.$testEmail),'MIME has authorized recipient before provider import');
        preg_match('/^Subject: (.+)$/m',$mime,$subject);
        $draft=['id'=>'saved-draft','@odata.etag'=>'W/"draft-v1"','isDraft'=>true,'from'=>['emailAddress'=>['address'=>'sales@re.sitesee.ai']],
            'toRecipients'=>[],'ccRecipients'=>[],'bccRecipients'=>[],'replyTo'=>[],'subject'=>trim($subject[1]),'internetMessageId'=>'<draft@example.test>'];
        return ['status'=>201,'body'=>$draft];
    }
    check(str_contains($path,'/messages/saved-draft'),'Same immutable draft ID');
    if($method==='PATCH') {
        ++$patchCount;check($etag===$draft['@odata.etag'],'Fresh version for PATCH');
        if($mailMode==='patch-conflict')return ['status'=>412,'body'=>[]];
        if($mailMode!=='patch-ignored')$draft=array_replace($draft,json_decode($body,true));
        return ['status'=>200,'body'=>$draft];
    }
    if($method==='POST') {
        ++$sentCount;
        check($draft['toRecipients']===[['emailAddress'=>['address'=>$testEmail]]],'Exact recipient at send');
        check($draft['replyTo']===[['emailAddress'=>['address'=>'sales@re.sitesee.ai']]],'Exact reply-to at send');
        $draft['isDraft']=false;$draft['sentDateTime']=gmdate('c');
        if($mailMode==='lost-send')throw new RuntimeException('Synthetic lost send response');
        return ['status'=>202,'body'=>[],'request_id'=>'synthetic'];
    }
    if($mailMode==='read-fails')return ['status'=>503,'body'=>[]];
    return ['status'=>200,'body'=>$draft];
};
$mailDeps=['config'=>['test_recipient_email'=>'sales@re.sitesee.ai'],'crm_config'=>$crmConfig,'crm'=>$crm,'graph'=>$graph];
// Reproduce provider importing a correct MIME To into an empty draft, then refusing repair.
$mailMode='patch-conflict';
rejects(fn()=>booking_send_invitation_locked($db,$ref,booking_scheduling_ms_config(),null,$api,$mailDeps),'Blocked draft');
$key='invitation:'.$ref;$saved=booking_communication_get($db,$key);$savedJson=$saved['message_json'];
check($createdCount===1&&$sentCount===0&&$saved['submission_state']==='draft_blocked'&&!$saved['submission_attempted_at'],'No send after failed PATCH');
check(booking_invitation_draft_resumable($db,$ref),'Eligible original draft');
// A revoked link, changed appointment or unresolved lifecycle work cannot be resumed.
$life=booking_lifecycle_state($db,$ref);
foreach ([['token_hash'=>hash('sha256','rotated')],['token_expires'=>time()-1],['state'=>'cancelled'],['revision'=>1]] as $change) {
    booking_lifecycle_set($db,$ref,$change);check(!booking_invitation_draft_resumable($db,$ref),'Ineligible lifecycle/link');
    foreach($change as $field=>$value)booking_lifecycle_set($db,$ref,[$field=>$life[$field]]);
}
$db->prepare("INSERT INTO booking_lifecycle_operations VALUES (?,?,1,'reschedule','staff','uncertain','{}',?,NULL)")->execute(['synthetic-operation',$ref,gmdate('c')]);
check(!booking_invitation_draft_resumable($db,$ref),'Pending lifecycle blocks repair');
$db->prepare('DELETE FROM booking_lifecycle_operations WHERE operation_id=?')->execute(['synthetic-operation']);
$bad=json_decode($savedJson,true);$bad['plain'].='Stale content';
$db->prepare('UPDATE booking_communications SET message_json=? WHERE communication_key=?')->execute([json_encode($bad),$key]);
check(!booking_invitation_draft_resumable($db,$ref),'Stale saved content blocks repair');
$db->prepare('UPDATE booking_communications SET message_json=? WHERE communication_key=?')->execute([$savedJson,$key]);
$claim=booking_confirmation_get($db,$ref);
booking_communication_update($db,$key,['sent_at'=>gmdate('c')]);check(!booking_invitation_draft_resumable($db,$ref),'Sent evidence blocks repair');booking_communication_update($db,$key,['sent_at'=>null]);
$db->prepare('UPDATE booking_communications SET event_uid=? WHERE communication_key=?')->execute(['other-event',$key]);check(!booking_invitation_draft_resumable($db,$ref),'Other event blocks repair');
$db->prepare('UPDATE booking_communications SET event_uid=? WHERE communication_key=?')->execute([$claim['event_uid'],$key]);
check(booking_invitation_draft_resumable($db,$ref),'Restored eligible fixture');
// Resume revalidates CRM and calendar before touching the existing message.
$crmMismatch=true;$calls=count($mailCalls);
rejects(fn()=>booking_send_invitation_locked($db,$ref,booking_scheduling_ms_config(),null,$api,$mailDeps,true),'Changed CRM');
check(count($mailCalls)===$calls,'CRM rejection precedes draft mutation');$crmMismatch=false;
$mode='outage';rejects(fn()=>booking_send_invitation_locked($db,$ref,booking_scheduling_ms_config(),null,$api,$mailDeps,true),'Unverified calendar');
check(count($mailCalls)===$calls,'Calendar rejection precedes draft mutation');$mode='ok';
$mailMode='normal';
booking_send_invitation_locked($db,$ref,booking_scheduling_ms_config(),null,$api,$mailDeps,true);
check($createdCount===1&&$sentCount===1&&$writes===$calendarWrites,'Resume sends same draft once and does not create event');
check(booking_communication_get($db,$key)['message_json']===$savedJson,'Existing content and management link retained');
check(booking_confirmation_get($db,$ref)['invitation_state']==='sent','Confirmation reflects submission');
check(!booking_invitation_draft_resumable($db,$ref),'No resume after send');
booking_send_invitation_locked($db,$ref,booking_scheduling_ms_config(),null,$api,$mailDeps,true);
check($sentCount===1,'Double click cannot send twice');
// The actual saved invitation keeps the fixed organizer and the selected agent attendee.
$message=json_decode($savedJson,true);
check($message['from']==='sales@re.sitesee.ai'&&$message['to']===$testEmail,'Saved fixed sender and exact recipient');
$ical=str_replace("\r\n ",'',$message['ical']);
check(str_contains($ical,'ORGANIZER;CN=SiteSee:mailto:sales@re.sitesee.ai')&&str_contains($ical,'RSVP=TRUE:mailto:'.$testEmail),'Calendar identities are separate');
if($testEmail==='info@1789media.com'){
    booking_communication_update($db,$key,['crm_state'=>'associated']);$calls=count($mailCalls);$sent=$sentCount;
    $report=booking_workflow_recover($db,$ref,['calendar_config'=>booking_scheduling_ms_config(),'calendar'=>$api,'mail_config'=>[],'graph'=>$graph,'crm_config'=>$crmConfig,'crm'=>$crm]);
    check(str_contains($report['items']['Recipient mailbox'],'Unverified')&&str_contains($report['items']['Recipient mailbox'],$testEmail),'External receipt requires manual confirmation');
    check(booking_communication_get($db,$key)['delivery_state']==='unverified'&&$sentCount===$sent,'Recovery never invents external evidence or resends');
    check(array_slice($mailCalls,$calls)===['GET'],'Recovery reads only the saved sender message');
    $calls=count($mailCalls);rejects(fn()=>booking_communication_delivery($db,$key,$graph),'External mailbox is not accessible');check(count($mailCalls)===$calls,'No external Graph lookup');
}
// New messages, including lifecycle notices, use the same repaired envelope path.
$baseMessage=['from'=>'sales@re.sitesee.ai','to'=>$testEmail,'subject'=>'Synthetic notice','headers'=>[],'body'=>'test'];
$fresh=static function()use($db,$baseMessage,$graph):string {
    $r=strtoupper(bin2hex(random_bytes(8)));$k=booking_communication_enqueue($db,$r,'probe',$baseMessage);
    booking_communication_update($db,$k,['submission_state'=>'draft_blocked','provider_message_id'=>'saved-draft']);return $k;
};
$clean=['id'=>'saved-draft','@odata.etag'=>'W/"draft-v1"','isDraft'=>true,'from'=>['emailAddress'=>['address'=>'sales@re.sitesee.ai']],
    'toRecipients'=>[],'ccRecipients'=>[],'bccRecipients'=>[],'replyTo'=>[],'subject'=>'Synthetic notice','internetMessageId'=>'<draft@example.test>'];
foreach(['other-approved-to','wrong-to','wrong-from','wrong-reply','wrong-subject','cc','bcc','not-draft','missing-id','no-etag','missing-envelope','read-fails','patch-conflict','patch-ignored'] as $case) {
    $k=$fresh();$draft=$clean;$mailMode=$case;$n=$sentCount;$p=$patchCount;
    if($case==='other-approved-to')$draft['toRecipients']=[['emailAddress'=>['address'=>$testEmail==='sales@re.sitesee.ai'?'info@1789media.com':'sales@re.sitesee.ai']]];
    if($case==='wrong-to')$draft['toRecipients']=[['emailAddress'=>['address'=>'wrong@example.test']]];
    if($case==='wrong-from')$draft['from']['emailAddress']['address']='wrong@example.test';
    if($case==='wrong-reply')$draft['replyTo']=[['emailAddress'=>['address'=>'wrong@example.test']]];
    if($case==='wrong-subject')$draft['subject']='Unrelated draft';
    if($case==='cc'||$case==='bcc')$draft[$case.'Recipients']=[['emailAddress'=>['address'=>'wrong@example.test']]];
    if($case==='not-draft')$draft['isDraft']=false;
    if($case==='missing-id')unset($draft['id']);
    if($case==='no-etag')unset($draft['@odata.etag']);
    if($case==='missing-envelope')unset($draft['bccRecipients']);
    rejects(fn()=>booking_communication_send_saved_draft($db,$k,$graph),$case);
    check($sentCount===$n,'No send: '.$case);
    if(!in_array($case,['patch-conflict','patch-ignored'],true))check($patchCount===$p,'No mutation of wrong identity: '.$case);
}
$k=$fresh();$draft=$clean;$mailMode='lost-send';$n=$sentCount;
rejects(fn()=>booking_communication_send_saved_draft($db,$k,$graph),'Lost send');
check(booking_communication_get($db,$k)['submission_state']==='uncertain','Unknown send outcome retained');
rejects(fn()=>booking_communication_send_saved_draft($db,$k,$graph),'No lost-send retry');check($sentCount===$n+1,'One send with lost response');
foreach(['submission_attempted_at','provider_accepted_at','sent_observed_at','sent_at'] as $field){
    $k=$fresh();booking_communication_update($db,$k,[$field=>gmdate('c')]);$calls=count($mailCalls);
    rejects(fn()=>booking_communication_send_saved_draft($db,$k,$graph),'Saved evidence '.$field);check(count($mailCalls)===$calls,'No Graph call after saved evidence');
}
$k=booking_communication_enqueue($db,strtoupper(bin2hex(random_bytes(8))),'lifecycle-1',$baseMessage);$mailMode='normal';$n=$sentCount;
booking_communication_submit($db,$k,$graph);check($sentCount===$n+1,'Lifecycle draft repaired and sent once');
$k=$fresh();$draft=$clean;$draft['toRecipients']=[['emailAddress'=>['address'=>$testEmail]]];$draft['replyTo']=[['emailAddress'=>['address'=>'sales@re.sitesee.ai']]];$p=$patchCount;
booking_communication_send_saved_draft($db,$k,$graph);check($patchCount===$p,'Correct existing envelope is not rewritten');
foreach([
 ['/users/cro%40sitesee.ai/messages/id',json_encode(['toRecipients'=>[['emailAddress'=>['address'=>'sales@re.sitesee.ai']]]]),'W/"v1"'],
 ['/users/sales%40re.sitesee.ai/messages/id/send','{}','W/"v1"'],
 ['/users/sales%40re.sitesee.ai/messages/id',json_encode(['subject'=>'changed']),'W/"v1"'],
 ['/users/sales%40re.sitesee.ai/messages/id',json_encode(['toRecipients'=>[['emailAddress'=>['address'=>'wrong@example.test']]]]),'W/"v1"'],
] as $args) rejects(fn()=>booking_graph_draft_patch_guard(...$args),'Restricted PATCH');
rejects(fn()=>booking_workflow_reader($graph)('PATCH','/messages/id'),'Recovery remains read-only');
$completed=true;echo "PASS saved draft repair, same-message resume, current calendar/CRM, identity guards, and no duplicate sends\n";
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($dir);
