<?php
declare(strict_types=1);
require_once __DIR__.'/portal-phone.php';

/** Runtime-only schema. The file installer never executes this module. */
function portal_email_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS portal_email_changes (
        account_id TEXT PRIMARY KEY, id_hash TEXT NOT NULL UNIQUE, old_email TEXT NOT NULL,
        new_email TEXT NOT NULL, code_hash TEXT NOT NULL, phone TEXT NOT NULL, revision TEXT NOT NULL,
        state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_email_attempts (
        account_id TEXT NOT NULL, ip_hash TEXT NOT NULL, email_hash TEXT NOT NULL, created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_email_rate_account ON portal_email_attempts(account_id,created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_email_rate_ip ON portal_email_attempts(ip_hash,created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_email_rate_target ON portal_email_attempts(email_hash,created_at)');
}

function portal_email_code_hash(string $id, string $code, string $secret): string
{
    if (strlen($secret)<32) throw new RuntimeException('Email verification unavailable.');
    return hash_hmac('sha256','portal-email-change-v1:'.$id.':'.$code,$secret);
}

/** Latest request wins across sessions. No raw code or session capability is stored. */
function portal_email_request(PDO $db, string $accountId, string $oldEmail, string $email,
    string $ip, string $secret, callable $send, ?int $now=null): string
{
    $now??=time();$email=portal_normalize_email($email);
    $id=bin2hex(random_bytes(32));$code=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
    $codeHash=portal_email_code_hash($id,$code,$secret);
    $ih=hash_hmac('sha256','email-change-ip:'.$ip,$secret);
    $eh=hash_hmac('sha256','email-change-target:'.$email,$secret);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $account=portal_active_account($db,$accountId);
        if (!$account || !portal_phone_session_valid($db,$account) || !hash_equals($account['email'],$oldEmail))
            throw new InvalidArgumentException('Your account changed. Refresh Account and try again.');
        if ($email===portal_normalize_email($account['email'])) throw new InvalidArgumentException('Enter a different email address.');
        $db->prepare('DELETE FROM portal_email_attempts WHERE created_at<=?')->execute([$now-86400]);
        $q=$db->prepare('SELECT COUNT(*) AS daily, SUM(CASE WHEN created_at>? THEN 1 ELSE 0 END) AS recent,
            MAX(CASE WHEN account_id=? THEN created_at END) AS last_request
            FROM portal_email_attempts WHERE account_id=? OR ip_hash=? OR email_hash=?');
        $q->execute([$now-900,$accountId,$accountId,$ih,$eh]);$rate=$q->fetch(PDO::FETCH_ASSOC);
        if ((int)$rate['daily']>=10 || (int)$rate['recent']>=5 || ($rate['last_request']!==null && (int)$rate['last_request']>$now-60))
            throw new InvalidArgumentException('Please wait before requesting another code. Wait at least one minute; repeated requests may take longer.');
        $db->prepare('INSERT INTO portal_email_attempts VALUES (?,?,?,?)')->execute([$accountId,$ih,$eh,$now]);
        $q=$db->prepare('SELECT 1 FROM portal_accounts WHERE email=? COLLATE NOCASE');$q->execute([$email]);
        if ($q->fetchColumn()!==false) {
            $db->exec('COMMIT');
            throw new DomainException('This email cannot be used. Choose another address or contact SiteSee.');
        }
        $phone=$_SESSION['portal_phone'];
        $db->prepare("INSERT INTO portal_email_changes VALUES (?,?,?,?,?,?,?,'sending',0,?,?)
            ON CONFLICT(account_id) DO UPDATE SET id_hash=excluded.id_hash,old_email=excluded.old_email,
            new_email=excluded.new_email,code_hash=excluded.code_hash,phone=excluded.phone,revision=excluded.revision,
            state='sending',attempts=0,created_at=excluded.created_at,expires_at=excluded.expires_at")
            ->execute([$accountId,hash('sha256',$id),$account['email'],$email,$codeHash,$phone['phone'],$phone['revision'],$now,$now+900]);
        $db->exec('COMMIT');
    } catch (DomainException $error) {
        // The rejected duplicate request still counts toward persistent rate limits.
        throw new InvalidArgumentException($error->getMessage());
    } catch (Throwable $error) { $db->exec('ROLLBACK');throw $error; }
    // An uncertain handoff never becomes verifiable. Retrying creates a new code after cooldown.
    try { $sent=$send($email,$code)===true; } catch (Throwable) { $sent=false; }
    $q=$db->prepare("UPDATE portal_email_changes SET state=? WHERE account_id=? AND id_hash=? AND state='sending'");
    $q->execute([$sent?'pending':'failed',$accountId,hash('sha256',$id)]);
    if (!$sent || $q->rowCount()!==1) throw new InvalidArgumentException('The code could not be confirmed as sent. Your email is unchanged. Wait one minute and request a new code.');
    return $id;
}

/** Only the requesting phone-authenticated session can confirm or cancel this challenge. */
function portal_email_pending(PDO $db, string $accountId, string $id, ?int $now=null): array|false
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return false;
    $account=portal_active_account($db,$accountId);
    if (!$account || !portal_phone_session_valid($db,$account)) return false;
    $phone=$_SESSION['portal_phone'];
    $q=$db->prepare("SELECT * FROM portal_email_changes WHERE account_id=? AND id_hash=? AND old_email=?
        AND phone=? AND revision=? AND state='pending' AND attempts<5 AND expires_at>?");
    $q->execute([$accountId,hash('sha256',$id),$account['email'],$phone['phone'],$phone['revision'],$now??time()]);
    return $q->fetch(PDO::FETCH_ASSOC);
}

function portal_email_confirm(PDO $db, string $accountId, string $id, string $code, string $secret, ?int $now=null): bool
{
    $now??=time();
    // Check secret before opening a transaction; malformed guesses still consume an attempt.
    $hash=portal_email_code_hash($id,$code,$secret);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row=portal_email_pending($db,$accountId,$id,$now);
        if (!$row) {$db->exec('COMMIT');return false;}
        $attempts=(int)$row['attempts']+1;
        $valid=preg_match('/^[0-9]{8}$/D',$code) && hash_equals($row['code_hash'],$hash);
        $state=$attempts>=5?'failed':'pending';
        if ($valid) {
            $q=$db->prepare('SELECT 1 FROM portal_accounts WHERE email=? COLLATE NOCASE AND id<>?');
            $q->execute([$row['new_email'],$accountId]);
            if ($q->fetchColumn()!==false) {$valid=false;$state='failed';}
        }
        if ($valid) {
            $q=$db->prepare('UPDATE portal_accounts SET email=? WHERE id=? AND email=? AND disabled=0');
            $q->execute([$row['new_email'],$accountId,$row['old_email']]);
            if ($q->rowCount()!==1) throw new RuntimeException('Account changed during verification.');
            $state='used';
        }
        $db->prepare('UPDATE portal_email_changes SET state=?,attempts=? WHERE account_id=? AND id_hash=?')
            ->execute([$state,$attempts,$accountId,hash('sha256',$id)]);
        $db->exec('COMMIT');return (bool)$valid;
    } catch (Throwable $error) {$db->exec('ROLLBACK');throw $error;}
}

function portal_email_cancel(PDO $db, string $accountId, string $id): void
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (portal_email_pending($db,$accountId,$id))
            $db->prepare("UPDATE portal_email_changes SET state='cancelled' WHERE account_id=? AND id_hash=?")
                ->execute([$accountId,hash('sha256',$id)]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {$db->exec('ROLLBACK');throw $error;}
}
