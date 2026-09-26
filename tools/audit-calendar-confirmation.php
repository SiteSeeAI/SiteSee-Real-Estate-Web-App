<?php
declare(strict_types=1);
/** Collect all independent evidence, then optionally reconcile an EXISTING event. Never create/send. */
require_once __DIR__ . '/diagnose-calendar-confirmation.php';

const CA_REQUIRED_FILES = [
    'server/booking-calendar-client.php'=>'e6f80acb03a71a5fc4daf055015fc45b1af62bbdd2700fb080c880bad6e7e0aa',
    'server/booking-confirmation.php'=>'06e259fc9582208cf5ae384b671aba3510b4aae7e3e45bed14f996256194caf6',
    'server/booking-invitation.php'=>'95d15de549108995a4fbbf54a93e9c7cb8d197bf98c2138ced24b8c1c9461fe4',
    'tools/diagnose-calendar-confirmation.php'=>'567c69df1df573342c4381dfe95987edc570b376012f94c90a0902806878d9e8',
];

function ca_check(array &$failures, string $label, callable $check): mixed
{
    try { $result=$check(); echo "PASS: $label\n"; return $result; }
    catch (Throwable $e) {
        $failures[]=$label;
        $message=$e instanceof RuntimeException && in_array($e->getFile(),[__FILE__,__DIR__.'/diagnose-calendar-confirmation.php'],true)
            ? $e->getMessage() : 'Check could not be completed safely.';
        echo "FAIL: $label — $message\n"; return null;
    }
}

function ca_need(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function ca_private(string $path, int $owner): void
{
    $s=@lstat($path);
    ca_need((bool)$s && ($s['mode']&0170000)===0100000 && ($s['mode']&0077)===0
        && $s['uid']===$owner && $s['nlink']===1,'Private file ownership or permissions do not match.');
}

function ca_run(string $root, string $reference, bool $reconcile, ?callable $transport=null): bool
{
    ca_need((bool)preg_match('/^[A-F0-9]{10,32}$/D',$reference),'Invalid booking reference.');
    $failures=[]; $hashes=[]; $owner=fileowner($root); $transport??='cd_http';
    echo "Booking: $reference\nMode: ".($reconcile?'complete checks and reconcile existing event':'read-only complete checks')."\n";
    ca_check($failures,'PHP runtime',static function():bool{
        ca_need(PHP_VERSION_ID>=80200 && extension_loaded('curl') && extension_loaded('pdo_sqlite'),'PHP 8.2+, cURL and PDO SQLite are required.');return true;
    });
    $manifest=ca_check($failures,'Release manifest',static function()use($root):array{
            $m=json_decode((string)@file_get_contents($root.'/calendar-confirmation-release.json'),true,32,JSON_THROW_ON_ERROR);
        ca_need(($m['release']??'')==='sitesee-calendar-confirmation-test-v1' && is_array($m['files']??null),'Release manifest is invalid.');
        foreach(CA_REQUIRED_FILES as $path=>$hash)ca_need(($m['files'][$path]??null)===$hash,'The current lookup correction is not listed in the release.');
        return $m;
    });
    if($manifest)foreach($manifest['files'] as $path=>$expected)ca_check($failures,'Deployed '.$path,static function()use($root,$path,$expected,&$hashes):bool{
        ca_need((bool)preg_match('~^(?:server|tools)/[a-z0-9.-]+\.(?:php|py)$~D',$path)
            && is_string($expected) && (bool)preg_match('/^[a-f0-9]{64}$/D',$expected),'Invalid release entry.');
        $raw=@file_get_contents($root.'/'.$path);
        ca_need(is_string($raw)&&hash('sha256',str_replace("\r\n","\n",$raw))===$expected,'Uploaded file does not match the tested release.');
        if(str_ends_with($path,'.php'))token_get_all($raw,TOKEN_PARSE);
        $hashes[$root.'/'.$path]=hash('sha256',$raw); return true;
    });
    $hashes[$root.'/calendar-confirmation-release.json']=@hash_file('sha256',$root.'/calendar-confirmation-release.json');
    $clientReady=ca_check($failures,'Calendar client ready',static function()use($root):bool{
        $p=$root.'/server/booking-calendar-client.php';$raw=(string)file_get_contents($p);
        ca_need(hash('sha256',str_replace("\r\n","\n",$raw))===CA_REQUIRED_FILES['server/booking-calendar-client.php'],'Upload the corrected calendar client.');
        require_once $p;return true;
    });
    if(!$clientReady){echo "RESULT: INCOMPLETE. No booking changes made.\n";return false;}
    $configs=[];
    foreach(['reader'=>'zoho-calendar.json','writer'=>'zoho-confirmation.json'] as $name=>$file){
        $configs[$name]=ca_check($failures,ucfirst($name).' configuration',static function()use($root,$file,&$hashes):array{
            $c=booking_calendar_config($root.'/'.$file);ca_need($c['enabled']===true,'Connection is disabled.');
            $hashes[$root.'/'.$file]=hash_file('sha256',$root.'/'.$file);return $c;
        });
    }
    $reader=$configs['reader'];$writer=$configs['writer'];
    if($reader&&$writer)ca_check($failures,'Matching calendar identity and test controls',static function()use($reader,$writer):bool{
        ca_need($reader['calendar_uid']===$writer['calendar_uid'] && ($reader['calendar_owner_id']??'')===($writer['calendar_owner_id']??'')
            && ($writer['confirmation_stage']??'')==='test' && ($writer['confirmation_enabled']??null)===true
            && ($writer['invitations_enabled']??null)===false && filter_var($writer['test_recipient_email']??'',FILTER_VALIDATE_EMAIL)
            && in_array('ZohoCalendar.event.READ',$writer['requested_scopes']??[],true)
            && in_array('ZohoCalendar.event.CREATE',$writer['requested_scopes']??[],true),'Calendar identity, scopes or test controls differ.');return true;
    });
    $db=ca_check($failures,'Existing private booking ledger',static function()use($root,$owner,$reconcile):PDO{
        ca_private($root.'/data/bookings.sqlite',$owner);
        $db=new PDO('sqlite:file:'.$root.'/data/bookings.sqlite?mode='.($reconcile?'rw':'ro'),null,null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5]);
        if(!$reconcile)$db->exec('PRAGMA query_only=ON');return $db;
    });
    $lock=ca_check($failures,'Exclusive confirmation lock',static function()use($root,$owner){
        ca_private($root.'/booking-confirmation.lock',$owner);$h=fopen($root.'/booking-confirmation.lock','rb');
        if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new RuntimeException('Another calendar operation is running.');}return $h;
    });
    try {
        $row=$claim=$expected=null; $remoteCheckedAt=0;
        if($db){
            $row=ca_check($failures,'Existing booking record',static function()use($db,$reference):array{
                $r=cd_booking($db,$reference);ca_need((bool)$r,'Booking was not found.');return $r;
            });
            $claim=ca_check($failures,'Recorded event ID and confirmation state',static function()use($db,$reference):array{
                $c=cd_claim($db,$reference);ca_need((bool)$c && in_array($c['state'],['uncertain','confirmed'],true)
                    && $c['invitation_state']==='none' && is_string($c['event_uid']) && $c['event_uid']!=='','An existing uninvited event ID is required.');
                booking_calendar_event_path($c['calendar_uid'],$c['event_uid']);echo 'Current booking state: '.$c['state']."\n";return $c;
            });
            ca_check($failures,'Recent diagnostic history',static function()use($db,$reference):bool{
                $q=$db->prepare("SELECT action,detail_json FROM booking_schedule_events WHERE reference=? AND action LIKE 'diagnostic_%' ORDER BY id DESC LIMIT 8");$q->execute([$reference]);
                foreach(array_reverse($q->fetchAll()) as $r){
                    $d=json_decode($r['detail_json'],true);$safe=[];
                    foreach(['http_status','curl_errno','json_response'] as $key)if(isset($d[$key])&&(is_int($d[$key])||is_bool($d[$key])))$safe[$key]=$d[$key];
                    if(preg_match('/^[a-z0-9_]{1,80}$/D',$r['action']))echo $r['action'].': '.json_encode($safe)."\n";
                }return true;
            });
        }
        if($row&&$writer)ca_check($failures,'Paid test booking and saved staff review',static function()use($row,$writer):bool{
            ca_need($row['status']==='deposit_paid_test' && (bool)$row['deposit_paid_at'] && (bool)$row['approved_at']
                && !$row['reschedule_required'] && in_array($row['rush_status'],['approved','not_requested'],true)
                && strcasecmp($row['email'],$writer['test_recipient_email'])===0
                && in_array(strtolower(trim($row['photographer'])),['david','david cro','david j cro','david j. cro'],true),'Payment, review, recipient or scheduling approval does not qualify.');return true;
        });
        if($claim&&$writer)ca_check($failures,'Saved calendar matches configuration',static function()use($claim,$writer):bool{
            ca_need($claim['calendar_uid']===$writer['calendar_uid'],'Saved calendar differs from configured calendar.');return true;
        });
        if($claim&&$row)$expected=ca_check($failures,'Arrival window, reviewed duration and original payload',static function()use($row,$claim,$reference):array{
            $e=json_decode($claim['event_json'],true,32,JSON_THROW_ON_ERROR);$r=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);
            $a=$row['appointment_json']?json_decode($row['appointment_json'],true,16,JSON_THROW_ON_ERROR):$r['appointment'];
            $window=booking_calendar_date($a['date'])->setTime((int)substr($a['time'],0,2),(int)substr($a['time'],3,2))->getTimestamp();
            $start=(int)$claim['planned_start'];$end=(int)$claim['planned_end'];
            ca_need(($a['windowMinutes']??null)===120 && in_array($a['time'],['07:00','09:00','11:00','13:00','15:00','17:00'],true)
                && $window>time() && $start>time() && $start>=$window && $start<$window+7200
                && (int)$row['duration_minutes']>=15 && (int)$row['duration_minutes']<=1440 && $end-$start===(int)$row['duration_minutes']*60
                && ($e['dateandtime']['start']??'')===gmdate('Ymd\THis\Z',$start) && ($e['dateandtime']['end']??'')===gmdate('Ymd\THis\Z',$end)
                && ($e['isprivate']??null)===true && ($e['isallday']??null)===false && ($e['isrep']??null)===false
                && ($e['transparency']??null)===0 && ($e['calendar_alarm']??null)===false && ($e['notify_attendee']??null)===0
                && ($e['conference']??null)==='none' && ($e['allowForwarding']??null)===false && str_contains($e['title']??'',$reference),'Saved schedule or privacy controls differ.');
            cd_creation_event($e);echo 'Arrival window: '.$a['date'].' '.$a['time'].'–'.(new DateTimeImmutable('@'.($window+7200)))->setTimezone(new DateTimeZone('America/Chicago'))->format('H:i')." America/Chicago\n";return $e;
        });
        if($db&&$claim)ca_check($failures,'Other local booking conflicts',static function()use($db,$claim,$reference):bool{
            $q=$db->prepare('SELECT reference FROM booking_confirmations WHERE reference<>? AND planned_start<? AND planned_end>?');
            $q->execute([$reference,$claim['planned_end'],$claim['planned_start']]);$rows=$q->fetchAll();ca_need(!$rows,'Another booking claims this interval.');return true;
        });
        foreach($configs as $name=>$cfg){
            if(!$cfg)continue;
            $token=ca_check($failures,ucfirst($name).' authorization',static function()use($transport,$cfg):string{
                $r=$transport('POST','https://accounts.zoho.com/oauth/v2/token',['grant_type'=>'refresh_token','client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'refresh_token'=>$cfg['refresh_token']],null);
                $t=$r['body']['access_token']??null;ca_need(($r['status']??0)===200 && is_string($t) && $t!=='' && !preg_match('/[^\x21-\x7e]/',$t),'Token refresh failed.');return $t;
            });
            if(!$token||!$claim)continue;
            $secrets=[$cfg['client_id'],$cfg['client_secret'],$cfg['refresh_token'],$token];
            $get=static function(string $path)use($transport,$token,$secrets,$name):array{
                $reply=$transport('GET','https://calendar.zoho.com'.$path,null,$token);
                echo ucfirst($name).' GET: '.json_encode(cd_summary($reply,$secrets))."\n";return $reply;
            };
            $detail=ca_check($failures,ucfirst($name).' direct event lookup',static function()use($get,$cfg,$claim):array{
                $r=$get(booking_calendar_event_path($cfg['calendar_uid'],$claim['event_uid']));
                ca_need(($r['status']??0)===200 && is_array($r['body']??null),'Event details were not returned.');return $r;
            });
            if($detail&&$expected)ca_check($failures,ucfirst($name).' event identity, privacy and interval',static function()use($detail,$expected,$cfg,$claim):bool{
                cd_verify($detail,$expected,$cfg['calendar_uid'],$claim['event_uid']);return true;
            });
            if($detail)ca_check($failures,ucfirst($name).' event guests, reminders and conference',static function()use($detail):bool{
                $e=$detail['body']['events'][0]??[];
                ca_need(empty($e['group_attendees']) && empty($e['group_list']) && empty($e['reminders']) && empty($e['alarm'])
                    && ($e['calendar_alarm']??null)===false && in_array($e['conference']??'none',['','none'],true),'Unexpected guests, reminders or conference settings.');return true;
            });
            $list=ca_check($failures,ucfirst($name).' complete calendar list (±36 hours)',static function()use($get,$cfg,$claim):array{
                $reply=[];booking_calendar_read_busy(static function($p,$q)use($get,&$reply):array{$reply=$get($p.'?'.http_build_query($q));return $reply;},
                    $cfg['calendar_uid'],['start'=>(int)$claim['planned_start']-129600,'end'=>(int)$claim['planned_end']+129600]);return $reply;
            });
            if(!$list)continue;
            if($name==='writer')$remoteCheckedAt=time();
            $records=$list['body']['events'];
            ca_check($failures,ucfirst($name).' unique saved event in listing',static function()use($records,$claim):bool{
                $own=array_filter($records,static fn($e)=>($e['uid']??null)===$claim['event_uid']);ca_need(count($own)===1,'Saved event is not uniquely visible in the calendar list.');return true;
            });
            ca_check($failures,ucfirst($name).' duplicate booking markers',static function()use($records,$claim,$reference):bool{
                foreach($records as $e)if(($e['uid']??null)!==$claim['event_uid'])ca_need(!str_contains(strtoupper(($e['title']??'').' '.($e['description']??'')),$reference),'A second event carries this booking reference.');return true;
            });
            ca_check($failures,ucfirst($name).' remote scheduling conflicts',static function()use($list,$cfg,$claim):bool{
                $list['body']['events']=array_values(array_filter($list['body']['events'],static fn($e)=>($e['uid']??null)!==$claim['event_uid']));
                $s=booking_calendar_read_busy(static fn($p,$q)=>$list,$cfg['calendar_uid'],['start'=>(int)$claim['planned_start'],'end'=>(int)$claim['planned_end']]);
                ca_need(!$s['busy'],'Another calendar event overlaps the shoot.');return true;
            });
        }
        if(!$row||!$claim||!$expected||!$reader||!$writer||!$lock)$failures[]='Required dependent checks unavailable';
        if($failures){echo 'RESULT: BLOCKED. Failed checks: '.implode('; ',$failures)."\nNo booking changes made.\n";return false;}
        if(!$reconcile){echo "RESULT: ALL CHECKS PASSED. Read-only; no booking changes made.\n";return true;}
        $saved=ca_check($failures,'Final consistency check and local confirmation',static function()use($db,$reference,$row,$claim,$hashes,$remoteCheckedAt):bool{
            $db->exec('BEGIN IMMEDIATE');
            try{
                foreach($hashes as $p=>$hash)ca_need(is_string($hash)&&hash_equals($hash,(string)@hash_file('sha256',$p)),'A deployed file or configuration changed during checks.');
                ca_need(cd_booking($db,$reference)===$row && cd_claim($db,$reference)===$claim,'Booking changed during checks.');
                ca_need($remoteCheckedAt>0 && time()-$remoteCheckedAt<=30 && (int)$claim['planned_start']>time(),'Calendar evidence is no longer fresh.');
                $q=$db->prepare('SELECT reference FROM booking_confirmations WHERE reference<>? AND planned_start<? AND planned_end>?');
                $q->execute([$reference,$claim['planned_end'],$claim['planned_start']]);ca_need(!$q->fetchAll(),'A competing local booking appeared.');
                if($claim['state']!=='confirmed'){
                    $q=$db->prepare("UPDATE booking_confirmations SET state='confirmed',confirmed_at=? WHERE reference=? AND state='uncertain' AND event_uid=? AND invitation_state='none'");
                    $q->execute([gmdate('c'),$reference,$claim['event_uid']]);ca_need($q->rowCount()===1,'Confirmation changed before saving.');
                    cd_audit($db,$reference,'calendar_confirmed',['event_uid'=>$claim['event_uid'],'method'=>'complete_existing_event_audit_v1']);
                }
                $db->exec('COMMIT');return true;
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        });
        if(!$saved){echo "RESULT: BLOCKED. Confirmation was not changed.\n";return false;}
        echo "RESULT: CONFIRMED. Existing event verified; booking confirmed.\nCalendar creations: 0. Invitations: 0. Payment calls: 0.\n";return true;
    } finally {if(is_resource($lock))fclose($lock);}
}

if(PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{
        ca_need(in_array(count($argv),[2,3],true) && in_array($argv[2]??'',['','--reconcile-existing'],true),'Usage: audit-calendar-confirmation.php REFERENCE [--reconcile-existing]');
        $root=is_dir(dirname(__DIR__).'/_private/server')?dirname(__DIR__).'/_private':dirname(__DIR__);
        $uid=function_exists('posix_geteuid')?posix_geteuid():null;
        if($uid===null&&preg_match('/^Uid:\s+\d+\s+(\d+)/m',(string)@file_get_contents('/proc/self/status'),$m))$uid=(int)$m[1];
        ca_need($uid===fileowner($root),'Run as the sitesee account.');exit(ca_run($root,$argv[1],isset($argv[2]))?0:1);
    }catch(Throwable $e){echo "STOP: Audit could not start safely. No calendar creation or invitation was attempted.\n";exit(1);}
}elseif(PHP_SAPI!=='cli'){http_response_code(404);exit;}
