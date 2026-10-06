<?php
declare(strict_types=1);
require_once __DIR__.'/booking-job-catalog.php';

/** Separate onsite and production ledger. Original bookings and payment history are immutable. */
function booking_job_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS booking_job_extras (reference TEXT PRIMARY KEY, revision INTEGER NOT NULL,
        lines_json TEXT NOT NULL, scope TEXT NOT NULL, approved_by TEXT, approved_at TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_jobs (reference TEXT PRIMARY KEY, scope TEXT NOT NULL,
        bill_json TEXT NOT NULL, amount INTEGER NOT NULL, customer TEXT NOT NULL, completed_at TEXT NOT NULL,
        payment_state TEXT NOT NULL DEFAULT 'ready', request_json TEXT, request_at INTEGER,
        payment_intent TEXT UNIQUE, confirm_at INTEGER, recovery_at INTEGER, paid_at TEXT,
        receipt_url TEXT, refunded INTEGER NOT NULL DEFAULT 0, disputed INTEGER NOT NULL DEFAULT 0,
        checked_at INTEGER, production_revision INTEGER NOT NULL DEFAULT 0, links_json TEXT NOT NULL DEFAULT '{}',
        published_json TEXT, production_complete_at TEXT, published_revision INTEGER)");
}
function booking_job_get(PDO $db,string $reference): array|false
{
    booking_job_schema($db);$q=$db->prepare('SELECT * FROM booking_jobs WHERE reference=?');$q->execute([$reference]);return $q->fetch(PDO::FETCH_ASSOC);
}
function booking_job_extras(PDO $db,string $reference): array
{
    booking_job_schema($db);$q=$db->prepare('SELECT * FROM booking_job_extras WHERE reference=?');$q->execute([$reference]);
    return $q->fetch(PDO::FETCH_ASSOC)?:['reference'=>$reference,'revision'=>0,'lines_json'=>'[]','scope'=>hash('sha256',$reference.':0:[]'),'approved_by'=>null,'approved_at'=>null];
}
function booking_job_guard(PDO $db,string $reference): array
{
    if(!booking_test_enabled())throw new InvalidArgumentException('TEST booking access is required.');
    $row=booking_lifecycle_row($db,$reference);
    if(!$row['deposit_paid_at']||!$row['approved_at']||$row['status']!=='deposit_paid_test'||$row['reschedule_required']||$row['rush_status']==='pending'
        ||booking_lifecycle_state($db,$reference)['state']!=='active'||booking_lifecycle_pending($db,$reference))throw new InvalidArgumentException('Resolve the booking review or appointment change before closing this job.');
    return $row;
}
function booking_job_cents(string $value): int
{
    if(!preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/D',$value))throw new InvalidArgumentException('Enter a valid service price.');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');$cents=(int)$whole*100+(int)str_pad($fraction,2,'0');
    if($cents<50||$cents>10000000)throw new InvalidArgumentException('Additional service prices must be between $0.50 and $100,000.');return $cents;
}
function booking_job_save_extras(PDO $db,string $reference,string $scope,string $action,string $label='',string $price='',string $remove=''): void
{
    booking_job_schema($db);$db->exec('BEGIN IMMEDIATE');
    try{
        booking_job_guard($db,$reference);if(booking_job_get($db,$reference))throw new InvalidArgumentException('The final bill is already fixed. Contact billing for adjustments.');
        $draft=booking_job_extras($db,$reference);if(!hash_equals($draft['scope'],$scope))throw new InvalidArgumentException('Additional services changed. Refresh and review them.');
        $lines=json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR);
        if($action==='add'){
            $label=trim($label);if($label===''||strlen($label)>160||preg_match('/[\x00-\x1f\x7f]/',$label)||count($lines)>=20)throw new InvalidArgumentException('Use a service description of 1–160 characters, with at most 20 services.');
            $lines[]=['id'=>bin2hex(random_bytes(8)),'label'=>$label,'cents'=>booking_job_cents($price)];
        }elseif($action==='remove'){
            $found=false;$lines=array_values(array_filter($lines,static function($line)use($remove,&$found){if($line['id']===$remove){$found=true;return false;}return true;}));
            if(!$found)throw new InvalidArgumentException('That service has already changed.');
        }else throw new InvalidArgumentException('Unknown service action.');
        if(array_sum(array_column($lines,'cents'))>10000000)throw new InvalidArgumentException('This additional scope needs manual billing review.');
        $json=json_encode($lines,JSON_THROW_ON_ERROR);$revision=(int)$draft['revision']+1;$next=hash('sha256',$reference.':'.$revision.':'.$json);
        $db->prepare('INSERT INTO booking_job_extras(reference,revision,lines_json,scope) VALUES (?,?,?,?) ON CONFLICT(reference) DO UPDATE SET revision=excluded.revision,lines_json=excluded.lines_json,scope=excluded.scope,approved_by=NULL,approved_at=NULL')->execute([$reference,$revision,$json,$next]);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function booking_job_approve_extras(PDO $db,string $account,string $reference,string $scope,bool $agreed): void
{
    portal_service_owned($db,$account,$reference);if(!$agreed)throw new InvalidArgumentException('Approve the displayed additional services before continuing.');
    booking_job_schema($db);$db->exec('BEGIN IMMEDIATE');
    try{
        portal_service_owned($db,$account,$reference);booking_job_guard($db,$reference);
        if(booking_job_get($db,$reference))throw new InvalidArgumentException('The final bill is already fixed.');
        $draft=booking_job_extras($db,$reference);
        if(!hash_equals($draft['scope'],$scope)||$draft['lines_json']==='[]')throw new InvalidArgumentException('Additional services changed. Review the current amount.');
        $db->prepare('UPDATE booking_job_extras SET approved_by=?,approved_at=? WHERE reference=? AND scope=?')->execute([$account,gmdate('c'),$reference,$scope]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function booking_job_bill(PDO $db,string $reference): array
{
    return booking_job_bill_from(booking_job_guard($db,$reference),booking_job_extras($db,$reference),portal_balance_paid($db,$reference));
}
function booking_job_bill_from(array $row,array $draft,int $paid): array
{
    $reference=$row['reference'];$lines=json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR);
    $total=(int)$row['approved_cents']+(int)$row['rush_fee_cents']+array_sum(array_column($lines,'cents'));
    $bill=['reference'=>$reference,'approved_cents'=>(int)$row['approved_cents'],'rush_cents'=>(int)$row['rush_fee_cents'],
        'deposit_cents'=>(int)$row['deposit_cents'],'prior_balance_cents'=>$paid,'extras'=>$lines,'extras_scope'=>$draft['scope'],
        'extras_approved_by'=>$draft['approved_by'],'extras_approved_at'=>$draft['approved_at'],'total_cents'=>$total,
        'due_cents'=>$total-(int)$row['deposit_cents']-$paid,'approved_at'=>$row['approved_at']];
    if($bill['due_cents']<0||$bill['due_cents']>99999999||($bill['due_cents']>0&&$bill['due_cents']<50))throw new InvalidArgumentException('This balance needs manual billing review.');
    $bill+=booking_job_commission($lines);
    $bill['scope']=hash('sha256',json_encode($bill,JSON_THROW_ON_ERROR));return $bill;
}
/** This transaction blocks legacy balance checkout and appointment changes before any provider call. */
function booking_job_complete(PDO $db,string $reference,string $scope,bool $agreed,?callable $api=null,?array $onsite=null,?callable $authorize=null): array
{
    if(!$agreed)throw new InvalidArgumentException('Confirm the onsite work and displayed final bill.');
    booking_job_schema($db);portal_billing_schema($db);$db->exec('BEGIN IMMEDIATE');
    try{
        $actor=$authorize===null?null:$authorize($reference);
        $job=booking_job_get($db,$reference);
        if($job){if(!hash_equals($job['scope'],$scope))throw new InvalidArgumentException('This job is already closed. Review its final bill.');$db->exec('COMMIT');return booking_job_collect($db,$reference,$api);}
        $preview=$onsite===null?null:booking_job_onsite_preview($db,$reference,$onsite['items'],$onsite['draft_scope']);
        $bill=$preview===null?booking_job_bill($db,$reference):$preview['bill'];
        if(!hash_equals($bill['scope'],$scope))throw new InvalidArgumentException('The final amount changed. Refresh and review it.');
        if($onsite===null&&$bill['extras']&&(!$bill['extras_approved_at']||!$bill['extras_approved_by']||!portal_owns_order($db,$bill['extras_approved_by'],$reference)))throw new InvalidArgumentException('The customer must approve the additional services in My Orders first.');
        $latest=portal_billing_latest($db,$reference);
        if($latest&&!$latest['paid_at']&&in_array($latest['state'],['open','creating'],true))throw new InvalidArgumentException('An existing customer balance payment is open. Resolve or verify its expiry before closing the job.');
        $row=booking_get($db,$reference);
        if($preview!==null){
            booking_job_write_draft($db,$preview['draft']);
            $bill['onsite_authorization']=['method'=>'staff_attested_verbal','photographer'=>$row['photographer'],
                'recorded_at'=>gmdate('c'),'extras_scope'=>$bill['extras_scope'],
                'statement'=>'I confirm the onsite work is finished, the agent verbally approved the additional services and displayed fees, and the final amount shown is correct.'];
            if($actor!==null){
                $bill['onsite_authorization']['method']='vendor_attested_verbal';
                $bill['onsite_authorization']['vendor']=$actor;
            }
        }
        $db->prepare('INSERT INTO booking_jobs(reference,scope,bill_json,amount,customer,completed_at) VALUES (?,?,?,?,?,?)')->execute([$reference,$scope,json_encode($bill,JSON_THROW_ON_ERROR),$bill['due_cents'],$row['stripe_customer_id'],gmdate('c')]);
        $db->exec('COMMIT');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();else {try{$db->exec('ROLLBACK');}catch(Throwable){}}throw $e;}
    // Collection failure never rolls back completed onsite work or the Production queue.
    return booking_job_collect($db,$reference,$api);
}
function booking_job_validate_intent(array $p,array $job): void
{
    portal_billing_need((bool)preg_match('/^pi_[A-Za-z0-9_]+$/D',(string)($p['id']??''))&&(!$job['payment_intent']||$job['payment_intent']===$p['id'])
        &&($p['livemode']??null)===false&&($p['customer']??'')===$job['customer']&&($p['currency']??'')==='usd'&&($p['amount']??null)===(int)$job['amount']
        &&($p['metadata']['booking_reference']??'')===$job['reference']&&($p['metadata']['portal_payment_kind']??'')==='job_closeout'&&($p['metadata']['job_scope']??'')===$job['scope']
        &&in_array($p['status']??'',['requires_payment_method','requires_confirmation','requires_action','processing','succeeded','canceled'],true));
}
function booking_job_verify_prior(PDO $db,array $job,callable $api,bool $allowAdjustments=false): bool
{
    $row=booking_get($db,$job['reference']);$bill=json_decode($job['bill_json'],true,16,JSON_THROW_ON_ERROR);
    $records=[portal_payment_evidence($row,(int)$row['deposit_cents'],$row['stripe_session_id'],$row['stripe_payment_intent_id'],$job['customer'],$api)];
    $q=$db->prepare('SELECT * FROM portal_balance_attempts WHERE reference=? AND paid_at IS NOT NULL');$q->execute([$job['reference']]);$prior=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $b){$prior+=(int)$b['amount'];$records[]=portal_payment_evidence($row,(int)$b['amount'],$b['session_id'],$b['payment_intent'],$b['customer'],$api,'balance',(int)$b['attempt']);}
    portal_billing_need($prior===$bill['prior_balance_cents']);
    $clear=true;
    foreach($records as $record)if($record['refunded']||$record['disputed']){
        if(!$allowAdjustments)throw new InvalidArgumentException('A payment adjustment needs billing review.');$clear=false;
    }
    return $clear;
}
/** GET-only verification; a browser redirect or Stripe status alone never releases deliverables. */
function booking_job_refresh(PDO $db,string $reference,?callable $api=null,bool $allowAdjustments=false): array
{
    $job=booking_job_get($db,$reference);if(!$job)throw new InvalidArgumentException('This job has not been closed.');$api??='booking_job_stripe';
    try{
        $priorClear=booking_job_verify_prior($db,$job,$api,$allowAdjustments);
        if((int)$job['amount']===0){$db->prepare('UPDATE booking_jobs SET payment_state=?,paid_at=COALESCE(paid_at,?),checked_at=? WHERE reference=?')->execute([$priorClear?'paid':'review',gmdate('c'),time(),$reference]);return booking_job_get($db,$reference);}
        if(!$job['payment_intent'])return $job;
        $p=$api('GET','/payment_intents/'.$job['payment_intent']);booking_job_validate_intent($p,$job);
        $state=match($p['status']){'succeeded'=>'verification_pending','processing'=>'processing','canceled'=>'review',default=>'needs_action'};$receipt=null;$refunded=0;$disputed=0;$paid=null;
        if($p['status']==='succeeded'){
            portal_billing_need(($p['amount_received']??null)===(int)$job['amount']&&(bool)preg_match('/^ch_[A-Za-z0-9_]+$/D',(string)($p['latest_charge']??'')));
            $c=$api('GET','/charges/'.$p['latest_charge']);
            portal_billing_need(($c['id']??'')===$p['latest_charge']&&($c['livemode']??null)===false&&($c['customer']??'')===$job['customer']&&($c['payment_intent']??'')===$p['id']
                &&($c['paid']??false)===true&&($c['captured']??false)===true&&($c['currency']??'')==='usd'&&($c['amount']??null)===(int)$job['amount']&&($c['amount_captured']??null)===(int)$job['amount']
                &&is_int($c['amount_refunded']??null)&&$c['amount_refunded']>=0&&$c['amount_refunded']<=(int)$job['amount']&&is_bool($c['disputed']??null));
            $refunded=$c['amount_refunded'];$disputed=$c['disputed']?1:0;$state=$refunded||$disputed||!$priorClear?'review':'paid';$paid=gmdate('c');
            if(!empty($c['receipt_url']))$receipt=portal_billing_url($c['receipt_url'],['pay.stripe.com']);
        }
        $db->prepare('UPDATE booking_jobs SET payment_state=?,receipt_url=?,refunded=?,disputed=?,paid_at=COALESCE(paid_at,?),checked_at=? WHERE reference=? AND payment_intent=?')->execute([$state,$receipt,$refunded,$disputed,$paid,time(),$reference,$p['id']]);
    }catch(Throwable $e){$db->prepare("UPDATE booking_jobs SET payment_state='verification_pending',checked_at=NULL WHERE reference=?")->execute([$reference]);throw $e;}
    return booking_job_get($db,$reference);
}
/** One fixed PaymentIntent, saved before confirmation; ambiguous creates retain the same request/key. */
function booking_job_collect(PDO $db,string $reference,?callable $api=null): array
{
    $api??='booking_job_stripe';$job=booking_job_get($db,$reference);if(!$job||!booking_test_enabled())throw new InvalidArgumentException('TEST job closeout is unavailable.');
    try{
        if((int)$job['amount']===0)return booking_job_refresh($db,$reference,$api);
        booking_job_verify_prior($db,$job,$api);
        if(!$job['request_json']){
            $body=['amount'=>(string)$job['amount'],'currency'=>'usd','customer'=>$job['customer'],'payment_method_types[0]'=>'card',
                'description'=>'SiteSee final job balance '.$reference,'metadata[booking_reference]'=>$reference,'metadata[portal_payment_kind]'=>'job_closeout','metadata[job_scope]'=>$job['scope']];
            $db->prepare("UPDATE booking_jobs SET request_json=?,request_at=?,payment_state='creating' WHERE reference=? AND request_json IS NULL")->execute([json_encode($body,JSON_THROW_ON_ERROR),time(),$reference]);$job=booking_job_get($db,$reference);
        }
        if(!$job['payment_intent']){
            if(time()-(int)$job['request_at']>23*3600){
                $list=$api('GET','/payment_intents?customer='.$job['customer'].'&limit=100');portal_billing_need(($list['has_more']??null)===false&&is_array($list['data']??null));
                $matches=array_values(array_filter($list['data'],static fn($p)=>($p['metadata']['job_scope']??'')===$job['scope']&&($p['metadata']['booking_reference']??'')===$reference));
                if(count($matches)!==1)throw new InvalidArgumentException('The earlier payment request needs billing review. Another charge was not created.');$p=$matches[0];
            }else $p=$api('POST','/payment_intents',json_decode($job['request_json'],true,16,JSON_THROW_ON_ERROR),'sitesee-job-create-test-'.$reference);
            booking_job_validate_intent($p,$job);
            $db->prepare('UPDATE booking_jobs SET payment_intent=? WHERE reference=? AND payment_intent IS NULL')->execute([$p['id'],$reference]);$job=booking_job_get($db,$reference);booking_job_validate_intent($p,$job);
        }
        $p=$api('GET','/payment_intents/'.$job['payment_intent']);booking_job_validate_intent($p,$job);
        if(in_array($p['status'],['requires_payment_method','requires_confirmation'],true)&&!$job['recovery_at']&&!$job['confirm_at']){
            $row=booking_get($db,$reference);
            if(!$row['consent_at']||$row['consent_version']!==BOOKING_CONSENT_VERSION)throw new InvalidArgumentException('Saved-card permission requires customer review.');
            $deposit=$api('GET','/payment_intents/'.$row['stripe_payment_intent_id']);$pm=(string)($deposit['payment_method']??'');
            portal_billing_need(($deposit['id']??'')===$row['stripe_payment_intent_id']&&($deposit['livemode']??null)===false&&($deposit['customer']??'')===$job['customer']&&($deposit['setup_future_usage']??'')==='off_session'&&(bool)preg_match('/^pm_[A-Za-z0-9_]+$/D',$pm));
            $method=$api('GET','/payment_methods/'.$pm);portal_billing_need(($method['id']??'')===$pm&&($method['livemode']??null)===false&&($method['customer']??'')===$job['customer']&&($method['type']??'')==='card');
            $claim=$db->prepare('UPDATE booking_jobs SET confirm_at=? WHERE reference=? AND recovery_at IS NULL AND confirm_at IS NULL');$claim->execute([time(),$reference]);
            if($claim->rowCount()===1){
                $p=$api('POST','/payment_intents/'.$job['payment_intent'].'/confirm',['off_session'=>'true','payment_method'=>$pm],'sitesee-job-confirm-test-'.$reference);booking_job_validate_intent($p,$job);
            }
        }
        return booking_job_refresh($db,$reference,$api);
    }catch(Throwable $e){
        $job=booking_job_get($db,$reference);
        if($job['payment_intent']){try{return booking_job_refresh($db,$reference,$api);}catch(Throwable){}}
        $db->prepare("UPDATE booking_jobs SET payment_state='needs_review' WHERE reference=? AND payment_state<>'paid'")->execute([$reference]);
        return booking_job_get($db,$reference);
    }
}
function booking_job_customer_payment(PDO $db,string $account,string $reference,string $scope,bool $agreed,?callable $api=null): array
{
    if(!booking_test_enabled())throw new InvalidArgumentException('TEST payments are unavailable.');
    portal_service_owned($db,$account,$reference);$job=booking_job_get($db,$reference);
    if(!$job||!hash_equals($job['scope'],$scope)||!$agreed)throw new InvalidArgumentException('Review and approve this final payment.');$api??='booking_job_stripe';
    if(!$job['payment_intent'])throw new InvalidArgumentException('SiteSee must recover the saved payment request first.');
    if($job['confirm_at']&&time()-(int)$job['confirm_at']<30)throw new InvalidArgumentException('Payment is being checked. Try again in a moment.');
    $job=booking_job_refresh($db,$reference,$api);if($job['payment_state']==='paid')return ['mode'=>'paid'];
    $p=$api('GET','/payment_intents/'.$job['payment_intent']);booking_job_validate_intent($p,$job);
    if(!in_array($p['status'],['requires_payment_method','requires_confirmation','requires_action'],true))return ['mode'=>'pending'];
    $secret=$p['client_secret']??null;portal_billing_need(is_string($secret)&&$secret!==''&&strlen($secret)<16384&&!preg_match('/[\x00-\x1f\x7f]/',$secret));
    $db->prepare('UPDATE booking_jobs SET recovery_at=COALESCE(recovery_at,?) WHERE reference=?')->execute([time(),$reference]);
    return ['mode'=>'payment','clientSecret'=>$secret];
}
/** The shared endpoint verifies the signature first. Provider reads remain the release authority. */
function booking_job_event(PDO $db,array $event): bool
{
    $p=$event['data']['object']??[];
    if(($p['metadata']['portal_payment_kind']??'')!=='job_closeout'||!str_starts_with((string)($event['type']??''),'payment_intent.'))return false;
    portal_billing_need(($event['livemode']??null)===false&&(bool)preg_match('/^evt_[A-Za-z0-9_]+$/D',(string)($event['id']??'')));
    $job=booking_job_get($db,(string)($p['metadata']['booking_reference']??''));portal_billing_need((bool)$job);booking_job_validate_intent($p,$job);
    $db->prepare("UPDATE booking_jobs SET payment_intent=COALESCE(payment_intent,?),payment_state=CASE WHEN paid_at IS NULL THEN 'verification_pending' ELSE payment_state END WHERE reference=? AND (payment_intent IS NULL OR payment_intent=?)")->execute([$p['id'],$job['reference'],$p['id']]);
    return true;
}
function booking_job_stripe(string $method,string $path,array $body=[],string $key=''): array
{
    if($method==='GET'&&!str_starts_with($path,'/payment_methods/'))return portal_stripe($method,$path,$body,$key);
    $allowed=($method==='GET'&&(bool)preg_match('~^/payment_methods/pm_[A-Za-z0-9_]+$~D',$path))
        ||($method==='POST'&&($path==='/payment_intents'||preg_match('~^/payment_intents/pi_[A-Za-z0-9_]+/confirm$~D',$path))&&preg_match('/^sitesee-job-(?:create|confirm)-test-[A-F0-9]{10,32}$/D',$key));
    if(!$allowed)throw new RuntimeException('Invalid final billing request.');
    $curl=curl_init('https://api.stripe.com/v1'.$path);if($curl===false)throw new RuntimeException('Billing unavailable.');$raw='';
    $headers=['Authorization: Bearer '.booking_test_key(),'Stripe-Version: '.BOOKING_CHECKOUT_API_VERSION];
    if($method==='POST'){$headers[]='Idempotency-Key: '.$key;$headers[]='Content-Type: application/x-www-form-urlencoded';curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($body,'','&',PHP_QUERY_RFC3986));}
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}]);
    try{$ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
    if($ok===false||!in_array($status,[200,402],true))throw new RuntimeException('Final billing needs recovery.');
    $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if($status===402&&is_array($r['error']['payment_intent']??null))return $r['error']['payment_intent'];
    if($status!==200||!is_array($r))throw new RuntimeException('Final billing needs recovery.');return $r;
}
function booking_job_labels(): array
{
    return ['website'=>'Independent Website','photos'=>'Photo Download','video'=>'Video Download','platform'=>'SiteSee Platform','floor'=>'Floor Plan Download'];
}
function booking_job_links(array $input): array
{
    $out=[];
    foreach(booking_job_labels() as $key=>$label){$v=$input[$key]??'';if(!is_string($v))throw new InvalidArgumentException('Invalid deliverable link.');$v=trim($v);if($v!=='')$out[$key]=['label'=>$label,'url'=>booking_job_url($v)];}
    for($i=0;$i<10;$i++){
        $label=$input['other_label_'.$i]??'';$url=$input['other_url_'.$i]??'';
        if(!is_string($label)||!is_string($url))throw new InvalidArgumentException('Invalid additional deliverable.');$label=trim($label);$url=trim($url);
        if($label===''&&$url==='')continue;
        if($label===''||strlen($label)>100||preg_match('/[\x00-\x1f\x7f]/',$label))throw new InvalidArgumentException('Name each additional deliverable.');
        $out['other_'.$i]=['label'=>$label,'url'=>booking_job_url($url)];
    }
    return $out;
}
function booking_job_url(string $url): string
{
    if(strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new InvalidArgumentException('Use a complete HTTPS deliverable link.');$p=parse_url($url);
    if(!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])||!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Use a complete HTTPS deliverable link.');return $url;
}
function booking_job_production(PDO $db,string $reference,int $revision,array $input,bool $complete,bool $agreed): void
{
    $links=booking_job_links($input);$db->exec('BEGIN IMMEDIATE');
    try{
        $job=booking_job_get($db,$reference);if(!$job||$revision!==(int)$job['production_revision'])throw new InvalidArgumentException('Production changed. Refresh before saving.');
        if($complete){
            if(!$agreed||!isset($links['photos']))throw new InvalidArgumentException('Add the photo download link and confirm all ordered deliverables are ready.');
            $row=booking_get($db,$reference);$bill=json_decode($job['bill_json'],true,16,JSON_THROW_ON_ERROR);
            $keys=array_merge(array_column(booking_request($row)['quote']['lines']??[],'key'),array_column($bill['extras']??[],'service'));
            foreach(['website','video','platform','floor'] as $key)if(in_array($key,$keys,true)&&!isset($links[$key]))throw new InvalidArgumentException('Add the ordered '.booking_job_labels()[$key].' link.');
        }
        $json=json_encode($links,JSON_THROW_ON_ERROR);
        $db->prepare('UPDATE booking_jobs SET links_json=?,production_revision=production_revision+1,published_json=?,production_complete_at=?,published_revision=? WHERE reference=? AND production_revision=?')->execute([$json,$complete?$json:$job['published_json'],$complete?gmdate('c'):$job['production_complete_at'],$complete?$revision+1:$job['published_revision'],$reference,$revision]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function booking_job_deliverables(PDO $db,string $account,string $reference,?callable $api=null): array
{
    portal_service_owned($db,$account,$reference);$job=booking_job_get($db,$reference);if(!$job||!$job['production_complete_at'])return [];
    $job=booking_job_refresh($db,$reference,$api);if($job['payment_state']!=='paid')return [];
    return json_decode($job['published_json']??'{}',true,16,JSON_THROW_ON_ERROR);
}
