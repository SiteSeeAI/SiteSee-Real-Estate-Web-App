<?php
declare(strict_types=1);
/** Explicitly configured provider only. No email fallback and no hard-coded credentials. */
function portal_sms_config(?string $path=null): array
{
    $path??=dirname(__DIR__).'/portal-sms.json';
    clearstatcache(true,$path);
    if(!is_file($path)||is_link($path)||(fileperms($path)&0077)!==0||filesize($path)>4096)throw new RuntimeException('SMS configuration unavailable.');
    $c=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($c)||($c['provider']??'')!=='twilio-verify'||($c['enabled']??false)!==true||($c['stage']??'')!=='TEST'
        ||!preg_match('/^AC[0-9a-fA-F]{32}$/D',(string)($c['account_sid']??''))||!preg_match('/^VA[0-9a-fA-F]{32}$/D',(string)($c['service_sid']??''))
        ||!preg_match('/^[0-9a-fA-F]{32}$/D',(string)($c['auth_token']??''))||!is_array($c['allowed_numbers']??null)||count($c['allowed_numbers'])<1||count($c['allowed_numbers'])>10)throw new RuntimeException('SMS configuration unavailable.');
    foreach($c['allowed_numbers'] as $phone)if(!is_string($phone)||!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone))throw new RuntimeException('SMS test recipients unavailable.');
    return $c;
}
function portal_sms_request(array $config,string $operation,array $fields): array
{
    if(!in_array($operation,['Verifications','VerificationCheck'],true))throw new RuntimeException('Unsupported verification operation.');
    $ch=curl_init('https://verify.twilio.com/v2/Services/'.$config['service_sid'].'/'.$operation);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERPWD=>$config['account_sid'].':'.$config['auth_token'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    try{$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);}finally{curl_close($ch);}
    if(!is_string($raw)||strlen($raw)>65536||$status<200||$status>=300)throw new RuntimeException('Text verification could not finish.');
    $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Verification response unavailable.');return $data;
}
function portal_sms_send(string $phone): string
{
    $c=portal_sms_config();if(!in_array($phone,$c['allowed_numbers'],true))throw new RuntimeException('Test recipient not enabled.');
    $r=portal_sms_request($c,'Verifications',['To'=>$phone,'Channel'=>'sms']);
    if(($r['status']??'')!=='pending'||($r['to']??'')!==$phone||($r['service_sid']??'')!==$c['service_sid']||($r['account_sid']??'')!==$c['account_sid']||($r['channel']??'')!=='sms'||!preg_match('/^VE[0-9a-fA-F]{32}$/D',(string)($r['sid']??'')))throw new RuntimeException('Verification response mismatch.');return $r['sid'];
}
function portal_sms_check(string $phone,string $sid,string $code): bool
{
    $c=portal_sms_config();if(!in_array($phone,$c['allowed_numbers'],true)||!preg_match('/^VE[0-9a-fA-F]{32}$/D',$sid))throw new RuntimeException('Verification unavailable.');
    $r=portal_sms_request($c,'VerificationCheck',['VerificationSid'=>$sid,'Code'=>$code]);
    if(($r['to']??'')!==$phone||($r['sid']??'')!==$sid||($r['service_sid']??'')!==$c['service_sid']||($r['account_sid']??'')!==$c['account_sid']||($r['channel']??'')!=='sms'||!in_array($r['status']??'',['pending','approved'],true))throw new RuntimeException('Verification response mismatch.');
    return $r['status']==='approved';
}
