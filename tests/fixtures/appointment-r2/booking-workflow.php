<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-invitation.php';

/** Read-only provider guard: recovery must never reach an event or mail send API. */
function booking_workflow_reader(callable $client): Closure
{
    return static function (string $method, ...$args) use ($client): array {
        if ($method !== 'GET') throw new LogicException('This workflow operation permits provider reads only.');
        return $client($method, ...$args);
    };
}

function booking_workflow_row(PDO $db, string $reference): array
{
    $row = booking_get($db, $reference);
    if (!booking_test_enabled() || !$row || $row['status'] !== 'deposit_paid_test' || !$row['deposit_paid_at']
        || strcasecmp($row['email'], 'cro@sitesee.ai') !== 0) {
        throw new InvalidArgumentException('This workflow requires a recorded TEST deposit and the authorized test recipient.');
    }
    return $row;
}

function booking_workflow_fingerprint(array $row): string { return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)); }

/** Local status is deliberately distinct from a fresh provider verification. GET never calls providers. */
function booking_workflow_status(PDO $db, array $row): array
{
    $claim = booking_confirmation_get($db, $row['reference']);
    $mail = booking_communication_get($db, 'invitation:' . $row['reference']);
    $config = null; $link = null; $contact = 'Not linked — use Check Booking Readiness to find the contact.';
    try { $config = booking_scheduling_config($claim); } catch (Throwable) {}
    try {
        $crm = booking_crm_config();
        $link = booking_crm_linked($db, $row['reference'], $row['email'], $crm);
        if ($row['crm_contact_id'] !== $link['contact_id'] || !$link['verified_at']) throw new RuntimeException();
        $contact = 'Verified link saved: ' . $link['contact_id'] . '. Contact identity is checked again before sending.';
    } catch (Throwable) { $link = null; }
    $mailReady = true;
    try { booking_mail_config(true); } catch (Throwable) { $mailReady = false; }
    $canSend = $config && $config['invitations_enabled'] && $mailReady && $link && $claim && $claim['state'] === 'confirmed'
        && $claim['invitation_state'] === 'none' && !$mail
        && booking_lifecycle_state($db,$row['reference'])['state']==='active'
        && (int)booking_lifecycle_state($db,$row['reference'])['revision']===0
        && !booking_lifecycle_pending($db,$row['reference']);
    if ($canSend) {
        try { booking_confirmation_gate($config, $row); }
        catch (Throwable) { $canSend = false; }
    }
    return ['lifecycle'=>booking_lifecycle_state($db,$row['reference']), 'claim'=>$claim, 'mail'=>$mail, 'config'=>$config, 'link'=>$link, 'can_send'=>(bool)$canSend,
        'calendar'=>$config ? (booking_scheduling_is_microsoft($config) ? 'Microsoft — sales@re.sitesee.ai / Calendar' : 'Zoho — existing appointment') : 'Connection unavailable — confirmation and sending blocked',
        'contact'=>$contact, 'mail_ready'=>$mailReady];
}

/** Independent checks are collected together. No booking or provider data is changed. */
function booking_workflow_check(PDO $db, string $reference, array $deps = []): array
{
    $row = booking_workflow_row($db, $reference);
    $claim = booking_confirmation_get($db, $reference);
    $report = ['items'=>[], 'candidates'=>[], 'alternatives'=>[], 'fingerprint'=>booking_workflow_fingerprint($row)];
    $report['items']['Payment'] = 'TEST deposit recorded.';
    $report['items']['Staff review'] = $row['approved_at'] ? 'Recorded; duration ' . (int)$row['duration_minutes'] . ' minutes.' : 'Required before calendar confirmation.';
    try {
        $config = $deps['calendar_config'] ?? booking_scheduling_config($claim);
        $transport = isset($deps['calendar']) ? booking_workflow_reader($deps['calendar']) : null;
        if ($claim && $claim['state'] === 'confirmed') {
            booking_scheduling_verify_saved($config, $claim, $transport);
            $report['items']['Calendar'] = 'Saved appointment verified on its assigned calendar; no event was created.';
        } elseif ($claim) {
            $report['items']['Calendar'] = 'A previous calendar attempt needs verification. Use Recover Booking Status; do not create another event.';
        } elseif (!$row['approved_at']) {
            $report['items']['Calendar'] = 'Save the staff review to check the window against the reviewed shoot duration.';
        } else {
            booking_confirmation_gate($config, $row);
            $appointment = booking_request($row)['appointment'];
            $now = new DateTimeImmutable('now', new DateTimeZone('America/Chicago'));
            $date = max($appointment['date'], $now->format('Y-m-d'));
            $snapshot = booking_scheduling_snapshot($db, $config, booking_availability_range($date, (int)$row['duration_minutes'], 14), $transport);
            $windows = $appointment['date'] < $date ? [] : booking_available_windows($appointment['date'], $row['rush_status'] === 'approved',
                (int)$row['duration_minutes'], $snapshot, booking_scheduling_notice($db, $row), 1);
            $fits = strtotime($row['requested_utc']) > time() && count(array_filter($windows, static fn($w)=>$w['time'] === $appointment['time'])) === 1;
            $report['items']['Calendar'] = $fits ? 'Agreed window currently fits the calendar and stored reservations. Confirmation checks it again.' : 'Agreed window is blocked or has started. Review the available alternatives below with the customer.';
            $report['alternatives'] = array_slice(booking_available_windows($date, $row['rush_status'] === 'approved', (int)$row['duration_minutes'], $snapshot, $now, 14), 0, 12);
        }
    } catch (Throwable) {
        $report['items']['Calendar'] = 'CHECK REQUIRED: calendar verification did not complete. A moved, missing or conflicting event, or a connection problem, must be resolved before sending. Existing reservations remain held.';
    }
    try {
        $config = $deps['crm_config'] ?? booking_crm_config();
        $crm = booking_workflow_reader($deps['crm'] ?? booking_crm_client($config));
        booking_crm_verify_org($config, $crm);
        $q = $db->prepare('SELECT * FROM booking_contact_links WHERE reference=?'); $q->execute([$reference]); $link = $q->fetch();
        if ($link) {
            $link = booking_crm_linked($db, $reference, $row['email'], $config);
            if ($row['crm_contact_id'] !== $link['contact_id'] || !$link['verified_at']) throw new RuntimeException();
            booking_crm_verify_contact($link['contact_id'], $row['email'], $crm);
            $report['items']['CRM contact'] = 'Linked contact verified against the current CRM organization and exact booking email.';
        } else {
            $candidates = booking_crm_candidates($row['email'], $crm);
            foreach ($candidates as $candidate) {
                $report['candidates'][] = ['id'=>(string)$candidate['id'], 'email'=>$row['email'],
                    'name'=>(string)($candidate['Full_Name'] ?? trim(($candidate['First_Name'] ?? '') . ' ' . ($candidate['Last_Name'] ?? ''))),
                    'account'=>(string)($candidate['Account_Name']['name'] ?? '')];
            }
            $report['items']['CRM contact'] = $candidates ? 'Select and verify the correct existing contact below. No contact will be created.' : 'No exact-email contact found. Review the booking email and the existing Zoho contact, then check again.';
        }
    } catch (Throwable) {
        $report['items']['CRM contact'] = 'CHECK REQUIRED: CRM access or the saved contact identity could not be verified. Existing links are preserved; sending remains subject to fresh verification.';
    }
    try {
        $config = $deps['mail_config'] ?? booking_mail_config(true);
        $graph = booking_workflow_reader($deps['graph'] ?? booking_graph_client($config));
        $r = $graph('GET', '/users/sales%40re.sitesee.ai/mailFolders/sentitems/messages?%24top=1&%24select=id');
        if ($r['status'] !== 200 || !is_array($r['body']['value'] ?? null)) throw new RuntimeException();
        $report['items']['Invitation connection'] = 'Dedicated sender mailbox read verified. Sending is a separate staff action; this check sends nothing.';
    } catch (Throwable) {
        $report['items']['Invitation connection'] = 'CHECK REQUIRED: dedicated sender configuration or mailbox access could not be verified. No invitation was attempted.';
    }
    if (booking_get($db, $reference) !== $row) {
        $report['candidates'] = []; $report['alternatives'] = [];
        $report['items']['Booking changed'] = 'Reload and check again; this booking changed during verification.';
    }
    return $report;
}

/** A staff selection from the current session is mandatory, even for a single result. */
function booking_workflow_link(PDO $db, string $reference, string $id, array $ticket, array $deps = []): void
{
    $row = booking_workflow_row($db, $reference);
    if (($ticket['reference'] ?? '') !== $reference || ($ticket['expires'] ?? 0) < time()
        || !hash_equals(booking_workflow_fingerprint($row), (string)($ticket['fingerprint'] ?? ''))
        || !in_array($id, $ticket['ids'] ?? [], true)) {
        throw new InvalidArgumentException('The contact selection expired or the booking changed. Check Booking Readiness again.');
    }
    $config = $deps['crm_config'] ?? booking_crm_config();
    $crm = booking_workflow_reader($deps['crm'] ?? booking_crm_client($config));
    booking_crm_verify_org($config, $crm);
    $candidates = booking_crm_candidates($row['email'], $crm);
    if (!in_array($id, array_map(static fn($c)=>(string)$c['id'], $candidates), true)) throw new InvalidArgumentException('The selected contact no longer matches the booking email. Check again.');
    booking_crm_verify_contact($id, $row['email'], $crm);
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (booking_get($db, $reference) !== $row) throw new InvalidArgumentException('Booking changed during contact verification. Reload it.');
        if ($row['crm_contact_id'] && $row['crm_contact_id'] !== $id) throw new InvalidArgumentException('A different contact ID is already saved. Review it before changing history.');
        $q = $db->prepare('SELECT * FROM booking_contact_links WHERE reference=?'); $q->execute([$reference]); $old = $q->fetch();
        if ($old && ($old['contact_id'] !== $id || $old['org_id'] !== $config['org_id'] || strcasecmp($old['email'], $row['email']) !== 0)) {
            throw new InvalidArgumentException('A different CRM link already exists. It has been preserved.');
        }
        $q = $db->prepare('SELECT crm_contact_id,crm_org_id FROM booking_communications WHERE reference=?'); $q->execute([$reference]);
        foreach ($q->fetchAll() as $communication) {
            if (($communication['crm_contact_id'] && $communication['crm_contact_id'] !== $id)
                || ($communication['crm_org_id'] && $communication['crm_org_id'] !== $config['org_id'])) throw new InvalidArgumentException('Existing communication history has a different CRM destination. Review it first.');
        }
        $db->prepare('INSERT OR IGNORE INTO booking_contact_links(reference,org_id,contact_id,email,verified_at) VALUES(?,?,?,?,?)')
            ->execute([$reference,$config['org_id'],$id,$row['email'],gmdate('c')]);
        $db->prepare('UPDATE bookings SET crm_contact_id=? WHERE reference=?')->execute([$id,$reference]);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

/** One recovery action, with independent outcomes. Never resets an attempt or sends mail. */
function booking_workflow_recover(PDO $db, string $reference, array $deps = []): array
{
    booking_workflow_row($db, $reference);
    $report = ['items'=>[], 'history'=>[]];
    $claim = booking_confirmation_get($db, $reference);
    try {
        if (!$claim) {
            $report['items']['Calendar'] = 'No calendar attempt exists. Nothing was created.';
        } elseif (booking_lifecycle_state($db,$reference)['state'] === 'cancelled') {
            require_once __DIR__.'/booking-lifecycle.php';
            $api=booking_workflow_reader($deps['calendar'] ?? booking_lifecycle_connection($claim));
            $observed=booking_lifecycle_observe($claim,$api);
            $report['items']['Calendar']=$observed['missing']
                ? 'Cancellation verified: the saved event is absent. The cancelled booking contributes no local reservation.'
                : 'CHECK REQUIRED: an event exists for this cancelled booking. Use Reconcile Calendar to review it; do not recreate or resend.';
        } else {
            $config = $deps['calendar_config'] ?? booking_scheduling_config($claim);
            $transport = isset($deps['calendar']) ? booking_workflow_reader($deps['calendar']) : null;
            if ($claim['state'] !== 'confirmed') {
                $claim = booking_reconcile_confirmation($db, $reference, $config, $transport, $deps['lock_path'] ?? null);
            } else {
                booking_scheduling_verify_saved($config, $claim, $transport);
            }
            $report['items']['Calendar'] = 'Existing appointment verified; no new event was created.';
        }
    } catch (Throwable) {
        $report['items']['Calendar'] = 'CHECK REQUIRED: saved appointment could not be verified. Review its assigned calendar for a move, deletion or conflict. Use Manage Appointment for the current reservation status.';
    }
    $key = 'invitation:' . $reference; $mail = booking_communication_get($db, $key);
    if (!$mail) {
        $report['items']['Invitation'] = $claim && $claim['invitation_state'] !== 'none'
            ? 'CHECK REQUIRED: invitation state has no matching communication record. Inspect the existing mailbox; do not resend.'
            : 'No invitation attempt is recorded. Use the separate Send Test Calendar Invitation action when ready.';
        return $report;
    }
    $graph = null;
    try {
        $config = $deps['mail_config'] ?? booking_mail_config();
        $graph = booking_workflow_reader($deps['graph'] ?? booking_graph_client($config));
        booking_communication_reconcile($db, $key, $graph);
        $report['items']['Sent copy'] = 'Actual Microsoft 365 sent copy verified.';
    } catch (Throwable) {
        $report['items']['Sent copy'] = 'CHECK REQUIRED: sent copy could not be verified now. Preserve the saved attempt; no resend is authorized. A missing message ID or remaining draft requires mailbox review.';
    }
    $mail = booking_communication_get($db, $key);
    if ($mail['submission_state'] !== 'sent_observed') {
        $report['items']['Delivery / CRM'] = 'Waiting for verified sent-message evidence. No CRM sent history was inserted.';
        return $report;
    }
    try {
        if ($mail['delivery_state'] !== 'recipient_copy_observed') {
            if (!$graph) throw new RuntimeException();
            booking_communication_delivery($db, $key, $graph);
        }
        $report['items']['Recipient mailbox'] = 'Recipient copy observed. This does not establish calendar acceptance.';
    } catch (Throwable) {
        $report['items']['Recipient mailbox'] = 'Receipt not verified yet. Check the test mailbox; CRM recovery continues independently.';
    }
    try {
        $config = $deps['crm_config'] ?? booking_crm_config();
        $crm = $deps['crm'] ?? booking_crm_client($config);
        booking_communication_crm($db, $key, $config, $crm);
        $mail = booking_communication_get($db, $key);
        if (in_array($mail['crm_state'], ['provider_duplicate', 'existing_candidate_review'], true)) {
            // Existing Message-ID evidence can finish recovery; ambiguous candidates require staff selection.
            booking_crm_verify_org($config, $crm);
            $link = booking_crm_linked($db, $reference, $mail['recipient'], $config);
            booking_crm_verify_contact($link['contact_id'], $mail['recipient'], $crm);
            $matches = array_values(array_filter(booking_crm_history($link['contact_id'], $crm), static fn($e)=>booking_crm_history_candidate($e, $mail)));
            $exact = array_values(array_filter($matches, static fn($e)=>($e['original_message_id'] ?? '') === $mail['internet_message_id'] && !empty($e['message_id'])));
            if (count($exact) === 1) {
                booking_communication_crm($db, $key, $config, $crm, (string)$exact[0]['message_id']);
            } else {
                foreach ($matches as $match) if (!empty($match['message_id'])) $report['history'][] = [
                    'id'=>(string)$match['message_id'], 'subject'=>(string)$match['subject'], 'time'=>(string)($match['time'] ?? $match['sent_time'] ?? '')];
            }
        }
        $mail = booking_communication_get($db, $key);
        $report['items']['CRM history'] = $mail['crm_state'] === 'associated' ? 'Actual sent message associated with the linked Zoho contact.'
            : 'CHECK REQUIRED: ' . $mail['crm_state'] . '. Review any matching existing email below; do not resend the invitation.';
    } catch (Throwable) {
        $report['items']['CRM history'] = 'CHECK REQUIRED: CRM association could not complete. The sent invitation remains sent. Recover Booking Status retries only its existing association.';
    }
    return $report;
}

function booking_workflow_escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function booking_workflow_form(string $csrf, string $reference, string $action, string $label, string $fields = ''): string
{
    return '<form method="post"><input type="hidden" name="csrf" value="' . booking_workflow_escape($csrf)
        . '"><input type="hidden" name="reference" value="' . booking_workflow_escape($reference)
        . '"><input type="hidden" name="action" value="' . booking_workflow_escape($action) . '">' . $fields . '<button>' . booking_workflow_escape($label) . '</button></form>';
}

function booking_workflow_html(array $row, array $status, string $csrf, ?array $report = null): string
{
    $ref = $row['reference']; $claim = $status['claim']; $mail = $status['mail'];
    $values = ['Assigned calendar'=>$status['calendar'], 'Staff review'=>$row['approved_at'] ? 'Recorded' : 'Required',
        'CRM contact'=>$status['contact'], 'Invitation configuration'=>($status['mail_ready'] ?? false) ? 'TEST sender configured; check readiness for current mailbox access' : 'Unavailable or disabled — sending blocked',
        'Calendar appointment'=>($status['lifecycle']['state']??'active') !== 'active' ? $status['lifecycle']['state'] : ($claim ? $claim['state'] : 'Not confirmed'),
        'Invitation'=>$claim ? $claim['invitation_state'] : 'Not attempted',
        'Saved message'=>$mail ? $mail['submission_state'] : 'No communication record',
        'Recipient evidence'=>$mail ? $mail['delivery_state'] : 'Not verified',
        'Zoho email history'=>$mail ? $mail['crm_state'] : 'Not yet applicable'];
    $html = '<section aria-labelledby="workflow"><h2 id="workflow">Booking Readiness &amp; Recovery</h2><p>Saved status is shown below. Check readiness to verify connections and find the CRM contact. Recovery verifies existing work and finishes its CRM history without sending another invitation.</p><dl>';
    foreach ($values as $label=>$value) $html .= '<dt><strong>' . booking_workflow_escape($label) . '</strong></dt><dd>' . booking_workflow_escape($value) . '</dd>';
    $html .= '</dl>' . booking_workflow_form($csrf, $ref, 'workflow_check', 'Check Booking Readiness');
    if ($claim || $mail) $html .= booking_workflow_form($csrf, $ref, 'workflow_recover', 'Recover Booking Status');
    if ($report) {
        $html .= '<h3>Check Results</h3><ul>';
        foreach ($report['items'] as $name=>$message) $html .= '<li><strong>' . booking_workflow_escape($name) . ':</strong> ' . booking_workflow_escape($message) . '</li>';
        $html .= '</ul>';
        foreach ($report['candidates'] ?? [] as $candidate) {
            $fields = '<p>' . booking_workflow_escape($candidate['name'] . ' · ' . $candidate['email'] . ' · ' . $candidate['account'] . ' · Contact ' . $candidate['id'])
                . '</p><input type="hidden" name="contact_id" value="' . booking_workflow_escape($candidate['id']) . '"><label><input type="checkbox" name="contact_verified" value="yes" required> I verified that this is the correct customer record.</label>';
            $html .= booking_workflow_form($csrf, $ref, 'workflow_link', 'Link This CRM Contact', $fields);
        }
        foreach ($report['history'] ?? [] as $history) {
            $fields = '<p>' . booking_workflow_escape($history['subject'] . ' · ' . $history['time'] . ' · CRM email ' . $history['id'])
                . '</p><input type="hidden" name="message_id" value="' . booking_workflow_escape($history['id']) . '"><label><input type="checkbox" name="history_verified" value="yes" required> I checked that this is the existing invitation email in Zoho.</label>';
            $html .= booking_workflow_form($csrf, $ref, 'workflow_history', 'Link Existing CRM Email', $fields);
        }
    }
    return $html . '</section>';
}
