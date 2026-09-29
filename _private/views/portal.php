<?php
declare(strict_types=1);
function portal_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function portal_csrf_field(): string { return '<input type="hidden" name="csrf" value="'.portal_escape($_SESSION['csrf']).'">'; }
function portal_page(string $title, string $body, array|false $account = false, int $status = 200): never
{
    http_response_code($status);
    $e='portal_escape';
    $nav=$account ? '<nav aria-label="Customer Navigation"><a href="/account.php">My Orders</a><a href="/account.php?view=profile">Account</a><a class="new-order" href="/account.php?view=new">New Order</a></nav>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>'.$e($title).' | SiteSee</title><link rel="stylesheet" href="/portal-assets/portal.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@400;500;600&display=swap"><script src="/portal-assets/portal.js" defer></script></head><body><a class="skip" href="#content">Skip To Content</a><header><a class="brand" href="/account.php" aria-label="SiteSee My Orders">SiteSee<span>.</span><small>Show More. Decide Faster.</small></a>'.$nav.'</header><div class="test-notice">TEST ACCESS <span>Orders and payments remain in test mode.</span></div><main id="content"><h1>'.$e($title).'</h1>'.$body.'</main><footer><span>SiteSee Real Estate</span><a href="mailto:sales@sitesee.ai">sales@sitesee.ai</a></footer></body></html>';
    exit;
}
function portal_sign_in(string $notice = ''): never
{
    portal_page('Sign In', ($notice ? '<p role="status" class="notice">'.portal_escape($notice).'</p>' : '')
        . '<p class="lead">Use your approved email address to open My Orders. No password needed.</p><section class="panel narrow"><form method="post">'.portal_csrf_field()
        . '<input type="hidden" name="action" value="request_login"><label>Email Address<input name="email" type="email" autocomplete="email" maxlength="180" required></label><button class="primary">Email My Sign In Link</button></form></section>'
        . '<p class="help">Need help with account access? <a href="mailto:sales@sitesee.ai">Contact SiteSee</a>.</p>');
}
function portal_verify_page(string $error = ''): never
{
    portal_page('Complete Your Sign In', ($error ? '<p role="alert" class="notice">'.portal_escape($error).'</p>' : '')
        . '<p class="lead">Continue to securely open My Orders.</p><section class="panel narrow"><form method="post" id="verify-form">'.portal_csrf_field()
        . '<input type="hidden" name="action" value="consume_login"><label id="code-label">Sign In Code<input id="login-code" name="token" autocomplete="off" maxlength="64" pattern="[a-f0-9]{64}" required></label><p id="code-help">Paste the code after # in your sign-in email link.</p><button class="primary">Sign In</button></form></section><p><a href="/account.php">Request A New Link</a></p>');
}
function portal_orders_page(PDO $db, array $account, int $page, string $notice = ''): never
{
    $result=portal_owned_orders($db,$account['id'],$page);$e='portal_escape';
    $body='<p class="lead">Your property services, appointments and payment status in one place.</p>';
    if ($notice) $body.='<p role="status" class="notice">'.$e($notice).'</p>';
    if (!$result['orders']) $body.='<section class="panel"><h2>No Orders To Show Yet</h2><p>Your verified orders will appear here.</p></section>';
    foreach ($result['orders'] as $order) {
        $body.='<article class="order"><div><p class="eyebrow">'.$e(ucfirst($order['market'])).' · '.$e($order['reference']).'</p><h2>'.$e($order['property']).'</h2><p>'.$e($order['appointment_status']).' · '.$e($order['payment_status']).'</p></div><a class="view-order" href="/account.php?view=order&amp;reference='.$e($order['reference']).'">View Order<span class="sr-only"> '.$e($order['reference']).'</span> →</a></article>';
    }
    $body.='<nav class="pagination" aria-label="Orders Pages">';
    if($page>1)$body.='<a href="/account.php?page='.($page-1).'">Previous</a>';
    if($result['has_more'])$body.='<a href="/account.php?page='.($page+1).'">Next</a>';
    $body.='</nav><details class="help"><summary>Missing A Previous Order?</summary><p>Use an unexpired private payment or appointment link from SiteSee to add it. The order email must match your verified account. If the link has expired, contact SiteSee for help.</p><form method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="claim_order"><label>Private Order Link Or Code<input name="credential" maxlength="2048" autocomplete="off" required></label><button>Add Previous Order</button></form></details>';
    portal_page('My Orders',$body,$account);
}
function portal_order_page(array $order, array $account): never
{
    $e='portal_escape';$money='real_estate_money';
    $body='<p><a href="/account.php">← My Orders</a></p><p class="lead">'.$e($order['property']).'</p><p class="eyebrow">Order '.$e($order['reference']).'</p><div class="columns"><section class="panel"><h2>Appointment</h2><p class="status">'.$e($order['appointment_status']).'</p><dl><dt>Requested Arrival Window</dt><dd>'.$e($order['date'].' '.$order['time'].($order['window_end'] ? '–'.$order['window_end'] : '')).' Central Time</dd><dt>Staff Review</dt><dd>'.$e($order['review_status']).'</dd><dt>Rush Service</dt><dd>'.$e(match($order['rush_status']){'approved'=>'Approved','declined'=>'Declined','pending'=>'Awaiting Approval',default=>'Not Requested'}).'</dd></dl><p class="help">Payment does not confirm your appointment. Use the private appointment link from SiteSee for eligible changes or cancellation.</p></section><section class="panel"><h2>Payment</h2><p class="status">'.$e($order['payment_status']).'</p><dl><dt>Original Estimate</dt><dd>'.$money($order['quote_cents']).'</dd><dt>Approved Job Amount</dt><dd>'.($order['approved_cents']===null?'Awaiting Staff Review':$money($order['approved_cents'])).'</dd><dt>Test Deposit Recorded</dt><dd>'.$money($order['deposit_paid_cents']).'</dd><dt>Remaining Job Balance</dt><dd>'.($order['remaining_cents']===null?'Confirmed After Staff Review':$money($order['remaining_cents'])).'</dd></dl><p class="help">Cancellation does not automatically mean a refund. Receipts and invoices will appear here when billing access is connected.</p></section></div><section class="panel"><h2>Services</h2>';
    if($order['package'] && $order['package']!=='À La Carte')$body.='<p>'.$e($order['package']).'</p>';
    $body.='<ul>';foreach($order['services'] as $service)$body.='<li>'.$e($service).'</li>';$body.='</ul></section>';
    if($order['access']) {
        $body.='<details class="panel"><summary>Property Access Instructions</summary><dl>';
        foreach(['meetPhotographer'=>'Meet The Photographer','accessType'=>'Access Type','lockboxCode'=>'Lockbox Code','keyLocation'=>'Key Location','specialRequests'=>'Special Requests'] as $key=>$label){
            if(isset($order['access'][$key]) && $order['access'][$key]!=='')$body.='<dt>'.$label.'</dt><dd>'.$e($order['access'][$key]).'</dd>';
        }
        $body.='</dl></details>';
    }
    portal_page('Order Details',$body,$account);
}
function portal_profile_page(PDO $db, array $account, string $notice = ''): never
{
    $e='portal_escape';$p=portal_profile($db,$account['id']);
    $body='<p class="lead">Your pricing access stays with your account.</p>';
    if($notice)$body.='<p role="status" class="notice">'.$e($notice).'</p>';
    $body.='<section class="panel narrow"><p>Verified Email<br><strong>'.$e($account['email']).'</strong></p><form method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="save_profile">';
    foreach(['first_name'=>['First Name','given-name',100],'last_name'=>['Last Name','family-name',100],'company'=>['Company','organization',140],'phone'=>['Phone','tel',35]] as $key=>[$label,$autocomplete,$max]){
        $body.='<label>'.$label.'<input name="'.$key.'" value="'.$e($p[$key]).'" maxlength="'.$max.'" autocomplete="'.$autocomplete.'"'.($key==='phone'?' type="tel"':'').'></label>';
    }
    $body.='<button class="primary">Save Profile</button></form></section><form method="post" class="help">'.portal_csrf_field().'<input type="hidden" name="action" value="logout"><button>Sign Out</button></form>';
    portal_page('Account',$body,$account);
}
