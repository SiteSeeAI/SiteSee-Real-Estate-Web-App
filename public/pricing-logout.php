<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private/real-estate-form-config.php';
real_estate_pricing_start_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'] ?? '',
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Cache-Control: no-store');
header('Location: pricing-request.html');
exit;
