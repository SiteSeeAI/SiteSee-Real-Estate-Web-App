<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (is_file($root.'/_private/zoho-calendar.json')) throw new RuntimeException('Endpoint tests must not use a real calendar configuration.');
$dir=sys_get_temp_dir().'/sitesee-endpoint-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$router=$dir.'/router.php';
$config=var_export($root.'/_private/real-estate-form-config.php',true);
$endpoint=var_export($root.'/_private/server/booking-availability-check.php',true);
file_put_contents($router,'<?php putenv("SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai"); putenv("SITESEE_REAL_ESTATE_PRICING_GATE_SECRET=".str_repeat("fixture-only-",4));'
    .'if (parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)==="/fixture-session") { require '.$config.'; real_estate_pricing_start_session();'
    .'$_SESSION["real_estate_pricing_email"]="fixture@example.test"; $_SESSION["real_estate_pricing_expires"]=time()+600;'
    .'$_SESSION["calendar_feedback_csrf"]=str_repeat("a",64); $_SESSION["calendar_feedback_requests"]=[];'
    .'echo json_encode(["cookie"=>session_name()."=".session_id()]); exit;} require '.$endpoint.';');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Test port unavailable.');
$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root);
if(!is_resource($process))throw new RuntimeException('Test server did not start.');fclose($pipes[0]);
$checks=0;
function endpoint_check(bool $condition,string $message):void {global $checks;++$checks;if(!$condition)throw new RuntimeException($message);}
function endpoint_request(string $address,string $method,string $path,array $headers=[],string $body=''):array {
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>4]]);
    $data=@file_get_contents('http://'.$address.$path,false,$context);
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'', $match);
    return [(int)($match[1]??0),is_string($data)?json_decode($data,true):null];
}
try {
    for($attempt=0;$attempt<60;$attempt++){ $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);break;}usleep(50000); }
    endpoint_check(endpoint_request($address,'GET','/booking-availability.php')[0]===405,'Only POST is allowed.');
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',['Content-Type: application/json'],'{}')[0]===403,'Unapproved session cannot read availability.');
    [$status,$session]=endpoint_request($address,'GET','/fixture-session');
    endpoint_check($status===200 && isset($session['cookie']),'Test pricing session created.');
    $headers=['Content-Type: application/json','Origin: https://re.sitesee.ai','Sec-Fetch-Site: same-origin','Cookie: '.$session['cookie'],'X-SiteSee-Availability: '.str_repeat('a',64)];
    $body=json_encode(['market'=>'residential','date'=>'2026-10-02','time'=>'09:00','rush'=>false,'state'=>['package'=>'custom','category'=>'average','sqft'=>2000,'selected'=>['photo']]]);
    $bad=$headers;$bad[4]='X-SiteSee-Availability: invalid';
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$bad,$body)[0]===403,'CSRF token is required.');
    $bad=$headers;$bad[1]='Origin: https://other.example';
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$bad,$body)[0]===403,'Cross-origin requests are rejected.');
    $bad=$headers;$bad[2]='Sec-Fetch-Site: cross-site';
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$bad,$body)[0]===403,'Cross-site requests are rejected.');
    $bad=$headers;$bad[0]='Content-Type: text/plain';
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$bad,$body)[0]===415,'Only JSON requests are accepted.');
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$headers,str_repeat('x',8193))[0]===413,'Oversized requests are rejected.');
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$headers,'{bad')[0]===400,'Malformed JSON is rejected.');
    for($n=0;$n<20;$n++){
        [$status,$answer]=endpoint_request($address,'POST','/booking-availability.php',$headers,$body);
        endpoint_check($status===503 && $answer['state']==='unknown' && !isset($answer['date_windows']),'Missing configuration never produces free windows.');
    }
    endpoint_check(endpoint_request($address,'POST','/booking-availability.php',$headers,$body)[0]===429,'Per-session rate cap is enforced.');
    echo 'Availability endpoint: '.$checks." checks passed.\n";
} finally {
    proc_terminate($process);proc_close($process);
    foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);
}
