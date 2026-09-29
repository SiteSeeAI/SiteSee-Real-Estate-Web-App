<?php
declare(strict_types=1);
require_once __DIR__.'/portal-access.php';
function portal_phone_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS portal_phone_identities (phone TEXT PRIMARY KEY, account_id TEXT NOT NULL UNIQUE, evidence TEXT NOT NULL, revision TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_phone_challenges (id TEXT PRIMARY KEY, account_id TEXT NOT NULL, phone TEXT NOT NULL, revision TEXT NOT NULL, provider_sid TEXT, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS portal_phone_attempts (ip_hash TEXT NOT NULL, phone_hash TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_phone_ip ON portal_phone_attempts(ip_hash,created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS portal_phone_number ON portal_phone_attempts(phone_hash,created_at)');
}
function portal_normalize_phone(string $input): string
{
    if(strlen($input)>40||preg_match('/[^0-9+ ().-]/',$input))throw new InvalidArgumentException('Enter a valid cell phone number.');
    $phone=preg_replace('/[ ().-]/','',trim($input));
    if(preg_match('/^[2-9][0-9]{9}$/D',$phone))$phone='+1'.$phone;
    elseif(preg_match('/^1[2-9][0-9]{9}$/D',$phone))$phone='+'.$phone;
    if(!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone))throw new InvalidArgumentException('Use your cell number, including the country code outside the United States.');
    return $phone;
}
/** Staff-only identity enrollment. Never expose as a customer HTTP action. */
function portal_phone_enroll(PDO $db,string $email,string $phone,string $evidence,callable $approved,?int $now=null): void
{
    $email=portal_normalize_email($email);$phone=portal_normalize_phone($phone);$now??=time();
    if(strlen(trim($evidence))<12||strlen($evidence)>500)throw new InvalidArgumentException('Record staff verification evidence.');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT * FROM portal_accounts WHERE email=? COLLATE NOCASE');$q->execute([$email]);$account=$q->fetch(PDO::FETCH_ASSOC);
        if(($account&&(int)$account['disabled']!==0)||(!$account&&$approved($email)!==true))throw new InvalidArgumentException('An active approved account is required.');
        $q=$db->prepare('SELECT * FROM portal_phone_identities WHERE phone=? OR account_id=?');$q->execute([$phone,$account['id']??'']);$existing=$q->fetchAll(PDO::FETCH_ASSOC);
        if($existing){
            if(count($existing)===1&&$existing[0]['phone']===$phone&&$existing[0]['account_id']===($account['id']??'')){$db->exec('COMMIT');return;}
            throw new InvalidArgumentException('This phone or account already has a login identity. Staff recovery is required.');
        }
        if(!$account){$account=['id'=>bin2hex(random_bytes(16))];$db->prepare('INSERT INTO portal_accounts (id,email,created_at) VALUES (?,?,?)')->execute([$account['id'],$email,$now]);}
        $db->prepare('INSERT INTO portal_phone_identities VALUES (?,?,?,?,?)')->execute([$phone,$account['id'],$evidence,bin2hex(random_bytes(16)),$now]);
        $db->exec('COMMIT');
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
}
function portal_phone_identity(PDO $db,string $phone): array|false
{
    $q=$db->prepare('SELECT p.*,a.email FROM portal_phone_identities p JOIN portal_accounts a ON a.id=p.account_id AND a.disabled=0 WHERE p.phone=?');$q->execute([$phone]);return $q->fetch(PDO::FETCH_ASSOC);
}
/** Same opaque response for unknown, throttled, unavailable and sent numbers. */
function portal_phone_request(PDO $db,string $phone,string $ip,string $secret,callable $send,?int $now=null): string
{
    $now??=time();$id=bin2hex(random_bytes(32));
    if(strlen($secret)<32)throw new RuntimeException('Rate protection unavailable.');
    try{$phone=portal_normalize_phone($phone);}catch(InvalidArgumentException){return $id;}
    $ih=hash_hmac('sha256','phone-ip:'.$ip,$secret);$ph=hash_hmac('sha256','phone-number:'.$phone,$secret);$identity=false;
    $db->exec('BEGIN IMMEDIATE');
    try{
        $db->prepare('DELETE FROM portal_phone_attempts WHERE created_at<?')->execute([$now-86400]);
        $db->prepare('DELETE FROM portal_phone_challenges WHERE expires_at<?')->execute([$now-86400]);
        $q=$db->prepare('SELECT COUNT(*) FROM portal_phone_attempts WHERE (ip_hash=? OR phone_hash=?) AND created_at>?');$q->execute([$ih,$ph,$now-900]);$recent=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COUNT(*) FROM portal_phone_attempts WHERE phone_hash=?');$q->execute([$ph]);$daily=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COUNT(*) FROM portal_phone_attempts WHERE ip_hash=?');$q->execute([$ih]);$ipDaily=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT MAX(created_at) FROM portal_phone_attempts WHERE phone_hash=?');$q->execute([$ph]);$last=$q->fetchColumn();
        if($recent<5&&$daily<10&&$ipDaily<30&&($last===null||(int)$last<=$now-60)){
            $db->prepare('INSERT INTO portal_phone_attempts VALUES (?,?,?)')->execute([$ih,$ph,$now]);
            $identity=portal_phone_identity($db,$phone);
            if($identity){
                $db->prepare("UPDATE portal_phone_challenges SET state='replaced' WHERE phone=? AND state IN ('sending','pending','checking')")->execute([$phone]);
                $db->prepare("INSERT INTO portal_phone_challenges (id,account_id,phone,revision,state,created_at,expires_at) VALUES (?,?,?,?,'sending',?,?)")->execute([hash('sha256',$id),$identity['account_id'],$phone,$identity['revision'],$now,$now+600]);
            }
        }
        $db->exec('COMMIT');
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    if($identity){
        try{$sid=$send($phone);if(!is_string($sid)||!preg_match('/^VE[0-9a-fA-F]{32}$/D',$sid))throw new RuntimeException('Verification unavailable.');
            $db->prepare("UPDATE portal_phone_challenges SET state='pending',provider_sid=? WHERE id=? AND state='sending'")->execute([$sid,hash('sha256',$id)]);
        }catch(Throwable){$db->prepare("UPDATE portal_phone_challenges SET state='failed' WHERE id=? AND state='sending'")->execute([hash('sha256',$id)]);}
    }
    return $id;
}
/** Session-bound exchange. A concurrent or uncertain provider result fails closed. */
function portal_phone_consume(PDO $db,string $id,string $code,callable $verify,?int $now=null): array|false
{
    $fixedNow=$now;$now??=time();if(!preg_match('/^[a-f0-9]{64}$/D',$id)||!preg_match('/^[0-9]{4,10}$/D',$code))return false;
    $hash=hash('sha256',$id);$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("SELECT c.* FROM portal_phone_challenges c JOIN portal_phone_identities p ON p.phone=c.phone AND p.account_id=c.account_id AND p.revision=c.revision JOIN portal_accounts a ON a.id=c.account_id AND a.disabled=0 WHERE c.id=? AND c.state='pending' AND c.attempts<5 AND c.expires_at>?");$q->execute([$hash,$now]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){$db->exec('COMMIT');return false;}
        $db->prepare("UPDATE portal_phone_challenges SET state='checking',attempts=attempts+1 WHERE id=?")->execute([$hash]);$db->exec('COMMIT');
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    try{$accepted=$verify($row['phone'],$row['provider_sid'],$code)===true;}catch(Throwable){$accepted=null;}
    $db->exec('BEGIN IMMEDIATE');
    try{
        $identity=portal_phone_identity($db,$row['phone']);$account=$identity?portal_active_account($db,$identity['account_id']):false;
        $q=$db->prepare('SELECT state,expires_at FROM portal_phone_challenges WHERE id=?');$q->execute([$hash]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if(!$identity||!$account||$identity['account_id']!==$row['account_id']||$identity['revision']!==$row['revision']||$current['state']!=='checking'||(int)$current['expires_at']<=($fixedNow??time())){$db->exec('COMMIT');return false;}
        $state=$accepted===true?'used':($accepted===false&&((int)$row['attempts']+1)<5?'pending':'failed');
        $db->prepare('UPDATE portal_phone_challenges SET state=? WHERE id=?')->execute([$state,$hash]);$db->exec('COMMIT');
        return $accepted===true?$account+['login_phone'=>$row['phone'],'phone_revision'=>$row['revision']]:false;
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
}
function portal_phone_session_valid(PDO $db,array $account): bool
{
    $s=$_SESSION['portal_phone']??null;
    if(!is_array($s)||!is_string($s['phone']??null)||!is_string($s['revision']??null))return false;
    $identity=portal_phone_identity($db,$s['phone']);return $identity&&$identity['account_id']===$account['id']&&hash_equals($identity['revision'],$s['revision']);
}
