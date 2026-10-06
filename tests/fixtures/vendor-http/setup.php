<?php
declare(strict_types=1);
$root=(string)getenv('PORTAL_TEST_PRIVATE');
if(PHP_SAPI!=='cli'||!str_starts_with($root,sys_get_temp_dir().'/sitesee-portal-http-'))exit(1);
require $root.'/server/portal-billing.php';require $root.'/server/vendor-access.php';
$db=booking_db();vendor_schema($db);$ref='DDDD000001';$cmd=$argv[1]??'';
if($cmd==='reset-rate'){$db->exec('DELETE FROM vendor_phone_attempts');exit;}
if($cmd==='bill'){$row=booking_job_get($db,$ref);echo $row?$row['bill_json']:'null';exit;}
if($cmd==='schedule-overlay'){
    $row=booking_get($db,$ref);file_put_contents($root.'/data/original-schedule.json',json_encode($row['schedule_appointment_json']));
    $a=booking_request($row)['appointment'];$a['date']='2026-11-12';$a['time']='15:00';$a['windowEnd']='17:00';$a['mustHaveShots']='Capture the courtyard';$a['onsiteDifferent']=true;$a['onsiteName']='Isolated Onsite Contact';$a['onsitePhone']='3125550188';$a['onsiteEmail']='onsite@example.test';
    $db->prepare('UPDATE booking_scheduling SET appointment_json=? WHERE reference=?')->execute([json_encode($a),$ref]);exit;
}
if($cmd==='restore-schedule'){$db->prepare('UPDATE booking_scheduling SET appointment_json=? WHERE reference=?')->execute([json_decode(file_get_contents($root.'/data/original-schedule.json'),true),$ref]);exit;}
throw new RuntimeException('Unknown isolated vendor fixture command.');
