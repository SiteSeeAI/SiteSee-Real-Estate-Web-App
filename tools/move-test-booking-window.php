<?php
declare(strict_types=1);
/** CLI-only, explicit move of a paid TEST request that has no calendar attempt. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function mw_need(bool $ok, string $message): void { if (!$ok) throw new InvalidArgumentException($message); }
function mw_row(PDO $db, string $reference): array
{
    $q=$db->prepare('SELECT * FROM bookings WHERE reference=?');$q->execute([$reference]);
    return $q->fetch() ?: [];
}
function mw_schedule(PDO $db, string $reference): array
{
    $q=$db->prepare('SELECT * FROM booking_scheduling WHERE reference=?');$q->execute([$reference]);
    return $q->fetch() ?: [];
}
function mw_apply(PDO $db, string $reference, string $date, string $from, string $to,
    array $config, callable $readBusy, DateTimeImmutable $now): string
{
    mw_need((bool)preg_match('/^[A-F0-9]{10,32}$/D',$reference),'Invalid booking reference.');
    mw_need(($config['confirmation_stage']??'')==='test' && ($config['enabled']??false)===true
        && ($config['confirmation_enabled']??false)===true,'The existing TEST calendar controls must be enabled.');
    $row=mw_row($db,$reference);$schedule=mw_schedule($db,$reference);
    mw_need((bool)$row && (bool)$schedule,'Existing booking or scheduling record is missing.');
    mw_need($row['status']==='deposit_paid_test' && $row['checkout_state']==='paid' && !empty($row['deposit_paid_at'])
        && strcasecmp($row['email'],(string)($config['test_recipient_email']??''))===0,'Only a paid booking for the configured TEST recipient may be moved.');
    mw_need($schedule['rush_status']==='not_requested' && (int)$schedule['rush_fee_cents']===0
        && (int)$schedule['reschedule_required']===0,'This helper handles standard TEST requests only.');
    $request=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);
    $current=$schedule['appointment_json'] ? json_decode($schedule['appointment_json'],true,32,JSON_THROW_ON_ERROR) : $request['appointment'];
    $prior=$db->prepare("SELECT detail_json FROM booking_schedule_events WHERE reference=? AND action='test_window_moved' ORDER BY id DESC LIMIT 1");
    $prior->execute([$reference]);$recorded=json_decode($prior->fetchColumn() ?: '{}',true);
    if (($current['date']??'')===$date && ($current['time']??'')===$to
        && ($recorded['date']??'')===$date && ($recorded['from']??'')===$from && ($recorded['to']??'')===$to) return 'Already moved; no changes made.';
    mw_need(($current['date']??'')===$date && ($current['time']??'')===$from
        && ($current['windowMinutes']??0)===120 && $from!==$to,'The current arrival window differs. Nothing was changed.');
    mw_need(!empty($row['approved_at']) && (int)$row['duration_minutes']>=15 && (int)$row['duration_minutes']<=1440,'An existing staff review is required.');
    $claim=$db->prepare('SELECT reference FROM booking_confirmations WHERE reference=?');$claim->execute([$reference]);
    mw_need(!$claim->fetchColumn(),'This booking already has a calendar attempt. No change is allowed.');
    $window=real_estate_validate_lead_time(real_estate_arrival_window(real_estate_validate_appointment(['date'=>$date,'time'=>$to],$now)),['rushRequested'=>false],$now);
    $snapshot=$readBusy(booking_availability_range($date,(int)$row['duration_minutes'],1));
    $db->exec('BEGIN IMMEDIATE');
    try {
        mw_need(mw_row($db,$reference)===$row && mw_schedule($db,$reference)===$schedule,'The booking changed during the calendar check. Run the check again.');
        $claim->execute([$reference]);mw_need(!$claim->fetchColumn(),'A calendar attempt appeared. Nothing was changed.');
        foreach($db->query('SELECT planned_start,planned_end FROM booking_confirmations')->fetchAll() as $other)
            $snapshot['busy'][]=[(int)$other['planned_start'],(int)$other['planned_end']];
        $windows=booking_available_windows($date,false,(int)$row['duration_minutes'],$snapshot,$now,1);
        $matches=array_values(array_filter($windows,static fn($w)=>$w['time']===$to));
        mw_need(count($matches)===1,'The proposed window is blocked. Nothing was changed.');
        $new=array_replace($current,$window);
        $utc=(new DateTimeImmutable($date.' '.$to,new DateTimeZone('America/Chicago')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $db->prepare('UPDATE booking_scheduling SET appointment_json=? WHERE reference=?')->execute([json_encode($new,JSON_THROW_ON_ERROR),$reference]);
        $db->prepare('UPDATE bookings SET requested_utc=?,approved_at=NULL,availability_checked_at=NULL WHERE reference=?')->execute([$utc,$reference]);
        $expected=$row;$expected['requested_utc']=$utc;$expected['approved_at']=null;$expected['availability_checked_at']=null;
        mw_need(mw_row($db,$reference)===$expected,'Payment or booking preservation check failed; changes rolled back.');
        $expectedSchedule=$schedule;$expectedSchedule['appointment_json']=json_encode($new,JSON_THROW_ON_ERROR);
        mw_need(mw_schedule($db,$reference)===$expectedSchedule,'Scheduling preservation check failed; changes rolled back.');
        $audit=$db->prepare('INSERT INTO booking_schedule_events (reference,action,recorded_at,detail_json) VALUES (?,?,?,?)');
        $at=$now->setTimezone(new DateTimeZone('UTC'))->format('c');
        $audit->execute([$reference,'replacement_window_requested',$at,json_encode($window,JSON_THROW_ON_ERROR)]);
        $audit->execute([$reference,'test_window_moved',$at,json_encode(['date'=>$date,'from'=>$from,'to'=>$to,
            'customer_agreement'=>'operator-selected TEST window','review_reset'=>true],JSON_THROW_ON_ERROR)]);
        $db->exec('COMMIT');
    } catch(Throwable $e) { $db->exec('ROLLBACK');throw $e; }
    return 'Arrival window updated; staff review is required again. Deposit and payment identifiers are unchanged.';
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');$lock=null;$stage='startup';
    try {
        mw_need(count($argv)===6 && $argv[5]==='--apply','Usage: move-test-booking-window.php REFERENCE DATE OLD_START NEW_START --apply');
        $root='/home/sitesee/.sitesee-real-estate';
        $uid=function_exists('posix_geteuid')?posix_geteuid():null;
        mw_need($uid===0 || $uid===fileowner($root),'Run in WHM Terminal as root or as sitesee.');
        require_once $root.'/server/booking-calendar-client.php';
        require_once $root.'/real-estate-pricing.php';
        $stage='read TEST calendar configuration';
        $config=booking_calendar_config($root.'/zoho-confirmation.json');
        $reader=booking_calendar_config($root.'/zoho-calendar.json');
        mw_need($reader['enabled'] && $reader['calendar_uid']===$config['calendar_uid']
            && ($reader['calendar_owner_id']??'')===($config['calendar_owner_id']??''),'The two calendar identities must match.');
        $stage='lock confirmation and open existing ledger';
        foreach(['booking-confirmation.lock','data/bookings.sqlite'] as $file){
            $info=lstat($root.'/'.$file);
            mw_need((bool)$info && ($info['mode']&0170000)===0100000 && ($info['mode']&0077)===0
                && $info['uid']===fileowner($root) && $info['nlink']===1,'Private file ownership or permissions differ.');
        }
        $lock=fopen($root.'/booking-confirmation.lock','rb');
        mw_need((bool)$lock && flock($lock,LOCK_EX|LOCK_NB),'Another calendar action is running; try again later.');
        $db=new PDO('sqlite:file:'.$root.'/data/bookings.sqlite?mode=rw',null,null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5]);
        $readBusy=static function(array $range)use($config):array{
            $reply=booking_zoho_request('https://accounts.zoho.com/oauth/v2/token',[
                'grant_type'=>'refresh_token','client_id'=>$config['client_id'],
                'client_secret'=>$config['client_secret'],'refresh_token'=>$config['refresh_token']]);
            $token=$reply['body']['access_token']??null;
            mw_need(($reply['status']??0)===200 && is_string($token) && $token!=='' && !preg_match('/[^\x21-\x7e]/',$token)
                && !isset($reply['body']['error']) && !isset($reply['body']['errors']),'Fresh calendar authorization failed.');
            return booking_calendar_read_busy(static fn($p,$q)=>booking_zoho_request('https://calendar.zoho.com'.$p.'?'.http_build_query($q),null,$token),$config['calendar_uid'],$range);
        };
        $stage='validate and move unconfirmed TEST request';
        $result=mw_apply($db,$argv[1],$argv[2],$argv[3],$argv[4],$config,$readBusy,new DateTimeImmutable('now'));
        echo "FINAL RESULTS\nBooking: ".$argv[1]."\n".$result."\nRequested: ".$argv[2].' '.$argv[4]." Central\n";
        echo "No Stripe call, calendar write, invitation, email, credential change or change to another booking was made.\n";
        echo "Next: reopen this booking, save its staff review, and then confirm the TEST appointment.\n";
    }catch(Throwable $e){
        $message=$e instanceof InvalidArgumentException ? $e->getMessage() : 'The operation could not complete safely.';
        fwrite(STDERR,'STOP at '.$stage.': '.$message."\n");exit(1);
    }finally{if(is_resource($lock))fclose($lock);}
}
