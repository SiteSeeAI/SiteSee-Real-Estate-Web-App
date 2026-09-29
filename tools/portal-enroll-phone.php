<?php
declare(strict_types=1);
// Staff use only, as the application owner. JSON on stdin avoids phone details in process arguments.
if(PHP_SAPI!=='cli')exit(1);
$root='/home/sitesee/.sitesee-real-estate';$path=$root.'/data/bookings.sqlite';
try{
    if(!function_exists('posix_geteuid')||!is_file($path)||is_link($path)||fileowner($path)!==posix_geteuid()||posix_geteuid()===0)throw new RuntimeException('Run as sitesee against the existing private booking ledger.');
    $input=json_decode((string)stream_get_contents(STDIN,4097),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($input)||($input['ownership_verified']??null)!==true)throw new RuntimeException('Staff ownership verification is required.');
    require $root.'/server/portal-phone.php';
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);portal_access_schema($db);portal_phone_schema($db);
    $approved=static function(string $email)use($root):bool{
        foreach(glob($root.'/real-estate-pricing-pending/*.json')?:[] as $p){
            if(is_link($p)||filesize($p)>65536)continue;
            $lead=json_decode((string)file_get_contents($p),true);
            if(is_array($lead)&&($lead['status']??'')==='approved'&&!empty($lead['approved_at'])&&strtolower(trim((string)($lead['email']??'')))===$email)return true;
        }
        return false;
    };
    portal_phone_enroll($db,(string)($input['email']??''),(string)($input['phone']??''),(string)($input['evidence']??''),$approved);
    echo "PASS: Staff-verified phone identity enrolled. No SMS sent; no order, payment, calendar or CRM record changed.\n";
}catch(Throwable $e){fwrite(STDERR,"STOP: Phone enrollment requires review (".get_class($e)."). No SMS sent.\n");exit(1);}
