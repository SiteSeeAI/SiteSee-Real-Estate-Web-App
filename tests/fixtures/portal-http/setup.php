<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=getenv('PORTAL_TEST_PRIVATE');
if (!$root || !str_starts_with($root,sys_get_temp_dir().'/sitesee-portal-http-')) throw new RuntimeException('Isolated fixture directory required.');
require $root.'/server/portal-orders.php';
$db=booking_db();portal_access_schema($db);portal_profile_schema($db);
$command=$argv[1]??'seed';
if ($command==='expire-link') {
    $db->exec('UPDATE portal_login_challenges SET expires_at=0');exit;
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
    foreach(['bookings','booking_scheduling','booking_confirmations','booking_lifecycle','stripe_events'] as $table)$snapshot[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
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
