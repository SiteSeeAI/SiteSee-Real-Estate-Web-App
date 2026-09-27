<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/sitesee-link-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.str_repeat('x',48));putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$tmp.'/bookings.sqlite');
require_once __DIR__.'/../_private/server/booking-invitation.php';
define('SITESEE_CONTACT_LINK_TEST_LIBRARY',true);require_once __DIR__.'/../tools/link-microsoft-test-contact.php';
$db=booking_db();booking_communication_schema($db);$checks=0;
function ok(bool $v,string $why):void{global $checks;++$checks;if(!$v)throw new RuntimeException($why);}
function no(callable $f,string $why):void{try{$f();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$why);return;}throw new RuntimeException($why);}
$day=(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');
foreach(['E4E51A0481','D32FFC7458']as$ref){
 $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
 'details'=>['first'=>'David','last'=>'Cro','company'=>'SiteSee','email'=>'cro@sitesee.ai','phone'=>'5555550100','street'=>'123 Main','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],
 'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
 'appointment'=>['date'=>$day,'time'=>'13:00','windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'Yes','cancellationAccepted'=>true]]);
 booking_capture($db,$s,$ref,true);$db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=?,approved_at=? WHERE reference=?")->execute([gmdate('c'),gmdate('c'),$ref]);
 $db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at,confirmed_at,invitation_state,invitation_recipient) VALUES(?,'confirmed',?,'event',100,5800,'{}',?,?,?,?)")
 ->execute([$ref,$ref==='E4E51A0481'?str_repeat('a',32):'microsoft:'.BOOKING_MS_CALENDAR,gmdate('c'),gmdate('c'),$ref==='E4E51A0481'?'sent':'none',$ref==='E4E51A0481'?'cro@sitesee.ai':null]);
}
$config=['org_id'=>'100','user_id'=>'200'];$calls=0;$wrongOrg=false;$wrongEmail=false;
$crm=static function($method,$path)use(&$calls,&$wrongOrg,&$wrongEmail):array{
 ++$calls;ok($method==='GET','Only provider reads are permitted.');
 if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>$wrongOrg?'999':'100']]]];
 if(str_starts_with($path,'/users'))return ['status'=>200,'body'=>['users'=>[['id'=>'200']]]];
 if(str_starts_with($path,'/Contacts/search'))return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>'cro@sitesee.ai']], 'info'=>['more_records'=>false]]];
 if($path==='/Contacts/300')return ['status'=>200,'body'=>['data'=>[['id'=>'300','Email'=>$wrongEmail?'wrong@example.com':'cro@sitesee.ai']]]];
 throw new RuntimeException('Unexpected provider request');
};
no(fn()=>sitesee_link_test_contact($db,$config,$crm),'Missing prior selection blocks guessing.');ok($calls===0,'Missing history makes no provider call.');
booking_crm_link($db,'E4E51A0481','300',$config,$crm);
$before=booking_get($db,'D32FFC7458');$sourceBefore=booking_get($db,'E4E51A0481');$claimBefore=booking_confirmation_get($db,'D32FFC7458');
$wrongOrg=true;no(fn()=>sitesee_link_test_contact($db,$config,$crm),'Wrong CRM org blocks linking.');$wrongOrg=false;
$wrongEmail=true;no(fn()=>sitesee_link_test_contact($db,$config,$crm),'Changed contact email blocks linking.');$wrongEmail=false;
$db->exec("UPDATE bookings SET crm_contact_id='999' WHERE reference='D32FFC7458'");no(fn()=>sitesee_link_test_contact($db,$config,$crm),'Existing different contact is preserved.');$db->exec("UPDATE bookings SET crm_contact_id=NULL WHERE reference='D32FFC7458'");
$result=sitesee_link_test_contact($db,$config,$crm);$after=booking_get($db,'D32FFC7458');$expected=$before;$expected['crm_contact_id']='300';
ok($after===$expected,'Only target local CRM ID changes; payment and scheduling preserved.');
ok(booking_get($db,'E4E51A0481')===$sourceBefore,'Original booking unchanged.');ok(booking_confirmation_get($db,'D32FFC7458')===$claimBefore,'Calendar and invitation state unchanged.');ok($result['invitation_state']==='none'&&$result['communications']===0,'Saved send evidence reported accurately.');
ok(sitesee_link_test_contact($db,$config,$crm)===$result,'Unchanged rerun succeeds without duplicate links.');
ok((int)$db->query('SELECT COUNT(*) FROM booking_contact_links')->fetchColumn()===2,'One contact link per booking.');
foreach(['sending','uncertain','sent']as$state){$db->exec("UPDATE booking_confirmations SET invitation_state='$state' WHERE reference='D32FFC7458'");no(fn()=>sitesee_link_test_contact($db,$config,$crm),'Any prior invitation attempt blocks a retry recommendation.');}
$db->exec("UPDATE booking_confirmations SET invitation_state='none' WHERE reference='D32FFC7458'");
booking_communication_enqueue($db,'D32FFC7458','invitation',['from'=>BOOKING_MAIL_SENDER,'to'=>'cro@sitesee.ai','subject'=>'fixture']);no(fn()=>sitesee_link_test_contact($db,$config,$crm),'A saved communication also blocks a retry recommendation.');
$db=null;foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);echo "Microsoft TEST contact reuse: $checks checks passed\n";
