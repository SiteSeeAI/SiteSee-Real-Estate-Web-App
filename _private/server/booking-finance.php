<?php
declare(strict_types=1);

/** Additive TEST ledger. Prices and Stripe payment rows remain gross/immutable. */
function booking_finance_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS booking_finance_cancellations (
        operation_id TEXT PRIMARY KEY, reference TEXT NOT NULL UNIQUE, actor TEXT NOT NULL,
        requested_at INTEGER NOT NULL, appointment_start INTEGER NOT NULL, rule TEXT NOT NULL,
        account_id TEXT, state TEXT NOT NULL DEFAULT 'queued', checked_at INTEGER, diagnostic TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_finance_refunds (
        id TEXT PRIMARY KEY, operation_id TEXT NOT NULL, payment_intent TEXT NOT NULL UNIQUE,
        charge TEXT NOT NULL UNIQUE, amount INTEGER NOT NULL CHECK(amount>0),
        request_json TEXT NOT NULL, created_at INTEGER NOT NULL, submitted_at INTEGER, refund_id TEXT UNIQUE,
        state TEXT NOT NULL DEFAULT 'prepared', checked_at INTEGER, diagnostic TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_finance_credits (
        id TEXT PRIMARY KEY, operation_id TEXT NOT NULL UNIQUE, source_reference TEXT NOT NULL,
        account_id TEXT NOT NULL, amount INTEGER NOT NULL CHECK(amount>0), created_at INTEGER NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_finance_allocations (
        id TEXT PRIMARY KEY, credit_id TEXT NOT NULL, reference TEXT NOT NULL, phase TEXT NOT NULL,
        amount INTEGER NOT NULL CHECK(amount>0), state TEXT NOT NULL CHECK(state IN ('reserved','used','restored')),
        created_at INTEGER NOT NULL, restored_at INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_finance_funding (
        reference TEXT PRIMARY KEY, account_id TEXT NOT NULL, cash_deposit INTEGER NOT NULL CHECK(cash_deposit>=0),
        deposit_credit INTEGER NOT NULL CHECK(deposit_credit>=0), source_reference TEXT NOT NULL,
        customer TEXT NOT NULL, card_intent TEXT NOT NULL, created_at INTEGER NOT NULL)");
}
function booking_finance_owner(PDO $db,string $reference): ?string
{
    $exists=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_order_owners'")->fetchColumn();
    if(!$exists)return null;
    $q=$db->prepare('SELECT account_id FROM portal_order_owners WHERE reference=?');$q->execute([$reference]);$id=$q->fetchColumn();return $id===false?null:(string)$id;
}
function booking_finance_funding(PDO $db,string $reference): array|false
{
    booking_finance_schema($db);$q=$db->prepare('SELECT * FROM booking_finance_funding WHERE reference=?');$q->execute([$reference]);return $q->fetch(PDO::FETCH_ASSOC);
}
function booking_finance_capability(PDO $db,string $account,string $reference): void
{
    $intent=portal_purchase_intent_for_order($db,$account,$reference);$row=booking_get($db,$reference);
    if(!$intent||!portal_owns_order($db,$account,$reference)||!hash_equals((string)$row['agent_token_hash'],hash('sha256',portal_purchase_token($intent))))throw new InvalidArgumentException('The payment link was updated. Contact SiteSee.');
}
function booking_finance_enrich(PDO $db,array $row): array
{
    $f=booking_finance_funding($db,$row['reference']);
    if($f){$row['cash_deposit_cents']=(int)$f['cash_deposit'];$row['deposit_credit_cents']=(int)$f['deposit_credit'];$row['credit_card_intent']=$f['card_intent'];}
    return $row;
}
function booking_finance_cash(array $row): int {return (int)($row['cash_deposit_cents']??$row['deposit_cents']);}

/** Called inside the applied calendar operation's transaction. No provider calls. */
function booking_finance_queue(PDO $db,array $op,array $payload): void
{
    booking_finance_schema($db);
    $requested=strtotime($op['created_at']);$start=(int)$payload['claim']['planned_start'];
    if($requested===false||$start<=0||!in_array($op['actor'],['customer','staff'],true))throw new RuntimeException('Cancellation policy evidence differs.');
    $rule=$op['actor']==='staff'?'management_refund':($start-$requested>=86400?'customer_refund':'customer_credit');
    $existing=booking_finance_plan($db,$op['reference']);
    if($existing&&$existing['operation_id']!==$op['operation_id']){
        $db->prepare("UPDATE booking_finance_cancellations SET state='review',diagnostic='A repeated cancellation requires staff review of the earlier refund or credit.' WHERE reference=?")->execute([$op['reference']]);return;
    }
    $db->prepare('INSERT OR IGNORE INTO booking_finance_cancellations(operation_id,reference,actor,requested_at,appointment_start,rule,account_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$op['operation_id'],$op['reference'],$op['actor'],$requested,$start,$rule,booking_finance_owner($db,$op['reference'])]);
}
function booking_finance_plan(PDO $db,string $reference): array|false
{
    booking_finance_schema($db);$q=$db->prepare('SELECT * FROM booking_finance_cancellations WHERE reference=?');$q->execute([$reference]);return $q->fetch(PDO::FETCH_ASSOC);
}
function booking_finance_available(PDO $db,string $account): int
{
    booking_finance_schema($db);
    $q=$db->prepare("SELECT c.amount-(SELECT COALESCE(SUM(a.amount),0) FROM booking_finance_allocations a WHERE a.credit_id=c.id AND a.state<>'restored') AS remaining FROM booking_finance_credits c WHERE c.account_id=?");$q->execute([$account]);$total=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $remaining){if((int)$remaining<0)throw new RuntimeException('Credit capacity needs staff review.');$total+=(int)$remaining;}return $total;
}
function booking_finance_unused_credits(PDO $db,string $account): array
{
    $q=$db->prepare("SELECT c.* FROM booking_finance_credits c WHERE c.account_id=? AND c.amount>(SELECT COALESCE(SUM(a.amount),0) FROM booking_finance_allocations a WHERE a.credit_id=c.id AND a.state<>'restored') ORDER BY c.created_at,c.id");$q->execute([$account]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function booking_finance_credit_sum(PDO $db,string $reference,string $phase,string $state='used'): int
{
    booking_finance_schema($db);$q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM booking_finance_allocations WHERE reference=? AND phase=? AND state=?');$q->execute([$reference,$phase,$state]);return (int)$q->fetchColumn();
}
/** Read the real source deposit, including its current refund/dispute evidence. */
function booking_finance_source(PDO $db,array $credit,callable $api): array
{
    if(booking_finance_owner($db,$credit['source_reference'])!==$credit['account_id'])throw new RuntimeException('Credit ownership needs review.');
    $row=booking_get($db,$credit['source_reference']);
    $record=portal_payment_evidence($row,booking_finance_cash($row),(string)$row['stripe_session_id'],(string)$row['stripe_payment_intent_id'],(string)$row['stripe_customer_id'],$api);
    if($record['refunded']||$record['disputed']||(int)$credit['amount']!==$record['amount'])throw new RuntimeException('Credit source has a payment adjustment.');
    return $row;
}
/** All source reads precede BEGIN IMMEDIATE; availability/ownership are rechecked under it. */
function booking_finance_reserve(PDO $db,string $account,string $reference,callable $api): void
{
    booking_finance_schema($db);
    if(!booking_test_enabled()||!portal_owns_order($db,$account,$reference))throw new InvalidArgumentException('This order is unavailable.');
    if(booking_finance_funding($db,$reference))return;
    $row=booking_get($db,$reference);
    if($row['deposit_paid_at']||!in_array($row['checkout_state'],['ready','expired'],true))return; // Never change a prepared Stripe amount.
    if(booking_finance_available($db,$account)===0)return;
    $credits=booking_finance_unused_credits($db,$account);$sources=[];
    foreach($credits as $c)$sources[$c['id']]=booking_finance_source($db,$c,$api);
    if(!$credits)return;
    $db->exec('BEGIN IMMEDIATE');
    try{
        $row=booking_get($db,$reference);
        booking_finance_capability($db,$account,$reference);
        if(booking_finance_funding($db,$reference)||$row['deposit_paid_at']||!in_array($row['checkout_state'],['ready','expired'],true)){$db->exec('COMMIT');return;}
        $deposit=(int)$row['deposit_cents'];$total=(int)($row['approved_cents']??$row['quote_cents']);$available=booking_finance_available($db,$account);
        $use=min($deposit,$available);
        // Stripe cannot charge 1–49 cents. Keep that credit available instead of writing off cash.
        if($deposit-$use>0&&$deposit-$use<50)$use=max(0,$deposit-50);
        $needs=['deposit'=>$use,'balance'=>min(max(0,$total-$deposit),max(0,$available-$use))];$first=null;
        if($use===0&&$needs['balance']===0){$db->exec('COMMIT');return;}
        foreach($credits as $c){
            $s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM booking_finance_allocations WHERE credit_id=? AND state<>'restored'");$s->execute([$c['id']]);$left=(int)$c['amount']-(int)$s->fetchColumn();
            foreach($needs as $phase=>$need){$take=min($left,$need);if($take<=0)continue;$first??=$sources[$c['id']];
                $db->prepare("INSERT INTO booking_finance_allocations VALUES (?,?,?,?,?,'reserved',?,NULL)")->execute([bin2hex(random_bytes(16)),$c['id'],$reference,$phase,$take,time()]);$needs[$phase]-=$take;$left-=$take;}
        }
        if($needs['deposit']!==0||!$first)throw new RuntimeException('Credit allocation changed.');
        $db->prepare('INSERT INTO booking_finance_funding VALUES (?,?,?,?,?,?,?,?)')->execute([$reference,$account,$deposit-$use,$use,$first['reference'],$first['stripe_customer_id'],$first['stripe_payment_intent_id'],time()]);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Validate credit allocations with real source payments; never invent a Stripe payment. */
function booking_finance_verify_credits(PDO $db,string $reference,callable $api,string $phase='deposit',bool $reserved=false): int
{
    $owner=booking_finance_owner($db,$reference);$q=$db->prepare("SELECT a.*,c.account_id,c.source_reference,c.amount AS grant_amount FROM booking_finance_allocations a JOIN booking_finance_credits c ON c.id=a.credit_id WHERE a.reference=? AND a.phase=? AND a.state IN ('used'".($reserved?",'reserved'":'').")");$q->execute([$reference,$phase]);$sum=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $a){
        if(!$owner||$a['account_id']!==$owner)throw new RuntimeException('Credit ownership differs.');
        booking_finance_source($db,['account_id'=>$owner,'source_reference'=>$a['source_reference'],'amount'=>$a['grant_amount']],$api);$sum+=(int)$a['amount'];
    }
    return $sum;
}
function booking_finance_deposit_evidence(PDO $db,array $row,callable $api): array
{
    $cash=booking_finance_cash($row);$credit=booking_finance_verify_credits($db,$row['reference'],$api);
    $restored=booking_finance_plan($db,$row['reference'])?booking_finance_credit_sum($db,$row['reference'],'deposit','restored'):0;
    $credit+=$restored;
    portal_billing_need($cash+$credit===(int)$row['deposit_cents']);
    if($cash>0)return portal_payment_evidence($row,$cash,(string)$row['stripe_session_id'],(string)$row['stripe_payment_intent_id'],(string)$row['stripe_customer_id'],$api)+['credit'=>$credit,'credit_restored'=>$restored];
    $f=booking_finance_funding($db,$row['reference']);portal_billing_need((bool)$f&&$row['stripe_customer_id']===$f['customer']&&!$row['stripe_payment_intent_id']&&!$row['stripe_session_id']);
    return ['kind'=>'deposit','amount'=>0,'credit'=>$credit,'credit_restored'=>$restored,'refunded'=>0,'disputed'=>false,'receipt'=>null,'invoice'=>null,'pdf'=>null];
}
function booking_finance_settle_deposit(PDO $db,array $row): void
{
    $f=booking_finance_funding($db,$row['reference']);if(!$f)return;
    if(booking_finance_owner($db,$row['reference'])!==$f['account_id'])throw new RuntimeException('Credit owner changed.');
    if(booking_finance_credit_sum($db,$row['reference'],'deposit','reserved')+booking_finance_credit_sum($db,$row['reference'],'deposit')!==(int)$f['deposit_credit'])throw new RuntimeException('Deposit credit allocation differs.');
    $db->prepare("UPDATE booking_finance_allocations SET state='used' WHERE reference=? AND phase='deposit' AND state='reserved'")->execute([$row['reference']]);
}
/** Fresh authenticated consent settles a fully credited deposit without provider writes. */
function booking_finance_credit_checkout(PDO $db,string $account,string $reference,string $ip,callable $api): bool
{
    $row=booking_get($db,$reference);if(booking_finance_cash($row)!==0)return false;
    booking_finance_verify_credits($db,$reference,$api,'deposit',true);
    $db->exec('BEGIN IMMEDIATE');
    try{
        $row=booking_get($db,$reference);$f=booking_finance_funding($db,$reference);
        if(!$f||$f['account_id']!==$account||!portal_owns_order($db,$account,$reference)||booking_finance_cash($row)!==0||!in_array($row['checkout_state'],['ready','expired','paid'],true))throw new RuntimeException('Credit payment changed.');
        booking_finance_capability($db,$account,$reference);
        booking_finance_settle_deposit($db,$row);
        $db->prepare("UPDATE bookings SET status='deposit_paid_test',checkout_state='paid',stripe_customer_id=?,deposit_paid_at=COALESCE(deposit_paid_at,?),consent_at=?,consent_version=?,consent_ip_hash=? WHERE reference=? AND stripe_session_id IS NULL AND stripe_payment_intent_id IS NULL")
            ->execute([$f['customer'],gmdate('c'),gmdate('c'),BOOKING_CONSENT_VERSION,hash('sha256',$ip),$reference]);
        $db->exec('COMMIT');return true;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Balance preview preserves 50-cent minimum and never changes a reservation on GET. */
function booking_finance_balance_credit(PDO $db,string $reference,int $due): int
{
    $owner=booking_finance_owner($db,$reference);
    $held=booking_finance_credit_sum($db,$reference,'balance','reserved');
    $open=false;
    if($db->query("SELECT 1 FROM sqlite_master WHERE name='portal_balance_attempts'")->fetchColumn()){
        $q=$db->prepare("SELECT 1 FROM portal_balance_attempts WHERE reference=? AND state IN ('creating','open') AND paid_at IS NULL");$q->execute([$reference]);$open=(bool)$q->fetchColumn();
    }
    $replacement=(bool)booking_finance_funding($db,$reference);
    $use=min(max(0,$due),$held+($owner&&!$open&&$replacement?booking_finance_available($db,$owner):0));
    if($due-$use>0&&$due-$use<50)$use=max(0,$due-50);
    return $use;
}
/** A fresh approved scope may use further wallet credit. Prepared cash requests never change. */
function booking_finance_prepare_balance(PDO $db,string $reference,int $due,callable $api,?callable $guard=null): void
{
    $owner=booking_finance_owner($db,$reference);if(!$owner)return;
    $want=booking_finance_balance_credit($db,$reference,$due);
    $held=booking_finance_credit_sum($db,$reference,'balance','reserved');
    if($want<=$held)return;
    $credits=booking_finance_unused_credits($db,$owner);
    foreach($credits as $c)booking_finance_source($db,$c,$api);
    $db->exec('BEGIN IMMEDIATE');
    try{
        if(!portal_owns_order($db,$owner,$reference)||booking_finance_plan($db,$reference)||booking_lifecycle_pending($db,$reference)||booking_job_get($db,$reference))throw new RuntimeException('Balance scope changed.');
        if($guard!==null)$guard();
        $q=$db->prepare("SELECT 1 FROM portal_balance_attempts WHERE reference=? AND state IN ('creating','open') AND paid_at IS NULL");$q->execute([$reference]);
        if($q->fetchColumn()){$db->exec('COMMIT');return;}
        $need=max(0,booking_finance_balance_credit($db,$reference,$due)-booking_finance_credit_sum($db,$reference,'balance','reserved'));
        foreach($credits as $c){
            $q=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM booking_finance_allocations WHERE credit_id=? AND state<>'restored'");$q->execute([$c['id']]);$take=min($need,(int)$c['amount']-(int)$q->fetchColumn());
            if($take<=0)continue;
            $db->prepare("INSERT INTO booking_finance_allocations VALUES (?,?,?,?,?,'reserved',?,NULL)")->execute([bin2hex(random_bytes(16)),$c['id'],$reference,'balance',$take,time()]);$need-=$take;
        }
        if($need!==0)throw new RuntimeException('Credit changed while preparing the balance.');
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Exact unpaid provider expiry retires this credit checkout; no aged/ambiguous release. */
function booking_finance_expire(PDO $db,array $row,bool $locked=false): void
{
    if(!$locked){$db->exec('BEGIN IMMEDIATE');try{booking_finance_expire($db,$row,true);$db->exec('COMMIT');}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}return;}
    if(!booking_finance_funding($db,$row['reference'])||$row['deposit_paid_at'])return;
    $q=$db->prepare("UPDATE bookings SET status='expired_credit_test',checkout_state='expired',stripe_checkout_url=NULL WHERE reference=? AND deposit_paid_at IS NULL AND status IN ('awaiting_deposit_test','approved_test')");$q->execute([$row['reference']]);
    if($q->rowCount()===1)$db->prepare("UPDATE booking_finance_allocations SET state='restored',restored_at=? WHERE reference=? AND state='reserved'")->execute([time(),$row['reference']]);
}
function booking_finance_bill_paid(PDO $db,array $row,array $draft): int
{
    $paid=portal_balance_paid($db,$row['reference']);
    $total=(int)$row['approved_cents']+(int)$row['rush_fee_cents']+array_sum(array_column(json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR),'cents'));
    return $paid+booking_finance_balance_credit($db,$row['reference'],$total-(int)$row['deposit_cents']-$paid);
}
/** Called within the final payment/closeout transaction, after source verification. */
function booking_finance_use_balance(PDO $db,string $reference,int $amount): void
{
    $q=$db->prepare("SELECT * FROM booking_finance_allocations WHERE reference=? AND phase='balance' AND state='reserved' ORDER BY created_at,id");$q->execute([$reference]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $a){$take=min($amount,(int)$a['amount']);
        if($take===(int)$a['amount'])$db->prepare("UPDATE booking_finance_allocations SET state='used' WHERE id=? AND state='reserved'")->execute([$a['id']]);
        else{
            $db->prepare("UPDATE booking_finance_allocations SET state='restored',restored_at=? WHERE id=? AND state='reserved'")->execute([time(),$a['id']]);
            if($take>0)$db->prepare("INSERT INTO booking_finance_allocations VALUES (?,?,?,?,?,'used',?,NULL)")->execute([bin2hex(random_bytes(16)),$a['credit_id'],$reference,'balance',$take,time()]);
        }
        $amount-=$take;
    }
    if($amount!==0)throw new RuntimeException('Balance credit changed.');
}

/** Dedicated TEST-only refund transport. Refund objects themselves have no livemode field. */
function booking_finance_stripe(string $method,string $path,array $body=[],string $key=''): array
{
    if($method==='GET'&&!str_starts_with($path,'/refunds'))return portal_stripe($method,$path,$body,$key);
    if(!(($method==='GET'&&preg_match('~^/refunds(?:/re_[A-Za-z0-9_]+|\?charge=ch_[A-Za-z0-9_]+&limit=100)$~D',$path))||($method==='POST'&&$path==='/refunds'&&preg_match('/^sitesee-refund-test-[a-f0-9]{64}$/D',$key))))throw new RuntimeException('Invalid refund request.');
    if(!booking_test_enabled())throw new RuntimeException('TEST refunds disabled.');$secret=booking_test_key();
    $curl=curl_init('https://api.stripe.com/v1'.$path);if($curl===false)throw new RuntimeException('Refund connection unavailable.');$raw='';
    $headers=['Authorization: Bearer '.$secret,'Stripe-Version: '.BOOKING_CHECKOUT_API_VERSION];
    if($method==='POST'){$headers[]='Idempotency-Key: '.$key;$headers[]='Content-Type: application/x-www-form-urlencoded';curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($body,'','&',PHP_QUERY_RFC3986));}
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($c,string $part)use(&$raw):int{if(strlen($raw)+strlen($part)>1048576)return 0;$raw.=$part;return strlen($part);}]);
    $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($ok===false||$status!==200)throw new RuntimeException('Refund result needs verification.');$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($r))throw new RuntimeException('Refund response differs.');return $r;
}
function booking_finance_refund_valid(array $r,array $op): void
{
    $body=json_decode($op['request_json'],true,16,JSON_THROW_ON_ERROR);
    portal_billing_need((bool)preg_match('/^re_[A-Za-z0-9_]+$/D',(string)($r['id']??''))&&(!$op['refund_id']||$op['refund_id']===$r['id'])&&($r['charge']??'')===$op['charge']&&($r['payment_intent']??'')===$op['payment_intent']&&($r['amount']??null)===(int)$op['amount']&&($r['currency']??'')==='usd'&&($r['metadata']['sitesee_operation']??'')===$op['id']&&($r['metadata']['booking_reference']??'')===$body['metadata[booking_reference]']&&in_array($r['status']??'',['pending','requires_action','succeeded','failed','canceled'],true));
}
/** Never create a new key. Aged unknown requests require exact list readback. */
function booking_finance_refund_run(PDO $db,array $op,callable $api,int $now): void
{
    $r=null;
    if($op['refund_id'])$r=$api('GET','/refunds/'.$op['refund_id']);
    else{
        $list=$api('GET','/refunds?charge='.$op['charge'].'&limit=100');portal_billing_need(($list['has_more']??null)===false&&is_array($list['data']??null));
        $matches=array_values(array_filter($list['data'],static fn($x)=>($x['metadata']['sitesee_operation']??'')===$op['id']));
        if(count($matches)>1)throw new RuntimeException('Duplicate refund evidence needs review.');
        if(count($matches)===1)$r=$matches[0];
        else{
            if($op['submitted_at']!==null&&$now-(int)$op['submitted_at']>=23*3600)throw new RuntimeException('Aged unknown refund needs staff review; no new request sent.');
            // An adjustment that is not our exact refund is never overwritten or netted away.
            $charge=$api('GET','/charges/'.$op['charge']);
            portal_billing_need(($charge['id']??'')===$op['charge']&&($charge['livemode']??null)===false&&($charge['payment_intent']??'')===$op['payment_intent']&&($charge['disputed']??null)===false&&($charge['amount_refunded']??null)===0&&($charge['amount']??null)===(int)$op['amount']);
            $db->prepare('UPDATE booking_finance_refunds SET submitted_at=COALESCE(submitted_at,?) WHERE id=?')->execute([$now,$op['id']]);
            $r=$api('POST','/refunds',json_decode($op['request_json'],true,16,JSON_THROW_ON_ERROR),'sitesee-refund-test-'.$op['id']);
        }
    }
    booking_finance_refund_valid($r,$op);
    if($r['status']==='succeeded'){
        $charge=$api('GET','/charges/'.$op['charge']);portal_billing_need(($charge['id']??'')===$op['charge']&&($charge['livemode']??null)===false&&($charge['payment_intent']??'')===$op['payment_intent']&&($charge['amount_refunded']??null)===(int)$op['amount']&&($charge['disputed']??null)===false);
    }
    $db->prepare('UPDATE booking_finance_refunds SET refund_id=?,state=?,checked_at=?,diagnostic=NULL WHERE id=?')->execute([$r['id'],$r['status'],$now,$op['id']]);
}

/** Process only new applied cancellations. Old/manual cancellations are not backfilled. */
function booking_finance_process(PDO $db,string $reference,?callable $api=null,?int $now=null): void
{
    $plan=booking_finance_plan($db,$reference);if(!$plan||!booking_test_enabled())return;
    $api??='booking_finance_stripe';$now??=time();
    try{
        require_once __DIR__.'/portal-billing.php';portal_billing_schema($db);
        $q=$db->prepare("SELECT * FROM booking_lifecycle_operations WHERE operation_id=? AND reference=? AND action='cancel' AND state='applied'");$q->execute([$plan['operation_id'],$reference]);if(!$q->fetch())throw new RuntimeException('Applied cancellation evidence differs.');
        $q=$db->prepare("SELECT 1 FROM booking_lifecycle_operations WHERE reference=? AND action='cancel' AND state='applied' AND operation_id<>?");$q->execute([$reference,$plan['operation_id']]);if($q->fetchColumn())throw new RuntimeException('Repeated cancellation requires financial review.');
        if(booking_job_get($db,$reference))throw new RuntimeException('Completed onsite work needs staff review.');
        $row=booking_get($db,$reference);$owner=booking_finance_owner($db,$reference);
        if($plan['account_id']!==null&&$owner!==$plan['account_id'])throw new RuntimeException('Cancellation owner changed.');
        $cash=booking_finance_cash($row);$payments=[];
        if($cash>0&&$row['deposit_paid_at'])$payments[]=portal_payment_evidence($row,$cash,(string)$row['stripe_session_id'],(string)$row['stripe_payment_intent_id'],(string)$row['stripe_customer_id'],$api);
        $q=$db->prepare('SELECT * FROM portal_balance_attempts WHERE reference=? AND paid_at IS NOT NULL ORDER BY attempt');$q->execute([$reference]);$balances=$q->fetchAll(PDO::FETCH_ASSOC);
        if($plan['actor']==='staff')foreach($balances as $b)$payments[]=portal_payment_evidence($row,(int)$b['amount'],$b['session_id'],$b['payment_intent'],$b['customer'],$api,'balance',(int)$b['attempt']);
        $review=$plan['actor']==='customer'&&count($balances)>0;
        $db->exec('BEGIN IMMEDIATE');
        try{
            // Return only this replacement's credit allocations, never mint their value again.
            $db->prepare("UPDATE booking_finance_allocations SET state='restored',restored_at=? WHERE reference=? AND state<>'restored'")->execute([$now,$reference]);
            foreach($payments as $p){
                if($p['disputed'])throw new RuntimeException('Disputed payment requires staff review.');
                if($p['kind']==='balance'&&$plan['actor']==='customer')continue;
                if($p['kind']==='deposit'&&$plan['rule']==='customer_credit'){
                    if($p['refunded']>0||!$owner)throw new RuntimeException('Credit source or verified account needs staff review.');
                    $db->prepare('INSERT OR IGNORE INTO booking_finance_credits VALUES (?,?,?,?,?,?)')->execute([hash('sha256','credit:'.$plan['operation_id']),$plan['operation_id'],$reference,$owner,$p['amount'],$now]);continue;
                }
                $id=hash('sha256',$plan['operation_id'].':'.$p['payment_intent']);$q=$db->prepare('SELECT * FROM booking_finance_refunds WHERE id=?');$q->execute([$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);
                if(!$existing){
                    if($p['refunded']>0)throw new RuntimeException('Existing refund requires staff review; no duplicate refund sent.');
                    $body=['charge'=>$p['charge'],'amount'=>(string)$p['amount'],'metadata[sitesee_operation]'=>$id,'metadata[booking_reference]'=>$reference,'metadata[cancellation_operation]'=>$plan['operation_id']];
                    if($plan['actor']==='customer')$body['reason']='requested_by_customer';
                    $db->prepare('INSERT INTO booking_finance_refunds(id,operation_id,payment_intent,charge,amount,request_json,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$id,$plan['operation_id'],$p['payment_intent'],$p['charge'],$p['amount'],json_encode($body,JSON_THROW_ON_ERROR),$now]);
                }
            }
            $db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        $q=$db->prepare('SELECT * FROM booking_finance_refunds WHERE operation_id=?');$q->execute([$plan['operation_id']]);$pending=false;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $refund){
            try{booking_finance_refund_run($db,$refund,$api,$now);}catch(Throwable){$db->prepare("UPDATE booking_finance_refunds SET diagnostic='Refund evidence needs staff review; original request preserved.',checked_at=? WHERE id=?")->execute([$now,$refund['id']]);$pending=true;}
            $s=$db->prepare('SELECT state FROM booking_finance_refunds WHERE id=?');$s->execute([$refund['id']]);$refundState=$s->fetchColumn();$pending=$pending||$refundState!=='succeeded';$review=$review||in_array($refundState,['failed','canceled','requires_action'],true);
        }
        // Signed payments can commit while Stripe readback is in progress. Complete only
        // against the current ledger, under the same write lock as the final state update.
        $db->exec('BEGIN IMMEDIATE');
        try{
            $q=$db->prepare("SELECT COUNT(*) FROM booking_lifecycle_operations WHERE reference=? AND action='cancel' AND state='applied' AND operation_id<>?");$q->execute([$reference,$plan['operation_id']]);
            if((int)$q->fetchColumn()!==0||booking_finance_owner($db,$reference)!==$owner||booking_job_get($db,$reference))throw new RuntimeException('Cancellation identity changed during financial verification.');
            $q=$db->prepare('SELECT * FROM portal_balance_attempts WHERE reference=? AND paid_at IS NOT NULL ORDER BY attempt');$q->execute([$reference]);$currentBalances=$q->fetchAll(PDO::FETCH_ASSOC);
            if($plan['actor']==='customer')$review=$review||count($currentBalances)>0;
            else $pending=$pending||$currentBalances!==$balances;
            $q=$db->prepare('SELECT state FROM booking_finance_refunds WHERE operation_id=?');$q->execute([$plan['operation_id']]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $state){$pending=$pending||$state!=='succeeded';$review=$review||in_array($state,['failed','canceled','requires_action'],true);}
            // An open balance checkout can settle later; retain this plan for its signed evidence.
            $q=$db->prepare("SELECT 1 FROM portal_balance_attempts WHERE reference=? AND paid_at IS NULL AND state IN ('creating','open')");$q->execute([$reference]);$pending=$pending||(bool)$q->fetchColumn();
            $db->prepare('UPDATE booking_finance_cancellations SET state=?,checked_at=?,diagnostic=? WHERE operation_id=?')->execute([$review?'review':($pending?'pending':'completed'),$now,$review?'A prepaid payment or refund requires staff review.':($pending?'Financial verification pending.':null),$plan['operation_id']]);
            $db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }catch(Throwable){$db->prepare("UPDATE booking_finance_cancellations SET state='review',checked_at=?,diagnostic='Financial evidence requires staff review. Existing payments and requests preserved.' WHERE operation_id=?")->execute([$now,$plan['operation_id']]);}
}

/** Private credential bridge for the existing account-owned CLI worker, never logs/arguments. */
function booking_finance_worker_key(bool $save=false): void
{
    $path=dirname(__DIR__).'/booking-finance-key.json';$st=@lstat($path);
    if($st!==false){
        if(($st['mode']&0170000)!==0100000||($st['mode']&0077)!==0||$st['nlink']!==1||(function_exists('posix_geteuid')&&$st['uid']!==posix_geteuid())||$st['size']>2048)throw new RuntimeException('Financial worker key permissions need review.');
        $v=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(($v['stage']??'')!=='TEST'||!preg_match('/^sk_test_[A-Za-z0-9_]{12,}$/D',(string)($v['secret']??'')))throw new RuntimeException('Financial worker requires TEST credentials.');
        $env=getenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET');if($env!==false&&!hash_equals($env,$v['secret']))throw new RuntimeException('Financial worker key differs; review rotation.');
        if($env===false&&PHP_SAPI==='cli')putenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET='.$v['secret']);return;
    }
    if(!$save)return;
    $key=booking_test_key();$old=umask(0077);try{$file=@fopen($path,'x');}finally{umask($old);}
    if($file===false)throw new RuntimeException('Financial worker key could not be saved safely.');
    try{if(fwrite($file,json_encode(['stage'=>'TEST','secret'=>$key],JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Financial worker key write failed.');}finally{fclose($file);}
}
function booking_finance_status(PDO $db,string $reference): array
{
    $plan=booking_finance_plan($db,$reference);if(!$plan)return [];
    $q=$db->prepare('SELECT amount,state FROM booking_finance_refunds WHERE operation_id=?');$q->execute([$plan['operation_id']]);$refunded=0;$pending=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){if($r['state']==='succeeded')$refunded+=(int)$r['amount'];else $pending+=(int)$r['amount'];}
    $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM booking_finance_credits WHERE operation_id=?');$q->execute([$plan['operation_id']]);
    return ['state'=>$plan['state'],'rule'=>$plan['rule'],'refunded'=>$refunded,'refund_pending'=>$pending,'credit'=>(int)$q->fetchColumn(),'review'=>$plan['diagnostic']];
}

function booking_finance_status_html(PDO $db,string $reference): string
{
    $s=booking_finance_status($db,$reference);if(!$s)return '';
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<section class="panel"><h2>Cancellation Billing</h2><p>'. $e(ucfirst($s['state'])) .'</p><dl><dt>Refund confirmed</dt><dd>'.real_estate_money($s['refunded']).'</dd><dt>Refund awaiting verification</dt><dd>'.real_estate_money($s['refund_pending']).'</dd><dt>Deposit credit issued</dt><dd>'.real_estate_money($s['credit']).'</dd></dl>';
    if($s['review'])$html.='<p class="notice">'.$e($s['review']).'</p>';
    return $html.'<p class="help">A confirmed refund is returned to the original payment method. Bank processing times vary. Credit is applied automatically to a replacement booking; unused credit stays on your account.</p></section>';
}
