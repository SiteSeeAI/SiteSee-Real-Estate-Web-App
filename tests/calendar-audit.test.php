<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/audit-calendar-confirmation.php';
require_once __DIR__.'/historical-source.php';
$source=getenv('SITESEE_DIAGNOSTIC_TEST_SOURCE') ?: historical_source();
require_once $source.'/server/booking-calendar-client.php';
$root=sys_get_temp_dir().'/sitesee-diagnostic-'.bin2hex(random_bytes(6));
mkdir($root,0700);mkdir($root.'/data',0700);mkdir($root.'/server',0700);
// The already loaded module remains the exact same realpath across fixtures.
symlink($source.'/server/booking-calendar-client.php',$root.'/server/booking-calendar-client.php');
symlink($source.'/server/booking-confirmation.php',$root.'/server/booking-confirmation.php');
file_put_contents($root.'/booking-confirmation.lock','');chmod($root.'/booking-confirmation.lock',0600);
$config=['schema_version'=>1,'setup'=>'sitesee-calendar-readonly','enabled'=>true,'timezone'=>'America/Chicago',
    'accounts_base'=>'https://accounts.zoho.com','calendar_base'=>'https://calendar.zoho.com','connection_verified_at'=>time(),
    'client_id'=>'fixture-client','client_secret'=>'fixture-secret','refresh_token'=>'fixture-refresh',
    'calendar_uid'=>str_repeat('a',32),'calendar_owner_id'=>'fixture-owner','confirmation_stage'=>'test',
    'confirmation_enabled'=>true,'invitations_enabled'=>false,'requested_scopes'=>['ZohoCalendar.event.READ','ZohoCalendar.event.CREATE'],
    'test_recipient_email'=>'agent@example.com'];
foreach(['zoho-calendar.json','zoho-confirmation.json'] as $f){file_put_contents($root.'/'.$f,json_encode($config));chmod($root.'/'.$f,0600);}
$db=new PDO('sqlite:'.$root.'/data/bookings.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
chmod($root.'/data/bookings.sqlite',0600);
$db->exec('CREATE TABLE bookings(reference TEXT PRIMARY KEY,status TEXT,email TEXT,photographer TEXT,duration_minutes INTEGER,deposit_paid_at TEXT,approved_at TEXT,request_json TEXT)');
$db->exec('CREATE TABLE booking_scheduling(reference TEXT PRIMARY KEY,rush_status TEXT,reschedule_required INTEGER,appointment_json TEXT)');
$db->exec('CREATE TABLE booking_confirmations(reference TEXT PRIMARY KEY,state TEXT,event_uid TEXT,calendar_uid TEXT,planned_start INTEGER,planned_end INTEGER,event_json TEXT,invitation_state TEXT,confirmed_at TEXT)');
$db->exec('CREATE TABLE booking_schedule_events(id INTEGER PRIMARY KEY,reference TEXT,action TEXT,recorded_at TEXT,detail_json TEXT)');
$date=(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');
$start=(new DateTimeImmutable($date.' 07:00',new DateTimeZone('America/Chicago')))->getTimestamp();
$reference='E6E183EF8E';$writes=0;$calls=0;$records=[];$created=[];$scenario='success';$checks=0;
function check(bool $condition,string $label):void{global $checks;++$checks;if(!$condition)throw new Exception($label);}
function reset_fixture():void{
    global $db,$reference,$date,$start,$config,$writes,$calls,$records,$created,$scenario;
    foreach(['booking_schedule_events','booking_confirmations','booking_scheduling','bookings'] as $t)$db->exec('DELETE FROM '.$t);
    $appointment=['date'=>$date,'time'=>'07:00','windowMinutes'=>120];
    $db->prepare('INSERT INTO bookings VALUES(?,?,?,?,?,?,?,?)')->execute([$reference,'deposit_paid_test','agent@example.com','David J Cro',120,gmdate('c'),gmdate('c'),json_encode(['appointment'=>$appointment])]);
    $db->prepare('INSERT INTO booking_scheduling VALUES(?,?,?,?)')->execute([$reference,'not_requested',0,null]);
    $e=['title'=>'SiteSee TEST Shoot '.$reference.' original-marker','dateandtime'=>['start'=>gmdate('Ymd\THis\Z',$start),'end'=>gmdate('Ymd\THis\Z',$start+7200),'timezone'=>'America/Chicago'],
        'isallday'=>false,'isprivate'=>true,'isrep'=>false,'transparency'=>0,'calendar_alarm'=>false,'notify_attendee'=>0,'attendees'=>[],'reminders'=>[],'conference'=>'none','allowForwarding'=>false];
    $db->prepare('INSERT INTO booking_confirmations VALUES(?,?,?,?,?,?,?,?,?)')->execute([$reference,'uncertain',null,$config['calendar_uid'],$start,$start+7200,json_encode($e),'none',null]);
    $writes=0;$calls=0;$records=[];$created=[];$scenario='success';
}

$manifest=json_decode(file_get_contents(dirname(__DIR__).'/documents/deployment/Calendar_Confirmation_Manifest_20260925.json'),true);
mkdir($root.'/tools',0700);
foreach($manifest['files'] as $path=>$hash){
    $from=str_starts_with($path,'server/')?$source.'/'.$path:dirname(__DIR__).'/'.$path;
    if(!file_exists($root.'/'.$path))symlink($from,$root.'/'.$path);
}
file_put_contents($root.'/calendar-confirmation-release.json',json_encode($manifest));
function ready_fixture():void{
    global $db,$reference,$created,$config,$calls,$writes,$scenario;
    reset_fixture();$db->exec("UPDATE booking_confirmations SET event_uid='fixture-event@zoho.com'");
    $e=json_decode(cd_claim($db,$reference)['event_json'],true);unset($e['attendees'],$e['reminders']);
    $e['uid']='fixture-event@zoho.com';$e['caluid']=$config['calendar_uid'];$e['organizer']='owner@example.com';
    $created=[$e['uid']=>$e];
}
$transport=static function($method,$url,$form,$token)use(&$calls,&$writes,&$created,&$scenario,$db,$root,$config):array{
    ++$calls;
    if($url==='https://accounts.zoho.com/oauth/v2/token')return ['status'=>200,'body'=>['access_token'=>'fixture-token']];
    if($method!=='GET'){++$writes;throw new RuntimeException('Calendar mutation is forbidden.');}
    if(str_contains($url,'%40'))return ['status'=>404,'body'=>null];
    if($scenario==='http_failure')return ['status'=>403,'body'=>['error'=>[['description'=>'fixture-secret fixture-token']]]];
    $e=reset($created);$list=str_ends_with(parse_url($url,PHP_URL_PATH),'/events');
    if($scenario==='many_failures'){$e['isprivate']=false;$e['calendar_alarm']=true;}
    if(!$list){
        if($scenario==='moved')$e['dateandtime']['end']=gmdate('Ymd\THis\Z',time()+999999);
        return ['status'=>200,'body'=>['events'=>[$e]]];
    }
    $events=[$e];
    if($scenario==='conflict'||$scenario==='duplicate'){$other=$e;$other['uid']='other@zoho.com';if($scenario==='conflict')$other['title']='Other booking';$events[]=$other;}
    if($scenario==='missing')$events=[];
    if($scenario==='pagination')return ['status'=>200,'body'=>['events'=>$events,'next_page'=>'2']];
    if($scenario==='booking_change')$db->exec('UPDATE bookings SET duration_minutes=60');
    if($scenario==='config_change'){$c=$config;$c['invitations_enabled']=true;file_put_contents($root.'/zoho-confirmation.json',json_encode($c));}
    return ['status'=>200,'body'=>['events'=>$events]];
};
function audit_case(bool $save):array{
    global $root,$reference,$transport;ob_start();$ok=ca_run($root,$reference,$save,$transport);return [$ok,ob_get_clean()];
}
ready_fixture();$hash=hash_file('sha256',$root.'/data/bookings.sqlite');[$ok,$out]=audit_case(false);
check($ok&&$calls===6,'Both connections, direct detail and complete lists checked in one read-only run. '.$out);
check($hash===hash_file('sha256',$root.'/data/bookings.sqlite')&&$writes===0,'Read-only combined check preserves ledger and does not create.');
$before=cd_booking($db,$reference);[$ok,$out]=audit_case(true);
check($ok&&cd_claim($db,$reference)['state']==='confirmed','Existing matching event reconciles successfully.');
check(cd_booking($db,$reference)===$before&&cd_claim($db,$reference)['invitation_state']==='none'&&$writes===0,'Payment/review unchanged; zero calendar writes or invitations.');
$count=$db->query('SELECT count(*) FROM booking_schedule_events')->fetchColumn();[$ok,$out]=audit_case(true);
check($ok&&$count===$db->query('SELECT count(*) FROM booking_schedule_events')->fetchColumn(),'Repeated combined run is idempotent.');
foreach(['many_failures','http_failure','moved','conflict','duplicate','missing','pagination','booking_change','config_change'] as $bad){
    ready_fixture();$scenario=$bad;[$ok,$out]=audit_case(true);
    check(!$ok&&cd_claim($db,$reference)['state']==='uncertain'&&$writes===0,'Unsafe evidence cannot confirm: '.$bad);
    if($bad==='many_failures')check(substr_count($out,'FAIL:')>=4&&$calls===6,'Independent failures collected across both connections without stopping early.');
    if($bad==='http_failure')check(!str_contains($out,'fixture-secret')&&!str_contains($out,'fixture-token'),'Provider output redacts credentials.');
    file_put_contents($root.'/zoho-confirmation.json',json_encode($config));
}
ready_fixture();$m=$manifest;$m['files']['server/booking-confirmation.php']=str_repeat('0',64);file_put_contents($root.'/calendar-confirmation-release.json',json_encode($m));
[$ok,$out]=audit_case(true);check(!$ok&&$calls===6&&cd_claim($db,$reference)['state']==='uncertain','Stale release blocks save while remaining independent diagnostics continue.');
file_put_contents($root.'/calendar-confirmation-release.json',json_encode($manifest));
ready_fixture();$db->exec("UPDATE bookings SET status='awaiting_deposit_test'");[$ok,$out]=audit_case(true);check(!$ok&&$writes===0,'Unpaid booking stays blocked.');
ready_fixture();$db->exec("UPDATE booking_confirmations SET event_uid=NULL");[$ok,$out]=audit_case(true);check(!$ok&&$writes===0,'Missing UID cannot cause an event creation.');
ready_fixture();$held=fopen($root.'/booking-confirmation.lock','rb');flock($held,LOCK_EX|LOCK_NB);[$ok,$out]=audit_case(true);fclose($held);check(!$ok&&cd_claim($db,$reference)['state']==='uncertain','Concurrent confirmation blocks save.');
$db=null;
foreach(['data','server','tools'] as $dir){foreach(glob($root.'/'.$dir.'/*') as $p)unlink($p);rmdir($root.'/'.$dir);}
foreach(glob($root.'/*') as $p)unlink($p);rmdir($root);
echo "PASS: $checks combined-audit checks; all requests mocked.\n";
