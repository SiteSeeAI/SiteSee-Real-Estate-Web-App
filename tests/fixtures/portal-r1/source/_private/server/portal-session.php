<?php
declare(strict_types=1);

const PORTAL_IDLE_SECONDS = 1800;
const PORTAL_ABSOLUTE_SECONDS = 43200;

function portal_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) throw new LogicException('A different session is active.');
    $path = dirname(__DIR__) . '/data/portal-sessions';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Session storage unavailable.');
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $real = realpath($path);
    if (is_link($path) || $real === false || (fileperms($path) & 0077) !== 0
        || ($root !== false && ($real === $root || str_starts_with($real, $root . '/')))) {
        throw new RuntimeException('Session storage must be private.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)PORTAL_ABSOLUTE_SECONDS);
    session_save_path($path);
    session_name('__Host-sitesee_portal');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) throw new RuntimeException('Session unavailable.');
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function portal_session_clear(): void
{
    $_SESSION = [];
    if (!session_regenerate_id(true)) throw new RuntimeException('Session reset failed.');
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function portal_session_login(array $account, ?int $now = null): void
{
    $now ??= time();
    portal_session_clear();
    $_SESSION['portal'] = ['account_id'=>$account['id'], 'started'=>$now, 'seen'=>$now];
}

function portal_session_account(PDO $db, ?int $now = null): array|false
{
    $now ??= time();
    $s = $_SESSION['portal'] ?? null;
    if (!is_array($s)) return false;
    if (!is_int($s['started'] ?? null) || !is_int($s['seen'] ?? null)
        || $s['started'] > $now || $s['seen'] > $now
        || $now - $s['started'] >= PORTAL_ABSOLUTE_SECONDS || $now - $s['seen'] >= PORTAL_IDLE_SECONDS
        || !is_string($s['account_id'] ?? null) || !($account = portal_active_account($db, $s['account_id']))) {
        portal_session_clear();
        return false;
    }
    $_SESSION['portal']['seen'] = $now;
    return $account;
}

function portal_csrf_valid(array $post): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    return ($origin === '' || $origin === SITESEE_REAL_ESTATE_SITE_URL)
        && in_array($site, ['', 'same-origin', 'none'], true)
        && is_string($post['csrf'] ?? null) && is_string($_SESSION['csrf'] ?? null)
        && hash_equals($_SESSION['csrf'], $post['csrf']);
}
