<?php
declare(strict_types=1);
require_once __DIR__.'/../_private/real-estate-pricing.php';
require_once __DIR__.'/../_private/server/booking-availability.php';
$cases=json_decode(file_get_contents(__DIR__.'/calendar-notice-cases.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;
foreach ($cases as $case) {
    $now=new DateTimeImmutable($case['now']);
    $range=booking_availability_range($case['date'],60,1);
    $windows=booking_available_windows($case['date'],$case['rush'],60,$range+['busy'=>[]],$now,1);
    $hours=array_map(static fn($w)=>(int)substr($w['time'],0,2),$windows);
    if ($hours!==$case['hours']) throw new RuntimeException('Availability boundary mismatch: '.$case['now']);
    foreach ([7,9,11,13,15,17] as $hour) {
        $accepted=true;
        try {real_estate_validate_lead_time(['date'=>$case['date'],'time'=>sprintf('%02d:00',$hour)],['rushRequested'=>$case['rush']],$now);}
        catch (InvalidArgumentException $e) {$accepted=false;}
        if ($accepted!==in_array($hour,$case['hours'],true)) throw new RuntimeException('Checkout boundary mismatch: '.$case['now']);
        $checks++;
    }
}
echo "Calendar notice/backend parity: PASS ($checks cutoff checks).\n";
