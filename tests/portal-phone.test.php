<?php
declare(strict_types=1);
require __DIR__.'/../_private/server/portal-phone.php';
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function refuses(callable $fn):void{try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected refusal.');}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);portal_access_schema($db);portal_phone_schema($db);
$approved=static fn($email)=>in_array($email,['one@example.com','two@example.com'],true);$secret=str_repeat('s',40);
check(portal_normalize_phone('(312) 555-0100')==='+13125550100','US normalization');check(portal_normalize_phone('+44 7700 900123')==='+447700900123','International normalization');
foreach(['bad','123','+1+3125550100','3125550100 ext4'] as $bad)refuses(fn()=>portal_normalize_phone($bad));
refuses(fn()=>portal_phone_enroll($db,'unknown@example.com','3125550100','Verified by staff call',$approved,1000));
portal_phone_enroll($db,'one@example.com','3125550100','Verified by staff call',$approved,1000);$one=portal_phone_identity($db,'+13125550100');
portal_phone_enroll($db,'one@example.com','3125550100','Verified by staff call',$approved,1001);check(portal_phone_identity($db,'+13125550100')['revision']===$one['revision'],'Enrollment idempotent');
refuses(fn()=>portal_phone_enroll($db,'two@example.com','3125550100','Verified by staff call',$approved,1001));refuses(fn()=>portal_phone_enroll($db,'one@example.com','3125550101','Verified by staff call',$approved,1001));
portal_phone_enroll($db,'two@example.com','3125550101','Verified by staff call',$approved,1001);
$sent=[];$send=static function($p)use(&$sent){$sid='VE'.bin2hex(random_bytes(16));$sent[$sid]=$p;return $sid;};
$verify=static function($p,$sid,$code)use(&$sent){check(($sent[$sid]??'')===$p,'Exact provider binding');return $code==='123456';};
$unknown=portal_phone_request($db,'3125550199','ip1',$secret,$send,1100);check(count($sent)===0,'Unknown cannot send');check(portal_phone_consume($db,$unknown,'123456',$verify,1101)===false,'Unknown cannot login');
$id=portal_phone_request($db,'3125550100','ip1',$secret,$send,1100);check(count($sent)===1,'Approved binding sends');check($db->query('SELECT id FROM portal_phone_challenges')->fetchColumn()!==$id,'Opaque local challenge hashed');
portal_phone_request($db,'3125550100','ip2',$secret,$send,1110);check(count($sent)===1,'Cross-IP cooldown');
check(portal_phone_consume($db,$id,'000000',$verify,1120)===false,'Wrong code denied');$account=portal_phone_consume($db,$id,'123456',$verify,1121);check($account['id']===$one['account_id'],'Same owned account');
check(portal_phone_consume($db,$id,'123456',$verify,1122)===false,'Single use');
$_SESSION=[];check(!portal_phone_session_valid($db,$account),'Old email session refused');$_SESSION['portal_phone']=['phone'=>$account['login_phone'],'revision'=>$account['phone_revision']];check(portal_phone_session_valid($db,$account),'Bound session');
$id=portal_phone_request($db,'3125550100','ip1',$secret,$send,1200);for($i=0;$i<5;$i++)check(!portal_phone_consume($db,$id,'000000',$verify,1201+$i),'Wrong-code limit');check(!portal_phone_consume($db,$id,'123456',$verify,1207),'Attempts exhausted');
$id=portal_phone_request($db,'3125550100','ip1',$secret,$send,1300);check(!portal_phone_consume($db,$id,'123456',$verify,1900),'Expiry exact boundary');
$id=portal_phone_request($db,'3125550100','ip1',$secret,$send,1400);check(!portal_phone_consume($db,$id,'123456',static function(){throw new RuntimeException('lost response');},1401),'Provider uncertainty fails closed');check(!portal_phone_consume($db,$id,'123456',$verify,1402),'Uncertain challenge cannot replay');
$db->exec('DELETE FROM portal_phone_attempts');$id=portal_phone_request($db,'3125550100','ip1',$secret,$send,1500);
$db->exec("UPDATE portal_accounts SET disabled=1 WHERE email='one@example.com'");check(!portal_phone_consume($db,$id,'123456',$verify,1501),'Disabled after send');check(!portal_phone_session_valid($db,$account),'Disable expires phone session');
$db->exec("UPDATE portal_accounts SET disabled=0 WHERE email='one@example.com'");$db->exec("UPDATE portal_phone_identities SET revision='changed' WHERE phone='+13125550100'");check(!portal_phone_consume($db,$id,'123456',$verify,1502),'Binding change invalidates challenge');check(!portal_phone_session_valid($db,$account),'Binding change invalidates session');
$db->exec('DELETE FROM portal_phone_attempts');$old=portal_phone_request($db,'3125550101','ip2',$secret,$send,2000);$new=portal_phone_request($db,'3125550101','ip2',$secret,$send,2060);check(!portal_phone_consume($db,$old,'123456',$verify,2061),'Resend retires old challenge');check((bool)portal_phone_consume($db,$new,'123456',$verify,2061),'New challenge usable');
$before=count($sent);for($i=0;$i<20;$i++)portal_phone_request($db,'3125550101','ip'.($i+10),$secret,$send,3000+$i*901);check(count($sent)-$before===8,'Daily number limit survives changing IP');
check($db->query('SELECT COUNT(*) FROM portal_order_owners')->fetchColumn()===0,'Enrollment never attaches historical orders');
echo "portal-phone: PASS (normalization; staff binding; isolation; cooldown and daily limits; expiry; attempts; one use; provider uncertainty; disabled and changed identities; old-session rejection)\n";
