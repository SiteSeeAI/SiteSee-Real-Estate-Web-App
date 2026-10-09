<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/views/application-shell.php';

/** Select the next screen from recorded state, without querying providers or changing data. */
function staff_booking_next(array $row, ?array $claim, array $life, bool $pending, ?array $change, ?array $mail, ?array $changeMail, ?array $workflow, bool $job, bool $invitationReceiptAvailable = false, bool $changeReceiptAvailable = false, bool $declineNoticeNeedsRecovery = false): array
{
    if ($pending) return ['appointment', 'Verify the saved appointment change', 'Check the existing calendar result before another change.'];
    if ($change) return ['appointment', 'Review the customer’s requested window', 'Compare the requested window with the confirmed appointment, then approve or decline.'];
    if ($declineNoticeNeedsRecovery) return ['appointment', 'Review the customer decline notice', 'Send a saved unsent notice or verify its existing result. The appointment remains unchanged.'];
    if ($changeMail && ($changeMail['submission_state'] !== 'sent_observed' || ($changeMail['crm_state'] ?? 'pending') !== 'associated' || ($changeReceiptAvailable && ($changeMail['delivery_state'] ?? 'unverified') !== 'recipient_copy_observed'))) return ['appointment', 'Verify the previous change notice', 'Check the saved notice before another appointment change. Do not resend it.'];
    if ($life['state'] !== 'active' && $claim) return ['appointment', 'Review the appointment status', 'Check the saved calendar status and any notice that needs attention.'];
    if ($row['reschedule_required']) return ['review', 'Request another arrival window', 'Keep the recorded deposit and provide the customer with a rescheduling link.'];
    if (!$row['deposit_paid_at']) return ['review', 'Review the request and deposit', 'The appointment can be reviewed after the TEST deposit is recorded.'];
    if (!$row['approved_at']) return ['review', 'Review the paid request', 'Check the scope, vendor and arrival window before saving the review.'];
    if ($workflow && !$workflow['link']) return ['readiness', 'Check readiness and the CRM contact', 'Verify the connections and link the customer before calendar confirmation.'];
    if (!$claim) return ['calendar', 'Confirm the appointment', 'Check the agreed window and reserve the appointment. Sending the invitation is a separate step.'];
    if ($claim['state'] !== 'confirmed') return ['calendar', 'Verify the saved calendar result', 'Recover the existing result instead of creating another appointment.'];
    if ($workflow['can_resume_draft'] ?? false) return ['readiness', 'Review the saved invitation draft', 'Verify and submit the existing draft only after an explicit staff decision.'];
    if ($claim['invitation_state'] === 'none') return ($workflow['can_send'] ?? false)
        ? ['calendar', 'Review and send the invitation', 'Confirm the recipient before sending the saved calendar invitation.']
        : ['readiness', 'Check invitation readiness', 'Review the prerequisite that is blocking the invitation.'];
    if ($claim['invitation_state'] !== 'sent' || !$mail || $mail['submission_state'] !== 'sent_observed' || $mail['crm_state'] !== 'associated' || ($invitationReceiptAvailable && ($mail['delivery_state'] ?? 'unverified') !== 'recipient_copy_observed')) return ['readiness', 'Verify the saved invitation', 'Check the sent copy and CRM history without sending another invitation.'];
    if ($job) return ['onsite', 'Review the completed onsite job', 'Check final payment and continue to Production.'];
    return ['appointment', 'Appointment confirmed', 'The saved appointment is confirmed. Open appointment management only if a change or status check is needed.'];
}

function staff_booking_step_for_action(string $action): string
{
    if (str_starts_with($action, 'lifecycle_')) return 'appointment';
    if (str_starts_with($action, 'job_')) return 'onsite';
    if (str_starts_with($action, 'workflow_') || in_array($action, ['resume_invitation', 'review_paid'], true)) return 'readiness';
    if (in_array($action, ['confirm_calendar', 'reconcile_calendar', 'check_windows', 'select_window', 'send_invitation'], true)) return 'calendar';
    if (in_array($action, ['approve', 'rotate', 'decline_rush', 'reschedule_link', 'vendor_assign'], true)) return 'review';
    return 'overview';
}

function staff_booking_primary_actions(string $title): ?array
{
    return match ($title) {
        'Verify the saved appointment change' => ['lifecycle_sync', 'lifecycle_resolve_unchanged'],
        'Review the customer’s requested window' => ['lifecycle_approve_request', 'lifecycle_reject_request'],
        'Review the customer decline notice' => ['lifecycle_decline_notice', 'lifecycle_recover_decline_notice'],
        'Verify the previous change notice' => ['lifecycle_notice', 'lifecycle_recover_notice'],
        'Review the appointment status' => ['lifecycle_sync', 'lifecycle_notice', 'lifecycle_recover_notice', 'lifecycle_adopt', 'lifecycle_legacy_deleted', 'lifecycle_close_order'],
        'Review the saved invitation draft' => ['resume_invitation'],
        'Verify the saved invitation' => ['workflow_recover'],
        'Check invitation readiness', 'Check readiness and the CRM contact' => ['workflow_check', 'workflow_link'],
        'Verify the saved calendar result' => ['reconcile_calendar'],
        'Confirm the appointment' => ['confirm_calendar'],
        'Review and send the invitation' => ['send_invitation'],
        default => null,
    };
}

function staff_booking_screens(string $reference, array $screens, array $next, string $action, int $phase = 0): string
{
    $e = 'staff_escape';
    $url = static fn(string $step): string => 'staff-bookings.php?reference=' . rawurlencode($reference) . '&amp;step=' . rawurlencode($step);
    [$nextStep, $title, $help] = $next;
    $identity = [];
    if ($action !== '') foreach (['request_id','message_id','contact_id','notice_revision'] as $name) {
        $value = $_POST[$name] ?? null;
        if ((is_string($value) || is_int($value)) && (string)$value !== '' && strlen((string)$value) <= 1024) $identity[$name] = (string)$value;
    }
    $phase = $phase ?: match ($nextStep) {'review'=>1, 'readiness'=>2, 'calendar'=>3, 'onsite'=>4, default=>4};
    $allowed = array_fill_keys(['overview', 'details', $nextStep], true);
    foreach ($screens as $key => $screen) if ($screen['secondary'] ?? false) $allowed[$key] = true;
    // Saved verification remains reachable; future operational screens do not.
    if ($phase >= 3) $allowed['readiness'] = true;
    if ($phase === 4 && $title === 'Appointment confirmed') $allowed['onsite'] = true;
    if ($phase === 4 && $nextStep === 'onsite') $allowed['appointment'] = true;
    $selected = $action !== '' ? staff_booking_step_for_action($action) : ($_GET['step'] ?? 'overview');
    if (!is_string($selected) || ($selected !== 'overview' && (!isset($screens[$selected]) || ($action === '' && (!isset($allowed[$selected]) || !($screens[$selected]['available'] ?? true)))))) $selected = 'overview';
    if ($action === 'review_paid' && !($screens['readiness']['available'] ?? false) && isset($screens['review'])) $selected = 'review';
    $html = site_workflow_progress(['Review request', 'Prepare booking', 'Confirm appointment', 'Onsite closeout'], $phase);
    $html .= '<nav class="booking-steps" aria-label="Booking context"><a href="' . $url('overview') . '"' . ($selected === 'overview' ? ' aria-current="page"' : '') . '>Overview</a>';
    if (isset($screens['details'])) $html .= '<a href="'.$url('details').'"'.($selected === 'details' ? ' aria-current="page"' : '').'>Booking Details</a>';
    $html .= '</nav>';
    $html .= '<section class="booking-screen" data-booking-screen="overview"' . ($selected !== 'overview' ? ' hidden' : '') . '><section class="card next-step"><p class="eyebrow">NEXT ACTION</p><h2>' . $e($title) . '</h2><p>' . $e($help) . '</p>';
    if (isset($screens[$nextStep]) && ($screens[$nextStep]['available'] ?? true)) $html .= '<a class="step-primary" href="' . $url($nextStep) . '">' . ($title === 'Appointment confirmed' ? 'Manage Appointment' : 'Continue') . ' →</a>';
    $html .= '</section>';
    if (isset($screens['onsite']) && isset($allowed['onsite'])) $html .= '<details class="staff-fold" id="closeout-shortcut"><summary>Onsite Closeout</summary><p>Open this step when the onsite work is ready for review. Confirm the services and amount with the agent before Job Complete.</p><a href="' . $url('onsite') . '">Open Onsite Closeout →</a></details>';
    $html .= '</section>';
    foreach ($screens as $key => $screen) {
        $focused = in_array($key, ['review', 'readiness', 'calendar', 'appointment'], true);
        $html .= '<section class="booking-screen" data-booking-screen="' . $e($key) . '"' . ($selected !== $key ? ' hidden' : '') . ' aria-labelledby="step-' . $e($key) . '"><p class="eyebrow">'.($key === 'details' ? 'ORDER INFORMATION' : 'STEP '.$phase.' OF 4').'</p><h2 id="step-' . $e($key) . '">' . $e($screen['label']) . '</h2>';
        $primary = $key === $nextStep ? staff_booking_primary_actions($title) : null;
        $html .= $focused ? '<div data-focus-actions data-focus-kind="'.$e($key).'"'.($primary !== null ? ' data-focus-primary="'.$e(json_encode($primary, JSON_THROW_ON_ERROR)).'"' : '').' data-current-action="'.$e($action).'" data-current-identity="'.$e(json_encode($identity ?: new stdClass(), JSON_THROW_ON_ERROR)).'">'.($key === $nextStep ? '<p class="flow-guidance">'.$e($help).'</p>' : '').$screen['html'].'</div>' : $screen['html'];
        if ($key === 'details' && ($screens['review']['secondary'] ?? false)) $html .= '<p><a href="'.$url('review').'">Manage Assigned Vendor →</a></p>';
        if ($nextStep !== $key && isset($screens[$nextStep]) && ($screens[$nextStep]['available'] ?? true)) $html .= '<p class="step-continue"><a href="' . $url($nextStep) . '">Next: ' . $e($title) . ' →</a></p>';
        $html .= '<p><a href="' . $url('overview') . '">← Booking Overview</a></p></section>';
    }
    return $html;
}
