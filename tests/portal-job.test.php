<?php
declare(strict_types=1);
$completed=false;register_shutdown_function(static function()use(&$completed):void{if(!$completed){fwrite(STDERR,"Job assertions did not complete.\n");exit(1);}});
$dir=sys_get_temp_dir().'/sitesee-job-'.bin2hex(random_bytes(8));mkdir($dir,0700);$tmp=$dir;$private=$dir.'/private';mkdir($private,0700);mkdir($private.'/server',0700);
foreach(glob(__DIR__.'/../_private/server/*.php') as $file)copy($file,$private.'/server/'.basename($file));
foreach(glob(__DIR__.'/../_private/*.php') as $file)copy($file,$private.'/'.basename($file));
file_put_contents($private.'/booking-lifecycle.json',json_encode(['schema'=>1,'stage'=>'test','enabled'=>true,'recipient'=>'sales@re.sitesee.ai']));chmod($private.'/booking-lifecycle.json',0600);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$dir.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-service-test-secret-1234567890');putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_1234567890123456');putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET=whsec_1234567890123456');
require $private.'/server/portal-billing.php';require $private.'/server/portal-purchase.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,string $label):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Accepted: '.$label);}
$db=booking_db();portal_access_schema($db);portal_purchase_schema($db);portal_billing_schema($db);booking_communication_schema($db);$now=time();
$one=str_repeat('a',32);$two=str_repeat('b',32);foreach([[$one,'sales@re.sitesee.ai'],[$two,'two@example.com']] as [$id,$email])$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,time()]);
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

$deps['graph']=static function(){throw new RuntimeException('Isolated notice capture unavailable');};$deps['crm_config']=[];$deps['crm']=$deps['graph'];
booking_confirm_appointment($db,$ref,booking_scheduling_ms_config(),$api,null,$deps['lock_path']);$db->prepare("UPDATE booking_confirmations SET invitation_state='sent' WHERE reference=?")->execute([$ref]);
$db->prepare('UPDATE bookings SET consent_at=?,consent_version=? WHERE reference=?')->execute([gmdate('c'),'test-card-reuse-v1',$ref]); // Retain original cash-deposit consent compatibility.
$pristine=booking_get($db,$ref);$calendarBefore=$events;$calendarWrites=$writes;$deposit=(int)$pristine['deposit_cents'];
$mode='success';$intents=[];$createKeys=[];$confirmCount=0;$calls=0;$refund=0;$depositRefund=0;$disputed=false;$methodMissing=false;
$stripe=static function($method,$path,$body=[],$key='')use(&$mode,&$intents,&$createKeys,&$confirmCount,&$calls,&$refund,&$depositRefund,&$disputed,&$methodMissing,$ref,$deposit,$db):array{
    ++$calls;
    if($path==='/checkout/sessions/cs_test_deposit')return ['id'=>'cs_test_deposit','livemode'=>false,'mode'=>'payment','status'=>'complete','payment_status'=>'paid','currency'=>'usd','amount_total'=>$deposit,'client_reference_id'=>$ref,'metadata'=>['booking_reference'=>$ref],'customer'=>'cus_owned','payment_intent'=>'pi_deposit'];
    if($path==='/payment_intents/pi_deposit')return ['id'=>'pi_deposit','livemode'=>false,'customer'=>'cus_owned','status'=>'succeeded','currency'=>'usd','amount_received'=>$deposit,'latest_charge'=>'ch_deposit','setup_future_usage'=>'off_session','payment_method'=>'pm_saved'];
    if($path==='/payment_methods/pm_saved')return ['id'=>'pm_saved','livemode'=>false,'customer'=>$methodMissing?null:'cus_owned','type'=>'card'];
    if($path==='/charges/ch_deposit')return ['id'=>'ch_deposit','livemode'=>false,'customer'=>'cus_owned','payment_intent'=>'pi_deposit','paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>$deposit,'amount_captured'=>$deposit,'amount_refunded'=>$depositRefund,'disputed'=>false];
    if($method==='POST'&&$path==='/payment_intents'){
        check(!isset($body['confirm'])&&!isset($body['payment_method']),'Intent saved before any charge');
        if(!isset($createKeys[$key])){
            $id='pi_job_'.(count($createKeys)+1);$createKeys[$key]=$id;
            $intents[$id]=['id'=>$id,'livemode'=>false,'amount'=>(int)$body['amount'],'currency'=>'usd','customer'=>$body['customer'],'metadata'=>['booking_reference'=>$body['metadata[booking_reference]'],'portal_payment_kind'=>$body['metadata[portal_payment_kind]'],'job_scope'=>$body['metadata[job_scope]']],
                'status'=>'requires_payment_method','client_secret'=>'synthetic_job_secret','amount_received'=>0,'latest_charge'=>null];
        }
        if($mode==='lost_create')throw new RuntimeException('lost create response');return $intents[$createKeys[$key]];
    }
    if($method==='POST'&&str_ends_with($path,'/confirm')){
        $id=basename(dirname($path));check(booking_job_get($db,$ref)['payment_intent']===$id,'Intent durable before confirmation');
        check($body===['off_session'=>'true','payment_method'=>'pm_saved'],'Only saved card and fixed off-session confirmation');++$confirmCount;
        if($mode==='before_confirm')throw new RuntimeException('network interrupted before confirmation');
        $intents[$id]['status']=$mode==='decline'?'requires_payment_method':($mode==='processing'?'processing':'succeeded');
        if($intents[$id]['status']==='succeeded'){$intents[$id]['amount_received']=$intents[$id]['amount'];$intents[$id]['latest_charge']='ch_job';}
        if($mode==='lost_confirm')throw new RuntimeException('provider accepted before response lost');return $intents[$id];
    }
    if($path==='/payment_intents?customer=cus_owned&limit=100')return ['has_more'=>false,'data'=>array_values($intents)];
    if($method==='GET'&&str_starts_with($path,'/payment_intents/pi_job_'))return $intents[basename($path)];
    if($path==='/charges/ch_job'){$job=booking_job_get($db,$ref);return ['id'=>'ch_job','livemode'=>false,'customer'=>'cus_owned','payment_intent'=>$job['payment_intent'],'paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>(int)$job['amount'],'amount_captured'=>(int)$job['amount'],'amount_refunded'=>$refund,'disputed'=>$disputed,'receipt_url'=>'https://pay.stripe.com/receipts/synthetic'];}
    throw new RuntimeException('Unexpected provider operation: '.$method.' '.$path);
};
$reset=static function()use($db,&$intents,&$createKeys,&$confirmCount,&$mode,&$refund,&$disputed,&$methodMissing,$ref){$db->exec('DELETE FROM booking_jobs');$db->exec('DELETE FROM booking_job_extras');$intents=[];$createKeys=[];$confirmCount=0;$mode='success';$refund=0;$disputed=false;$methodMissing=false;};

// Extra scope requires current customer approval and cannot be silently edited afterward.
$draft=booking_job_extras($db,$ref);booking_job_save_extras($db,$ref,$draft['scope'],'add','Additional aerial photographs','84.00');$draft=booking_job_extras($db,$ref);
$before=$calls;rejects(fn()=>booking_job_approve_extras($db,$two,$ref,$draft['scope'],true),'Foreign extra approval');check($calls===$before,'Foreign approval makes no provider calls');
rejects(fn()=>booking_job_approve_extras($db,$one,$ref,$draft['scope'],false),'Extra consent required');
$bill=booking_job_bill($db,$ref);rejects(fn()=>booking_job_complete($db,$ref,$bill['scope'],true,$stripe),'Unapproved extra blocked');
booking_job_approve_extras($db,$one,$ref,$draft['scope'],true);
booking_job_save_extras($db,$ref,$draft['scope'],'add','Floor plan upgrade','50.00');$next=booking_job_extras($db,$ref);check(!$next['approved_at'],'Edit invalidates approval');
rejects(fn()=>booking_job_approve_extras($db,$one,$ref,$draft['scope'],true),'Stale customer scope');
booking_job_approve_extras($db,$one,$ref,$next['scope'],true);$bill=booking_job_bill($db,$ref);
check($bill['due_cents']===(int)$pristine['approved_cents']-$deposit+13400,'Final amount includes exactly approved extras');
rejects(fn()=>booking_job_complete($db,$ref,$bill['scope'],false,$stripe),'Photographer confirmation required');
$job=booking_job_complete($db,$ref,$bill['scope'],true,$stripe);check($job['payment_state']==='paid','Normal saved-card collection succeeds');check($confirmCount===1&&count($createKeys)===1,'One intent and one confirmation');
booking_job_complete($db,$ref,$bill['scope'],true,$stripe);booking_job_collect($db,$ref,$stripe);check($confirmCount===1&&count($createKeys)===1,'Repeated clicks and recovery never duplicate collection');
check(booking_get($db,$ref)===$pristine&&$events===$calendarBefore&&$writes===$calendarWrites,'Original booking and calendar preserved');
rejects(fn()=>portal_balance_scope($db,$one,$ref),'Legacy balance cannot charge after closeout');
rejects(fn()=>booking_job_save_extras($db,$ref,$next['scope'],'add','Late change','10'),'Final bill immutable');
rejects(fn()=>booking_lifecycle_change($db,$ref,'cancel',booking_lifecycle_fingerprint($db,$ref),'staff','','',$deps),'Completed job cannot cancel appointment');check($writes===$calendarWrites,'Closed appointment makes no calendar write');

// Draft delivery links are never visible; completion and verified payment are both required.
booking_job_production($db,$ref,0,['photos'=>'https://assets.example/photos'],false,false);
check(booking_job_deliverables($db,$one,$ref,$stripe)===[],'Draft links private');
rejects(fn()=>booking_job_production($db,$ref,0,['photos'=>'https://assets.example/stale'],false,false),'Concurrent production edits rejected');
rejects(fn()=>booking_job_production($db,$ref,1,['photos'=>'javascript:alert(1)'],true,true),'Unsafe scheme rejected');
rejects(fn()=>booking_job_production($db,$ref,1,['photos'=>'https://example.com@evil.example/file'],true,true),'Credential-bearing URL rejected');
rejects(fn()=>booking_job_production($db,$ref,1,[],true,true),'Missing photography rejected');
$links=['photos'=>'https://assets.example/photos','website'=>'https://property.example/','video'=>'https://assets.example/video','platform'=>'https://app.example/property','floor'=>'https://assets.example/floor','other_label_0'=>'Zillow 3D Home','other_url_0'=>'https://zillow.example/experience'];
booking_job_production($db,$ref,1,$links,true,true);check(count(booking_job_deliverables($db,$one,$ref,$stripe))===6,'Completed paid set visible');
$before=$calls;rejects(fn()=>booking_job_deliverables($db,$two,$ref,$stripe),'Foreign deliverables');check($calls===$before,'Foreign delivery request calls no provider');
$refund=100;check(booking_job_deliverables($db,$one,$ref,$stripe)===[],'Refund blocks delivery');$refund=0;$disputed=true;check(booking_job_deliverables($db,$one,$ref,$stripe)===[],'Dispute blocks delivery');$disputed=false;
$depositRefund=1;rejects(fn()=>booking_job_deliverables($db,$one,$ref,$stripe),'Deposit adjustment blocks delivery');$depositRefund=0;
$records=portal_billing_records($db,$one,$ref,$stripe);check(count($records)===2&&$records[1]['kind']==='job_closeout','Final receipt added to existing billing records');
$depositRefund=1;$records=portal_billing_records($db,$one,$ref,$stripe);check(count($records)===2&&$records[0]['refunded']===1,'Prior refund history remains visible during review');$depositRefund=0;
$published=booking_job_deliverables($db,$one,$ref,$stripe);$job=booking_job_get($db,$ref);
booking_job_production($db,$ref,(int)$job['production_revision'],['photos'=>'https://assets.example/unfinished'],false,false);
check(booking_job_deliverables($db,$one,$ref,$stripe)===$published,'Draft update preserves last published set');
$job=booking_job_get($db,$ref);check((int)$job['published_revision']<(int)$job['production_revision'],'Published and draft revisions are distinct');
$p=$intents[$job['payment_intent']];$event=['id'=>'evt_job_success','livemode'=>false,'type'=>'payment_intent.succeeded','data'=>['object'=>$p]];
check(booking_job_event($db,$event)&&booking_job_event($db,$event),'Repeated signed intent events are harmless');
$event['data']['object']['amount']++;rejects(fn()=>booking_job_event($db,$event),'Signed mismatched amount rejected');
check(booking_job_get($db,$ref)['amount']===$job['amount'],'Event cannot change the fixed bill');

foreach(['lost_create','lost_confirm','decline','before_confirm','processing'] as $failure){
    $reset();$mode=$failure;$bill=booking_job_bill($db,$ref);$job=booking_job_complete($db,$ref,$bill['scope'],true,$stripe);check((bool)$job['completed_at'],'Failure retains Production: '.$failure);
    if($failure==='lost_create'){check(!$job['payment_intent'],'Lost creation is recoverable');$mode='success';$job=booking_job_collect($db,$ref,$stripe);check($job['payment_state']==='paid'&&count($createKeys)===1,'Lost creation same-key recovery');}
    elseif($failure==='lost_confirm')check($job['payment_state']==='paid'&&$confirmCount===1,'Lost successful confirmation verified without second charge');
    else{
        check($job['payment_state']!== 'paid','No failed or processing payment is paid');booking_job_collect($db,$ref,$stripe);check($confirmCount===1,'Failure does not repeat off-session charge');
        if($failure!=='processing'){
            $db->prepare('UPDATE booking_jobs SET confirm_at=? WHERE reference=?')->execute([time()-31,$ref]);
            $before=$calls;rejects(fn()=>booking_job_customer_payment($db,$two,$ref,$bill['scope'],true,$stripe),'Foreign recovery');check($calls===$before,'Foreign payment recovery calls no provider');
            rejects(fn()=>booking_job_customer_payment($db,$one,$ref,$bill['scope'],false,$stripe),'Recovery consent');
            $result=booking_job_customer_payment($db,$one,$ref,$bill['scope'],true,$stripe);check($result['mode']==='payment'&&$result['clientSecret']==='synthetic_job_secret','Same intent customer recovery');
            booking_job_collect($db,$ref,$stripe);check($confirmCount===1,'No staff confirmation after customer recovery started');
        }
    }
}
$reset();$methodMissing=true;$bill=booking_job_bill($db,$ref);$job=booking_job_complete($db,$ref,$bill['scope'],true,$stripe);check($confirmCount===0&&$job['payment_intent']&&$job['payment_state']==='needs_action','Removed card creates recoverable uncharged intent');check(booking_job_customer_payment($db,$one,$ref,$bill['scope'],true,$stripe)['mode']==='payment','Customer can supply replacement card on same intent');
$reset();$mode='lost_create';$bill=booking_job_bill($db,$ref);booking_job_complete($db,$ref,$bill['scope'],true,$stripe);$db->prepare('UPDATE booking_jobs SET request_at=? WHERE reference=?')->execute([time()-25*3600,$ref]);$mode='success';$job=booking_job_collect($db,$ref,$stripe);check(count($createKeys)===1&&$job['payment_state']==='paid','Old ambiguous creation recovered by exact metadata, not recreated');
$reset();$mode='lost_create';$bill=booking_job_bill($db,$ref);booking_job_complete($db,$ref,$bill['scope'],true,$stripe);$db->prepare('UPDATE booking_jobs SET request_at=? WHERE reference=?')->execute([time()-25*3600,$ref]);$intents=[];$mode='success';$job=booking_job_collect($db,$ref,$stripe);check(count($createKeys)===1&&!$job['payment_intent'],'Unknown old creation never creates another intent');
$reset();$db->prepare("INSERT INTO portal_balance_attempts(reference,attempt,account_id,amount,scope,customer,state,created_at,request_json,consent_text,consent_at) VALUES (?,1,?,100,'test','cus_owned','creating',?,'{}','test',?)")->execute([$ref,$one,time(),time()]);$before=$calls;$bill=booking_job_bill($db,$ref);rejects(fn()=>booking_job_complete($db,$ref,$bill['scope'],true,$stripe),'Open legacy payment blocks closeout');check(!booking_job_get($db,$ref)&&$calls===$before,'Legacy race blocked before all provider calls');$db->exec('DELETE FROM portal_balance_attempts');
// Vendor-attested additions are repriced and saved atomically with the fixed bill.
$reset();$draft=booking_job_extras($db,$ref);
$onsite=['draft_scope'=>$draft['scope'],'items'=>[['service'=>'drone','inputs'=>[]],['service'=>'video','inputs'=>['videoMinutes'=>2]],['service'=>'platform','inputs'=>[]]]];
$preview=booking_job_onsite_preview($db,$ref,$onsite['items'],$draft['scope']);$scope=$preview['bill']['scope'];
check($preview['bill']['commission_cents']===2160,'Commission excludes package video and platform');
check(booking_job_extras($db,$ref)['revision']===0,'Preview makes no draft writes');
$before=$calls;rejects(fn()=>booking_job_complete($db,$ref,$scope,false,$stripe,$onsite),'Vendor attestation required');
$changed=$onsite;$changed['items'][1]['inputs']['videoMinutes']=3;
rejects(fn()=>booking_job_complete($db,$ref,$scope,true,$stripe,$changed),'Changed quantities invalidate displayed bill');
check($calls===$before&&!booking_job_get($db,$ref)&&booking_job_extras($db,$ref)['revision']===0,'Rejected closeout does not mutate draft or call providers');
$job=booking_job_complete($db,$ref,$scope,true,$stripe,$onsite);$fixed=json_decode($job['bill_json'],true,16,JSON_THROW_ON_ERROR);
check($job['payment_state']==='paid'&&$confirmCount===1,'Vendor flow collects without portal approval');
check($fixed['onsite_authorization']['method']==='staff_attested_verbal'&&$fixed['onsite_authorization']['photographer']===$pristine['photographer'],'Verbal attestation identifies assigned photographer');
check($fixed['extras_approved_by']===null&&booking_job_extras($db,$ref)['approved_by']===null,'Vendor attestation never forges customer portal approval');
check((int)$job['amount']===(int)$pristine['approved_cents']-$deposit+12000+28750,'Final charge contains service fees, not commission or monthly subscription');
booking_job_complete($db,$ref,$scope,true,$stripe,$onsite);check($confirmCount===1&&count($createKeys)===1,'Repeated vendor submission cannot charge again');
rejects(fn()=>booking_job_production($db,$ref,0,['photos'=>'https://assets.example/photos','website'=>'https://property.example','video'=>'https://assets.example/video','floor'=>'https://assets.example/floor'],true,true),'New onsite platform requires delivery URL');
check(booking_get($db,$ref)===$pristine,'Vendor closeout preserves all original booking fields');
$reset();$draft=booking_job_extras($db,$ref);$onsite=['draft_scope'=>$draft['scope'],'items'=>[['service'=>'drone','inputs'=>[]]]];
$preview=booking_job_onsite_preview($db,$ref,$onsite['items'],$draft['scope']);
booking_job_save_extras($db,$ref,$draft['scope'],'add','Concurrent custom addition','20');$before=$calls;
rejects(fn()=>booking_job_complete($db,$ref,$preview['bill']['scope'],true,$stripe,$onsite),'Concurrent draft edit preserved');
check(!$calls||$calls===$before,'Stale draft creates no charge');
$reset();$db->prepare('UPDATE bookings SET approved_cents=deposit_cents WHERE reference=?')->execute([$ref]);$bill=booking_job_bill($db,$ref);$job=booking_job_complete($db,$ref,$bill['scope'],true,$stripe);check((int)$job['amount']===0&&$job['payment_state']==='paid'&&!$createKeys,'Zero remaining creates no charge');
check($events===$calendarBefore&&$writes===$calendarWrites,'All closeout tests preserve calendar');
$completed=true;echo "portal-job: PASS (exact approved bill, immutable booking, ownership, consent, duplicate clicks, lost create/confirm, declines, detached cards, legacy races, payment recovery, refunds/disputes, private drafts, production release)\n";
