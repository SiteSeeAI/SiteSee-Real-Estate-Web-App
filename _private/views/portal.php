<?php
declare(strict_types=1);
require_once __DIR__.'/application-shell.php';
require_once dirname(__DIR__).'/server/booking-list-ui.php';
function portal_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function portal_csrf_field(): string { return '<input type="hidden" name="csrf" value="'.portal_escape($_SESSION['csrf']).'">'; }
function portal_payment_label(array $order, int $balancePaid): string
{
    if(!empty($order['payment_review']))return 'Payment Under Review';
    // Describe the recorded payment; refunds and disputes remain in Billing & Receipts.
    return $balancePaid > 0 ? 'Test Balance Recorded' : $order['payment_status'];
}
function portal_page(string $title, string $body, array|false $account = false, int $status = 200): never
{
    http_response_code($status);
    echo site_application_shell('customer', $title, $body, (bool)$account, '', ['new_order'=>(bool)($account['profile_complete']??false)]);
    exit;
}
function portal_sign_in(string $notice = ''): never
{
    portal_page('Sign In', ($notice ? '<p role="status" class="notice">'.portal_escape($notice).'</p>' : '')
        . '<p class="lead">Use your registered cell phone number to open My Orders. No password needed.</p><section class="panel narrow"><form method="post">'.portal_csrf_field()
        . '<input type="hidden" name="action" value="request_sms"><label>Cell Phone Number<input name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="40" required></label><label class="card-consent"><input type="checkbox" name="sms_consent" value="yes" required><span>Text me a one-time sign-in code. Message and data rates may apply.</span></label><button class="primary">Text My Sign In Code</button></form></section>'
        . '<p class="help">New number or trouble signing in? <a href="mailto:sales@re.sitesee.ai">Contact SiteSee</a>. Changing your profile contact number does not change your login number.</p>');
}
function portal_verify_page(string $error = ''): never
{
    portal_page('Enter Your Text Code', ($error ? '<p role="alert" class="notice">'.portal_escape($error).'</p>' : '')
        . '<p class="lead" role="status">If your number has account access and text verification is available, a code is on its way. Enter it within 10 minutes.</p><section class="panel narrow"><form method="post" id="verify-form">'.portal_csrf_field()
        . '<input type="hidden" name="action" value="verify_sms"><label>Sign In Code<input id="login-code" name="code" type="text" autocomplete="one-time-code" inputmode="numeric" minlength="4" maxlength="10" pattern="[0-9]{4,10}" required></label><button class="primary">Sign In</button></form></section><p><a href="/account.php">Request A New Text</a> · Wait one minute before requesting again.</p>');
}
function portal_orders_page(PDO $db, array $account, int $page, string $notice = ''): never
{
    $options=booking_list_options($_GET);$groups=[];$e='portal_escape';
    foreach(['open','previous'] as $group)$groups[$group]=booking_order_list($db,$account['id'],$group,$options[$group.'_page'],$options[$group.'_size']);
    $body='<p class="lead">Open jobs stay here until SiteSee completes or closes the order.</p>';
    if($notice)$body.='<p role="status" class="notice">'.$e($notice).'</p>';
    if(!portal_profile_complete($db,$account['id']))$body.='<section class="panel next-action"><h2>Complete Your Account</h2><p>Fill in your name, company and contact phone before booking a new appointment.</p><a class="payment-action" href="/account.php?view=profile">Complete Account →</a></section>';
    $body.=booking_lists_html($groups,$options,'/account.php',[],static function($row)use($db,$e){
        $summary=portal_order_summary($row);$status=$row['production_complete_at']?'Completed':($row['completed_at']?'In Production':$summary['appointment_status']);
        $total=$row['bill_json']?json_decode($row['bill_json'],true,32,JSON_THROW_ON_ERROR)['total_cents']:($row['approved_at']?(int)$row['approved_cents']+(int)$row['rush_fee_cents']:null);
        return '<article class="order"><div><p class="eyebrow">Order '.$e($row['order_number']).'</p><h3>'.$e($summary['property']).'</h3><p>'.$e($status).' · '.$e(portal_payment_label($summary,portal_balance_paid($db,$row['reference']))).' · '.($total===null?'Total awaiting review':real_estate_money($total)).'</p></div><a class="view-order" href="/account.php?view=order&amp;reference='.$e($row['reference']).'">View Order<span class="sr-only"> '.$e($row['order_number']).'</span> →</a></article>';
    });
    $body.='<details class="help"><summary>Missing A Previous Order?</summary><p>Use an unexpired private payment or appointment link from SiteSee to add it. The order contact must match your staff-verified account. If the link has expired, contact SiteSee for help.</p><form method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="claim_order"><label>Private Order Link Or Code<input name="credential" maxlength="2048" autocomplete="off" required></label><button>Add Previous Order</button></form></details>';
    portal_page('My Orders',$body,$account);
}
function portal_billing_index_page(PDO $db,array $account): never
{
    $options=booking_list_options($_GET);$groups=[];$e='portal_escape';
    foreach(['open','previous'] as $group)$groups[$group]=booking_order_list($db,$account['id'],$group,$options[$group.'_page'],$options[$group.'_size']);
    $body='<p class="lead">Job totals and receipts for your open and previous orders. Open a job for verified payments, refunds and invoices.</p>';
    $body.=booking_lists_html($groups,$options,'/account.php',['view'=>'billing'],static function($row)use($e){
        $summary=portal_order_summary($row);$total=$row['bill_json']?json_decode($row['bill_json'],true,32,JSON_THROW_ON_ERROR)['total_cents']:($row['approved_at']?(int)$row['approved_cents']+(int)$row['rush_fee_cents']:null);
        return '<article class="order"><div><p class="eyebrow">Order '.$e($row['order_number']).'</p><h3>'.$e($summary['property']).'</h3><p>Job Total: '.($total===null?'Awaiting Staff Review':real_estate_money($total)).'</p><p>'.$e($summary['payment_status']).'</p></div><a class="view-order" href="/account.php?view=billing&amp;reference='.$e($row['reference']).'">Billing &amp; Receipts<span class="sr-only"> '.$e($row['order_number']).'</span> →</a></article>';
    });
    portal_page('Billing',$body,$account);
}
/** Render only the chosen, state-eligible step. Authentication and ownership precede this view. */
function portal_order_body(array $order,array $account,array $input=[]): string
{
    $e='portal_escape';$money='real_estate_money';$ref=$order['reference'];$balance=(int)($order['balance_paid_cents']??0);
    $url=static fn($view)=>'/account.php?view='.$view.'&amp;reference='.rawurlencode($ref);
    $tabs=['overview'=>'Overview','services'=>'Services & Access'];
    if ($order['job_closed'] ?? false) $tabs['job'] = 'Job & Deliverables';
    $tabs['payment']='Payment Summary';$tabs['billing']='Billing & Receipts';
    $selected=is_string($input['step']??null)&&isset($tabs[$input['step']])?$input['step']:'overview';
    if(in_array($selected,['appointment','job','billing'],true))$selected='overview';
    $phase = !$order['deposit_paid_cents'] ? 1 : ($order['appointment_status'] === 'Confirmed' ? (($order['job_closed'] ?? false) || ($order['extras_need_approval'] ?? false) ? 4 : 3) : 2);
    $body='<p><a href="/account.php">← My Orders</a></p><section class="panel order-summary"><p class="eyebrow">Order '.$e($order['order_number']).'</p><h2>'.$e($order['property']).'</h2><p class="status">'.$e($order['appointment_status']).'</p><p>'.$e($order['date'].' '.$order['time'].($order['window_end']?'–'.$order['window_end']:'')).' Central Time</p><p class="help">'.$e($order['review_status']).' · '.$e(portal_payment_label($order,$balance)).'</p></section>';
    if ($order['appointment_status'] !== 'Cancelled') $body .= site_workflow_progress(['Deposit', 'SiteSee review', 'Appointment', 'Delivery'], $phase);
    $body.='<nav class="order-step-nav" aria-label="Order information">';
    foreach($tabs as $step=>$label){$href=in_array($step,['appointment','job','billing'],true)?$url($step):$url('order').'&amp;step='.$step;
        $body.='<a href="'.$href.'"'.($selected===$step?' aria-current="step"':'').'>'.$label.'</a>';}
    $body.='</nav>';
    if($selected==='services'){
        $body.='<section class="panel"><h2>Services</h2>';
        if($order['package']&&$order['package']!=='À La Carte')$body.='<p>'.$e($order['package']).'</p>';
        $body.='<ul>';foreach($order['services'] as $service)$body.='<li>'.$e($service).'</li>';$body.='</ul>';
        if($order['access']){$body.='<details><summary>Property Access Instructions</summary><dl>';
            foreach(['meetPhotographer'=>'Meet The Photographer','accessType'=>'Access Type','lockboxCode'=>'Lockbox Code','keyLocation'=>'Key Location','specialRequests'=>'Special Requests'] as $key=>$label)
                if(isset($order['access'][$key])&&$order['access'][$key]!=='')$body.='<dt>'.$label.'</dt><dd>'.$e($order['access'][$key]).'</dd>';
            $body.='</dl></details>';}
        return $body.'</section>';
    }
    if($selected==='payment'){
        $body.='<section class="panel"><h2>Payment</h2><p class="status">'.$e(portal_payment_label($order,$balance)).'</p><dl><dt>Original Estimate</dt><dd>'.$money($order['quote_cents']).'</dd>';
        if($order['approved_cents']!==null)$body.='<dt>Approved Job Amount</dt><dd>'.$money($order['approved_cents']).'</dd>';
        if($order['rush_fee_cents']>0)$body.='<dt>Approved Rush Fee</dt><dd>'.$money($order['rush_fee_cents']).'</dd>';
        if(($order['extras_cents']??0)>0)$body.='<dt>Approved Onsite Services</dt><dd>'.$money($order['extras_cents']).'</dd>';
        $body.='<dt>Test Deposit Recorded</dt><dd>'.$money($order['deposit_paid_cents']).'</dd>';
        if($balance>0)$body.='<dt>Test Balance Recorded</dt><dd>'.$money($balance).'</dd>';
        if($order['remaining_cents']!==null)$body.='<dt>Remaining Job Balance</dt><dd>'.(!empty($order['payment_review'])?'Under Billing Review':$money($order['remaining_cents'])).'</dd>';
        $body.='</dl><p><a href="'.$url('billing').'">Billing &amp; Receipts</a></p><p class="help">Cancellation refunds or credits are processed separately. Verified refunds appear in Billing.</p></section>';
        return $body;
    }
    $next=['SiteSee Is Reviewing Your Request','Your deposit is recorded. We will confirm your appointment after reviewing scope and availability.',null];
    if($order['appointment_status']==='Cancelled')$next=['Appointment Cancelled','Your appointment is cancelled. Review payments and any manually processed refund in Billing.', $url('billing')];
    elseif($order['change_request']??null){$request=$order['change_request'];$next=['Appointment Change Awaiting Review','Requested: '.$request['date'].' '.$request['time'].'–'.$request['window_end'].' Central Time. Your confirmed appointment remains unchanged until SiteSee approves the change.',$url('appointment')];}
    elseif($order['change_pending']??false)$next=['Appointment Change Being Verified','SiteSee is checking the saved calendar result before another change.',$url('appointment')];
    elseif(!$order['deposit_paid_cents'])$next=($account['profile_complete']??false)
        ? ['Record Your TEST Deposit','Payment is the next step. SiteSee schedule review follows the verified deposit.',!empty($order['portal_payment'])?$url('payment'):null]
        : ['Complete Your Account','Complete your name, company and contact phone before booking.','/account.php?view=profile'];
    elseif($order['review_status']!=='Reviewed')$next=['SiteSee Is Reviewing Your Request','Your TEST deposit is recorded. Appointment controls will appear after review and calendar confirmation.',null];
    elseif($order['appointment_status']!=='Confirmed')$next=['Calendar Confirmation Pending','SiteSee has reviewed your request. We will notify you when the appointment is confirmed.',null];
    elseif($order['extras_need_approval']??false)$next=['Review Additional Services','SiteSee has proposed additional onsite services. Review their scope and price before approval.',$url('job')];
    elseif($order['job_closed']??false)$next=[($order['job_status']??'Production'),!empty($order['payment_review'])?'Your payment needs a billing check. Contact SiteSee.':(($order['remaining_cents']??0)>0?'Review your completed job and final payment.':'Open your job to check Production and available deliverables.'),$url('job')];
    else $next=['Appointment Confirmed','Your confirmed arrival window is shown above. Manage your appointment when you need to request a change.',$url('appointment')];
    $body.='<section class="panel next-action"><p class="eyebrow">NEXT STEP</p><h2>'.$e($next[0]).'</h2><p>'.$e($next[1]).'</p>';
    if($next[2])$body.='<a class="'.(!$order['deposit_paid_cents']&&($account['profile_complete']??false)?'payment-action':'step-primary').'" href="'.$next[2].'">'.(!$order['deposit_paid_cents']&&($account['profile_complete']??false)?'Pay Test Deposit':'Continue →').'</a>';
    return $body.'</section>';
}
function portal_order_page(array $order,array $account): never
{
    portal_page('Order Details',portal_order_body($order,$account,$_GET),$account);
}
function portal_profile_page(PDO $db, array $account, string $notice = ''): never
{
    $e='portal_escape';$p=portal_profile($db,$account['id']);
    $body='<p class="lead">Your pricing access stays with your account.</p>';
    if($notice)$body.='<p role="status" class="notice">'.$e($notice).'</p>';
    $body.='<section class="panel narrow"><dl><dt>Contact Email</dt><dd><strong>'.$e($account['email']).'</strong><br><a href="/account.php?view=email">Change Email</a></dd></dl><form method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="save_profile">';
    foreach(['first_name'=>['First Name','given-name',100],'last_name'=>['Last Name','family-name',100],'company'=>['Company','organization',140],'phone'=>['Contact Phone','tel',35]] as $key=>[$label,$autocomplete,$max]){
        $body.='<label>'.$label.'<input name="'.$key.'" value="'.$e($p[$key]).'" maxlength="'.$max.'" autocomplete="'.$autocomplete.'"'.($key==='phone'?' type="tel"':'').' required></label>';
    }
    $body.='<p class="help">Your contact phone is used for order communication. To change your sign-in cell number, contact SiteSee for identity verification.</p><button class="primary">Save Profile</button></form></section><form method="post" class="help">'.portal_csrf_field().'<input type="hidden" name="action" value="logout"><button>Sign Out</button></form>';
    portal_page('Account',$body,$account);
}

function portal_email_page(PDO $db, array $account, string $notice = ''): never
{
    $e='portal_escape';$id=(string)($_SESSION['portal_email_change']??'');
    $pending=portal_email_pending($db,$account['id'],$id);
    $body='<p><a href="/account.php?view=profile">← Account</a></p><p class="lead">Verify your new contact email. Your cell-phone sign-in, pricing access and orders stay with your account.</p>';
    if($notice)$body.='<p role="alert" class="notice">'.$e($notice).'</p>';
    $body.='<section class="panel narrow"><dl><dt>Current Email</dt><dd><strong>'.$e($account['email']).'</strong></dd></dl>';
    if($pending){
        $body.='<dl role="status"><dt>Check Your New Email</dt><dd><strong>'.$e($pending['new_email']).'</strong></dd></dl><p>Enter your eight-digit code within 15 minutes of your request.</p><form method="post">'.portal_csrf_field()
            .'<input type="hidden" name="action" value="email_confirm"><label>Verification Code<input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" minlength="8" maxlength="8" required></label><button class="primary">Verify &amp; Change Email</button></form>'
            .'<details class="help"><summary>Wrong Address Or Need A New Code?</summary><p>Cancel this request, then enter your address again. Wait at least one minute between code requests.</p><form method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="email_cancel"><button>Cancel Email Change</button></form></details>';
    }else{
        if($id!=='' && $notice==='')$body.='<p role="status">The previous request is no longer available. Your current email is shown above.</p>';
        $body.='<form method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="email_request"><label>New Email Address<input name="email" type="email" autocomplete="email" maxlength="180" required></label><button class="primary">Send Verification Code</button></form>';
    }
    $body.='</section><p class="help">Your current email stays active until you verify the new address. New orders will use your verified address. Existing appointments and recipients stay as recorded. To add an older order sent to a previous address, contact SiteSee.</p>';
    portal_page('Change Email',$body,$account);
}
