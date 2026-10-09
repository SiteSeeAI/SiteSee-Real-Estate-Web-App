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
    $hours=array_column($windows,'time');
    if ($hours!==$case['times']) throw new RuntimeException('Availability boundary mismatch: '.$case['now']);
    foreach (['07:00','09:00','11:00','13:00','15:00','17:00','13:30','15:30','17:30'] as $hour) {
        $accepted=true;
        try {real_estate_validate_lead_time(real_estate_arrival_window(['date'=>$case['date'],'time'=>$hour]),['rushRequested'=>$case['rush']],$now);}
        catch (InvalidArgumentException $e) {$accepted=false;}
        if ($accepted!==in_array($hour,$case['times'],true)) throw new RuntimeException('Checkout boundary mismatch: '.$case['now']);
        $checks++;
    }
}
echo "Calendar notice/backend parity: PASS ($checks cutoff checks).\n";
