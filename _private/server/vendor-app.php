<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');
header('Referrer-Policy: same-origin');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if(getenv('SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED')!=='1'||getenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED')!=='1'){http_response_code(503);exit('Vendor access is not available yet.');}
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){http_response_code(400);exit('Use the secure SiteSee vendor address.');}
require_once __DIR__.'/booking-job-ui.php';
require_once __DIR__.'/vendor-access.php';
require_once __DIR__.'/portal-sms.php';
require_once dirname(__DIR__).'/views/application-shell.php';

// The shared onsite view needs formatting and CSRF helpers only, never staff login.
function staff_escape(string $s): string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function staff_money(int $cents): string{return '$'.number_format($cents/100,2);}
function staff_csrf(): string{return $_SESSION['csrf'];}
function vendor_page(string $title,string $html,?array $account=null,int $status=200): never
{
    http_response_code($status);$e='staff_escape';
    $nav=$account?'<div class="topline"><div><p class="help">'.$e($account['name']).'</p></div><form method="post"><input type="hidden" name="csrf" value="'.$e(staff_csrf()).'"><button name="action" value="logout">Sign Out</button></form></div>':'';
    echo site_application_shell('vendor', $title, $nav.$html, (bool)$account);exit;
}
function vendor_redirect(string $query=''): never{header('Location: /vendor.php'.$query,true,303);exit;}
function vendor_json(array $data,int $status=200): never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function vendor_sign_in(string $error='',bool $verify=false,int $status=200): never
{
    $e='staff_escape';$html=$error?'<p class="error" role="alert">'.$e($error).'</p>':'';
    $html.='<form method="post"><input type="hidden" name="csrf" value="'.$e(staff_csrf()).'">';
    if($verify)$html.='<p>If this number has an active vendor account and is approved for TEST texts, a sign-in code will arrive shortly.</p><label>Sign In Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,10}" maxlength="10" required></label><button name="action" value="verify_sms">Sign In</button></form><p><a href="/vendor.php">Use another number or request a new code</a></p>';
    else $html.='<p>Sign in to view your assigned jobs and complete onsite work.</p><label>Cell Phone Number<input name="phone" type="tel" autocomplete="tel" maxlength="40" required></label><label><input name="sms_consent" type="checkbox" value="yes" required>Text me a one-time sign-in code.</label><button name="action" value="request_sms">Text My Sign In Code</button></form><p class="help">Your manager creates your vendor account and assigns your jobs.</p>';
    vendor_page($verify?'Enter Your Text Code':'Vendor Sign In',$html,null,$status);
}

try{
    $db=booking_db();booking_communication_schema($db);portal_billing_schema($db);booking_job_schema($db);vendor_schema($db);vendor_session_start();
    $account=vendor_session_account($db);$method=$_SERVER['REQUEST_METHOD']??'GET';$notice='';$error='';
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');vendor_page('Request Not Available','<p>Use the vendor account links.</p>',$account?:null,405);}
    $reference=staff_job_input('reference',32);
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)vendor_page('Request Too Large','<p>Please shorten your entry.</p>',$account?:null,413);
        $action=staff_job_input('action',32);
        if(!portal_csrf_valid($_POST)){
            if($action==='job_preview')vendor_json(['error'=>'Refresh your vendor session and try again.'],403);
            vendor_page('Please Refresh','<p>Refresh the page and try again.</p>',$account?:null,403);
        }
        if($action==='logout'){vendor_session_clear();vendor_redirect();}
        if($action==='request_sms'){
            if(staff_job_input('sms_consent',8)!=='yes')vendor_sign_in('Confirm that you want a sign-in code sent by text.');
            vendor_session_clear();
            $_SESSION['vendor_challenge']=vendor_phone_request($db,staff_job_input('phone',40),(string)($_SERVER['REMOTE_ADDR']??''),SITESEE_REAL_ESTATE_PRICING_GATE_SECRET,'portal_sms_send');
            vendor_redirect('?view=verify');
        }
        if($action==='verify_sms'){
            $verified=vendor_phone_consume($db,(string)($_SESSION['vendor_challenge']??''),staff_job_input('code',10),'portal_sms_check');
            if(!$verified)vendor_sign_in('This code could not be verified. Check it or request a new text after one minute.',true);
            vendor_session_login($verified);vendor_redirect();
        }
        if(!$account){if($action==='job_preview')vendor_json(['error'=>'Please sign in to your vendor account again.'],403);vendor_sign_in('Please sign in to continue.',false,403);}
        // A role allowlist, not hidden manager buttons, defines the vendor's authority.
        if(!in_array($action,['job_preview','job_save','job_complete'],true))vendor_page('Action Not Available','<p>This action is not available in your vendor account.</p>',$account,403);
        $assignmentRevision=staff_job_input('assignment_revision',32);
        $authorize=static function(string $ref)use($db,$account,$assignmentRevision):array{
            $current=vendor_session_account($db);
            if(!$current||$current['id']!==$account['id']||$current['revision']!==$account['revision'])throw new InvalidArgumentException('Please sign in to your vendor account again.');
            $grant=vendor_require_job($db,$account,$ref,$assignmentRevision);
            return ['id'=>$account['id'],'name'=>$grant['account']['name'],'account_revision'=>$account['revision'],'assignment_revision'=>$grant['assignment']['revision']];
        };
        try{$authorize($reference);}catch(InvalidArgumentException){
            if($action==='job_preview')vendor_json(['error'=>'This job is not available in your vendor account.'],404);
            vendor_page('Job Not Available','<p>This job is not available in your vendor account.</p>',$account,404);
        }
        if($action==='job_preview')staff_job_preview_response($db);
        try{$notice=staff_job_action($db,$action,$authorize);}
        catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'The job action needs review. Check the saved status below and contact your manager before retrying.';}
    }else{
        $reference=$_GET['reference']??'';
        if(!is_string($reference)||strlen($reference)>32)throw new InvalidArgumentException('Invalid job reference.');
    }
    if(!$account)vendor_sign_in('',($_GET['view']??'')==='verify'&&isset($_SESSION['vendor_challenge']));
    $e='staff_escape';$html=($notice?'<p class="note" role="status">'.$e($notice).'</p>':'').($error?'<p class="error" role="alert">'.$e($error).'</p>':'');
    if($reference!==''){
        try{$grant=vendor_require_job($db,$account,$reference);}catch(InvalidArgumentException){vendor_page('Job Not Available','<p>This job is not available in your vendor account.</p>',$account,404);}
        $row=$grant['row'];$request=booking_request($row);$d=$request['details'];$a=$request['appointment'];$job=booking_job_get($db,$reference);
        $ready=true;try{booking_job_guard($db,$reference);}catch(InvalidArgumentException){$ready=false;}
        $html.='<section class="card"><p class="eyebrow">'.$e($reference).'</p><h2>'.$e(portal_property($d)).'</h2><dl class="facts"><div><dt>Arrival Window · Central Time</dt><dd>'.$e(($a['date']??'').' '.($a['time']??'').(isset($a['windowEnd'])?'–'.$a['windowEnd']:'')).'</dd></div><div><dt>Agent</dt><dd>'.$e(trim(($d['first']??'').' '.($d['last']??''))).'<br>'.$e($d['phone']??'').'</dd></div></dl></section>';
        if($ready&&!$job){
            $html.='<section class="card"><h2>Services &amp; Property Access</h2><ul>';
            foreach($request['quote']['lines']??[] as $line){$label=(string)$line['label'];if(($line['key']??'')==='video'){$parts=explode(' · ',$label,2);$label='Property Video'.(isset($parts[1])?' · '.$parts[1]:'');}$html.='<li>'.$e($label).'</li>';}
            $html.='</ul><dl class="facts">';
            foreach(['meetPhotographer'=>'Agent Meets Photographer','accessType'=>'Access Type','lockboxCode'=>'Lockbox Code','keyLocation'=>'Key Location','specialRequests'=>'Special Requests','mustHaveShots'=>'Must-Have Shots','onsiteName'=>'Onsite Contact','onsitePhone'=>'Onsite Phone','onsiteEmail'=>'Onsite Email','additionalName'=>'Additional Contact','additionalPhone'=>'Additional Contact Phone','additionalEmail'=>'Additional Contact Email'] as $key=>$label)if(isset($a[$key])&&$a[$key]!=='')$html.='<div><dt>'.$label.'</dt><dd>'.$e((string)$a[$key]).'</dd></div>';
            $html.='</dl></section>';
        }
        $html.=($ready||$job)?staff_job_panel($db,$reference,$grant['assignment']):'<p class="note">This job needs manager review before onsite closeout. Contact your manager for its current appointment status.</p>';
        vendor_page('Job Details',$html,$account);
    }
    $q=$db->prepare('SELECT b.reference,b.request_json,b.requested_utc,s.appointment_json AS schedule_appointment_json,j.completed_at,j.production_complete_at,j.payment_state FROM vendor_assignments v JOIN vendor_accounts a ON a.id=v.vendor_id AND a.enabled=1 JOIN bookings b ON b.reference=v.reference AND b.photographer=v.photographer LEFT JOIN booking_scheduling s ON s.reference=b.reference LEFT JOIN booking_jobs j ON j.reference=b.reference WHERE v.vendor_id=? ORDER BY (j.completed_at IS NOT NULL),b.requested_utc,b.reference LIMIT 200');$q->execute([$account['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $html.='<p>Your assigned jobs appear here. Open a job for property access, additional services and onsite closeout.</p><div class="request-list">';
    foreach($rows as $row){
        $request=booking_request($row);$a=$request['appointment'];$state=$row['completed_at']?($row['production_complete_at']?'Production Complete':'Production'):'Assigned';
        if(!$row['completed_at'])try{booking_job_guard($db,$row['reference']);}catch(InvalidArgumentException){$state='Manager Review Needed';}
        $html.='<article class="request-item"><div><p class="eyebrow">'.$e($row['reference']).' · '.$state.'</p><h2>'.$e(portal_property($request['details'])).'</h2><p>'.$e(($a['date']??'').' '.($a['time']??'')).' Central Time</p></div><a class="request-link" href="/vendor.php?reference='.$e($row['reference']).'">Open Job →</a></article>';
    }
    vendor_page('My Jobs',$html.($rows?'':'<p>No jobs are assigned to your account yet.</p>').'</div>',$account);
}catch(Throwable $e){
    vendor_page('Vendor Account Unavailable','<p>'.staff_escape($e instanceof InvalidArgumentException?$e->getMessage():'Please try again later or contact your manager.').'</p>',null,$e instanceof InvalidArgumentException?400:503);
}
