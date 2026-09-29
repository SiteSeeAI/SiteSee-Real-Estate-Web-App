<?php
declare(strict_types=1);
// Test-only CLI server router. Never package or install fixtures into a public directory.
if (PHP_SAPI!=='cli-server' || !str_starts_with((string)getenv('PORTAL_TEST_PRIVATE'),sys_get_temp_dir().'/sitesee-portal-http-')) exit;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/account.php') {
    // The test's local HTTPS proxy terminates TLS; no production proxy headers are trusted.
    require __DIR__.'/service-providers.php';
    $_SERVER['HTTPS']='on';require getenv('PORTAL_TEST_ACCOUNT');return true;
}
if(preg_match('~^/assets/fonts/(?:Inter-Regular|Poppins-Regular|Poppins-SemiBold)\.ttf$~D',(string)$path))return false;
if(preg_match('~^/portal-assets/(?:portal|order|payment)\.(?:css|js)$~D',(string)$path))return false;
http_response_code(404);return true;
