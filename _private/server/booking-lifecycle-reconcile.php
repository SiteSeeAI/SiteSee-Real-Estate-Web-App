<?php
declare(strict_types=1);
/** Scheduled local reconciliation; provider GETs only, never calendar writes, email or Stripe. */
if(PHP_SAPI!=='cli'){http_response_code(404);error_log('SiteSee reconciliation requires the PHP CLI executable.');exit;}
echo gmdate('c')." Worker started.\n";
$workerStage='bootstrap';
set_exception_handler(static function(Throwable $error)use(&$workerStage):void{
    $message=$error->getMessage();
    $code=match(true){
        $message==='Booking database must remain outside the document root.'=>'database-path-guard',
        str_contains($message,'could not find driver')=>'sqlite-driver-missing',
        str_contains($message,'unable to open database')=>'database-unreadable',
        str_contains($message,'database is locked')=>'database-locked',
        default=>'check-private-error-log',
    };
    if(preg_match('/no such (table|column): ([a-zA-Z_][a-zA-Z0-9_.]*)/',$message,$missing))$code='missing-'.$missing[1].'-'.str_replace('.','-',$missing[2]);
    echo gmdate('c')." Worker stopped: stage=".$workerStage."; type=".get_class($error)."; code=".$code.".\n";
    exit(1);
});
// CLI has no HTTP document root. Supply the actual public sibling, never realpath('') (cron's home directory).
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__,2).'/public_html/re';
register_shutdown_function(static function(){if(error_get_last() && in_array(error_get_last()['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))echo gmdate('c')." Worker stopped: PHP failure; inspect private server logs.\n";});
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.bin2hex(random_bytes(32)));
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require_once __DIR__.'/booking-lifecycle.php';
require_once __DIR__.'/application.php';
site_application_bootstrap(dirname(__DIR__));
if(!booking_lifecycle_enabled()){echo "TEST lifecycle activation unavailable.\n";exit(1);}
$workerStage='database';
$db=booking_db();
$workerStage='schema';booking_communication_schema($db);
$started=microtime(true);$count=0;$errors=0;
$sql="SELECT c.reference FROM booking_confirmations c JOIN bookings b ON b.reference=c.reference
 LEFT JOIN booking_lifecycle l ON l.reference=c.reference
 WHERE c.state='confirmed' AND c.event_uid IS NOT NULL AND lower(b.email) IN (?,?) AND b.status='deposit_paid_test'
 AND b.reference <> 'D32FFC7458'
 AND (c.planned_end > strftime('%s','now')-86400 OR EXISTS (SELECT 1 FROM booking_lifecycle_operations o WHERE o.reference=c.reference AND o.state IN ('prepared','uncertain')))
 ORDER BY COALESCE(l.checked_at,0) ASC LIMIT 20";
$workerStage='selection';$selection=$db->prepare($sql);$selection->execute(booking_test_recipients());$eligible=$selection->fetchAll();
if(($argv[1]??'')==='--diagnose'){echo "CLI, database and worker selection: PASS. No booking-row or provider writes.\n";exit;}
$workerStage='reconciliation';
foreach($eligible as$row){
    if(microtime(true)-$started>45)break;
    try{booking_lifecycle_sync($db,$row['reference']);++$count;}
    catch(Throwable){++$errors;booking_lifecycle_set($db,$row['reference'],['checked_at'=>time(),'diagnostic'=>'Scheduled reconciliation could not verify this appointment. Existing reservations remain held; staff review is required.']);}
}
echo gmdate('c')." Reconciled: $count; review required: $errors. No calendar or mail writes.\n";
