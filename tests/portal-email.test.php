<?php
declare(strict_types=1);
$completed=false;
register_shutdown_function(static function()use(&$completed):void{if(!$completed){fwrite(STDERR,"Email assertions did not complete.\n");exit(1);}});
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://portal-test.example');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=isolated-email-secret-not-production-1234567890');
putenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1');
$dir=sys_get_temp_dir().'/sitesee-email-'.bin2hex(random_bytes(8));mkdir($dir,0700);
putenv('SITESEE_REAL_ESTATE_BOOKING_DB='.$dir.'/bookings.sqlite');
require __DIR__.'/../_private/server/portal-email.php';
require __DIR__.'/../_private/server/portal-purchase.php';
require __DIR__.'/../_private/server/portal-mail.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejects(callable $call,string $label):void{try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Accepted: '.$label);}
try {
 $db=booking_db();portal_access_schema($db);portal_phone_schema($db);portal_purchase_schema($db);portal_email_schema($db);
 $one=str_repeat('a',32);$two=str_repeat('b',32);$secret=SITESEE_REAL_ESTATE_PRICING_GATE_SECRET;
 foreach([[$one,'one@example.com','3125550100'],[$two,'two@example.com','3125550101']] as [$id,$email,$phone]){
  $db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,1000]);
  portal_phone_enroll($db,$email,$phone,'Synthetic staff verification',static fn()=>true,1000);
 }
 $session=static function($phone)use($db):void{$p=portal_phone_identity($db,portal_normalize_phone($phone));$_SESSION=['portal_phone'=>['phone'=>$p['phone'],'revision'=>$p['revision']]];};
 $session('3125550100');$mail=[];$send=static function($email,$code)use(&$mail):bool{$mail[]=[$email,$code];return true;};
 $time=10000;
 $request=static function($email)use($db,$one,$secret,$send,&$time):string{$time+=901;return portal_email_request($db,$one,portal_active_account($db,$one)['email'],$email,'fixture-ip',$secret,$send,$time);};
 $confirm=static fn($id,$code,$at)=>portal_email_confirm($db,$one,$id,$code,$secret,$at);
 $payload=['market'=>'residential','details'=>['first'=>'Fixture','last'=>'Agent','company'=>'Synthetic','phone'=>'3125550100','street'=>'101 Example','city'=>'Chicago','state'=>'IL','zip'=>'60601'],
  'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],
  'appointment'=>['date'=>(new DateTimeImmutable('+8 days'))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>false,'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]];
portal_profile_schema($db);foreach($db->query('SELECT id FROM portal_accounts')->fetchAll(PDO::FETCH_COLUMN) as $profileId)portal_save_profile($db,$profileId,['first_name'=>'Fixture','last_name'=>'Agent','company'=>'Synthetic','phone'=>'3125550100']);
 $review=portal_purchase_review($db,$one,$payload);$ref=portal_purchase_submit($db,$one,$review['review']);
 $payload['details']['street']='An unsubmitted order';$stale=portal_purchase_review($db,$one,$payload);
 $payload['details']['street']='Interrupted capture';$gap=portal_purchase_review($db,$one,$payload);
 try{portal_purchase_submit($db,$one,$gap['review'],static function(...$args){booking_capture(...$args);throw new RuntimeException('fixture interruption');});}catch(RuntimeException){}
 $before=$db->query('SELECT * FROM bookings ORDER BY reference')->fetchAll();$phones=$db->query('SELECT * FROM portal_phone_identities ORDER BY phone')->fetchAll();
 $owners=$db->query('SELECT * FROM portal_order_owners ORDER BY reference')->fetchAll();
 $id=$request(' NEW@Example.com ');$code=$mail[array_key_last($mail)][1];
 check(portal_active_account($db,$one)['email']==='one@example.com','Request does not activate email');
 $stored=json_encode($db->query('SELECT * FROM portal_email_changes')->fetchAll());check(!str_contains($stored,$code)&&!str_contains($stored,$id),'No raw code or capability persisted');
 check(!$confirm(str_repeat('e',64),$code,$time+1),'Other session capability rejected');
 $session('3125550101');check(!portal_email_confirm($db,$two,$id,$code,$secret,$time+1),'Cross-account verification rejected');
 check(!$confirm($id,$code,$time+1),'Wrong phone session rejected');$session('3125550100');
 check($confirm($id,$code,$time+2),'Verified change');check(!$confirm($id,$code,$time+3),'One use');
 check(portal_active_account($db,$one)['email']==='new@example.com','Normalized email activated');
 check($phones===$db->query('SELECT * FROM portal_phone_identities ORDER BY phone')->fetchAll(),'Phone identity byte-equivalent');
 check($before===$db->query('SELECT * FROM bookings ORDER BY reference')->fetchAll(),'Bookings untouched');
 check($owners===$db->query('SELECT * FROM portal_order_owners ORDER BY reference')->fetchAll(),'Existing ownership untouched');
 check(portal_owns_order($db,$one,$ref),'Linked history retained');
 check(portal_purchase_submit($db,$one,$review['review'])===$ref,'Existing submission remains stable');
 check(portal_purchase_submit($db,$one,$gap['review'])!=='','Captured gap recovers despite email change');
 rejects(fn()=>portal_purchase_submit($db,$one,$stale['review']),'Stale email draft blocked at capture');
 $fresh=portal_purchase_review($db,$one,$payload);check($fresh['details']['email']==='new@example.com','New order uses current account email');
 // Case-insensitive duplicates, including disabled accounts, cannot be activated.
 rejects(fn()=>$request('TWO@example.com'),'Duplicate request');$db->exec("UPDATE portal_accounts SET disabled=1 WHERE id='$two'");
 rejects(fn()=>$request('two@example.com'),'Disabled duplicate');$db->exec("UPDATE portal_accounts SET disabled=0 WHERE id='$two'");
 rejects(fn()=>$request('NEW@example.com'),'Same address');rejects(fn()=>$request("bad@example.com\r\nBcc:other@example.com"),'Header injection');
 // Fresh requests invalidate prior pending codes, even if the address matches.
 $id=$request('next@example.com');$oldCode=$mail[array_key_last($mail)][1];$id2=$request('next@example.com');$code=$mail[array_key_last($mail)][1];
 check(!$confirm($id,$oldCode,$time),'Replaced challenge rejected');check(!$confirm($id2,$code,$time+900),'Expiry boundary');
 $id=$request('guess@example.com');$code=$mail[array_key_last($mail)][1];$wrong=$code==='00000000'?'11111111':'00000000';
 for($i=0;$i<5;$i++)check(!$confirm($id,$wrong,$time+1),'Wrong guess');check(!$confirm($id,$code,$time+2),'Five-guess cap');
 $id=$request('cancel@example.com');$code=$mail[array_key_last($mail)][1];portal_email_cancel($db,$one,$id); // Production clock exceeds synthetic expiry; explicit cancellation tested below.
 $db->exec("UPDATE portal_email_changes SET expires_at=".(time()+900)." WHERE account_id='$one'");portal_email_cancel($db,$one,$id);check(!$confirm($id,$code,$time+1),'Cancellation');
 $time+=86401;$id=$request('taken@example.com');$code=$mail[array_key_last($mail)][1];
 $db->prepare('UPDATE portal_accounts SET email=? WHERE id=?')->execute(['TAKEN@example.com',$two]);check(!$confirm($id,$code,$time+1),'Duplicate arriving during delivery');
 $id=$request('disabled@example.com');$code=$mail[array_key_last($mail)][1];$db->exec("UPDATE portal_accounts SET disabled=1 WHERE id='$one'");check(!$confirm($id,$code,$time+1),'Disabled account');$db->exec("UPDATE portal_accounts SET disabled=0 WHERE id='$one'");
 $id=$request('revision@example.com');$code=$mail[array_key_last($mail)][1];$db->exec("UPDATE portal_phone_identities SET revision='changed' WHERE account_id='$one'");check(!$confirm($id,$code,$time+1),'Changed phone revision');$session('3125550100');
 $id=$request('old-email@example.com');$code=$mail[array_key_last($mail)][1];$db->prepare('UPDATE portal_accounts SET email=? WHERE id=?')->execute(['manual@example.com',$one]);check(!$confirm($id,$code,$time+1),'Concurrent account change');
 // Failed/uncertain mail and delayed success cannot reanimate an earlier request.
 $time+=901;rejects(fn()=>portal_email_request($db,$one,'manual@example.com','failed@example.com','fixture-ip',$secret,static fn()=>false,$time),'Failed handoff');
 $time+=901;rejects(fn()=>portal_email_request($db,$one,'manual@example.com','uncertain@example.com','fixture-ip',$secret,static function(){throw new RuntimeException('uncertain');},$time),'Uncertain handoff');
 $time+=86401;$later=null;$laterCode=null;
 $delayed=static function($email,$code)use($db,$one,$secret,$time,&$later,&$laterCode):bool{
  $later=portal_email_request($db,$one,'manual@example.com','winner@example.com','fixture-ip',$secret,static function($email,$code)use(&$laterCode){$laterCode=$code;return true;},$time+61);return true;
 };
 rejects(fn()=>portal_email_request($db,$one,'manual@example.com','loser@example.com','fixture-ip',$secret,$delayed,$time),'Late handoff replaced');check($confirm($later,$laterCode,$time+62),'Newer request survives');
 $time+=86401;$id=$request('rate@example.com');$count=count($mail);
 rejects(fn()=>portal_email_request($db,$one,'winner@example.com','rate2@example.com','fixture-ip',$secret,$send,$time+1),'Cooldown');check(count($mail)===$count,'Rate rejection sends nothing');
 // Historical claim rechecks email under lock after trusted proof evaluation.
 $proof=static function($ref,$token)use($db,$one){$db->prepare('UPDATE portal_accounts SET email=? WHERE id=?')->execute(['raced@example.com',$one]);return ['reference'=>$ref,'email'=>'winner@example.com'];};
 check(!portal_claim_existing_order($db,$one,'ABCDEF123456','fixture',$proof),'Historical email race rejected');
 $proof=static fn($ref,$token)=>['reference'=>$ref,'email'=>'one@example.com'];check(!portal_claim_existing_order($db,$one,'ABCDEF123456','fixture',$proof),'Retired email cannot automatically claim');
 // This test must never attempt the real transport; an unrecognized PHP mail path fails closed.
 check(!preg_match('~^/usr/local/bin/sitesee-graph-sendmail(?:\s+-(?:t|i))*$~D',trim((string)ini_get('sendmail_path'))),'Run fixtures with an isolated sendmail_path');
 check(!portal_send_email_code('fixture@example.com','12345678'),'Unknown transport refused');
 $message=portal_email_message('fixture@example.com','12345678');check(in_array('From: SiteSee Real Estate <sales@re.sitesee.ai>',$message['headers'],true),'Fixed RE sender');
 check(!str_contains($message['body'],'http'),'No verification capability in a URL');
 $completed=true;echo "portal-email: PASS (identity; verification; expiry/reuse; duplicates; revisions; rates; mail ambiguity; races; history; drafts; captured recovery; fixed sender)\n";
}finally{unset($db);foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
