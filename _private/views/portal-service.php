<?php
declare(strict_types=1);
function portal_service_back(string $reference): string {return '<p><a href="/account.php?view=order&amp;reference='.portal_escape($reference).'">← Order Details</a></p>';}
function portal_service_form(string $reference,string $action,string $fields,string $label): string
{
    return '<form method="post">'.portal_csrf_field().'<input type="hidden" name="reference" value="'.portal_escape($reference).'"><input type="hidden" name="action" value="'.$action.'">'.$fields.'<button>'.$label.'</button></form>';
}
function portal_appointment_page(PDO $db,array $account,string $reference,array $windows=[],string $notice=''): never
{
    if(!function_exists('booking_change_request_create'))portal_page('Appointment Update','<p>Appointment management is being updated. Please try again shortly.</p>',$account,503);
    if(!portal_owns_order($db,$account['id'],$reference))portal_page('Order Unavailable','<p>This order is not available in your account.</p>',$account,404);
    $body=portal_service_back($reference);$e='portal_escape';
    if($notice)$body.='<p class="notice" role="status">'.$e($notice).'</p>';
    $owned=booking_get($db,$reference);
    if($owned && $owned['status']==='deposit_paid_test' && $owned['deposit_paid_at']){
        $confirmation=booking_confirmation_get($db,$reference);
        if(!$owned['approved_at'])portal_page('Manage Appointment',$body.'<p>Your payment is recorded. SiteSee is reviewing your requested appointment. Reschedule and cancellation options will appear after the appointment is confirmed.</p>',$account);
        if(!$confirmation || $confirmation['state']!=='confirmed' || !$confirmation['event_uid'])portal_page('Manage Appointment',$body.'<p>SiteSee has reviewed your request. Calendar confirmation is pending. Reschedule and cancellation options will appear after the appointment is confirmed.</p>',$account);
    }
    try{$row=portal_appointment_guard($db,$account['id'],$reference);}catch(InvalidArgumentException){portal_page('Manage Appointment',$body.'<p>Contact SiteSee for help with this appointment.</p>',$account);}
    $state=booking_lifecycle_state($db,$reference);$pending=booking_lifecycle_pending($db,$reference);$claim=booking_confirmation_get($db,$reference);$a=booking_request($row)['appointment'];
    $unresolved=$db->prepare("SELECT 1 FROM booking_communications WHERE reference=? AND kind LIKE 'lifecycle-%' AND submission_state<>'sent_observed' LIMIT 1");$unresolved->execute([$reference]);$noticePending=(bool)$unresolved->fetchColumn();
    $body.='<section class="panel"><h2>Your Arrival Window</h2><p>'.$e($a['date'].' '.$a['time'].'–'.$a['windowEnd']).' Central Time</p><p>Cancellation does not automatically issue a refund or determine a fee.</p>';
    $request=booking_change_request_pending($db,$reference);$lastRequest=booking_change_request_latest($db,$reference);
    if($request){$body.='<p class="notice">Requested window: '.$e($request['date'].' '.$request['time'].'–'.$request['window_end']).' Central Time. '
        .($request['state']==='pending'?'Awaiting SiteSee manager approval. Your confirmed appointment remains unchanged.':'Manager approved this request; the calendar result is awaiting verification.').'</p>';
        if($pending)$body.=portal_service_form($reference,'appointment_sync','','Check Saved Change');
    }
    elseif($pending){$body.='<p class="notice">Your last change is awaiting verification. Do not submit another change.</p>'.portal_service_form($reference,'appointment_sync','','Check Saved Change');}
    elseif($state['state']==='cancelled')$body.='<p class="status">Cancelled</p>';
    elseif($noticePending||in_array($claim['invitation_state'],['sending','uncertain'],true))$body.='<p>SiteSee must verify the previous notice before another appointment change.</p>';
    elseif($state['state']!=='active'||!str_starts_with($claim['calendar_uid'],'microsoft:')||booking_calendar_date($a['date'])->setTime((int)substr($a['time'],0,2),0)->getTimestamp()<=time())$body.='<p>Contact SiteSee for appointment assistance.</p>';
    else{
        $fingerprint='<input type="hidden" name="fingerprint" value="'.$e(booking_lifecycle_fingerprint($db,$reference)).'">';
        $body.=portal_service_form($reference,'appointment_windows','<label>Search From<input type="date" name="date" required value="'.$e(max($a['date'],date('Y-m-d'))).'"></label>','Find Available Windows');
        if($windows){
            $options='';foreach($windows as $w)$options.='<option value="'.$e($w['date'].'|'.$w['time']).'">'.$e($w['date'].' '.$w['time'].'–'.$w['end_time']).' Central</option>';
            $body.='<h2>Request A New Window</h2><p>SiteSee must approve your request before your calendar appointment changes or a revised RSVP is sent.</p>'.portal_service_form($reference,'appointment_reschedule',$fingerprint.'<label for="arrival-window">Arrival Window</label><select id="arrival-window" name="window">'.$options.'</select><label class="card-consent"><input type="checkbox" name="agreed" value="yes" required><span>I want to request this arrival window for manager approval.</span></label>','Request New Window');
        }
        $body.='<details class="help"><summary>Cancel Appointment</summary>'.portal_service_form($reference,'appointment_cancel',$fingerprint.'<label class="card-consent"><input type="checkbox" name="agreed" value="yes" required><span>I want to cancel this appointment.</span></label>','Confirm Cancellation').'</details>';
    }
    if(!$request&&($lastRequest['state']??'')==='rejected')$body.='<p>Your last requested window was not approved. Your confirmed appointment is unchanged.</p>';
    $mail=booking_communication_get($db,'lifecycle-'.$state['revision'].':'.$reference);
    if($mail&&$mail['submission_state']!=='sent_observed')$body.='<p class="notice">Your saved change notice needs a staff check. Your appointment status above reflects the saved result.</p>';
    portal_page('Manage Appointment',$body.'</section>',$account);
}
function portal_billing_page(PDO $db,array $account,string $reference): never
{
    // Permit only the verified Stripe redirect following the same-origin billing form POST.
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self' https://billing.stripe.com; base-uri 'none'; frame-ancestors 'none'");
    if(!portal_owns_order($db,$account['id'],$reference))portal_page('Order Unavailable','<p>This order is not available in your account.</p>',$account,404);
    $body=portal_service_back($reference);$e='portal_escape';$money='real_estate_money';
    try{$payments=portal_billing_records($db,$account['id'],$reference);}catch(Throwable){portal_page('Billing & Receipts',$body.'<p class="notice">Billing records could not be verified right now. Please try again or contact SiteSee.</p>',$account,503);}
    if(!$payments)$body.='<section class="panel"><p>No verified payment has been recorded for this order yet.</p></section>';
    foreach($payments as $p){
        $body.='<section class="panel"><h2>'.($p['kind']==='deposit'?'Test Deposit':'Test Balance').'</h2><dl><dt>Paid</dt><dd>'.$money($p['amount']).'</dd><dt>Refunded</dt><dd>'.$money($p['refunded']).'</dd><dt>Net Paid</dt><dd>'.$money($p['amount']-$p['refunded']).'</dd></dl>';
        if($p['disputed'])$body.='<p class="notice">This payment has a dispute. Contact SiteSee for assistance.</p>';
        foreach(['receipt'=>'View Receipt','invoice'=>'View Invoice','pdf'=>'Download Invoice PDF'] as $key=>$label)if($p[$key])$body.='<p><a href="'.$e($p[$key]).'" rel="noreferrer" target="_blank">'.$label.'</a></p>';
        if(!$p['invoice'])$body.='<p class="help">No invoice is available for this payment.</p>';
        $body.='</section>';
    }
    if($payments)$body.='<section class="panel"><h2>Payment Methods</h2><p>Manage the billing address and test payment methods associated with this order. Changes do not authorize a charge.</p>'.portal_service_form($reference,'billing_manage','','Manage Test Payment Methods').'</section>';
    portal_page('Billing & Receipts',$body,$account);
}
function portal_balance_page(PDO $db,array $account,string $reference,bool $return): never
{
    if(!portal_owns_order($db,$account['id'],$reference))portal_page('Order Unavailable','<p>This order is not available in your account.</p>',$account,404);
    if(booking_job_get($db,$reference))portal_job_page($db,$account,$reference);
    $body=portal_service_back($reference);$e='portal_escape';
    $paid=portal_balance_paid($db,$reference);
    if($paid>0)portal_page('Test Balance Recorded',$body.'<section class="panel"><h2>'.real_estate_money($paid).' Received</h2><p>Your balance payment is recorded.</p><a href="/account.php?view=billing&amp;reference='.$e($reference).'">Billing &amp; Receipts</a></section>',$account);
    try{$current=portal_balance_scope($db,$account['id'],$reference);}catch(InvalidArgumentException $error){portal_page('Your Test Balance',$body.'<p>'.$e($error->getMessage()).'</p>',$account);}
    if($return)$body.='<p class="notice" role="status">Payment has not been recorded yet. Allow time for verification if you completed checkout. Returning here does not confirm payment.</p><p><a href="/account.php?view=balance&amp;reference='.$e($reference).'&amp;result=return">Check Payment Status</a></p>';
    $config=booking_checkout_config();$text='I authorize one TEST payment of '.real_estate_money($current['amount']).' for the approved balance on order '.$reference.'.';
    $body.='<section class="panel"><dl><dt>Approved Job Amount</dt><dd>'.real_estate_money((int)$current['row']['approved_cents']).'</dd><dt>Approved Rush Fee</dt><dd>'.real_estate_money((int)$current['row']['rush_fee_cents']).'</dd><dt>Deposit Recorded</dt><dd>'.real_estate_money((int)$current['row']['deposit_cents']).'</dd></dl><h2>Approved Test Balance · '.real_estate_money($current['amount']).'</h2><p>This includes the approved job amount and any approved rush fee, less your recorded deposit. Your appointment status is separate.</p><form id="portal-payment" data-view="balance" data-email="'.$e($account['email']).'" data-key="'.$e($config['enabled']?$config['publishable_key']:'').'" method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="balance_checkout"><input type="hidden" name="reference" value="'.$e($reference).'"><input type="hidden" name="scope" value="'.$e($current['scope']).'"><label class="card-consent"><input type="checkbox" name="card_consent" value="yes" required><span>'.$e($text).'</span></label><button class="primary">Continue To Secure Payment</button></form><p id="payment-error" role="alert" hidden></p><p id="checkout-status" aria-live="polite"></p><div id="stripe-checkout"></div></section><script src="/portal-assets/payment.js" defer></script>';
    if($config['enabled']){$body.=portal_payment_stripe_assets();}
    portal_page('Your Test Balance',$body,$account);
}
