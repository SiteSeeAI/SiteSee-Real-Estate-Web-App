<?php
declare(strict_types=1);
/** CLI-only, recoverable temporary event test; never loads the booking store. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once (is_dir(dirname(__DIR__) . '/_private/server') ? dirname(__DIR__) . '/_private' : dirname(__DIR__))
    . '/server/booking-microsoft-calendar.php';

function booking_ms_save_probe(string $path, array $value): void
{
    $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $temp = $path . '.' . bin2hex(random_bytes(12));
    $old = umask(0077);
    try {
        $h = fopen($temp, 'x+b');
        booking_ms_need($h !== false, 'Probe journal could not be created.');
        try {
            booking_ms_need(fwrite($h, $bytes) === strlen($bytes) && fflush($h) && fsync($h), 'Probe journal could not be saved.');
        } finally { fclose($h); }
        booking_ms_need(rename($temp, $path), 'Probe journal could not be committed.');
        $directory = fopen(dirname($path), 'r');
        booking_ms_need($directory !== false, 'Probe journal directory is unavailable.');
        try { booking_ms_need(fsync($directory), 'Probe journal directory could not be synced.'); }
        finally { fclose($directory); }
    } finally { umask($old); if (is_file($temp)) unlink($temp); }
}

function booking_ms_verify_probe_event(array $event, array $journal): void
{
    $expected = booking_ms_probe_payload($journal['transaction_id'], $journal['start']);
    booking_ms_need(($event['id'] ?? '') === $journal['event_id'] && ($event['transactionId'] ?? '') === $journal['transaction_id']
        && ($event['subject'] ?? '') === $expected['subject'] && ($event['attendees'] ?? null) === []
        && ($event['isOrganizer'] ?? null) === true && strtolower($event['organizer']['emailAddress']['address'] ?? '') === BOOKING_MS_MAILBOX
        && ($event['type'] ?? '') === 'singleInstance' && ($event['recurrence'] ?? null) === null
        && ($event['sensitivity'] ?? '') === 'private' && ($event['showAs'] ?? '') === 'free'
        && ($event['isReminderOn'] ?? null) === false && ($event['isOnlineMeeting'] ?? null) === false
        && ($event['isCancelled'] ?? null) === false && ($event['isAllDay'] ?? null) === false
        && booking_ms_timestamp($event['start'] ?? []) === $journal['start']
        && booking_ms_timestamp($event['end'] ?? []) === $journal['start'] + 300,
        'Temporary event identity or no-attendee safeguards differ; no deletion was attempted.');
}

function booking_ms_validate_journal(array $j): void
{
    booking_ms_need(($j['schema'] ?? null) === 1 && ($j['mailbox'] ?? '') === BOOKING_MS_MAILBOX
        && ($j['calendar_id'] ?? '') === BOOKING_MS_CALENDAR && ($j['application_id'] ?? '') === BOOKING_MS_APP
        && (bool)preg_match('/^[a-f0-9]{32}$/D', (string)($j['transaction_id'] ?? ''))
        && is_int($j['start'] ?? null) && $j['start'] > 0
        && in_array($j['state'] ?? '', ['prepared','create_started','identified','delete_started','complete'], true)
        && is_string($j['event_id'] ?? null), 'Existing probe journal is invalid; preserve it for review.');
    if (in_array($j['state'], ['identified','delete_started','complete'], true)) booking_ms_event_path($j['event_id']);
    if (in_array($j['state'], ['delete_started','complete'], true)) {
        booking_ms_need(is_int($j['verified_at'] ?? null) && $j['verified_at'] > 0, 'Probe verification evidence is incomplete.');
    }
    if ($j['state'] === 'complete') booking_ms_need(is_int($j['completed_at'] ?? null), 'Probe cleanup evidence is incomplete.');
}

/** Caller holds the application deployment lock. An uncertain create is never reposted. */
function booking_ms_run_probe(callable $request, string $journalPath, ?int $now = null): array
{
    $now ??= time();
    $j = file_exists($journalPath) || is_link($journalPath) ? booking_ms_private_json($journalPath) : null;
    if ($j === null) {
        $j = ['schema'=>1, 'mailbox'=>BOOKING_MS_MAILBOX, 'calendar_id'=>BOOKING_MS_CALENDAR,
            'application_id'=>BOOKING_MS_APP, 'transaction_id'=>bin2hex(random_bytes(16)),
            'start'=>(int)(floor($now / 60) * 60 + 86400), 'state'=>'prepared', 'event_id'=>''];
        booking_ms_save_probe($journalPath, $j);
    }
    booking_ms_validate_journal($j);
    if ($j['state'] === 'complete') return $j;
    if ($j['state'] === 'create_started' && $j['event_id'] === '') {
        $matches = [];
        foreach (booking_ms_view($request, ['start'=>$j['start'] - 60, 'end'=>$j['start'] + 360], 'id,subject,transactionId') as $e) {
            if (($e['transactionId'] ?? '') === $j['transaction_id']) $matches[] = $e;
        }
        booking_ms_need(count($matches) === 1, 'Earlier creation is unresolved. No second event was created. Preserve the journal and report this output.');
        $j['event_id'] = $matches[0]['id']; $j['state'] = 'identified';
        booking_ms_save_probe($journalPath, $j);
    }
    if ($j['state'] === 'prepared') {
        $j['state'] = 'create_started'; booking_ms_save_probe($journalPath, $j);
        $r = $request('POST', booking_ms_calendar_path() . '/events', booking_ms_probe_payload($j['transaction_id'], $j['start']));
        // These explicit client errors mean no creation; a later rerun may retry the SAME transaction.
        if (in_array($r['status'] ?? 0, [400,401,403,404,422], true)) {
            $j['state'] = 'prepared'; booking_ms_save_probe($journalPath, $j);
        }
        if (is_string($r['body']['id'] ?? null) && $r['body']['id'] !== '') {
            $j['event_id'] = $r['body']['id']; $j['state'] = 'identified'; booking_ms_save_probe($journalPath, $j);
        }
        booking_ms_ok($r, 'Temporary TEST event creation', 201);
        booking_ms_need($j['event_id'] !== '', 'Creation returned no event identity; preserve the journal and rerun for reconciliation.');
    }
    $path = booking_ms_event_path($j['event_id']);
    $r = $request('GET', $path);
    if (($r['status'] ?? 0) === 404 && $j['state'] === 'delete_started') {
        // Continue only to the independent mailbox-wide absence check below.
    } else {
        $event = booking_ms_ok($r, 'Temporary TEST event readback');
        booking_ms_verify_probe_event($event, $j);
        $j['verified_at'] = $now; $j['state'] = 'delete_started'; booking_ms_save_probe($journalPath, $j);
        $r = $request('DELETE', $path);
        booking_ms_need(in_array($r['status'] ?? 0, [204,404], true), 'Temporary event cleanup is pending. Rerun the same installer to reconcile it.');
    }
    $r = $request('GET', booking_ms_mailbox_event_path($j['event_id']));
    booking_ms_need(($r['status'] ?? 0) === 404, 'Temporary event removal is not verified. The journal is retained; rerun the same installer.');
    $j['state'] = 'complete'; $j['completed_at'] = $now; booking_ms_save_probe($journalPath, $j);
    return $j;
}

function booking_ms_verify_main(array $args): void
{
    booking_ms_need(in_array($args, [['--read-only'], ['--test']], true), 'Use --read-only or --test.');
    $root = dirname(__DIR__);
    booking_ms_need(PHP_VERSION_ID >= 80200 && extension_loaded('curl'), 'PHP 8.2+ with cURL is required.');
    $config = booking_ms_private_json($root . '/microsoft-calendar.json');
    booking_ms_validate_config($config);
    $mail = booking_ms_private_json($root . '/booking-mail.json');
    booking_ms_need(($mail['stage'] ?? '') === 'test' && ($mail['sender'] ?? '') === BOOKING_MS_MAILBOX
        && ($mail['graph_credentials'] ?? '') === $config['graph_credentials'], 'Existing TEST mail configuration differs.');
    $request = booking_ms_connection($config, booking_ms_private_json($config['graph_credentials']));
    booking_ms_verify_calendar($request);
    $now = time();
    booking_ms_snapshot($request, ['start'=>$now, 'end'=>$now + 14 * 86400]);
    echo "Calendar identity and complete 14-day provider availability read: PASS\n";
    if ($args === ['--read-only']) {
        echo "Effective calendar write permission: NOT TESTED by this read-only run\n";
        return;
    }
    $j = booking_ms_run_probe($request, $root . '/microsoft-calendar-probe.json', $now);
    echo "Temporary private TEST event creation and readback: PASS\n";
    echo "Temporary TEST event removal: PASS\n";
    echo 'Verified at: ' . gmdate('Y-m-d H:i:s', $j['completed_at']) . " UTC (saved result reused on an unchanged rerun)\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $lock = null;
    try {
        // Lock the directory shared with all consolidated installers; no lock-file race.
        $lock = fopen(dirname(__DIR__), 'r');
        booking_ms_need($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Another deployment or connection test is running.');
        booking_ms_verify_main(array_slice($argv, 1));
    } catch (Throwable $e) {
        $message = $e instanceof BookingMicrosoftCalendarError ? $e->getMessage() : 'Microsoft connection check could not finish; preserve its journal and report this output.';
        fwrite(STDERR, 'STOP: ' . $message . "\n"); exit(1);
    } finally { if (is_resource($lock)) fclose($lock); }
}
