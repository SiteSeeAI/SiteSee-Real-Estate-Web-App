<?php
declare(strict_types=1);
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function refuse(callable $fn):void{try{$fn();}catch(RuntimeException){return;}throw new LogicException('Expected refusal.');}
$root=sys_get_temp_dir().'/sitesee-sms-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/server',0700);
$source=file_get_contents(__DIR__.'/../_private/server/portal-sms.php');file_put_contents($root.'/server/sms.php',str_replace('function portal_sms_request(', 'function portal_sms_request_unused_fixture(', $source));
function portal_sms_request(array $c,string $operation,array $fields):array{$GLOBALS['request']=[$operation,$fields];return $GLOBALS['response'];}
try{
    require $root.'/server/sms.php';$path=$root.'/portal-sms.json';
    $c=['provider'=>'twilio-verify','enabled'=>true,'stage'=>'TEST','account_sid'=>'AC'.str_repeat('a',32),'auth_token'=>str_repeat('b',32),'service_sid'=>'VA'.str_repeat('c',32),'allowed_numbers'=>['+13125550100']];
    file_put_contents($path,json_encode($c));chmod($path,0600);check(portal_sms_config()===$c,'Private config accepted');
    chmod($path,0644);refuse(fn()=>portal_sms_config());chmod($path,0600);
    $GLOBALS['response']=['sid'=>'VE'.str_repeat('d',32),'service_sid'=>$c['service_sid'],'account_sid'=>$c['account_sid'],'channel'=>'sms','status'=>'pending','to'=>'+13125550100'];
    check(portal_sms_send('+13125550100')===$GLOBALS['response']['sid'],'Send verified response');check($GLOBALS['request']===['Verifications',['To'=>'+13125550100','Channel'=>'sms']],'SMS-only destination');
    refuse(fn()=>portal_sms_send('+13125550101'));
    check(!portal_sms_check('+13125550100',$GLOBALS['response']['sid'],'123456'),'Pending is not authenticated');$GLOBALS['response']['status']='approved';
    check(portal_sms_check('+13125550100',$GLOBALS['response']['sid'],'123456'),'Approved bound response');check($GLOBALS['request'][1]===['VerificationSid'=>$GLOBALS['response']['sid'],'Code'=>'123456'],'Stored SID used');
    foreach(['sid'=>'VE'.str_repeat('e',32),'service_sid'=>'VA'.str_repeat('e',32),'account_sid'=>'AC'.str_repeat('e',32),'channel'=>'email','to'=>'+13125550101','status'=>'expired'] as $key=>$bad){$before=$GLOBALS['response'][$key];$GLOBALS['response'][$key]=$bad;refuse(fn()=>portal_sms_check('+13125550100','VE'.str_repeat('d',32),'123456'));$GLOBALS['response'][$key]=$before;}
    $c['stage']='LIVE';file_put_contents($path,json_encode($c));refuse(fn()=>portal_sms_config());
    echo "portal-sms: PASS (private configuration; allowed recipients; SMS-only requests; exact account/service/number/SID binding; approved-only authentication)\n";
}finally{@unlink($path);@unlink($root.'/server/sms.php');@rmdir($root.'/server');@rmdir($root);}
