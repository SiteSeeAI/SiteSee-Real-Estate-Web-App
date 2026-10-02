<?php
declare(strict_types=1);
$completed=false;register_shutdown_function(static function()use(&$completed):void{if(!$completed){fwrite(STDERR,"Recipient assertions did not complete.\n");exit(1);}});
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-recipient-test-secret-1234567890');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
require __DIR__.'/../_private/server/booking-lifecycle-ui.php';
function check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label); }
function rejects(callable $fn,string $label):void { try{$fn();}catch(Throwable){return;}throw new RuntimeException('Accepted: '.$label); }
$agent='info@1789media.com';$sales='sales@re.sitesee.ai';
foreach([$sales,$agent,strtoupper($agent)] as $email)check(booking_test_recipient_allowed($email),'Approved exact address');
foreach(['info@1789meidia.com','info+test@1789media.com','cro@sitesee.ai','other@example.test',' '.$agent,$agent."\r\n"] as $email)check(!booking_test_recipient_allowed($email),'Reject unapproved variation');
check(booking_test_recipient_matches($agent,$sales),'Second recipient under existing sales primary');
check(!booking_test_recipient_matches($agent,'cro@sitesee.ai'),'Legacy primary not extended');
check(booking_test_recipient_matches('cro@sitesee.ai','cro@sitesee.ai'),'Preserve legacy exact match');
$config=booking_scheduling_ms_config();
$row=['email'=>$agent,'status'=>'deposit_paid_test','deposit_paid_at'=>gmdate('c'),'approved_at'=>gmdate('c'),'reschedule_required'=>0,'rush_status'=>'not_requested','duration_minutes'=>95,'photographer'=>'David J Cro'];
booking_confirmation_gate($config,$row);
foreach([['email'=>'unapproved@example.test'],['status'=>'awaiting_deposit_test'],['deposit_paid_at'=>null],['approved_at'=>null],['reschedule_required'=>1]] as $change)rejects(fn()=>booking_confirmation_gate($config,array_replace($row,$change)),'Existing booking prerequisites');
foreach([['test_recipient_email'=>'cro@sitesee.ai'],['confirmation_enabled'=>false],['enabled'=>false],['confirmation_stage'=>'live']] as $change)rejects(fn()=>booking_confirmation_gate(array_replace($config,$change),$row),'Existing configured prerequisites');
$legacy=$config;unset($legacy['provider']);rejects(fn()=>booking_confirmation_gate($legacy,$row),'Legacy calendar is not extended');
$path='/users/sales%40re.sitesee.ai/messages/synthetic';$etag='W/"fixture"';
$address=static fn($email)=>[['emailAddress'=>['address'=>$email]]];
booking_graph_draft_patch_guard($path,json_encode(['toRecipients'=>$address($agent),'replyTo'=>$address($sales)]),$etag);
foreach([
    ['replyTo'=>$address($agent)],['toRecipients'=>array_merge($address($sales),$address($agent))],
    ['toRecipients'=>$address('unapproved@example.test')],['toRecipients'=>[['emailAddress'=>['address'=>$agent,'name'=>'extra']]]],
    ['toRecipients'=>[]],['ccRecipients'=>$address($agent)],['from'=>['emailAddress'=>['address'=>$sales]]],
] as $body)rejects(fn()=>booking_graph_draft_patch_guard($path,json_encode($body),$etag),'Narrow PATCH envelope');
rejects(fn()=>booking_graph_draft_patch_guard('/users/info%401789media.com/messages/synthetic',json_encode(['toRecipients'=>$address($agent)]),$etag),'External mailbox PATCH');
// Exact primary CRM matching and explicit selection stay unchanged, including old sales records.
$crm=static function($method,$path,$body=null)use($sales,$agent):array{
    check($method==='GET','No CRM mutation');
    $contacts=[['id'=>'101','Email'=>$sales,'Secondary_Email'=>$agent],['id'=>'102','Email'=>$agent]];
    if(str_starts_with($path,'/Contacts/search?'))return ['status'=>200,'body'=>['data'=>$contacts,'info'=>['more_records'=>false]]];
    return ['status'=>200,'body'=>['data'=>[$contacts[$path==='/Contacts/101'?0:1]]]];
};
check(array_column(booking_crm_candidates($agent,$crm),'id')===['102'],'Only matching primary CRM candidate');
rejects(fn()=>booking_crm_verify_contact('101',$agent,$crm),'Cannot reuse historical sales contact for agent');
booking_crm_verify_contact('101',$sales,$crm);booking_crm_verify_contact('102',$agent,$crm);
// Execute the worker selection SQL against isolated rows, never the cron entry point.
$source=file_get_contents(__DIR__.'/../_private/server/booking-lifecycle-reconcile.php');
check((bool)preg_match('/\$sql="(SELECT c\.reference.*?)";/s',$source,$match),'Worker query located');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE bookings(reference TEXT,email TEXT,status TEXT); CREATE TABLE booking_confirmations(reference TEXT,state TEXT,event_uid TEXT,planned_end INTEGER); CREATE TABLE booking_lifecycle(reference TEXT,checked_at INTEGER); CREATE TABLE booking_lifecycle_operations(reference TEXT,state TEXT)');
foreach([['D32FFC7458',$sales],['SYNTHSALES',$sales],['SYNTHAGENT',strtoupper($agent)],['SYNTHOTHER','unapproved@example.test']] as [$ref,$email]){
    $db->prepare('INSERT INTO bookings VALUES (?,?,?)')->execute([$ref,$email,'deposit_paid_test']);
    $db->prepare('INSERT INTO booking_confirmations VALUES (?,?,?,?)')->execute([$ref,'confirmed','synthetic',time()+86400]);
}
$q=$db->prepare($match[1]);$q->execute(booking_test_recipients());$selected=$q->fetchAll(PDO::FETCH_COLUMN);sort($selected);
check($selected===['SYNTHAGENT','SYNTHSALES'],'Cron selects only approved recipients and excludes protected booking');
$mail=['recipient'=>$agent,'delivery_state'=>'unverified'];
check(!booking_communication_receipt_available($mail)&&str_contains(booking_communication_receipt_status($mail),'Unverified'),'External delivery never implied');
$completed=true;echo "PASS approved recipients, unchanged prerequisites, narrow PATCH, exact CRM and protected cron selection\n";
