<?php
declare(strict_types=1);
function portal_job_page(PDO $db,array $account,string $reference,string $notice=''): never
{
    portal_service_owned($db,$account['id'],$reference);$e='portal_escape';$money='real_estate_money';$job=booking_job_get($db,$reference);
    $body=portal_service_back($reference);if($notice)$body.='<p class="notice" role="status">'.$e($notice).'</p>';
    if(!$job){
        $draft=booking_job_extras($db,$reference);$lines=json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR);
        $body.='<section class="panel"><h2>Onsite Services</h2>';
        if(!$lines)$body.='<p>No additional services are awaiting approval. SiteSee will collect the approved remaining balance when the onsite work is complete.</p>';
        else{
            $body.='<ul>';foreach($lines as $line)$body.='<li>'.$e($line['label']).' · '.$money($line['cents']).(!empty($line['monthly_cents'])?' + '.$money($line['monthly_cents']).'/month separately after publication':'').'</li>';$body.='</ul><h3>Additional Services Total · '.$money(array_sum(array_column($lines,'cents'))).'</h3>';
            if($draft['approved_at'])$body.='<p class="status">Your approval is recorded. No additional payment has been collected yet.</p>';
            else $body.=portal_service_form($reference,'job_approve','<input type="hidden" name="scope" value="'.$e($draft['scope']).'"><label class="card-consent"><input type="checkbox" name="agreed" value="yes" required><span>I approve these additional services and authorize their displayed total to be added to my final TEST payment when the onsite work is complete.</span></label>','Approve Additional Services');
        }
        portal_page('Job Status',$body.'</section>',$account);
    }
    $bill=json_decode($job['bill_json'],true,16,JSON_THROW_ON_ERROR);$links=[];$verified=false;
    try{$job=booking_job_refresh($db,$reference);$verified=$job['payment_state']==='paid';if($verified&&$job['production_complete_at'])$links=json_decode($job['published_json'],true,16,JSON_THROW_ON_ERROR);}catch(Throwable){$job=booking_job_get($db,$reference);}
    $body.='<section class="panel"><h2>'.($job['production_complete_at']?'Production Complete':'Production').'</h2><p>The onsite work is complete.</p><dl><dt>Approved Job Amount</dt><dd>'.$money($bill['approved_cents']).'</dd><dt>Approved Rush Fee</dt><dd>'.$money($bill['rush_cents']).'</dd>';
    foreach($bill['extras'] as $line)$body.='<dt>'.$e($line['label']).'</dt><dd>'.$money($line['cents']).'</dd>';
    if(!empty($bill['additional_monthly_cents']))$body.='<dt>Additional Platform Billing</dt><dd>'.$money($bill['additional_monthly_cents']).'/month separately after publication</dd>';
    $body.='<dt>Final Job Total</dt><dd>'.$money($bill['total_cents']).'</dd><dt>Deposit And Earlier Payments</dt><dd>'.$money($bill['deposit_cents']+$bill['prior_balance_cents']).'</dd><dt>Final Payment</dt><dd>'.$money((int)$job['amount']).'</dd><dt>Remaining</dt><dd>'.($verified?'$0.00':($job['paid_at']?'Under Billing Review':$money((int)$job['amount']))).'</dd></dl><p class="status">'.($verified?'Payment Verified':$e(ucwords(str_replace('_',' ',$job['payment_state'])))).'</p>';
    if($verified&&$job['receipt_url'])$body.='<p><a target="_blank" rel="noreferrer" href="'.$e($job['receipt_url']).'">View Final Payment Receipt</a></p>';
    if(!$verified&&$job['payment_intent']&&$job['payment_state']==='needs_action'){
        $config=booking_checkout_config();
        if($config['enabled'])$body.='<p>Your card needs an update or bank verification. Complete this same final payment securely below.</p><form id="job-payment" data-key="'.$e($config['publishable_key']).'" method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="job_payment"><input type="hidden" name="reference" value="'.$e($reference).'"><input type="hidden" name="scope" value="'.$e($job['scope']).'"><label class="card-consent"><input type="checkbox" name="agreed" value="yes" required><span>I authorize the final TEST payment of '.$money((int)$job['amount']).'.</span></label><div id="job-payment-element"></div><button>Continue To Secure Payment</button><p id="job-payment-error" role="alert"></p></form>'.portal_payment_stripe_assets().'<script src="/portal-assets/job-payment.js" defer></script>';
        else $body.='<p>Contact SiteSee for help completing the secure final payment.</p>';
    }elseif(!$verified)$body.='<p>Your final payment needs verification. Contact SiteSee if this status does not update.</p>';
    $body.='<p><a href="/account.php?view=job&amp;reference='.$e($reference).'">Refresh Job Status</a></p></section><section class="panel"><h2>Your Deliverables</h2>';
    if($links){$body.='<ul>';foreach($links as $link)$body.='<li><a href="'.$e($link['url']).'" target="_blank" rel="noopener noreferrer">'.$e($link['label']).'</a></li>';$body.='</ul>';}
    else $body.='<p>'.($job['production_complete_at']?'Your finished links will appear after payment verification.':'Your finished links will appear here when Production is complete and payment is verified.').'</p>';
    portal_page('Job Status',$body.'</section>',$account);
}
