<?php
declare(strict_types=1);
require_once __DIR__.'/portal-service.php';
require_once __DIR__.'/booking-checkout.php';

function portal_billing_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS portal_balance_attempts (
        reference TEXT NOT NULL, attempt INTEGER NOT NULL, account_id TEXT NOT NULL,
        amount INTEGER NOT NULL, scope TEXT NOT NULL, customer TEXT NOT NULL,
        state TEXT NOT NULL, created_at INTEGER NOT NULL, request_json TEXT NOT NULL,
        session_id TEXT UNIQUE, payment_intent TEXT UNIQUE, paid_at TEXT,
        consent_text TEXT NOT NULL, consent_at INTEGER NOT NULL,
        PRIMARY KEY(reference,attempt))");
    $db->exec('CREATE TABLE IF NOT EXISTS portal_balance_events (event_id TEXT PRIMARY KEY, reference TEXT NOT NULL, processed_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_billing_config (singleton INTEGER PRIMARY KEY CHECK(singleton=1), configuration TEXT NOT NULL)');
}
function portal_billing_latest(PDO $db,string $reference): array|false
{
    $q=$db->prepare('SELECT * FROM portal_balance_attempts WHERE reference=? ORDER BY attempt DESC LIMIT 1');$q->execute([$reference]);return $q->fetch(PDO::FETCH_ASSOC);
}
function portal_balance_paid(PDO $db,string $reference): int
{
    $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM portal_balance_attempts WHERE reference=? AND paid_at IS NOT NULL');$q->execute([$reference]);return (int)$q->fetchColumn();
}
function portal_billing_url(string $url,array $hosts): string
{
    $p=parse_url($url);
    if(!is_array($p)||($p['scheme']??'')!=='https'||!in_array($p['host']??'',$hosts,true)||isset($p['user'])||isset($p['pass'])||isset($p['port']))throw new RuntimeException('Unverified billing link.');
    return $url;
}
/** Fixed provider, narrowly allowlisted endpoints, TEST credentials, bounded TLS requests. */
function portal_stripe(string $method,string $path,array $body=[],string $idempotency=''): array
{
    $key=booking_test_key();
    $read='~^/(?:checkout/sessions/cs_test_[A-Za-z0-9_]+|payment_intents/pi_[A-Za-z0-9_]+|charges/ch_[A-Za-z0-9_]+|invoices/in_[A-Za-z0-9_]+|billing_portal/configurations/bpc_[A-Za-z0-9_]+|checkout/sessions\?customer=cus_[A-Za-z0-9_]+&limit=100)$~D';
    if(!(($method==='GET'&&preg_match($read,$path))||($method==='POST'&&in_array($path,['/checkout/sessions','/billing_portal/configurations','/billing_portal/sessions'],true)&&preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$idempotency))))throw new RuntimeException('Invalid billing request.');
    $curl=curl_init('https://api.stripe.com/v1'.$path);if($curl===false)throw new RuntimeException('Billing unavailable.');
    $headers=['Authorization: Bearer '.$key,'Stripe-Version: '.BOOKING_CHECKOUT_API_VERSION];
    if($method==='POST'){$headers[]='Idempotency-Key: '.$idempotency;$headers[]='Content-Type: application/x-www-form-urlencoded';curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($body,'','&',PHP_QUERY_RFC3986));}
    $response='';curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>1048576)return 0;$response.=$chunk;return strlen($chunk);}]);
    try{$ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
    if($ok===false||$status!==200)throw new RuntimeException('Billing is temporarily unavailable.');
    $result=json_decode($response,true,32,JSON_THROW_ON_ERROR);if(!is_array($result))throw new RuntimeException('Invalid billing response.');return $result;
}
function portal_billing_need(bool $valid): void {if(!$valid)throw new RuntimeException('Payment evidence could not be verified. Contact SiteSee.');}
function portal_payment_evidence(array $row,int $amount,string $sessionId,string $intent,string $customer,callable $api,string $kind='deposit',?int $attempt=null): array
{
    portal_billing_need((bool)preg_match('/^cs_test_[A-Za-z0-9_]+$/D',$sessionId)&&(bool)preg_match('/^pi_[A-Za-z0-9_]+$/D',$intent)&&(bool)preg_match('/^cus_[A-Za-z0-9_]+$/D',$customer));
    $s=$api('GET','/checkout/sessions/'.$sessionId);
    portal_billing_need(($s['id']??'')===$sessionId&&($s['livemode']??null)===false&&($s['mode']??'')==='payment'&&($s['status']??'')==='complete'&&($s['payment_status']??'')==='paid'&&($s['currency']??'')==='usd'&&($s['amount_total']??null)===$amount&&($s['client_reference_id']??'')===$row['reference']&&($s['metadata']['booking_reference']??'')===$row['reference']&&($s['customer']??'')===$customer&&($s['payment_intent']??'')===$intent);
    if($kind==='balance')portal_billing_need(($s['metadata']['portal_payment_kind']??'')==='balance'&&($s['metadata']['portal_attempt']??'')===(string)$attempt);
    $p=$api('GET','/payment_intents/'.$intent);
    portal_billing_need(($p['id']??'')===$intent&&($p['livemode']??null)===false&&($p['customer']??'')===$customer&&($p['status']??'')==='succeeded'&&($p['currency']??'')==='usd'&&($p['amount_received']??null)===$amount&&(bool)preg_match('/^ch_[A-Za-z0-9_]+$/D',(string)($p['latest_charge']??'')));
    $c=$api('GET','/charges/'.$p['latest_charge']);
    portal_billing_need(($c['id']??'')===$p['latest_charge']&&($c['livemode']??null)===false&&($c['customer']??'')===$customer&&($c['payment_intent']??'')===$intent&&($c['paid']??false)===true&&($c['captured']??false)===true&&($c['currency']??'')==='usd'&&($c['amount']??null)===$amount&&($c['amount_captured']??null)===$amount&&is_int($c['amount_refunded']??null)&&$c['amount_refunded']>=0&&$c['amount_refunded']<=$amount&&is_bool($c['disputed']??null));
    $result=['kind'=>$kind,'amount'=>$amount,'refunded'=>$c['amount_refunded'],'disputed'=>$c['disputed'],'receipt'=>null,'invoice'=>null,'pdf'=>null];
    if(!empty($c['receipt_url']))$result['receipt']=portal_billing_url($c['receipt_url'],['pay.stripe.com']);
    if(!empty($s['invoice'])){
        portal_billing_need(is_string($s['invoice'])&&(bool)preg_match('/^in_[A-Za-z0-9_]+$/D',$s['invoice']));$i=$api('GET','/invoices/'.$s['invoice']);
        portal_billing_need(($i['id']??'')===$s['invoice']&&($i['livemode']??null)===false&&($i['customer']??'')===$customer&&($i['currency']??'')==='usd'&&($i['status']??'')==='paid'&&($i['amount_paid']??null)===$amount);
        foreach(['hosted_invoice_url'=>'invoice','invoice_pdf'=>'pdf'] as $source=>$target)if(!empty($i[$source]))$result[$target]=portal_billing_url($i[$source],['invoice.stripe.com','pay.stripe.com']);
    }
    return $result;
}
function portal_billing_records(PDO $db,string $account,string $reference,?callable $api=null): array
{
    $row=portal_service_owned($db,$account,$reference);$api??='portal_stripe';$out=[];
    if($row['deposit_paid_at'])$out[]=portal_payment_evidence($row,(int)$row['deposit_cents'],$row['stripe_session_id'],$row['stripe_payment_intent_id'],$row['stripe_customer_id'],$api);
    $q=$db->prepare("SELECT * FROM portal_balance_attempts WHERE reference=? AND paid_at IS NOT NULL ORDER BY attempt");$q->execute([$reference]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $b)$out[]=portal_payment_evidence($row,(int)$b['amount'],$b['session_id'],$b['payment_intent'],$b['customer'],$api,'balance',(int)$b['attempt']);
    return $out;
}
function portal_balance_scope(PDO $db,string $account,string $reference): array
{
    $row=portal_service_owned($db,$account,$reference);$s=$row;$life=booking_lifecycle_state($db,$reference);
    if(!booking_test_enabled()||!$row['deposit_paid_at']||!$row['approved_at']||$row['status']!=='deposit_paid_test'||!$s||($s['reschedule_required']??0)||($s['rush_status']??'')==='pending'||$life['state']!=='active'||booking_lifecycle_pending($db,$reference))throw new InvalidArgumentException('Your order needs staff review before balance payment.');
    $amount=(int)$row['approved_cents']+(int)$s['rush_fee_cents']-(int)$row['deposit_cents'];
    if($amount<=0)throw new InvalidArgumentException('No balance payment is due.');
    $scope=hash('sha256',json_encode([$reference,$row['approved_at'],(int)$row['approved_cents'],(int)$row['deposit_cents'],(int)$s['rush_fee_cents'],$row['request_json']],JSON_THROW_ON_ERROR));
    return ['row'=>$row,'amount'=>$amount,'scope'=>$scope];
}
function portal_balance_validate(array $s,array $b): void
{
    portal_billing_need(($s['livemode']??null)===false&&($s['mode']??'')==='payment'&&(bool)preg_match('/^cs_test_[A-Za-z0-9_]+$/D',(string)($s['id']??''))&&(!$b['session_id']||$b['session_id']===$s['id'])&&($s['client_reference_id']??'')===$b['reference']&&($s['metadata']['booking_reference']??'')===$b['reference']&&($s['metadata']['portal_payment_kind']??'')==='balance'&&($s['metadata']['portal_attempt']??'')===(string)$b['attempt']&&($s['amount_total']??null)===(int)$b['amount']&&($s['currency']??'')==='usd'&&($s['customer']??'')===$b['customer']);
}
function portal_balance_result(array $s,array $b): array
{
    portal_balance_validate($s,$b);
    if(($s['status']??'')==='complete')return ['mode'=>'pending'];
    if(($s['status']??'')==='expired')return ['mode'=>'expired'];
    portal_billing_need(($s['status']??'')==='open');
    $request=json_decode($b['request_json'],true,32,JSON_THROW_ON_ERROR);
    if(($request['ui_mode']??'')==='embedded_page'){
        portal_billing_need(($s['ui_mode']??'')==='embedded_page'&&is_string($s['client_secret']??null)&&$s['client_secret']!=='');return ['mode'=>'embedded','clientSecret'=>$s['client_secret']];
    }
    return ['mode'=>'hosted','url'=>portal_billing_url((string)($s['url']??''),['checkout.stripe.com'])];
}
/** A saved immutable request is retried with one key. A new attempt requires verified expiry. */
function portal_balance_checkout(PDO $db,string $account,string $reference,string $scope,bool $consent,?callable $api=null,?array $config=null): array
{
    portal_service_owned($db,$account,$reference);
    if(!$consent)throw new InvalidArgumentException('Accept this balance payment before continuing.');
    $latest=portal_billing_latest($db,$reference);if(portal_balance_paid($db,$reference)>0)return ['mode'=>'paid'];
    $current=portal_balance_scope($db,$account,$reference);if(!hash_equals($current['scope'],$scope))throw new InvalidArgumentException('The approved amount changed. Refresh and review it.');
    $api??='portal_stripe';$config??=booking_checkout_config();
    $records=portal_billing_records($db,$account,$reference,$api);
    foreach($records as $payment)if($payment['refunded']||$payment['disputed'])throw new InvalidArgumentException('A payment adjustment needs staff review before any new payment.');
    if($latest&&$latest['state']==='open'){
        if($latest['scope']!==$scope)throw new InvalidArgumentException('The previous payment needs staff review.');
        $session=$api('GET','/checkout/sessions/'.$latest['session_id']);portal_balance_validate($session,$latest);
        if(($session['status']??'')!=='expired')return portal_balance_result($session,$latest);
        $db->prepare("UPDATE portal_balance_attempts SET state='expired' WHERE reference=? AND attempt=? AND state='open' AND paid_at IS NULL")->execute([$reference,$latest['attempt']]);
        return ['mode'=>'expired'];
    }
    $db->exec('BEGIN IMMEDIATE');
    try{
        $fresh=portal_balance_scope($db,$account,$reference);if($fresh['scope']!==$scope)throw new InvalidArgumentException('Refresh the approved amount.');
        $latest=portal_billing_latest($db,$reference);
        if(portal_balance_paid($db,$reference)>0){$db->exec('COMMIT');return ['mode'=>'paid'];}
        if($latest&&$latest['state']==='open')throw new InvalidArgumentException('Payment is already opening. Retry shortly.');
        if($latest&&$latest['state']==='creating'){
            if($latest['scope']!==$scope||time()-(int)$latest['created_at']>23*3600)throw new InvalidArgumentException('The previous payment needs staff review before retrying.');
        }else{
            $fresh=portal_balance_scope($db,$account,$reference);if($fresh['scope']!==$scope)throw new InvalidArgumentException('Refresh the approved amount.');
            $attempt=$latest?(int)$latest['attempt']+1:1;
            $body=['mode'=>'payment','customer'=>$fresh['row']['stripe_customer_id'],'client_reference_id'=>$reference,'metadata[booking_reference]'=>$reference,'metadata[portal_payment_kind]'=>'balance','metadata[portal_attempt]'=>(string)$attempt,'payment_method_types[0]'=>'card','invoice_creation[enabled]'=>'true','line_items[0][quantity]'=>'1','line_items[0][price_data][currency]'=>'usd','line_items[0][price_data][unit_amount]'=>(string)$fresh['amount'],'line_items[0][price_data][product_data][name]'=>'SiteSee approved job balance','payment_intent_data[metadata][booking_reference]'=>$reference,'payment_intent_data[metadata][portal_payment_kind]'=>'balance'];
            $return=SITESEE_REAL_ESTATE_SITE_URL.'/account.php?view=balance&reference='.$reference.'&result=return';
            if($config['enabled']){$body['ui_mode']='embedded_page';$body['return_url']=$return;}else{$body['success_url']=$return;$body['cancel_url']=$return;}
            $text='I authorize one TEST payment of '.real_estate_money($fresh['amount']).' for the approved balance on order '.$reference.'.';
            $db->prepare("INSERT INTO portal_balance_attempts(reference,attempt,account_id,amount,scope,customer,state,created_at,request_json,consent_text,consent_at) VALUES (?,?,?,?,?,?,'creating',?,?,?,?)")->execute([$reference,$attempt,$account,$fresh['amount'],$scope,$fresh['row']['stripe_customer_id'],time(),json_encode($body,JSON_THROW_ON_ERROR),$text,time()]);
            $latest=portal_billing_latest($db,$reference);
        }
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    $body=json_decode($latest['request_json'],true,32,JSON_THROW_ON_ERROR);
    // Do not silently switch an existing embedded attempt to hosted checkout.
    if(($body['ui_mode']??'')==='embedded_page'&&!$config['enabled'])throw new InvalidArgumentException('The saved secure payment form needs staff review.');
    $session=$api('POST','/checkout/sessions',$body,'sitesee-balance-test-'.$reference.'-'.$latest['attempt']);portal_balance_validate($session,$latest);
    $db->prepare("UPDATE portal_balance_attempts SET session_id=?,state='open' WHERE reference=? AND attempt=? AND state='creating' AND paid_at IS NULL")->execute([$session['id'],$reference,$latest['attempt']]);
    $saved=portal_billing_latest($db,$reference);if(portal_balance_paid($db,$reference)>0)return ['mode'=>'paid'];if($saved['state']==='expired')return ['mode'=>'expired'];
    return portal_balance_result($session,$saved);
}
/** Called only after the shared endpoint verifies Stripe's signature. Never gated on login state. */
function portal_balance_event(PDO $db,array $event): bool
{
    $s=$event['data']['object']??[];if(($s['metadata']['portal_payment_kind']??'')!=='balance')return false;
    if(!in_array($event['type']??'',['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],true))return true;
    portal_billing_need(($event['livemode']??null)===false&&(bool)preg_match('/^evt_[A-Za-z0-9_]+$/D',(string)($event['id']??'')));
    portal_billing_schema($db);$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT 1 FROM portal_balance_events WHERE event_id=?');$q->execute([$event['id']]);if($q->fetchColumn()){$db->exec('COMMIT');return true;}
        $q=$db->prepare('SELECT * FROM portal_balance_attempts WHERE reference=? AND attempt=?');$q->execute([$s['metadata']['booking_reference']??'',$s['metadata']['portal_attempt']??'']);$b=$q->fetch(PDO::FETCH_ASSOC);portal_billing_need((bool)$b);portal_balance_validate($s,$b);
        if($event['type']==='checkout.session.expired'){
            portal_billing_need(($s['status']??'')==='expired');$db->prepare("UPDATE portal_balance_attempts SET session_id=?,state='expired' WHERE reference=? AND attempt=? AND paid_at IS NULL")->execute([$s['id'],$b['reference'],$b['attempt']]);
        }else{
            portal_billing_need(($s['payment_status']??'')==='paid'&&($s['status']??'')==='complete'&&(bool)preg_match('/^pi_[A-Za-z0-9_]+$/D',(string)($s['payment_intent']??'')));
            if($b['paid_at'])portal_billing_need($b['payment_intent']===$s['payment_intent']);
            $db->prepare("UPDATE portal_balance_attempts SET session_id=?,payment_intent=?,state='paid',paid_at=COALESCE(paid_at,?) WHERE reference=? AND attempt=?")->execute([$s['id'],$s['payment_intent'],gmdate('c'),$b['reference'],$b['attempt']]);
        }
        $db->prepare('INSERT INTO portal_balance_events VALUES (?,?,?)')->execute([$event['id'],$b['reference'],time()]);$db->exec('COMMIT');return true;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Payment-method access requires a verified customer with no unknown or differently owned sessions. */
function portal_billing_customer(PDO $db,string $account,string $reference,callable $api): string
{
    $row=portal_service_owned($db,$account,$reference);portal_billing_need((bool)$row['deposit_paid_at']);
    portal_payment_evidence($row,(int)$row['deposit_cents'],$row['stripe_session_id'],$row['stripe_payment_intent_id'],$row['stripe_customer_id'],$api);
    $customer=$row['stripe_customer_id'];$q=$db->prepare('SELECT reference FROM bookings WHERE stripe_customer_id=?');$q->execute([$customer]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $ref)portal_billing_need(portal_owns_order($db,$account,$ref));
    $sessions=$api('GET','/checkout/sessions?customer='.$customer.'&limit=100');portal_billing_need(($sessions['has_more']??null)===false&&is_array($sessions['data']??null)&&count($sessions['data'])>0);
    foreach($sessions['data'] as $s){
        $ref=$s['metadata']['booking_reference']??'';portal_billing_need(is_string($ref)&&portal_owns_order($db,$account,$ref)&&($s['customer']??'')===$customer&&($s['livemode']??null)===false);
        $owned=booking_get($db,$ref);$known=$owned['stripe_session_id']===($s['id']??'');
        if(!$known){$q=$db->prepare('SELECT 1 FROM portal_balance_attempts WHERE reference=? AND session_id=? AND customer=?');$q->execute([$ref,$s['id']??'',$customer]);$known=(bool)$q->fetchColumn();}portal_billing_need($known);
    }
    return $customer;
}
function portal_billing_config_valid(array $c): void
{
    portal_billing_need(($c['livemode']??null)===false&&($c['active']??null)===true&&(bool)preg_match('/^bpc_[A-Za-z0-9_]+$/D',(string)($c['id']??''))&&($c['login_page']['enabled']??null)===false&&($c['features']['payment_method_update']['enabled']??null)===true);
    foreach($c['features']??[] as $key=>$feature)if($key!=='payment_method_update')portal_billing_need(($feature['enabled']??false)===false);
}
function portal_billing_manage(PDO $db,string $account,string $reference,?callable $api=null): string
{
    $api??='portal_stripe';$customer=portal_billing_customer($db,$account,$reference,$api);
    $id=$db->query('SELECT configuration FROM portal_billing_config WHERE singleton=1')->fetchColumn();
    if($id){$c=$api('GET','/billing_portal/configurations/'.$id);}else{
        $body=['features[payment_method_update][enabled]'=>'true','features[customer_update][enabled]'=>'false','features[invoice_history][enabled]'=>'false','features[subscription_update][enabled]'=>'false','features[subscription_cancel][enabled]'=>'false','login_page[enabled]'=>'false','business_profile[headline]'=>'SiteSee test payment methods'];
        $c=$api('POST','/billing_portal/configurations',$body,'sitesee-owned-methods-test-v1');portal_billing_config_valid($c);
        $db->prepare('INSERT OR IGNORE INTO portal_billing_config VALUES (1,?)')->execute([$c['id']]);
    }
    portal_billing_config_valid($c);
    $s=$api('POST','/billing_portal/sessions',['customer'=>$customer,'configuration'=>$c['id'],'return_url'=>SITESEE_REAL_ESTATE_SITE_URL.'/account.php?view=billing&reference='.$reference],'sitesee-methods-'.bin2hex(random_bytes(16)));
    portal_billing_need(($s['livemode']??null)===false&&($s['customer']??'')===$customer&&($s['configuration']??'')===$c['id']);return portal_billing_url((string)($s['url']??''),['billing.stripe.com']);
}
