<?php
declare(strict_types=1);
require_once __DIR__.'/portal-orders.php';

/** Ownership is checked before even inspecting eligibility or contacting a provider. */
function portal_service_owned(PDO $db,string $account,string $reference): array
{
    if(!portal_owns_order($db,$account,$reference))throw new InvalidArgumentException('This order is unavailable.');
    return booking_get($db,$reference);
}
function portal_appointment_guard(PDO $db,string $account,string $reference): array
{
    portal_service_owned($db,$account,$reference);
    if(!booking_test_enabled()||!booking_lifecycle_enabled())throw new InvalidArgumentException('Contact SiteSee for appointment assistance.');
    booking_communication_schema($db);
    return booking_lifecycle_row($db,$reference); // Retains the existing sales@re.sitesee.ai TEST restriction.
}
function portal_appointment_windows(PDO $db,string $account,string $reference,string $date,array $deps=[]): array
{
    portal_appointment_guard($db,$account,$reference);
    return array_map(static fn($w)=>array_intersect_key($w,array_flip(['date','time','end_time'])),booking_lifecycle_windows($db,$reference,$date,$deps));
}
function portal_appointment_change(PDO $db,string $account,string $reference,string $action,string $fingerprint,bool $agreed,string $date='',string $time='',array $deps=[]): array
{
    portal_appointment_guard($db,$account,$reference);
    if(!$agreed||!in_array($action,['cancel','reschedule'],true))throw new InvalidArgumentException('Review and accept the appointment change.');
    $before=booking_lifecycle_state($db,$reference);
    $after=booking_lifecycle_change($db,$reference,$action,$fingerprint,'customer',$date,$time,$deps);
    // A retry never resends a past notice. The canonical notice function independently prevents duplicate sends.
    if((int)$after['revision']>(int)$before['revision']){
        try{booking_lifecycle_notice($db,$reference,true,$deps);}catch(Throwable){/* Saved change remains authoritative; staff can recover notice delivery. */}
    }
    return $after;
}
