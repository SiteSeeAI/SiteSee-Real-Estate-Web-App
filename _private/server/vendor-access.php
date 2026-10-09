<?php
declare(strict_types=1);
require_once __DIR__.'/portal-phone.php';
require_once __DIR__.'/portal-session.php';

/** Vendor identities and explicit grants are independent of customer/staff accounts. */
function vendor_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_accounts (id TEXT PRIMARY KEY, name TEXT NOT NULL, phone TEXT NOT NULL UNIQUE, revision TEXT NOT NULL, enabled INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_assignments (reference TEXT PRIMARY KEY, vendor_id TEXT NOT NULL, revision TEXT NOT NULL, photographer TEXT NOT NULL, assigned_at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS vendor_jobs ON vendor_assignments(vendor_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_review_grants (reference TEXT PRIMARY KEY, assignment_revision TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, vendor_id TEXT NOT NULL, reference TEXT NOT NULL, recorded_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_challenges (id TEXT PRIMARY KEY, vendor_id TEXT NOT NULL, phone TEXT NOT NULL, revision TEXT NOT NULL, provider_sid TEXT, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_phone_attempts (ip_hash TEXT NOT NULL, phone_hash TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS vendor_phone_ip ON vendor_phone_attempts(ip_hash,created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS vendor_phone_number ON vendor_phone_attempts(phone_hash,created_at)');
}
function vendor_get(PDO $db,string $id): array|false
{
    $q=$db->prepare('SELECT * FROM vendor_accounts WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);
}
function vendor_by_phone(PDO $db,string $phone): array|false
{
    $q=$db->prepare('SELECT * FROM vendor_accounts WHERE phone=? AND enabled=1');$q->execute([$phone]);return $q->fetch(PDO::FETCH_ASSOC);
}
function vendor_audit(PDO $db,string $action,string $id,string $ref=''): void
{
    $db->prepare('INSERT INTO vendor_audit(action,vendor_id,reference,recorded_at) VALUES (?,?,?,?)')->execute([$action,$id,$ref,time()]);
}
/** Called only behind the existing authenticated manager POST boundary. */
function vendor_save(PDO $db,string $id,string $revision,string $name,string $phone,bool $enabled): string
{
    $name=trim($name);$phone=portal_normalize_phone($phone);
    if($name===''||strlen($name)>120||preg_match('/[\x00-\x1f\x7f]/',$name))throw new InvalidArgumentException('Enter the vendor’s name (up to 120 characters).');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT id FROM vendor_accounts WHERE phone=? AND id<>?');$q->execute([$phone,$id]);
        if($q->fetchColumn())throw new InvalidArgumentException('That cell phone already belongs to a vendor account. Edit that account instead.');
        if($id===''){
            $id=bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO vendor_accounts VALUES (?,?,?,?,?,?,?)')->execute([$id,$name,$phone,bin2hex(random_bytes(16)),(int)$enabled,time(),time()]);
            vendor_audit($db,'created',$id);
        }else{
            $old=vendor_get($db,$id);
            if(!$old||!hash_equals($old['revision'],$revision))throw new InvalidArgumentException('This vendor account changed. Refresh before saving.');
            $db->prepare('UPDATE vendor_accounts SET name=?,phone=?,revision=?,enabled=?,updated_at=? WHERE id=?')->execute([$name,$phone,bin2hex(random_bytes(16)),(int)$enabled,time(),$id]);
            $db->prepare("UPDATE vendor_challenges SET state='revoked' WHERE vendor_id=? AND state IN ('sending','pending','checking')")->execute([$id]);
            vendor_audit($db,$enabled?'updated':'disabled',$id);
        }
        $db->exec('COMMIT');return $id;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function vendor_assignment(PDO $db,string $ref): array|false
{
    $q=$db->prepare('SELECT * FROM vendor_assignments WHERE reference=?');$q->execute([$ref]);return $q->fetch(PDO::FETCH_ASSOC);
}
/** A review grants access only when the separate calendar confirmation succeeds. */
function vendor_review_paid(PDO $db,string $ref,string $id,string $vendorRevision,int $duration,bool $available,string $rushDecision=''): void
{
    vendor_schema($db);$vendor=vendor_get($db,$id);
    if(!$vendor||(int)$vendor['enabled']!==1||!hash_equals($vendor['revision'],$vendorRevision))throw new InvalidArgumentException('Choose an active vendor and refresh if the account changed.');
    booking_review_paid($db,$ref,$duration,$vendor['name'],$available,$rushDecision,
        static function(PDO $db,string $ref,string $name)use($id,$vendorRevision): void {
            $current=vendor_get($db,$id);
            if(!$current||(int)$current['enabled']!==1||!hash_equals($current['revision'],$vendorRevision)||$current['name']!==$name||vendor_assignment($db,$ref))throw new InvalidArgumentException('The vendor or assignment changed. Refresh before review.');
            $revision=bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO vendor_assignments VALUES(?,?,?,?,?)')->execute([$ref,$id,$revision,$name,time()]);
            $db->prepare('INSERT INTO vendor_review_grants VALUES(?,?)')->execute([$ref,$revision]);
            vendor_audit($db,'review_assigned',$id,$ref);
        });
}
function vendor_review_grant_ready(PDO $db,array $grant): bool
{
    $q=$db->prepare('SELECT assignment_revision FROM vendor_review_grants WHERE reference=?');$q->execute([$grant['reference']]);$pending=$q->fetchColumn();
    if(!$pending||!hash_equals($pending,$grant['revision']))return true;
    $claim=booking_confirmation_get($db,$grant['reference']);
    return $claim&&$claim['state']==='confirmed';
}
function vendor_assign(PDO $db,string $ref,string $id,string $revision): void
{
    $db->exec('BEGIN IMMEDIATE');
    try{
        $old=vendor_assignment($db,$ref);
        if(!hash_equals($old['revision']??'', $revision))throw new InvalidArgumentException('The vendor assignment changed. Refresh before saving.');
        if($id===''){
            $db->prepare('DELETE FROM vendor_assignments WHERE reference=?')->execute([$ref]);
            if($old)vendor_audit($db,'unassigned',$old['vendor_id'],$ref);
        }else{
            $account=vendor_get($db,$id);$row=booking_job_guard($db,$ref);
            if(!$account||(int)$account['enabled']!==1)throw new InvalidArgumentException('Choose an active vendor account.');
            if(booking_job_get($db,$ref))throw new InvalidArgumentException('This job is already complete. Its recorded vendor and commission remain fixed.');
            $db->prepare('INSERT INTO vendor_assignments VALUES (?,?,?,?,?) ON CONFLICT(reference) DO UPDATE SET vendor_id=excluded.vendor_id,revision=excluded.revision,photographer=excluded.photographer,assigned_at=excluded.assigned_at')->execute([$ref,$id,bin2hex(random_bytes(16)),$row['photographer'],time()]);
            vendor_audit($db,'assigned',$id,$ref);
        }
        $db->prepare('DELETE FROM vendor_review_grants WHERE reference=?')->execute([$ref]);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Use again under the draft/closeout write lock; a stale session or grant cannot write. */
function vendor_require_job(PDO $db,array $identity,string $ref,?string $revision=null): array
{
    $account=vendor_get($db,$identity['id']);$grant=vendor_assignment($db,$ref);
    $row=$grant?booking_get($db,$ref):false;
    if(!$account||(int)$account['enabled']!==1||!hash_equals($account['revision'],$identity['revision'])
        ||!$grant||$grant['vendor_id']!==$account['id']||!$row||$row['photographer']!==$grant['photographer']
        ||!vendor_review_grant_ready($db,$grant)
        ||($revision!==null&&!hash_equals($grant['revision'],$revision)))throw new InvalidArgumentException('This job is not available in your vendor account.');
    return ['account'=>$account,'assignment'=>$grant,'row'=>$row];
}

/** Opaque, session-bound phone verification; unknown/disabled accounts cannot send. */
function vendor_phone_request(PDO $db,string $phone,string $ip,string $secret,callable $send,?int $now=null): string
{
    $now??=time();$id=bin2hex(random_bytes(32));
    if(strlen($secret)<32)throw new RuntimeException('Rate protection unavailable.');
    try{$phone=portal_normalize_phone($phone);}catch(InvalidArgumentException){return $id;}
    $ih=hash_hmac('sha256','vendor-ip:'.$ip,$secret);$ph=hash_hmac('sha256','vendor-phone:'.$phone,$secret);$account=false;
    $db->exec('BEGIN IMMEDIATE');
    try{
        $db->prepare('DELETE FROM vendor_phone_attempts WHERE created_at<?')->execute([$now-86400]);
        $db->prepare('DELETE FROM vendor_challenges WHERE expires_at<?')->execute([$now-86400]);
        $q=$db->prepare('SELECT COUNT(*) FROM vendor_phone_attempts WHERE (ip_hash=? OR phone_hash=?) AND created_at>?');$q->execute([$ih,$ph,$now-900]);$recent=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COUNT(*) FROM vendor_phone_attempts WHERE phone_hash=?');$q->execute([$ph]);$daily=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COUNT(*) FROM vendor_phone_attempts WHERE ip_hash=?');$q->execute([$ih]);$ipDaily=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT MAX(created_at) FROM vendor_phone_attempts WHERE phone_hash=?');$q->execute([$ph]);$last=$q->fetchColumn();
        if($recent<5&&$daily<10&&$ipDaily<30&&($last===null||(int)$last<=$now-60)){
            $db->prepare('INSERT INTO vendor_phone_attempts VALUES (?,?,?)')->execute([$ih,$ph,$now]);$account=vendor_by_phone($db,$phone);
            if($account){
                $db->prepare("UPDATE vendor_challenges SET state='replaced' WHERE phone=? AND state IN ('sending','pending','checking')")->execute([$phone]);
                $db->prepare("INSERT INTO vendor_challenges(id,vendor_id,phone,revision,state,created_at,expires_at) VALUES (?,?,?,?,'sending',?,?)")->execute([hash('sha256',$id),$account['id'],$phone,$account['revision'],$now,$now+600]);
            }
        }
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    if($account){
        try{
            $sid=$send($phone);if(!is_string($sid)||!preg_match('/^VE[0-9a-fA-F]{32}$/D',$sid))throw new RuntimeException('Verification unavailable.');
            $db->prepare("UPDATE vendor_challenges SET state='pending',provider_sid=? WHERE id=? AND state='sending'")->execute([$sid,hash('sha256',$id)]);
        }catch(Throwable){$db->prepare("UPDATE vendor_challenges SET state='failed' WHERE id=? AND state='sending'")->execute([hash('sha256',$id)]);}
    }
    return $id;
}
function vendor_phone_consume(PDO $db,string $id,string $code,callable $verify,?int $now=null): array|false
{
    $fixedNow=$now;$now??=time();if(!preg_match('/^[a-f0-9]{64}$/D',$id)||!preg_match('/^[0-9]{4,10}$/D',$code))return false;
    $hash=hash('sha256',$id);$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("SELECT c.* FROM vendor_challenges c JOIN vendor_accounts a ON a.id=c.vendor_id AND a.phone=c.phone AND a.revision=c.revision AND a.enabled=1 WHERE c.id=? AND c.state='pending' AND c.attempts<5 AND c.expires_at>?");$q->execute([$hash,$now]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){$db->exec('COMMIT');return false;}
        $db->prepare("UPDATE vendor_challenges SET state='checking',attempts=attempts+1 WHERE id=?")->execute([$hash]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    try{$accepted=$verify($row['phone'],$row['provider_sid'],$code)===true;}catch(Throwable){$accepted=null;}
    $db->exec('BEGIN IMMEDIATE');
    try{
        $account=vendor_get($db,$row['vendor_id']);
        $q=$db->prepare('SELECT state,expires_at FROM vendor_challenges WHERE id=?');$q->execute([$hash]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if(!$account||(int)$account['enabled']!==1||$account['phone']!==$row['phone']||!hash_equals($account['revision'],$row['revision'])||!$current||$current['state']!=='checking'||(int)$current['expires_at']<=($fixedNow??time())){$db->exec('COMMIT');return false;}
        $state=$accepted===true?'used':($accepted===false&&((int)$row['attempts']+1)<5?'pending':'failed');
        $db->prepare('UPDATE vendor_challenges SET state=? WHERE id=?')->execute([$state,$hash]);
        if($accepted===true)vendor_audit($db,'signed_in',$account['id']);
        $db->exec('COMMIT');return $accepted===true?$account:false;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function vendor_session_start(): void
{
    if(session_status()===PHP_SESSION_ACTIVE)throw new LogicException('A different session is active.');
    $path=dirname(__DIR__).'/data/vendor-sessions';
    if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new RuntimeException('Session storage unavailable.');
    $root=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));$real=realpath($path);
    if(is_link($path)||$real===false||(fileperms($path)&0077)!==0||($root!==false&&($real===$root||str_starts_with($real,$root.'/'))))throw new RuntimeException('Session storage must be private.');
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');ini_set('session.gc_maxlifetime','43200');
    session_save_path($path);session_name('__Host-sitesee_vendor');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    if(!session_start())throw new RuntimeException('Session unavailable.');
    if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function vendor_session_clear(): void
{
    $_SESSION=[];if(!session_regenerate_id(true))throw new RuntimeException('Session reset failed.');$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function vendor_session_login(array $account,?int $now=null): void
{
    $now??=time();vendor_session_clear();$_SESSION['vendor']=['id'=>$account['id'],'revision'=>$account['revision'],'started'=>$now,'seen'=>$now];
}
function vendor_session_account(PDO $db,?int $now=null): array|false
{
    $now??=time();$s=$_SESSION['vendor']??null;if(!is_array($s))return false;
    if(!is_int($s['started']??null)||!is_int($s['seen']??null)||$s['started']>$now||$s['seen']>$now||$now-$s['started']>=43200||$now-$s['seen']>=1800
        ||!is_string($s['id']??null)||!is_string($s['revision']??null)||!($account=vendor_get($db,$s['id']))||(int)$account['enabled']!==1||!hash_equals($account['revision'],$s['revision'])){vendor_session_clear();return false;}
    $_SESSION['vendor']['seen']=$now;return $account;
}
