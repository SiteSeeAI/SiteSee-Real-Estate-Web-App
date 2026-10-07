<?php
declare(strict_types=1);
require_once __DIR__ . '/../_private/server/application.php';
$checks = 0;
function application_ok(bool $condition, string $message): void {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
$fixture = sys_get_temp_dir() . '/sitesee-bootstrap-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700); mkdir($fixture . '/public', 0700); mkdir($fixture . '/private', 0700);
mkdir($fixture . '/private/server', 0700); mkdir($fixture . '/private/views', 0700);
register_shutdown_function(static function () use ($fixture): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture);
});
copy(__DIR__ . '/../_private/server/application.php', $fixture . '/private/server/application.php');
copy(__DIR__ . '/../_private/server/portal-release.php', $fixture . '/private/server/portal-release.php');
copy(__DIR__ . '/../public/application-entry.php', $fixture . '/public/application-entry.php');
$routes = site_application_routes();
$original = json_decode(file_get_contents(__DIR__.'/../documents/unified/bootstrap-source.json'), true, flags:JSON_THROW_ON_ERROR);
$contracts = [];
foreach ($original['files'] as $path => $change) {
    $before = base64_decode($change['before'], true);
    application_ok(hash('sha256', $before) === $change['before_sha256'], 'Original route contract has its recorded digest.');
    preg_match_all("~require '/home/sitesee/\\.sitesee-real-estate/([^']+)';~", $before, $includes);
    $contracts[basename($path)] = ['handler'=>end($includes[1]), 'portal'=>str_contains($before,'portal_release_enabled'),
        'production'=>str_contains($before,"define('SITESEE_PRODUCTION_PAGE',true)"),
        'vendors'=>str_contains($before,"define('SITESEE_VENDOR_ADMIN_PAGE',true)")];
}
$actualNames=array_keys($routes); $originalNames=array_keys($contracts); sort($actualNames); sort($originalNames);
application_ok($actualNames===$originalNames, 'Every original route alias is retained.');
foreach ($contracts as $name => $entry) {
    copy(__DIR__ . '/../public/' . $name, $fixture . '/public/' . $name);
    // Controller sentinels prove route loading without database or provider side effects.
    file_put_contents($fixture . '/private/' . $entry['handler'], '<?php echo json_encode(["handler"=>'.var_export($entry['handler'],true).',"production"=>defined("SITESEE_PRODUCTION_PAGE"),"vendors"=>defined("SITESEE_VENDOR_ADMIN_PAGE"),"portal"=>getenv("SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED"),"booking"=>getenv("SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED")]);');
}
$runner = $fixture . '/run.php';
file_put_contents($runner, '<?php $_SERVER["DOCUMENT_ROOT"]=$argv[2];$_SERVER["SCRIPT_FILENAME"]=$argv[1];register_shutdown_function(static function(){fwrite(STDERR,"STATUS:".(http_response_code()?:200));});require $argv[1];');
function application_request(string $route, array $overrides = []): array {
    global $fixture, $runner;
    $env = array_replace(getenv(), ['SITESEE_APPLICATION_PRIVATE_ROOT'=>$fixture.'/private', 'SITESEE_APPLICATION_STAGE'=>'TEST',
        'SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED'=>'1', 'SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED'=>'0',
        'SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET'=>'sk_test_synthetic_only', 'SITESEE_REAL_ESTATE_STRIPE_PUBLISHABLE_KEY'=>''], $overrides);
    $process = proc_open([PHP_BINARY, $runner, $fixture.'/public/'.$route, $fixture.'/public'], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, $env);
    $body = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    preg_match('/STATUS:(\d+)/', $error, $status);
    return [$body, (int)($status[1] ?? 0), $exit];
}
foreach ($contracts as $name => $entry) {
    [$body, $status, $exit] = application_request($name);
    $result = json_decode($body, true, flags:JSON_THROW_ON_ERROR);
    application_ok($exit === 0 && $status === 200 && $result['handler'] === $entry['handler'], 'Existing route alias reaches the same handler: '.$name);
    application_ok($result['booking'] === '0', 'Bootstrap never enables booking: '.$name);
    application_ok($result['production'] === $entry['production'] && $result['vendors'] === $entry['vendors'], 'Staff page flags stay separate: '.$name);
    if (!empty($entry['portal'])) application_ok($result['portal'] === '0', 'Missing physical portal gate overrides inherited enabled flag.');
}
$flag = $fixture.'/private/portal-test.json';
file_put_contents($flag, json_encode(['release'=>'portal-20260929-r2','stage'=>'TEST','enabled'=>true])); chmod($flag,0600);
foreach (['account.php','vendor.php'] as $route) {
    application_ok(json_decode(application_request($route)[0],true)['portal']==='1', 'Valid private flag preserves portal activation.');
}
chmod($flag,0644);
application_ok(json_decode(application_request('account.php')[0],true)['portal']==='0', 'Publicly readable flag remains disabled.');
foreach ([['SITESEE_APPLICATION_STAGE'=>'LIVE'], ['SITESEE_APPLICATION_STAGE'=>'0'], ['SITESEE_APPLICATION_PRIVATE_ROOT'=>'0'], ['SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET'=>'sk_live_NEVER_EXPOSE_THIS'],
    ['SITESEE_REAL_ESTATE_STRIPE_PUBLISHABLE_KEY'=>'pk_live_NEVER_EXPOSE_THIS'], ['SITESEE_APPLICATION_PRIVATE_ROOT'=>$fixture.'/public'],
    ['SITESEE_APPLICATION_PRIVATE_ROOT'=>'https://example.invalid/private'], ['SITESEE_APPLICATION_PRIVATE_ROOT'=>'relative/private'],
    ['SITESEE_APPLICATION_PRIVATE_ROOT'=>$fixture.'/missing']] as $bad) {
    [$body,$status,$exit] = application_request('account.php',$bad);
    application_ok($status===503 && $exit===0 && $body==='SiteSee access is temporarily unavailable.', 'Unsafe configuration fails closed without secrets or paths.');
}
symlink($fixture.'/private',$fixture.'/private-link');
application_ok(application_request('account.php',['SITESEE_APPLICATION_PRIVATE_ROOT'=>$fixture.'/private-link'])[1]===503, 'Private root symlink is rejected.');
application_ok(application_request('application-entry.php')[1]===404, 'Shared loader is not a public controller.');
try { site_application_route_target('../server/booking-staff.php'); throw new RuntimeException('Unknown route accepted.'); }
catch (InvalidArgumentException) { application_ok(true, 'Request data cannot select an arbitrary PHP file.'); }
application_ok(count($routes)===17 && $routes['account.php']['role']==='customer' && $routes['vendor.php']['role']==='vendor' && $routes['staff-bookings.php']['role']==='staff', 'Customer, vendor and staff entry points remain distinct.');
echo "Application bootstrap: $checks route and configuration checks passed.\n";
