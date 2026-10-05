<?php
declare(strict_types=1);
$root=(string)getenv('PORTAL_TEST_PRIVATE');
if(PHP_SAPI!=='cli'||!str_starts_with($root,sys_get_temp_dir().'/sitesee-portal-http-'))exit(1);
require $root.'/server/portal-billing.php';$db=booking_db();booking_communication_schema($db);booking_job_schema($db);
$ref='DDDD000001';$command=$argv[1]??'';
if($command==='prepare'){
    $db->prepare('UPDATE bookings SET consent_version=?,consent_at=? WHERE reference=?')->execute([BOOKING_CONSENT_VERSION,gmdate('c'),$ref]);
    file_put_contents($root.'/booking-checkout.json',json_encode(['schema'=>1,'stage'=>'TEST','enabled'=>true,'publishable_key'=>'pk_test_isolated1234567890123456']));chmod($root.'/booking-checkout.json',0600);
    echo 'base64:'.base64_encode(password_hash('isolated-staff-password',PASSWORD_DEFAULT));exit;
}
if($command==='recovery-ready'){$db->prepare('UPDATE booking_jobs SET confirm_at=? WHERE reference=?')->execute([time()-31,$ref]);exit;}
if($command==='commercial-preview'){
    // Separate synthetic scenario after the residential preservation assertions.
    $db->prepare('DELETE FROM booking_jobs WHERE reference=?')->execute([$ref]);
    $db->prepare('DELETE FROM booking_job_extras WHERE reference=?')->execute([$ref]);
    $row=booking_get($db,$ref);$request=booking_request($row);
    $request['quote']=real_estate_commercial_quote(['category'=>'small','selected'=>[],'licenseType'=>'unlimited']);
    $db->prepare("UPDATE bookings SET market='commercial',request_json=? WHERE reference=?")->execute([json_encode($request,JSON_THROW_ON_ERROR),$ref]);exit;
}
if(in_array($command,['paid','refund'],true)){
    $p=$root.'/data/job-provider.json';$s=json_decode(file_get_contents($p),true);$s['paid']=true;$s['refunded']=$command==='refund'?100:0;file_put_contents($p,json_encode($s));exit;
}
if($command==='snapshot'){
    $all=[];foreach(['bookings','booking_scheduling','booking_confirmations','booking_lifecycle','booking_lifecycle_operations','booking_communications','booking_contact_links','stripe_events'] as $t)$all[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();
    $all['calendar']=file_get_contents($root.'/data/provider-events.json');echo hash('sha256',json_encode($all));exit;
}
throw new RuntimeException('Unknown isolated setup command.');
