<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/diagnose-calendar-confirmation.php';
$source=getenv('SITESEE_DIAGNOSTIC_TEST_SOURCE') ?: dirname(__DIR__).'/_private';
require_once $source.'/server/booking-calendar-client.php';
$root=sys_get_temp_dir().'/sitesee-diagnostic-'.bin2hex(random_bytes(6));
mkdir($root,0700);mkdir($root.'/data',0700);mkdir($root.'/server',0700);
// The already loaded module remains the exact same realpath across fixtures.
symlink($source.'/server/booking-calendar-client.php',$root.'/server/booking-calendar-client.php');
file_put_contents($root.'/booking-confirmation.lock','');chmod($root.'/booking-confirmation.lock',0600);
$config=['schema_version'=>1,'setup'=>'sitesee-calendar-readonly','enabled'=>true,'timezone'=>'America/Chicago',
    'accounts_base'=>'https://accounts.zoho.com','calendar_base'=>'https://calendar.zoho.com','connection_verified_at'=>time(),
    'client_id'=>'fixture-client','client_secret'=>'fixture-secret','refresh_token'=>'fixture-refresh',
    'calendar_uid'=>str_repeat('a',32),'calendar_owner_id'=>'fixture-owner','confirmation_stage'=>'test',
    'confirmation_enabled'=>true,'invitations_enabled'=>false,'requested_scopes'=>['ZohoCalendar.event.CREATE'],
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
$transport=static function($method,$url,$form,$token)use(&$writes,&$calls,&$records,&$created,&$scenario,$config):array{
    ++$calls;
    if($url==='https://accounts.zoho.com/oauth/v2/token')return ['status'=>200,'body'=>['access_token'=>'fixture-token']];
    check($token==='fixture-token','Token goes only to the calendar request.');
    if($method==='POST'){
        ++$writes;$e=json_decode($form['eventdata'],true);check($e['attendees']===[]&&$e['notify_attendee']===0,'No invitation payload.');
        if($scenario==='timeout')throw new RuntimeException('fixture-secret must never escape');
        if($scenario==='rejected')return ['status'=>400,'body'=>['error'=>[['error_code'=>'EXTRA_KEY_FOUND','description'=>'isrep invalid fixture-secret fixture-token']]]];
        $e['uid']='fixture-event@zoho.com';$e['caluid']=$config['calendar_uid'];$created[$e['uid']]=$e;
        return ['status'=>200,'body'=>['events'=>[$e]]];
    }
    if(str_ends_with(parse_url($url,PHP_URL_PATH),'/events')){
        $events=array_merge($records,array_values($created));
        if($scenario==='pagination')return ['status'=>200,'body'=>['events'=>[],'next_page'=>'2']];
        if($scenario==='malformed')return ['status'=>200,'body'=>['events'=>[['title'=>'missing dates']]]];
        if($scenario==='race'&&$created){$other=reset($created);$other['uid']='racing-event@zoho.com';$events[]=$other;}
        return ['status'=>200,'body'=>['events'=>$events ?: [['message'=>'No events found.']]]];
    }
    $e=$created[rawurldecode(basename(parse_url($url,PHP_URL_PATH)))]??[];
    if($scenario==='moved')$e['dateandtime']['end']=gmdate('Ymd\THis\Z',time()+1000000);
    return ['status'=>200,'body'=>['events'=>[$e]]];
};
function run_case(bool $retry,?callable $ack=null):array{
    global $root,$reference,$transport;$exception=null;ob_start();
    try{cd_run($root,$reference,$retry,$transport,$ack??static fn($ref)=>'RETRY '.$ref);}catch(Throwable $e){$exception=$e;}
    return [ob_get_clean(),$exception];
}
reset_fixture();$before=$db->query('SELECT * FROM bookings')->fetchAll();$hash=hash_file('sha256',$root.'/data/bookings.sqlite');
[$out,$error]=run_case(false);check($error===null&&str_contains($out,'READ-ONLY CHECK COMPLETE'),'Default check succeeds.');
check($writes===0&&$db->query('SELECT count(*) FROM booking_schedule_events')->fetchColumn()===0,'Default check creates no event/audit.');
check($hash===hash_file('sha256',$root.'/data/bookings.sqlite'),'Read-only check preserves database bytes.');
[$out,$error]=run_case(true,static fn($ref)=>'NO');check($error!==null&&$writes===0,'No retry without exact manual acknowledgement.');
[$out,$error]=run_case(true);check($error===null&&$writes===1,'One authorized retry succeeds.');
$claim=cd_claim($db,$reference);check($claim['state']==='confirmed'&&$claim['event_uid']==='fixture-event@zoho.com'&&$claim['invitation_state']==='none','Verified event saved without invitation.');
check($before===$db->query('SELECT * FROM bookings')->fetchAll(),'Payment and review records unchanged.');
run_case(true);check($writes===1,'Repeated command cannot create again.');
reset_fixture();$scenario='rejected';[$out,$error]=run_case(true);check($error!==null&&$writes===1,'Provider rejection retained.');
check(str_contains($out,'EXTRA_KEY_FOUND')&&!str_contains($out,'fixture-secret')&&!str_contains($out,'fixture-token'),'Provider diagnostics redact credentials.');
$audit=json_encode($db->query('SELECT * FROM booking_schedule_events')->fetchAll());check(!str_contains($audit,'fixture-secret')&&!str_contains($audit,'fixture-token'),'Audit has no credentials.');
check(cd_claim($db,$reference)['state']==='uncertain','Rejected response does not reset claim.');run_case(true);check($writes===1,'Rejected retry is single-use.');
reset_fixture();$scenario='timeout';[$out,$error]=run_case(true);check($error!==null&&!str_contains($out,'fixture-secret'),'Transport exception stays private.');run_case(true);check($writes===1,'Lost response cannot auto-retry.');
foreach(['pagination','malformed'] as $bad){reset_fixture();$scenario=$bad;[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Incomplete/malformed read blocks retry.');}
reset_fixture();$records=[['title'=>'Existing '.$reference,'uid'=>'old@zoho.com','isallday'=>false,'dateandtime'=>['start'=>gmdate('Ymd\THis\Z',$start-86400),'end'=>gmdate('Ymd\THis\Z',$start-82800),'timezone'=>'UTC']]];
[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Booking marker outside shoot interval blocks retry.');
reset_fixture();$records=[['title'=>'Other event','uid'=>'other@zoho.com','isallday'=>false,'dateandtime'=>['start'=>gmdate('Ymd\THis\Z',$start),'end'=>gmdate('Ymd\THis\Z',$start+1800),'timezone'=>'UTC']]];
[$out,$error]=run_case(true);check($error!==null&&$writes===0,'New remote conflict blocks retry.');
foreach(['moved','race'] as $bad){reset_fixture();$scenario=$bad;[$out,$error]=run_case(true);$claim=cd_claim($db,$reference);check($error!==null&&$claim['state']==='uncertain'&&$claim['event_uid']==='fixture-event@zoho.com','Post-create verification failure preserves UID and uncertainty.');run_case(true);check($writes===1,'Post-create failure cannot retry.');}
reset_fixture();[$out,$error]=run_case(true,static function($ref)use($db){$db->exec('UPDATE bookings SET duration_minutes=60');return 'RETRY '.$ref;});check($error!==null&&$writes===0,'Changed booking blocks retry before write.');
reset_fixture();$db->exec("UPDATE bookings SET status='awaiting_deposit_test'");[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Unpaid booking blocked.');
reset_fixture();$db->exec("UPDATE bookings SET email='different@example.com'");[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Non-allowlisted mailbox blocked.');
reset_fixture();$db->exec("UPDATE booking_confirmations SET event_uid='existing@zoho.com'");[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Known event ID blocks retry.');
reset_fixture();$db->exec("INSERT INTO booking_confirmations SELECT 'AAA0000001',state,event_uid,calendar_uid,planned_start,planned_end,event_json,invitation_state,confirmed_at FROM booking_confirmations");
[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Other durable local claim blocks retry.');
reset_fixture();$held=fopen($root.'/booking-confirmation.lock','rb');flock($held,LOCK_EX|LOCK_NB);[$out,$error]=run_case(true);fclose($held);check($error!==null&&$writes===0,'Existing confirmation lock blocks parallel operation.');
reset_fixture();[$out,$error]=run_case(true,static function($ref)use($root,$config){$c=$config;$c['confirmation_enabled']=false;file_put_contents($root.'/zoho-confirmation.json',json_encode($c));return 'RETRY '.$ref;});
check($error!==null&&$writes===0,'Control change during acknowledgement blocks retry.');file_put_contents($root.'/zoho-confirmation.json',json_encode($config));
reset_fixture();$modified=$config;$modified['invitations_enabled']=true;file_put_contents($root.'/zoho-confirmation.json',json_encode($modified));
[$out,$error]=run_case(true);check($error!==null&&$writes===0,'Enabled invitations block diagnostic retry.');
foreach(glob($root.'/data/*') as $p)unlink($p);unlink($root.'/server/booking-calendar-client.php');rmdir($root.'/server');rmdir($root.'/data');foreach(glob($root.'/*') as $p)unlink($p);rmdir($root);
echo "PASS: $checks diagnostic safety checks (mocked Zoho; no real requests).\n";
