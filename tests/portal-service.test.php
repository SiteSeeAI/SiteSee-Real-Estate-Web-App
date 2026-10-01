<?php
declare(strict_types=1);
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
$one=str_repeat('a',32);$two=str_repeat('b',32);foreach([[$one,'sales@re.sitesee.ai'],[$two,'two@example.com']] as [$id,$email])$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,time()]);
$payload=['market'=>'residential','details'=>['first'=>'Test','last'=>'Customer','company'=>'Synthetic','phone'=>'3125550100','street'=>'101 Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],'appointment'=>['date'=>(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]];
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
$n=$writes;rejects(fn()=>portal_appointment_windows($db,$two,$ref,$payload['appointment']['date'],$deps),'Foreign appointment');check($writes===$n,'Foreign appointment no calls');
$windows=portal_appointment_windows($db,$one,$ref,$payload['appointment']['date'],$deps);check(count($windows)>0&&array_keys($windows[0])===['date','time','end_time'],'Public window projection');$w=array_values(array_filter($windows,static fn($w)=>$w['time']==='13:00'))[0];$fingerprint=booking_lifecycle_fingerprint($db,$ref);
rejects(fn()=>portal_appointment_change($db,$one,$ref,'adopt',$fingerprint,true,'','',$deps),'No staff action');rejects(fn()=>portal_appointment_change($db,$one,$ref,'reschedule',$fingerprint,false,$w['date'],$w['time'],$deps),'Fresh consent');
portal_appointment_change($db,$one,$ref,'reschedule',$fingerprint,true,$w['date'],$w['time'],$deps);check($writes===$n+1,'One owned appointment patch');rejects(fn()=>portal_appointment_change($db,$one,$ref,'reschedule',$fingerprint,true,$w['date'],$w['time'],$deps),'Repeat patch');
booking_communication_update($db,'lifecycle-1:'.$ref,['submission_state'=>'sent_observed']);
// Independent synthetic Stripe API. Every response derives from the exact trusted fixture ledger.
$deposit=(int)booking_get($db,$ref)['deposit_cents'];$sessions=[];$keys=[];$refund=0;$disputed=false;$foreign=false;$unknown=false;$invoice=false;$badInvoice=false;$stripeMode='ok';$configuration=['id'=>'bpc_isolated','livemode'=>false,'active'=>true,'login_page'=>['enabled'=>false],'features'=>['payment_method_update'=>['enabled'=>true],'customer_update'=>['enabled'=>true,'allowed_updates'=>['address','name','phone']],'invoice_history'=>['enabled'=>false],'subscription_update'=>['enabled'=>false],'subscription_cancel'=>['enabled'=>false]]];
$base=['livemode'=>false,'mode'=>'payment','client_reference_id'=>$ref,'metadata'=>['booking_reference'=>$ref],'currency'=>'usd','amount_total'=>$deposit,'customer'=>'cus_owned','payment_intent'=>'pi_deposit','status'=>'complete','payment_status'=>'paid','id'=>'cs_test_deposit','invoice'=>null];
$stripe=static function($method,$path,$body=[],$key='')use(&$sessions,&$keys,&$refund,&$disputed,&$foreign,&$unknown,&$invoice,&$badInvoice,&$stripeMode,&$configuration,$base,$deposit,$ref,$db):array{
    if($path==='/checkout/sessions/cs_test_deposit')return $foreign?array_replace($base,['customer'=>'cus_other']):($invoice?array_replace($base,['invoice'=>'in_owned']):$base);
    if(str_starts_with($path,'/payment_intents/')){$id=basename($path);$amount=$id==='pi_deposit'?$deposit:(int)portal_billing_latest($db,$ref)['amount'];return ['id'=>$id,'livemode'=>false,'customer'=>'cus_owned','status'=>'succeeded','currency'=>'usd','amount_received'=>$amount,'latest_charge'=>$id==='pi_deposit'?'ch_deposit':'ch_balance'];}
    if($path==='/invoices/in_owned')return ['id'=>'in_owned','livemode'=>false,'customer'=>$badInvoice?'cus_other':'cus_owned','currency'=>'usd','status'=>'paid','amount_paid'=>$deposit,'hosted_invoice_url'=>'https://invoice.stripe.com/i/synthetic','invoice_pdf'=>'https://pay.stripe.com/invoice/synthetic/pdf'];
    if(str_starts_with($path,'/charges/')){$id=basename($path);$amount=$id==='ch_deposit'?$deposit:(int)portal_billing_latest($db,$ref)['amount'];return ['id'=>$id,'livemode'=>false,'customer'=>'cus_owned','payment_intent'=>$id==='ch_deposit'?'pi_deposit':'pi_balance','paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>$amount,'amount_captured'=>$amount,'amount_refunded'=>$refund,'disputed'=>$disputed,'receipt_url'=>'https://pay.stripe.com/receipts/payment/synthetic'];}
    if($method==='POST'&&$path==='/checkout/sessions'){
        $keys[]=$key;check(!isset($body['subscription_data'])&&!isset($body['payment_intent_data[off_session]'])&&$body['mode']==='payment','Explicit one-time payment');
        $id='cs_test_balance_'.$body['metadata[portal_attempt]'];$s=['id'=>$id,'livemode'=>false,'mode'=>'payment','ui_mode'=>$body['ui_mode']??'hosted_page','client_secret'=>'synthetic-secret','url'=>'https://checkout.stripe.com/c/pay/synthetic','client_reference_id'=>$ref,'metadata'=>['booking_reference'=>$ref,'portal_payment_kind'=>'balance','portal_attempt'=>$body['metadata[portal_attempt]']],'currency'=>'usd','amount_total'=>(int)$body['line_items[0][price_data][unit_amount]'],'customer'=>$body['customer'],'status'=>'open','payment_status'=>'unpaid'];$sessions[$id]=$s;
        if($stripeMode==='lost')throw new RuntimeException('lost creation response after provider acceptance');
        if($stripeMode==='early_webhook'){$event=['id'=>'evt_early','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>array_replace($s,['status'=>'complete','payment_status'=>'paid','payment_intent'=>'pi_balance'])]];portal_balance_event($db,$event);}
        return $s;
    }
    if(str_starts_with($path,'/checkout/sessions?'))return ['has_more'=>false,'data'=>$unknown?[$base,array_replace($base,['id'=>'cs_test_foreign'])]:array_merge([$base],array_values($sessions))];
    if(str_starts_with($path,'/checkout/sessions/'))return $sessions[basename($path)];
    if(str_starts_with($path,'/payment_intents?'))return ['has_more'=>false,'data'=>[['id'=>'pi_deposit','customer'=>'cus_owned','livemode'=>false]]];
    if(str_starts_with($path,'/invoices?')||str_starts_with($path,'/subscriptions?'))return ['has_more'=>false,'data'=>[]];
    if(str_starts_with($path,'/billing_portal/configurations'))return $configuration;
    if($path==='/billing_portal/sessions')return ['livemode'=>false,'customer'=>$body['customer'],'configuration'=>$body['configuration'],'url'=>'https://billing.stripe.com/p/session/test'];
    throw new RuntimeException('Unexpected mock API path '.$path);
};
$beforeKeys=count($keys);rejects(fn()=>portal_billing_records($db,$two,$ref,$stripe),'Foreign receipt');rejects(fn()=>portal_billing_manage($db,$two,$ref,$stripe),'Foreign methods');$scope=portal_balance_scope($db,$one,$ref);rejects(fn()=>portal_balance_checkout($db,$two,$ref,$scope['scope'],true,$stripe,['enabled'=>false]),'Foreign balance');rejects(fn()=>portal_balance_checkout($db,$one,$ref,$scope['scope'],false,$stripe,['enabled'=>false]),'Balance consent');rejects(fn()=>portal_balance_checkout($db,$one,$ref,'stale',true,$stripe,['enabled'=>false]),'Amount changed');check(count($keys)===$beforeKeys,'Rejected requests never create payment');
$foreign=true;rejects(fn()=>portal_billing_records($db,$one,$ref,$stripe),'Provider customer mismatch');$foreign=false;$refund=1;rejects(fn()=>portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false]),'Refund blocks payment');$refund=0;$disputed=true;rejects(fn()=>portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false]),'Dispute blocks payment');$disputed=false;
$records=portal_billing_records($db,$one,$ref,$stripe);check(count($records)===1&&$records[0]['amount']===$deposit,'Verified deposit receipt');
$invoice=true;check(portal_billing_records($db,$one,$ref,$stripe)[0]['invoice']==='https://invoice.stripe.com/i/synthetic','Owned invoice');$badInvoice=true;rejects(fn()=>portal_billing_records($db,$one,$ref,$stripe),'Foreign invoice customer');$badInvoice=false;$invoice=false;
check(str_starts_with(portal_billing_manage($db,$one,$ref,$stripe),'https://billing.stripe.com/'),'Restricted owned payment methods');$unknown=true;rejects(fn()=>portal_billing_manage($db,$one,$ref,$stripe),'Unknown provider session');$unknown=false;$configuration['features']['invoice_history']['enabled']=true;rejects(fn()=>portal_billing_manage($db,$one,$ref,$stripe),'Configuration drift');$configuration['features']['invoice_history']['enabled']=false;
$stripeMode='lost';rejects(fn()=>portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false]),'Ambiguous balance create');$db->prepare('UPDATE portal_balance_attempts SET created_at=? WHERE reference=?')->execute([time()-24*3600,$ref]);rejects(fn()=>portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false]),'Old ambiguous payment cannot create again');$db->prepare('UPDATE portal_balance_attempts SET created_at=? WHERE reference=?')->execute([time(),$ref]);$stripeMode='ok';$result=portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false]);check($result['mode']==='hosted'&&$keys[0]===$keys[1],'Immutable same-key retry');$keyCount=count($keys);check(portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false])===$result&&count($keys)===$keyCount,'Open session reused');check(portal_balance_paid($db,$ref)===0,'Checkout and return are not payment');
$sessions['cs_test_balance_1']['status']='expired';check(portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false])['mode']==='expired','Verify expiry before new attempt');
$stripeMode='early_webhook';check(portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>true])['mode']==='paid','Webhook before create response');check($keys[2]!==$keys[1],'Only verified expiry advances key');
$balance=portal_billing_latest($db,$ref);check(portal_balance_paid($db,$ref)===$scope['amount'],'Signed payment ledger amount');
$sessions['cs_test_balance_2']=array_replace($sessions['cs_test_balance_2'],['status'=>'complete','payment_status'=>'paid','payment_intent'=>'pi_balance']);$event=['id'=>'evt_verified','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>$sessions['cs_test_balance_2']]];$raw=json_encode($event);$stamp=time();$signature='t='.$stamp.',v1='.hash_hmac('sha256',$stamp.'.'.$raw,'whsec_1234567890123456');rejects(fn()=>booking_verify_stripe_event($raw,'t='.$stamp.',v1='.str_repeat('0',64)),'Unsigned balance');$verified=booking_verify_stripe_event($raw,$signature);check(portal_balance_event($db,$verified)&&portal_balance_event($db,$verified),'Verified webhook replay');check(portal_balance_checkout($db,$one,$ref,$scope['scope'],true,$stripe,['enabled'=>false])['mode']==='paid'&&count($keys)===3,'Paid no second checkout');
$bad=$verified;$bad['id']='evt_wrong_amount';$bad['data']['object']['amount_total']++;rejects(fn()=>portal_balance_event($db,$bad),'Wrong webhook amount');check(!portal_balance_event($db,['data'=>['object'=>['metadata'=>['booking_reference'=>$ref]]]]),'Deposit webhook stays canonical');
check(count(portal_billing_records($db,$one,$ref,$stripe))===2,'Owned balance receipt');
// Interruption recovery through the new adapter must not repeat the calendar write.
$fingerprint=booking_lifecycle_fingerprint($db,$ref);$mode='lost';rejects(fn()=>portal_appointment_change($db,$one,$ref,'cancel',$fingerprint,true,'','',$deps),'Lost calendar response');$n=$writes;$mode='ok';portal_appointment_guard($db,$one,$ref);booking_lifecycle_sync($db,$ref,$deps);check($writes===$n&&booking_lifecycle_state($db,$ref)['state']==='cancelled','Read-only recovered cancellation');portal_appointment_change($db,$one,$ref,'cancel',$fingerprint,true,'','',$deps);check($writes===$n,'Repeated cancellation no write');rejects(fn()=>portal_balance_scope($db,$one,$ref),'Cancelled balance');
// The RE business address is the only active workflow test recipient.
$db->prepare('UPDATE bookings SET email=? WHERE reference=?')->execute(['cro@sitesee.ai',$ref]);
rejects(fn()=>booking_workflow_row($db,$ref),'Old personal recipient cannot use RE workflow');
$db->prepare('UPDATE bookings SET email=? WHERE reference=?')->execute(['sales@re.sitesee.ai',$ref]);
check(booking_workflow_row($db,$ref)['email']==='sales@re.sitesee.ai','RE business recipient is eligible');
check(booking_scheduling_ms_config()['test_recipient_email']==='sales@re.sitesee.ai','Scheduling recipient');
$mailConfig=['sender'=>'sales@re.sitesee.ai','stage'=>'test','enabled'=>true,'graph_credentials'=>'/home/sitesee/.sitesee-graph-mail.json','test_recipient_email'=>'cro@sitesee.ai'];
file_put_contents($private.'/booking-mail.json',json_encode($mailConfig));chmod($private.'/booking-mail.json',0600);
rejects(fn()=>booking_mail_config(true),'Old mail recipient blocked');
$mailConfig['test_recipient_email']='sales@re.sitesee.ai';file_put_contents($private.'/booking-mail.json',json_encode($mailConfig));
check(booking_mail_config(true)['test_recipient_email']==='sales@re.sitesee.ai','RE mail configuration accepted');
$message=['from'=>'sales@re.sitesee.ai','to'=>'sales@re.sitesee.ai','subject'=>'Synthetic RE receipt','headers'=>[],'body'=>'Synthetic'];
$key=booking_communication_enqueue($db,$ref,'probe',$message);
booking_communication_update($db,$key,['internet_message_id'=>'<re-receipt@example.test>','submission_state'=>'sent_observed']);
$copy=['id'=>'inbox-copy','internetMessageId'=>'<re-receipt@example.test>','isDraft'=>false,'receivedDateTime'=>gmdate('c'),'subject'=>'Synthetic RE receipt','from'=>['emailAddress'=>['address'=>'sales@re.sitesee.ai']],'parentFolderId'=>'inbox'];
$onlySent=static function($method,$path)use($copy){check($method==='GET','Delivery check is read only');return ['status'=>200,'body'=>['value'=>str_contains($path,'/mailFolders/inbox/messages?')?[]:[$copy]]];};
rejects(fn()=>booking_communication_delivery($db,$key,$onlySent),'Sent Items alone is not receipt');
$received=static function($method,$path)use($copy){check($method==='GET'&&str_contains($path,'/users/sales%40re.sitesee.ai/mailFolders/inbox/messages?'),'Exact RE inbox required');return ['status'=>200,'body'=>['value'=>[$copy]]];};
booking_communication_delivery($db,$key,$received);check(booking_communication_get($db,$key)['delivery_state']==='recipient_copy_observed','Distinct inbox receipt verified');
$oldMessage=$message;$oldMessage['to']='cro@sitesee.ai';$oldKey=booking_communication_enqueue($db,'EEEE000001','probe',$oldMessage);$graphCalls=0;
rejects(fn()=>booking_communication_submit($db,$oldKey,static function()use(&$graphCalls){++$graphCalls;return [];}),'Old recipient cannot send');check($graphCalls===0,'Rejected recipient causes no Graph calls');
$db->prepare('UPDATE portal_accounts SET disabled=1 WHERE id=?')->execute([$one]);rejects(fn()=>portal_billing_records($db,$one,$ref,$stripe),'Disabled billing owner');rejects(fn()=>portal_appointment_guard($db,$one,$ref),'Disabled appointment owner');
$completed=true;echo "portal-service: PASS (owned lifecycle; no staff controls; recovery; payment evidence; consent; approved scope; refunds/disputes; same-key retry; expiry; webhook race/replay; restricted methods; disabled ownership)\n";
