<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-communication.php';

function booking_crm_verify_org(array $config, callable $crm): void
{
    $r = $crm('GET','/org');
    if ($r['status'] !== 200 || count($r['body']['org'] ?? []) !== 1
        || (string)$r['body']['org'][0]['id'] !== $config['org_id']) throw new RuntimeException('CRM organization identity changed.');
    $u = $crm('GET','/users?type=CurrentUser');
    if ($u['status'] !== 200 || count($u['body']['users'] ?? []) !== 1
        || (string)$u['body']['users'][0]['id'] !== $config['user_id']) throw new RuntimeException('CRM authorized user changed.');
}

/** Exact primary email comparison, exhaustive pagination and explicit staff selection. No contact creation. */
function booking_crm_candidates(string $email, callable $crm): array
{
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Valid booking email required.');
    $found = [];
    for ($page=1;$page<=10;++$page) {
        $r = $crm('GET','/Contacts/search?'.http_build_query(['email'=>$email,'page'=>$page,'per_page'=>200],'','&',PHP_QUERY_RFC3986));
        if ($r['status'] === 204) return array_values($found);
        if ($r['status'] !== 200 || !isset($r['body']['data']) || !is_array($r['body']['data'])) throw new RuntimeException('CRM contact search failed.');
        foreach ($r['body']['data'] as $contact) {
            if (strcasecmp(trim($contact['Email'] ?? ''),$email) === 0) $found[booking_crm_id((string)$contact['id'])] = $contact;
        }
        if (($r['body']['info']['more_records'] ?? null) === false) return array_values($found);
        if (($r['body']['info']['more_records'] ?? null) !== true) throw new RuntimeException('CRM search pagination is incomplete.');
    }
    throw new RuntimeException('CRM search is too large to verify safely.');
}

function booking_crm_verify_contact(string $id, string $email, callable $crm): array
{
    $r = $crm('GET','/Contacts/'.booking_crm_id($id));
    if ($r['status'] !== 200 || count($r['body']['data'] ?? []) !== 1
        || (string)($r['body']['data'][0]['id'] ?? '') !== $id
        || strcasecmp(trim($r['body']['data'][0]['Email'] ?? ''),$email) !== 0) {
        throw new RuntimeException('CRM contact ID does not match the exact booking email.');
    }
    return $r['body']['data'][0];
}

/** Called only after a staff member reviews and selects the displayed CRM record. */
function booking_crm_link(PDO $db, string $reference, string $id, array $config, callable $crm): void
{
    booking_crm_verify_org($config,$crm);
    $row = booking_get($db,$reference);
    if (!$row) throw new InvalidArgumentException('Booking not found.');
    $candidates = booking_crm_candidates($row['email'],$crm);
    if (!in_array($id,array_map(static fn($c)=>(string)$c['id'],$candidates),true)) throw new InvalidArgumentException('Select a verified exact-email candidate.');
    booking_crm_verify_contact($id,$row['email'],$crm);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $s=$db->prepare('SELECT * FROM booking_contact_links WHERE reference=?');$s->execute([$reference]);$old=$s->fetch();
        if ($old && ($old['contact_id']!==$id || $old['org_id']!==$config['org_id'] || strcasecmp($old['email'],$row['email'])!==0)) {
            throw new InvalidArgumentException('A different CRM link already exists; review it before changing history.');
        }
        if (booking_get($db,$reference)['email'] !== $row['email']) throw new RuntimeException('Booking email changed during verification.');
        $db->prepare('INSERT OR IGNORE INTO booking_contact_links (reference,org_id,contact_id,email,verified_at) VALUES (?,?,?,?,?)')
            ->execute([$reference,$config['org_id'],$id,$row['email'],gmdate('c')]);
        $db->prepare('UPDATE bookings SET crm_contact_id=? WHERE reference=?')->execute([$id,$reference]);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function booking_crm_linked(PDO $db, string $reference, string $email, array $config): array
{
    $s=$db->prepare('SELECT * FROM booking_contact_links WHERE reference=?');$s->execute([$reference]);$link=$s->fetch();
    if (!$link || $link['org_id']!==$config['org_id'] || strcasecmp($link['email'],$email)!==0) {
        throw new InvalidArgumentException('Verify and link the correct CRM contact before sending.');
    }
    return $link;
}

/** Inspect native synchronization or an explicitly selected existing email. */
function booking_crm_history(string $contact, callable $crm): array
{
    $all=[];$index=null;$seen=[];
    for ($page=0;$page<100;++$page) {
        $path='/Contacts/'.booking_crm_id($contact).'/Emails'.($index === null ? '' : '?index='.rawurlencode($index));
        $r=$crm('GET',$path);
        if ($r['status']===204) return $all;
        if ($r['status']!==200 || !is_array($r['body']['Emails'] ?? null)) throw new RuntimeException('CRM email history could not be inspected.');
        $all=array_merge($all,$r['body']['Emails']);
        if (($r['body']['info']['more_records'] ?? null)===false) return $all;
        $index=$r['body']['info']['next_index'] ?? null;
        if (($r['body']['info']['more_records'] ?? null)!==true || !is_string($index) || $index==='' || isset($seen[$index])) {
            throw new RuntimeException('CRM email pagination is incomplete.');
        }
        $seen[$index]=true;
    }
    throw new RuntimeException('CRM history exceeds the safe inspection limit.');
}

function booking_crm_history_candidate(array $email, array $row): bool
{
    $to=array_map(static fn($v)=>strtolower($v['email'] ?? ''),$email['to'] ?? []);
    $time=strtotime($email['time'] ?? $email['sent_time'] ?? '');
    return ($email['subject'] ?? '')===$row['subject'] && ($email['sent'] ?? false)===true
        && strcasecmp($email['from']['email'] ?? '',$row['sender'])===0 && in_array(strtolower($row['recipient']),$to,true)
        && $time!==false && abs($time-strtotime($row['sent_at']))<=60;
}

/** No Graph calls and no send API. Repeated CRM attempts reuse the same original_message_id. */
function booking_communication_crm(PDO $db, string $key, array $config, callable $crm, ?string $confirmExisting = null): void
{
    $row=booking_communication_get($db,$key);
    if (!$row || $row['submission_state']!=='sent_observed' || !$row['internet_message_id']) throw new InvalidArgumentException('Verify the actual sent copy before associating CRM history.');
    if ($row['crm_state']==='associated') return;
    booking_crm_verify_org($config,$crm);
    $link=booking_crm_linked($db,$row['reference'],$row['recipient'],$config);
    booking_crm_verify_contact($link['contact_id'],$row['recipient'],$crm);
    if (($row['crm_org_id'] && $row['crm_org_id']!==$link['org_id']) || ($row['crm_contact_id'] && $row['crm_contact_id']!==$link['contact_id'])) {
        throw new RuntimeException('The stored CRM destination changed.');
    }
    $syncMode = $row['sender']==='cro@sitesee.ai' ? ($config['original_sync_mode'] ?? 'native') : $config['sync_mode'];
    // API-mode senders were explicitly verified as not synchronized during setup.
    // Associate the actual sent Message-ID directly: Zoho rejects an already-associated
    // original_message_id with DUPLICATE_DATA. Unrelated historical mailbox volume
    // must not prevent a new API-owned email entry. Native sync still uses inspection.
    if ($confirmExisting===null && in_array($row['crm_state'],['existing_candidate_review','provider_duplicate'],true)) return;
    $history=($syncMode==='native' || $confirmExisting!==null) ? booking_crm_history($link['contact_id'],$crm) : [];
    $candidates=array_values(array_filter($history,static fn($e)=>booking_crm_history_candidate($e,$row)));
    if (count($candidates)>0) {
        // Documentation does not guarantee original_message_id on GET. Do not guess or duplicate.
        $exact=array_values(array_filter($candidates,static fn($e)=>($e['original_message_id'] ?? '')===$row['internet_message_id']));
        $selected=count($exact)===1 ? $exact[0] : null;
        if ($confirmExisting!==null) {
            $matches=array_values(array_filter($candidates,static fn($e)=>($e['message_id'] ?? '')===$confirmExisting));
            if (count($matches)!==1) throw new InvalidArgumentException('Selected CRM email is not a matching existing candidate.');
            $selected=$matches[0];
        }
        if (!$selected) {
            booking_communication_update($db,$key,['crm_state'=>'existing_candidate_review','crm_error'=>'Review matching CRM email; API insertion blocked.']);
            return;
        }
        booking_communication_update($db,$key,['crm_state'=>'associated','crm_org_id'=>$link['org_id'],'crm_contact_id'=>$link['contact_id'],
            'crm_message_id'=>$selected['message_id'],'crm_associated_at'=>gmdate('c'),'crm_error'=>null]);
        return;
    }
    if ($confirmExisting!==null) throw new InvalidArgumentException('Matching CRM email was not found.');
    if ($syncMode==='native') {
        booking_communication_update($db,$key,['crm_state'=>'awaiting_native_sync','crm_error'=>null]); return;
    }
    $db->exec('BEGIN IMMEDIATE');
    $diagnostic='CRM association needs recovery.';
    try {
        $current=booking_communication_get($db,$key);
        if ($current['crm_state']==='associated') { $db->exec('COMMIT'); return; }
        if ($current['crm_state']==='associating' && strtotime($current['crm_attempted_at'])>time()-120) throw new RuntimeException('CRM association is already in progress.');
        booking_communication_update($db,$key,['crm_state'=>'associating','crm_attempted_at'=>gmdate('c'),
            'crm_org_id'=>$link['org_id'],'crm_contact_id'=>$link['contact_id'],'crm_error'=>null]);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    $m=json_decode($row['message_json'],true,32,JSON_THROW_ON_ERROR);
    $email=['from'=>['user_name'=>'SiteSee Real Estate','email'=>$row['sender']],
        'to'=>[['user_name'=>$row['recipient'],'email'=>$row['recipient']]],'subject'=>$row['subject'],
        'content'=>$m['html'],'mail_format'=>'html','date_time'=>$row['sent_at'],'sent'=>true,
        'original_message_id'=>$row['internet_message_id'],'owner'=>['id'=>$config['user_id']]];
    try {
        $r=$crm('POST','/Contacts/'.$link['contact_id'].'/actions/associate_email',['Emails'=>[$email]]);
        $item=$r['body']['Emails'][0] ?? [];
        if (in_array($r['status'],[200,201,202],true) && ($item['code'] ?? '')==='SUCCESS' && !empty($item['details']['message_id'])) {
            booking_communication_update($db,$key,['crm_state'=>'associated','crm_message_id'=>$item['details']['message_id'],
                'crm_associated_at'=>gmdate('c'),'crm_error'=>null]);
        } elseif (($item['code'] ?? $r['body']['code'] ?? '')==='DUPLICATE_DATA') {
            // Documented provider duplicate result; retain it without inventing a CRM email ID.
            booking_communication_update($db,$key,['crm_state'=>'provider_duplicate','crm_error'=>'Provider reports existing association; inspect CRM history.']);
        } else {
            $code=$item['code'] ?? $r['body']['code'] ?? 'UNACKNOWLEDGED';
            if (!is_string($code) || !preg_match('/^[A-Z_]{1,80}$/D',$code)) $code='UNACKNOWLEDGED';
            $diagnostic='CRM association failed (HTTP '.(int)$r['status'].'; code '.$code.').';
            throw new RuntimeException('CRM association was not acknowledged.');
        }
    } catch (Throwable) {
        booking_communication_update($db,$key,['crm_state'=>'retry_pending','crm_error'=>$diagnostic.' Mail must not be resent.']);
        throw new RuntimeException('Mail remains sent. '.$diagnostic.' Retry only the CRM association.');
    }
}
