<?php
declare(strict_types=1);
$root=getenv('STAFF_TEST_PRIVATE');
if(PHP_SAPI!=='cli-server' || !str_starts_with((string)$root,sys_get_temp_dir().'/sitesee-staff-http-'))exit;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/staff-window-fixture.php'){
    // Exercise the production view/picker against saved synthetic state; no provider request.
    require $root.'/server/booking-lifecycle-ui.php';
    $db=booking_db();$row=booking_get($db,'AB0000000A');$date=booking_request($row)['appointment']['date'];
    $windows=[['date'=>$date,'time'=>'07:00','end_time'=>'09:00'],['date'=>$date,'time'=>'09:00','end_time'=>'11:00'],['date'=>$date,'time'=>'13:00','end_time'=>'15:00']];
    echo '<!doctype html><html><head><title>Isolated window view</title></head><body>'.booking_lifecycle_html($db,$row,'synthetic-csrf',true,$windows).'<script src="/portal-assets/booking-review.js" defer></script></body></html>';return true;
}
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
if(in_array($path,['/portal-assets/onsite-services.js','/portal-assets/booking-review.js'],true))return false;
if($path==='/portal-assets/application.css')return false;
http_response_code(404);return true;
