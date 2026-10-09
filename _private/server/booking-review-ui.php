<?php
declare(strict_types=1);

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
    if (!$row['approved_at']) return ['review', 'Review the paid request', 'Check the scope, photographer and arrival window before saving the review.'];
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
    if (str_starts_with($action, 'job_') || $action === 'vendor_assign') return 'onsite';
    if (str_starts_with($action, 'workflow_') || in_array($action, ['resume_invitation', 'review_paid'], true)) return 'readiness';
    if (in_array($action, ['confirm_calendar', 'reconcile_calendar', 'check_windows', 'select_window', 'send_invitation'], true)) return 'calendar';
    if (in_array($action, ['approve', 'rotate', 'decline_rush', 'reschedule_link'], true)) return 'review';
    return 'overview';
}

function staff_booking_screens(string $reference, array $screens, array $next, string $action): string
{
    $e = 'staff_escape';
    $url = static fn(string $step): string => 'staff-bookings.php?reference=' . rawurlencode($reference) . '&amp;step=' . rawurlencode($step);
    $selected = $action !== '' ? staff_booking_step_for_action($action) : ($_GET['step'] ?? 'overview');
    if (!is_string($selected) || ($selected !== 'overview' && (!isset($screens[$selected]) || ($action === '' && !($screens[$selected]['available'] ?? true))))) $selected = 'overview';
    [$nextStep, $title, $help] = $next;
    if ($action === 'review_paid' && !($screens['readiness']['available'] ?? false) && isset($screens['review'])) $selected = 'review';
    $html = '<nav class="booking-steps" aria-label="Booking review steps"><a href="' . $url('overview') . '"' . ($selected === 'overview' ? ' aria-current="step"' : '') . '>Overview</a>';
    foreach ($screens as $key => $screen) if ($screen['available'] ?? true) $html .= '<a href="' . $url($key) . '"' . ($selected === $key ? ' aria-current="step"' : '') . '>' . $e($screen['label']) . '</a>';
    $html .= '</nav>';
    $html .= '<section class="booking-screen" data-booking-screen="overview"' . ($selected !== 'overview' ? ' hidden' : '') . '><section class="card next-step"><p class="eyebrow">NEXT ACTION</p><h2>' . $e($title) . '</h2><p>' . $e($help) . '</p>';
    if (isset($screens[$nextStep])) $html .= '<a class="step-primary" href="' . $url($nextStep) . '">' . ($title === 'Appointment confirmed' ? 'Manage Appointment' : 'Continue') . ' →</a>';
    $html .= '</section>';
    if (isset($screens['onsite'])) $html .= '<details class="staff-fold" id="closeout-shortcut"><summary>Onsite Closeout</summary><p>Open this step when the onsite work is ready for review. Confirm the services and amount with the agent before Job Complete.</p><a href="' . $url('onsite') . '">Open Onsite Closeout →</a></details>';
    $html .= '</section>';
    foreach ($screens as $key => $screen) {
        $html .= '<section class="booking-screen" data-booking-screen="' . $e($key) . '"' . ($selected !== $key ? ' hidden' : '') . ' aria-labelledby="step-' . $e($key) . '"><p class="eyebrow">BOOKING STEP</p><h2 id="step-' . $e($key) . '">' . $e($screen['label']) . '</h2>' . $screen['html'];
        if ($nextStep !== $key && isset($screens[$nextStep])) $html .= '<p class="step-continue"><a href="' . $url($nextStep) . '">Next: ' . $e($title) . ' →</a></p>';
        $html .= '<p><a href="' . $url('overview') . '">← Booking Overview</a></p></section>';
    }
    return $html;
}
