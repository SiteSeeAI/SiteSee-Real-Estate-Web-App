<?php
declare(strict_types=1);
require_once __DIR__.'/booking-lifecycle.php';
function booking_lifecycle_html(PDO $db,array $row,string $csrf,bool $staff,array $windows=[],array $history=[]): string
{
    if(!function_exists('booking_change_request_create'))return '<p>Appointment management is being updated. Please try again shortly.</p>';
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
    $request=booking_change_request_pending($db,$ref);$lastRequest=booking_change_request_latest($db,$ref);
    if($request){
        $html.='<section class="panel"><h3>Requested Appointment Change</h3><p>Requested window: <strong>'.$e($request['date'].' '.$request['time'].'–'.$request['window_end'].' Central Time').'</strong></p>';
        $html.='<p>'.($request['state']==='pending'?'Awaiting manager approval. The confirmed appointment remains unchanged.':'Manager approval recorded; recover the saved calendar result before another change.').'</p>';
        $requestField='<input type="hidden" name="request_id" value="'.$e($request['request_id']).'">';
        if($staff&&$request['state']==='pending'){
            $html.=$form('approve_request','Approve Requested Window',$requestField.'<label><input type="checkbox" name="agreed" value="yes" required> I approve the customer’s requested window. Update the existing calendar appointment and send the revised customer RSVP.</label>');
            $html.=$form('reject_request','Decline Requested Window',$requestField.'<label><input type="checkbox" name="agreed" value="yes" required> I reviewed and decline this request. Keep the confirmed appointment unchanged.</label>');
        }
        $html.='</section>';
    }
    if($staff&&$lastRequest){
        $mail=booking_communication_get($db,'change-request-'.$lastRequest['request_id'].':'.$ref);
        if($mail){
            $requestField='<input type="hidden" name="request_id" value="'.$e($lastRequest['request_id']).'">';
            $html.='<p>Division request notification: '.$e($mail['submission_state']).'<br>Receipt: '.$e(booking_communication_receipt_status($mail)).'</p>';
            if($mail['submission_state']==='prepared'||booking_communication_unsent_draft($mail))$html.=$form('request_notice','Send Saved Division Notification',$requestField);
            $html.=$form('recover_request_notice','Check Division Notification',$requestField);
        }
    }
    if(!$request&&$lastRequest&&$lastRequest['state']==='rejected')$html.='<p>The last requested window was not approved. The confirmed appointment remains unchanged.</p>';
    if(!$request&&!$pending&&$s['state']!=='cancelled'){
        $html.=$form('windows','Check Available Alternatives','<label>Starting date <input type="date" name="date" value="'.$e(max($a['date'],(new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d'))).'" required></label>');
        if($windows)$html.='<p>Available two-hour arrival windows (Central Time). Availability is checked again when you confirm.</p>';
        if($windows&&$staff){
            $options='<option value="">Choose an arrival window</option>';
            foreach($windows as $w)$options.='<option value="'.$e($w['date'].'|'.$w['time']).'">'.$e($w['date'].' '.$w['time'].'–'.$w['end_time']).' Central</option>';
            $html.='<div data-window-choices><p>Choose an alternative only after agreeing it with the customer. This is separate from a customer’s pending change request.</p><label hidden>Customer-agreed arrival window<select data-window-picker aria-label="Customer-agreed arrival window" autocomplete="off">'.$options.'</select></label>';
        }
        foreach($windows as $w){
            $windowForm=$form('reschedule',$staff?'Confirm New Arrival Window':'Request New Arrival Window',$hidden.'<input type="hidden" name="date" value="'.$e($w['date']).'"><input type="hidden" name="time" value="'.$e($w['time']).'"><p><strong>'.$e($w['date'].' '.$w['time'].'–'.$w['end_time']).' Central</strong></p><label><input type="checkbox" name="agreed" value="yes" required autocomplete="off"> '.($staff?'The customer agreed to this window.':'I request this window for manager approval; my confirmed appointment stays unchanged until approval.').'</label>');
            $html.=$staff?str_replace('<form ', '<form data-window-option="'.$e($w['date'].'|'.$w['time']).'" ', $windowForm):$windowForm;
        }
        if($windows&&$staff)$html.='</div>';
        if($staff&&$s['state']==='calendar_changed')$html.=$form('adopt','Adopt Calendar Move',$hidden.'<label>Customer-agreed date <input type="date" name="date" required></label><label>Arrival window starts <select name="time"><option>07:00</option><option>09:00</option><option>11:00</option><option>13:00</option><option>15:00</option><option>17:00</option></select></label><label><input type="checkbox" name="agreed" value="yes" required> I verified the calendar move and the customer agreed to this arrival window.</label>');
        $html.='<details><summary>Cancel Appointment</summary>'.$form('cancel','Confirm Cancellation',$hidden.'<label><input type="checkbox" name="agreed" value="yes" required> '.($staff?'The customer requested cancellation.':'I want to cancel this appointment.').'</label>').'</details>';
    }
    $mail=booking_communication_get($db,'lifecycle-'.$s['revision'].':'.$ref);
    if($mail){
        $html.='<p>Change notice: '.$e($mail['submission_state']).'<br>Receipt evidence: '.$e(booking_communication_receipt_status($mail)).'<br>Zoho history: '.$e($mail['crm_state']).'</p>';
        if($staff){if($mail['submission_state']==='prepared')$html.=$form('notice','Send Saved Change Notice');
            $html.=$form('recover_notice','Recover Notice &amp; Zoho History');}
    }
    if($staff)foreach($history as $h)$html.=$form('history','Link Verified Existing Zoho Email','<p>'. $e($h['subject'].' · '.$h['time']).'</p><input type="hidden" name="message_id" value="'.$e($h['id']).'"><label><input type="checkbox" name="agreed" value="yes" required> I verified this is the existing change notice in Zoho.</label>');
    if(!$staff)$html.='<p>Need help? <a href="mailto:sales@re.sitesee.ai">sales@re.sitesee.ai</a> · <a href="tel:8002222053">800 222-2053</a></p>';
    return $html;
}
