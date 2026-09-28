<?php
declare(strict_types=1);
require __DIR__ . '/../_private/server/portal-access.php';
function portal_test(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
portal_access_schema($db);
$secret = str_repeat('test-only-rate-secret-',2);
$mail = [];
$send = static function (string $email,string $token) use (&$mail): bool { $mail[$email][]=$token; return true; };
$approved = static fn(string $email): bool => in_array($email,['one@example.com','two@example.com'],true);
portal_test(portal_request_login($db,'unknown@example.com','192.0.2.1',$secret,$approved,$send,1000),'Generic request response');
portal_test($mail===[],'Unknown address must not receive an access link');
portal_request_login($db,' One@Example.com ','192.0.2.1',$secret,$approved,$send,1000);
$token=$mail['one@example.com'][0];
portal_test(strlen($token)===64,'Random token length');
portal_test($db->query('SELECT token_hash FROM portal_login_challenges')->fetchColumn()!==$token,'Raw token must not be stored');
portal_test(portal_consume_login($db,str_repeat('0',64),1001)===false,'Unknown token');
$one=portal_consume_login($db,$token,1001);
portal_test(is_array($one) && $one['email']==='one@example.com','Verified email creates account');
portal_test(portal_consume_login($db,$token,1002)===false,'Token must be single use');
portal_request_login($db,'two@example.com','192.0.2.2',$secret,$approved,$send,1000);
portal_test(portal_consume_login($db,$mail['two@example.com'][0],1900)===false,'Expiry boundary is denied');
portal_request_login($db,'two@example.com','192.0.2.2',$secret,$approved,$send,2000);
$two=portal_consume_login($db,$mail['two@example.com'][1],2001);
portal_test(is_array($two),'Second account');
$ref='AABBCCDDEE';
$verify = static fn(string $reference,string $proof): array|false => $proof==='existing-valid-token'
    ? ['reference'=>$reference,'email'=>'one@example.com'] : false;
portal_test(!portal_owns_order($db,$one['id'],$ref),'Email alone must never link historical order');
portal_test(!portal_claim_existing_order($db,$one['id'],$ref,'wrong-token',$verify),'Invalid ownership proof');
portal_test(!portal_claim_existing_order($db,$two['id'],$ref,'existing-valid-token',$verify),'Token alone cannot override email mismatch');
portal_test(portal_claim_existing_order($db,$one['id'],$ref,'existing-valid-token',$verify),'Two matching proofs claim order');
portal_test(portal_owns_order($db,$one['id'],$ref),'Owner access');
portal_test(!portal_owns_order($db,$two['id'],$ref),'Cross-account read is denied');
$wrongReference = static fn(): array => ['reference'=>'FFFFFFFFFF','email'=>'one@example.com'];
portal_test(!portal_claim_existing_order($db,$one['id'],'1122334455','existing-valid-token',$wrongReference),'Verifier must bind exact reference');
$fakeSecondEmail = static fn(string $reference): array => ['reference'=>$reference,'email'=>'two@example.com'];
portal_test(!portal_claim_existing_order($db,$two['id'],$ref,'token',$fakeSecondEmail),'Existing owner cannot be replaced');
// Returning verified customers do not need another pricing-access request.
portal_request_login($db,'one@example.com','192.0.2.3',$secret,static fn()=>false,$send,3000);
portal_test(is_array(portal_consume_login($db,$mail['one@example.com'][1],3001)),'Retained approved access');
for($i=0;$i<8;$i++) portal_request_login($db,'one@example.com','192.0.2.3',$secret,$approved,$send,3000);
portal_test(count($mail['one@example.com'])===6,'Maximum five login emails per address in a 15-minute window');
$db->prepare('UPDATE portal_accounts SET disabled=1 WHERE id=?')->execute([$one['id']]);
portal_test(!portal_owns_order($db,$one['id'],$ref),'Disabled account loses order access');
portal_test(portal_consume_login($db,end($mail['one@example.com']),3001)===false,'Disabled account cannot consume outstanding link');
// Delivery failure invalidates a generated challenge, without disclosing eligibility.
$before=(int)$db->query('SELECT COUNT(*) FROM portal_login_challenges')->fetchColumn();
portal_request_login($db,'new@example.com','192.0.2.4',$secret,static fn()=>true,static fn()=>false,4000);
portal_test((int)$db->query('SELECT COUNT(*) FROM portal_login_challenges')->fetchColumn()===$before,'Undelivered login token revoked');
portal_test(!portal_owns_order($db,"' OR 1=1 --",$ref),'SQL injection denied');
echo "portal-access: PASS (one-use login, expiry, throttling, disabled accounts, ownership isolation)\n";
