<?php
declare(strict_types=1);
function portal_new_order_page(PDO $db,array $account,string $again=''): never
{
    $p=portal_profile($db,$account['id']);$seed=$again!==''?portal_purchase_seed($db,$account['id'],$again):null;
    if($again!==''&&!$seed)portal_page('Order Unavailable','<p>This order is not available in your account.</p>',$account,404);
    $config=['csrf'=>$_SESSION['csrf'],'details'=>['first'=>$p['first_name'],'last'=>$p['last_name'],'company'=>$p['company'],'phone'=>$p['phone'],'email'=>$account['email']],'seed'=>$seed];
    $body=($seed?'<p class="notice">This is a new service order. Review the property, service quantities and current pricing, then choose a new date and provide fresh access instructions.</p>':'')
        .'<div id="portal-wizard" data-config="'.portal_escape(json_encode($config,JSON_THROW_ON_ERROR)).'"><div id="sp-screen"></div><noscript>Enable JavaScript to complete the guided order form.</noscript></div>'
        .'<link rel="stylesheet" href="/portal-assets/order.css"><script src="/account.php?view=engine&amp;market=residential" defer></script><script src="/account.php?view=engine&amp;market=commercial" defer></script><script src="/portal-assets/order.js" defer></script>';
    portal_page('New Order',$body,$account);
}
function portal_payment_page(PDO $db,array $account,string $reference,bool $return): never
{
    $intent=portal_purchase_intent_for_order($db,$account['id'],$reference);
    if(!$intent)portal_page('Order Unavailable','<p>This order is not available in your account.</p>',$account,404);
    $row=booking_get($db,$reference);$paid=$row['status']==='deposit_paid_test' && $row['deposit_paid_at'];
    $e='portal_escape';$url='/account.php?view=order&amp;reference='.$e($reference);
    $body='<p><a href="'.$url.'">← Order Details</a></p><p class="lead">'. $e(portal_property(booking_request($row)['details'])).'</p>';
    if($paid)portal_page('Test Deposit Recorded',$body.'<section class="panel"><h2>'.real_estate_money((int)$row['deposit_cents']).' Received</h2><p>Your deposit is recorded. Staff price review, rush approval and appointment confirmation are separate steps.</p><a href="'.$url.'">View Your Order Status</a></section>',$account);
    $q=$db->prepare('SELECT state FROM booking_lifecycle WHERE reference=?');$q->execute([$reference]);$life=$q->fetchColumn();
    if(!in_array($row['status'],['awaiting_deposit_test','approved_test'],true)||($life!==false&&$life!=='active'))portal_page('Payment Unavailable',$body.'<p>This order needs staff review before payment.</p>',$account);
    if($return)$body.='<p class="notice" role="status">A deposit has not been recorded yet. If you completed payment, allow time for verification. If you left checkout, you can continue securely below. Returning here does not confirm payment.</p><p><a href="/account.php?view=payment&amp;reference='.$e($reference).'&amp;result=return">Check Payment Status</a></p>';
    if($intent['staff_notice']!=='sent'||$intent['customer_notice']!=='sent')$body.='<p class="notice">Your order is saved. Email delivery needs a staff check. Contact SiteSee with reference '.$e($reference).' if you need help.</p>';
    $config=booking_checkout_config();
    $body.='<section class="panel"><h2>50% Test Deposit · '.real_estate_money((int)$row['deposit_cents']).'</h2><p>Only the deposit is due today. No live charge or subscription is created.</p><p>Your deposit starts schedule review. Your preferred window is not guaranteed. You must accept any alternative before confirmation. If no mutually acceptable date is available, the deposit is refundable. Any scope or price change requires your agreement.</p>'
        .'<form id="portal-payment" data-key="'.$e($config['enabled']?$config['publishable_key']:'').'" method="post">'.portal_csrf_field().'<input type="hidden" name="action" value="checkout"><input type="hidden" name="reference" value="'.$e($reference).'"><label class="card-consent"><input type="checkbox" name="card_consent" value="yes" required><span>'.$e(BOOKING_CONSENT_TEXT).'</span></label><button class="primary">Continue To Secure Payment</button></form><p role="alert" id="payment-error" hidden></p><p id="checkout-status" aria-live="polite"></p><div id="stripe-checkout"></div></section><script src="/portal-assets/payment.js" defer></script>';
    if($config['enabled']){
        header("Content-Security-Policy: default-src 'none'; script-src 'self' https://js.stripe.com https://*.js.stripe.com https://checkout.stripe.com; style-src 'self'; font-src 'self'; img-src 'self' data: https://*.stripe.com https://*.link.com; connect-src 'self' https://api.stripe.com https://checkout.stripe.com https://link.com https://*.link.com; frame-src https://checkout.stripe.com https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com https://link.com https://*.link.com; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        $body.='<script src="https://js.stripe.com/dahlia/stripe.js" defer></script>';
    }
    portal_page('Your Test Deposit',$body,$account);
}
