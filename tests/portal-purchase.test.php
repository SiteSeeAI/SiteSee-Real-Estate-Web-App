<?php
declare(strict_types=1);
$completed=false;
register_shutdown_function(static function()use(&$completed):void{if(!$completed){fwrite(STDERR,"Purchase assertions did not complete.\n");exit(1);}});
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');
$dir=sys_get_temp_dir().'/sitesee-purchase-'.bin2hex(random_bytes(8));mkdir($dir,0700);
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$dir.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-purchase-test-secret-not-production-12345');
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_1234567890123456');
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET=whsec_1234567890123456');
require __DIR__.'/../_private/server/portal-purchase.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,string $label):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Accepted: '.$label);}
try{
    $db=booking_db();portal_access_schema($db);portal_profile_schema($db);portal_purchase_schema($db);booking_lifecycle_schema($db);
    $one=str_repeat('a',32);$two=str_repeat('b',32);
    foreach([[$one,'one@example.com'],[$two,'two@example.com']] as [$id,$email])$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,time()]);
    $payload=['market'=>'residential','details'=>['first'=>'Test','last'=>'Customer','company'=>'Synthetic','email'=>'two@example.com','phone'=>'3125550100','street'=>'101 Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],
        'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],
        'appointment'=>['date'=>(new DateTimeImmutable('+8 days'))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]];
    rejects(fn()=>portal_purchase_review($db,$one,$payload),'Missing required account profile cannot create booking review');
    foreach([$one,$two] as $id)portal_save_profile($db,$id,['first_name'=>'Test','last_name'=>'Customer','company'=>'Synthetic','phone'=>'3125550100']);
    $review=portal_purchase_review($db,$one,$payload);check($review['details']['email']==='one@example.com','Server identity wins');
    check(portal_purchase_review($db,$one,$payload)['review']===$review['review'],'Review retry reuses draft');
    rejects(fn()=>portal_purchase_submit($db,$two,$review['review']),'Cross-account intent');
    // Crash immediately after the independently committed capture, before token/owner binding.
    rejects(fn()=>portal_purchase_submit($db,$one,$review['review'],static function(...$args){booking_capture(...$args);throw new RuntimeException('simulated crash');}),'Capture interruption');
    check((int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===1,'Capture committed once');
    check((int)$db->query('SELECT COUNT(*) FROM portal_order_owners')->fetchColumn()===0,'No owner before recovery');
    $reference=portal_purchase_submit($db,$one,$review['review']);
    check(portal_purchase_submit($db,$one,$review['review'])===$reference,'Submit retry returns original order');
    check((int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===1,'No duplicate booking');
    check(portal_owns_order($db,$one,$reference)&&!portal_owns_order($db,$two,$reference),'Durable isolated ownership');
    $row=booking_get($db,$reference);check((int)$row['deposit_cents']===intdiv($review['quote']['totalCents']+1,2),'Canonical deposit');
    $sent=[];$send=static function($recipient,$submission,$ref)use(&$sent){$sent[]=$recipient;return $recipient==='customer';};
    portal_purchase_notify($db,$one,$reference,$send);portal_purchase_notify($db,$one,$reference,$send);check($sent===['staff','customer'],'Failed or successful mail never replayed');
    $seed=portal_purchase_seed($db,$one,$reference);check(!isset($seed['appointment'])&&!isset($seed['details']['email'])&&!str_contains(json_encode($seed),'1234567890'),'Order Again strips access, contact identity, date and consents');
    check($seed['state']['selected']===['photo','mp'],'Order Again selected services');
    rejects(fn()=>portal_purchase_checkout($db,$two,$reference,true,'test'),'Wrong owner checkout');
    rejects(fn()=>portal_purchase_checkout($db,$one,$reference,false,'test'),'Fresh consent required');
    $calls=[];$hosted=static function($body,$id,$key)use(&$calls,$reference,$row){$calls[]=$id;check($body['mode']==='payment'&&!isset($body['subscription_data']),'No subscription');check($body['line_items[0][price_data][unit_amount]']===(string)$row['deposit_cents'],'Trusted amount');check(str_contains($body['success_url'],'/account.php?view=payment'),'Owned return');check(str_starts_with($key,'sk_test_'),'TEST key');return ['id'=>'cs_test_portal','url'=>'https://checkout.stripe.com/c/pay/cs_test_portal','livemode'=>false];};
    $config=['enabled'=>false];
    // An ambiguous transport retry must keep the same provider idempotency key.
    $ambiguous=static function($body,$id,$key)use(&$calls){$calls[]=$id;throw new RuntimeException('transport interrupted');};
    rejects(fn()=>portal_purchase_checkout($db,$one,$reference,true,'test',null,$ambiguous,$config),'Ambiguous transport');
    $checkout=portal_purchase_checkout($db,$one,$reference,true,'test',null,$hosted,$config);
    check($calls[0]===$calls[1],'Ambiguous checkout retries same key');
    check(portal_purchase_checkout($db,$one,$reference,true,'test',null,$hosted,$config)===$checkout && count($calls)===2,'Open checkout reused');
    check(!booking_get($db,$reference)['deposit_paid_at'],'Browser session creation is not payment');
    $event=['id'=>'evt_portal_paid','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['id'=>'cs_test_portal','livemode'=>false,'mode'=>'payment','client_reference_id'=>$reference,'metadata'=>['booking_reference'=>$reference],'payment_status'=>'paid','currency'=>'usd','amount_total'=>(int)$row['deposit_cents'],'customer'=>'cus_synthetic','payment_intent'=>'pi_synthetic']]];
    $raw=json_encode($event);$stamp=time();$signature='t='.$stamp.',v1='.hash_hmac('sha256',$stamp.'.'.$raw,'whsec_1234567890123456');
    rejects(fn()=>booking_verify_stripe_event($raw,'t='.$stamp.',v1='.str_repeat('0',64)),'Unsigned payment');
    $verified=booking_verify_stripe_event($raw,$signature);check(booking_process_stripe_event($db,$verified)==='deposit_paid_test','Signed webhook authoritative');check(booking_process_stripe_event($db,$verified)==='duplicate','Webhook replay harmless');
    check(portal_purchase_checkout($db,$one,$reference,true,'test',null,$hosted,$config)['mode']==='paid'&&count($calls)===2,'Paid order cannot charge again');
    check(!booking_get($db,$reference)['approved_at'],'Payment does not approve price');check((int)$db->query('SELECT COUNT(*) FROM booking_confirmations')->fetchColumn()===0,'Payment does not create calendar event');
    $commercial=$payload;$commercial['market']='commercial';$commercial['state']=['category'=>'small','selected'=>['photo','platform','mp','drone'],'photoCount'=>35,'platformMonths'=>6,'matterportSqft'=>5000,'aerialImages'=>5,'hostingMonths'=>12,'hostingPrepaid'=>true,'licenseType'=>'unlimited'];
    $c=portal_purchase_review($db,$two,$commercial);$ref2=portal_purchase_submit($db,$two,$c['review']);
    check($c['quote']===real_estate_commercial_quote($commercial['state']),'Commercial canonical pricing');
    $embedded=static function($method,$id,$body,$idem,$key)use($ref2,$c):array{check(str_contains($body['return_url'],'/account.php?view=payment'),'Embedded owned return');return ['id'=>'cs_test_embedded','livemode'=>false,'mode'=>'payment','ui_mode'=>'embedded_page','client_reference_id'=>$ref2,'metadata'=>['booking_reference'=>$ref2],'currency'=>'usd','amount_total'=>intdiv($c['quote']['totalCents']+1,2),'status'=>'open','client_secret'=>'synthetic_opaque_secret'];};
    check(portal_purchase_checkout($db,$two,$ref2,true,'test',$embedded,null,['enabled'=>true])['mode']==='embedded','Existing embedded adapter');
    rejects(fn()=>portal_purchase_checkout($db,$two,$ref2,true,'test',null,$hosted,['enabled'=>false]),'No embedded to hosted duplicate');
    $db->prepare("INSERT INTO booking_lifecycle(reference,state) VALUES (?,'cancelled')")->execute([$ref2]);
    rejects(fn()=>portal_purchase_checkout($db,$two,$ref2,true,'test',$embedded,null,['enabled'=>true]),'Cancelled checkout');
    $bad=$payload;$bad['appointment']['cancellationAccepted']=false;rejects(fn()=>portal_purchase_review($db,$one,$bad),'Fresh cancellation consent');
    $bad=$payload;$bad['appointment']['date']=(new DateTimeImmutable('+1 day'))->format('Y-m-d');rejects(fn()=>portal_purchase_review($db,$one,$bad),'Standard 72 hour lead time');
    $bad=$payload;$bad['details']['street']=['unexpected'];rejects(fn()=>portal_purchase_review($db,$one,$bad),'Nested input');
    $late=$payload;$late['details']['street']='Expired draft';$draft=portal_purchase_review($db,$one,$late);$db->prepare('UPDATE portal_submissions SET created_at=0 WHERE id=?')->execute([$draft['review']]);rejects(fn()=>portal_purchase_submit($db,$one,$draft['review']),'Expired review');
    $db->exec("UPDATE portal_accounts SET disabled=1 WHERE id='$two'");check(portal_purchase_seed($db,$two,$ref2)===false,'Disabled owner');
    $completed=true;
    echo "portal-purchase: PASS (canonical prices; crash recovery; ownership; consent; mail retries; hosted/embedded idempotency; signed webhook; Order Again; expiry; disabled/cancelled)\n";
}finally{unset($db);foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
