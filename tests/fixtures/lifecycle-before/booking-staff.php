<?php
declare(strict_types=1);

require_once __DIR__ . '/booking-workflow.php';
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
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SiteSee | Booking Review</title><style>body{margin:0;background:#01111e;color:#102031;font:16px/1.55 Inter,Arial,sans-serif}main{max-width:900px;margin:4vw auto;padding:36px;background:#fff;border-top:7px solid #ffc107}h1,h2{font-family:Poppins,Arial,sans-serif}label{display:block;margin:14px 0}input:not([type=checkbox]){padding:9px;max-width:100%;box-sizing:border-box}button{background:#ffc107;padding:12px 18px;border:0;font-weight:bold;cursor:pointer}pre{overflow:auto;white-space:pre-wrap;word-break:break-word;background:#f4f6f7;padding:18px}table{width:100%;border-collapse:collapse}td,th{text-align:left;border-bottom:1px solid #ddd;padding:9px}a{color:#07517d}dd{margin:0 0 12px;overflow-wrap:anywhere}form{margin:12px 0}.note{background:#fff7db;padding:14px}.error{color:#9a1825}</style></head><body><main><h1>SiteSee Booking Review</h1>'
        . $body . '</main></body></html>';
    exit;
}
function staff_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function staff_money(int $cents): string { return '$' . number_format($cents / 100, 2); }

$hash = booking_staff_password_hash();
if (!booking_test_enabled() || $hash === '' || !str_starts_with(SITESEE_REAL_ESTATE_SITE_URL, 'https://')) {
    staff_page('<p>Staff review is not configured.</p>', 503);
}
session_name('sitesee_real_estate_staff');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
$db = booking_db();
booking_communication_schema($db);
$error = '';
$issuedLink = '';
$notice = '';
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
    } elseif (in_array($action, ['workflow_check','workflow_link','workflow_recover','workflow_history'], true)) {
        try {
            $reference = (string)($_POST['reference'] ?? '');
            booking_workflow_row($db, $reference);
            if ($action === 'workflow_check') {
                $workflowReport = booking_workflow_check($db, $reference);
                $_SESSION['contact_selection'] = ['reference'=>$reference,'expires'=>time()+900,
                    'fingerprint'=>$workflowReport['fingerprint'],'ids'=>array_column($workflowReport['candidates'], 'id')];
                $alternatives = $workflowReport['alternatives'];
                $notice = 'Readiness checks completed. Review all results below. Nothing was sent or reserved.';
            } elseif ($action === 'workflow_link') {
                if (($_POST['contact_verified'] ?? '') !== 'yes') throw new InvalidArgumentException('Verify the displayed CRM contact first.');
                booking_workflow_link($db, $reference, (string)($_POST['contact_id'] ?? ''), $_SESSION['contact_selection'] ?? []);
                unset($_SESSION['contact_selection']);
                $notice = 'CRM contact verified and linked to this booking. No invitation was sent.';
            } elseif ($action === 'workflow_history') {
                if (($_POST['history_verified'] ?? '') !== 'yes') throw new InvalidArgumentException('Review the existing CRM email first.');
                $ticket = $_SESSION['history_selection'] ?? [];
                $id = (string)($_POST['message_id'] ?? '');
                if (($ticket['reference'] ?? '') !== $reference || ($ticket['expires'] ?? 0) < time() || !in_array($id, $ticket['ids'] ?? [], true)) {
                    throw new InvalidArgumentException('This email selection expired. Use Recover Booking Status again.');
                }
                $crmConfig = booking_crm_config();
                booking_communication_crm($db, 'invitation:' . $reference, $crmConfig, booking_crm_client($crmConfig), $id);
                unset($_SESSION['history_selection']);
                $notice = 'Existing CRM email linked. No invitation was resent.';
            } else {
                $workflowReport = booking_workflow_recover($db, $reference);
                $_SESSION['history_selection'] = ['reference'=>$reference,'expires'=>time()+900,'ids'=>array_column($workflowReport['history'], 'id')];
                $notice = 'Recovery completed. Review each result below. No calendar event or invitation was created.';
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage()
                : 'This workflow action could not finish. Use Check Booking Readiness for the combined results. Existing calendar and invitation attempts are preserved.';
        }
    } elseif (in_array($action, ['confirm_calendar', 'reconcile_calendar', 'send_invitation', 'recheck_mail', 'associate_crm', 'check_windows', 'select_window'], true)) {
        try {
            $reference = (string)($_POST['reference'] ?? '');
            if ($action === 'recheck_mail' || $action === 'associate_crm') {
                $key = 'invitation:' . $reference;
                if ($action === 'recheck_mail') {
                    booking_communication_reconcile($db,$key,booking_graph_client(booking_mail_config()));
                    $notice = 'Saved Microsoft 365 sent copy verified. No invitation was resent.';
                } else {
                    $crmConfig = booking_crm_config();
                    booking_communication_crm($db,$key,$crmConfig,booking_crm_client($crmConfig));
                    $notice = 'CRM association checked. Review its separate status below. No invitation was resent.';
                }
            } elseif ($action === 'check_windows') {
                $alternatives = booking_scheduling_alternatives($db,$reference);
                $notice = 'Available alternatives checked. No window was reserved or changed.';
            } elseif ($action === 'select_window') {
                if (($_POST['customer_agreed'] ?? '') !== 'yes') throw new InvalidArgumentException('Agree the new window with the customer first.');
                booking_scheduling_change_window($db,$reference,(string)($_POST['date']??''),(string)($_POST['time']??''),(string)($_POST['booking_fingerprint']??''));
                $notice = 'Arrival window updated. Save the staff review again before confirming. Deposit and payment identifiers are unchanged.';
            } elseif ($action === 'confirm_calendar') {
                if (($_POST['confirm_window'] ?? '') !== 'yes') throw new InvalidArgumentException('Confirm the customer-agreed window and reviewed duration.');
                booking_confirm_appointment($db, $reference);
                $notice = 'Test appointment confirmed on its assigned calendar. No invitation was sent and no payment was collected.';
            } elseif ($action === 'reconcile_calendar') {
                booking_reconcile_confirmation($db, $reference);
                $notice = 'Existing calendar event verified. No new event or invitation was created.';
            } else {
                if (($_POST['verify_recipient'] ?? '') !== 'yes') throw new InvalidArgumentException('Verify the displayed test recipient before sending.');
                $sendRow = booking_workflow_row($db, $reference);
                $sendStatus = booking_workflow_status($db, $sendRow);
                if (!$sendStatus['can_send']) throw new InvalidArgumentException('Review Booking Readiness & Recovery above. Link the CRM contact before sending; recover any existing invitation attempt without resending.');
                booking_send_invitation($db, $reference);
                $notice = 'Test invitation accepted by the mail server. Check the recipient mailbox to verify receipt.';
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException || $exception instanceof BookingCalendarUnavailable || $exception instanceof BookingMicrosoftCalendarError
                ? $exception->getMessage() : 'Confirmation could not be completed. Reload this booking to inspect its status before retrying.';
            if ($action === 'confirm_calendar' && !booking_confirmation_get($db,$reference)) {
                try { $alternatives = booking_scheduling_alternatives($db,$reference); }
                catch (Throwable) { $alternativesError = 'Availability could not be checked. Use Check Available Alternatives when the connection is available.'; }
            }
        }
    } elseif ($action === 'rotate') {
        try {
            $reference = (string)($_POST['reference'] ?? '');
            $token = booking_rotate_test_link($db, $reference);
            $issuedLink = SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?reference=' . rawurlencode($reference) . '&token=' . rawurlencode($token);
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The test link could not be replaced.';
        }
    } elseif ($action === 'decline_rush') {
        try {
            booking_decline_rush($db, (string)($_POST['reference'] ?? ''), (string)($_POST['reason'] ?? ''));
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Rush decision could not be saved.';
        }
    } elseif ($action === 'reschedule_link') {
        try {
            $reference = (string)($_POST['reference'] ?? '');
            $token = booking_reschedule_link($db, $reference);
            $issuedLink = SITESEE_REAL_ESTATE_SITE_URL . '/booking-pay.php?reference=' . rawurlencode($reference) . '&token=' . rawurlencode($token);
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Rescheduling link could not be created.';
        }
    } elseif ($action === 'review_paid') {
        try {
            booking_review_paid($db, (string)($_POST['reference'] ?? ''), (int)($_POST['duration'] ?? 0),
                (string)($_POST['photographer'] ?? ''), ($_POST['available'] ?? '') === 'yes', (string)($_POST['rush_decision'] ?? ''));
            $notice = 'Staff review saved. Readiness results are shown together below; no appointment or invitation was created.';
            try {
                $reference = (string)($_POST['reference'] ?? '');
                $workflowReport = booking_workflow_check($db, $reference);
                $_SESSION['contact_selection'] = ['reference'=>$reference,'expires'=>time()+900,
                    'fingerprint'=>$workflowReport['fingerprint'],'ids'=>array_column($workflowReport['candidates'], 'id')];
                $alternatives = $workflowReport['alternatives'];
            } catch (Throwable) {
                $notice = 'Staff review saved. The combined checks could not finish; use Check Booking Readiness when the connections are available.';
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Review could not be saved.';
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
$body = '<p class="note">TEST PHASE: new requests collect a test deposit before staff schedule review. Payment and review do not confirm an appointment. Final calendar confirmation and invitations require separate staff actions and enabled test controls. No live charges are collected. CRM email history is recorded separately when authorized. Older unpaid requests retain their original approval flow.</p>'
    . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="logout"><button>Sign Out</button></form>';
if ($notice) $body .= '<p class="note" role="status">' . staff_escape($notice) . '</p>';
if ($error) $body .= '<p class="error">' . staff_escape($error) . '</p>';
if ($issuedLink) {
    $body .= '<h2>Private Booking Link</h2><p>Copy this link to an authorized test payer. It appears only once; save it before leaving this page.</p><p><input type="text" readonly aria-label="Test payment link" value="' . staff_escape($issuedLink) . '" style="width:100%"></p><p><a href="' . staff_escape($issuedLink) . '" target="_blank" rel="noopener noreferrer">Open Booking Page ↗</a></p>';
}
$reference = (string)($_POST['reference'] ?? $_GET['reference'] ?? '');
$row = booking_get($db, $reference);
if ($row) {
    $request = booking_request($row);
    $details = $request['details'];
    $quote = $request['quote'];
    $body .= '<p><a href="staff-bookings.php">All requests</a></p><h2>Request ' . staff_escape($reference) . '</h2>'
        . '<p>Status: <strong>' . staff_escape($row['status']) . '</strong><br>Agent: ' . staff_escape($details['first'] . ' ' . $details['last'] . ' · ' . $row['email'])
        . '<br>Property: ' . staff_escape($details['street'] . ' ' . $details['unit'] . ', ' . $details['city'] . ', ' . $details['state'] . ' ' . $details['zip'])
        . '<br>Requested: ' . staff_escape($request['appointment']['date'] . ' ' . $request['appointment']['time'] . (isset($request['appointment']['windowEnd']) ? '–' . $request['appointment']['windowEnd'] . ' arrival window' : '')) . ' America/Chicago (' . staff_escape($row['requested_utc']) . ' window start UTC)</p>'
        . '<p>Server quote: <strong>' . staff_money((int)$row['quote_cents']) . '</strong>'
        . ($row['platform_monthly_cents'] ? '; residential platform separately ' . staff_money((int)$row['platform_monthly_cents']) . '/month if selected and published' : '')
        . '<br>Estimated on site: ' . staff_escape((string)($quote['knownMinutes'] ?? 0)) . '–' . staff_escape((string)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 0)) . ' minutes, plus any capture that requires confirmation.</p>'
        . '<details><summary>Full validated request (staff only, includes property access)</summary><pre>' . staff_escape($request['salesPlain']) . '</pre></details>';
    $body .= '<p>Rush status: <strong>' . staff_escape($row['rush_status']) . '</strong>; approved rush fee: <strong>' . staff_money((int)$row['rush_fee_cents']) . '</strong>.</p>';
    if ($row['reschedule_required']) {
        $body .= '<p class="note">Rush declined. No rush fee is charged. The agent must request another standard window at least 72 hours ahead. Their existing deposit remains credited; do not create a new booking.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="reschedule_link"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><button>Create Rescheduling Link</button></form>';
    }
    if ($row['status'] === 'awaiting_deposit_test') {
        $body .= '<p class="note">Waiting for the test deposit. Schedule review becomes available only after the verified payment notification.</p>';
    }
    $workflowStatus = null;
    if ($row['status'] === 'deposit_paid_test' && $row['deposit_paid_at'] && strcasecmp($row['email'], 'cro@sitesee.ai') === 0) {
        $workflowStatus = booking_workflow_status($db, $row);
        $body .= booking_workflow_html($row, $workflowStatus, staff_csrf(), $workflowReport ?? null);
    }
    if ($row['status'] === 'deposit_paid_test' && !$row['approved_at'] && !$row['reschedule_required']) {
        $duration = max(15, (int)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 60));
        $body .= '<h2>Review Paid Request</h2><p>Assign the photographer and check the requested arrival window. If another date is needed, agree it with the agent first. Saving this review records your assignment. Calendar confirmation and the invitation are separate staff actions.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="review_paid"><input type="hidden" name="reference" value="' . staff_escape($reference) . '">'
            . '<label>Photographer <input name="photographer" maxlength="120" value="David J Cro" required></label>'
            . '<label>Planned shoot duration (minutes) <input name="duration" type="number" min="15" max="1440" value="' . $duration . '" required></label>'
            . '<label><input type="checkbox" name="available" value="yes" required> I checked availability for the requested window and reviewed the scope.</label>'
            . ($row['rush_status'] === 'pending' ? '<label><input type="checkbox" name="rush_decision" value="approve" required> I approve rush service and the $59 fee on the remaining balance. No charge is made by this review.</label>' : '')
            . '<button>' . ($row['rush_status'] === 'pending' ? 'Approve Rush &amp; Save Test Review' : 'Save Test Review — No Invitation') . '</button></form>';
        if ($row['rush_status'] === 'pending') {
            $body .= '<h3>Decline Rush Service</h3><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="decline_rush"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><label>Reason <input name="reason" maxlength="500" required></label><button>Decline Rush — Request Another Window</button></form>';
        }
    } elseif ($row['status'] === 'deposit_paid_test' && $row['approved_at']) {
        $body .= '<p class="note">Staff review recorded. Use the calendar confirmation section below when test confirmation is enabled.</p>';
    }
    if ($row['status'] === 'deposit_paid_test' && $row['approved_at']) {
        $confirmation = booking_confirmation_get($db, $reference);
        try { $confirmationConfig = booking_scheduling_config($confirmation); } catch (Throwable) { $confirmationConfig = null; }
        $canConfirm = $confirmationConfig && $confirmationConfig['confirmation_enabled'];
        $body .= '<h2>Calendar Confirmation</h2><p>Calendar: ' . staff_escape($confirmationConfig ? (booking_scheduling_is_microsoft($confirmationConfig) ? 'Microsoft — sales@re.sitesee.ai' : 'Zoho — existing appointment connection') : 'Connection unavailable — confirmation blocked') . '</p><p>Photographer: ' . staff_escape((string)$row['photographer']) . '; reviewed shoot duration: ' . (int)$row['duration_minutes'] . ' minutes.</p>';
        if (!$confirmation) {
            $body .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="check_windows"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><button>Check Available Alternatives</button></form>';
            if (isset($alternativesError)) $body .= '<p class="note">' . staff_escape($alternativesError) . '</p>';
            if (isset($alternatives)) {
                $body .= '<h3>Available Alternatives</h3><p>Central Time. Suggestions are checked again when selected and when confirmed. Agree a change with the customer first.</p>';
                if (!$alternatives) $body .= '<p>No fitting windows were found in the next 14 days from the requested date.</p>';
                foreach ($alternatives as $alternative) {
                    $body .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="select_window"><input type="hidden" name="reference" value="' . staff_escape($reference)
                        . '"><input type="hidden" name="date" value="' . staff_escape($alternative['date']) . '"><input type="hidden" name="time" value="' . staff_escape($alternative['time'])
                        . '"><input type="hidden" name="booking_fingerprint" value="' . hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)) . '"><p><strong>' . staff_escape($alternative['date'] . ' ' . $alternative['time'] . '–' . $alternative['end_time'])
                        . ' Central</strong></p><label><input type="checkbox" name="customer_agreed" value="yes" required> The customer agreed to this arrival window.</label><button>Use This Window — Review Again</button></form>';
                }
            }
            $body .= '<p>Final confirmation checks the calendar again and blocks the full shoot duration inside the customer-agreed arrival window. It does not send an invitation.</p>';
            if ($canConfirm) {
                $body .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="confirm_calendar"><input type="hidden" name="reference" value="' . staff_escape($reference) . '">'
                    . '<label><input type="checkbox" name="confirm_window" value="yes" required> I approve this customer-agreed arrival window, David as photographer and the reviewed shoot duration.</label><button>Confirm Test Appointment</button></form>';
            } else $body .= '<p class="note">Calendar confirmation is disabled pending connection verification.</p>';
        } elseif ($confirmation['state'] !== 'confirmed') {
            $body .= '<p class="note">Calendar creation result is uncertain. Do not create another appointment. Recheck the existing result first; if it cannot be verified, inspect the assigned calendar manually.</p>'
                . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="reconcile_calendar"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><button>Recheck Calendar Result</button></form>';
        } else {
            $plannedStart = (new DateTimeImmutable('@' . $confirmation['planned_start']))->setTimezone(new DateTimeZone('America/Chicago'));
            $plannedEnd = (new DateTimeImmutable('@' . $confirmation['planned_end']))->setTimezone(new DateTimeZone('America/Chicago'));
            $body .= '<p class="note"><strong>Test Appointment Confirmed</strong><br>Internal planned shoot: ' . staff_escape($plannedStart->format('Y-m-d g:i A') . '–' . $plannedEnd->format('g:i A')) . ' Central Time.<br>The customer invitation retains the agreed two-hour arrival window.</p>';
            if ($confirmation['invitation_state'] === 'sent') {
                $body .= '<p>Invitation accepted by the mail server for ' . staff_escape((string)$confirmation['invitation_recipient']) . '. Mailbox receipt and calendar acceptance must be checked separately.</p>';
            } elseif ($confirmation['invitation_state'] !== 'none') {
                $body .= '<p class="note">Invitation delivery is uncertain. Check the recipient mailbox before any manual resend. Automatic retries are blocked.</p>';
            } elseif ($canConfirm && $confirmationConfig['invitations_enabled'] && ($workflowStatus['can_send'] ?? false)) {
                $body .= '<h2>Send Test Invitation</h2><p>Recipient: <strong>' . staff_escape($row['email']) . '</strong>. The invitation includes the property address and arrival window. Property access codes remain private.</p>'
                    . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="send_invitation"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><label><input type="checkbox" name="verify_recipient" value="yes" required> I verified this test recipient and want to send the calendar invitation.</label><button>Send Test Calendar Invitation</button></form>';
            } else $body .= '<p class="note">Sending is blocked by a prerequisite or an existing communication record. Use Booking Readiness &amp; Recovery above to link the contact or recover the saved attempt.</p>';
        }
    }
    $communications = $db->prepare('SELECT * FROM booking_communications WHERE reference=? ORDER BY created_at');
    $communications->execute([$reference]);
    foreach ($communications->fetchAll() as $communication) {
        $body .= '<h2>Communication Status</h2><p>' . staff_escape($communication['kind'])
            . ' — From: ' . staff_escape($communication['sender']) . '<br>Microsoft 365: ' . staff_escape($communication['submission_state'])
            . '<br>Recipient mailbox evidence: ' . staff_escape($communication['delivery_state'])
            . '<br>CRM: ' . staff_escape($communication['crm_state']) . '</p>';
        if ($communication['kind'] === 'invitation') $body .= '<p>Use Recover Booking Status above to check the sent copy, recipient evidence and CRM history together. Receipt and calendar acceptance are separate.</p>';
    }
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
            . '; payment: ' . staff_escape($row['checkout_state']) . '.</p><p>Remaining balance including any approved rush fee: <strong>' . staff_money(booking_remaining_cents($row)) . '</strong>. Final balance collection is not enabled in this test phase.</p>';
        if (in_array($row['status'], ['approved_test', 'awaiting_deposit_test'], true) && in_array($row['checkout_state'], ['ready', 'expired'], true)) {
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
            . '</td><td>' . staff_escape($item['email']) . '</td><td>' . staff_escape($item['status'] . ($item['calendar_status'] ? ' · calendar ' . $item['calendar_status'] : '')) . '</td></tr>';
    }
    $body .= '</table>';
}
staff_page($body);
