<?php
declare(strict_types=1);
require_once __DIR__.'/booking-lifecycle.php';
function booking_lifecycle_html(PDO $db,array $row,string $csrf,bool $staff,array $windows=[],array $history=[]): string
{
    $ref=$row['reference'];$s=booking_lifecycle_state($db,$ref);$claim=booking_confirmation_get($db,$ref);$pending=booking_lifecycle_pending($db,$ref);
    $e='booking_workflow_escape';$prefix=$staff?'lifecycle_':'';
    $form=static function($action,$label,$fields='')use($csrf,$ref,$prefix){return booking_workflow_form($csrf,$ref,$prefix.$action,$label,$fields);};
    $fingerprint=booking_lifecycle_fingerprint($db,$ref);
    $hidden='<input type="hidden" name="fingerprint" value="'.$fingerprint.'">';
    $a=booking_request($row)['appointment'];
    $html='<h2>Manage Appointment</h2><p><strong>'.($s['state']==='cancelled'?'Cancelled':$e($a['date'].' '.$a['time'].'–'.$a['windowEnd'].' Central Time')).'</strong><br>Reference: '.$e($ref).'</p>';
    $html.='<p>Appointment status: <strong>'.$e($pending?'change awaiting verification':str_replace('_',' ',$s['state'])).'</strong></p>';
    if($staff && $s['actual_start']!==null) $html.='<p>Observed private shoot: '.$e((new DateTimeImmutable('@'.$s['actual_start']))->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d g:i A')).' Central.</p>';
    if($s['diagnostic'])$html.='<p class="note">'.$e($s['diagnostic']).'</p>';
    $html.='<p>Payments and consent remain on this booking. Cancellation does not automatically issue a refund or determine a fee.</p>';
    if($staff){
        $html.=$form('sync',$pending?'Recover Change':'Reconcile Calendar');
        if(!str_starts_with($claim['calendar_uid'],'microsoft:') && $s['state']!=='cancelled') $html.='<details><summary>Verify Deleted Legacy Zoho Event</summary>'.$form('legacy_deleted','Release Verified Stale Reservation',$hidden.'<label><input type="checkbox" name="agreed" value="yes" required> I checked the original Zoho calendar and verified that this event was deleted, not moved to another calendar. Preserve its saved identity and release only its stale reservation.</label>').'</details>';
        if($s['state']!=='cancelled')$html.=$form('link','Create / Replace Private Management Link');
        if($pending)$html.=$form('resolve_unchanged','Resolve Unapplied Change','<label><input type="checkbox" name="agreed" value="yes" required> I used Recover Change and reviewed the original appointment. Resolve only if its original provider version is unchanged.</label>');
        if($pending)$html.='<p>Do not repeat the calendar edit. Recover Change reads its existing result.</p>';
    }
    if(!$pending&&$s['state']!=='cancelled'){
        $html.=$form('windows','Check Available Alternatives','<label>Starting date <input type="date" name="date" value="'.$e(max($a['date'],(new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d'))).'" required></label>');
        if($windows)$html.='<p>Available two-hour arrival windows (Central Time). Availability is checked again when you confirm.</p>';
        foreach($windows as$w)$html.=$form('reschedule','Confirm New Arrival Window',$hidden.'<input type="hidden" name="date" value="'.$e($w['date']).'"><input type="hidden" name="time" value="'.$e($w['time']).'"><p><strong>'.$e($w['date'].' '.$w['time'].'–'.$w['end_time']).' Central</strong></p><label><input type="checkbox" name="agreed" value="yes" required> '.($staff?'The customer agreed to this window.':'I want to change my appointment to this window.').'</label>');
        if($staff&&$s['state']==='calendar_changed')$html.=$form('adopt','Adopt Calendar Move',$hidden.'<label>Customer-agreed date <input type="date" name="date" required></label><label>Arrival window starts <select name="time"><option>07:00</option><option>09:00</option><option>11:00</option><option>13:00</option><option>15:00</option><option>17:00</option></select></label><label><input type="checkbox" name="agreed" value="yes" required> I verified the calendar move and the customer agreed to this arrival window.</label>');
        $html.='<details><summary>Cancel Appointment</summary>'.$form('cancel','Confirm Cancellation',$hidden.'<label><input type="checkbox" name="agreed" value="yes" required> '.($staff?'The customer requested cancellation.':'I want to cancel this appointment.').'</label>').'</details>';
    }
    $mail=booking_communication_get($db,'lifecycle-'.$s['revision'].':'.$ref);
    if($mail){
        $html.='<p>Change notice: '.$e($mail['submission_state']).'<br>Receipt evidence: '.$e($mail['delivery_state']).'<br>Zoho history: '.$e($mail['crm_state']).'</p>';
        if($staff){if($mail['submission_state']==='prepared')$html.=$form('notice','Send Saved Change Notice');
            $html.=$form('recover_notice','Recover Notice &amp; Zoho History');}
    }
    if($staff)foreach($history as $h)$html.=$form('history','Link Verified Existing Zoho Email','<p>'. $e($h['subject'].' · '.$h['time']).'</p><input type="hidden" name="message_id" value="'.$e($h['id']).'"><label><input type="checkbox" name="agreed" value="yes" required> I verified this is the existing change notice in Zoho.</label>');
    if(!$staff)$html.='<p>Need help? <a href="mailto:sales@re.sitesee.ai">sales@re.sitesee.ai</a> · <a href="tel:8002222053">800 222-2053</a></p>';
    return $html;
}
