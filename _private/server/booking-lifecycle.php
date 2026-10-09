<?php
declare(strict_types=1);
require_once __DIR__.'/booking-workflow.php';
require_once __DIR__.'/booking-job.php';

function booking_lifecycle_enabled(): bool
{
    $p=dirname(__DIR__).'/booking-lifecycle.json';
    if(!is_file($p))return false;
    $c=booking_ms_private_json($p);
    $expected=['schema'=>1,'stage'=>'test','enabled'=>true,'recipient'=>'sales@re.sitesee.ai'];
    return count($c)===count($expected) && array_replace($expected,$c)===$expected;
}
function booking_lifecycle_row(PDO $db,string $reference): array
{
    $row=booking_workflow_row($db,$reference);
    $c=booking_confirmation_get($db,$reference);
    if(!$c||$c['state']!=='confirmed'||!$c['event_uid'])throw new InvalidArgumentException('A verified existing appointment is required.');
    return $row;
}
function booking_lifecycle_fingerprint(PDO $db,string $reference): string
{
    $s=booking_lifecycle_state($db,$reference);
    $claim=booking_confirmation_get($db,$reference);
    // Observing the unchanged active event must not invalidate a saved manager request.
    $actualStart=$s['actual_start']??($s['state']==='active'?($claim['planned_start']??null):null);
    $actualEnd=$s['actual_end']??($s['state']==='active'?($claim['planned_end']??null):null);
    return hash('sha256',json_encode([booking_get($db,$reference),$claim,
        $s['state'],(int)$s['revision'],$actualStart===null?null:(int)$actualStart,$actualEnd===null?null:(int)$actualEnd],JSON_THROW_ON_ERROR));
}
function booking_management_issue(PDO $db,string $reference): string
{
    booking_lifecycle_row($db,$reference);
    if(booking_lifecycle_state($db,$reference)['state']==='cancelled')throw new InvalidArgumentException('This appointment is cancelled.');
    $token=bin2hex(random_bytes(32));
    booking_lifecycle_set($db,$reference,['token_hash'=>hash('sha256',$token),'token_expires'=>time()+7*86400]);
    // Fragment secrets never enter HTTP request URLs, server logs, or Referer headers.
    return SITESEE_REAL_ESTATE_SITE_URL.'/manage-appointment.php#'.$reference.'.'.$token;
}
/** Reuse only an unexpired, unrevoked link from this booking's private saved notices. */
function booking_management_notice_link(PDO $db,string $reference): string
{
    $q=$db->prepare('SELECT message_json FROM booking_communications WHERE reference=? ORDER BY created_at DESC');
    $q->execute([$reference]);
    $prefix=SITESEE_REAL_ESTATE_SITE_URL.'/manage-appointment.php#'.$reference.'.';
    foreach($q->fetchAll() as $saved){
        $message=json_decode($saved['message_json'],true,64,JSON_THROW_ON_ERROR);
        if(preg_match('~'.preg_quote($prefix,'~').'([a-f0-9]{64})(?![a-f0-9])~D',$message['plain']??'',$match)
            && booking_management_auth($db,$reference,$match[1]))return $prefix.$match[1];
    }
    return booking_management_issue($db,$reference);
}
function booking_management_auth(PDO $db,string $reference,string $token): bool
{
    if(!preg_match('/^[A-F0-9]{10,32}$/D',$reference)||!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
    $s=booking_lifecycle_state($db,$reference);
    return (int)$s['token_expires']>=time()&&is_string($s['token_hash'])&&hash_equals($s['token_hash'],hash('sha256',$token));
}
/** This transport can change only one stored private event, using its fresh version. No creation or mail. */
function booking_lifecycle_ms_transport(array $secret,string $id,?callable $http=null): Closure
{
    $http??='booking_provider_http';$authorization=null;
    $read=booking_scheduling_ms_transport($secret,static function($method,$url,$headers,$body)use($http,&$authorization){
        $r=$http($method,$url,$headers,$body);
        if(str_contains($url,'/oauth2/v2.0/token')&&($r['status']??0)===200)$authorization=$r['body']['access_token']??null;
        return $r;
    });
    $target=booking_ms_event_path($id);
    return static function(string $method,string $path,?array $body=null,?string $etag=null)use($read,$http,$target,&$authorization):array{
        if($method==='GET'&&$body===null&&$etag===null)return $read($method,$path);
        booking_ms_need(in_array($method,['PATCH','DELETE'],true)&&$path===$target&&is_string($etag)
            &&(bool)preg_match('/^(?:W\/)?"[A-Za-z0-9+\/=._-]{1,512}"$/D',$etag),'Unsafe or unversioned appointment change refused.');
        if($method==='PATCH'){
            booking_ms_need(is_array($body)&&array_keys($body)===['start','end','body'],'Only the saved appointment interval and description may change.');
            $duration=booking_ms_timestamp($body['end'])-booking_ms_timestamp($body['start']);
            booking_ms_need($duration>=900&&$duration<=86400&&($body['body']['contentType']??'')==='text'
                &&is_string($body['body']['content']??null),'Invalid appointment change.');
        }else booking_ms_need($body===null,'Deletion must not carry a payload.');
        return $http($method,'https://graph.microsoft.com'.$target,['Authorization: Bearer '.$authorization,
            'Prefer: IdType="ImmutableId", outlook.timezone="UTC"','If-Match: '.$etag,
            'Content-Type: application/json','Accept: application/json'],$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
    };
}
function booking_lifecycle_connection(array $claim): callable
{
    $c=booking_scheduling_config($claim);
    if(!booking_scheduling_is_microsoft($c))return booking_confirmation_connection($c);
    $connection=booking_ms_private_json(dirname(__DIR__).'/microsoft-calendar.json');booking_ms_validate_config($connection);
    return booking_lifecycle_ms_transport(booking_ms_private_json($connection['graph_credentials']),$claim['event_uid']);
}
function booking_lifecycle_missing_reply(array $r): bool
{
    return ($r['status']??0)===404&&($r['body']['error']['code']??'')==='ErrorItemNotFound';
}
/** A completed staff cancellation must prove the same legacy identity before 404 can mean absent. */
function booking_lifecycle_verified_legacy_cancel(PDO $db,array $claim): bool
{
    if(str_starts_with($claim['calendar_uid'],'microsoft:'))return false;
    $state=booking_lifecycle_state($db,$claim['reference']);
    if($state['state']!=='cancelled')return false;
    $q=$db->prepare("SELECT payload_json FROM booking_lifecycle_operations WHERE reference=? AND revision=? AND state='applied' AND action='cancel' AND actor='staff'");
    $q->execute([$claim['reference'],$state['revision']]);$raw=$q->fetchColumn();
    if(!is_string($raw))return false;
    $saved=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    return ($saved['legacy_deleted']??false)===true
        && ($saved['row']['reference']??null)===$claim['reference']
        && ($saved['claim']['event_uid']??null)===$claim['event_uid']
        && ($saved['claim']['calendar_uid']??null)===$claim['calendar_uid'];
}
/** Read an immutable ID at both calendar and mailbox scope before believing it disappeared. */
function booking_lifecycle_observe(array $claim,callable $api,bool $verifiedLegacyCancellation=false): array
{
    $expected=json_decode($claim['event_json'],true,32,JSON_THROW_ON_ERROR);
    if(str_starts_with($claim['calendar_uid'],'microsoft:')){
        booking_ms_need($claim['calendar_uid']==='microsoft:'.BOOKING_MS_CALENDAR,'Saved Microsoft calendar identity differs.');
        booking_ms_verify_calendar($api);
        $r=$api('GET',booking_ms_event_path($claim['event_uid']));
        if(booking_lifecycle_missing_reply($r)){
            $mailbox=$api('GET',booking_ms_mailbox_event_path($claim['event_uid']));
            if(booking_lifecycle_missing_reply($mailbox))return ['missing'=>true];
            throw new BookingCalendarUnavailable('Event moved to another calendar or cannot be verified. Staff must review its location.');
        }
        $e=booking_ms_ok($r,'Appointment read');
        booking_ms_need(($e['id']??'')===$claim['event_uid'],'Appointment identity differs.');
        $actual=$expected;$actual['start']=$e['start']??[];$actual['end']=$e['end']??[];
        booking_scheduling_ms_verify($r,$actual);
        $start=booking_ms_timestamp($actual['start']);$end=booking_ms_timestamp($actual['end']);
        booking_ms_need($end>$start,'Appointment interval is invalid.');
        return ['missing'=>false,'start'=>$start,'end'=>$end,'expected'=>$actual,'etag'=>$e['@odata.etag']??'',
            'unchanged'=>$start===(int)$claim['planned_start']&&$end===(int)$claim['planned_end']];
    }
    // Legacy Zoho event identities remain on Zoho. Existing credentials are read/create only.
    $r=$api('GET',booking_calendar_event_path($claim['calendar_uid'],$claim['event_uid']));
    if($verifiedLegacyCancellation && ($r['status']??0)===404 && is_array($r['body']??null) && $r['body']){
        // A working, complete calendar read is still mandatory; a denied/outage response is not deletion.
        booking_calendar_read_busy(static fn($path,$query)=>$api('GET',$path.'?'.http_build_query($query)),$claim['calendar_uid'],
            ['start'=>(int)$claim['planned_start']-86400,'end'=>(int)$claim['planned_end']+86400]);
        return ['missing'=>true];
    }
    $items=$r['body']['events']??null;
    if(($r['status']??0)===200&&is_array($items)&&count($items)===1&&($items[0]['estatus']??'')==='deleted'
        &&($items[0]['uid']??'')===$claim['event_uid']&&($items[0]['caluid']??'')===$claim['calendar_uid'])return ['missing'=>true];
    if(($r['status']??0)!==200||!is_array($items)||count($items)!==1)throw new BookingCalendarUnavailable('Legacy Zoho event absence is not proven. Keep its hold for staff review.');
    $e=$items[0];$actual=$expected;$actual['dateandtime']=$e['dateandtime']??[];
    booking_confirmation_verify($r,$actual,$claim['calendar_uid']);
    if(($e['uid']??'')!==$claim['event_uid']||!empty($e['rrule'])||!empty($e['repeat']))throw new BookingCalendarUnavailable('Legacy appointment identity or recurrence differs.');
    $zone=new DateTimeZone($actual['dateandtime']['timezone']??'UTC');
    $start=booking_calendar_timestamp($actual['dateandtime']['start'],$zone,false);$end=booking_calendar_timestamp($actual['dateandtime']['end'],$zone,false);
    return ['missing'=>false,'start'=>$start,'end'=>$end,'expected'=>$actual,'etag'=>(string)($e['etag']??''),
        'unchanged'=>$start===(int)$claim['planned_start']&&$end===(int)$claim['planned_end']];
}
/** Called with the shared calendar lock. Two independent missing observations are required. */
function booking_lifecycle_sync_locked(PDO $db,string $reference,callable $api,int $now): array
{
    $claim=booking_confirmation_get($db,$reference);$s=booking_lifecycle_state($db,$reference);
    if(!$claim||$claim['state']!=='confirmed'||!$claim['event_uid']||booking_lifecycle_pending($db,$reference))return $s;
    $o=booking_lifecycle_observe($claim,$api,booking_lifecycle_verified_legacy_cancel($db,$claim));
    if($s['state']==='cancelled'){
        if($o['missing'])booking_lifecycle_set($db,$reference,['checked_at'=>$now,'diagnostic'=>null]);
        if(!$o['missing'])booking_lifecycle_set($db,$reference,['state'=>'calendar_changed','actual_start'=>$o['start'],'actual_end'=>$o['end'],
            'checked_at'=>$now,'diagnostic'=>'A cancelled event reappeared. Staff review required.']);
        return booking_lifecycle_state($db,$reference);
    }
    if($o['missing']){
        $since=$s['missing_since']===null?$now:(int)$s['missing_since'];
        $state=$now-$since>=60?'calendar_missing':$s['state'];
        booking_lifecycle_set($db,$reference,['state'=>$state,'missing_since'=>$since,'checked_at'=>$now,
            'diagnostic'=>$state==='calendar_missing'?'Calendar deletion verified. Customer cancellation or replacement still needs confirmation.':'Calendar event not found; waiting for an independent second read.']);
    }else{
        $state=$o['unchanged']?'active':'calendar_changed';
        booking_lifecycle_set($db,$reference,['state'=>$state,'actual_start'=>$o['start'],'actual_end'=>$o['end'],
            'missing_since'=>null,'checked_at'=>$now,'diagnostic'=>$o['unchanged']?null:'Calendar moved manually. The customer arrival window has not changed.']);
    }
    $new=booking_lifecycle_state($db,$reference);
    if($new['state']!==$s['state']||$new['actual_start']!==$s['actual_start']||$new['actual_end']!==$s['actual_end'])
        booking_schedule_event($db,$reference,'calendar_lifecycle_observed',['state'=>$new['state'],'start'=>$new['actual_start'],'end'=>$new['actual_end']]);
    return $new;
}
function booking_lifecycle_sync(PDO $db,string $reference,array $deps=[]): array
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        booking_lifecycle_row($db,$reference);$claim=booking_confirmation_get($db,$reference);
        $api=$deps['calendar']??booking_lifecycle_connection($claim);
        if(booking_lifecycle_pending($db,$reference))return booking_lifecycle_recover_locked($db,$reference,$api);
        return booking_lifecycle_sync_locked($db,$reference,$api,$deps['now']??time());
    }finally{fclose($lock);}
}
/** Provider busy intervals exclude this exact event only; unrelated overlapping blocks stay. */
function booking_lifecycle_windows(PDO $db,string $reference,string $date,array $deps=[]): array
{
    $row=booking_lifecycle_row($db,$reference);$claim=booking_confirmation_get($db,$reference);
    if(booking_lifecycle_pending($db,$reference))throw new InvalidArgumentException('An earlier change needs recovery first.');
    $s=booking_lifecycle_state($db,$reference);
    if($s['state']==='cancelled')throw new InvalidArgumentException('This appointment is cancelled.');
    $now=$deps['now']??time();$day=booking_calendar_date($date);
    $today=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('America/Chicago'))->setTime(0,0);
    if($day<$today||$day>$today->modify('+365 days'))throw new InvalidArgumentException('Choose a date within the next year.');
    $api=$deps['calendar']??booking_lifecycle_connection($claim);
    if(!str_starts_with($claim['calendar_uid'],'microsoft:'))throw new InvalidArgumentException('This legacy appointment stays on Zoho. Staff must move it there and use Adopt Calendar Move.');
    booking_ms_verify_calendar($api);
    $range=booking_availability_range($date,(int)$row['duration_minutes'],14);
    $view=booking_ms_view($api,$range,'id,start,end,showAs,isCancelled,type');
    $snapshot=$range+['busy'=>[]];
    foreach($view as$e){
        if($e['id']===$claim['event_uid'])continue;
        booking_ms_need(is_bool($e['isCancelled']??null)&&in_array($e['type']??'',['singleInstance','occurrence','exception'],true)
            &&in_array($e['showAs']??'',['free','busy','tentative','oof','workingElsewhere','unknown'],true),'Calendar coverage is incomplete.');
        if($e['isCancelled']||$e['showAs']==='free')continue;
        $snapshot['busy'][]=[booking_ms_timestamp($e['start']),booking_ms_timestamp($e['end'])];
    }
    $snapshot=booking_lifecycle_busy($db,$snapshot,$reference);
    return array_slice(booking_available_windows($date,$row['rush_status']==='approved',(int)$row['duration_minutes'],$snapshot,
        new DateTimeImmutable('@'.$now),14),0,24);
}
function booking_lifecycle_message(array $row,array $claim,int $revision,string $action,string $managementLink = ''): array
{
    $m=booking_invitation_message($row,$claim);$cancel=$action==='cancel';
    $method=$cancel?'CANCEL':'REQUEST';
    $plain='This is a SiteSee TEST appointment. No live payment has been collected.' . "\n\n"
        .($cancel?'Your appointment is cancelled.':'Your appointment arrival window has been updated to '.booking_request($row)['appointment']['date'].' '.booking_request($row)['appointment']['time'].'–'.booking_request($row)['appointment']['windowEnd'].' Central Time.')
        ."\nReference: ".$row['reference']."\nProperty: ".booking_confirmation_property(booking_request($row)['details'])
        ."\nPayment records are unchanged. This notice does not issue a refund or determine any cancellation fee."
        .( !$cancel && $managementLink !== '' ? "\n\nManage your appointment securely: ".$managementLink."\nThis private link expires seven days after it was issued. Contact SiteSee for a replacement." : '')
        ."\nFor help, reply to this message.";
    $ical=preg_replace_callback('/DESCRIPTION:.*?(?=\r\nORGANIZER;)/s',static fn()=>booking_ical_fold('DESCRIPTION:'.booking_ical_text($plain)),$m['ical']);
    $ical=str_replace(['METHOD:REQUEST','SEQUENCE:0','STATUS:CONFIRMED','PARTSTAT=NEEDS-ACTION;RSVP=TRUE'],
        ['METHOD:'.$method,'SEQUENCE:'.$revision,$cancel?'STATUS:CANCELLED':'STATUS:CONFIRMED',$cancel?'PARTSTAT=DECLINED;RSVP=FALSE':'PARTSTAT=NEEDS-ACTION;RSVP=TRUE'],$ical);
    $ical=preg_replace('/DTSTAMP:[^\r\n]+/','DTSTAMP:'.gmdate('Ymd\THis\Z'),$ical);
    $subject=booking_property_subject('[TEST] SiteSee Appointment '.($cancel?'Cancellation':'Updated'),booking_request($row)['details']);
    $html='<p>'.nl2br(htmlspecialchars($plain,ENT_QUOTES,'UTF-8')).'</p>';
    $boundary='sitesee_change_'.bin2hex(random_bytes(16));$body='';
    foreach([['text/plain; charset=UTF-8',$plain],['text/html; charset=UTF-8',$html],['text/calendar; charset=UTF-8; method='.$method.'; name="arrival-window.ics"',$ical]]as[$type,$part]){
        $body.='--'.$boundary."\r\nContent-Type: ".$type."\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($part),76,"\r\n");
    }
    $body.='--'.$boundary."--\r\n";
    return ['to'=>$row['email'],'from'=>BOOKING_MAIL_SENDER,'subject'=>$subject,'plain'=>$plain,'html'=>$html,'ical'=>$ical,'body'=>$body,
        'headers'=>['From: SiteSee Real Estate <'.BOOKING_MAIL_SENDER.'>','Reply-To: '.BOOKING_MAIL_SENDER,'MIME-Version: 1.0','Content-Type: multipart/alternative; boundary="'.$boundary.'"']];
}
/** Commit only after provider readback proves the target state. */
function booking_lifecycle_finish(PDO $db,array $op): void
{
    $p=json_decode($op['payload_json'],true,64,JSON_THROW_ON_ERROR);$ref=$op['reference'];$cancel=$op['action']==='cancel';
    $db->exec('BEGIN IMMEDIATE');
    try{
        $pending=booking_lifecycle_pending($db,$ref);
        if(!$pending||$pending['operation_id']!==$op['operation_id'])throw new RuntimeException('Change state differs.');
        if(booking_get($db,$ref)!==$p['row']||booking_confirmation_get($db,$ref)!==$p['claim'])throw new RuntimeException('Booking changed during the operation. Staff recovery required.');
        if(!$cancel){
            $db->prepare('UPDATE booking_scheduling SET appointment_json=? WHERE reference=?')->execute([json_encode($p['appointment'],JSON_THROW_ON_ERROR),$ref]);
            $utc=booking_arrival_start($p['appointment']['date'],$p['appointment']['time'])->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $db->prepare('UPDATE bookings SET requested_utc=? WHERE reference=?')->execute([$utc,$ref]);
            $db->prepare('UPDATE booking_confirmations SET planned_start=?,planned_end=?,event_json=? WHERE reference=?')
                ->execute([$p['new_interval'][0],$p['new_interval'][1],json_encode($p['expected'],JSON_THROW_ON_ERROR),$ref]);
        }
        booking_lifecycle_set($db,$ref,['state'=>$cancel?'cancelled':'active','revision'=>(int)$op['revision'],'missing_since'=>null,
            'actual_start'=>$cancel?null:$p['new_interval'][0],'actual_end'=>$cancel?null:$p['new_interval'][1],
            'checked_at'=>time(),'diagnostic'=>null]);
        $db->prepare("UPDATE booking_lifecycle_operations SET state='applied',completed_at=? WHERE operation_id=?")->execute([gmdate('c'),$op['operation_id']]);
        if(isset($p['approval_request'])){
            $q=$db->prepare("UPDATE booking_change_requests SET state='approved',resolved_at=? WHERE request_id=? AND reference=? AND state='applying' AND operation_id=?");
            $q->execute([gmdate('c'),$p['approval_request'],$ref,$op['operation_id']]);
            if($op['actor']!=='staff'||$q->rowCount()!==1)throw new RuntimeException('Manager approval record differs. Preserve the change for recovery.');
        }
        booking_schedule_event($db,$ref,$cancel?'appointment_cancelled':'appointment_rescheduled',['actor'=>$op['actor'],'revision'=>(int)$op['revision'],
            'old_window'=>booking_request($p['row'])['appointment'],'new_window'=>$cancel?null:$p['appointment'],
            'calendar_uid'=>$p['claim']['calendar_uid'],'event_uid'=>$p['claim']['event_uid']]);
        booking_communication_enqueue($db,$ref,'lifecycle-'.$op['revision'],booking_lifecycle_message(booking_get($db,$ref),booking_confirmation_get($db,$ref),(int)$op['revision'],$op['action'],$cancel?'':booking_management_notice_link($db,$ref)),$p['claim']);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** GET only. It never retries PATCH/DELETE or sends a message. */
function booking_lifecycle_recover_locked(PDO $db,string $reference,callable $api): array
{
    $op=booking_lifecycle_pending($db,$reference);if(!$op)return booking_lifecycle_state($db,$reference);
    $p=json_decode($op['payload_json'],true,64,JSON_THROW_ON_ERROR);
    if(!empty($p['legacy_deleted']) && $op['actor']==='staff' && $op['action']==='cancel'){
        $c=$p['claim'];$r=$api('GET',booking_calendar_event_path($c['calendar_uid'],$c['event_uid']));
        if(($r['status']??0)!==404 || !is_array($r['body']??null) || !$r['body']) throw new BookingCalendarUnavailable('Legacy absence needs review.');
        booking_calendar_read_busy(static fn($path,$query)=>$api('GET',$path.'?'.http_build_query($query)),$c['calendar_uid'],['start'=>(int)$c['planned_start']-86400,'end'=>(int)$c['planned_end']+86400]);
        booking_lifecycle_finish($db,$op);return booking_lifecycle_state($db,$reference);
    }
    $o=booking_lifecycle_observe($p['claim'],$api);
    if($op['action']==='cancel'&&$o['missing']){booking_lifecycle_finish($db,$op);return booking_lifecycle_state($db,$reference);}
    if($op['action']!=='cancel'&&!$o['missing']&&[$o['start'],$o['end']]===$p['new_interval']){
        // A fresh view verifies the same saved event and excludes late competing blocks.
        if(str_starts_with($p['claim']['calendar_uid'],'microsoft:'))booking_scheduling_ms_clear($api,$p['expected'],$p['claim']['event_uid']);
        booking_lifecycle_finish($db,$op);return booking_lifecycle_state($db,$reference);
    }
    throw new BookingCalendarUnavailable('The change is not yet verified. Both reservations remain held. Use Recover Change; no repeated calendar write or notice was sent.');
}
/** Customer requests preserve the confirmed window and event until explicit staff approval. */
function booking_change_request_create(PDO $db,string $reference,string $fingerprint,string $date,string $time,array $deps=[]): array
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        $row=booking_lifecycle_row($db,$reference);$claim=booking_confirmation_get($db,$reference);
        if(booking_job_get($db,$reference))throw new InvalidArgumentException('Onsite work is complete. Contact SiteSee for help.');
        $existing=booking_change_request_pending($db,$reference);
        if($existing){
            if($existing['state']!=='pending'||!hash_equals($existing['fingerprint'],$fingerprint)
                ||$existing['date']!==$date||$existing['time']!==$time)
                throw new InvalidArgumentException('Your saved request needs manager review before another change.');
            $request=$existing;
        }else{
            booking_lifecycle_assert_active($db,$reference);
            if(!hash_equals(booking_lifecycle_fingerprint($db,$reference),$fingerprint))throw new InvalidArgumentException('Appointment changed. Reload the current details.');
            $now=$deps['now']??time();$a=booking_request($row)['appointment'];
            if(booking_arrival_start($a['date'],$a['time'])->getTimestamp()<=$now)
                throw new InvalidArgumentException('This arrival window has started. Contact SiteSee for help.');
            if(in_array($claim['invitation_state'],['sending','uncertain'],true))throw new InvalidArgumentException('Staff must verify the original invitation first.');
            $q=$db->prepare("SELECT 1 FROM booking_communications WHERE reference=? AND kind LIKE 'lifecycle-%' AND submission_state<>'sent_observed' LIMIT 1");
            $q->execute([$reference]);if($q->fetchColumn())throw new InvalidArgumentException('Staff must verify the previous change notice first.');
            if($date===$a['date']&&$time===$a['time'])throw new InvalidArgumentException('Choose a different arrival window.');
            $api=$deps['calendar']??booking_lifecycle_connection($claim);
            $observed=booking_lifecycle_observe($claim,$api);
            if($observed['missing']||!$observed['unchanged'])throw new InvalidArgumentException('The calendar needs staff review before requesting a new window.');
            $windows=booking_lifecycle_windows($db,$reference,$date,['calendar'=>$api,'now'=>$now]);
            $matches=array_values(array_filter($windows,static fn($w)=>$w['date']===$date&&$w['time']===$time));
            if(count($matches)!==1)throw new InvalidArgumentException('That window is no longer available. Check alternatives again.');
            $request=['request_id'=>bin2hex(random_bytes(16)),'reference'=>$reference,'state'=>'pending',
                'fingerprint'=>$fingerprint,'date'=>$date,'time'=>$time,'window_end'=>$matches[0]['end_time'],'created_at'=>gmdate('c')];
            $db->exec('BEGIN IMMEDIATE');
            try{
                if(booking_job_get($db,$reference)||!hash_equals(booking_lifecycle_fingerprint($db,$reference),$fingerprint))
                    throw new InvalidArgumentException('The booking changed. Reload before requesting another window.');
                $db->prepare('INSERT INTO booking_change_requests(request_id,reference,state,fingerprint,date,time,window_end,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array_values($request));
                $key=booking_communication_enqueue($db,$reference,'change-request-'.$request['request_id'],booking_change_request_message($row,$request));
                booking_communication_update($db,$key,['crm_state'=>'not_applicable']);
                booking_schedule_event($db,$reference,'appointment_change_requested',['request_id'=>$request['request_id'],
                    'old_window'=>$a,'requested_window'=>['date'=>$date,'time'=>$time,'windowEnd'=>$request['window_end']]]);
                $db->exec('COMMIT');
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        }
    }finally{fclose($lock);}
    // A mail outage never loses the request or changes the calendar. Its saved state is visible to staff.
    try{booking_change_request_notify($db,$reference,$request['request_id'],true,$deps);}catch(Throwable){}
    return booking_change_request_get($db,$reference,$request['request_id']);
}
/** Plain staff notification, with no calendar attachment, RSVP or customer recipients. */
function booking_change_request_message(array $row,array $request): array
{
    $a=booking_request($row)['appointment'];
    $plain="A customer has requested a new TEST appointment window. Manager approval is required.\n\nReference: ".$row['reference']
        ."\nProperty: ".booking_confirmation_property(booking_request($row)['details'])
        ."\nConfirmed window: ".$a['date'].' '.$a['time'].'–'.$a['windowEnd'].' Central Time'
        ."\nRequested window: ".$request['date'].' '.$request['time'].'–'.$request['window_end'].' Central Time'
        ."\n\nThe calendar, customer RSVP and payment records have not changed. Review the saved request in Staff Bookings:\n"
        .SITESEE_REAL_ESTATE_SITE_URL.'/staff-bookings.php?reference='.rawurlencode($row['reference']);
    return ['from'=>BOOKING_MAIL_SENDER,'to'=>BOOKING_MAIL_SENDER,
        'subject'=>booking_property_subject('[TEST] Appointment Change Requested',booking_request($row)['details']),
        'plain'=>$plain,'body'=>$plain,'headers'=>['From: SiteSee Real Estate <'.BOOKING_MAIL_SENDER.'>',
            'Reply-To: '.BOOKING_MAIL_SENDER,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8']];
}
function booking_change_request_notify(PDO $db,string $reference,string $id,bool $send,array $deps=[]): array
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        booking_lifecycle_row($db,$reference);$request=booking_change_request_get($db,$reference,$id);
        if(!$request)throw new InvalidArgumentException('Saved request not found.');
        $key='change-request-'.$id.':'.$reference;$mail=booking_communication_get($db,$key);
        if(!$mail||$mail['recipient']!==BOOKING_MAIL_SENDER)throw new InvalidArgumentException('Division notification identity differs.');
        $graph=$deps['graph']??booking_graph_client(booking_mail_config($send));
        if($send&&$mail['submission_state']==='prepared')booking_communication_submit($db,$key,$graph);
        // Safe recovery of a verified unsent draft reuses its existing provider identity.
        elseif($send&&booking_communication_unsent_draft($mail))booking_communication_send_saved_draft($db,$key,$graph);
        try{booking_communication_reconcile($db,$key,$graph);}catch(Throwable){}
        if(booking_communication_get($db,$key)['submission_state']==='sent_observed'){
            try{booking_communication_delivery($db,$key,$graph);}catch(Throwable){}
        }
        return booking_communication_get($db,$key);
    }finally{fclose($lock);}
}
function booking_change_request_approve(PDO $db,string $reference,string $id,bool $agreed,array $deps=[]): array
{
    if(!$agreed)throw new InvalidArgumentException('Review and approve the requested window first.');
    booking_lifecycle_row($db,$reference);$request=booking_change_request_get($db,$reference,$id);
    if(!$request)throw new InvalidArgumentException('Saved request not found.');
    if($request['state']==='pending')booking_lifecycle_change($db,$reference,'reschedule',$request['fingerprint'],'staff',
        $request['date'],$request['time'],array_replace($deps,['approval_request'=>$id]));
    elseif($request['state']!=='approved')throw new InvalidArgumentException('This request needs recovery or is no longer awaiting approval.');
    $current=booking_change_request_get($db,$reference,$id);
    if($current['state']!=='approved')throw new InvalidArgumentException('Recover the saved calendar change before sending its notice.');
    // Only the notice belonging to this approved operation may be sent; a later revision is never replayed.
    $op=$db->prepare('SELECT revision,state FROM booking_lifecycle_operations WHERE operation_id=?');$op->execute([$current['operation_id']]);$saved=$op->fetch();
    if(!$saved||$saved['state']!=='applied'||(int)$saved['revision']!==(int)booking_lifecycle_state($db,$reference)['revision'])
        return ['notice'=>'Request already approved; no earlier invitation was resent.'];
    try{return booking_lifecycle_notice($db,$reference,true,array_replace($deps,['notice_revision'=>(int)$saved['revision']]));}catch(Throwable){return ['notice'=>'Approved window saved. The customer notice needs staff recovery; do not repeat the calendar change.'];}
}
function booking_change_request_reject(PDO $db,string $reference,string $id,bool $agreed,array $deps=[]): array
{
    if(!$agreed)throw new InvalidArgumentException('Review the request before declining it.');
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        $row=booking_lifecycle_row($db,$reference);$db->exec('BEGIN IMMEDIATE');
        try{
            $q=$db->prepare("UPDATE booking_change_requests SET state='rejected',resolved_at=? WHERE reference=? AND request_id=? AND state='pending'");
            $q->execute([gmdate('c'),$reference,$id]);if($q->rowCount()!==1)throw new InvalidArgumentException('The request is no longer awaiting review.');
            $request=booking_change_request_get($db,$reference,$id);
            booking_communication_enqueue($db,$reference,'change-declined-'.$id,booking_change_request_decline_message($row,$request));
            booking_schedule_event($db,$reference,'appointment_change_declined',['request_id'=>$id]);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }finally{fclose($lock);}
    try{return booking_change_request_decline_notice($db,$reference,$id,true,$deps);}
    catch(Throwable){return ['notice'=>'The decline is saved. Its customer email needs recovery in Manage Appointment.'];}
}
/** Declining a proposed change is not a calendar cancellation or a new RSVP. */
function booking_change_request_decline_message(array $row,array $request): array
{
    $a=booking_request($row)['appointment'];
    $plain="Your requested appointment change was declined.\n\nReference: ".$row['reference']
        ."\nProperty: ".booking_confirmation_property(booking_request($row)['details'])
        ."\nDeclined requested window: ".$request['date'].' '.$request['time'].'–'.$request['window_end'].' Central Time'
        ."\nConfirmed window when this request was declined: ".$a['date'].' '.$a['time'].'–'.$a['windowEnd'].' Central Time'
        ."\n\nThis decline did not change your confirmed appointment or payment records.\n"
        ."Check your current appointment status or request another available window in Customer Account:\n".SITESEE_REAL_ESTATE_SITE_URL.'/account.php';
    return ['from'=>BOOKING_MAIL_SENDER,'to'=>$row['email'],
        'subject'=>booking_property_subject('[TEST] Appointment Change Declined',booking_request($row)['details']),
        'plain'=>$plain,'body'=>$plain,'html'=>nl2br(htmlspecialchars($plain,ENT_QUOTES,'UTF-8')),'headers'=>['From: SiteSee Real Estate <'.BOOKING_MAIL_SENDER.'>',
            'Reply-To: '.BOOKING_MAIL_SENDER,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8']];
}
/** Explicit staff send/recovery only. Historical missing notices are never queued on GET or installation. */
function booking_change_request_decline_notice(PDO $db,string $reference,string $id,bool $send,array $deps=[]): array
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        $row=booking_lifecycle_row($db,$reference);$request=booking_change_request_get($db,$reference,$id);
        if(!$request||$request['state']!=='rejected')throw new InvalidArgumentException('A saved declined request for this booking is required.');
        $key='change-declined-'.$id.':'.$reference;$mail=booking_communication_get($db,$key);
        if(!$mail){
            $latest=booking_change_request_latest($db,$reference);
            if(!$send||empty($deps['prepare_missing'])||$latest['request_id']!==$id
                ||!hash_equals($request['fingerprint'],booking_lifecycle_fingerprint($db,$reference)))
                throw new InvalidArgumentException('No saved decline email exists for this unchanged appointment.');
            booking_communication_enqueue($db,$reference,'change-declined-'.$id,booking_change_request_decline_message($row,$request));
            $mail=booking_communication_get($db,$key);
        }
        if($mail['reference']!==$reference||$mail['kind']!=='change-declined-'.$id
            ||$mail['sender']!==BOOKING_MAIL_SENDER||$mail['recipient']!==$row['email'])
            throw new InvalidArgumentException('Saved decline email identity differs.');
        return booking_customer_notice_locked($db,$reference,$key,$mail,$send,$deps);
    }finally{fclose($lock);}
}
/** Include older unsent/unverified notices even after a newer request was created. */
function booking_change_request_decline_notices(PDO $db,string $reference): array
{
    $latest=booking_change_request_latest($db,$reference);$q=$db->prepare("SELECT * FROM booking_change_requests WHERE reference=? AND state='rejected' ORDER BY created_at DESC,rowid DESC");
    $q->execute([$reference]);$notices=[];
    foreach($q->fetchAll() as $request){
        $mail=booking_communication_get($db,'change-declined-'.$request['request_id'].':'.$reference);
        $needs=$mail&&($mail['submission_state']!=='sent_observed'||$mail['crm_state']!=='associated'
            ||(booking_communication_receipt_available($mail)&&$mail['delivery_state']!=='recipient_copy_observed'));
        if($needs||$request['request_id']===($latest['request_id']??null))$notices[]=['request'=>$request,'mail'=>$mail?:null,'needs_recovery'=>(bool)$needs];
    }
    return $notices;
}
function booking_lifecycle_change(PDO $db,string $reference,string $action,string $fingerprint,string $actor,string $date='',string $time='',array $deps=[]): array
{
    if($actor==='customer'&&$action==='reschedule')return booking_change_request_create($db,$reference,$fingerprint,$date,$time,$deps);
    if(booking_job_get($db,$reference))throw new InvalidArgumentException('Onsite work is complete. Contact SiteSee for help with this completed job.');
    if(!in_array($action,['cancel','reschedule','adopt'],true)||!in_array($actor,['customer','staff'],true)||($action==='adopt'&&$actor!=='staff'))throw new InvalidArgumentException('Invalid appointment action.');
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        $row=booking_lifecycle_row($db,$reference);$claim=booking_confirmation_get($db,$reference);$s=booking_lifecycle_state($db,$reference);
        $request=booking_change_request_pending($db,$reference);
        $approval=$deps['approval_request']??null;
        if($request && ($actor!=='staff'||$action!=='reschedule'||$request['state']!=='pending'
            ||$request['request_id']!==$approval||$request['date']!==$date||$request['time']!==$time
            ||!hash_equals($request['fingerprint'],$fingerprint)))
            throw new InvalidArgumentException('Review the pending customer request before changing this appointment.');
        if($approval!==null&&!$request)throw new InvalidArgumentException('This request is no longer awaiting approval.');
        if($action==='cancel'&&$s['state']==='cancelled')return $s;
        if(booking_lifecycle_pending($db,$reference))throw new InvalidArgumentException('An earlier change needs recovery first.');
        if(!hash_equals(booking_lifecycle_fingerprint($db,$reference),$fingerprint))throw new InvalidArgumentException('Appointment changed. Reload and review the current details.');
        if($s['state']==='cancelled')throw new InvalidArgumentException('This appointment is cancelled.');
        $now=$deps['now']??time();
        $a=booking_request($row)['appointment'];$start=booking_arrival_start($a['date'],$a['time'])->getTimestamp();
        if($actor==='customer'&&$start<=$now)throw new InvalidArgumentException('This arrival window has started. Please contact SiteSee for help.');
        if(in_array($claim['invitation_state'],['sending','uncertain'],true))throw new InvalidArgumentException('Staff must recover the original invitation status before changing this appointment.');
        $last=$db->prepare("SELECT submission_state FROM booking_communications WHERE reference=? AND kind LIKE 'lifecycle-%' AND submission_state NOT IN ('sent_observed')");$last->execute([$reference]);
        if($last->fetch())throw new InvalidArgumentException('Staff must finish the previous change notice before another change.');
        $api=$deps['calendar']??booking_lifecycle_connection($claim);
        $legacyDeleted=$actor==='staff'&&$action==='cancel'&&!str_starts_with($claim['calendar_uid'],'microsoft:')&&$s['state']==='calendar_missing';
        if($legacyDeleted){
            $r=$api('GET',booking_calendar_event_path($claim['calendar_uid'],$claim['event_uid']));
            if(($r['status']??0)!==404 || !is_array($r['body']??null) || !$r['body']) throw new BookingCalendarUnavailable('Legacy absence changed. Reconcile before cancellation.');
            $o=['missing'=>true];
        } else $o=booking_lifecycle_observe($claim,$api);
        $ms=str_starts_with($claim['calendar_uid'],'microsoft:');
        if($actor==='customer'&&($o['missing']||!$o['unchanged']))throw new InvalidArgumentException('The calendar was changed outside this page. Please contact SiteSee so staff can confirm the correct appointment.');
        if(!$ms&&!$o['missing']&&$action!=='adopt')throw new InvalidArgumentException('Legacy Zoho credentials do not authorize event updates/deletion. Staff must change the existing Zoho event, then reconcile it here. Its identity will be preserved.');
        $p=['row'=>$row,'claim'=>$claim,'old_interval'=>[(int)$claim['planned_start'],(int)$claim['planned_end']],'etag'=>$o['etag']??null,'legacy_deleted'=>$legacyDeleted];
        if($request)$p['approval_request']=$request['request_id'];
        if($action!=='cancel'){
            if($o['missing'])throw new InvalidArgumentException('The event was deleted. Restore the same event or contact staff; creating a replacement with a new identity is blocked.');
            if($action==='adopt'){
                if(!in_array($time,booking_window_times($date),true))throw new InvalidArgumentException('Choose an agreed two-hour arrival window.');
                $windowStart=booking_arrival_start($date,$time)->getTimestamp();
                if($windowStart<=$now||$o['start']<$windowStart||$o['start']>=$windowStart+7200||$o['end']-$o['start']!==(int)$row['duration_minutes']*60)throw new InvalidArgumentException('The moved event must fit the agreed arrival window and reviewed shoot duration.');
                if($ms)booking_scheduling_ms_clear($api,$o['expected'],$claim['event_uid']);
                else booking_confirmation_verify_clear($api,$o['expected'],$claim['calendar_uid'],$claim['event_uid']);
                foreach(booking_lifecycle_busy($db,['busy'=>[]],$reference)['busy'] as $busy) if($busy[0]<$o['end'] && $busy[1]>$o['start']) throw new InvalidArgumentException('The moved appointment overlaps another stored reservation.');
                $p['expected']=$o['expected'];$p['new_interval']=[$o['start'],$o['end']];
                $end=(new DateTimeImmutable('@'.($windowStart+7200)))->setTimezone(new DateTimeZone('America/Chicago'))->format('H:i');
            }else{
                $windows=booking_lifecycle_windows($db,$reference,$date,['calendar'=>$api,'now'=>$now]);
                $matches=array_values(array_filter($windows,static fn($w)=>$w['date']===$date&&$w['time']===$time));
                if(count($matches)!==1)throw new InvalidArgumentException('That window is blocked or no longer available. Check available alternatives again.');
                $w=$matches[0];$end=$w['end_time'];$p['new_interval']=[strtotime($w['planned_start_utc']),strtotime($w['planned_end_utc'])];
                $p['expected']=booking_scheduling_ms_event($row,$w,json_decode($claim['event_json'],true)['transactionId']);
            }
            $p['appointment']=array_replace($a,['date'=>$date,'time'=>$time,'windowEnd'=>$end,'windowMinutes'=>120]);
        }
        if($action==='cancel'&&$o['missing']&&($s['state']!=='calendar_missing'||$actor!=='staff'))throw new InvalidArgumentException('Verify calendar deletion with Reconcile Calendar before recording cancellation.');
        if($ms && !$o['missing'] && $action!=='adopt' && !preg_match('/^(?:W\/)?"[A-Za-z0-9+\/=._-]{1,512}"$/D',$o['etag'])) throw new BookingCalendarUnavailable('The calendar did not provide a usable version. No change was attempted.');
        if($action==='reschedule' && $o['unchanged'] && $p['appointment']===$a && $p['new_interval']===$p['old_interval']) return $s;
        $op=['operation_id'=>bin2hex(random_bytes(16)),'reference'=>$reference,'revision'=>(int)$s['revision']+1,'action'=>$action,'actor'=>$actor,'state'=>'prepared','payload_json'=>json_encode($p,JSON_THROW_ON_ERROR)];
        $db->exec('BEGIN IMMEDIATE');
        try{
            if(booking_job_get($db,$reference))throw new InvalidArgumentException('Onsite work was completed. The appointment cannot be changed.');
            if(!hash_equals(booking_lifecycle_fingerprint($db,$reference),$fingerprint))throw new InvalidArgumentException('Appointment changed during the check. Reload.');
            $db->prepare('INSERT INTO booking_lifecycle_operations(operation_id,reference,revision,action,actor,state,payload_json,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute([...array_values($op),gmdate('c')]);
            if($request){
                $q=$db->prepare("UPDATE booking_change_requests SET state='applying',operation_id=? WHERE request_id=? AND reference=? AND state='pending'");
                $q->execute([$op['operation_id'],$request['request_id'],$reference]);
                if($q->rowCount()!==1)throw new InvalidArgumentException('The customer request changed. Reload it.');
            }
            $db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        try{
            if($action!=='adopt'&&!$o['missing']){
                // Re-read immediately before mutation. The provider version protects against another edit.
                $fresh=booking_lifecycle_observe($claim,$api);
                if($fresh!==$o)throw new RuntimeException('Calendar changed before submission.');
                $body=$action==='cancel'?null:['start'=>$p['expected']['start'],'end'=>$p['expected']['end'],'body'=>$p['expected']['body']];
                $r=$api($action==='cancel'?'DELETE':'PATCH',booking_ms_event_path($claim['event_uid']),$body,$o['etag']);
                if(in_array(($r['status']??0),[400,401,403,404,409,412,422,429],true)){
                    $db->exec('BEGIN IMMEDIATE');
                    try{
                        $db->prepare("UPDATE booking_lifecycle_operations SET state='aborted',completed_at=? WHERE operation_id=?")->execute([gmdate('c'),$op['operation_id']]);
                        booking_lifecycle_set($db,$reference,['revision'=>$op['revision']]);
                        if($request)$db->prepare("UPDATE booking_change_requests SET state='stale',resolved_at=? WHERE request_id=? AND operation_id=? AND state='applying'")->execute([gmdate('c'),$request['request_id'],$op['operation_id']]);
                        $db->exec('COMMIT');
                    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
                    throw new InvalidArgumentException('The calendar refused the change (HTTP '.(int)$r['status'].'). Reconcile it and resolve the connection or conflict before trying again.');
                }
                if(($r['status']??0)!==($action==='cancel'?204:200))throw new RuntimeException('Provider result needs recovery.');
            }
            if($legacyDeleted){booking_lifecycle_finish($db,$op);return booking_lifecycle_state($db,$reference);}
            return booking_lifecycle_recover_locked($db,$reference,$api);
        }catch(Throwable $e){
            $db->prepare("UPDATE booking_lifecycle_operations SET state='uncertain' WHERE operation_id=? AND state='prepared'")->execute([$op['operation_id']]);
            if($e instanceof InvalidArgumentException)throw $e;
            throw new BookingCalendarUnavailable('The change needs verification. Use Recover Change; no second calendar write will be attempted.');
        }
    }finally{fclose($lock);}
}
/** Explicit customer/staff action sends once. Recovery only reads mail and repairs CRM history. */
function booking_lifecycle_notice(PDO $db,string $reference,bool $send,array $deps=[]): array
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        $row=booking_lifecycle_row($db,$reference);$s=booking_lifecycle_state($db,$reference);$key='lifecycle-'.$s['revision'].':'.$reference;
        if(isset($deps['notice_revision'])&&(int)$s['revision']!==$deps['notice_revision'])return ['notice'=>'Appointment revision changed; no earlier invitation was resent.'];
        $m=booking_communication_get($db,$key);if(!$m)return ['notice'=>'No change notice exists.'];
        if($m['reference']!==$reference||$m['kind']!=='lifecycle-'.$s['revision']||$m['sender']!==BOOKING_MAIL_SENDER||$m['recipient']!==$row['email'])throw new InvalidArgumentException('Saved appointment notice identity differs.');
        $op=$db->prepare("SELECT action,state FROM booking_lifecycle_operations WHERE reference=? AND revision=?");$op->execute([$reference,$s['revision']]);$saved=$op->fetch(PDO::FETCH_ASSOC);
        $deps['verified_cancel_notice']=$s['state']==='cancelled'&&($saved['action']??'')==='cancel'&&($saved['state']??'')==='applied';
        return booking_customer_notice_locked($db,$reference,$key,$m,$send,$deps);
    }finally{fclose($lock);}
}
/** The authenticated staff cancellation itself authorizes its saved customer notice. */
function booking_staff_cancel(PDO $db,string $reference,string $fingerprint,array $deps=[]): array
{
    $state=booking_lifecycle_change($db,$reference,'cancel',$fingerprint,'staff','','',$deps);
    $revision=(int)$state['revision'];
    try{$report=booking_lifecycle_notice($db,$reference,true,['notice_revision'=>$revision]+$deps);}
    catch(Throwable){$report=['notice'=>'Cancellation is saved; the customer notice needs recovery. Do not repeat the calendar deletion.'];}
    return ['state'=>$state,'notice'=>$report];
}

/** Caller holds the booking lock and validates this saved customer communication identity. */
function booking_customer_notice_locked(PDO $db,string $reference,string $key,array $m,bool $send,array $deps=[]): array
{
    $result=[];$graph=$deps['graph']??booking_graph_client(booking_mail_config($send));
    if($send&&$m['submission_state']==='prepared'){
        $config=$deps['crm_config']??booking_crm_config();$crm=$deps['crm']??booking_crm_client($config);
        booking_crm_verify_org($config,$crm);$link=booking_crm_linked($db,$reference,$m['recipient'],$config);booking_crm_verify_contact($link['contact_id'],$m['recipient'],$crm);
        booking_communication_submit($db,$key,$graph);
    }elseif($send&&(str_starts_with($m['kind'],'change-declined-')||($deps['verified_cancel_notice']??false))&&booking_communication_unsent_draft($m)){
        $config=$deps['crm_config']??booking_crm_config();$crm=$deps['crm']??booking_crm_client($config);
        booking_crm_verify_org($config,$crm);$link=booking_crm_linked($db,$reference,$m['recipient'],$config);booking_crm_verify_contact($link['contact_id'],$m['recipient'],$crm);
        booking_communication_send_saved_draft($db,$key,$graph);
    }
    try{booking_communication_reconcile($db,$key,$graph);$result['notice']='Sent copy verified.';}catch(Throwable){$result['notice']='Sent copy not yet verified. No resend attempted.';}
    if(!booking_communication_receipt_available($m))$result['delivery']=booking_communication_receipt_status($m);
    else try{booking_communication_delivery($db,$key,$graph);$result['delivery']='Recipient copy verified.';}catch(Throwable){$result['delivery']='Receipt is not yet verified.';}
    try{$config=$deps['crm_config']??booking_crm_config();$crm=$deps['crm']??booking_crm_client($config);booking_communication_crm($db,$key,$config,$crm);
        $result['crm']='CRM: '.booking_communication_get($db,$key)['crm_state'];}catch(Throwable){$result['crm']='CRM history needs recovery. Calendar and mail results remain saved.';}
    $current=booking_communication_get($db,$key);
    if(in_array($current['crm_state'],['provider_duplicate','existing_candidate_review'],true)){
        try{
            $config=$deps['crm_config']??booking_crm_config();$crm=$deps['crm']??booking_crm_client($config);
            booking_crm_verify_org($config,$crm);$link=booking_crm_linked($db,$reference,$current['recipient'],$config);booking_crm_verify_contact($link['contact_id'],$current['recipient'],$crm);
            $history=array_values(array_filter(booking_crm_history($link['contact_id'],$crm),static fn($h)=>booking_crm_history_candidate($h,$current)));
            $exact=array_values(array_filter($history,static fn($h)=>($h['original_message_id']??'')===$current['internet_message_id']));
            if(count($exact)===1 && is_string($exact[0]['message_id']??null)){
                booking_communication_crm($db,$key,$config,$crm,$exact[0]['message_id']);$result['crm']='CRM: '.booking_communication_get($db,$key)['crm_state'];
            }else{
                $result['history']=array_map(static fn($h)=>['id'=>(string)($h['message_id']??''),'subject'=>(string)($h['subject']??''),'time'=>(string)($h['time']??$h['sent_time']??'')],$history);
                $result['crm']='Matching Zoho history requires explicit staff review below; no email was resent.';
            }
        }catch(Throwable){$result['crm']='Existing Zoho history could not be verified. Keep the saved notice; do not resend it.';}
    }
    return $result;
}

/** Explicit staff fallback for old Zoho credentials: no event or identity is rewritten. */
function booking_lifecycle_legacy_deleted(PDO $db,string $reference,string $fingerprint,array $deps=[]): void
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        booking_lifecycle_row($db,$reference);$c=booking_confirmation_get($db,$reference);
        if(str_starts_with($c['calendar_uid'],'microsoft:')||booking_lifecycle_pending($db,$reference)
            ||!hash_equals(booking_lifecycle_fingerprint($db,$reference),$fingerprint))throw new InvalidArgumentException('Reload the existing legacy appointment before reviewing its deletion.');
        $api=$deps['calendar']??booking_lifecycle_connection($c);
        $r=$api('GET',booking_calendar_event_path($c['calendar_uid'],$c['event_uid']));
        if(($r['status']??0)!==404||!is_array($r['body']??null)||!$r['body'])throw new BookingCalendarUnavailable('The exact legacy event is not returning a structured not-found result. Its reservation is preserved.');
        $range=['start'=>(int)$c['planned_start']-86400,'end'=>(int)$c['planned_end']+86400];
        booking_calendar_read_busy(static fn($path,$query)=>$api('GET',$path.'?'.http_build_query($query)),$c['calendar_uid'],$range);
        booking_lifecycle_set($db,$reference,['state'=>'calendar_missing','missing_since'=>time(),'checked_at'=>time(),
            'diagnostic'=>'Staff verified deletion of the original Zoho event; its exact lookup returned 404 and calendar read succeeded. Cancellation notice still requires confirmation.']);
        booking_schedule_event($db,$reference,'legacy_deletion_staff_verified',['calendar_uid'=>$c['calendar_uid'],'event_uid'=>$c['event_uid']]);
    }finally{fclose($lock);}
}

/** Staff can resolve an unacknowledged attempt only after its original provider version remains unchanged. */
function booking_lifecycle_resolve_unchanged(PDO $db,string $reference,array $deps=[]): void
{
    $lock=booking_confirmation_lock($deps['lock_path']??null);
    try{
        booking_lifecycle_row($db,$reference);$op=booking_lifecycle_pending($db,$reference);
        if(!$op||strtotime($op['created_at'])>($deps['now']??time())-120)throw new InvalidArgumentException('Wait two minutes, then recover the change before resolving it.');
        $p=json_decode($op['payload_json'],true,64,JSON_THROW_ON_ERROR);$api=$deps['calendar']??booking_lifecycle_connection($p['claim']);$o=booking_lifecycle_observe($p['claim'],$api);
        if($o['missing']||!$o['unchanged']||empty($p['etag'])||$o['etag']!==$p['etag'])throw new BookingCalendarUnavailable('The original unchanged provider version is not proven. Keep the operation for recovery.');
        $db->exec('BEGIN IMMEDIATE');
        try{
            $db->prepare("UPDATE booking_lifecycle_operations SET state='aborted',completed_at=? WHERE operation_id=? AND state IN ('prepared','uncertain')")->execute([gmdate('c'),$op['operation_id']]);
            booking_lifecycle_set($db,$reference,['revision'=>(int)$op['revision']]);
            if(isset($p['approval_request']))$db->prepare("UPDATE booking_change_requests SET state='stale',resolved_at=? WHERE request_id=? AND reference=? AND operation_id=? AND state='applying'")->execute([gmdate('c'),$p['approval_request'],$reference,$op['operation_id']]);
            booking_schedule_event($db,$reference,'unapplied_change_resolved',['operation'=>$op['operation_id'],'provider_version_unchanged'=>true]);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }finally{fclose($lock);}
}
