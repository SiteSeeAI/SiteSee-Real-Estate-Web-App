<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-confirmation.php';
require_once __DIR__ . '/booking-crm.php';

function booking_ical_text(string $value): string
{
    return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
}

/** Fold by UTF-8 octets, preserving characters; continuation lines include a space. */
function booking_ical_fold(string $line): string
{
    if (!preg_match('//u', $line)) throw new InvalidArgumentException('Calendar text must be valid UTF-8.');
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xc0) === 0x80) --$cut;
        $out .= substr($line, 0, $cut) . "\r\n";
        $line = ' ' . substr($line, $cut);
    }
    return $out . $line;
}

/** The customer sees only the agreed arrival window, never the private planned start. */
function booking_invitation_message(array $row, array $confirmation, string $managementLink = ''): array
{
    foreach ([$row['email'], BOOKING_MAIL_SENDER] as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n;:,]/', $email)) {
            throw new InvalidArgumentException('A valid invitation recipient and sender are required.');
        }
    }
    $request = booking_request($row);
    $appointment = $request['appointment'];
    $start = booking_calendar_date($appointment['date'])->setTime((int)substr($appointment['time'], 0, 2), 0);
    $end = $start->modify('+2 hours');
    $property = booking_confirmation_property($request['details']);
    $summary = '[TEST] SiteSee Photography Arrival Window';
    $plain = 'This is a SiteSee booking integration test, not a real photography appointment.' . "\n\n"
        . 'Your test arrival window is confirmed for ' . $start->format('F j, Y, g:i A') . '–' . $end->format('g:i A') . ' Central Time.'
        . "\nProperty: " . $property . "\nReference: " . $row['reference']
        . "\nThe calendar entry shows the two-hour arrival window. Photography may continue beyond that window."
        . "\nNo live payment has been collected."
        . ($managementLink !== '' ? "\n\nManage your appointment securely: " . $managementLink . "\nThis private link expires after seven days. Contact SiteSee for a replacement." : "\n\nPlease contact SiteSee to request a change. Your confirmed window stays in place until a change is agreed.");
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SiteSee//Arrival Window//EN', 'CALSCALE:GREGORIAN',
        'METHOD:REQUEST', 'BEGIN:VEVENT', 'UID:sitesee-arrival-test-' . $row['reference'] . '@re.sitesee.ai',
        'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime($confirmation['confirmed_at'])), 'SEQUENCE:0',
        'DTSTART:' . gmdate('Ymd\THis\Z', $start->getTimestamp()), 'DTEND:' . gmdate('Ymd\THis\Z', $end->getTimestamp()),
        'SUMMARY:' . booking_ical_text($summary), 'LOCATION:' . booking_ical_text($property),
        'DESCRIPTION:' . booking_ical_text($plain), 'ORGANIZER;CN=SiteSee:mailto:' . BOOKING_MAIL_SENDER,
        'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $row['email'],
        'STATUS:CONFIRMED', 'CLASS:PRIVATE', 'TRANSP:OPAQUE', 'END:VEVENT', 'END:VCALENDAR'];
    $ical = implode("\r\n", array_map('booking_ical_fold', $lines)) . "\r\n";
    $boundary = 'sitesee_invite_' . bin2hex(random_bytes(16));
    $headers = ['From: SiteSee Real Estate <' . BOOKING_MAIL_SENDER . '>', 'Reply-To: ' . BOOKING_MAIL_SENDER,
        'MIME-Version: 1.0', 'Content-Type: multipart/alternative; boundary="' . $boundary . '"'];
    $html = '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')) . '</p>';
    $body = '';
    foreach ([['text/plain; charset=UTF-8', $plain], ['text/html; charset=UTF-8', $html],
        ['text/calendar; charset=UTF-8; method=REQUEST; name="arrival-window.ics"', $ical]] as [$type, $part]) {
        $body .= '--' . $boundary . "\r\nContent-Type: " . $type . "\r\nContent-Transfer-Encoding: base64\r\n";
        if (str_starts_with($type, 'text/calendar')) $body .= "Content-Disposition: inline; filename=\"arrival-window.ics\"\r\n";
        $body .= "\r\n" . chunk_split(base64_encode($part), 76, "\r\n");
    }
    $body .= '--' . $boundary . "--\r\n";
    return ['to'=>$row['email'], 'subject'=>$summary . ' | ' . $row['reference'], 'headers'=>$headers,
        'body'=>$body, 'ical'=>$ical, 'plain'=>$plain, 'html'=>$html, 'from'=>BOOKING_MAIL_SENDER];
}

/** One attempted submission. Provider and CRM recovery are separate from sending. */
function booking_send_invitation_locked(PDO $db, string $reference, ?array $config = null, ?callable $send = null, ?callable $transport = null, ?array $mailDependencies = null): void
{
    booking_lifecycle_assert_active($db,$reference);
    if ((int)booking_lifecycle_state($db,$reference)['revision'] > 0) throw new InvalidArgumentException('Use the saved change notice; the original invitation must not be sent after a change.');
    $config ??= booking_scheduling_config(booking_confirmation_get($db,$reference));
    if (($config['invitations_enabled'] ?? false) !== true) throw new InvalidArgumentException('Test invitation delivery is disabled.');
    $before = booking_get($db, $reference);
    if (!$before) throw new InvalidArgumentException('Booking not found.');
    booking_confirmation_gate($config, $before);
    $saved = booking_confirmation_get($db, $reference);
    if (!$saved || $saved['state'] !== 'confirmed') throw new InvalidArgumentException('Verify the calendar appointment before sending an invitation.');
    if ($saved['invitation_state'] === 'sent') return;
    if ($saved['invitation_state'] !== 'none') throw new InvalidArgumentException('Invitation delivery was already attempted. Check the mailbox before any manual resend.');
    if ($saved['calendar_uid'] !== $config['calendar_uid']) throw new InvalidArgumentException('Calendar identity changed.');
    booking_communication_schema($db);
    $graph = null; $crm = null; $crmConfig = null;
    if ($send === null) {
        $mailConfig = $mailDependencies['config'] ?? booking_mail_config(true);
        if (strcasecmp($before['email'],$mailConfig['test_recipient_email']) !== 0) throw new InvalidArgumentException('Dedicated mail test recipient mismatch.');
        $crmConfig = $mailDependencies['crm_config'] ?? booking_crm_config(); $crm = $mailDependencies['crm'] ?? booking_crm_client($crmConfig);
        booking_crm_verify_org($crmConfig,$crm);
        $link = booking_crm_linked($db,$reference,$before['email'],$crmConfig);
        booking_crm_verify_contact($link['contact_id'],$before['email'],$crm);
        $graph = $mailDependencies['graph'] ?? booking_graph_client($mailConfig);
    }

    // A deleted or manually moved event must not produce a stale confirmation invitation.
    booking_scheduling_verify_saved($config,$saved,$transport);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_get($db, $reference);
        if (!$row) throw new InvalidArgumentException('Booking not found.');
        if ($row !== $before) throw new InvalidArgumentException('Booking changed. Reload before sending an invitation.');
        booking_confirmation_gate($config, $row);
        $confirmation = booking_confirmation_get($db, $reference);
        if (!$confirmation || $confirmation['state'] !== 'confirmed') throw new InvalidArgumentException('Verify the calendar appointment before sending an invitation.');
        if ($confirmation['calendar_uid'] !== $config['calendar_uid']) throw new InvalidArgumentException('Calendar identity changed.');
        if ($confirmation['invitation_state'] === 'sent') { $db->exec('COMMIT'); return; }
        if ($confirmation['invitation_state'] !== 'none') throw new InvalidArgumentException('Invitation delivery was already attempted. Check the mailbox before any manual resend.');
        $request = booking_request($row);
        $start = booking_calendar_date($request['appointment']['date'])->setTime((int)substr($request['appointment']['time'], 0, 2), 0);
        if ($start->getTimestamp() <= time()) throw new InvalidArgumentException('This arrival window has started. Do not send a late confirmation invitation.');
        require_once __DIR__.'/booking-lifecycle.php';
        $managementLink = booking_lifecycle_enabled() ? booking_management_issue($db,$reference) : '';
        $message = booking_invitation_message($row, $confirmation, $managementLink);
        if ($send === null) $key = booking_communication_enqueue($db,$reference,'invitation',$message,$confirmation);
        $db->prepare("UPDATE booking_confirmations SET invitation_state='sending', invitation_attempted_at=?, invitation_recipient=? WHERE reference=?")
            ->execute([gmdate('c'), $row['email'], $reference]);
        booking_schedule_event($db, $reference, 'invitation_started', ['recipient'=>$row['email']]);
        $db->exec('COMMIT');
    } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
    try {
        if ($send === null) { booking_communication_submit($db,$key,$graph); $sent = true; }
        else $sent = $send($message); // Test-only dependency injection; production has no legacy fallback.
        if ($sent !== true) throw new RuntimeException('Mail delivery was not acknowledged.');
        $db->prepare("UPDATE booking_confirmations SET invitation_state='sent', invitation_sent_at=? WHERE reference=? AND invitation_state='sending'")
            ->execute([gmdate('c'), $reference]);
        booking_schedule_event($db, $reference, 'invitation_accepted_by_mail_server', ['recipient'=>$row['email']]);
    } catch (Throwable) {
        $db->prepare("UPDATE booking_confirmations SET invitation_state='uncertain' WHERE reference=? AND invitation_state='sending'")->execute([$reference]);
        throw new BookingCalendarUnavailable('The appointment is confirmed, but invitation delivery is uncertain. Check the mailbox; automatic resend is blocked.');
    }
    if ($send === null) {
        try {
            booking_communication_reconcile($db,$key,$graph);
            booking_communication_crm($db,$key,$crmConfig,$crm);
        } catch (Throwable) {
            // Sent-copy lag and CRM failures are recovered separately. Never undo mail acceptance.
        }
    }
}

function booking_send_invitation(PDO $db, string $reference, ?array $config = null, ?callable $send = null, ?callable $transport = null, ?array $mailDependencies = null): void
{
    $lock=booking_confirmation_lock();
    try { booking_send_invitation_locked($db,$reference,$config,$send,$transport,$mailDependencies); }
    finally { fclose($lock); }
}
