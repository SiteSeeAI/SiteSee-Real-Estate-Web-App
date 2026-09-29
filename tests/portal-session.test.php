<?php
declare(strict_types=1);
require __DIR__.'/../_private/server/portal-access.php';
require __DIR__.'/../_private/server/portal-session.php';
function check(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
define('SITESEE_REAL_ESTATE_SITE_URL','https://re.sitesee.ai');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);portal_access_schema($db);
$id=str_repeat('a',32);$db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,'one@example.com',1000]);
$_SERVER['DOCUMENT_ROOT']=__DIR__.'/../public';portal_session_start();$old=session_id();$csrf=$_SESSION['csrf'];
portal_session_login(['id'=>$id],1000);check(session_id()!==$old,'Login regenerates the session ID');check($_SESSION['csrf']!==$csrf,'Login rotates CSRF');
check((bool)portal_session_account($db,1001),'Active account');
check(portal_session_account($db,2801)===false,'Idle boundary expires and clears auth');check(!isset($_SESSION['portal']),'Expired auth removed');
portal_session_login(['id'=>$id],1000);$_SESSION['portal']['seen']=44199;
check(portal_session_account($db,44200)===false,'Absolute boundary cannot be extended by activity');
portal_session_login(['id'=>$id],1000);$db->exec('UPDATE portal_accounts SET disabled=1');check(portal_session_account($db,1001)===false,'Disabled account invalidates existing sessions');
$_SERVER['HTTP_ORIGIN']='https://re.sitesee.ai';$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
check(portal_csrf_valid(['csrf'=>$_SESSION['csrf']]),'Same origin CSRF');
check(!portal_csrf_valid(['csrf'=>[]]),'Array injection rejected');
$_SERVER['HTTP_ORIGIN']='https://evil.example';check(!portal_csrf_valid(['csrf'=>$_SESSION['csrf']]),'Foreign origin denied');
$_SERVER['HTTP_ORIGIN']='';$_SERVER['HTTP_SEC_FETCH_SITE']='same-site';check(!portal_csrf_valid(['csrf'=>$_SESSION['csrf']]),'Sibling site POST denied');
$p=session_get_cookie_params();check($p['secure'] && $p['httponly'] && $p['samesite']==='Lax' && $p['domain']==='' && $p['path']==='/','Secure host-only cookie');
$_SESSION=[];session_destroy();
echo "portal-session: PASS (strict cookies, ID and CSRF rotation, idle/absolute expiry, disable, cross-origin rejection)\n";
