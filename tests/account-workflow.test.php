<?php
declare(strict_types=1);
$complete=false;$checks=0;
register_shutdown_function(static function()use(&$complete):void{if(!$complete){fwrite(STDERR,"Account workflow assertions did not complete.\n");exit(1);}});
$dir=sys_get_temp_dir().'/sitesee-account-flow-'.bin2hex(random_bytes(6));mkdir($dir,0700);
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$dir.'/bookings.sqlite');putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-account-workflow-secret-1234567890');
require_once __DIR__.'/../_private/server/portal-purchase.php';
require_once __DIR__.'/../_private/server/booking-job.php';
require_once __DIR__.'/../_private/server/vendor-access.php';
require_once __DIR__.'/../_private/server/booking-list-ui.php';
require_once __DIR__.'/../_private/views/portal.php';
function check(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException){check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
try{
 $db=booking_db();portal_access_schema($db);portal_profile_schema($db);portal_purchase_schema($db);booking_communication_schema($db);booking_job_schema($db);vendor_schema($db);
 foreach(['2026-01-01','2026-04-03','2026-04-05','2026-05-25','2026-07-04','2026-09-07','2026-11-26','2026-12-25','2027-03-26','2027-03-28','2027-05-31','2027-09-06','2027-11-25'] as $holiday){
  check(booking_window_times($holiday)===[],'Blocked holiday '.$holiday);
  rejects(fn()=>real_estate_arrival_window(['date'=>$holiday,'time'=>'13:30']),'Server refuses closed-day submission');
 }
 check(booking_closed_day('2027-06-19')===null&&booking_closed_day('2027-01-18')===null,'Other federal holidays remain available');
 check(booking_window_times('2026-07-05')===['13:30','15:30','17:30'],'Actual holiday dates only; following Sunday starts13:30');
 rejects(fn()=>real_estate_arrival_window(['date'=>'2026-10-11','time'=>'13:00']),'Sunday before1:30 refused');
 foreach(['13:30'=>'15:30','15:30'=>'17:30','17:30'=>'19:30'] as $start=>$end)check(real_estate_arrival_window(['date'=>'2026-10-11','time'=>$start])['windowEnd']===$end,'Sunday minutes preserved');
 check(booking_arrival_start('2026-11-01','13:30')->setTimezone(new DateTimeZone('UTC'))->format('H:i')==='19:30','Fall Central offset preserves minutes');
 $one=str_repeat('a',32);$two=str_repeat('b',32);
 foreach([[$one,'one@example.com'],[$two,'two@example.com']] as [$id,$email])$db->prepare('INSERT INTO portal_accounts VALUES(?,?,?,0)')->execute([$id,$email,time()]);
 foreach(['first_name','last_name','company','phone'] as $missing){$profile=['first_name'=>'Test','last_name'=>'Agent','company'=>'Synthetic','phone'=>'3125550100'];unset($profile[$missing]);rejects(fn()=>portal_save_profile($db,$one,$profile),'Missing field '.$missing);}
 rejects(fn()=>portal_save_profile($db,$one,['first_name'=>'Test','last_name'=>'Agent','company'=>'Synthetic','phone'=>'not a phone']),'Malformed contact phone');
 foreach([$one,$two] as $id)portal_save_profile($db,$id,['first_name'=>'Test','last_name'=>'Agent','company'=>'Synthetic','phone'=>'3125550100']);
 $payload=['market'=>'residential','details'=>['first'=>'Test','last'=>'Agent','company'=>'Synthetic','phone'=>'3125550100','street'=>'101 Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],
  'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],
  'appointment'=>['date'=>'2027-01-06','time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]];
 foreach([['2027-01-01','09:00'],['2026-10-18','13:00']] as [$date,$time]){
  $old=$payload;$old['details']['email']='one@example.com';$old['appointment']['date']=$date;$old['appointment']['time']=$time;
  try{real_estate_prepare_submission(['version'=>1,'action'=>'request_appointment']+$old,new DateTimeImmutable('2026-10-09'));throw new RuntimeException('Legacy policy bypass accepted.');}
  catch(InvalidArgumentException $error){check(str_contains($error->getMessage(),'Sundays start at 1:30 PM Central'),'Legacy form rejects at business-hours policy, not lead time');}
 }
 $old['appointment']['time']='13:30';check(real_estate_prepare_submission(['version'=>1,'action'=>'request_appointment']+$old,new DateTimeImmutable('2026-10-09'))['appointment']['time']==='13:30','Legacy valid future Sunday control passes');
 $review=portal_purchase_review($db,$one,$payload);$ref=portal_purchase_submit($db,$one,$review['review']);
 $alias=booking_order_number($db,$ref);check((bool)preg_match('/^[A-Z0-9]{8}$/D',$alias),'Random eight-character public number');
 check(booking_order_number($db,$ref)===$alias,'Display number is stable');
 $original=booking_get($db,$ref);check(portal_owned_order($db,$one,$ref)['order_number']===$alias,'Owned order displays alias');
 check(!portal_owned_order($db,$two,$ref),'Alias does not grant ownership');
 rejects(fn()=>booking_order_number($db,'0000000000'),'No alias for nonexistent order');
 $capturePayload=$payload;$capturePayload['details']['email']='one@example.com';$base=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment']+$capturePayload);
 // Preserve a still-valid review made before subjects changed, including capture/bind identity.
 $payload['details']['street']='Legacy saved review';$legacy=portal_purchase_review($db,$one,$payload);
 $intent=portal_submission($db,$one,$legacy['review']);$saved=json_decode($intent['submission_json'],true);$saved['subject']=$saved['quote']['subject'];$bytes=json_encode($saved,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 $db->prepare('UPDATE portal_submissions SET submission_json=?,submission_hash=? WHERE id=?')->execute([$bytes,hash('sha256',$bytes),$legacy['review']]);
 $legacyRef=portal_purchase_submit($db,$one,$legacy['review']);check(booking_get($db,$legacyRef)['request_json']===$bytes,'Legacy subject transition preserves exact saved submission');
 $numbers=[];
 for($i=0;$i<32;$i++){$r=sprintf('ABC%07X',$i);booking_capture($db,$base,$r,true);$db->prepare('INSERT INTO portal_order_owners VALUES(?,?,?,?)')->execute([$r,$i===31?$two:$one,'fixture',time()]);$numbers[]=$r;
  if($i<7)$db->prepare("INSERT INTO booking_jobs(reference,scope,bill_json,amount,customer,completed_at,production_complete_at) VALUES(?,'fixture','{}',0,'fixture-customer','complete','published')")->execute([$r]);
 }
 $db->prepare("INSERT INTO booking_jobs(reference,scope,bill_json,amount,customer,completed_at) VALUES(?,'fixture','{}',0,'fixture-customer','onsite-only')")->execute([$numbers[7]]);
 $open=booking_order_list($db,$one,'open');$previous=booking_order_list($db,$one,'previous');
 check($open['total']===26&&count($open['orders'])===5&&$open['pages']===6,'Default open list five rows with correct independent pages');
 check($previous['total']===7&&count($previous['orders'])===5&&$previous['pages']===2,'Previous list counts only Production complete');
 check(count(booking_order_list($db,$one,'open',1,'25')['orders'])===25,'25 rows selectable');
 $all=booking_order_list($db,$one,'open',1,'all');check(count($all['orders'])===26&&$all['pages']===1,'All owned open orders selectable');
 check(in_array($numbers[7],array_column($all['orders'],'reference'),true),'Onsite completion stays open');
 check(!in_array($numbers[31],array_column($all['orders'],'reference'),true),'Other customer excluded from every group');
 check(booking_order_list($db,null,'open',1,'all')['total']===27,'Authenticated management scope includes both customers');
 check(booking_order_list($db,$one,'open',999)['page']===6,'Out-of-range page is clamped');
 $q=booking_list_options(['open_size'=>'25','previous_page'=>'2','show'=>'both']);
 $html=booking_lists_html(['open'=>booking_order_list($db,$one,'open',1,'25'),'previous'=>booking_order_list($db,$one,'previous',2)],$q,'/account.php',[],fn($r)=>'<p>'.$r['order_number'].'</p>');
 check(str_contains($html,'open_page=2')&&str_contains($html,'previous_page=2'),'Open paging preserves previous column position');
 check(strpos($html,'class="order-list-options"')>strpos($html,'aria-label="Previous Orders Pages"'),'Display preferences follow both lists');
 check(str_contains($html,'data-auto-list')&&str_contains($html,'name="previous_page" value="2"'),'Automatic GET preferences preserve the other page');
 $db->prepare('DELETE FROM booking_order_numbers WHERE reference=?')->execute([$numbers[30]]);$attempt=0;
 $collision=booking_order_number($db,$numbers[30],static function()use(&$attempt,$alias){return $attempt++===0?$alias:'Z9Y8X7W6';});
 check($collision==='Z9Y8X7W6'&&$attempt===2,'Collision retries safely and never changes another order number');
 $vendor=vendor_save($db,'','','Selected Vendor','3125550199',true);$v=vendor_get($db,$vendor);
 $db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=? WHERE reference=?")->execute([gmdate('c'),$ref]);
 rejects(fn()=>vendor_review_paid($db,$ref,$vendor,'stale',90,true),'Stale vendor revision rejected');
 check(!booking_get($db,$ref)['approved_at'],'Failed selection does not approve review');
 vendor_review_paid($db,$ref,$vendor,$v['revision'],90,true);$grant=vendor_assignment($db,$ref);
 check($grant['vendor_id']===$vendor&&booking_get($db,$ref)['photographer']==='Selected Vendor','Paid review and selected grant commit together');
 check(!vendor_review_grant_ready($db,$grant),'Vendor cannot see unconfirmed job');
 rejects(fn()=>vendor_require_job($db,$v,$ref),'Forged pending job URL denied');
 $db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at) VALUES(?,'confirmed','microsoft:fixture','event',0,1,'{}',?)")->execute([$ref,gmdate('c')]);
 check(vendor_require_job($db,$v,$ref)['assignment']['revision']===$grant['revision'],'Original selected grant becomes accessible after confirmation');
 $failRef=$numbers[29];$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=? WHERE reference=?")->execute([gmdate('c'),$failRef]);$db->prepare("UPDATE booking_scheduling SET rush_status='pending' WHERE reference=?")->execute([$failRef]);$beforeReview=booking_get($db,$failRef);$audit=$db->query('SELECT * FROM vendor_audit')->fetchAll();
 $db->exec("CREATE TRIGGER reject_test_grant BEFORE INSERT ON vendor_assignments BEGIN SELECT RAISE(ABORT,'isolated grant failure'); END");
 try{vendor_review_paid($db,$failRef,$vendor,$v['revision'],90,true,'approve');throw new RuntimeException('Grant insertion failure did not abort');}catch(PDOException){check(true,'Grant insert failure aborts review');}
 $db->exec('DROP TRIGGER reject_test_grant');
 check(booking_get($db,$failRef)===$beforeReview&&!vendor_assignment($db,$failRef)&&$db->query('SELECT * FROM vendor_audit')->fetchAll()===$audit,'Approval, rush, vendor grant and audit all roll back together');
 check(booking_get($db,$ref)['stripe_session_id']===$original['stripe_session_id']&&booking_get($db,$ref)['request_json']===$original['request_json'],'Review and aliases preserve financial and request identities');
 $order=portal_owned_order($db,$one,$ref)+['can_manage_appointment'=>false,'can_view_job'=>false];$order['deposit_paid_cents']=0;$order['portal_payment']=true;
 $page=portal_order_body($order,['profile_complete'=>true]);check(!str_contains($page,'Manage Appointment')&&!str_contains($page,'Job &amp; Deliverables')&&!str_contains($page,'Order Again'),'Untriggered customer actions absent');
 check(str_contains($page,'Pay Test Deposit')&&str_contains($page,$alias),'Eligible deposit step and public number rendered');
 $saved=$order;$saved['job_closed']=true;$saved['appointment_status']='Cancelled';
 $page=portal_order_body($saved,['profile_complete'=>true]);
 check(str_contains($page,'Job & Deliverables')&&str_contains($page,'view=job'),'Saved job recovery and deliverables remain reachable after cancellation');
 check(booking_property_subject('[TEST] Appointment Declined',['street'=>"101 Example\r\nBcc: bad",'city'=>'Chicago'])==='[TEST] Appointment Declined | 101 Example Bcc: bad Chicago','Subject cannot inject headers');
 $complete=true;echo "Account workflow: $checks checks passed (profile, scoped paging, collision, state triggers, Vendor review, identity preservation, holidays/minutes and legacy drafts).\n";
}finally{unset($db);foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);}
