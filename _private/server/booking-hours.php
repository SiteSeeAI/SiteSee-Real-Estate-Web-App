<?php
declare(strict_types=1);

/** New availability only; never changes an existing calendar event or paid record. */
function booking_hours_date(string $date): DateTimeImmutable
{
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('America/Chicago'));
    if(!$day||$day->format('Y-m-d')!==$date)throw new InvalidArgumentException('Choose a valid calendar date.');
    return $day;
}
function booking_easter(int $year): DateTimeImmutable
{
    // Gregorian computus, independent of the server timezone/calendar extension.
    $a=$year%19;$b=intdiv($year,100);$c=$year%100;$d=intdiv($b,4);$e=$b%4;
    $f=intdiv($b+8,25);$g=intdiv($b-$f+1,3);$h=(19*$a+$b-$d-$g+15)%30;
    $i=intdiv($c,4);$k=$c%4;$l=(32+2*$e+2*$i-$h-$k)%7;
    $m=intdiv($a+11*$h+22*$l,451);$value=$h+$l-7*$m+114;
    return booking_hours_date(sprintf('%04d-%02d-%02d',$year,intdiv($value,31),$value%31+1));
}
function booking_closed_day(string $date): ?string
{
    $day=booking_hours_date($date);$year=(int)$day->format('Y');$monthDay=$day->format('m-d');
    $fixed=['01-01'=>"New Year's Day",'07-04'=>'Independence Day','12-25'=>'Christmas'];
    if(isset($fixed[$monthDay]))return $fixed[$monthDay];
    $easter=booking_easter($year);
    if($date===$easter->format('Y-m-d'))return 'Easter';
    if($date===$easter->modify('-2 days')->format('Y-m-d'))return 'Good Friday';
    if($date===booking_hours_date($year.'-05-31')->modify('last monday of this month')->format('Y-m-d'))return 'Memorial Day';
    if($date===booking_hours_date($year.'-09-01')->modify('first monday of this month')->format('Y-m-d'))return 'Labor Day';
    if($date===booking_hours_date($year.'-11-01')->modify('fourth thursday of this month')->format('Y-m-d'))return 'Thanksgiving';
    return null;
}
function booking_window_times(string $date): array
{
    if(booking_closed_day($date)!==null)return [];
    return booking_hours_date($date)->format('w')==='0'
        ? ['13:30','15:30','17:30'] : ['07:00','09:00','11:00','13:00','15:00','17:00'];
}
function booking_arrival_start(string $date,string $time): DateTimeImmutable
{
    if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Choose a valid arrival time.');
    return booking_hours_date($date)->setTime((int)substr($time,0,2),(int)substr($time,3,2));
}
