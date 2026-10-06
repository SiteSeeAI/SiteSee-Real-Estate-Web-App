<?php
declare(strict_types=1);
// Isolated CLI fixture only. No fixture routes or provider replacements are installed.
$root=(string)getenv('PORTAL_TEST_PRIVATE');
if(PHP_SAPI!=='cli-server'||!str_starts_with($root,sys_get_temp_dir().'/sitesee-portal-http-'))exit;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($path,['/account.php','/staff-bookings.php','/staff-production.php','/staff-vendors.php','/vendor.php'],true)){
    require $root.'/fixture-providers.php';require __DIR__.'/stripe.php';
    $_SERVER['HTTPS']='on';
    if($path==='/account.php')require getenv('PORTAL_TEST_ACCOUNT');
    elseif($path==='/vendor.php')require getenv('VENDOR_TEST_ENTRY');
    else{if($path==='/staff-vendors.php')define('SITESEE_VENDOR_ADMIN_PAGE',true);if($path==='/staff-production.php')define('SITESEE_PRODUCTION_PAGE',true);require $root.'/server/booking-staff.php';}
    return true;
}
if(preg_match('~^/assets/fonts/(?:Inter-Regular|Poppins-Regular|Poppins-SemiBold)\.ttf$~D',(string)$path))return false;
if(preg_match('~^/portal-assets/(?:portal|order|payment|job-payment|onsite-services|vendor)\.(?:css|js)$~D',(string)$path))return false;
http_response_code(404);return true;
