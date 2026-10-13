<?php
declare(strict_types=1);
$checks=0;$complete=false;
register_shutdown_function(static function()use(&$complete):void{if(!$complete){fwrite(STDERR,"Report assertions did not complete.\n");exit(1);}});
$dir=sys_get_temp_dir().'/sitesee-reports-'.bin2hex(random_bytes(6));mkdir($dir,0700);
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$dir.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=report-synthetic-local-secret-1234567890');
require_once __DIR__.'/../_private/server/portal-billing.php';require_once __DIR__.'/../_private/server/portal-reports.php';require_once __DIR__.'/../_private/views/portal.php';
function ok(bool $value,string $label):void{global $checks;$checks++;if(!$value)throw new RuntimeException($label);}
function nope(callable $fn):void{try{$fn();}catch(InvalidArgumentException){ok(true,'Invalid date/account rejected');return;}throw new RuntimeException('Invalid report accepted');}
try{
 $now=new DateTimeImmutable('2026-10-13T03:00:00Z');$range=portal_report_range(['period'=>'month'],$now);
 ok($range['from']==='2026-10-01'&&$range['to']==='2026-10-31','Month Central boundary');
 ok(portal_report_range(['period'=>'quarter'],$now)['to']==='2026-12-31','Quarter includes full current quarter');
 ok(portal_report_range(['period'=>'year'],$now)['from']==='2026-01-01','Year preset');
 $dst=portal_report_range(['period'=>'custom','from'=>'2026-11-01','to'=>'2026-11-01']);ok($dst['end']-$dst['start']===25*3600,'DST full Central day');
 foreach([['period'=>[]],['period'=>'custom','from'=>[],'to'=>'2026-11-01'],['period'=>'custom','from'=>'2026-02-29','to'=>'2026-11-01'],['period'=>'custom','from'=>'2026-11-02','to'=>'2026-11-01']] as $input)nope(fn()=>portal_report_range($input));
 $db=booking_db();portal_access_schema($db);portal_billing_schema($db);booking_job_schema($db);booking_finance_schema($db);
 $one=str_repeat('a',32);$two=str_repeat('b',32);foreach([[$one,'one@example.com'],[$two,'two@example.com']] as [$id,$email])$db->prepare('INSERT INTO portal_accounts VALUES(?,?,?,0)')->execute([$id,$email,time()]);
 $payload=['version'=>2,'action'=>'request_appointment','market'=>'residential','details'=>['first'=>'Test','last'=>'Agent','company'=>'Synthetic','email'=>'one@example.com','phone'=>'3125550100','street'=>'123 Résumé','city'=>'Chicago','state'=>'IL','zip'=>'60601'],'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],'appointment'=>['date'=>'2027-01-06','time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'Yes','cancellationAccepted'=>true]];
 $submission=real_estate_prepare_submission($payload,new DateTimeImmutable('2026-10-13'));$refs=['ABC0000001','ABC0000002','ABC0000003'];
 foreach($refs as $i=>$ref){booking_capture($db,$submission,$ref,true);$db->prepare('INSERT INTO portal_order_owners VALUES(?,?,?,?)')->execute([$ref,$i===2?$two:$one,'fixture',time()]);$db->prepare("UPDATE bookings SET deposit_cents=20000,deposit_paid_at=?,stripe_payment_intent_id=? WHERE reference=?")->execute(['2026-10-01T05:00:00+00:00','pi_report_'.$i,$ref]);}
 $db->prepare('INSERT INTO booking_finance_funding VALUES(?,?,?,?,?,?,?,?)')->execute([$refs[1],$one,5000,15000,$refs[0],'cus_report','pi_card',time()]);
 $db->prepare("INSERT INTO portal_balance_attempts(reference,attempt,account_id,amount,scope,customer,state,created_at,request_json,payment_intent,paid_at,consent_text,consent_at) VALUES(?,1,?,8000,'scope','cus_report','paid',0,'{}','pi_balance','2026-10-02T05:00:00+00:00','consent',0)")->execute([$refs[0],$one]);
 $db->prepare("INSERT INTO booking_jobs(reference,scope,bill_json,amount,customer,completed_at,payment_intent,paid_at,payment_state) VALUES(?,'scope','{}',4000,'cus_report','2026-10-03T05:00:00+00:00','pi_final','2026-10-03T05:00:00+00:00','paid')")->execute([$refs[0]]);
 $db->prepare("INSERT INTO booking_finance_cancellations(operation_id,reference,actor,requested_at,appointment_start,rule,account_id) VALUES('op',?,'staff',0,1,'management_refund',?)")->execute([$refs[0],$one]);
 $db->prepare("INSERT INTO booking_finance_refunds(id,operation_id,payment_intent,charge,amount,request_json,created_at,submitted_at,refund_id,state,checked_at) VALUES('refund','op','pi_report_0','ch_report',20000,'{}',?,?, 're_report','succeeded',?)")->execute([strtotime('2026-10-04T05:00Z'),strtotime('2026-10-04T05:00Z'),strtotime('2026-11-04T05:00Z')]);
 $db->prepare("INSERT INTO booking_finance_refund_dates VALUES('re_report',?)")->execute([strtotime('2026-10-04T05:00Z')]);
 $db->prepare("INSERT INTO booking_finance_credits VALUES('credit','op',?,?,15000,0)")->execute([$refs[0],$one]);$db->prepare("INSERT INTO booking_finance_allocations VALUES('allocation','credit',?,'deposit',15000,'used',0,NULL)")->execute([$refs[1]]);
 $before=$db->query('SELECT * FROM bookings')->fetchAll();$ledger=$db->query('SELECT * FROM booking_finance_refunds')->fetchAll();
 $report=portal_expense_report($db,$one,$range);
 ok($report['paid']===37000&&$report['refunded']===20000&&$report['net']===17000&&$report['credit']===15000,'Cash, credit, refund, balance and final payment counted exactly once');
 ok(count($report['entries'])===6,'No unknown, pending or other-account entry');
 ok($db->query('SELECT * FROM bookings')->fetchAll()===$before&&$db->query('SELECT * FROM booking_finance_refunds')->fetchAll()===$ledger,'Report changes no payment/refund record');
 $other=portal_expense_report($db,$two,$range);ok($other['paid']===20000&&$other['refunded']===0&&count($other['entries'])===1,'Other-account refund/credit excluded');
 $empty=portal_expense_report($db,$one,portal_report_range(['period'=>'custom','from'=>'2026-09-01','to'=>'2026-09-30']));ok(!$empty['entries']&&$empty['net']===0,'Boundary excludes pre-month events');
 $missing=$db->query('SELECT * FROM booking_finance_refund_dates')->fetchAll();$db->exec('DELETE FROM booking_finance_refund_dates');ok(portal_expense_report($db,$one,$range)['net']===null,'Undated historical confirmed refunds never generate a misleading net');$db->prepare('INSERT INTO booking_finance_refund_dates VALUES(?,?)')->execute(['re_report',strtotime('2026-11-04T05:00Z')]);ok(portal_expense_report($db,$one,$range)['refunded']===0,'Refund submitted in October but confirmed in November belongs to November');$db->exec('DELETE FROM booking_finance_refund_dates');$db->prepare('INSERT INTO booking_finance_refund_dates VALUES(?,?)')->execute(array_values($missing[0]));
 $db->exec('UPDATE booking_jobs SET refunded=100');ok(portal_expense_report($db,$one,$range)['net']===null,'Known undated closeout refund makes net unavailable');$db->exec('UPDATE booking_jobs SET refunded=0');
 $bad=$report;$bad['entries'][0]['property']='道';nope(fn()=>portal_report_pdf($bad));
 $pdf=portal_report_pdf($report);ok(str_starts_with($pdf,'%PDF-1.4')&&str_ends_with($pdf,"%%EOF\n"),'Real PDF artifact');ok(str_contains($pdf,'/FontFile2')&&str_contains($pdf,'/ToUnicode'),'Embedded Unicode font/text mapping');ok(!str_contains($pdf,'pi_report')&&!str_contains($pdf,$one),'No provider/account IDs');
 file_put_contents($dir.'/report.pdf',$pdf);
 if(is_executable('/usr/bin/pdftotext')){exec('/usr/bin/pdftotext '.escapeshellarg($dir.'/report.pdf').' -',$text,$status);ok($status===0&&str_contains(implode("\n",$text),'123 Résumé'),'Independent PDF parser extracts Unicode property');}
 $large=$report;for($i=0;$i<100;$i++)$large['entries'][]=$report['entries'][0];$pdf=portal_report_pdf($large);ok((bool)preg_match('~/Count ([3-9]|[1-9][0-9]+) /Kids~',$pdf),'Long report paginates');
 // Cancelled lists use applied closure time; archive boundary is strictly older than 14 days.
 $ref=$refs[0];$clock=strtotime('2026-10-15T12:00Z');$db->prepare("INSERT INTO booking_lifecycle(reference,state) VALUES(?,'cancelled')")->execute([$ref]);
 $db->prepare("INSERT INTO booking_lifecycle_operations VALUES('closed',?,1,'cancel','staff','applied','{}',?,?)")->execute([$ref,gmdate('c',$clock-14*86400),gmdate('c',$clock-14*86400)]);
 ok(!in_array($ref,array_column(booking_order_list($db,$one,'open',1,'all',$clock)['orders'],'reference'),true),'Cancellation instantly leaves customer Open');
 ok(in_array($ref,array_column(booking_order_list($db,null,'previous',1,'all',$clock)['orders'],'reference'),true),'Exactly14days remains previous for staff');
 ok(in_array($ref,array_column(booking_order_list($db,$one,'archive',1,'all',$clock+1)['orders'],'reference'),true),'Older than14days moves archive');
 ok(!in_array($ref,array_column(booking_order_list($db,$one,'previous',1,'all',$clock+1)['orders'],'reference'),true),'No duplicate archived/previous row');
 ok(booking_order_list($db,$two,'archive',1,'all',$clock+1)['total']===0,'Archive ownership remains enforced');
 ok(in_array($ref,array_column(booking_order_list($db,$one,'history',1,'all',$clock+1)['orders'],'reference'),true),'Billing history includes archived orders');
 $db->prepare('UPDATE portal_accounts SET disabled=1 WHERE id=?')->execute([$one]);nope(fn()=>portal_expense_report($db,$one,$range));
 $complete=true;echo "Client reports/archive: $checks checks passed (owned recorded expenses, Central/DST ranges, cash/credit/refunds, real Unicode PDF and cancellation/archive boundaries).\n";
}finally{unset($db);foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);}
