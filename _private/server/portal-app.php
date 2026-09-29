<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
// Preserve same-origin form Origin headers while suppressing cross-origin referrers.
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
// Independent, default-off release gate. Never enables another integration or accepts live keys.
if (getenv('SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED') !== '1' || getenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED') !== '1') {
    http_response_code(503);exit('Account access is not available yet.');
}
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(400);exit('Use the secure SiteSee account address.');
}
require_once __DIR__ . '/portal-orders.php';
require_once __DIR__ . '/portal-session.php';
require_once __DIR__ . '/portal-mail.php';
require_once __DIR__.'/portal-purchase.php';
require_once dirname(__DIR__).'/views/portal-purchase.php';
require_once dirname(__DIR__) . '/views/portal.php';
function portal_json(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function portal_redirect(string $query = ''): never { header('Location: /account.php'.$query, true,303);exit; }
function portal_input(array $source, string $key, int $max = 2048): string
{
    $v=$source[$key]??'';
    if(!is_string($v)||strlen($v)>$max)throw new InvalidArgumentException('Invalid request.');
    return $v;
}
try {
    $db=booking_db();portal_access_schema($db);portal_profile_schema($db);portal_purchase_schema($db);portal_session_start();
    $account=portal_session_account($db);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');portal_page('Request Not Available','<p>Use the account links to continue.</p>',$account,405);}
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)portal_page('Request Too Large','<p>Please shorten your entry.</p>',$account,413);
        if(!portal_csrf_valid($_POST))portal_page('Please Refresh','<p>Refresh the page and try again.</p>',$account,403);
        $action=portal_input($_POST,'action',32);
        if($action==='logout'){
            $_SESSION=[];session_destroy();
            setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
            portal_redirect('?signed_out=1');
        }
        if($action==='request_login'){
            portal_request_login($db,portal_input($_POST,'email',180),(string)($_SERVER['REMOTE_ADDR']??''),
                SITESEE_REAL_ESTATE_PRICING_GATE_SECRET,'portal_pricing_approved','portal_send_login');
            portal_redirect('?sent=1');
        }
        if($action==='consume_login'){
            $verified=portal_consume_login($db,portal_input($_POST,'token',64));
            if(!$verified)portal_verify_page('This sign-in link is invalid or expired. Request a new link to continue.');
            portal_session_login($verified);portal_redirect();
        }
        if(!$account)portal_sign_in('Please sign in to continue.');
        if(in_array($action,['review_order','submit_order','checkout'],true)){
            try {
                if($action==='review_order'){
                    $payload=json_decode(portal_input($_POST,'payload',50000),true,24);
                    if(!is_array($payload))throw new InvalidArgumentException('Complete your order details.');
                    $result=portal_purchase_review($db,$account['id'],$payload);
                    $result['availability']=portal_purchase_availability($db,portal_purchase_input($payload,$account));
                    portal_json($result);
                }
                if($action==='submit_order'){
                    $reference=portal_purchase_submit($db,$account['id'],portal_input($_POST,'review',64));
                    portal_purchase_notify($db,$account['id'],$reference);
                    portal_json(['reference'=>$reference]);
                }
                $reference=portal_input($_POST,'reference',32);
                if(!portal_purchase_intent_for_order($db,$account['id'],$reference))portal_json(['error'=>'This order is unavailable.'],404);
                portal_json(portal_purchase_checkout($db,$account['id'],$reference,portal_input($_POST,'card_consent',8)==='yes',(string)($_SERVER['REMOTE_ADDR']??'')));
            }catch(InvalidArgumentException $error){portal_json(['error'=>$error->getMessage()],422);}
            catch(Throwable $error){error_log('SiteSee portal purchase failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine());portal_json(['error'=>'Your request could not finish. Retry to recover the same order or payment.'],503);}
        }
        if($action==='claim_order'){
            $code=portal_claim_code(portal_input($_POST,'credential'));
            $ok=$code && portal_claim_existing_order($db,$account['id'],$code[0],$code[1],
                static fn($ref,$token)=>portal_existing_order_proof($db,$ref,$token));
            portal_orders_page($db,$account,1,$ok?'Your order is now available.':'This order could not be added. Check the private link or contact SiteSee.');
        }
        if($action==='save_profile'){
            portal_save_profile($db,$account['id'],$_POST);portal_redirect('?view=profile&saved=1');
        }
        portal_page('Request Not Available','<p>Use the account links to continue.</p>',$account,400);
    }
    $view=portal_input($_GET,'view',20);
    if($view==='verify')portal_verify_page();
    if(!$account)portal_sign_in(isset($_GET['sent'])?'If this email has account access, a sign-in link is on its way. Check your inbox.':(isset($_GET['signed_out'])?'You are signed out.':''));
    if($view==='engine'){
        $market=portal_input($_GET,'market',16);
        if(!in_array($market,['residential','commercial'],true))portal_json(['error'=>'Unavailable'],404);
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(dirname(__DIR__).'/pricing-assets/'.($market==='residential'?'quote-engine.js':'commercial-quote-engine.js'));exit;
    }
    if($view==='payment')portal_payment_page($db,$account,portal_input($_GET,'reference',32),isset($_GET['result']));
    if($view==='order'){
        $order=portal_owned_order($db,$account['id'],portal_input($_GET,'reference',32));
        if(!$order)portal_page('Order Unavailable','<p>This order is not available in your account.</p><p><a href="/account.php">Return To My Orders</a></p>',$account,404);
        $order['portal_payment']=portal_purchase_intent_for_order($db,$account['id'],$order['reference'])!==false;
        portal_order_page($order,$account);
    }
    if($view==='profile')portal_profile_page($db,$account,isset($_GET['saved'])?'Your profile is saved.':'');
    if($view==='new')portal_new_order_page($db,$account,portal_input($_GET,'again',32));
    if($view!=='')portal_page('Page Unavailable','<p><a href="/account.php">Return To My Orders</a></p>',$account,404);
    $page=portal_input($_GET,'page',5);portal_orders_page($db,$account,ctype_digit($page)?max(1,min(10000,(int)$page)):1);
} catch(InvalidArgumentException){
    portal_page('Review Your Entry','<p>Please check your entry and try again.</p><p><a href="/account.php">Return To My Orders</a></p>',$account??false,400);
} catch(Throwable $error){
    // Do not log tokens, submitted forms, SQL, provider credentials or customer payloads.
    error_log('SiteSee portal request failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine());
    portal_page('Please Try Again','<p>Account access is temporarily unavailable. Please try again shortly.</p>',false,503);
}
