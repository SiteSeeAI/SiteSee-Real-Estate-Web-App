<?php
declare(strict_types=1);

require_once __DIR__ . '/booking-store.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'');
header('Content-Type: text/html; charset=utf-8');

function staff_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function staff_page(string $body, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SiteSee | Booking Review</title><style>body{margin:0;background:#01111e;color:#102031;font:16px/1.55 Inter,Arial,sans-serif}main{max-width:900px;margin:4vw auto;padding:36px;background:#fff;border-top:7px solid #ffc107}h1,h2{font-family:Poppins,Arial,sans-serif}label{display:block;margin:14px 0}input:not([type=checkbox]){padding:9px;max-width:100%;box-sizing:border-box}button{background:#ffc107;padding:12px 18px;border:0;font-weight:bold;cursor:pointer}pre{overflow:auto;white-space:pre-wrap;word-break:break-word;background:#f4f6f7;padding:18px}table{width:100%;border-collapse:collapse}td,th{text-align:left;border-bottom:1px solid #ddd;padding:9px}a{color:#07517d}.note{background:#fff7db;padding:14px}.error{color:#9a1825}</style></head><body><main><h1>SiteSee Booking Review</h1>'
        . $body . '</main></body></html>';
    exit;
}
function staff_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function staff_money(int $cents): string { return '$' . number_format($cents / 100, 2); }

$hash = (string)getenv('SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH');
if (!booking_test_enabled() || $hash === '' || !str_starts_with(SITESEE_REAL_ESTATE_SITE_URL, 'https://')) {
    staff_page('<p>Staff review is not configured.</p>', 503);
}
session_name('sitesee_real_estate_staff');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
$db = booking_db();
$error = '';
$issuedLink = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (!real_estate_same_origin() || !hash_equals(staff_csrf(), (string)($_POST['csrf'] ?? ''))) {
        staff_page('<p>Review session expired. Reload the page.</p>', 403);
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $db->prepare('DELETE FROM staff_login_attempts WHERE at < ?')->execute([time() - 900]);
        $stmt = $db->prepare('SELECT COUNT(*) FROM staff_login_attempts WHERE ip_hash=?');
        $stmt->execute([$ipHash]);
        if ((int)$stmt->fetchColumn() >= 5) {
            staff_page('<p>Too many login attempts. Wait fifteen minutes.</p>', 429);
        }
        if (password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['staff_until'] = time() + 7200;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: staff-bookings.php', true, 303);
            exit;
        }
        $db->prepare('INSERT INTO staff_login_attempts (ip_hash,at) VALUES (?,?)')->execute([$ipHash, time()]);
        $error = 'Sign-in failed.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: staff-bookings.php', true, 303);
        exit;
    } elseif (empty($_SESSION['staff_until']) || (int)$_SESSION['staff_until'] < time()) {
        staff_page('<p>Your staff session has expired. Reload and sign in again.</p>', 403);
    } elseif ($action === 'rotate') {
        try {
            $reference = (string)($_POST['reference'] ?? '');
            $token = booking_rotate_test_link($db, $reference);
            $issuedLink = SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?reference=' . rawurlencode($reference) . '&token=' . rawurlencode($token);
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The test link could not be replaced.';
        }
    } elseif ($action === 'approve') {
        $price = (string)($_POST['final_price'] ?? '');
        if (!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D', $price)) {
            $error = 'Enter a valid final price in dollars and cents.';
        } else {
            [$dollars, $fraction] = array_pad(explode('.', $price, 2), 2, '');
            $cents = (int)$dollars * 100 + (int)str_pad($fraction, 2, '0');
            try {
                $reference = (string)($_POST['reference'] ?? '');
                $token = booking_approve(
                    $db, $reference, $cents, (int)($_POST['duration'] ?? 0),
                    (string)($_POST['photographer'] ?? ''), ($_POST['available'] ?? '') === 'yes',
                    (string)($_POST['price_reason'] ?? ''), (string)($_POST['crm_contact_id'] ?? '')
                );
                $issuedLink = SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?reference=' . rawurlencode($reference) . '&token=' . rawurlencode($token);
            } catch (Throwable $exception) {
                $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Approval could not be saved.';
            }
        }
    } else {
        staff_page('<p>Unknown action.</p>', 400);
    }
}
if (empty($_SESSION['staff_until']) || (int)$_SESSION['staff_until'] < time()) {
    $body = '<p>Staff sign-in is required. This page cannot be accessed with a pricing invitation.</p>'
        . ($error ? '<p class="error">' . staff_escape($error) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . staff_csrf() . '"><input type="hidden" name="action" value="login"><label>Password <input type="password" name="password" autocomplete="current-password" required></label><button>Sign In</button></form>';
    staff_page($body, $error ? 401 : 200);
}
$csrf = staff_escape(staff_csrf());
$body = '<p class="note">TEST PHASE: approving a request locks its test price and creates a private test payment link. No customer email, live charge, CRM event or calendar invitation is sent.</p>'
    . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="logout"><button>Sign Out</button></form>';
if ($error) $body .= '<p class="error">' . staff_escape($error) . '</p>';
if ($issuedLink) {
    $body .= '<h2>Test payment link</h2><p>Copy this link to an authorized test payer. It appears only once; save it before leaving this page.</p><p><input type="text" readonly aria-label="Test payment link" value="' . staff_escape($issuedLink) . '" style="width:100%"></p>';
}
$reference = (string)($_POST['reference'] ?? $_GET['reference'] ?? '');
$row = booking_get($db, $reference);
if ($row) {
    $request = json_decode($row['request_json'], true);
    $details = $request['details'];
    $quote = $request['quote'];
    $body .= '<p><a href="staff-bookings.php">All requests</a></p><h2>Request ' . staff_escape($reference) . '</h2>'
        . '<p>Status: <strong>' . staff_escape($row['status']) . '</strong><br>Agent: ' . staff_escape($details['first'] . ' ' . $details['last'] . ' · ' . $row['email'])
        . '<br>Property: ' . staff_escape($details['street'] . ' ' . $details['unit'] . ', ' . $details['city'] . ', ' . $details['state'] . ' ' . $details['zip'])
        . '<br>Requested: ' . staff_escape($request['appointment']['date'] . ' ' . $request['appointment']['time']) . ' America/Chicago (' . staff_escape($row['requested_utc']) . ' UTC)</p>'
        . '<p>Server quote: <strong>' . staff_money((int)$row['quote_cents']) . '</strong>'
        . ($row['platform_monthly_cents'] ? '; residential platform separately ' . staff_money((int)$row['platform_monthly_cents']) . '/month if selected and published' : '')
        . '<br>Estimated on site: ' . staff_escape((string)($quote['knownMinutes'] ?? 0)) . '–' . staff_escape((string)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 0)) . ' minutes, plus any capture that requires confirmation.</p>'
        . '<details><summary>Full validated request (staff only, includes property access)</summary><pre>' . staff_escape($request['salesPlain']) . '</pre></details>';
    if ($row['status'] === 'pending_review') {
        $duration = max(15, (int)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 60));
        $body .= '<h2>Approve for test checkout</h2><p>Check the photographer and availability outside this screen. The requested slot remains unconfirmed after approval.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="approve"><input type="hidden" name="reference" value="' . staff_escape($reference) . '">'
            . '<label>Photographer <input name="photographer" maxlength="120" required></label>'
            . '<label>Planned duration (minutes) <input name="duration" type="number" min="15" max="1440" value="' . $duration . '" required></label>'
            . '<label>Locked one-time price (USD) <input name="final_price" type="number" min=".50" max="1000000" step=".01" value="' . number_format((int)$row['quote_cents'] / 100, 2, '.', '') . '" required></label>'
            . '<label>Reason for a price change, if any <input name="price_reason" maxlength="500"></label>'
            . '<label>Production CRM Contact ID, if manually checked <input name="crm_contact_id" inputmode="numeric" maxlength="30"></label>'
            . '<label><input type="checkbox" name="available" value="yes" required> I checked photographer availability and reviewed the scope, duration and price.</label>'
            . '<button>Lock Price &amp; Create Test Link</button></form>';
    } else {
        $body .= '<p>Locked price: ' . staff_money((int)$row['approved_cents'])
            . '; test deposit: ' . staff_money((int)$row['deposit_cents'])
            . '; photographer: ' . staff_escape((string)$row['photographer'])
            . '; payment: ' . staff_escape($row['checkout_state']) . '.</p>';
        if ($row['status'] === 'approved_test' && in_array($row['checkout_state'], ['ready', 'expired'], true)) {
            $body .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf
                . '"><input type="hidden" name="action" value="rotate"><input type="hidden" name="reference" value="'
                . staff_escape($reference) . '"><button>Replace Lost Test Link</button></form>';
        }
    }
} else {
    $body .= '<h2>Recent requests</h2><table><tr><th>Reference</th><th>Date</th><th>Market</th><th>Agent</th><th>Status</th></tr>';
    foreach (booking_recent($db) as $item) {
        $body .= '<tr><td><a href="staff-bookings.php?reference=' . rawurlencode($item['reference']) . '">' . staff_escape($item['reference'])
            . '</a></td><td>' . staff_escape($item['created_at']) . '</td><td>' . staff_escape($item['market'])
            . '</td><td>' . staff_escape($item['email']) . '</td><td>' . staff_escape($item['status']) . '</td></tr>';
    }
    $body .= '</table>';
}
staff_page($body);
