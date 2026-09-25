<?php
declare(strict_types=1);

/** Additive scheduling ledger; existing bookings need no column migration. */
function booking_schedule_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS booking_scheduling (
        reference TEXT PRIMARY KEY,
        rush_status TEXT NOT NULL DEFAULT 'not_requested' CHECK(rush_status IN ('not_requested','pending','approved','declined')),
        rush_fee_cents INTEGER NOT NULL DEFAULT 0 CHECK(rush_fee_cents IN (0,5900)),
        reschedule_required INTEGER NOT NULL DEFAULT 0,
        appointment_json TEXT,
        decision_reason TEXT,
        CHECK(rush_fee_cents=0 OR rush_status='approved')
    )");
    $db->exec('CREATE TABLE IF NOT EXISTS booking_schedule_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL,
        action TEXT NOT NULL, recorded_at TEXT NOT NULL, detail_json TEXT NOT NULL
    )');
}

function booking_schedule_event(PDO $db, string $reference, string $action, array $details = []): void
{
    $db->prepare('INSERT INTO booking_schedule_events (reference,action,recorded_at,detail_json) VALUES (?,?,?,?)')
        ->execute([$reference, $action, gmdate('c'), json_encode($details, JSON_THROW_ON_ERROR)]);
}

function booking_request(array $row): array
{
    $request = json_decode($row['request_json'], true, 32, JSON_THROW_ON_ERROR);
    if (!empty($row['schedule_appointment_json'])) {
        $request['appointment'] = json_decode($row['schedule_appointment_json'], true, 16, JSON_THROW_ON_ERROR);
        $request['plain'] = real_estate_quote_body($request['quote'], $request['details'], $request['appointment']);
        $request['salesPlain'] = real_estate_quote_body($request['quote'], $request['details'], $request['appointment'], true);
    }
    return $request;
}

function booking_remaining_cents(array $row): int
{
    return (int)$row['approved_cents'] - (int)$row['deposit_cents'] + (int)($row['rush_fee_cents'] ?? 0);
}

function booking_review_paid(PDO $db, string $reference, int $duration, string $photographer, bool $available, string $rushDecision = ''): void
{
    $photographer = trim($photographer);
    if (!booking_test_enabled() || !$available || $photographer === '' || strlen($photographer) > 120 || $duration < 15 || $duration > 1440) {
        throw new InvalidArgumentException('Review the photographer, duration and availability.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_get($db, $reference);
        if (!$row || $row['status'] !== 'deposit_paid_test' || $row['approved_at'] || $row['reschedule_required']) {
            throw new InvalidArgumentException('Only an unreviewed paid request with an agreed requested window can be reviewed.');
        }
        if ($row['rush_status'] === 'pending') {
            if ($rushDecision !== 'approve') throw new InvalidArgumentException('Explicitly approve or decline this rush request.');
            $db->prepare("UPDATE booking_scheduling SET rush_status='approved', rush_fee_cents=5900 WHERE reference=? AND rush_status='pending'")
                ->execute([$reference]);
            booking_schedule_event($db, $reference, 'rush_approved', ['fee_cents'=>5900, 'collection'=>'remaining_balance']);
        } elseif ($rushDecision !== '') {
            throw new InvalidArgumentException('This request does not have a pending rush decision.');
        }
        $db->prepare('UPDATE bookings SET approved_at=?, photographer=?, duration_minutes=?, availability_checked_at=? WHERE reference=?')
            ->execute([gmdate('c'), $photographer, $duration, gmdate('c'), $reference]);
        booking_schedule_event($db, $reference, 'staff_reviewed', ['photographer'=>$photographer, 'duration_minutes'=>$duration]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function booking_decline_rush(PDO $db, string $reference, string $reason): void
{
    $reason = trim($reason);
    if (!booking_test_enabled() || $reason === '' || strlen($reason) > 500) throw new InvalidArgumentException('Enter a short reason for declining rush service.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_get($db, $reference);
        if (!$row || $row['status'] !== 'deposit_paid_test' || $row['approved_at'] || $row['rush_status'] !== 'pending') {
            throw new InvalidArgumentException('Only a paid, pending rush request can be declined.');
        }
        $db->prepare("UPDATE booking_scheduling SET rush_status='declined', rush_fee_cents=0, reschedule_required=1, decision_reason=? WHERE reference=?")
            ->execute([$reason, $reference]);
        booking_schedule_event($db, $reference, 'rush_declined', ['reason'=>$reason, 'fee_cents'=>0]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

/** The same paid booking is reused; no new deposit or change to original request_json. */
function booking_request_new_window(PDO $db, string $reference, string $token, array $source, ?DateTimeImmutable $now = null): void
{
    if (!booking_test_enabled()) throw new RuntimeException('Test bookings are disabled.');
    $window = real_estate_validate_appointment($source, $now);
    $window = real_estate_arrival_window($window);
    $window = real_estate_validate_lead_time($window, ['rushRequested'=>false], $now);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = booking_agent_record($db, $reference, $token);
        if (!$row || $row['status'] !== 'deposit_paid_test' || !$row['reschedule_required'] || $row['rush_status'] !== 'declined') {
            throw new InvalidArgumentException('This booking is not waiting for a replacement window.');
        }
        $request = booking_request($row);
        $appointment = array_replace($request['appointment'], $window);
        $db->prepare("UPDATE booking_scheduling SET rush_status='not_requested', rush_fee_cents=0, reschedule_required=0, appointment_json=? WHERE reference=?")
            ->execute([json_encode($appointment, JSON_THROW_ON_ERROR), $reference]);
        $utc = (new DateTimeImmutable($window['date'] . ' ' . $window['time'], new DateTimeZone('America/Chicago')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $db->prepare('UPDATE bookings SET requested_utc=? WHERE reference=?')->execute([$utc, $reference]);
        booking_schedule_event($db, $reference, 'replacement_window_requested', $window);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

/** Staff can recover a lost rescheduling link without creating another payment. */
function booking_reschedule_link(PDO $db, string $reference): string
{
    if (!booking_test_enabled()) throw new RuntimeException('Test bookings are disabled.');
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("UPDATE bookings SET agent_token_hash=?, agent_token_expires=?
        WHERE reference=? AND status='deposit_paid_test' AND EXISTS (
            SELECT 1 FROM booking_scheduling s WHERE s.reference=bookings.reference AND s.reschedule_required=1)");
    $stmt->execute([hash('sha256', $token), time() + 7 * 86400, $reference]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('This request is not waiting for a new window.');
    return $token;
}
