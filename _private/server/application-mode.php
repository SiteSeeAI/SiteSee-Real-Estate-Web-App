<?php
declare(strict_types=1);

/** Installation is TEST by default. Only a private activation record enables LIVE. */
function site_runtime_control(): array
{
    static $saved=null;
    if ($saved!==null) return $saved;
    $path = dirname(__DIR__).(site_runtime_preparing()?'/application-live-ready.json':'/application-live.json');
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) {
        $journal=dirname(__DIR__).'/live-activation-journal.json';
        if (file_exists($journal)||is_link($journal)) {
            $prior=site_runtime_private_json($journal);
            if (($prior['state']??'')!=='prepared') throw new RuntimeException('LIVE activation requires repair forward.');
        }
        if(site_runtime_preparing())throw new RuntimeException('LIVE preparation record is missing.');
        return $saved=['stage'=>'TEST'];
    }
    $record = site_runtime_private_json($path);
    if (($record['format']??null)!==1 || ($record['stage']??'')!=='LIVE'
        || ($record['enabled']??null)!==true
        || !preg_match('/^[a-f0-9]{32}$/D',(string)($record['realm']??''))
        || !preg_match('/^acct_[A-Za-z0-9]{8,64}$/D',(string)($record['stripe_account']??''))) {
        throw new RuntimeException('Application activation record needs review.');
    }
    if (!site_runtime_preparing()) {
        $journal=site_runtime_private_json(dirname(__DIR__).'/live-activation-journal.json');
        if (($journal['state']??'')!=='active' || ($journal['realm']??'')!==$record['realm']
            || ($journal['stripe_account']??'')!==$record['stripe_account']
            || !preg_match('/^unified-test-[a-f0-9]{12}$/D',(string)($record['initial_code_release']??''))
            || ($journal['initial_code_release']??'')!==$record['initial_code_release']) {
            throw new RuntimeException('LIVE activation requires completion or repair forward.');
        }
    }
    return $saved=$record;
}

function site_runtime_private_json(string $path): array
{
    $root=dirname(__DIR__);$lock=fopen($root,'r');$file=null;
    if (!$lock || !flock($lock,LOCK_SH|LOCK_NB)) {
        if ($lock) fclose($lock);
        throw new RuntimeException('Runtime configuration update is in progress.');
    }
    $identity=static fn(array $s):array=>array_intersect_key($s,array_flip(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']));
    try {
        if (!str_starts_with($path,$root.'/')) throw new RuntimeException('Runtime configuration left its application.');
        $parents=[];$current=$root;
        foreach (explode('/',substr(dirname($path),strlen($root))) as $part) {
            if ($part==='') continue;
            if (in_array($part,['.','..'],true)) throw new RuntimeException('Invalid runtime configuration path.');
            $current.='/'.$part;clearstatcache(true,$current);$parent=@lstat($current);
            if (!$parent || ($parent['mode']&0170000)!==0040000 || ($parent['mode']&0077)!==0
                || $parent['uid']!==fileowner($root)) throw new RuntimeException('Runtime configuration parent differs.');
            $parents[$current]=$identity($parent);
        }
        clearstatcache(true,$path);$s=@lstat($path);
        if (!$s || ($s['mode']&0170000)!==0100000 || ($s['mode']&0077)!==0
            || $s['nlink']!==1 || $s['uid']!==fileowner($root) || $s['size']>65536) {
            throw new RuntimeException('Private runtime configuration is unavailable or unsafe.');
        }
        $file=@fopen($path,'rb');
        if (!$file || $identity(fstat($file))!==$identity($s)) throw new RuntimeException('Runtime configuration changed before reading.');
        foreach ($parents as $parent=>$before) {
            clearstatcache(true,$parent);$now=@lstat($parent);
            if (!$now || $identity($now)!==$before) throw new RuntimeException('Runtime configuration parent changed.');
        }
        $raw=stream_get_contents($file,65537);
        clearstatcache(true,$path);$after=@lstat($path);
        if (!is_string($raw) || strlen($raw)!==$s['size'] || !$after
            || $identity(fstat($file))!==$identity($s) || $identity($after)!==$identity($s)) {
            throw new RuntimeException('Runtime configuration changed during reading.');
        }
        foreach ($parents as $parent=>$before) {
            clearstatcache(true,$parent);$now=@lstat($parent);
            if (!$now || $identity($now)!==$before) throw new RuntimeException('Runtime configuration parent changed.');
        }
        $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Private runtime configuration differs.');
        return $value;
    } finally { if (is_resource($file)) fclose($file);fclose($lock); }
}

function site_runtime_mode(): string
{
    // This constant is defined only by the fixed TEST webhook route or private CLI.
    if (defined('SITESEE_REQUEST_MODE')) {
        if (!in_array(SITESEE_REQUEST_MODE,['TEST','LIVE'],true)) throw new RuntimeException('Unknown runtime mode.');
        if (SITESEE_REQUEST_MODE==='LIVE' && site_runtime_control()['stage']!=='LIVE') throw new RuntimeException('LIVE is not activated.');
        return SITESEE_REQUEST_MODE;
    }
    return site_runtime_control()['stage'];
}

function site_runtime_live(): bool { return site_runtime_mode()==='LIVE'; }
function site_runtime_stage(): string { return strtolower(site_runtime_mode()); }
function site_runtime_preparing(): bool
{
    return PHP_SAPI==='cli' && defined('SITESEE_PREPARE_LIVE') && SITESEE_PREPARE_LIVE===true;
}
function site_runtime_provider_write(): void
{
    if(site_runtime_preparing())throw new RuntimeException('LIVE provider writes remain disabled until activation.');
}
function site_runtime_bookings_enabled(): bool
{
    if(site_runtime_preparing())return false;
    return site_runtime_live() || getenv('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED')==='1';
}

function site_runtime_session_storage(string $role): void
{
    if (!site_runtime_live()) return;
    if (!in_array($role,['pricing','staff','management','payment'],true)) throw new RuntimeException('Unknown session role.');
    $path=site_runtime_path('data/'.$role.'-sessions');
    if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) throw new RuntimeException('Private session storage unavailable.');
    session_save_path($path);
}

/** Fixed SiteSee-only state root. LIVE cannot inherit an override to the TEST DB. */
function site_runtime_path(string $relative): string
{
    if ($relative==='' || str_starts_with($relative,'/') || str_contains($relative,"\0")
        || array_intersect(explode('/',$relative),['','..','.'])) throw new RuntimeException('Invalid runtime path.');
    $root = dirname(__DIR__);
    if (!site_runtime_live()) return $root.'/'.$relative;
    $parts = array_merge(['runtime','live'],explode('/',$relative)); $path=$root;
    foreach ($parts as $part) {
        $path.='/'.$part; clearstatcache(true,$path); $s=@lstat($path);
        if ($s && (($s['mode']&0170000)===0120000 || $s['uid']!==fileowner($root)
            || ($s['mode']&0077)!==0)) throw new RuntimeException('LIVE storage must remain private and owned.');
    }
    return $path;
}

function site_runtime_env(string $name): string|false
{
    if (!site_runtime_live()) return getenv($name);
    $values=site_runtime_private_json(site_runtime_path('environment.json'));
    if (($values['stage']??'')!=='LIVE' || ($values['realm']??'')!==site_runtime_control()['realm']
        || !is_array($values['values']??null)) throw new RuntimeException('LIVE environment binding differs.');
    if (array_key_exists($name,$values['values'])) {
        if (!is_string($values['values'][$name])) throw new RuntimeException('LIVE environment value differs.');
        return $values['values'][$name];
    }
    // Existing business SMTP and staff authentication remain unchanged. TEST Stripe
    // credentials never become LIVE payment credentials through this fallback.
    if (str_contains($name,'STRIPE') || $name==='SITESEE_REAL_ESTATE_PRICING_GATE_SECRET') return false;
    return getenv($name);
}

function site_runtime_cookie(string $name): string { return $name.(site_runtime_live()?'_live':''); }
function site_runtime_link_path(string $path): string
{
    if (!site_runtime_live()) {
        if(site_runtime_control()['stage']==='LIVE'){
            foreach(['staff-bookings.php'=>'staff-test-bookings.php','staff-production.php'=>'staff-test-production.php','staff-vendors.php'=>'staff-test-vendors.php'] as $old=>$new){
                foreach(['/',''] as $prefix)if(preg_match('~^'.preg_quote($prefix.$old,'~').'(?=[?#]|$)~D',$path))return $prefix.$new.substr($path,strlen($prefix.$old));
            }
        }
        return $path;
    }
    foreach (['booking-pay.php'=>'booking-live-pay.php','manage-appointment.php'=>'manage-live-appointment.php',
        'pricing-approve.php'=>'pricing-live-approve.php','pricing-confirm.php'=>'pricing-live-confirm.php'] as $old=>$new) {
        foreach (['/',''] as $prefix) {
            if (str_starts_with($path,$prefix.$old)) return $prefix.$new.substr($path,strlen($prefix.$old));
        }
    }
    return $path;
}

/** Rewrite generated staff URL attributes only, never saved messages/customer text. */
function site_runtime_staff_html(string $html): string
{
    if(site_runtime_live()||site_runtime_control()['stage']!=='LIVE')return $html;
    return preg_replace_callback('~\b(href|action|data-preview-url)="(/?staff-(?:bookings|production|vendors)\.php(?:[?#][^"]*)?)"~',
        static fn(array $m):string=>$m[1].'="'.site_runtime_link_path($m[2]).'"',$html);
}
function site_runtime_livemode(): bool { return site_runtime_live(); }
function site_runtime_session_id(string $id): bool
{
    return (bool)preg_match(site_runtime_live()?'/^cs_live_[A-Za-z0-9_]+$/D':'/^cs_test_[A-Za-z0-9_]+$/D',$id);
}
function site_runtime_key_valid(string $key, string $kind='sk'): bool
{
    return in_array($kind,['sk','pk'],true) && (bool)preg_match('/^'.$kind.'_'.site_runtime_stage().'_'.($kind==='pk'?'[A-Za-z0-9]{12,512}':'[A-Za-z0-9_]{12,}').'$/D',$key);
}
function site_runtime_idempotency(string $key): string
{
    return site_runtime_live()?str_replace('-test-','-live-',$key):$key;
}
function site_runtime_payment_body(array $body): array
{
    if (site_runtime_live()) {
        $body['metadata[site_application_realm]']=site_runtime_control()['realm'];
        if (($body['mode']??'')==='payment') $body['payment_intent_data[metadata][site_application_realm]']=site_runtime_control()['realm'];
    }
    return $body;
}
function site_runtime_subject(string $subject): string
{
    return site_runtime_live()?str_replace(['[TEST] ',' TEST '],['',' '],$subject):$subject;
}

/** Apply only to source-authored copy, never to saved records or customer text. */
function site_runtime_copy(string $text): string
{
    if (!site_runtime_live()) return $text;
    return str_replace([
        'This is a SiteSee booking integration test, not a real photography appointment.',
        'This is a SiteSee TEST appointment. No live payment has been collected.',
        'This remains an integration test; no live charge has been collected.',
        'This is a test payment. No live charge will be collected.',
        'No live charge or subscription is created.',
        'approved for TEST texts', 'sitesee-arrival-test-',
        '[TEST] ', ' TEST ', 'Test Deposit','Test Balance','Test Payment','Test Bookings',
        'test deposit','test payment','test arrival window','test calendar invitation',
    ],[
        'SiteSee has confirmed your photography appointment.',
        'Your SiteSee appointment status is shown below.',
        'Payment and appointment confirmation are recorded separately.',
        'This payment collects the deposit shown above.',
        'Your card will be charged for this deposit. No subscription starts with this payment.',
        'enabled for secure text verification','sitesee-arrival-live-'.site_runtime_control()['realm'].'-',
        '', ' ', 'Deposit','Balance','Payment','Bookings',
        'deposit','payment','arrival window','calendar invitation',
    ],$text);
}

/** A fresh LIVE ledger is explicitly bound before any application schema writes. */
function site_runtime_bind_db(PDO $db): void
{
    $exists=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='application_realm'")->fetchColumn();
    if (!$exists) {
        if (site_runtime_live()) throw new RuntimeException('LIVE database binding is missing.');
        return;
    }
    $rows=$db->query('SELECT * FROM application_realm')->fetchAll(PDO::FETCH_ASSOC);
    $expected=site_runtime_control();
    if (count($rows)!==1 || ($rows[0]['stage']??'')!==site_runtime_mode()
        || (site_runtime_live() && (($rows[0]['realm']??'')!==$expected['realm']
            || ($rows[0]['stripe_account']??'')!==$expected['stripe_account']))) {
        throw new RuntimeException('Database mode or Stripe account binding differs.');
    }
}

/** Read-only account identity verification; never called at include/bootstrap time. */
function site_runtime_stripe_account(string $key, ?callable $read=null): void
{
    if (!site_runtime_key_valid($key)) throw new RuntimeException('Stripe credential mode differs.');
    if (!site_runtime_live()) return;
    static $verified=[]; $binding=site_runtime_control();
    $fingerprint=hash('sha256',$key.'|'.$binding['realm'].'|'.$binding['stripe_account']);
    if ($read===null && isset($verified[$fingerprint])) return;
    if ($read!==null) $account=$read();
    else {
        $curl=curl_init('https://api.stripe.com/v1/account'); $body='';
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body):int {
                if (strlen($body)+strlen($chunk)>131072) return 0; $body.=$chunk; return strlen($chunk);
            }]);
        try { $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); }
        finally { curl_close($curl); }
        if ($ok===false || $status!==200) throw new RuntimeException('Stripe account verification unavailable.');
        $account=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    }
    if (!is_array($account) || ($account['id']??'')!==$binding['stripe_account']
        || ($account['charges_enabled']??null)!==true || ($account['details_submitted']??null)!==true) {
        throw new RuntimeException('Stripe merchant identity or LIVE readiness differs.');
    }
    if ($read===null) $verified[$fingerprint]=true;
}
