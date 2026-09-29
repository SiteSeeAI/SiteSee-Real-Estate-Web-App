<?php
declare(strict_types=1);
/** A private installer flag controls the public portal without changing PHP-FPM. */
function portal_release_enabled(string $path): bool
{
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 1024) return false;
    $raw = @file_get_contents($path);
    $config = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($config) && $config === ['release'=>'portal-20260929-r1', 'stage'=>'TEST', 'enabled'=>true];
}
