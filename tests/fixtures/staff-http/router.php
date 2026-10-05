<?php
declare(strict_types=1);
$root=getenv('STAFF_TEST_PRIVATE');
if(PHP_SAPI!=='cli-server' || !str_starts_with((string)$root,sys_get_temp_dir().'/sitesee-staff-http-'))exit;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($path,['/staff-bookings.php','/staff-before.php'],true)){
    // No real provider connection is possible, including from an accidental action.
    function booking_provider_http(string $method,string $url,array $headers,?string $body=null): array {
        file_put_contents(getenv('STAFF_TEST_PRIVATE').'/provider-blocked.txt',"blocked\n",FILE_APPEND);
        throw new RuntimeException('External providers are blocked in this isolated fixture.');
    }
    $_SERVER['HTTPS']='on';
    require ($path==='/staff-before.php'?getenv('STAFF_TEST_BEFORE'):$root).'/server/booking-staff.php';return true;
}
if(preg_match('~^/assets/fonts/(?:Inter-Regular|Poppins-Regular|Poppins-SemiBold)\.ttf$~D',(string)$path))return false;
if($path==='/portal-assets/onsite-services.js')return false;
http_response_code(404);return true;
