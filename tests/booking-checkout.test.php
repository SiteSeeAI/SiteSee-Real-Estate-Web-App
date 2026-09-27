<?php
declare(strict_types=1);
$temp=sys_get_temp_dir().'/sitesee-checkout-test-'.bin2hex(random_bytes(6));mkdir($temp,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_'.str_repeat('a',24));
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('q',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$temp.'/bookings.sqlite');
require_once __DIR__.'/../_private/server/booking-checkout.php';
require_once __DIR__.'/../_private/views/booking-payment.php';
$checks=0;
$assert=static function(bool $ok,string $why)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $fn,string $why)use(&$checks):void{$checks++;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){return;}throw new RuntimeException($why);};
$db=booking_db();
$make=static function(string $ref,string $market='residential')use($db):string{
    return booking_capture($db,['action'=>'request_appointment','market'=>$market,
        'details'=>['email'=>'agent@example.test','street'=>'123 Main Street','unit'=>'Suite 2','city'=>'Madison','state'=>'WI','zip'=>'53703'],
        'appointment'=>['date'=>'2026-10-15','time'=>'09:00','windowEnd'=>'11:00','windowMinutes'=>120],
        'quote'=>['totalCents'=>24501,'platformMonthlyCents'=>0,'lines'=>[['key'=>'photo','label'=>'Professional Photography','cents'=>24501]],'packageCents'=>0]],$ref,true);
};
$session=static function(string $ref,string $id='cs_test_embedded',string $status='open'):array{
    return ['id'=>$id,'livemode'=>false,'mode'=>'payment','ui_mode'=>'embedded_page','client_reference_id'=>$ref,
        'metadata'=>['booking_reference'=>$ref],'currency'=>'usd','amount_total'=>12251,'status'=>$status,
        'client_secret'=>$id.'_secret_'.str_repeat('s',24)];
};
$ref='AABBCC0011';$token=$make($ref);$calls=[];
$provider=static function($method,$id,$body,$idem,$key)use(&$calls,$session,$ref,$assert):array{
    $calls[]=[$method,$id,$body,$idem];
    $assert(str_starts_with($key,'sk_test_'),'Only test key goes to Stripe.');
    if($method==='POST'){
        $assert($body['ui_mode']==='embedded_page','Embedded UI is explicit.');
        $assert(!isset($body['success_url'],$body['cancel_url']),'Embedded session excludes hosted redirect fields.');
        $assert($body['line_items[0][price_data][unit_amount]']==='12251','Locked odd-cent deposit rounds upward.');
        $assert($body['payment_intent_data[setup_future_usage]']==='off_session','Approved future-use behavior retained.');
        $assert(!str_contains($body['return_url'],'token='),'Bearer token excluded from Stripe return URL.');
        $assert($body['branding_settings[font_family]']==='inter','Per-session branding is supplied.');
    }
    return $session($ref);
};
$result=booking_checkout_start($db,$ref,$token,'127.0.0.1',$provider);
$assert($result['mode']==='embedded','Embedded session returned.');
$assert(booking_checkout_start($db,$ref,$token,'127.0.0.1',$provider)===$result,'Refresh returns same session secret.');
$assert(array_column($calls,0)===['POST','GET'],'Refresh retrieves instead of creating another session.');
$row=booking_get($db,$ref);
$assert($row['stripe_checkout_url']===null && $row['stripe_session_id']==='cs_test_embedded','Embedded session occupies the existing payment ledger.');
$assert(!str_contains(json_encode($row),'_secret_'),'Client secret absent from booking ledger.');
$assert(!str_contains(json_encode($db->query('SELECT * FROM booking_checkout_ui')->fetchAll()),'_secret_'),'Client secret absent from UI table.');
$assert($row['consent_version']===BOOKING_CONSENT_VERSION,'Consent version recorded.');
$reject(static fn()=>booking_checkout_start($db,$ref,str_repeat('0',64),'127.0.0.1',$provider),'Wrong booking token rejected.');
$assert(count($calls)===2,'Unauthorized access makes no provider call.');
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_live_forbidden');
$reject(static fn()=>booking_checkout_start($db,$ref,$token,'127.0.0.1',$provider),'Live key refused.');
putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET=sk_test_'.str_repeat('a',24));
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');
$reject(static fn()=>booking_checkout_start($db,$ref,$token,'127.0.0.1',$provider),'Disabled test mode refused.');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
foreach(['livemode'=>true,'amount_total'=>1,'currency'=>'eur','client_reference_id'=>'AABBCC0099','ui_mode'=>'hosted_page','id'=>'cs_test_other']as$field=>$value){
    $bad=$session($ref);$bad[$field]=$value;
    $reject(static fn()=>booking_checkout_result($bad,$row,'cs_test_embedded'),'Mismatched '.$field.' rejected.');
}
// Stripe documents client_secret as an opaque string, not an ID-derived format.
$opaque='opaque.checkout.'.str_repeat('Ab9_-/+=',55);
$opaqueSession=$session($ref);$opaqueSession['client_secret']=$opaque;
$assert(booking_checkout_result($opaqueSession,$row,'cs_test_embedded')['clientSecret']===$opaque,'Opaque Stripe secret passes through unchanged.');
foreach([null,'','   ',123,[],"token\nvalue",str_repeat('x',16385)]as$invalidSecret){
    $bad=$session($ref);$bad['client_secret']=$invalidSecret;
    $reject(static fn()=>booking_checkout_result($bad,$row),'Missing, non-string, control-character or oversized secret rejected.');
}
$complete=static fn()=> $session($ref,'cs_test_embedded','complete');
$assert(booking_checkout_start($db,$ref,$token,'127.0.0.1',$complete)['mode']==='pending','Complete Stripe session waits for signed webhook.');
$assert(booking_get($db,$ref)['status']==='awaiting_deposit_test','Return lookup cannot mark deposit paid.');
$assert(booking_checkout_return($db,booking_get($db,$ref),$complete)==='complete','Return status reads the saved session.');
$event=['id'=>'evt_embedded','type'=>'checkout.session.completed','data'=>['object'=>[
    'id'=>'cs_test_embedded','livemode'=>false,'mode'=>'payment','client_reference_id'=>$ref,
    'metadata'=>['booking_reference'=>$ref],'payment_status'=>'paid','currency'=>'usd','amount_total'=>12251,
    'payment_intent'=>'pi_embedded','customer'=>'cus_embedded']]];
$assert(booking_process_stripe_event($db,$event)==='deposit_paid_test','Existing verified-webhook handler accepts embedded session.');
$assert(booking_process_stripe_event($db,$event)==='duplicate','Webhook duplication remains harmless.');
$assert(booking_checkout_start($db,$ref,$token,'127.0.0.1',$provider)['mode']==='paid','Paid booking never creates another session.');
$assert(count($calls)===2,'Paid booking does not call Stripe.');

$legacy='AABBCC0022';$legacyToken=$make($legacy);
booking_start_checkout($db,$legacy,$legacyToken,'127.0.0.1',static fn()=>['id'=>'cs_test_old','url'=>'https://checkout.stripe.com/c/pay/old','livemode'=>false]);
$old=booking_checkout_start($db,$legacy,$legacyToken,'127.0.0.1',static function(){throw new RuntimeException('Must not call new provider.');});
$assert($old===['mode'=>'hosted','url'=>'https://checkout.stripe.com/c/pay/old'],'Existing hosted payment link is reused.');
$ambiguous='AABBCC0023';$ambiguousToken=$make($ambiguous);
$db->prepare("UPDATE bookings SET checkout_state='creating', checkout_attempt=1, checkout_started=? WHERE reference=?")->execute([time()-130,$ambiguous]);
$legacyRetry=static function($body,$idempotency)use($assert,$ambiguous):array{
    $assert(!isset($body['ui_mode']) && isset($body['success_url'],$body['cancel_url']),'Ambiguous hosted attempt preserves original request format.');
    $assert($idempotency==='sitesee-deposit-test-'.$ambiguous.'-1','Ambiguous hosted attempt preserves original idempotency key.');
    return ['id'=>'cs_test_legacy_recovered','url'=>'https://checkout.stripe.com/c/pay/recovered','livemode'=>false];
};
$assert(booking_checkout_start($db,$ambiguous,$ambiguousToken,'127.0.0.1',null,$legacyRetry)['mode']==='hosted','Legacy timeout never becomes a second embedded payment.');

$retry='AABBCC0033';$retryToken=$make($retry,'commercial');$keys=[];
$flaky=static function($method,$id,$body,$idem)use(&$keys,$session,$retry):array{
    $keys[]=$idem;if(count($keys)===1)throw new RuntimeException('Timeout after acceptance.');
    return $session($retry,'cs_test_retry');
};
$reject(static fn()=>booking_checkout_start($db,$retry,$retryToken,'127.0.0.1',$flaky),'Ambiguous provider timeout remains retryable.');
$assert(booking_checkout_start($db,$retry,$retryToken,'127.0.0.1',$flaky)['mode']==='embedded','Retry recovers the session.');
$assert(count($keys)===2 && $keys[0]===$keys[1],'Retry uses the same idempotency key.');
$expiry=static fn()=> $session($retry,'cs_test_retry','expired');
$assert(booking_checkout_start($db,$retry,$retryToken,'127.0.0.1',$expiry)['mode']==='expired','Verified expiry allows a fresh attempt.');
$assert(booking_get($db,$retry)['checkout_state']==='expired','Exact expired session recorded.');
$newKey='';$new=static function($method,$id,$body,$idem)use(&$newKey,$session,$retry){$newKey=$idem;return $session($retry,'cs_test_new');};
booking_checkout_start($db,$retry,$retryToken,'127.0.0.1',$new);
$assert(str_ends_with($newKey,'-2'),'New session only after verified expiry gets next attempt.');
$late=['id'=>'evt_old_expiry','type'=>'checkout.session.expired','data'=>['object'=>[
    'id'=>'cs_test_retry','livemode'=>false,'mode'=>'payment','client_reference_id'=>$retry,'metadata'=>['booking_reference'=>$retry]]]];
$assert(booking_process_stripe_event($db,$late)==='stale_expired','Late expiry cannot close the replacement session.');

$locked='AABBCC0044';$lockedToken=$make($locked);
booking_checkout_schema($db);
$db->prepare("UPDATE bookings SET checkout_state='creating', checkout_attempt=1, checkout_started=? WHERE reference=?")->execute([time(),$locked]);
$db->prepare('INSERT INTO booking_checkout_ui VALUES (?,?,?)')->execute([$locked,1,BOOKING_CHECKOUT_API_VERSION]);
$reject(static fn()=>booking_checkout_start($db,$locked,$lockedToken,'127.0.0.1',$provider),'Concurrent submission cannot bypass creation lease.');
$db->exec('BEGIN IMMEDIATE');$db->exec('ROLLBACK'); // Rejection released its transaction.

// A provider success followed by local secret rejection must recover the same
// session with the exact original POST and idempotency key, then use GET only.
$recovery='AABBCC0055';$recoveryToken=$make($recovery);$recoveryCalls=[];
$recover=static function($method,$id,$body,$idem)use(&$recoveryCalls,$session,$recovery,$opaque):array{
    $recoveryCalls[]=[$method,$id,$body,$idem];
    $reply=$session($recovery,'cs_test_recovered');
    $reply['client_secret']=count($recoveryCalls)===1 ? null : $opaque;
    return $reply;
};
$reject(static fn()=>booking_checkout_start($db,$recovery,$recoveryToken,'127.0.0.1',$recover),'Local rejection after provider success is recoverable.');
$failed=booking_get($db,$recovery);
$assert($failed['checkout_state']==='creating' && (int)$failed['checkout_attempt']===1,'Failed validation preserves the original attempt.');
$assert(booking_checkout_start($db,$recovery,$recoveryToken,'127.0.0.1',$recover)['clientSecret']===$opaque,'Retry accepts an opaque provider secret.');
$assert($recoveryCalls[0]===$recoveryCalls[1],'Recovery replays the exact original request and idempotency key.');
$recovered=booking_get($db,$recovery);
$assert($recovered['stripe_session_id']==='cs_test_recovered' && $recovered['checkout_state']==='open','Recovered session is attached to the existing ledger.');
$assert($recovered['status']==='awaiting_deposit_test','Recovering a session does not record payment.');
$assert(!str_contains(json_encode($recovered),$opaque) && !str_contains(json_encode($db->query('SELECT * FROM booking_checkout_ui')->fetchAll()),$opaque),'Opaque secret is absent from both persistent records.');
booking_checkout_start($db,$recovery,$recoveryToken,'127.0.0.1',$recover);
$assert(array_column($recoveryCalls,0)===['POST','POST','GET'],'Subsequent retry retrieves the recovered session.');

$viewRow=booking_get($db,$retry);$req=booking_request($viewRow);$req['details']['street']='<script>alert(1)</script>';
$viewRow['request_json']=json_encode($req);
$html=pay_render('<h2>Payment</h2>',['row'=>$viewRow,'nonce'=>'fixture','client'=>['reference'=>'</script><script>bad()</script>']]);
$assert(str_contains($html,'&lt;script&gt;alert(1)&lt;/script&gt;'),'Booking details escaped.');
$assert(!str_contains($html,'<script>bad()'),'Client settings escape script boundaries.');
$assert(str_contains($html,'800 222-2053') && str_contains($html,'Show More. Decide Faster.'),'Brand and support details present.');
$assert(!str_contains($html,'sk_test_'),'No Stripe secret key in page.');
$db=null;foreach(glob($temp.'/*')as$file)unlink($file);rmdir($temp);
echo "Booking checkout: $checks checks passed.\n";
