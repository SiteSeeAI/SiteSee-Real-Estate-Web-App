<?php
declare(strict_types=1);

function pay_escape(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
function pay_money(int $cents): string { return '$'.number_format($cents/100,2); }

function pay_summary(array $row): string
{
    $request=booking_request($row);$details=$request['details'];$appointment=$request['appointment'];
    $paid=$row['status']==='deposit_paid_test';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$appointment['date']);
    $clock=static function(string $time): string {
        $d=DateTimeImmutable::createFromFormat('!H:i',$time);return $d ? $d->format('g:i A') : $time;
    };
    $window=$clock((string)$appointment['time']);
    if (isset($appointment['windowEnd'])) $window.=' – '.$clock($appointment['windowEnd']);
    $street=trim($details['street'].' '.($details['unit']??''));
    $locality=$details['city'].', '.$details['state'].' '.($details['zip']??'');
    $services=[];
    if (($request['quote']['packageCents']??0)>0) $services[]=ucfirst((string)$request['quote']['package']).' Package';
    foreach ($request['quote']['lines']??[] as $line) $services[]=(string)$line['label'];
    $services=array_values(array_unique($services));
    $html='<aside class="booking-summary" aria-label="Booking summary"><div class="deposit-heading">'
        .'<span class="eyebrow">'.($paid?'Deposit Received':'Deposit Due Today').'</span>'
        .'<p class="deposit-amount">'.pay_money((int)$row['deposit_cents']).'<span>USD</span></p>'
        .'<p class="deposit-caption">50% of your one-time shoot price</p></div>'
        .'<div class="summary-details"><p class="eyebrow muted">'.pay_escape(ucfirst($row['market'])).' Photography</p>'
        .'<h2>'.pay_escape($street).'</h2><p class="locality">'.pay_escape($locality).'</p>'
        .'<dl class="booking-facts"><div><dt>Requested Date</dt><dd>'.pay_escape($date?$date->format('l, F j, Y'):$appointment['date']).'</dd></div>'
        .'<div><dt>Arrival Window</dt><dd>'.pay_escape($window).'<small>Central Time · Subject to confirmation</small></dd></div></dl>';
    if ($services) {
        $html.='<details class="services"><summary>Your Selected Services <span>'.count($services).'</span></summary><ul>';
        foreach ($services as $service) $html.='<li>'.pay_escape($service).'</li>';
        $html.='</ul></details>';
    }
    $html.='<dl class="totals"><div><dt>One-Time Shoot Price</dt><dd>'.pay_money((int)$row['approved_cents']).'</dd></div>'
        .'<div><dt>50% Deposit'.($paid?' Received':' Today').'</dt><dd>'.pay_money((int)$row['deposit_cents']).'</dd></div>';
    if ($row['rush_status']==='approved') $html.='<div><dt>Approved Rush Fee</dt><dd>'.pay_money((int)$row['rush_fee_cents']).'</dd></div>';
    $html.='<div class="balance"><dt>Balance After Deposit</dt><dd>'.pay_money(booking_remaining_cents($row)).'</dd></div></dl>';
    if ($row['rush_status']==='pending') $html.='<p class="summary-note">Rush requested: $59 is added to the remaining balance only if approved. It is excluded from this deposit.</p>';
    if ((int)$row['platform_monthly_cents']>0 && $row['market']==='residential') {
        $html.='<p class="summary-note">Selected platform: '.pay_money((int)$row['platform_monthly_cents']).'/month, billed separately after publication. No subscription starts with this deposit.</p>';
    }
    if ($row['price_reason']) $html.='<p class="summary-note">Price adjustment: '.pay_escape($row['price_reason']).'</p>';
    return $html.'<p class="reference">Booking Reference <strong>'.pay_escape($row['reference']).'</strong></p></div></aside>';
}

function pay_render(string $body, array $options=[]): string
{
    $title=$options['title']??'Your Booking Deposit';
    $intro=$options['intro']??'Review your details and pay your 50% deposit. We’ll then review your preferred date and arrival window.';
    $row=$options['row']??null;$nonce=$options['nonce']??'';
    $config=$options['client']??[];
    $css=(string)file_get_contents(__DIR__.'/../payment-assets/booking-payment.css');
    $js=(string)file_get_contents(__DIR__.'/../payment-assets/booking-payment.js');
    $step=$options['step']??'payment';
    $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>'.pay_escape($title).' | SiteSee</title><meta name="robots" content="noindex,nofollow,noarchive">'
        .'<style nonce="'.pay_escape($nonce).'">'.$css.'</style>';
    if ($config['enabled']??false) $html.='<script src="https://js.stripe.com/dahlia/stripe.js" defer></script>';
    $html.='</head><body><a href="#main" class="skip-link">Skip To Payment</a>'
        .'<div class="test-banner"><span class="test-dot" aria-hidden="true"></span> Test Mode <span class="test-explainer">No live charge will be collected.</span></div>'
        .'<header class="payment-header"><div class="header-inner"><a class="brand" href="/" aria-label="SiteSee Home">'
        .'<img src="/assets/images/sitesee-logo.png" alt="SiteSee" width="185" height="62"><span>Show More. Decide Faster.</span></a>'
        .'<a class="support-phone" href="tel:18002222053"><span>Questions? We’re Here.</span>800 222-2053</a></div></header>'
        .'<main id="main" class="payment-main"><nav class="steps" aria-label="Booking progress"><span class="done"><b>01</b> Your Request</span>'
        .'<span class="'.($step==='payment'?'current':'done').'"'.($step==='payment'?' aria-current="step"':'').'><b>02</b> Your Deposit</span>'
        .'<span class="'.($step==='review'?'current':'').'"'.($step==='review'?' aria-current="step"':'').'><b>03</b> Schedule Review</span></nav>'
        .'<div class="page-intro"><p class="eyebrow muted">SiteSee Real Estate</p><h1>'.pay_escape($title).'</h1><p>'.pay_escape($intro).'</p></div>'
        .'<div class="payment-layout'.($row?'':' single').'">'.($row?pay_summary($row):'')
        .'<section class="payment-panel" aria-label="Payment and appointment status">'.$body.'</section></div>'
        .'<div class="payment-help"><div><h2>A Clear Next Step.</h2><p>Your deposit starts our schedule review. We’ll confirm your appointment after reviewing your preferred window with you.</p></div>'
        .'<div><h2>Plans Changed?</h2><p>Cancel at least 24 hours before a confirmed appointment for a deposit refund. Within 24 hours, your deposit remains a credit toward one rescheduled shoot.</p></div></div></main>'
        .'<footer class="payment-footer"><span>© '.gmdate('Y').' SiteSee. All Rights Reserved.</span><div><a href="https://sitesee.ai/privacy.html" target="_blank" rel="noopener noreferrer">Privacy</a><a href="mailto:sales@re.sitesee.ai">Contact SiteSee</a></div></footer>';
    $html.='<script nonce="'.pay_escape($nonce).'">window.siteSeeCheckout='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';</script>'
        .'<script nonce="'.pay_escape($nonce).'">'.$js.'</script></body></html>';
    return $html;
}
