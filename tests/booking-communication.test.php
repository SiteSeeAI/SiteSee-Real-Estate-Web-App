<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/booking-comms-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('x',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__.'/../_private/server/booking-invitation.php';
$checks=0;
function ok(bool $v,string $why):void {global $checks;++$checks;if(!$v)throw new RuntimeException($why);}
function fails(callable $f,string $why):void {try{$f();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$why);return;}throw new RuntimeException($why);}
$db=booking_db();booking_communication_schema($db);
$day=(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');
$details=['first'=>'David','last'=>'Cro','company'=>'SiteSee','email'=>'cro@sitesee.ai','phone'=>'5555550100','street'=>'123 Main St','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'];
function fixture(string $ref):array {
    global $db,$day,$details;
    $submission=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential','details'=>$details,
        'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
        'appointment'=>['date'=>$day,'time'=>'07:00','windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'0123456789','cancellationAccepted'=>true]]);
    booking_capture($db,$submission,$ref,true);
    $db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,approved_at=?,photographer='David',duration_minutes=120 WHERE reference=?")->execute([gmdate('c'),gmdate('c'),$ref]);
    return booking_get($db,$ref);
}
$config=['org_id'=>'100','user_id'=>'200','sync_mode'=>'api','original_sync_mode'=>'api'];
$native=array_replace($config,['sync_mode'=>'native']);
$history=[];$historyReads=0;$crmWrites=0;$crmFail=false;$crmCommitThenTimeout=false;$wrongOrg=false;$wrongEmail=false;$malformedHistory=false;$duplicate=false;$providerError=null;
$crm=static function($method,$path,$body=null)use(&$history,&$historyReads,&$crmWrites,&$crmFail,&$crmCommitThenTimeout,&$wrongOrg,&$wrongEmail,&$malformedHistory,&$duplicate,&$providerError):array {
    if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>$wrongOrg?'999':'100']]]];
    if(str_starts_with($path,'/users'))return ['status'=>200,'body'=>['users'=>[['id'=>'200']]]];
    if(str_starts_with($path,'/Contacts/search'))return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>'cro@sitesee.ai']], 'info'=>['more_records'=>false]]];
    if($path==='/Contacts/300')return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>$wrongEmail?'wrong@example.com':'cro@sitesee.ai']]]];
    if(str_contains($path,'/Emails')) {++$historyReads;return ['status'=>200,'body'=>$malformedHistory?['Emails'=>[]]:['Emails'=>$history,'info'=>['more_records'=>false]]];}
    if($method==='POST') {
        ++$crmWrites;ok(str_ends_with($path,'/actions/associate_email'),'Only CRM association endpoint, never send.');
        $e=$body['Emails'][0];ok($e['original_message_id']==='<sent-id@example.com>','Use provider original Message-ID.');
        ok(!str_contains($e['content'],'0123456789'),'Access codes absent from CRM content.');
        if($crmFail)throw new RuntimeException('secret provider failure');
        if($providerError!==null)return ['status'=>403,'body'=>['code'=>$providerError,'message'=>'secret provider detail']];
        if($duplicate)return ['status'=>400,'body'=>['Emails'=>[['code'=>'DUPLICATE_DATA']]]];
        $history=[array_merge($e,['message_id'=>'crm-1','time'=>$e['date_time']])];
        if($crmCommitThenTimeout)throw new RuntimeException('lost CRM acknowledgment');
        return ['status'=>200,'body'=>['Emails'=>[['code'=>'SUCCESS','details'=>['message_id'=>'crm-1']]]]];
    }
    throw new RuntimeException('Unexpected CRM route '.$path);
};
$graphCalls=[];$graphDrafts=0;$graphSends=0;$lostCreate=false;$lostSend=false;$wrongFrom=false;$pendingSent=false;$sent=[];$lastMessage=null;
$graph=static function($method,$path,$body=null)use(&$graphCalls,&$graphDrafts,&$graphSends,&$lostCreate,&$lostSend,&$wrongFrom,&$pendingSent,&$sent,&$lastMessage):array {
    $graphCalls[]=[$method,$path];
    if($method==='POST' && str_ends_with($path,'/messages')) {
        ++$graphDrafts;$mime=base64_decode($body,true);ok(str_contains($mime,'From: SiteSee Real Estate <sales@re.sitesee.ai>'),'Graph MIME sender dedicated.');
        ok(str_contains($mime,'Reply-To: sales@re.sitesee.ai'),'Graph Reply-To dedicated.');
        preg_match('/^Subject: (.*)$/m',$mime,$m);$subject=trim($m[1]);
        $lastMessage=['id'=>'immutable-'.$graphDrafts,'isDraft'=>true,'internetMessageId'=>'<draft-id@example.com>',
            'from'=>['emailAddress'=>['address'=>'sales@re.sitesee.ai']],'toRecipients'=>[['emailAddress'=>['address'=>'cro@sitesee.ai']]],
            'replyTo'=>[['emailAddress'=>['address'=>'sales@re.sitesee.ai']]],'subject'=>$subject];
        if($lostCreate)throw new RuntimeException('lost draft create reply');
        return ['status'=>201,'body'=>$lastMessage];
    }
    if($method==='POST' && str_ends_with($path,'/send')) {
        ++$graphSends;$lastMessage['isDraft']=false;$lastMessage['internetMessageId']='<sent-id@example.com>';$lastMessage['sentDateTime']=gmdate('c');
        if($lostSend)throw new RuntimeException('lost send acknowledgment');
        return ['status'=>202,'body'=>[],'request_id'=>'request-1'];
    }
    if(str_contains($path,'?%24filter='))return ['status'=>200,'body'=>['value'=>[array_merge($lastMessage,['receivedDateTime'=>gmdate('c'),'parentFolderId'=>'recipient-folder'])]]];
    $m=$lastMessage;if($wrongFrom)$m['from']['emailAddress']['address']='cro@sitesee.ai';
    if($pendingSent && !$m['isDraft'])return ['status'=>404,'body'=>[]];
    return ['status'=>200,'body'=>$m];
};
function make(string $ref):string {
    global $db;
    $r=fixture($ref);$m=booking_invitation_message($r,['confirmed_at'=>gmdate('c')]);
    ok(str_contains($m['ical'],'ORGANIZER;CN=SiteSee:mailto:sales@re.sitesee.ai'),'ICS organizer matches sender.');
    ok(!str_contains($m['plain'],'0123456789'),'Private access omitted.');
    return booking_communication_enqueue($db,$ref,'invitation',$m,['calendar_uid'=>'calendar','event_uid'=>'event@zoho.com']);
}
$key=make('ABB0000001');booking_crm_link($db,'ABB0000001','300',$config,$crm);
$bookingBefore=booking_get($db,'ABB0000001');
booking_communication_submit($db,$key,$graph);
$r=booking_communication_get($db,$key);ok($r['submission_state']==='accepted' && $r['provider_message_id']==='immutable-1','Draft ID saved before accepted submission.');
ok($r['delivery_state']==='unverified' && $r['crm_state']==='pending','202 proves neither delivery nor CRM.');
fails(fn()=>booking_communication_submit($db,$key,$graph),'Duplicate attempt blocked.');ok($graphSends===1,'One Graph send only.');
$pendingSent=true;fails(fn()=>booking_communication_reconcile($db,$key,$graph),'Sent-copy lag retained.');$pendingSent=false;
booking_communication_reconcile($db,$key,$graph);ok(booking_communication_get($db,$key)['internet_message_id']==='<sent-id@example.com>','Actual sent ID replaces draft ID.');
$crmFail=true;fails(fn()=>booking_communication_crm($db,$key,$config,$crm),'CRM failure isolated.');$crmFail=false;
ok(booking_communication_get($db,$key)['submission_state']==='sent_observed' && $graphSends===1,'CRM error never alters mail or resends.');
booking_communication_crm($db,$key,$config,$crm);$writes=$crmWrites;booking_communication_crm($db,$key,$config,$crm);
ok($crmWrites===$writes && booking_communication_get($db,$key)['crm_state']==='associated','CRM repeat idempotent.');
ok($historyReads===0,'Verified API sender associates exact Message-ID without scanning unrelated mailbox history.');
booking_communication_delivery($db,$key,$graph);ok(booking_communication_get($db,$key)['delivery_state']==='recipient_copy_observed','Recipient evidence separately recorded.');
ok(booking_get($db,'ABB0000001')===$bookingBefore,'Transport leaves booking, payment, review and schedule unchanged.');
ok(booking_communication_get($db,$key)['event_uid']==='event@zoho.com','Calendar linkage retained.');
$key2=make('ABB0000002');$lostSend=true;fails(fn()=>booking_communication_submit($db,$key2,$graph),'Lost send response uncertain.');$lostSend=false;
$sendCount=$graphSends;fails(fn()=>booking_communication_submit($db,$key2,$graph),'No resend after timeout.');booking_communication_reconcile($db,$key2,$graph);
ok($graphSends===$sendCount && booking_communication_get($db,$key2)['submission_state']==='sent_observed','GET-only recovery after uncertain send.');
$key3=make('ABB0000003');$lostCreate=true;fails(fn()=>booking_communication_submit($db,$key3,$graph),'Lost create reply tracked.');$lostCreate=false;
$draftCount=$graphDrafts;fails(fn()=>booking_communication_submit($db,$key3,$graph),'No duplicate draft creation.');ok($graphDrafts===$draftCount,'Uncertain creation remains blocked.');
$key4=make('ABB0000004');$wrongFrom=true;fails(fn()=>booking_communication_submit($db,$key4,$graph),'Sender rewrite blocks sending.');$wrongFrom=false;ok($graphSends===$sendCount,'Wrong sender never submitted.');
$history=[];booking_crm_link($db,'ABB0000002','300',$config,$crm);
$wrongOrg=true;fails(fn()=>booking_communication_crm($db,$key2,$config,$crm),'Wrong CRM organization blocked.');$wrongOrg=false;
$wrongEmail=true;fails(fn()=>booking_communication_crm($db,$key2,$config,$crm),'Changed CRM email blocked.');$wrongEmail=false;
$malformedHistory=true;fails(fn()=>booking_communication_crm($db,$key2,$native,$crm),'Incomplete native history cannot cause insertion.');$malformedHistory=false;
$crmCommitThenTimeout=true;fails(fn()=>booking_communication_crm($db,$key2,$config,$crm),'CRM accepted but acknowledgment lost.');$crmCommitThenTimeout=false;
$writes=$crmWrites;$duplicate=true;booking_communication_crm($db,$key2,$config,$crm);$duplicate=false;
ok($crmWrites===$writes+1 && booking_communication_get($db,$key2)['crm_state']==='provider_duplicate','Retry reuses the exact Message-ID; provider duplicate remains reviewable, never invented success.');
$writes=$crmWrites;booking_communication_crm($db,$key2,$config,$crm);ok($crmWrites===$writes,'Unresolved provider duplicate is not repeatedly inserted.');
// Native synchronization candidate without an exposed original ID is never guessed or duplicated.
booking_communication_update($db,$key2,['crm_state'=>'pending','crm_message_id'=>null]);unset($history[0]['original_message_id']);
booking_communication_crm($db,$key2,$native,$crm);ok(booking_communication_get($db,$key2)['crm_state']==='existing_candidate_review' && $crmWrites===$writes,'Matching native history blocks insertion pending review.');
booking_communication_crm($db,$key2,$config,$crm);ok($crmWrites===$writes,'An existing review requirement is retained even if the supplied mode changes.');
booking_communication_crm($db,$key2,$config,$crm,'crm-1');ok(booking_communication_get($db,$key2)['crm_state']==='associated','Explicit existing-history review links it without insertion.');
booking_communication_update($db,$key2,['crm_state'=>'pending']);$history=[];
booking_communication_crm($db,$key2,array_replace($config,['sync_mode'=>'native']),$crm);ok(booking_communication_get($db,$key2)['crm_state']==='awaiting_native_sync' && $crmWrites===$writes,'Native sync lag never creates competing history.');
$duplicate=true;booking_communication_crm($db,$key2,$config,$crm);ok(booking_communication_get($db,$key2)['crm_state']==='provider_duplicate','Provider duplicate preserved without fake CRM ID.');
$duplicate=false;booking_communication_update($db,$key2,['crm_state'=>'pending']);$providerError='NO_PERMISSION';
fails(fn()=>booking_communication_crm($db,$key2,$config,$crm),'Provider permission failure is retained for CRM-only recovery.');
$error=booking_communication_get($db,$key2)['crm_error'];ok(str_contains($error,'HTTP 403; code NO_PERMISSION') && !str_contains($error,'secret'),'Diagnostics expose only HTTP status and a validated provider code.');
$providerError=null;
fails(fn()=>booking_crm_link($db,'ABB0000002','999',$config,$crm),'Unverified contact ID cannot link.');
fails(fn()=>booking_communication_enqueue($db,'ABB0000001','invitation',booking_invitation_message($bookingBefore,['confirmed_at'=>gmdate('c')])),'Unique booking and kind blocks concurrent enqueue.');
// Enumeration must inspect more than first page and must compare exact email.
$pages=0;$search=static function($method,$path)use(&$pages){++$pages;return ['status'=>200,'body'=>['data'=>[['id'=>$pages===1?'301':'302','Email'=>$pages===1?'cro+alias@sitesee.ai':'cro@sitesee.ai']],'info'=>['more_records'=>$pages===1]]];};
$candidates=booking_crm_candidates('cro@sitesee.ai',$search);ok($pages===2 && count($candidates)===1 && $candidates[0]['id']==='302','Complete paginated exact contact matching.');
// The original delivered invitation is never a valid submission target.
$m=booking_invitation_message($bookingBefore,['confirmed_at'=>gmdate('c')]);$m['from']='cro@sitesee.ai';$orig=booking_communication_enqueue($db,'ABB0000001','original',$m);
fails(fn()=>booking_communication_submit($db,$orig,$graph),'Original message cannot enter send path.');
booking_communication_update($db,$orig,['submission_state'=>'sent_observed','internet_message_id'=>'<sent-id@example.com>','sent_at'=>gmdate('c')]);
$unreviewed=$config;unset($unreviewed['original_sync_mode']);$reads=$historyReads;$writes=$crmWrites;
booking_communication_crm($db,$orig,$unreviewed,$crm);
ok($historyReads===$reads+1 && $crmWrites===$writes,'Unreviewed original sender defaults to native inspection without inserting.');
$reads=$historyReads;booking_communication_crm($db,$orig,$config,$crm);
ok($historyReads===$reads && booking_communication_get($db,$orig)['crm_state']==='associated','Explicit original API mode records its actual sender without scanning old mailbox history.');
$beforeCalls=count($graphCalls);fails(fn()=>booking_communication_crm($db,$key3,$config,$crm),'Unverified send cannot create CRM sent history.');ok(count($graphCalls)===$beforeCalls,'CRM recovery cannot call Graph.');
// Exercise the real invitation orchestration with provider clients injected, no legacy mail callback.
$full=fixture('ABB0000010');$cc=['confirmation_stage'=>'test','confirmation_enabled'=>true,'invitations_enabled'=>true,'enabled'=>true,
    'test_recipient_email'=>'cro@sitesee.ai','client_id'=>'fixture','client_secret'=>'fixture','refresh_token'=>'fixture','calendar_uid'=>'calendar'];
$events=[];$eventWrites=0;
$calendar=static function($method,$url,$form,$token)use(&$events,&$eventWrites,$cc){
    if(str_contains($url,'/oauth/v2/token'))return ['status'=>200,'body'=>['access_token'=>'fixture']];
    if($method==='POST'){++$eventWrites;$e=json_decode($form['eventdata'],true);$e['uid']='full@zoho.com';$e['caluid']=$cc['calendar_uid'];$events=[$e];return ['status'=>200,'body'=>['events'=>$events]];}
    return ['status'=>200,'body'=>['events'=>$events]];
};
booking_confirm_appointment($db,'ABB0000010',$cc,$calendar,null,$tmp.'/calendar.lock');
booking_crm_link($db,'ABB0000010','300',$config,$crm);
$history=[];$duplicate=false;$crmFail=true;$beforeSends=$graphSends;
$deps=['config'=>['test_recipient_email'=>'cro@sitesee.ai'],'crm_config'=>$config,'crm'=>$crm,'graph'=>$graph];
booking_send_invitation($db,'ABB0000010',$cc,null,$calendar,$deps);
ok(booking_confirmation_get($db,'ABB0000010')['invitation_state']==='sent','Full orchestration keeps accepted invitation sent despite CRM failure.');
ok(booking_communication_get($db,'invitation:ABB0000010')['crm_state']==='retry_pending','Full orchestration persists CRM recovery state.');
booking_send_invitation($db,'ABB0000010',$cc,null,$calendar,$deps);
ok($graphSends===$beforeSends+1 && $eventWrites===1,'Full repeated staff action never duplicates email or calendar.');
$crmFail=false;booking_communication_crm($db,'invitation:ABB0000010',$config,$crm);
ok(booking_communication_get($db,'invitation:ABB0000010')['crm_state']==='associated' && $graphSends===$beforeSends+1,'Full CRM-only recovery reaches completion without send.');
$db=null;foreach(glob($tmp.'/*') as $p)unlink($p);rmdir($tmp);
echo "Booking communication safety: $checks checks passed\n";
