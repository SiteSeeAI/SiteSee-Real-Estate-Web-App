<?php
declare(strict_types=1);
require_once __DIR__ . '/portal-access.php';
require_once __DIR__ . '/portal-phone.php';
require_once __DIR__ . '/booking-lifecycle.php';

/** Stored staff approval, not an expiring pricing browser session or an email match to a booking. */
function portal_pricing_approved(string $email, ?string $directory = null): bool
{
    $directory ??= dirname(__DIR__) . '/real-estate-pricing-pending';
    if (!is_dir($directory)) return false;
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile() || $file->isLink() || !preg_match('/^[a-f0-9]{32}\.json$/D', $file->getFilename()) || $file->getSize() > 65536) continue;
        $handle = @fopen($file->getPathname(), 'rb');
        if (!$handle) continue;
        try {
            if (!flock($handle, LOCK_SH)) continue;
            $lead = json_decode((string)stream_get_contents($handle, 65537), true);
        } finally { fclose($handle); }
        if (is_array($lead) && ($lead['status'] ?? '') === 'approved'
            && is_string($lead['approved_at'] ?? null) && $lead['approved_at'] !== ''
            && is_string($lead['email'] ?? null) && strtolower(trim($lead['email'])) === $email) return true;
    }
    return false;
}

/** All data reads start with an ownership join. Never query orders by email. */
function portal_owned_orders(PDO $db, string $accountId, int $page = 1): array
{
    $q = $db->prepare('SELECT b.reference,b.request_json,b.market,b.created_at,b.deposit_paid_at,b.approved_at,
        c.state AS calendar_state,l.state AS lifecycle_state
        FROM portal_order_owners o JOIN portal_accounts a ON a.id=o.account_id AND a.disabled=0
        JOIN bookings b ON b.reference=o.reference
        LEFT JOIN booking_confirmations c ON c.reference=b.reference
        LEFT JOIN booking_lifecycle l ON l.reference=b.reference
        WHERE o.account_id=? ORDER BY b.created_at DESC,b.reference DESC LIMIT 21 OFFSET ?');
    $q->bindValue(1, $accountId);
    $q->bindValue(2, (max(1,min(10000,$page))-1)*20, PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > 20;
    return ['orders'=>array_map('portal_order_summary',array_slice($rows,0,20)), 'has_more'=>$hasMore];
}

function portal_property(array $details): string
{
    return trim(implode(' ', array_filter(array_map(static fn($key): string =>
        is_string($details[$key] ?? null) ? $details[$key] : '', ['street','unit','city','state','zip']))));
}

function portal_order_summary(array $row): array
{
    $request = json_decode($row['request_json'], true, 32, JSON_THROW_ON_ERROR);
    $lifecycle = $row['lifecycle_state'] ?? 'active';
    $appointment = match ($lifecycle) {
        'cancelled'=>'Cancelled', 'calendar_missing','calendar_changed'=>'Staff Review Required',
        default=>($row['calendar_state'] ?? '') === 'confirmed' ? 'Confirmed' : 'Awaiting Confirmation',
    };
    return ['reference'=>$row['reference'], 'order_number'=>$row['order_number']??$row['reference'], 'property'=>portal_property($request['details'] ?? []),
        'market'=>$row['market'], 'created_at'=>$row['created_at'], 'appointment_status'=>$appointment,
        'payment_status'=>$row['deposit_paid_at'] ? 'Test Deposit Recorded' : 'Deposit Not Recorded',
        'review_status'=>$row['approved_at'] ? 'Reviewed' : 'Awaiting Staff Review'];
}

function portal_owned_order(PDO $db, string $accountId, string $reference): array|false
{
    if (!preg_match('/^[A-F0-9]{10,32}$/D', $reference)) return false;
    $q = $db->prepare('SELECT b.*,s.rush_status,s.rush_fee_cents,s.appointment_json AS schedule_appointment_json,
        c.state AS calendar_state,c.planned_start,c.planned_end,l.state AS lifecycle_state,l.actual_start
        FROM portal_order_owners o JOIN portal_accounts a ON a.id=o.account_id AND a.disabled=0
        JOIN bookings b ON b.reference=o.reference LEFT JOIN booking_scheduling s ON s.reference=b.reference
        LEFT JOIN booking_confirmations c ON c.reference=b.reference LEFT JOIN booking_lifecycle l ON l.reference=b.reference
        WHERE o.account_id=? AND o.reference=?');
    $q->execute([$accountId,$reference]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $request = booking_request($row);
    $appointment = $request['appointment'] ?? [];
    $row['order_number']=booking_order_number($db,$reference);
    $summary = portal_order_summary($row);
    $paid = $row['deposit_paid_at'] ? (int)$row['deposit_cents'] : 0;
    // Display only allowlisted fields. Tokens, Stripe IDs, CRM IDs and raw payloads stay private.
    return $summary + ['services'=>array_values(array_map(static fn(array $line): string => (string)$line['label'], $request['quote']['lines'] ?? [])),
        'package'=>(string)($request['quote']['package'] ?? ''),
        'date'=>(string)($appointment['date'] ?? ''), 'time'=>(string)($appointment['time'] ?? ''),
        'window_end'=>(string)($appointment['windowEnd'] ?? ''),
        'quote_cents'=>(int)$row['quote_cents'], 'approved_cents'=>$row['approved_at'] ? (int)$row['approved_cents'] : null,
        'expired_credit'=>$row['status']==='expired_credit_test', 'deposit_paid_cents'=>$paid, 'remaining_cents'=>$row['approved_at'] ? max(0,(int)$row['approved_cents'] + (int)$row['rush_fee_cents'] - $paid) : null,
        'rush_status'=>(string)($row['rush_status'] ?? 'not_requested'),
        'rush_fee_cents'=>(int)($row['rush_fee_cents'] ?? 0),
        'access'=>array_intersect_key($appointment,array_flip(['meetPhotographer','accessType','lockboxCode','keyLocation','specialRequests'])),
    ];
}

function portal_existing_order_proof(PDO $db, string $reference, string $token): array|false
{
    $row = booking_agent_record($db, $reference, $token);
    if ($row) return $row;
    return booking_management_auth($db, $reference, $token) ? booking_get($db, $reference) : false;
}

function portal_claim_code(string $input): array|false
{
    $input = trim($input);
    if (strlen($input) > 2048) return false;
    if (preg_match('/^([A-F0-9]{10,32})\.([a-f0-9]{64})$/D', $input, $m)) return [$m[1],$m[2]];
    $url = parse_url($input);
    if (!is_array($url) || isset($url['user']) || isset($url['pass'])) return false;
    $base = parse_url(SITESEE_REAL_ESTATE_SITE_URL);
    if (($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== $base['host']
        || ($url['port'] ?? 443) !== ($base['port'] ?? 443)) return false;
    if (($url['path'] ?? '') === '/manage-appointment.php') return portal_claim_code((string)($url['fragment'] ?? ''));
    if (($url['path'] ?? '') !== '/booking-pay.php') return false;
    parse_str($url['query'] ?? '', $q);
    if (!is_string($q['reference'] ?? null) || !is_string($q['token'] ?? null)) return false;
    return preg_match('/^[A-F0-9]{10,32}$/D',$q['reference']) && preg_match('/^[a-f0-9]{64}$/D',$q['token']) ? [$q['reference'],$q['token']] : false;
}

function portal_profile_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS portal_profiles (account_id TEXT PRIMARY KEY, first_name TEXT NOT NULL,
        last_name TEXT NOT NULL, company TEXT NOT NULL, phone TEXT NOT NULL, updated_at INTEGER NOT NULL)');
}
function portal_profile(PDO $db, string $id): array
{
    $q=$db->prepare('SELECT first_name,last_name,company,phone FROM portal_profiles p JOIN portal_accounts a ON a.id=p.account_id AND a.disabled=0 WHERE p.account_id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: ['first_name'=>'','last_name'=>'','company'=>'','phone'=>''];
}
function portal_save_profile(PDO $db, string $id, array $input): void
{
    if (!portal_active_account($db,$id)) throw new InvalidArgumentException('Sign in again.');
    $values=[];
    foreach (['first_name'=>100,'last_name'=>100,'company'=>140,'phone'=>35] as $key=>$max) {
        $value=real_estate_optional_text($input,$key,$max,'Review your contact details.');
        if($value==='')throw new InvalidArgumentException('Complete your first name, last name, company and contact phone.');
        if($key==='phone')portal_normalize_phone($value);
        $values[]=$value;
    }
    $q=$db->prepare('INSERT INTO portal_profiles VALUES (?,?,?,?,?,?) ON CONFLICT(account_id) DO UPDATE SET
        first_name=excluded.first_name,last_name=excluded.last_name,company=excluded.company,phone=excluded.phone,updated_at=excluded.updated_at');
    $q->execute([$id,...$values,time()]);
}

function portal_profile_complete(PDO $db,string $id): bool
{
    $p=portal_profile($db,$id);
    foreach(['first_name','last_name','company','phone'] as $key)if(trim($p[$key]??'')==='')return false;
    try{portal_normalize_phone($p['phone']);}catch(InvalidArgumentException){return false;}
    return true;
}
function portal_require_profile(PDO $db,string $id): array
{
    if(!portal_active_account($db,$id)||!portal_profile_complete($db,$id))throw new InvalidArgumentException('Complete all Account fields before booking a new appointment.');
    return portal_profile($db,$id);
}
