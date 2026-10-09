<?php
declare(strict_types=1);
require_once __DIR__.'/portal-orders.php';
require_once __DIR__.'/booking-checkout.php';
require_once __DIR__.'/booking-feedback.php';

function portal_purchase_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS portal_submissions (
        id TEXT PRIMARY KEY, account_id TEXT NOT NULL, reference TEXT NOT NULL UNIQUE,
        payload_json TEXT NOT NULL, submission_json TEXT NOT NULL, submission_hash TEXT NOT NULL,
        created_at INTEGER NOT NULL, bound_at INTEGER,
        staff_notice TEXT NOT NULL DEFAULT 'pending', customer_notice TEXT NOT NULL DEFAULT 'pending'
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS portal_submission_account ON portal_submissions(account_id,created_at)');
}
function portal_purchase_input(array $payload, array $account): array
{
    // No client prices, account IDs, provider IDs or status values enter the booking.
    $p=['version'=>2,'action'=>'request_appointment','market'=>$payload['market']??'',
        'details'=>$payload['details']??[], 'appointment'=>$payload['appointment']??[], 'state'=>$payload['state']??[]];
    if(!is_array($p['details'])||!is_array($p['appointment'])||!is_array($p['state']))throw new InvalidArgumentException('Complete your order details.');
    foreach([$p['details'],$p['appointment'],$p['state']] as $fields)foreach($fields as $key=>$value){
        if($key==='selected' && is_array($value)){foreach($value as $v)if(!is_string($v))throw new InvalidArgumentException('Review your services.');}
        elseif(!is_scalar($value)&&$value!==null)throw new InvalidArgumentException('Review your order details.');
    }
    $p['details']=array_intersect_key($p['details'],array_flip(['first','last','company','phone','street','unit','propertyId','city','state','zip','optOut']));
    $p['details']['email']=$account['email'];
    $p['state']=array_intersect_key($p['state'],array_flip(['category','package','sqft','selected','matterportSqft','videoSeconds','images','photoCount','aerialImages','videos','plans','views360','platformMonths','hostingMonths','hostingPrepaid','delivery','licenseType','licenseMonths']));
    $p['appointment']=array_intersect_key($p['appointment'],array_flip(['date','time','rushRequested','meetPhotographer','accessType','lockboxCode','keyLocation','specialRequests','mustHaveShots','onsiteDifferent','onsiteName','onsiteEmail','onsitePhone','additionalDifferent','additionalName','additionalEmail','additionalPhone','cancellationAccepted']));
    return $p;
}
function portal_purchase_review(PDO $db, string $accountId, array $payload): array
{
    $account=portal_active_account($db,$accountId);
    if(!$account)throw new InvalidArgumentException('Please sign in again.');
    portal_require_profile($db,$accountId);
    $payload=portal_purchase_input($payload,$account);
    $submission=real_estate_prepare_submission($payload);
    $json=json_encode($submission,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $hash=hash('sha256',$json);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $current=portal_active_account($db,$accountId);
        if (!$current || !hash_equals($current['email'],$account['email'])) throw new InvalidArgumentException('Your contact email changed. Review this order again.');
        portal_require_profile($db,$accountId);
        // Repeated Review requests reuse the exact same recent draft.
        $q=$db->prepare('SELECT id FROM portal_submissions WHERE account_id=? AND submission_hash=? AND bound_at IS NULL AND created_at>? ORDER BY created_at DESC LIMIT 1');
        $q->execute([$accountId,$hash,time()-1800]);$id=$q->fetchColumn();
        if(!$id){
            $q=$db->prepare('SELECT COUNT(*) FROM portal_submissions WHERE account_id=? AND created_at>?');$q->execute([$accountId,time()-3600]);
            if((int)$q->fetchColumn()>=30)throw new InvalidArgumentException('Please wait before starting another order.');
            $id=bin2hex(random_bytes(32));
            $db->prepare('INSERT INTO portal_submissions (id,account_id,reference,payload_json,submission_json,submission_hash,created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id,$accountId,strtoupper(bin2hex(random_bytes(10))),json_encode($payload,JSON_THROW_ON_ERROR),$json,$hash,time()]);
        }
        $db->exec('COMMIT');
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    return ['review'=>$id,'quote'=>$submission['quote'],'appointment'=>$submission['appointment'],'details'=>$submission['details']];
}
function portal_submission(PDO $db,string $accountId,string $id): array|false
{
    $q=$db->prepare('SELECT s.* FROM portal_submissions s JOIN portal_accounts a ON a.id=s.account_id AND a.disabled=0 WHERE s.account_id=? AND s.id=?');
    $q->execute([$accountId,$id]);return $q->fetch(PDO::FETCH_ASSOC);
}
function portal_purchase_token(array $intent): string
{
    return hash_hmac('sha256','portal-deposit-v1:'.$intent['id'].':'.$intent['account_id'].':'.$intent['reference'],SITESEE_REAL_ESTATE_PRICING_GATE_SECRET);
}
/** Each booking_capture owns its transaction. A reserved random reference plus exact saved
 * submission hash recovers the capture/bind gap; email alone is never ownership evidence. */
function portal_purchase_submit(PDO $db,string $accountId,string $id,?callable $capture=null): string
{
    $intent=portal_submission($db,$accountId,$id);
    if(!$intent)throw new InvalidArgumentException('Review this order again.');
    if($intent['bound_at']!==null){
        if(!portal_owns_order($db,$accountId,$intent['reference']))throw new RuntimeException('Ownership needs review.');
        return $intent['reference'];
    }
    portal_require_profile($db,$accountId);
    $submission=json_decode($intent['submission_json'],true,32,JSON_THROW_ON_ERROR);
    $row=booking_get($db,$intent['reference']);
    if(!$row){
        if((int)$intent['created_at']<time()-1800)throw new InvalidArgumentException('Your review expired. Review the current pricing and arrival window again.');
        // Recheck the server clock and canonical price immediately before capture.
        $fresh=real_estate_prepare_submission(json_decode($intent['payload_json'],true,32,JSON_THROW_ON_ERROR));
        // A still-valid pre-update review keeps its exact stored/captured identity.
        // Only the explicitly reviewed subject-format change is compatible.
        if(($submission['subject']??null)===($submission['quote']['subject']??null)
            &&($fresh['subject']??null)===booking_property_subject($submission['quote']['subject'],$submission['details']))
            $fresh['subject']=$submission['subject'];
        if(!hash_equals($intent['submission_hash'],hash('sha256',json_encode($fresh,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))))throw new InvalidArgumentException('Pricing changed. Review your services again.');
        $guard=static function()use($db,$accountId,$submission):void{
            portal_require_profile($db,$accountId);
            $current=portal_active_account($db,$accountId);
            if (!$current || !hash_equals($current['email'],$submission['details']['email']))
                throw new InvalidArgumentException('Your contact email changed. Review this order again.');
        };
        try {($capture??'booking_capture')($db,$submission,$intent['reference'],true,$guard);}
        catch(PDOException $error){if(!booking_get($db,$intent['reference']))throw $error;}
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        if(!portal_active_account($db,$accountId))throw new InvalidArgumentException('Please sign in again.');
        $row=booking_get($db,$intent['reference']);
        if(!$row||!hash_equals($intent['submission_hash'],hash('sha256',$row['request_json'])))throw new RuntimeException('Submission recovery needs review.');
        $q=$db->prepare('SELECT account_id FROM portal_order_owners WHERE reference=?');$q->execute([$intent['reference']]);$owner=$q->fetchColumn();
        if($owner!==false && $owner!==$accountId)throw new RuntimeException('Ownership conflict.');
        if($owner===false){
            if($row['status']!=='awaiting_deposit_test'||$row['checkout_state']!=='ready'||(int)$row['checkout_attempt']!==0)throw new RuntimeException('Capture recovery needs review.');
            // The throwaway capture token was never published. Establish a recoverable server-only
            // capability before publishing ownership; no bearer token appears in the portal HTML.
            $db->prepare('UPDATE bookings SET agent_token_hash=?,agent_token_expires=? WHERE reference=?')->execute([hash('sha256',portal_purchase_token($intent)),time()+7*86400,$intent['reference']]);
            $db->prepare('INSERT INTO portal_order_owners (reference,account_id,evidence,created_at) VALUES (?,?,?,?)')->execute([$intent['reference'],$accountId,'portal_submission_v1',time()]);
        }
        $db->prepare('UPDATE portal_submissions SET bound_at=COALESCE(bound_at,?) WHERE id=?')->execute([time(),$id]);
        $db->exec('COMMIT');
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    return $intent['reference'];
}
function portal_purchase_intent_for_order(PDO $db,string $accountId,string $reference): array|false
{
    $q=$db->prepare('SELECT s.* FROM portal_submissions s JOIN portal_order_owners o ON o.reference=s.reference AND o.account_id=s.account_id JOIN portal_accounts a ON a.id=s.account_id AND a.disabled=0 WHERE s.account_id=? AND s.reference=? AND s.bound_at IS NOT NULL');
    $q->execute([$accountId,$reference]);return $q->fetch(PDO::FETCH_ASSOC);
}
function portal_purchase_notify(PDO $db,string $accountId,string $reference,?callable $send=null): void
{
    $intent=portal_purchase_intent_for_order($db,$accountId,$reference);if(!$intent)return;
    $submission=json_decode($intent['submission_json'],true,32,JSON_THROW_ON_ERROR);
    foreach(['staff','customer'] as $recipient){
        // At-most-once handoff to mail. An ambiguous/failed handoff requires staff review;
        // browser retries never replay notices already sent or in flight.
        $column=$recipient.'_notice';
        $q=$db->prepare("UPDATE portal_submissions SET $column='sending' WHERE id=? AND $column='pending'");$q->execute([$intent['id']]);
        if($q->rowCount()!==1)continue;
        try {$ok=($send??'portal_send_order')($recipient,$submission,$reference);}
        catch(Throwable){$ok=false;}
        $db->prepare("UPDATE portal_submissions SET $column=? WHERE id=?")->execute([$ok?'sent':'review_required',$intent['id']]);
    }
}
function portal_purchase_checkout(PDO $db,string $accountId,string $reference,bool $consent,string $ip,?callable $embedded=null,?callable $hosted=null,?array $config=null): array
{
    $intent=portal_purchase_intent_for_order($db,$accountId,$reference);
    if(!$intent)throw new InvalidArgumentException('This order is unavailable.');
    if(!$consent)throw new InvalidArgumentException('Please agree to the stated future card use before continuing.');
    $row=booking_get($db,$reference);
    $q=$db->prepare('SELECT state FROM booking_lifecycle WHERE reference=?');$q->execute([$reference]);$life=$q->fetch(PDO::FETCH_ASSOC);
    if($life && $life['state']!=='active')throw new InvalidArgumentException('This order requires staff review before payment.');
    if($row['status']==='deposit_paid_test')return ['mode'=>'paid'];
    if(!in_array($row['status'],['awaiting_deposit_test','approved_test'],true))throw new InvalidArgumentException('A deposit is not available for this order.');
    $token=portal_purchase_token($intent);
    if(!hash_equals((string)$row['agent_token_hash'],hash('sha256',$token)))throw new InvalidArgumentException('The payment link was updated. Contact SiteSee to continue.');
    // Renew only the capability this adapter established, after current account/ownership checks.
    $db->prepare('UPDATE bookings SET agent_token_expires=? WHERE reference=? AND agent_token_hash=?')->execute([time()+7*86400,$reference,hash('sha256',$token)]);
    $url=SITESEE_REAL_ESTATE_SITE_URL.'/account.php?view=payment&reference='.rawurlencode($reference).'&result=return';
    $hostedCall=static function(array $body,string $idempotency,string $key) use($hosted,$url):array{
        $body['success_url']=$url;$body['cancel_url']=$url;
        return ($hosted??'booking_stripe_create_session')($body,$idempotency,$key);
    };
    $config??=booking_checkout_config();
    if($config['enabled'])return booking_checkout_start($db,$reference,$token,$ip,
        static function(string $method,string $id,array $body,string $idempotency,string $key)use($embedded,$url):array{
            if($method==='POST')$body['return_url']=$url;
            return ($embedded??'booking_checkout_request')($method,$id,$body,$idempotency,$key);
        },$hostedCall);
    booking_checkout_schema($db);
    $q=$db->prepare('SELECT attempt FROM booking_checkout_ui WHERE reference=?');$q->execute([$reference]);$attempt=$q->fetchColumn();
    if($attempt!==false && (int)$attempt===(int)$row['checkout_attempt'] && in_array($row['checkout_state'],['creating','open'],true))throw new RuntimeException('Embedded configuration needs review.');
    return ['mode'=>'hosted','url'=>booking_start_checkout($db,$reference,$token,$ip,$hostedCall)];
}
function portal_purchase_seed(PDO $db,string $accountId,string $reference): array|false
{
    if(!portal_owns_order($db,$accountId,$reference))return false;
    $intent=portal_purchase_intent_for_order($db,$accountId,$reference);
    $row=booking_get($db,$reference);$request=booking_request($row);$quote=$request['quote'];
    $payload=$intent?json_decode($intent['payload_json'],true,32,JSON_THROW_ON_ERROR):null;
    $state=$payload['state']??[];
    if(!$payload){
        // Historical submissions retained the calculated quote, not every quantity input.
        // Carry services/property forward; require the customer to review current quantities.
        $state['selected']=array_values(array_intersect(array_keys($quote['services']??[]),array_column($quote['lines']??[],'key')));
        $state['category']=array_search($quote['category']??'', $row['market']==='residential'
            ?['small'=>'Small Home / Condo','average'=>'Average Home','large'=>'Large Home','luxury'=>'Luxury Home']
            :['small'=>'Small Commercial / Retail','mid'=>'Warehouse / Office','large'=>'Factory / Industrial'],true)?:'small';
        $state['package']=array_search($quote['package']??'',['custom'=>'Individual Services','silver'=>'Silver','gold'=>'Gold','platinum'=>'Platinum'],true)?:'custom';
        foreach(['sqft','matterportSqft','photoCount','views360','licenseType','licenseMonths','hostingMonths','hostingPrepaid','delivery'] as $key)if(isset($quote[$key]))$state[$key]=$quote[$key];
    }
    return ['market'=>$row['market'],'state'=>$state,'details'=>array_intersect_key($request['details'],array_flip(['street','unit','propertyId','city','state','zip']))];
}
function portal_purchase_availability(PDO $db,array $payload): array
{
    try {
        $config=booking_scheduling_config();
        if(!$config['enabled'])throw new RuntimeException('Calendar disabled.');
        $feedback=booking_feedback(['market'=>$payload['market'],'state'=>$payload['state'],
            'date'=>$payload['appointment']['date'],'time'=>$payload['appointment']['time'],'rush'=>$payload['appointment']['rushRequested']],
            static fn(array $range):array=>booking_scheduling_snapshot($db,$config,$range));
        if(($feedback['state']??'')==='checked')$feedback['message']=($feedback['selected_available']??false)?'Your preferred window currently has availability. SiteSee must still confirm it.':'Your preferred window is not currently available. Edit your schedule or submit it for staff review; it is not reserved.';
        return $feedback;
    }catch(Throwable){return ['state'=>'unknown','message'=>'We could not check the calendar. SiteSee will review your preferred window before confirming.'];}
}
