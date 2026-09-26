<?php
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/sitesee-confirm-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=' . str_repeat('q',48));
putenv('SITESEE_REAL_ESTATE_BOOKING_DB=' . $tmp . '/bookings.sqlite');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__ . '/../_private/server/booking-invitation.php';
$checks = 0;
function check(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($why); }
function rejects(callable $fn, string $why): void { try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) { check(true, $why); return; } throw new RuntimeException($why); }
$db = booking_db();
$now = new DateTimeImmutable('now', new DateTimeZone('America/Chicago'));
$day = $now->modify('+10 days')->format('Y-m-d');
$config = ['confirmation_stage'=>'test','confirmation_enabled'=>true,'invitations_enabled'=>true,'enabled'=>true,
    'test_recipient_email'=>'agent@example.com','client_id'=>'fixture','client_secret'=>'fixture','refresh_token'=>'fixture',
    'calendar_uid'=>'83fd48b78da6469684c4051099b39737'];
$lock = $tmp . '/calendar.lock';
$details = ['first'=>'Test','last'=>'Agent','company'=>'Example','email'=>'agent@example.com','phone'=>'5555550100',
    'street'=>'123 Main St','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'];
function paid(string $reference, string $time = '09:00', bool $rush = false): array {
    global $db, $day, $details, $now;
    $submission = real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
        'details'=>$details, 'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
        'appointment'=>['date'=>$day,'time'=>$time,'windowMinutes'=>120,'rushRequested'=>$rush,'meetPhotographer'=>'No','accessType'=>'Lockbox',
            'lockboxCode'=>'0123456789','cancellationAccepted'=>true]], $now);
    booking_capture($db, $submission, $reference, true);
    $db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,checkout_state='paid' WHERE reference=?")->execute([gmdate('c'),$reference]);
    return booking_get($db, $reference);
}
$created = []; $writes = 0; $calls = 0; $timeout = false; $malformed = false; $busyAllDay = false; $conflict = true; $racing = false;
$transport = static function ($method, $url, $form, $token) use (&$created,&$writes,&$calls,&$timeout,&$malformed,&$busyAllDay,&$conflict,&$racing,$config,$day): array {
    ++$calls;
    if ($url === 'https://accounts.zoho.com/oauth/v2/token') return ['status'=>200,'body'=>['access_token'=>'fixture-token']];
    check($token === 'fixture-token', 'Only the scoped token reaches the calendar host.');
    if ($method === 'POST') {
        ++$writes;
        $event = json_decode($form['eventdata'], true);
        check(!array_key_exists('attendees', $event) && !array_key_exists('group_attendees', $event)
            && !array_key_exists('reminders', $event) && $event['notify_attendee'] === 0
            && $event['calendar_alarm'] === false, 'Calendar writes omit optional arrays and disable notifications/alarms.');
        check(!str_contains(json_encode($event), '0123456789'), 'Private property access code excluded from event.');
        $event['uid'] = 'event-' . $writes . '@zoho.com'; $event['caluid'] = $config['calendar_uid'];
        $created[$event['uid']] = $event;
        if ($timeout) throw new RuntimeException('Simulated lost provider reply with secret-that-must-not-escape');
        return ['status'=>200,'body'=>['events'=>[$event]]];
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (!str_ends_with($path, '/events')) {
        $uid = rawurldecode(basename($path));
        return ['status'=>200,'body'=>['events'=>[$created[$uid] ?? []]]];
    }
    if ($malformed) return ['status'=>200,'body'=>['events'=>[['message'=>'Unexpected data']]]];
    $events = array_values($created);
    if ($racing && $created) { $extra=end($created);$extra['uid']='external-edit@zoho.com';$events[]=$extra; }
    if ($busyAllDay) $events[] = ['isallday'=>true,'dateandtime'=>['start'=>str_replace('-','',$day), 'end'=>(new DateTimeImmutable($day))->modify('+1 day')->format('Ymd'), 'timezone'=>'America/Chicago']];
    elseif ($conflict) $events[] = ['isallday'=>false,'dateandtime'=>['start'=>(new DateTimeImmutable($day . ' 09:00',new DateTimeZone('America/Chicago')))->format('Ymd\THisO'),
        'end'=>(new DateTimeImmutable($day . ' 10:00',new DateTimeZone('America/Chicago')))->format('Ymd\THisO'),'timezone'=>'America/Chicago']];
    return ['status'=>200,'body'=>['events'=>$events]];
};
$run = static fn($ref,$cfg=null,$clock=null) => booking_confirm_appointment($db,$ref,$cfg ?? $config,$transport,$clock ?? $now,$lock);
paid('AAA0000001');
rejects(fn()=>$run('AAA0000001'), 'Unreviewed deposit cannot confirm.');
booking_review_paid($db,'AAA0000001',150,'David',true);
rejects(fn()=>$run('AAA0000001',array_replace($config,['confirmation_enabled'=>false])), 'Default-off switch blocks all writes.');
rejects(fn()=>$run('AAA0000001',array_replace($config,['test_recipient_email'=>'other@example.com'])), 'Only configured test recipient allowed.');
check($writes===0 && $calls===0, 'Invalid or disabled confirmation never calls Zoho.');
$malformed=true; rejects(fn()=>$run('AAA0000001'), 'Malformed fresh reads cannot confirm.'); $malformed=false;
check(!booking_confirmation_get($db,'AAA0000001') && $writes===0,'Read failures do not create a claim or event.');
$busyAllDay=true;rejects(fn()=>$run('AAA0000001'), 'All-day conflicts block confirmation.');$busyAllDay=false;
$before=booking_get($db,'AAA0000001');
$confirmed=$run('AAA0000001');
check($confirmed['state']==='confirmed' && $writes===1,'One calendar event is verified after creation.');
$local=(new DateTimeImmutable('@'.$confirmed['planned_start']))->setTimezone(new DateTimeZone('America/Chicago'));
check($local->format('H:i')==='10:00' && (int)$confirmed['planned_end']-(int)$confirmed['planned_start']===150*60,'Internal block starts after the busy hour and covers full shoot.');
check($confirmed['invitation_state']==='none','Calendar confirmation sends no invitation.');
$after=booking_get($db,'AAA0000001');
check($after===$before, 'Confirmation cannot modify deposit, rush fee, payment state, request or price.');
$callCount=$calls;$run('AAA0000001');check($writes===1 && $calls===$callCount,'Duplicate confirmation is idempotent without network calls.');
$message=booking_invitation_message($after,$confirmed);
$windowStart=(new DateTimeImmutable($day.' 09:00',new DateTimeZone('America/Chicago')))->getTimestamp();
check(str_contains($message['ical'],'DTSTART:'.gmdate('Ymd\THis\Z',$windowStart)) && str_contains($message['ical'],'DTEND:'.gmdate('Ymd\THis\Z',$windowStart+7200)), 'Invitation shows original two-hour arrival window.');
check(!str_contains($message['ical'],'0123456789') && !str_contains($message['ical'],'10:00'), 'Invitation excludes access code and internal planned start.');
check(str_contains($message['ical'],'METHOD:REQUEST') && str_contains($message['body'],'method=REQUEST'),'Calendar and MIME methods agree.');
check(preg_match('/(?<!\r)\n/', $message['ical'])===0,'Calendar uses CRLF lines.');
foreach(explode("\r\n", $message['ical']) as $line) check(strlen($line)<=75,'Calendar lines fit 75 octets.');
$folded=booking_ical_fold('SUMMARY:'.str_repeat('é',100));check(preg_match('//u',$folded)===1 && str_replace("\r\n ",'',$folded)==='SUMMARY:'.str_repeat('é',100),'UTF-8 folding is lossless.');
$mailCalls=0;$mail=static function($msg)use(&$mailCalls):bool{++$mailCalls;check($msg['to']==='agent@example.com','Invitation goes only to the verified booking email.');return true;};
rejects(fn()=>booking_send_invitation($db,'AAA0000001',array_replace($config,['invitations_enabled'=>false]),$mail),'Invitations remain separately gated.');
booking_send_invitation($db,'AAA0000001',$config,$mail,$transport);booking_send_invitation($db,'AAA0000001',$config,$mail,$transport);
check($mailCalls===1 && booking_confirmation_get($db,'AAA0000001')['invitation_state']==='sent','Repeated send does not duplicate invitations.');
paid('AAA0000002','09:00');booking_review_paid($db,'AAA0000002',150,'David',true);
rejects(fn()=>$run('AAA0000002'),'Second booking cannot overlap confirmed shoot.');
check($writes===1,'Overlap never creates a second event.');
paid('AAA0000003','13:00',true);booking_review_paid($db,'AAA0000003',60,'David',true,'approve');
$rushBefore=booking_get($db,'AAA0000003');$timeout=true;
try{$run('AAA0000003');throw new Exception('Expected uncertain result');}catch(BookingCalendarUnavailable $e){check(!str_contains($e->getMessage(),'secret-that'),'Provider failure details remain private.');}
check(booking_confirmation_get($db,'AAA0000003')['state']==='uncertain','Lost response preserves durable uncertainty.');
$writeCount=$writes;rejects(fn()=>$run('AAA0000003'),'An uncertain create is never automatically retried.');check($writes===$writeCount,'No duplicate after timeout.');
rejects(fn()=>booking_send_invitation($db,'AAA0000003',$config,$mail,$transport),'Uncertain appointments cannot send invitations.');
$timeout=false;$recovered=booking_reconcile_confirmation($db,'AAA0000003',$config,$transport,$lock);
check($recovered['state']==='confirmed' && $writes===$writeCount,'Read-only reconciliation recovers the exact existing event.');
check(booking_get($db,'AAA0000003')===$rushBefore && (int)$rushBefore['rush_fee_cents']===5900,'Rush approval and remaining balance preserved through recovery.');
$failedMailCalls=0;$failedMail=static function($msg)use(&$failedMailCalls){++$failedMailCalls;throw new RuntimeException('lost SMTP reply');};
rejects(fn()=>booking_send_invitation($db,'AAA0000003',$config,$failedMail,$transport),'Uncertain mail result reported.');
rejects(fn()=>booking_send_invitation($db,'AAA0000003',$config,$failedMail,$transport),'Uncertain mail cannot resend automatically.');
check($failedMailCalls===1 && booking_confirmation_get($db,'AAA0000003')['state']==='confirmed','Mail failure leaves appointment confirmed and prevents duplicates.');
paid('AAA0000004','17:00');booking_review_paid($db,'AAA0000004',30,'David',true);
$futureReview=new DateTimeImmutable($day.' 16:30',new DateTimeZone('America/Chicago'));
$late=$run('AAA0000004',null,$futureReview);
check($late['state']==='confirmed','Staff approval does not restart the 72-hour request clock.');
paid('AAA0000005','15:00');booking_review_paid($db,'AAA0000005',30,'David',true);
rejects(fn()=>$run('AAA0000005',null,new DateTimeImmutable($day.' 15:01',new DateTimeZone('America/Chicago'))),'Already-started arrival window cannot confirm.');
$busyHandle=booking_confirmation_lock($lock);rejects(fn()=>$run('AAA0000005'),'Concurrent calendar confirmation is serialized.');fclose($busyHandle);
check(count(array_filter(booking_recent($db),fn($r)=>$r['calendar_status']==='confirmed'))===3,'Staff list distinguishes confirmed calendar bookings.');
check(count(booking_recent($db))===5,'Staff ledger retains every booking.');
paid('AAA0000006','15:00');booking_review_paid($db,'AAA0000006',30,'David',true);
$racing=true;rejects(fn()=>$run('AAA0000006'),'An external edit racing the create prevents confirmation.');
check(booking_confirmation_get($db,'AAA0000006')['state']==='uncertain','A post-create conflict retains a blocked result.');
$racing=false;$six=booking_reconcile_confirmation($db,'AAA0000006',$config,$transport,$lock);
$originalEvent=$created[$six['event_uid']];
$created[$six['event_uid']]['dateandtime']['end']=gmdate('Ymd\THis\Z',(int)$six['planned_end']+300);
$mailBefore=$mailCalls;rejects(fn()=>booking_send_invitation($db,'AAA0000006',$config,$mail,$transport),'Manually moved calendar event cannot send stale invitation.');
check($mailBefore===$mailCalls && booking_confirmation_get($db,'AAA0000006')['invitation_state']==='none','Read verification failure does not attempt email.');
$created[$six['event_uid']]=$originalEvent;
$guardRow=booking_get($db,'AAA0000006');
foreach ([['status'=>'approved_test'],['deposit_paid_at'=>null],['duration_minutes'=>0],['photographer'=>'Another Photographer'],['rush_status'=>'pending'],['reschedule_required'=>1]] as $change) {
    rejects(fn()=>booking_confirmation_gate($config,array_replace($guardRow,$change)),'Payment, duration, photographer and rush guards cannot be bypassed.');
}
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=0');rejects(fn()=>$run('AAA0000006'),'Global test booking switch remains mandatory.');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
$db=null;foreach(glob($tmp.'/*') as $file)unlink($file);rmdir($tmp);
echo "Booking confirmation and invitation safety: $checks checks passed\n";
