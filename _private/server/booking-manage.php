<?php
declare(strict_types=1);
require_once __DIR__.'/booking-lifecycle-ui.php';
header('Cache-Control: no-store, private, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');
$nonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
function manage_page(string $body,int $status=200): never{
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SiteSee | Manage Appointment</title><style>body{margin:0;background:#01111e;color:#162c3b;font:16px/1.6 Inter,Arial,sans-serif}main{max-width:760px;margin:4vw auto;padding:30px;background:white;border-top:6px solid #ffc107}h1,h2{font-family:Poppins,Arial,sans-serif}label{display:block;margin:14px 0}button{background:#ffc107;color:#01111e;border:0;padding:12px 18px;font-weight:bold;cursor:pointer}input,select{font:inherit;padding:8px;max-width:100%;box-sizing:border-box}form{padding:14px 0;border-top:1px solid #ddd}a{color:#07517d}.note{background:#fff7db;padding:14px}.error{color:#9a1825}details{margin:26px 0}summary{cursor:pointer;font-weight:bold}</style></head><body><main><p>SiteSee · Show More. Decide Faster.</p><h1>Your Appointment</h1>'.$body.'</main></body></html>';exit;
}
if(!booking_test_enabled()||!booking_lifecycle_enabled()||!str_starts_with(SITESEE_REAL_ESTATE_SITE_URL,'https://'))manage_page('<p>Appointment management is unavailable. Please contact SiteSee.</p>',503);
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){header('Allow: GET, POST');manage_page('<p>Use your private appointment link.</p>',405);}
session_name('sitesee_appointment_management');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
$db=booking_db();booking_communication_schema($db);$error='';$windows=[];
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='POST'){
    if((int)($_SERVER['CONTENT_LENGTH']??0)>4096)manage_page('<p>Request is too large.</p>',413);
    if(!real_estate_same_origin()||!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))manage_page('<p>Reload your private appointment link.</p>',403);
    $action=(string)($_POST['action']??'');
    $ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));
    $db->prepare('DELETE FROM booking_management_attempts WHERE at<?')->execute([time()-900]);
    $q=$db->prepare('SELECT count(*) FROM booking_management_attempts WHERE ip_hash=? AND at>?');$q->execute([$ip,time()-60]);
    if((int)$q->fetchColumn()>=20){header('Retry-After: 60');manage_page('<p>Please wait one minute and try again.</p>',429);}
    $db->prepare('INSERT INTO booking_management_attempts(ip_hash,at) VALUES(?,?)')->execute([$ip,time()]);
    if($action==='open'){
        $parts=explode('.',(string)($_POST['credential']??''));
        if(count($parts)!==2||!booking_management_auth($db,$parts[0],$parts[1]))manage_page('<p>This private link is invalid or expired. Ask SiteSee for a replacement.</p>',403);
        booking_lifecycle_row($db,$parts[0]);session_regenerate_id(true);
        $_SESSION['access']=['reference'=>$parts[0],'hash'=>hash('sha256',$parts[1]),'until'=>time()+1800];
        $_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: manage-appointment.php',true,303);exit;
    }
}
$access=$_SESSION['access']??[];$reference=(string)($access['reference']??'');$s=booking_lifecycle_state($db,$reference);
$authorized=($access['until']??0)>=time()&&(int)$s['token_expires']>=time()&&is_string($s['token_hash'])&&is_string($access['hash']??null)&&hash_equals($s['token_hash'],$access['hash']);
if(!$authorized){
    if($method==='POST')manage_page('<p>Your session expired. Reopen your private appointment link.</p>',403);
    $e='booking_workflow_escape';
    manage_page('<p>Open the private management link provided by SiteSee. Links expire after seven days.</p><form method="post" id="access"><input type="hidden" name="csrf" value="'.$e($_SESSION['csrf']).'"><input type="hidden" name="action" value="open"><label>Private link code <input id="credential" name="credential" autocomplete="off" required></label><button>Open My Appointment</button></form><script nonce="'.$e($nonce).'">const code=location.hash.slice(1);history.replaceState(null,"",location.pathname);if(/^[A-F0-9]{10,32}\.[a-f0-9]{64}$/.test(code)){document.getElementById("credential").value=code;document.getElementById("access").requestSubmit();}</script><noscript><p>Paste the portion after # from your private link into the code field.</p></noscript>');
}
if($method==='POST'){
    if((string)($_POST['reference']??'')!==$reference)manage_page('<p>Appointment identity differs. Reopen your link.</p>',403);
    try{
        if($action==='windows'){
            $windows=booking_lifecycle_windows($db,$reference,(string)($_POST['date']??''));
            if(!$windows)$error='No fitting windows were found in the next 14 days. Try another starting date or contact SiteSee.';
        }elseif(in_array($action,['cancel','reschedule'],true)){
            if(($_POST['agreed']??'')!=='yes')throw new InvalidArgumentException('Confirm your appointment choice first.');
            if($action==='reschedule'){
                if(!function_exists('booking_change_request_create'))throw new RuntimeException('Appointment update in progress.');
                booking_change_request_create($db,$reference,(string)($_POST['fingerprint']??''),(string)($_POST['date']??''),(string)($_POST['time']??''));
            }else booking_lifecycle_change($db,$reference,$action,(string)($_POST['fingerprint']??''),'customer');
            $notice=$action==='cancel'?'Your appointment is cancelled.':'Your requested window is saved for SiteSee manager approval. Your confirmed appointment remains unchanged.';
            if($action==='cancel'){
                try{booking_lifecycle_notice($db,$reference,true);$notice.=' Your change notice is being verified.';}catch(Throwable){$notice.=' Staff must finish the change notice; your appointment result is saved.';}
            }
            if($action==='cancel')booking_finance_process($db,$reference);
            $_SESSION['notice']=$notice;header('Location: manage-appointment.php',true,303);exit;
        }else throw new InvalidArgumentException('Unknown action.');
    }catch(Throwable $e){$error=$e instanceof InvalidArgumentException||$e instanceof BookingCalendarUnavailable?$e->getMessage():'We could not verify the change. Please contact SiteSee before trying again.';}
}
// A second private link replaces the current appointment session, even in an already signed-in tab.
$exchange='<form id="new-access" method="post" hidden><input type="hidden" name="csrf" value="'.booking_workflow_escape($_SESSION['csrf']).'"><input type="hidden" name="action" value="open"><input id="new-credential" name="credential"></form><script nonce="'.booking_workflow_escape($nonce).'">const next=location.hash.slice(1);history.replaceState(null,"",location.pathname);if(/^[A-F0-9]{10,32}\\.[a-f0-9]{64}$/.test(next)){document.getElementById("new-credential").value=next;document.getElementById("new-access").requestSubmit();}</script>';
$row=booking_lifecycle_row($db,$reference);$notice=(string)($_SESSION['notice']??'');unset($_SESSION['notice']);
manage_page($exchange.'<p class="note">TEST appointment — no live payments.</p>'.($notice?'<p role="status">'.booking_workflow_escape($notice).'</p>':'').($error?'<p role="alert" class="error">'.booking_workflow_escape($error).'</p>':'').booking_lifecycle_html($db,$row,$_SESSION['csrf'],false,$windows));
