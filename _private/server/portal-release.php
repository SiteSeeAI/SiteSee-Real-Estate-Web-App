<?php
declare(strict_types=1);
require_once __DIR__.'/application-mode.php';
/** A private installer flag controls the public portal without changing PHP-FPM. */
function portal_release_enabled(string $path): bool
{
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 1024) return false;
    $raw = @file_get_contents($path);
    $config = is_string($raw) ? json_decode($raw, true) : null;
    $expected=['release'=>'portal-20260929-r2', 'stage'=>site_runtime_mode(), 'enabled'=>true];
    return is_array($config) && count($config)===count($expected) && array_replace($expected,$config)===$expected;
}
