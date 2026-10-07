<?php
declare(strict_types=1);

/** Shared public loader. Configure its private root in the server environment only. */
ini_set('display_errors', '0');
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

try {
    $siteSeePrivatePath = getenv('SITESEE_APPLICATION_PRIVATE_ROOT');
    if ($siteSeePrivatePath === false || $siteSeePrivatePath === '') $siteSeePrivatePath = '/home/sitesee/.sitesee-real-estate';
    if (!str_starts_with($siteSeePrivatePath, '/') || str_contains($siteSeePrivatePath, "\0") || is_link($siteSeePrivatePath)) {
        throw new RuntimeException('Invalid private application directory.');
    }
    $siteSeePrivateRoot = realpath($siteSeePrivatePath);
    $siteSeePublicRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($siteSeePublicRoot === false || empty($_SERVER['DOCUMENT_ROOT'])) $siteSeePublicRoot = realpath(__DIR__);
    if ($siteSeePrivateRoot === false || $siteSeePublicRoot === false
        || $siteSeePrivateRoot === $siteSeePublicRoot
        || str_starts_with($siteSeePrivateRoot, $siteSeePublicRoot . '/')) {
        throw new RuntimeException('Application source must remain outside the document root.');
    }
    if (!is_file($siteSeePrivateRoot . '/server/application.php')) throw new RuntimeException('Application bootstrap is missing.');
    require_once $siteSeePrivateRoot . '/server/application.php';
    site_application_bootstrap($siteSeePrivateRoot);
    unset($siteSeePrivatePath, $siteSeePrivateRoot, $siteSeePublicRoot);
} catch (Throwable) {
    error_log('SiteSee application bootstrap is unavailable. Check private configuration and deployment.');
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('SiteSee access is temporarily unavailable.');
}
