<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=getenv('PORTAL_TEST_PRIVATE');
if (!$root || !str_starts_with($root,sys_get_temp_dir().'/sitesee-portal-http-')) throw new RuntimeException('Isolated fixture directory required.');
require __DIR__.'/service-providers.php';
require $root.'/server/portal-orders.php';
require $root.'/server/portal-phone.php';
$db=booking_db();portal_access_schema($db);portal_profile_schema($db);portal_phone_schema($db);
$command=$argv[1]??'seed';
if($command==='reset-sms-rate'){$db->exec('DELETE FROM portal_phone_attempts');exit;}
if($command==='service-notice-observed'){
    booking_communication_schema($db);booking_communication_update($db,'lifecycle-1:DDDD000001',['submission_state'=>'sent_observed']);exit;
}
if($command==='seed-service'){
    require $root.'/server/portal-billing.php';portal_billing_schema($db);booking_communication_schema($db);
    file_put_contents($root.'/booking-lifecycle.json',json_encode(['schema'=>1,'stage'=>'test','enabled'=>true,'recipient'=>'cro@sitesee.ai']));chmod($root.'/booking-lifecycle.json',0600);
    file_put_contents($root.'/real-estate-pricing-pending/'.str_repeat('3',32).'.json',json_encode(['email'=>'cro@sitesee.ai','status'=>'approved','approved_at'=>gmdate('c')]));
    $id=str_repeat('d',32);$ref='DDDD000001';$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,'cro@sitesee.ai',time()]);portal_phone_enroll($db,'cro@sitesee.ai','3125550102','Synthetic staff verification',static fn()=>true);
    $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential','details'=>['first'=>'Test','last'=>'Customer','company'=>'Example','email'=>'cro@sitesee.ai','phone'=>'3125550100','street'=>'404 Service Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],'appointment'=>['date'=>(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]]);
    booking_capture($db,$s,$ref,true);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,stripe_session_id='cs_test_http_deposit',stripe_payment_intent_id='pi_http',stripe_customer_id='cus_http' WHERE reference=?")->execute([gmdate('c'),$ref]);
    $db->prepare('INSERT INTO portal_order_owners VALUES (?,?,?,?)')->execute([$ref,$id,'isolated-fixture',time()]);
    booking_review_paid($db,$ref,95,'David',true);booking_confirm_appointment($db,$ref,booking_scheduling_ms_config(),'portal_fixture_calendar',null,$root.'/data/fixture-lock');$db->prepare("UPDATE booking_confirmations SET invitation_state='sent' WHERE reference=?")->execute([$ref]);exit;
}
if ($command==='rotate-one-csrf') {
    $id=$db->query("SELECT id FROM portal_accounts WHERE email='one@example.com'")->fetchColumn();
    foreach(glob($root.'/data/portal-sessions/sess_*')?:[] as $file){
        $raw=file_get_contents($file);
        if(str_contains($raw,(string)$id))file_put_contents($file,preg_replace('/csrf\|s:64:"[a-f0-9]{64}";/', 'csrf|s:64:"'.bin2hex(random_bytes(32)).'";', $raw));
    }
    exit;
}
if ($command==='expire-link') {
    $db->exec('UPDATE portal_phone_challenges SET expires_at=0');exit;
}
if ($command==='disable-one') {
    $db->exec("UPDATE portal_accounts SET disabled=1 WHERE email='one@example.com'");exit;
}
if ($command==='expire-session' || $command==='absolute-session') {
    foreach(glob($root.'/data/portal-sessions/sess_*')?:[] as $file) {
        $raw=file_get_contents($file);
        $key=$command==='expire-session'?'seen':'started';
        $raw=preg_replace('/s:'.strlen($key).':"'.$key.'";i:\d+;/', 's:'.strlen($key).':"'.$key.'";i:1;', $raw);
        file_put_contents($file,$raw);
    }
    exit;
}
if ($command==='snapshot') {
    $snapshot=[];
    foreach(['bookings','booking_scheduling','booking_confirmations','booking_lifecycle','stripe_events'] as $table)$snapshot[$table]=$db->query("SELECT * FROM ".$table." WHERE reference IN ('AAAAAAAAAA','BBBBBBBBBB','CCCCCCCCCC')")->fetchAll();
    echo hash('sha256',json_encode($snapshot));exit;
}
if ($command==='remove-approval') {
    foreach(glob($root.'/real-estate-pricing-pending/*.json')?:[] as $file)unlink($file);exit;
}
$pending=$root.'/real-estate-pricing-pending';mkdir($pending,0700,true);
foreach([['one@example.com','AAAAAAAAAA','101 Example Lane','1234567890'],['two@example.com','BBBBBBBBBB','202 Other Street','9876543210'],['one@example.com','CCCCCCCCCC','303 Unclaimed Road','1111111111']] as $i=>[$email,$ref,$street,$code]) {
    $submission=['action'=>'request_appointment','market'=>'residential',
        'details'=>['first'=>'Test','last'=>(string)$i,'company'=>'Synthetic Company','email'=>$email,'phone'=>'3125550100','street'=>$street,'city'=>'Chicago','state'=>'IL','zip'=>'60601'],
        'appointment'=>['date'=>'2026-10-08','time'=>'09:00','windowEnd'=>'11:00','windowMinutes'=>120,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>$code,'specialRequests'=>'Private request '.$ref,'cancellationAccepted'=>true],
        'quote'=>['totalCents'=>50000,'market'=>'residential','platformMonthlyCents'=>0,'lines'=>[['label'=>'HDR Photography','cents'=>50000,'included'=>false]]]];
    booking_capture($db,$submission,$ref,true);
    $token=str_repeat((string)($i+1),64);
    $db->prepare('UPDATE bookings SET agent_token_hash=?,stripe_customer_id=?,stripe_payment_intent_id=? WHERE reference=?')->execute([hash('sha256',$token),'cus_private_'.$ref,'pi_private_'.$ref,$ref]);
    if($i<2)file_put_contents($pending.'/'.str_repeat((string)($i+1),32).'.json',json_encode(['email'=>$email,'status'=>'approved','approved_at'=>'2026-09-28T12:00:00Z']));
}
// A valid management proof uses the existing verifier without performing calendar calls.
booking_lifecycle_set($db,'CCCCCCCCCC',['token_hash'=>hash('sha256',str_repeat('4',64)),'token_expires'=>time()+900]);

portal_phone_enroll($db,'one@example.com','3125550100','Synthetic staff verification','portal_pricing_approved');
portal_phone_enroll($db,'two@example.com','3125550101','Synthetic staff verification','portal_pricing_approved');
