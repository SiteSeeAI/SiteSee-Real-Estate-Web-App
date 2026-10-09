<?php
declare(strict_types=1);
$finished=false;register_shutdown_function(static function()use(&$finished){if(!$finished){fwrite(STDERR,"Financial assertions did not complete.\n");exit(1);}});
$tmp=sys_get_temp_dir().'/sitesee-finance-'.bin2hex(random_bytes(8));mkdir($tmp,0700);mkdir($tmp.'/private',0700);mkdir($tmp.'/private/server',0700);
foreach(glob(__DIR__.'/../_private/server/*.php') as $file)copy($file,$tmp.'/private/server/'.basename($file));
foreach(glob(__DIR__.'/../_private/*.php') as $file)copy($file,$tmp.'/private/'.basename($file));
file_put_contents($tmp.'/private/booking-lifecycle.json',json_encode(['schema'=>1,'stage'=>'test','enabled'=>true,'recipient'=>'sales@re.sitesee.ai']));chmod($tmp.'/private/booking-lifecycle.json',0600);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-finance-secret-1234567890');putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_'.str_repeat('0',16));
require $tmp.'/private/server/portal-purchase.php';require $tmp.'/private/server/portal-billing.php';
$checks=0;function check(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function rejects(callable $f,string $label):void{try{$f();}catch(Throwable){check(true,$label);return;}check(false,$label);}
$db=booking_db();portal_access_schema($db);portal_profile_schema($db);portal_purchase_schema($db);portal_billing_schema($db);booking_communication_schema($db);booking_job_schema($db);
$account=str_repeat('a',32);$other=str_repeat('b',32);foreach([[$account,'sales@re.sitesee.ai'],[$other,'other@example.test']] as [$id,$email]){$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,time()]);portal_save_profile($db,$id,['first_name'=>'Synthetic','last_name'=>'Agent','company'=>'Fixture','phone'=>'3125550100']);}
$payload=['market'=>'residential','details'=>['first'=>'Synthetic','last'=>'Agent','company'=>'Fixture','phone'=>'3125550100','street'=>'101 Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],'appointment'=>['date'=>(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]];
$sessions=[];$intents=[];$charges=[];$refunds=[];$writes=0;$keys=[];$lost=false;$refundStatus='succeeded';$counter=0;
$api=static function(string $method,string $path,array $body=[],string $key='')use(&$sessions,&$intents,&$charges,&$refunds,&$writes,&$keys,&$lost,&$refundStatus):array{
    if($method==='GET'){
        if(preg_match('~^/checkout/sessions/(cs_test_\w+)$~',$path,$m))return $sessions[$m[1]];
        if(preg_match('~^/payment_intents/(pi_\w+)$~',$path,$m))return $intents[$m[1]];
        if(preg_match('~^/charges/(ch_\w+)$~',$path,$m))return $charges[$m[1]];
        if(preg_match('~^/refunds/(re_\w+)$~',$path,$m))return $refunds[$m[1]];
        if(preg_match('~^/refunds\?charge=(ch_\w+)&limit=100$~',$path,$m))return ['has_more'=>false,'data'=>array_values(array_filter($refunds,static fn($r)=>$r['charge']===$m[1]))];
        if(preg_match('~^/(checkout/sessions|payment_intents|invoices|subscriptions)\?customer=(cus_\w+)&(?:status=all&)?limit=100$~',$path,$m)){
            $items=match($m[1]){'checkout/sessions'=>$sessions,'payment_intents'=>$intents,default=>[]};return ['has_more'=>false,'data'=>array_values(array_filter($items,static fn($v)=>$v['customer']===$m[2]))];
        }
        if(preg_match('~^/payment_methods/(pm_\w+)$~',$path,$m))return ['id'=>$m[1],'livemode'=>false,'customer'=>'cus_'.substr($m[1],3),'type'=>'card'];
    }
    if($method==='POST'&&$path==='/payment_intents'){
        $id='pi_job_'.$body['metadata[booking_reference]'];
        return $intents[$id]??= ['id'=>$id,'livemode'=>false,'customer'=>$body['customer'],'status'=>'requires_confirmation','currency'=>'usd','amount'=>(int)$body['amount'],'metadata'=>['booking_reference'=>$body['metadata[booking_reference]'],'portal_payment_kind'=>'job_closeout','job_scope'=>$body['metadata[job_scope]']]];
    }
    if($method==='POST'&&preg_match('~^/payment_intents/(pi_\w+)/confirm$~',$path,$m)){
        $p=$intents[$m[1]];check($body['payment_method']==='pm_'.substr($p['customer'],4)&&$body['off_session']==='true','Full-credit collection uses the verified source card.');
        $ch='ch_'.$p['id'];$p['status']='succeeded';$p['amount_received']=$p['amount'];$p['latest_charge']=$ch;$intents[$p['id']]=$p;
        $charges[$ch]=['id'=>$ch,'livemode'=>false,'customer'=>$p['customer'],'payment_intent'=>$p['id'],'paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>$p['amount'],'amount_captured'=>$p['amount'],'amount_refunded'=>0,'disputed'=>false];return $p;
    }
    if($method==='POST'&&$path==='/refunds'){
        check((bool)preg_match('/^sitesee-refund-test-[a-f0-9]{64}$/D',$key),'Stable refund key.');
        if(isset($keys[$key]))return $refunds[$keys[$key]];
        ++$writes;$id='re_refund'.$writes;$charge=$charges[$body['charge']];$r=['id'=>$id,'charge'=>$charge['id'],'payment_intent'=>$charge['payment_intent'],'amount'=>(int)$body['amount'],'currency'=>'usd','status'=>$refundStatus,'metadata'=>['sitesee_operation'=>$body['metadata[sitesee_operation]'],'booking_reference'=>$body['metadata[booking_reference]']]];$refunds[$id]=$r;$keys[$key]=$id;
        if($refundStatus==='succeeded')$charges[$charge['id']]['amount_refunded']+=(int)$body['amount'];
        if($lost){$lost=false;throw new RuntimeException('Lost provider response');}return $r;
    }
    throw new RuntimeException('Unexpected synthetic request '.$method.' '.$path);
};
function order(string $owner,?int $gross=null,bool $paid=true,string $customer=''):string{
    global $db,$payload,$counter;$review=portal_purchase_review($db,$owner,$payload);$ref=portal_purchase_submit($db,$owner,$review['review']);++$counter;
    if($gross!==null)$db->prepare('UPDATE bookings SET deposit_cents=?,quote_cents=?,approved_cents=? WHERE reference=?')->execute([$gross,$gross*2,$gross*2,$ref]);
    if($paid){payment($ref,booking_finance_cash(booking_get($db,$ref)),'deposit',1,$customer);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,checkout_state='paid',consent_at=?,consent_version=? WHERE reference=?")->execute([gmdate('c'),gmdate('c'),BOOKING_CONSENT_VERSION,$ref]);}
    return $ref;
}
function payment(string $ref,int $amount,string $kind='deposit',int $attempt=1,string $customer=''):array{
    global $db,$sessions,$intents,$charges;$suffix=strtolower($ref).'_'.$kind.$attempt;$sid='cs_test_'.$suffix;$pi='pi_'.$suffix;$ch='ch_'.$suffix;$customer=$customer?:'cus_'.$suffix;
    $meta=['booking_reference'=>$ref];if($kind==='balance')$meta+=['portal_payment_kind'=>'balance','portal_attempt'=>(string)$attempt];
    $s=['id'=>$sid,'livemode'=>false,'mode'=>'payment','status'=>'complete','payment_status'=>'paid','currency'=>'usd','amount_total'=>$amount,'client_reference_id'=>$ref,'metadata'=>$meta,'customer'=>$customer,'payment_intent'=>$pi];$sessions[$sid]=$s;
    $intents[$pi]=['id'=>$pi,'livemode'=>false,'customer'=>$customer,'status'=>'succeeded','currency'=>'usd','amount_received'=>$amount,'latest_charge'=>$ch,'payment_method'=>'pm_'.substr($customer,4),'setup_future_usage'=>'off_session'];
    $charges[$ch]=['id'=>$ch,'livemode'=>false,'customer'=>$customer,'payment_intent'=>$pi,'paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>$amount,'amount_captured'=>$amount,'amount_refunded'=>0,'disputed'=>false];
    if($kind==='deposit')$db->prepare('UPDATE bookings SET stripe_session_id=?,stripe_payment_intent_id=?,stripe_customer_id=? WHERE reference=?')->execute([$sid,$pi,$customer,$ref]);
    return $s;
}
function cancel_plan(string $ref,string $actor,int $notice):void{
    global $db;$op=['operation_id'=>bin2hex(random_bytes(16)),'reference'=>$ref,'revision'=>1,'actor'=>$actor,'action'=>'cancel','created_at'=>gmdate('c',time()-10)];$payload=['row'=>booking_get($db,$ref),'claim'=>['planned_start'=>strtotime($op['created_at'])+$notice]];
    $db->exec('BEGIN IMMEDIATE');try{$db->prepare("INSERT INTO booking_lifecycle_operations(operation_id,reference,revision,actor,action,state,payload_json,created_at) VALUES (?,?,?,?,?,'applied',?,?)")->execute([$op['operation_id'],$ref,1,$actor,'cancel',json_encode($payload),$op['created_at']]);booking_finance_queue($db,$op,$payload);$db->exec('COMMIT');}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function balance(string $ref,int $amount,bool $paid=true):array{
    global $db,$account;$s=payment($ref,$amount,'balance');$row=booking_get($db,$ref);
    $db->prepare('INSERT INTO portal_balance_attempts VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$ref,1,$account,$amount,'scope',$s['customer'],$paid?'paid':'open',time(),'{}',$s['id'],$paid?$s['payment_intent']:null,$paid?gmdate('c'):null,'synthetic consent',time()]);return $s;
}
// Management refunds all collected cash, regardless of notice; repeats are readback only.
$ref=order($account,20000);balance($ref,17000);cancel_plan($ref,'staff',-5);booking_finance_process($db,$ref,$api);$s=booking_finance_status($db,$ref);check($s['refunded']===37000&&$s['state']==='completed','Full manager refund.');$first=$writes;booking_finance_process($db,$ref,$api);check($writes===$first,'No duplicate refund.');
// Exact 24-hour boundary uses saved original time, not recovery time.
$ref=order($account,15000);cancel_plan($ref,'customer',86400);booking_finance_process($db,$ref,$api,time()+10*86400);check(booking_finance_status($db,$ref)['refunded']===15000,'Exactly24 hours refund despite delayed worker.');
$ref=order($account,20000,true,'cus_source');$source=$ref;cancel_plan($ref,'customer',86399);booking_finance_process($db,$ref,$api);check(booking_finance_available($db,$account)===20000,'Late cancellation credit.');$first=$writes;booking_finance_process($db,$ref,$api);check(booking_finance_available($db,$account)===20000&&$writes===$first,'Credit granted once.');
// Wrong account cannot allocate or learn provider evidence.
$target=order($account,10000,false);rejects(fn()=>booking_finance_reserve($db,$other,$target,$api),'Foreign account cannot reserve.');
booking_finance_reserve($db,$account,$target,$api);$row=booking_get($db,$target);check(booking_finance_cash($row)===0&&$row['deposit_cents']===10000,'Gross deposit intact; separate zero cash.');check(booking_finance_available($db,$account)===0,'Deposit and next balance reserved.');
check(booking_finance_credit_checkout($db,$account,$target,'127.0.0.1',$api),'Fully credited deposit settles locally.');$row=booking_get($db,$target);check($row['stripe_session_id']===null&&$row['stripe_payment_intent_id']===null&&$row['stripe_customer_id']==='cus_source','No fictitious Stripe IDs; verified lineage.');check(booking_finance_deposit_evidence($db,$row,$api)['credit']===10000,'Verified full-credit evidence.');
// Cash-plus-credit balance and final job cannot charge the credit again.
$db->prepare('UPDATE bookings SET approved_at=?,approved_cents=?,duration_minutes=90 WHERE reference=?')->execute([gmdate('c'),20000,$target]);
$db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at) VALUES (?,'confirmed','microsoft:test','event-test',?,?,'{}',?)")->execute([$target,time()+86400,time()+90000,gmdate('c')]);
booking_lifecycle_set($db,$target,['state'=>'active']);$scope=portal_balance_scope($db,$account,$target);check($scope['amount']===0&&$scope['credit']===10000,'Credit covers remaining balance.');check(portal_balance_checkout($db,$account,$target,$scope['scope'],true,$api,['enabled'=>false])['mode']==='paid','No Stripe checkout for zero balance.');check(portal_balance_paid($db,$target)===10000,'Balance credit counted once.');
$bill=booking_job_bill($db,$target);check($bill['due_cents']===0&&$bill['total_cents']===20000,'Final gross bill keeps credits.');$job=booking_job_complete($db,$target,$bill['scope'],true,$api);check($job['payment_state']==='paid','Credit-funded job verifies.');
// Manager cancellation of another replacement restores credit, never refunds its source again.
$creditRef=order($account,15000);cancel_plan($creditRef,'customer',1);booking_finance_process($db,$creditRef,$api);$replacement=order($account,5000,false);booking_finance_reserve($db,$account,$replacement,$api);booking_finance_credit_checkout($db,$account,$replacement,'127.0.0.1',$api);$before=$writes;cancel_plan($replacement,'staff',1);booking_finance_process($db,$replacement,$api);check($writes===$before&&booking_finance_available($db,$account)===15000,'Replacement credit restored without cash refund.');check(booking_finance_deposit_evidence($db,booking_get($db,$replacement),$api)['credit_restored']===5000,'Canceled credited Billing remains accessible.');
// Customer prepaid balances require review; deposit refunds independently.
$ref=order($other,5000);balance($ref,7000);cancel_plan($ref,'customer',86400);booking_finance_process($db,$ref,$api);$s=booking_finance_status($db,$ref);check($s['refunded']===5000&&$s['state']==='review','Extra prepayment retained for staff review.');
// Lost refund reply survives request age and recovers the exact provider operation.
$ref=order($other,5000);cancel_plan($ref,'staff',1);$lost=true;booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['state']==='pending','Lost response remains pending.');$before=$writes;booking_finance_process($db,$ref,$api,time()+25*3600);check($writes===$before&&booking_finance_status($db,$ref)['refunded']===5000,'Aged refund found by exact metadata without POST.');
// Unknown aged request with no provider evidence is never re-created.
$ref=order($other,5000);cancel_plan($ref,'staff',1);$offline=static function($m,$p,$b=[],$k='')use($api){if($m==='POST')throw new RuntimeException('Disconnected');return $api($m,$p,$b,$k);};booking_finance_process($db,$ref,$offline);$before=$writes;booking_finance_process($db,$ref,$api,time()+25*3600);check($writes===$before&&booking_finance_status($db,$ref)['refunded']===0,'Aged unknown refund held for review.');
// Pending and failed provider refunds cannot become available credit or confirmed refunds.
foreach(['pending','failed'] as $status){$ref=order($other,5000);cancel_plan($ref,'staff',1);$refundStatus=$status;booking_finance_process($db,$ref,$api);$s=booking_finance_status($db,$ref);check($s['refunded']===0&&$s['refund_pending']===5000,'Non-final refund not completed: '.$status);}$refundStatus='succeeded';
// Already adjusted deposit is preserved with no refund or credit grant.
$ref=order($other,5000);$r=booking_get($db,$ref);$charges[$intents[$r['stripe_payment_intent_id']]['latest_charge']]['amount_refunded']=100;cancel_plan($ref,'customer',1);$before=$writes;booking_finance_process($db,$ref,$api);check($writes===$before&&booking_finance_status($db,$ref)['credit']===0,'Existing adjustment needs review.');
// Manager cancellation still dispositions a balance payment that arrives afterwards.
$ref=order($other,5000);$s=balance($ref,8000,false);cancel_plan($ref,'staff',1);booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['state']==='pending','Open balance held after cancellation.');portal_balance_event($db,['id'=>'evt_late_balance','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>$s]]);booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['refunded']===13000,'Late signed balance refunded under original management policy.');
// Partial credit never leaves an invalid sub50cent Stripe amount.
// A signed payment arriving inside refund readback must survive the processor's final write.
$ref=order($other,5000);$s=balance($ref,8000,false);cancel_plan($ref,'staff',1);$arrived=false;
$duringRefund=static function($m,$p,$b=[],$k='')use($api,$db,$s,&$arrived){if(!$arrived&&$m==='GET'&&str_starts_with($p,'/refunds')){$arrived=true;portal_balance_event($db,['id'=>'evt_balance_during_refund','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>$s]]);}return $api($m,$p,$b,$k);};
booking_finance_process($db,$ref,$duringRefund);check($arrived&&booking_finance_status($db,$ref)['state']==='pending','Concurrent signed cash remains selected for worker recovery.');
booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['state']==='completed'&&booking_finance_status($db,$ref)['refunded']===13000,'Concurrent late cash receives the full original manager refund.');
$available=booking_finance_available($db,$account);$ref=order($account,$available+25,false);booking_finance_reserve($db,$account,$ref,$api);check(booking_finance_cash(booking_get($db,$ref))===50&&booking_finance_credit_sum($db,$ref,'balance','reserved')===25,'Minimum cash respected; residual credit retained.');
// A source dispute freezes spending instead of allowing stale wallet evidence.
$ref=order($other,12000);cancel_plan($ref,'customer',1);booking_finance_process($db,$ref,$api);$r=booking_get($db,$ref);$charges[$intents[$r['stripe_payment_intent_id']]['latest_charge']]['disputed']=true;$target=order($other,5000,false);rejects(fn()=>booking_finance_reserve($db,$other,$target,$api),'Disputed credit source cannot fund replacement.');
// Unknown legacy cancellations are not automatically paid again.
$ref=order($other,5000);$before=$writes;booking_finance_process($db,$ref,$api);check($writes===$before&&!booking_finance_plan($db,$ref),'No retroactive backfill.');
// Fresh authorization is checked again after provider reads, before any ledger write.
$creditRef=order($account,30000);cancel_plan($creditRef,'customer',1);booking_finance_process($db,$creditRef,$api);
$target=order($account,5000,false);$once=false;$disableApi=static function($m,$p,$b=[],$k='')use($api,$db,$account,&$once){if(!$once){$once=true;$db->prepare('UPDATE portal_accounts SET disabled=1 WHERE id=?')->execute([$account]);}return $api($m,$p,$b,$k);};
$wallet=booking_finance_available($db,$account);rejects(fn()=>booking_finance_reserve($db,$account,$target,$disableApi),'Account disabled during source read cannot reserve.');check(!booking_finance_funding($db,$target)&&booking_finance_available($db,$account)===$wallet,'Denied reserve has no money writes.');$db->prepare('UPDATE portal_accounts SET disabled=0 WHERE id=?')->execute([$account]);
$once=false;$oldHash=booking_get($db,$target)['agent_token_hash'];$revokeApi=static function($m,$p,$b=[],$k='')use($api,$db,$target,&$once){if(!$once){$once=true;$db->prepare('UPDATE bookings SET agent_token_hash=? WHERE reference=?')->execute([str_repeat('f',64),$target]);}return $api($m,$p,$b,$k);};rejects(fn()=>booking_finance_reserve($db,$account,$target,$revokeApi),'Revoked payment capability cannot reserve.');check(!booking_finance_funding($db,$target),'Revocation leaves credit available.');$db->prepare('UPDATE bookings SET agent_token_hash=? WHERE reference=?')->execute([$oldHash,$target]);
booking_finance_reserve($db,$account,$target,$api);$once=false;rejects(fn()=>booking_finance_credit_checkout($db,$account,$target,'127.0.0.1',$disableApi),'Account disabled during full credit verification cannot settle.');check(!booking_get($db,$target)['deposit_paid_at'],'Disabled settlement preserves unpaid order.');$db->prepare('UPDATE portal_accounts SET disabled=0 WHERE id=?')->execute([$account]);booking_finance_credit_checkout($db,$account,$target,'127.0.0.1',$api);
// Subsequent onsite additions use remaining credit without altering the gross scope.
$db->prepare('UPDATE bookings SET approved_at=?,approved_cents=?,duration_minutes=90 WHERE reference=?')->execute([gmdate('c'),15000,$target]);
$db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at) VALUES (?,'confirmed','microsoft:test','event-test',?,?,'{}',?)")->execute([$target,time()+86400,time()+90000,gmdate('c')]);booking_lifecycle_set($db,$target,['state'=>'active']);
$bill=booking_job_bill($db,$target);check($bill['due_cents']===0&&$bill['total_cents']===15000,'Final increased scope previews available credit.');$reserved=booking_finance_credit_sum($db,$target,'balance','reserved');
rejects(fn()=>booking_job_complete($db,$target,$bill['scope'],true,$api,null,static function(){throw new RuntimeException('Vendor grant revoked');}),'Revoked vendor cannot reserve additional balance credit.');check(booking_finance_credit_sum($db,$target,'balance','reserved')===$reserved&&!booking_job_get($db,$target),'Denied vendor leaves wallet/job intact.');
$job=booking_job_complete($db,$target,$bill['scope'],true,$api);check($job['payment_state']==='paid'&&portal_balance_paid($db,$target)===10000,'Final increased scope consumes credit once.');
// Partially credited embedded deposit uses its exact cash amount; signed settlement uses credit once.
$wallet=booking_finance_available($db,$account);$partial=order($account,$wallet+1000,false);$s=null;$embedded=static function($m,$id,$body,$key,$secret)use($partial,&$s){check($m==='POST','Partial checkout POST.');$s=payment($partial,(int)$body['line_items[0][price_data][unit_amount]']);$s['ui_mode']='embedded_page';$s['client_secret']='synthetic_opaque';$s['status']='open';$s['payment_status']='unpaid';return $s;};
$result=portal_purchase_checkout($db,$account,$partial,true,'127.0.0.1',$embedded,null,['enabled'=>true],$api);check($result['mode']==='embedded'&&$s['amount_total']===1000,'Partial credit charges exact cash.');$s['status']='complete';$s['payment_status']='paid';booking_process_stripe_event($db,['id'=>'evt_partial_paid','type'=>'checkout.session.completed','data'=>['object'=>$s]]);$row=booking_get($db,$partial);check($row['deposit_cents']===$wallet+1000&&booking_finance_deposit_evidence($db,$row,$api)['credit']===$wallet,'Gross deposit preserves verified cash and credit.');
// Expiry after payment and repeat completed events cannot return/spend the same credit twice.
booking_process_stripe_event($db,['id'=>'evt_partial_expiry','type'=>'checkout.session.expired','data'=>['object'=>$s]]);check(booking_finance_credit_sum($db,$partial,'deposit')===$wallet,'Expiry cannot release settled credit.');
cancel_plan($partial,'staff',1);booking_finance_process($db,$partial,$api);$restoredWallet=booking_finance_available($db,$account);booking_process_stripe_event($db,['id'=>'evt_partial_paid_again','type'=>'checkout.session.completed','data'=>['object'=>$s]]);check(booking_finance_available($db,$account)===$restoredWallet,'Duplicate paid after cancellation does not spend restored credit.');
// Exact unpaid expiry returns reservations and retires its old payment capability.
$expired=order($account,booking_finance_available($db,$account)+500,false);booking_finance_reserve($db,$account,$expired,$api);$amount=booking_finance_cash(booking_get($db,$expired));$s=payment($expired,$amount);$db->prepare("UPDATE bookings SET checkout_state='open' WHERE reference=?")->execute([$expired]);$s['status']='expired';$s['payment_status']='unpaid';$expected=booking_finance_available($db,$account)+booking_finance_credit_sum($db,$expired,'deposit','reserved')+booking_finance_credit_sum($db,$expired,'balance','reserved');
booking_process_stripe_event($db,['id'=>'evt_credit_expired','type'=>'checkout.session.expired','data'=>['object'=>$s]]);check(booking_finance_available($db,$account)===$expected&&booking_get($db,$expired)['status']==='expired_credit_test','Verified expiry returns all held credit.');rejects(fn()=>portal_purchase_checkout($db,$account,$expired,true,'127.0.0.1',null,null,['enabled'=>true],$api),'Retired expired order cannot issue another reduced checkout.');
// An aged but provably unsubmitted request may safely make its first POST.
$ref=order($account,5000);cancel_plan($ref,'staff',1);$readOffline=static function($m,$p,$b=[],$k='')use($api){if(str_starts_with($p,'/refunds'))throw new RuntimeException('Refund reads unavailable');return $api($m,$p,$b,$k);};booking_finance_process($db,$ref,$readOffline);$before=$writes;booking_finance_process($db,$ref,$api,time()+25*3600);check($writes===$before+1&&booking_finance_status($db,$ref)['refunded']===5000,'Never-submitted aged request creates first refund safely.');
// Late payment wakes a previously completed plan inside the signed payment commit.
$ref=order($account,5000);cancel_plan($ref,'staff',1);booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['state']==='completed','Initial cancellation completed.');$s=balance($ref,5000,false);portal_balance_event($db,['id'=>'evt_completed_plan_late','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>$s]]);check(booking_finance_status($db,$ref)['state']==='queued','Late signed evidence durably wakes completed plan.');booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['refunded']===10000,'Worker refunds late evidence without a browser handoff.');
// A fully credited deposit can collect a later cash balance from its verified card lineage, with fresh consent.
$third=str_repeat('c',32);$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$third,'info@1789media.com',time()]);portal_save_profile($db,$third,['first_name'=>'Synthetic','last_name'=>'Agent','company'=>'Fixture','phone'=>'3125550100']);
$source=order($third,3000,true,'cus_lineage');cancel_plan($source,'customer',1);booking_finance_process($db,$source,$api);$target=order($third,3000,false);booking_finance_reserve($db,$third,$target,$api);booking_finance_credit_checkout($db,$third,$target,'127.0.0.1',$api);
$db->prepare('UPDATE bookings SET approved_at=?,approved_cents=?,duration_minutes=90 WHERE reference=?')->execute([gmdate('c'),10000,$target]);$db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at) VALUES (?,'confirmed','microsoft:test','event-lineage',?,?,'{}',?)")->execute([$target,time()+86400,time()+90000,gmdate('c')]);booking_lifecycle_set($db,$target,['state'=>'active']);
$bill=booking_job_bill($db,$target);check($bill['due_cents']===7000,'Credit leaves exact final cash balance.');$job=booking_job_complete($db,$target,$bill['scope'],true,$api);check($job['payment_state']==='paid'&&$job['amount']===7000,'Verified lineage collects final balance once.');$before=count($intents);booking_job_collect($db,$target,$api);check(count($intents)===$before,'Repeat collection cannot create another intent.');check(booking_get($db,$target)['stripe_payment_intent_id']===null,'Source intent never masquerades as target deposit.');
// Two independently connected processes compete for one wallet; BEGIN IMMEDIATE prevents double reservation.
check(function_exists('pcntl_fork'),'Concurrency coverage requires pcntl.');
$wallet=booking_finance_available($db,$account);check($wallet>0,'Concurrent wallet fixture has funds.');$targets=[order($account,$wallet,false),order($account,$wallet,false)];$pids=[];
foreach($targets as $index=>$target){$pid=pcntl_fork();if($pid===0){
    $finished=true;$db=booking_db();$ready=false;
    $barrierApi=static function($m,$p,$b=[],$k='')use($api,$tmp,$index,&$ready){
        if(!$ready){$ready=true;file_put_contents($tmp.'/reserve-ready-'.$index,'ready');$until=microtime(true)+5;while(!is_file($tmp.'/reserve-ready-'.(1-$index))){if(microtime(true)>$until)throw new RuntimeException('Concurrency barrier failed');usleep(1000);}}
        return $api($m,$p,$b,$k);
    };
    try{booking_finance_reserve($db,$account,$target,$barrierApi);exit(0);}catch(Throwable $e){fwrite(STDERR,'Concurrent reserve failed: '.$e->getMessage()."\n");exit(2);}
}check($pid>0,'Child created.');$pids[]=$pid;}
foreach($pids as $pid){pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'Concurrent reserve completed.');}
$held=0;$funded=0;foreach($targets as $target){$held+=booking_finance_credit_sum($db,$target,'deposit','reserved')+booking_finance_credit_sum($db,$target,'balance','reserved');$funded+=(int)(bool)booking_finance_funding($db,$target);}
check($held===$wallet&&booking_finance_available($db,$account)===0&&$funded===1,'Only one concurrent replacement spends the wallet.');
// Exhausted old credits never block unrelated cash orders or healthy newly available credit.
$q=$db->prepare("SELECT c.* FROM booking_finance_credits c WHERE c.account_id=? AND c.amount=(SELECT COALESCE(SUM(a.amount),0) FROM booking_finance_allocations a WHERE a.credit_id=c.id AND a.state<>'restored') LIMIT 1");$q->execute([$account]);$spent=$q->fetch(PDO::FETCH_ASSOC);check((bool)$spent,'Exhausted source fixture exists.');$spentRow=booking_get($db,$spent['source_reference']);$spentCharge=$intents[$spentRow['stripe_payment_intent_id']]['latest_charge'];$charges[$spentCharge]['amount_refunded']=1;
$cashOrder=order($account,5000,false);$noReads=static function(){throw new RuntimeException('Cash-only checkout should not inspect exhausted credit');};booking_finance_reserve($db,$account,$cashOrder,$noReads);check(!booking_finance_funding($db,$cashOrder)&&booking_finance_cash(booking_get($db,$cashOrder))===5000,'Exhausted adjusted credit does not block cash checkout.');
$healthy=order($account,6000);cancel_plan($healthy,'customer',1);booking_finance_process($db,$healthy,$api);$target=order($account,5000,false);booking_finance_reserve($db,$account,$target,$api);check(booking_finance_cash(booking_get($db,$target))===0,'Healthy available credit remains usable despite exhausted adjustment.');$charges[$spentCharge]['amount_refunded']=0;
// Repeated cancellation after a provider event reappears is explicit review, preserving prior money.
$ref=order($account,5000);cancel_plan($ref,'customer',1);booking_finance_process($db,$ref,$api);$before=$writes;$op=['operation_id'=>bin2hex(random_bytes(16)),'reference'=>$ref,'revision'=>2,'actor'=>'staff','action'=>'cancel','created_at'=>gmdate('c')];$payload2=['claim'=>['planned_start'=>time()+86400]];
$db->prepare("INSERT INTO booking_lifecycle_operations(operation_id,reference,revision,actor,action,state,payload_json,created_at) VALUES (?,?,?,?,?,'applied',?,?)")->execute([$op['operation_id'],$ref,2,'staff','cancel',json_encode($payload2),$op['created_at']]);booking_finance_queue($db,$op,$payload2);booking_finance_process($db,$ref,$api);check(booking_finance_status($db,$ref)['state']==='review'&&$writes===$before,'Repeated cancellation cannot impersonate old completed policy.');
booking_finance_worker_key(true);check((fileperms($tmp.'/private/booking-finance-key.json')&0077)===0,'Worker key private.');putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET');booking_finance_worker_key();check(str_starts_with(booking_test_key(),'sk_test_'),'CLI loads private TEST key.');chmod($tmp.'/private/booking-finance-key.json',0644);clearstatcache();rejects(fn()=>booking_finance_worker_key(),'Broad-readable key rejected.');
$finished=true;echo "booking-finance: PASS ($checks assertions; refund recovery, ownership, cash/credit, balances, late events, card lineage, review, TEST key)\n";
$cleanup=static function($path)use(&$cleanup){foreach(scandir($path) as $name){if($name==='.'||$name==='..')continue;$p=$path.'/'.$name;if(is_dir($p)&&!is_link($p))$cleanup($p);else unlink($p);}rmdir($path);};$db=null;$cleanup($tmp);
