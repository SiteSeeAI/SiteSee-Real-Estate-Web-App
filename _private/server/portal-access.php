<?php
declare(strict_types=1);

/**
 * Customer identity primitives. Private module only: no public route is installed.
 * Use the existing private booking PDO. Approval and mail adapters are supplied by
 * trusted server code; never accept approval, identity or owner IDs from a client.
 */
function portal_access_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS portal_accounts (
        id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        created_at INTEGER NOT NULL, disabled INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_login_challenges (
        token_hash TEXT PRIMARY KEY, email TEXT NOT NULL, expires_at INTEGER NOT NULL,
        used_at INTEGER, created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_login_attempts (
        ip_hash TEXT NOT NULL, email_hash TEXT NOT NULL, created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_attempt_ip ON portal_login_attempts(ip_hash,created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_attempt_email ON portal_login_attempts(email_hash,created_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_order_owners (
        reference TEXT PRIMARY KEY, account_id TEXT NOT NULL,
        evidence TEXT NOT NULL, created_at INTEGER NOT NULL,
        FOREIGN KEY(account_id) REFERENCES portal_accounts(id)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_orders_account ON portal_order_owners(account_id,created_at)');
}

function portal_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return $email;
}

function portal_active_account(PDO $db, string $id): array|false
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return false;
    $q = $db->prepare('SELECT id,email,created_at FROM portal_accounts WHERE id=? AND disabled=0');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}

/**
 * Always returns the same value, whether ineligible, throttled or sent. The caller
 * must use one generic response. $isApproved checks durable pricing approval;
 * existing active accounts retain access without requesting pricing again.
 * $sendLink receives a 256-bit one-time token; only the mail adapter may use it.
 * The HTTP layer must consume links via CSRF-protected POST, never on GET.
 */
function portal_request_login(PDO $db, string $email, string $ip, string $rateSecret,
    callable $isApproved, callable $sendLink, ?int $now = null): bool
{
    $now ??= time();
    if (strlen($rateSecret) < 32) throw new RuntimeException('Portal rate limiting is not configured.');
    try { $email = portal_normalize_email($email); }
    catch (InvalidArgumentException) { return true; }
    $ipHash = hash_hmac('sha256', 'portal-ip:' . $ip, $rateSecret);
    $emailHash = hash_hmac('sha256', 'portal-email:' . $email, $rateSecret);
    $token = null;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM portal_login_attempts WHERE created_at < ?')->execute([$now - 86400]);
        $q = $db->prepare('SELECT COUNT(*) FROM portal_login_attempts WHERE created_at > ? AND ip_hash=?');
        $q->execute([$now - 900, $ipHash]);
        $ipCount = (int)$q->fetchColumn();
        $q = $db->prepare('SELECT COUNT(*) FROM portal_login_attempts WHERE created_at > ? AND email_hash=?');
        $q->execute([$now - 900, $emailHash]);
        $emailCount = (int)$q->fetchColumn();
        // Count rejected addresses too, without keeping their raw email or IP.
        if ($ipCount < 20 && $emailCount < 5) {
            $db->prepare('INSERT INTO portal_login_attempts VALUES (?,?,?)')->execute([$ipHash,$emailHash,$now]);
            $q = $db->prepare('SELECT id,disabled FROM portal_accounts WHERE email=? COLLATE NOCASE');
            $q->execute([$email]);
            $account = $q->fetch(PDO::FETCH_ASSOC);
            $allowed = $account ? (int)$account['disabled'] === 0 : $isApproved($email) === true;
            if ($allowed) {
                $token = bin2hex(random_bytes(32));
                $db->prepare('INSERT INTO portal_login_challenges VALUES (?,?,?,NULL,?)')
                    ->execute([hash('sha256',$token),$email,$now + 900,$now]);
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
    if ($token !== null) {
        try { $sent = $sendLink($email,$token) === true; }
        catch (Throwable) { $sent = false; }
        if (!$sent) {
            $db->prepare('DELETE FROM portal_login_challenges WHERE token_hash=? AND used_at IS NULL')
                ->execute([hash('sha256',$token)]);
        }
    }
    return true;
}

/** Atomic one-use exchange; caller regenerates the PHP session after success. */
function portal_consume_login(PDO $db, string $token, ?int $now = null): array|false
{
    $now ??= time();
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q = $db->prepare('SELECT email FROM portal_login_challenges WHERE token_hash=? AND used_at IS NULL AND expires_at>?');
        $q->execute([hash('sha256',$token),$now]);
        $email = $q->fetchColumn();
        if ($email === false) { $db->exec('COMMIT'); return false; }
        $q = $db->prepare('SELECT id,email,created_at,disabled FROM portal_accounts WHERE email=? COLLATE NOCASE');
        $q->execute([$email]);
        $account = $q->fetch(PDO::FETCH_ASSOC);
        if ($account && (int)$account['disabled'] !== 0) { $db->exec('COMMIT'); return false; }
        if (!$account) {
            $account = ['id'=>bin2hex(random_bytes(16)),'email'=>$email,'created_at'=>$now];
            $db->prepare('INSERT INTO portal_accounts (id,email,created_at) VALUES (?,?,?)')
                ->execute([$account['id'],$email,$now]);
        }
        $db->prepare('UPDATE portal_login_challenges SET used_at=? WHERE token_hash=? AND used_at IS NULL')
            ->execute([$now,hash('sha256',$token)]);
        $db->exec('COMMIT');
        unset($account['disabled']);
        return $account;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

/**
 * Historical claim: both authenticated account email and existing booking-token
 * authority must match. $verifyExistingToken is the trusted adapter to the
 * installed payment or management verifier, returning its verified booking row.
 * No booking row, token, appointment, payment or message is changed here.
 */
function portal_claim_existing_order(PDO $db, string $accountId, string $reference,
    string $token, callable $verifyExistingToken, ?int $now = null): bool
{
    if (!preg_match('/^[A-F0-9]{10,32}$/D',$reference) || strlen($token) > 256) return false;
    $account = portal_active_account($db,$accountId);
    if (!$account) return false;
    $booking = $verifyExistingToken($reference,$token);
    if (!is_array($booking) || ($booking['reference'] ?? '') !== $reference) return false;
    try { $bookingEmail = portal_normalize_email((string)($booking['email'] ?? '')); }
    catch (InvalidArgumentException) { return false; }
    if (!hash_equals($account['email'],$bookingEmail)) return false;
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Email activation and historical claiming serialize on the same write lock.
        $current=portal_active_account($db,$accountId);
        if (!$current || !hash_equals($current['email'],$bookingEmail)) { $db->exec('COMMIT'); return false; }
        $q = $db->prepare('SELECT account_id FROM portal_order_owners WHERE reference=?');
        $q->execute([$reference]);
        $owner = $q->fetchColumn();
        if ($owner !== false) { $db->exec('COMMIT'); return hash_equals($owner,$accountId); }
        $db->prepare('INSERT INTO portal_order_owners VALUES (?,?,?,?)')
            ->execute([$reference,$accountId,isset($_SESSION['portal_phone'])?'staff-bound-phone-and-existing-token':'verified-email-and-existing-token',$now ?? time()]);
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

/** Authorize before reading any booking, CRM, billing or document data. */
function portal_owns_order(PDO $db, string $accountId, string $reference): bool
{
    $q = $db->prepare('SELECT 1 FROM portal_order_owners o JOIN portal_accounts a ON a.id=o.account_id
        WHERE o.reference=? AND o.account_id=? AND a.disabled=0');
    $q->execute([$reference,$accountId]);
    return $q->fetchColumn() !== false;
}
