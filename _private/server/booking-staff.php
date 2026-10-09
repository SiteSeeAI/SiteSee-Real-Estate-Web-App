<?php
declare(strict_types=1);

require_once __DIR__ . '/booking-lifecycle-ui.php';
require_once __DIR__ . '/booking-job-ui.php';
require_once __DIR__ . '/booking-review-ui.php';
require_once __DIR__ . '/vendor-admin.php';
require_once __DIR__ . '/booking-list-ui.php';
require_once dirname(__DIR__).'/views/application-shell.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Content-Security-Policy: default-src \'none\'; script-src \'self\'; connect-src \'self\'; style-src \'self\' \'unsafe-inline\'; font-src \'self\'; form-action \'self\'; base-uri \'none\'');
header('Content-Type: text/html; charset=utf-8');

function staff_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function staff_page(string $body, int $status = 200): never
{
    http_response_code($status);
    $style = <<<'CSS'
@font-face{font-family:Inter;src:url(/assets/fonts/Inter-Regular.ttf) format("truetype");font-display:swap}
@font-face{font-family:Poppins;src:url(/assets/fonts/Poppins-Regular.ttf) format("truetype");font-weight:400 500;font-display:swap}
@font-face{font-family:Poppins;src:url(/assets/fonts/Poppins-SemiBold.ttf) format("truetype");font-weight:600 700;font-display:swap}
:root{color-scheme:light;font:16px/1.6 Inter,Arial,sans-serif;color:#17252e;background:#f7f8f9}*{box-sizing:border-box}body{margin:0}header{background:#01111e;color:#fff;padding:24px max(6%,calc((100% - 1080px)/2));border-bottom:4px solid #ffc107}.brand{font:600 30px/1.2 Poppins,Arial,sans-serif}.brand span{color:#ffc107}header small{display:block;font-size:12px;margin-top:5px}main{max-width:1080px;margin:auto;padding:32px 24px 60px}h1,h2,h3{font-family:Poppins,Arial,sans-serif;color:#01111e;line-height:1.3;font-weight:500}h1{font-size:30px;margin:0 0 20px}h2{font-size:22px}h3{font-size:18px}p{margin:0 0 18px}a{color:#07517d;text-underline-offset:4px}a,button,summary,input,select{touch-action:manipulation}button,input,select{font:inherit}label{display:grid;gap:7px;margin:18px 0;max-width:680px}label:has(input[type=checkbox]){display:flex;gap:12px;align-items:flex-start}input:not([type=checkbox]),select{padding:10px 12px;border:1px solid #9faab1;border-radius:5px;min-height:46px;max-width:100%;width:100%;min-width:0}input[type=checkbox]{width:20px;height:20px;flex:0 0 20px;margin:3px 0}button{background:#01111e;color:#fff;padding:12px 18px;border:1px solid #01111e;border-radius:6px;min-height:46px;max-width:100%;cursor:pointer;text-align:left}button:hover{background:#163142}.quiet button{background:#fff;color:#17252e;border-color:#a6afb5}form{margin:16px 0}.topline{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}.topline form{margin:0}.test-label{font-size:13px;font-weight:600;letter-spacing:1px}.help,.eyebrow{color:#58636b;font-size:14px}.eyebrow{font-size:12px;letter-spacing:.6px}.note,.error{padding:16px 20px;border-left:4px solid #ba8b00;background:#fff8dd;margin:20px 0}.error{background:#fff0f0;border-color:#9a1825;color:#9a1825}.card,.staff-fold{background:#fff;border:1px solid #d9dfe2;border-radius:8px;margin:20px 0;padding:24px}.next-step{border-left:4px solid #ffc107}.facts,.progress{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 28px;margin:18px 0}.progress{grid-template-columns:repeat(4,minmax(0,1fr));padding-top:18px;border-top:1px solid #d9dfe2}.progress dt{font-size:12px}.progress dd{font-size:14px;font-weight:600}dt{font-size:13px;color:#58636b}dd{margin:3px 0 0;overflow-wrap:anywhere}summary{cursor:pointer;min-height:44px;padding:8px 0;color:#01111e;font-weight:600}details[open]>summary{margin-bottom:16px}.staff-fold{padding:12px 24px}.staff-fold>summary{font-family:Poppins,Arial,sans-serif;font-size:18px}.section-body>h2:first-child,.section-body>section>h2:first-child{display:none}.section-body{padding:0 0 8px}details details{border-top:1px solid #d9dfe2;padding-top:8px;margin-top:20px}pre{overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#f4f6f7;padding:16px;font:14px/1.6 Inter,Arial,sans-serif}li{margin:10px 0}.request-list{display:grid;gap:14px}.request-item{background:#fff;border:1px solid #d9dfe2;border-radius:8px;padding:20px 24px;display:grid;grid-template-columns:1fr auto;gap:10px 24px;align-items:center}.request-item h3{margin:4px 0 8px;overflow-wrap:anywhere}.request-item p{margin:4px 0;overflow-wrap:anywhere}.request-link{padding:10px 0;min-height:44px;white-space:nowrap}footer{border-top:1px solid #d9dfe2;padding:24px;color:#58636b;font-size:13px;text-align:center}:focus-visible{outline:3px solid #147eb3;outline-offset:4px}.skip{position:absolute;left:12px;top:-100px;background:#fff;padding:12px;z-index:1}.skip:focus{top:12px}
[hidden]{display:none!important}#job-service-items fieldset{border:1px solid #d9dfe2;border-radius:6px;padding:14px 18px;margin:18px 0}#job-service-items legend{font-weight:600;padding:0 6px}#job-service-items button{background:#fff;color:#17252e;border-color:#a6afb5}#job-commission input{background:#f7f8f9}button:disabled{opacity:.55;cursor:wait}
.booking-steps{display:flex;flex-wrap:wrap;gap:8px;margin:24px 0}.booking-steps a{display:inline-flex;align-items:center;min-height:44px;padding:9px 14px;border:1px solid #d9dfe2;border-radius:6px;background:#fff;text-decoration:none;overflow-wrap:anywhere}.booking-steps a[aria-current=step]{background:#01111e;color:#fff;border-color:#01111e}.step-primary{display:inline-block;background:#01111e;color:#fff;border-radius:6px;padding:12px 20px;min-height:46px;text-decoration:none}.step-continue{padding-top:20px;border-top:1px solid #d9dfe2}.booking-screen>h2{margin-bottom:8px}.booking-screen>.eyebrow{margin-bottom:4px}
@media(max-width:650px){main{padding:26px 6% 44px}h1{font-size:25px}h2{font-size:20px}.card,.staff-fold{padding:18px}.staff-fold{padding:10px 18px}.facts{grid-template-columns:1fr}.progress{grid-template-columns:1fr 1fr;gap:16px}.request-item{grid-template-columns:1fr;padding:18px}.request-link{justify-self:start}.topline{align-items:flex-start}button{font-size:14px}header{padding:22px 6%}#job-service-items fieldset{padding:10px}}
CSS;
    $staffTitle=defined('SITESEE_VENDOR_ADMIN_PAGE')&&SITESEE_VENDOR_ADMIN_PAGE?'Vendor Accounts':(defined('SITESEE_PRODUCTION_PAGE')&&SITESEE_PRODUCTION_PAGE?'Production':'Booking Review');
    echo site_application_shell('staff', $staffTitle, $body, !empty($_SESSION['staff_until']) && (int)$_SESSION['staff_until'] >= time(), $style);
    exit;
}
function staff_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function staff_money(int $cents): string { return '$' . number_format($cents / 100, 2); }

/** Presentation only. Every original form and its server-side guard is retained. */
function staff_disclosure(string $id, string $title, string $html, bool $open = false): string
{
    return '<details class="staff-fold" id="' . staff_escape($id) . '"' . ($open ? ' open' : '') . '><summary>' . staff_escape($title) . '</summary><div class="section-body">' . $html . '</div></details>';
}

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
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)staff_page('<p>Request too large.</p>',413);
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
            header('Location: '.(defined('SITESEE_VENDOR_ADMIN_PAGE')&&SITESEE_VENDOR_ADMIN_PAGE?'staff-vendors.php':(defined('SITESEE_PRODUCTION_PAGE')&&SITESEE_PRODUCTION_PAGE?'staff-production.php':'staff-bookings.php')), true, 303);
            exit;
        }
        $db->prepare('INSERT INTO staff_login_attempts (ip_hash,at) VALUES (?,?)')->execute([$ipHash, time()]);
        $error = 'Sign-in failed.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: '.(defined('SITESEE_VENDOR_ADMIN_PAGE')&&SITESEE_VENDOR_ADMIN_PAGE?'staff-vendors.php':(defined('SITESEE_PRODUCTION_PAGE')&&SITESEE_PRODUCTION_PAGE?'staff-production.php':'staff-bookings.php')), true, 303);
        exit;
    } elseif (empty($_SESSION['staff_until']) || (int)$_SESSION['staff_until'] < time()) {
        staff_page('<p>Your staff session has expired. Reload and sign in again.</p>', 403);
    } elseif (str_starts_with($action, 'vendor_')) {
        try { $notice=vendor_admin_action($db,$action); }
        catch(Throwable $exception){$error=$exception instanceof InvalidArgumentException?$exception->getMessage():'The vendor account action could not finish. Refresh before trying again.';}
    } elseif (str_starts_with($action, 'job_')) {
        if($action==='job_preview')staff_job_preview_response($db);
        try { $notice=staff_job_action($db,$action); }
        catch(Throwable $exception){$error=$exception instanceof InvalidArgumentException?$exception->getMessage():'The job action needs recovery. Review the saved status before retrying.';}
    } elseif (str_starts_with($action, 'lifecycle_')) {
        try {
            if (!booking_lifecycle_enabled()) throw new InvalidArgumentException('Appointment management is not enabled.');
            $reference=(string)($_POST['reference']??'');
            booking_lifecycle_row($db,$reference);
            $choice=substr($action,10);
            if($choice==='approve_request'){
                $report=booking_change_request_approve($db,$reference,(string)($_POST['request_id']??''),($_POST['agreed']??'')==='yes');
                $notice='Manager approval saved. '.implode(' ',array_filter($report,'is_string'));
            }elseif($choice==='reject_request'){
                $report=booking_change_request_reject($db,$reference,(string)($_POST['request_id']??''),($_POST['agreed']??'')==='yes');
                $notice='Requested window declined. Confirmed calendar and payment records remain unchanged. '.implode(' ',array_filter($report,'is_string'));
            }elseif(in_array($choice,['decline_notice','recover_decline_notice'],true)){
                if($choice==='decline_notice'&&($_POST['agreed']??'')!=='yes')throw new InvalidArgumentException('Review the saved decline email before sending.');
                $id=(string)($_POST['request_id']??'');
                $report=booking_change_request_decline_notice($db,$reference,$id,$choice==='decline_notice',['prepare_missing'=>$choice==='decline_notice']);
                $notice=implode(' ',array_filter($report,'is_string'));
                $lifecycleHistory=$report['history']??[];$_SESSION['lifecycle_history_selection']=['reference'=>$reference,'expires'=>time()+900,'ids'=>array_column($lifecycleHistory,'id'),'key'=>'change-declined-'.$id.':'.$reference];
            }elseif(in_array($choice,['request_notice','recover_request_notice'],true)){
                $mail=booking_change_request_notify($db,$reference,(string)($_POST['request_id']??''),$choice==='request_notice');
                $notice='Division request notification: '.$mail['submission_state'].'. Receipt: '.booking_communication_receipt_status($mail);
            }elseif ($choice==='link') $issuedLink=booking_management_issue($db,$reference);
            elseif ($choice==='history') {
                $ticket=$_SESSION['lifecycle_history_selection']??[];$id=(string)($_POST['message_id']??'');
                if(($_POST['agreed']??'')!=='yes'||($ticket['reference']??'')!==$reference||($ticket['expires']??0)<time()||!in_array($id,$ticket['ids']??[],true))throw new InvalidArgumentException('Recover Notice & Zoho History again and verify the displayed candidate.');
                $c=booking_crm_config();booking_communication_crm($db,$ticket['key'],$c,booking_crm_client($c),$id);unset($_SESSION['lifecycle_history_selection']);$notice='Existing change notice linked to Zoho; no message was resent.';
            }
            elseif ($choice==='resolve_unchanged') { if(($_POST['agreed']??'')!=='yes')throw new InvalidArgumentException('Review the unresolved attempt first.');booking_lifecycle_resolve_unchanged($db,$reference);$notice='Provider version remains unchanged. The unapplied attempt was resolved without another calendar write.'; }
            elseif ($choice==='legacy_deleted') { if(($_POST['agreed']??'')!=='yes')throw new InvalidArgumentException('Verify the original event deletion first.');booking_lifecycle_legacy_deleted($db,$reference,(string)($_POST['fingerprint']??''));$notice='Verified stale reservation released. Confirm cancellation to prepare its customer notice.'; }
            elseif ($choice==='finance_recover') { if(!booking_finance_plan($db,$reference))throw new InvalidArgumentException('No saved cancellation billing exists.');booking_finance_worker_key(true);booking_finance_process($db,$reference);$notice='Saved cancellation billing checked. Original refund requests preserved.'; }
            elseif ($choice==='close_order') { booking_order_close_cancelled($db,$reference,(string)($_POST['fingerprint']??''),($_POST['agreed']??'')==='yes',(string)($_POST['payment_disposition']??''));$notice='Cancelled order closed. Payment records are preserved; no refund or credit was issued by this action.'; }
            elseif ($choice==='sync') { booking_lifecycle_sync($db,$reference);$notice='Calendar state reconciled. No event or notice was sent.'; }
            elseif ($choice==='windows') {
                $lifecycleWindows=booking_lifecycle_windows($db,$reference,(string)($_POST['date']??''));
                $notice=$lifecycleWindows?'Available alternatives are shown in Manage Appointment.':'No fitting windows found in the next 14 days. Choose a later starting date.';
            } elseif (in_array($choice,['cancel','reschedule','adopt'],true)) {
                if (($_POST['agreed']??'')!=='yes') throw new InvalidArgumentException('Record the customer’s agreement first.');
                if($choice==='cancel'){
                    $result=booking_staff_cancel($db,$reference,(string)($_POST['fingerprint']??''));
                    $notice='Appointment cancellation saved. '.implode(' ',array_filter($result['notice'],'is_string'));
                }else{
                    booking_lifecycle_change($db,$reference,$choice,(string)($_POST['fingerprint']??''),'staff',(string)($_POST['date']??''),(string)($_POST['time']??''));
                    $notice='Appointment change saved. Send the saved change notice below.';
                }
            } elseif (in_array($choice,['notice','recover_notice'],true)) {
                $revision=(string)($_POST['notice_revision']??'');
                if(!ctype_digit($revision))throw new InvalidArgumentException('Refresh and review the saved notice.');
                if($choice==='notice'&&($_POST['agreed']??'')!=='yes')throw new InvalidArgumentException('Confirm sending the saved customer notice.');
                $report=booking_lifecycle_notice($db,$reference,$choice==='notice',['notice_revision'=>(int)$revision]);$notice=implode(' ',array_filter($report,'is_string'));
                $lifecycleHistory=$report['history']??[];$_SESSION['lifecycle_history_selection']=['reference'=>$reference,'expires'=>time()+900,'ids'=>array_column($lifecycleHistory,'id'),'key'=>'lifecycle-'.booking_lifecycle_state($db,$reference)['revision'].':'.$reference];
            } else throw new InvalidArgumentException('Unknown appointment action.');
        } catch (Throwable $exception) {
            $error=$exception instanceof InvalidArgumentException || $exception instanceof BookingCalendarUnavailable
                ?$exception->getMessage():'Appointment management could not finish. Reconcile Calendar / Recover Change before repeating any action.';
        }
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
    } elseif (in_array($action, ['confirm_calendar', 'reconcile_calendar', 'send_invitation', 'resume_invitation', 'recheck_mail', 'associate_crm', 'check_windows', 'select_window'], true)) {
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
            } elseif ($action === 'resume_invitation') {
                if (($_POST['verify_recipient'] ?? '') !== 'yes') throw new InvalidArgumentException('Confirm the RE business recipient before sending the saved draft.');
                booking_workflow_row($db,$reference);
                booking_resume_invitation($db,$reference);
                $workflowReport = booking_workflow_recover($db,$reference);
                $_SESSION['history_selection'] = ['reference'=>$reference,'expires'=>time()+900,'ids'=>array_column($workflowReport['history'], 'id')];
                $notice = 'Saved invitation submitted once. Review the sent-copy, recipient and CRM results below.';
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
            $selection=(string)($_POST['vendor_selection']??'');
            if(!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{32})$/D',$selection,$vendorChoice))throw new InvalidArgumentException('Choose an active vendor.');
            vendor_review_paid($db,(string)($_POST['reference']??''),$vendorChoice[1],$vendorChoice[2],(int)($_POST['duration']??0),($_POST['available']??'')==='yes',(string)($_POST['rush_decision']??''));
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
$postedAction = $method === 'POST' ? (string)($_POST['action'] ?? '') : '';
$body = '<div class="topline"><div><span class="test-label">TEST BOOKINGS</span><p class="help">Payment, calendar confirmation and invitations are separate steps.</p></div>'
    . '<form method="post" class="quiet"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="logout"><button>Sign Out</button></form></div>';
if ($notice) $body .= '<p class="note" role="status">' . staff_escape($notice) . '</p>';
if ($error) $body .= '<p class="error" role="alert">' . staff_escape($error) . '</p>';
if ($issuedLink) {
    $body .= '<h2>Private Booking Link</h2><p>Copy this private link to the authorized test customer. It appears only once; save it before leaving this page.</p><p><input type="text" readonly aria-label="Private booking link" value="' . staff_escape($issuedLink) . '" style="width:100%"></p><p><a href="' . staff_escape($issuedLink) . '" target="_blank" rel="noopener noreferrer">Open Booking Page ↗</a></p>';
}
$reference = (string)($_POST['reference'] ?? $_GET['reference'] ?? '');
if(defined('SITESEE_VENDOR_ADMIN_PAGE')&&SITESEE_VENDOR_ADMIN_PAGE)staff_page($body.vendor_admin_page($db));
if(defined('SITESEE_PRODUCTION_PAGE')&&SITESEE_PRODUCTION_PAGE)staff_page($body.staff_job_production_page($db,$reference));
$row = booking_get($db, $reference);
if ($row) {
    $request = booking_request($row);
    $details = $request['details'];
    $quote = $request['quote'];
    $staffClaim = booking_confirmation_get($db, $reference);
    $staffLife = booking_lifecycle_state($db, $reference);
    $staffPending = booking_lifecycle_pending($db, $reference);
    $staffChangeRequest = booking_change_request_pending($db, $reference);
    $declineAttention=false;
    if($staffClaim)foreach(booking_change_request_decline_notices($db,$reference) as $decline){
        if($decline['needs_recovery']||(!$decline['mail']&&hash_equals($decline['request']['fingerprint'],booking_lifecycle_fingerprint($db,$reference))))$declineAttention=true;
    }
    $staffMail = booking_communication_get($db, 'invitation:' . $reference);
    $calendarLabel = $staffPending ? 'Change needs verification' : ($staffLife['state'] !== 'active' ? ucfirst(str_replace('_', ' ', $staffLife['state'])) : ($staffClaim ? ($staffClaim['state'] === 'confirmed' ? 'Confirmed' : 'Needs verification') : 'Not confirmed'));
    $invitationLabel = !$staffClaim || $staffClaim['invitation_state'] === 'none' ? 'Not sent' : ($staffClaim['invitation_state'] === 'sent' ? 'Submitted' : 'Needs recovery');
    $body .= '<p><a href="staff-bookings.php">← All Requests</a></p><div class="booking-workspace"><aside class="booking-summary"><section class="card booking-summary-card"><p class="eyebrow">Request ' . staff_escape(booking_order_number($db,$reference)) . ' · ' . staff_escape(ucfirst($row['market'])) . '</p>'
        . '<h2>' . staff_escape(trim($details['street'] . ' ' . $details['unit'])) . '</h2><p class="help">' . staff_escape($details['city'] . ', ' . $details['state'] . ' ' . $details['zip']) . '</p><p class="summary-window">'.staff_escape($request['appointment']['date'].' '.$request['appointment']['time'].'–'.($request['appointment']['windowEnd'] ?? '')).' Central Time</p><details class="summary-more" open><summary>Order details &amp; status</summary><dl class="facts">'
        . '<div><dt>Customer</dt><dd>' . staff_escape($details['first'] . ' ' . $details['last']) . '<br>' . staff_escape($row['email']) . '</dd></div>'
        . '<div><dt>' . ($staffClaim && $staffClaim['state'] === 'confirmed' ? 'Confirmed' : 'Requested') . ' Arrival Window · Central Time</dt><dd>' . staff_escape($request['appointment']['date'] . ' ' . $request['appointment']['time'] . (isset($request['appointment']['windowEnd']) ? '–' . $request['appointment']['windowEnd'] : '')) . '</dd></div>'
        . '<div><dt>Quote</dt><dd>' . staff_money((int)$row['quote_cents']) . '</dd></div><div><dt>Rush Service</dt><dd>' . staff_escape(ucfirst(str_replace('_', ' ', $row['rush_status']))) . ((int)$row['rush_fee_cents'] > 0 ? ' · ' . staff_money((int)$row['rush_fee_cents']) . ' approved fee' : '') . '</dd></div></dl>'
        . '<dl class="progress"><div><dt>Deposit</dt><dd>' . ($row['deposit_paid_at'] ? 'Recorded' : 'Not recorded') . '</dd></div><div><dt>Staff Review</dt><dd>' . ($row['approved_at'] ? 'Recorded' : 'Pending') . '</dd></div><div><dt>Calendar</dt><dd>' . staff_escape($calendarLabel) . '</dd></div><div><dt>Invitation</dt><dd>' . staff_escape($invitationLabel) . '</dd></div></dl></details>';
    if ($staffChangeRequest) $body .= '<p class="note">Requested change · awaiting review<br><strong>'.staff_escape($staffChangeRequest['date'].' '.$staffChangeRequest['time'].'–'.$staffChangeRequest['window_end']).' Central Time</strong><br>The confirmed appointment above remains unchanged.</p>';
    $body .= '</section></aside><div class="booking-flow">';
    $requestHtml = '<p>Saved booking status: <strong>' . staff_escape($row['status']) . '</strong><br>Window start UTC: ' . staff_escape($row['requested_utc']) . '.</p><p>Server quote: <strong>' . staff_money((int)$row['quote_cents']) . '</strong>'
        . ($row['platform_monthly_cents'] ? '; residential platform separately ' . staff_money((int)$row['platform_monthly_cents']) . '/month if selected and published' : '')
        . '<br>Estimated on site: ' . staff_escape((string)($quote['knownMinutes'] ?? 0)) . '–' . staff_escape((string)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 0)) . ' minutes, plus any capture that requires confirmation.</p>'
        . '<p class="help">Staff only. Includes the customer’s property access instructions.</p><pre>' . staff_escape($request['salesPlain']) . '</pre>';
    $reviewHtml = '';
    $detailsHtml = staff_disclosure('request-details', 'Services & Property Access', $requestHtml);
    $jobHtml = staff_job_panel($db,$reference);
    $onsiteHtml = $jobHtml;
    if($row['approved_at'])$reviewHtml .= vendor_admin_assignment($db,$reference,false);
    $changeMail = booking_communication_get($db, 'lifecycle-' . $staffLife['revision'] . ':' . $reference);
    if ($row['reschedule_required']) {
        $reviewHtml .= '<p class="note">Rush declined. No rush fee is charged. The agent must request another standard window at least 72 hours ahead. Their existing deposit remains credited; do not create a new booking.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="reschedule_link"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><button>Create Rescheduling Link</button></form>';
    }
    if ($row['status'] === 'awaiting_deposit_test') {
        $reviewHtml .= '<p class="note">Waiting for the test deposit. Schedule review becomes available only after the verified payment notification.</p>';
    }
    $workflowStatus = null;
    $workflowHtml = $lifecycleHtml = '';
    if ($row['status'] === 'deposit_paid_test' && $row['deposit_paid_at'] && booking_test_recipient_allowed($row['email'])) {
        $workflowStatus = booking_workflow_status($db, $row);
        $needsRecovery = $staffClaim && ($staffClaim['state'] !== 'confirmed' || !in_array($staffClaim['invitation_state'], ['none', 'sent'], true));
        $needsEvidence = $staffMail ? ($staffMail['submission_state'] !== 'sent_observed' || (booking_communication_receipt_available($staffMail) && $staffMail['delivery_state'] !== 'recipient_copy_observed') || $staffMail['crm_state'] !== 'associated') : ($staffClaim && $staffClaim['invitation_state'] !== 'none');
        $blockedUnsent = $staffClaim && $staffClaim['state'] === 'confirmed' && $staffClaim['invitation_state'] === 'none' && !$workflowStatus['can_send'] && $staffLife['state'] === 'active' && (int)$staffLife['revision'] === 0 && !$staffPending;
        $workflowOpen = isset($workflowReport) || str_starts_with($postedAction, 'workflow_') || $postedAction === 'resume_invitation' || ($workflowStatus['can_resume_draft'] ?? false) || ($row['approved_at'] && (!$workflowStatus['link'] || $needsRecovery || $needsEvidence || $blockedUnsent));
        $workflowHtml = staff_disclosure('readiness', 'Booking Readiness & Recovery', ($blockedUnsent ? '<p class="note">The invitation has not been sent. A prerequisite needs attention; check readiness before sending.</p>' : '') . booking_workflow_html($row, $workflowStatus, staff_csrf(), $workflowReport ?? null), $workflowOpen);
        $lifecycleClaim=booking_confirmation_get($db,$reference);
        if(booking_lifecycle_enabled() && $lifecycleClaim && $lifecycleClaim['state']==='confirmed') {
            $changeMail = booking_communication_get($db, 'lifecycle-' . $staffLife['revision'] . ':' . $reference);
            $manageOpen = str_starts_with($postedAction, 'lifecycle_') || $declineAttention || $staffChangeRequest || $staffPending || $staffLife['diagnostic'] || in_array($staffLife['state'], ['calendar_missing', 'calendar_changed'], true) || ($changeMail && ($changeMail['submission_state'] !== 'sent_observed' || (booking_communication_receipt_available($changeMail) && $changeMail['delivery_state'] !== 'recipient_copy_observed') || $changeMail['crm_state'] !== 'associated'));
            $lifecycleHtml = staff_disclosure('manage-appointment', 'Manage Appointment', booking_lifecycle_html($db,$row,staff_csrf(),true,$lifecycleWindows??[],$lifecycleHistory??[]), (bool)$manageOpen);
        }
    }
    if ($row['status'] === 'deposit_paid_test' && !$row['approved_at'] && !$row['reschedule_required']) {
        $duration = max(15, (int)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 60));
        $reviewHtml .= '<section class="card next-step"><p class="eyebrow">NEXT STEP</p><h2>Review Paid Request</h2><p>Assign the vendor and check the requested arrival window. Saving this review does not confirm the calendar or send an invitation.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="review_paid"><input type="hidden" name="reference" value="' . staff_escape($reference) . '">'
            . vendor_review_picker($db)
            . '<label>Planned shoot duration (minutes) <input name="duration" type="number" min="15" max="1440" value="' . $duration . '" required></label>'
            . '<label><input type="checkbox" name="available" value="yes" required> I checked availability for the requested window and reviewed the scope.</label>'
            . ($row['rush_status'] === 'pending' ? '<label><input type="checkbox" name="rush_decision" value="approve" required> I approve rush service and the $59 fee on the remaining balance. No charge is made by this review.</label>' : '')
            . '<button>' . ($row['rush_status'] === 'pending' ? 'Approve Rush &amp; Save Test Review' : 'Save Test Review — No Invitation') . '</button></form>';
        if ($row['rush_status'] === 'pending') {
            $reviewHtml .= '<details' . ($postedAction === 'decline_rush' ? ' open' : '') . '><summary>Decline Rush Service</summary><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="decline_rush"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><label>Reason <input name="reason" maxlength="500" required></label><button>Decline Rush — Request Another Window</button></form></details>';
        }
        $reviewHtml .= '</section>';
    }
    $calendarHtml = '';
    if ($row['status'] === 'deposit_paid_test' && $row['approved_at'] && booking_lifecycle_state($db,$reference)['state']==='active' && (int)booking_lifecycle_state($db,$reference)['revision']===0 && !booking_lifecycle_pending($db,$reference)) {
        $confirmation = booking_confirmation_get($db, $reference);
        try { $confirmationConfig = booking_scheduling_config($confirmation); } catch (Throwable) { $confirmationConfig = null; }
        $canConfirm = $confirmationConfig && $confirmationConfig['confirmation_enabled'];
        $calendarHtml .= '<h2>Calendar Confirmation</h2><p>Calendar: ' . staff_escape($confirmationConfig ? (booking_scheduling_is_microsoft($confirmationConfig) ? 'Microsoft — sales@re.sitesee.ai' : 'Zoho — existing appointment connection') : 'Connection unavailable — confirmation blocked') . '</p><p>Vendor: ' . staff_escape((string)$row['photographer']) . '; reviewed shoot duration: ' . (int)$row['duration_minutes'] . ' minutes.</p>';
        if (!$confirmation) {
            $calendarHtml .= '<details' . (in_array($postedAction, ['check_windows', 'select_window', 'confirm_calendar'], true) ? ' open' : '') . '><summary>Choose Another Arrival Window</summary><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="check_windows"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><button>Check Available Alternatives</button></form>';
            if (isset($alternativesError)) $calendarHtml .= '<p class="note">' . staff_escape($alternativesError) . '</p>';
            if (isset($alternatives)) {
                $calendarHtml .= '<h3>Available Alternatives</h3><p>Central Time. Suggestions are checked again when selected and when confirmed. Agree a change with the customer first.</p>';
                if (!$alternatives) $calendarHtml .= '<p>No fitting windows were found in the next 14 days from the requested date.</p>';
                if ($alternatives) {
                    $options = '<option value="">Choose an arrival window</option>';
                    foreach ($alternatives as $alternative) $options .= '<option value="' . staff_escape($alternative['date'] . '|' . $alternative['time']) . '">' . staff_escape($alternative['date'] . ' ' . $alternative['time'] . '–' . $alternative['end_time']) . ' Central</option>';
                    $calendarHtml .= '<div data-window-choices><label hidden>Customer-agreed arrival window<select data-window-picker aria-label="Customer-agreed arrival window" autocomplete="off">' . $options . '</select></label>';
                }
                foreach ($alternatives as $alternative) {
                    $calendarHtml .= '<form data-window-option="' . staff_escape($alternative['date'] . '|' . $alternative['time']) . '" method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="select_window"><input type="hidden" name="reference" value="' . staff_escape($reference)
                        . '"><input type="hidden" name="date" value="' . staff_escape($alternative['date']) . '"><input type="hidden" name="time" value="' . staff_escape($alternative['time'])
                        . '"><input type="hidden" name="booking_fingerprint" value="' . hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)) . '"><p><strong>' . staff_escape($alternative['date'] . ' ' . $alternative['time'] . '–' . $alternative['end_time'])
                        . ' Central</strong></p><label><input type="checkbox" name="customer_agreed" value="yes" required> The customer agreed to this arrival window.</label><button>Use This Window — Review Again</button></form>';
                }
                if ($alternatives) $calendarHtml .= '</div>';
            }
            $calendarHtml .= '</details><p>Final confirmation checks the calendar again and blocks the full shoot duration inside the customer-agreed arrival window. It does not send an invitation.</p>';
            if ($canConfirm) {
                $calendarHtml .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="confirm_calendar"><input type="hidden" name="reference" value="' . staff_escape($reference) . '">'
                    . '<label><input type="checkbox" name="confirm_window" value="yes" required> I approve this customer-agreed arrival window, the selected vendor and the reviewed shoot duration.</label><button>Confirm Test Appointment</button></form>';
            } else $calendarHtml .= '<p class="note">Calendar confirmation is disabled pending connection verification.</p>';
        } elseif ($confirmation['state'] !== 'confirmed') {
            $calendarHtml .= '<p class="note">Calendar creation result is uncertain. Do not create another appointment. Recheck the existing result first; if it cannot be verified, inspect the assigned calendar manually.</p>'
                . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="reconcile_calendar"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><button>Recheck Calendar Result</button></form>';
        } else {
            $plannedStart = (new DateTimeImmutable('@' . $confirmation['planned_start']))->setTimezone(new DateTimeZone('America/Chicago'));
            $plannedEnd = (new DateTimeImmutable('@' . $confirmation['planned_end']))->setTimezone(new DateTimeZone('America/Chicago'));
            $calendarHtml .= '<p class="note"><strong>Test Appointment Confirmed</strong><br>Internal planned shoot: ' . staff_escape($plannedStart->format('Y-m-d g:i A') . '–' . $plannedEnd->format('g:i A')) . ' Central Time.<br>The customer invitation retains the agreed two-hour arrival window.</p>';
            if ($confirmation['invitation_state'] === 'sent') {
                $calendarHtml .= '<p>Invitation accepted by the mail server for ' . staff_escape((string)$confirmation['invitation_recipient']) . '. Mailbox receipt and calendar acceptance must be checked separately.</p>';
            } elseif ($workflowStatus['can_resume_draft'] ?? false) {
                $calendarHtml .= '<p class="note">The saved invitation draft stopped before sending. Use Repair &amp; Send Saved Invitation above to verify and submit that same draft.</p>';
            } elseif ($confirmation['invitation_state'] !== 'none') {
                $calendarHtml .= '<p class="note">Invitation delivery is uncertain. Check the recipient mailbox before any manual resend. Automatic retries are blocked.</p>';
            } elseif ($canConfirm && $confirmationConfig['invitations_enabled'] && ($workflowStatus['can_send'] ?? false)) {
                $calendarHtml .= '<h2>Send Test Invitation</h2><p>Recipient: <strong>' . staff_escape($row['email']) . '</strong>. The invitation includes the property address and arrival window. Property access codes remain private.</p>'
                    . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="send_invitation"><input type="hidden" name="reference" value="' . staff_escape($reference) . '"><label><input type="checkbox" name="verify_recipient" value="yes" required> I verified this test recipient and want to send the calendar invitation.</label><button>Send Test Calendar Invitation</button></form>';
            } else $calendarHtml .= '<p class="note">Sending is blocked by a prerequisite or an existing communication record. Use Booking Readiness &amp; Recovery above to link the contact or recover the saved attempt.</p>';
        }
    }
    if ($calendarHtml !== '') {
        $calendarHtml = staff_disclosure('calendar-confirmation', 'Calendar & Invitation', $calendarHtml, true);
    }
    $communicationsHtml = '';
    $communications = $db->prepare('SELECT * FROM booking_communications WHERE reference=? ORDER BY created_at');
    $communications->execute([$reference]);
    foreach ($communications->fetchAll() as $communication) {
        $communicationsHtml .= '<h2>Communication Status</h2><p>' . staff_escape($communication['kind'])
            . ' — From: ' . staff_escape($communication['sender']) . '<br>Microsoft 365: ' . staff_escape($communication['submission_state'])
            . '<br>Recipient mailbox evidence: ' . staff_escape($communication['delivery_state'])
            . '<br>CRM: ' . staff_escape($communication['crm_state']) . '</p>';
        if ($communication['kind'] === 'invitation') $communicationsHtml .= '<p>Use Recover Booking Status above to check the sent copy, recipient evidence and CRM history together. Receipt and calendar acceptance are separate.</p>';
    }
    if ($communicationsHtml !== '') $detailsHtml .= staff_disclosure('communication-history', 'Communication History', $communicationsHtml);
    $paymentHtml = '';
    if ($row['status'] === 'pending_review') {
        $duration = max(15, (int)($quote['knownMinutesMax'] ?? $quote['knownMinutes'] ?? 60));
        $paymentHtml .= '<h2>Approve for test checkout</h2><p>Check the photographer and availability outside this screen. The requested slot remains unconfirmed after approval.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="approve"><input type="hidden" name="reference" value="' . staff_escape($reference) . '">'
            . '<label>Photographer <input name="photographer" maxlength="120" required></label>'
            . '<label>Planned duration (minutes) <input name="duration" type="number" min="15" max="1440" value="' . $duration . '" required></label>'
            . '<label>Locked one-time price (USD) <input name="final_price" type="number" min=".50" max="1000000" step=".01" value="' . number_format((int)$row['quote_cents'] / 100, 2, '.', '') . '" required></label>'
            . '<label>Reason for a price change, if any <input name="price_reason" maxlength="500"></label>'
            . '<label>Production CRM Contact ID, if manually checked <input name="crm_contact_id" inputmode="numeric" maxlength="30"></label>'
            . '<label><input type="checkbox" name="available" value="yes" required> I checked photographer availability and reviewed the scope, duration and price.</label>'
            . '<button>Lock Price &amp; Create Test Link</button></form>';
    } else {
        $paymentHtml .= '<p>Locked price: ' . staff_money((int)$row['approved_cents'])
            . '; test deposit: ' . staff_money((int)$row['deposit_cents'])
            . '; photographer: ' . staff_escape((string)$row['photographer'])
            . '; payment: ' . staff_escape($row['checkout_state']) . '.</p><p>Approved balance after deposit, including any approved rush fee: <strong>' . staff_money(booking_remaining_cents($row)) . '</strong>. Customers can review eligible TEST balance payments in their portal. No automatic charge is made.</p>';
        if (in_array($row['status'], ['approved_test', 'awaiting_deposit_test'], true) && in_array($row['checkout_state'], ['ready', 'expired'], true)) {
            $paymentHtml .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf
                . '"><input type="hidden" name="action" value="rotate"><input type="hidden" name="reference" value="'
                . staff_escape($reference) . '"><button>Replace Lost Test Link</button></form>';
        }
    }
    if ($row['status'] === 'pending_review') $reviewHtml .= staff_disclosure('payment-details', 'Price & Payment', $paymentHtml, true);
    else $detailsHtml .= staff_disclosure('payment-details', 'Price & Payment', $paymentHtml, in_array($postedAction, ['rotate', 'approve'], true));
    $screens = [];
    if ($reviewHtml !== '') $screens['review'] = ['label'=>$row['approved_at'] && !$row['reschedule_required'] ? 'Assigned Vendor' : 'Review Request', 'html'=>$reviewHtml, 'secondary'=>(bool)$row['approved_at']];
    if ($workflowHtml !== '') $screens['readiness'] = ['label'=>'Readiness & Recovery', 'html'=>$workflowHtml, 'available'=>(bool)$row['approved_at']];
    if ($calendarHtml !== '') $screens['calendar'] = ['label'=>'Calendar & Invitation', 'html'=>$calendarHtml];
    if ($lifecycleHtml !== '') $screens['appointment'] = ['label'=>'Manage Appointment', 'html'=>$lifecycleHtml];
    if ($onsiteHtml !== '') $screens['onsite'] = ['label'=>'Onsite Closeout', 'html'=>$onsiteHtml, 'secondary'=>(bool)($staffClaim && $staffClaim['state'] === 'confirmed' && $row['approved_at'] && $staffLife['state'] === 'active' && !$staffPending && !$staffChangeRequest)];
    $screens['details'] = ['label'=>'Booking Details', 'html'=>$detailsHtml];
    // Price-link replacement remains reachable even when no review is pending.
    if ($reviewHtml === '' && in_array($row['status'], ['approved_test', 'awaiting_deposit_test'], true)) {
        $screens['review'] = ['label'=>'Review Request', 'html'=>'<p>The checkout link is ready. Wait for the verified TEST deposit before schedule review.</p>'];
    }
    if ($postedAction === 'rotate') $screens['review'] = ['label'=>'Review Request', 'html'=>'<p>The replacement link is shown above. Keep it private.</p>'];
    $next = staff_booking_next($row, $staffClaim ?: null, $staffLife, (bool)$staffPending, $staffChangeRequest ?: null, $staffMail ?: null, $changeMail ?: null, $workflowStatus, (bool)booking_job_get($db,$reference), (bool)($staffMail && booking_communication_receipt_available($staffMail)), (bool)($changeMail && booking_communication_receipt_available($changeMail)), $declineAttention);
    $phase = match ($next[0]) {'review'=>1, 'readiness'=>2, 'calendar'=>3, default=>4};
    $body .= staff_booking_screens($reference, $screens, $next, $postedAction, $phase);
    $body .= '</div></div>';
    $body .= '<script src="/portal-assets/booking-review.js?v=booking-steps-r1" defer></script>';
} else {
    $options=booking_list_options($_GET);$groups=[];
    foreach(['open','previous'] as $group)$groups[$group]=booking_order_list($db,null,$group,$options[$group.'_page'],$options[$group.'_size']);
    $body.='<p>Orders remain open until SiteSee completes Production or records their closeout.</p>';
    $body.=booking_lists_html($groups,$options,'staff-bookings.php',[],static function(array $item)use($db): string {
        $request=booking_request($item);$details=$request['details'];$change=booking_change_request_pending($db,$item['reference']);
        $status=$item['closed_at']?'Closed':($item['production_complete_at']?'Completed':($item['completed_at']?'In Production':ucfirst(str_replace('_',' ',$item['lifecycle_state']??$item['status']))));
        $property=trim(implode(' ',array_filter([$details['street']??'',$details['unit']??'',$details['city']??'',$details['state']??'',$details['zip']??''])));
        return '<article class="order"><div><p class="eyebrow">Order '.staff_escape($item['order_number']).'</p><h3>'.staff_escape($property).'</h3><p>'.staff_escape($status).'</p>'.($change?'<p class="help">Appointment change awaiting review</p>':'').'</div><a class="view-order" href="staff-bookings.php?reference='.rawurlencode($item['reference']).'" aria-label="Review order '.staff_escape($item['order_number']).'">Review Order →</a></article>';
    });
}
staff_page($body);
