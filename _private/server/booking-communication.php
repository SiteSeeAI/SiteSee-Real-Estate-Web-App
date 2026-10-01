<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-store.php';
require_once __DIR__ . '/booking-mail-client.php';

function booking_communication_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS booking_contact_links (
        reference TEXT PRIMARY KEY, org_id TEXT NOT NULL, contact_id TEXT NOT NULL,
        email TEXT NOT NULL, verified_at TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_communications (
        communication_key TEXT PRIMARY KEY, reference TEXT NOT NULL, kind TEXT NOT NULL,
        sender TEXT NOT NULL, recipient TEXT NOT NULL, subject TEXT NOT NULL, message_json TEXT NOT NULL,
        calendar_uid TEXT, event_uid TEXT, created_at TEXT NOT NULL,
        submission_state TEXT NOT NULL DEFAULT 'prepared', submission_attempted_at TEXT,
        provider_message_id TEXT, internet_message_id TEXT, provider_request_id TEXT,
        provider_accepted_at TEXT, sent_observed_at TEXT, sent_at TEXT,
        delivery_state TEXT NOT NULL DEFAULT 'unverified', delivery_evidence_json TEXT,
        crm_state TEXT NOT NULL DEFAULT 'pending', crm_org_id TEXT, crm_contact_id TEXT,
        crm_message_id TEXT, crm_attempted_at TEXT, crm_associated_at TEXT, crm_error TEXT,
        UNIQUE(reference,kind))");
}

function booking_communication_get(PDO $db, string $key): array|false
{
    $s = $db->prepare('SELECT * FROM booking_communications WHERE communication_key=?'); $s->execute([$key]); return $s->fetch();
}

function booking_communication_update(PDO $db, string $key, array $values): void
{
    $allowed = ['submission_state','submission_attempted_at','provider_message_id','internet_message_id','provider_request_id',
        'provider_accepted_at','sent_observed_at','sent_at','delivery_state','delivery_evidence_json','crm_state','crm_org_id',
        'crm_contact_id','crm_message_id','crm_attempted_at','crm_associated_at','crm_error'];
    foreach (array_keys($values) as $column) if (!in_array($column, $allowed, true)) throw new LogicException('Invalid tracking column.');
    $s = $db->prepare('UPDATE booking_communications SET ' . implode(',', array_map(static fn($k)=>$k.'=?', array_keys($values))) . ' WHERE communication_key=?');
    $s->execute([...array_values($values), $key]);
}

function booking_communication_enqueue(PDO $db, string $reference, string $kind, array $message, array $confirmation = []): string
{
    if ((!in_array($kind, ['invitation','probe','original'], true) && !preg_match('/^lifecycle-[1-9][0-9]{0,8}$/D',$kind)) || !preg_match('/^[A-F0-9]{10,32}$/D', $reference)) throw new InvalidArgumentException('Invalid communication identity.');
    $key = $kind . ':' . $reference;
    $s = $db->prepare('INSERT INTO booking_communications (communication_key,reference,kind,sender,recipient,subject,message_json,calendar_uid,event_uid,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$key,$reference,$kind,$message['from'],$message['to'],$message['subject'],json_encode($message, JSON_THROW_ON_ERROR),
        $confirmation['calendar_uid'] ?? null,$confirmation['event_uid'] ?? null,gmdate('c')]);
    return $key;
}

/** Each transition is committed before its corresponding non-idempotent provider call. */
function booking_communication_submit(PDO $db, string $key, callable $graph): void
{
    $s = $db->prepare("UPDATE booking_communications SET submission_state='creating' WHERE communication_key=? AND submission_state='prepared'");
    $s->execute([$key]);
    if ($s->rowCount() !== 1) throw new InvalidArgumentException('This communication was already attempted. Recheck the saved message; do not resend.');
    $row = booking_communication_get($db,$key);
    if ($row['sender'] !== BOOKING_MAIL_SENDER || $row['recipient'] !== 'sales@re.sitesee.ai' || $row['kind'] === 'original') {
        throw new InvalidArgumentException('Only the dedicated sender and configured test recipient may submit.');
    }
    $m = json_decode($row['message_json'], true, 32, JSON_THROW_ON_ERROR);
    $path = '/users/' . rawurlencode($row['sender']) . '/messages';
    $mime = implode("\r\n", ['To: ' . $m['to'],'Subject: ' . $m['subject'],'Date: ' . gmdate(DATE_RFC2822),
        'X-SiteSee-Communication: ' . $key, ...$m['headers']]) . "\r\n\r\n" . $m['body'];
    try {
        $r = $graph('POST',$path,base64_encode($mime));
        if ($r['status'] !== 201 || empty($r['body']['id']) || ($r['body']['isDraft'] ?? null) !== true) {
            throw new RuntimeException('Graph draft creation was not verified.');
        }
        $id = $r['body']['id'];
        booking_communication_update($db,$key,['submission_state'=>'draft','provider_message_id'=>$id,
            'internet_message_id'=>$r['body']['internetMessageId'] ?? null]);
        booking_communication_send_saved_draft($db,$key,$graph);
    } catch (Throwable $error) {
        $state = booking_communication_get($db,$key)['submission_state'];
        booking_communication_update($db,$key,['submission_state'=>in_array($state,['draft','draft_blocked'],true) ? 'draft_blocked' : 'uncertain']);
        if ($error instanceof InvalidArgumentException) throw $error;
        throw new RuntimeException('Mail operation stopped. Recheck the saved message; automatic resend is blocked.');
    }
}

function booking_communication_unsent_draft(array $row): bool
{
    if (!in_array($row['submission_state'] ?? '', ['draft','draft_blocked'], true)
        || empty($row['provider_message_id']) || ($row['sender'] ?? '') !== BOOKING_MAIL_SENDER
        || ($row['recipient'] ?? '') !== BOOKING_MAIL_SENDER || ($row['kind'] ?? '') === 'original') return false;
    foreach (['submission_attempted_at','provider_accepted_at','sent_observed_at','sent_at'] as $field) {
        if (!empty($row[$field])) return false;
    }
    return ($row['delivery_state'] ?? '') === 'unverified' && ($row['crm_state'] ?? '') === 'pending';
}

/** Fill only absent envelope fields; wrong identities, extra recipients and sent items stop. */
function booking_communication_verify_draft(array $row, callable $graph): void
{
    $path = '/users/'.rawurlencode($row['sender']).'/messages/'.rawurlencode($row['provider_message_id']);
    $query = '?%24select=id,isDraft,from,toRecipients,ccRecipients,bccRecipients,replyTo,subject,internetMessageId';
    $r = $graph('GET',$path.$query);
    if ($r['status'] !== 200 || ($r['body']['isDraft'] ?? false) !== true
        || ($r['body']['id'] ?? '') !== $row['provider_message_id']) {
        throw new RuntimeException('The saved draft is unavailable or no longer unsent. Nothing was sent.');
    }
    $m = $r['body']; $patch = [];
    // Validate the full envelope before PATCH. Only empty To/Reply-To may differ.
    foreach (['toRecipients'=>$row['recipient'],'replyTo'=>$row['sender']] as $field=>$address) {
        if (!isset($m[$field]) || !is_array($m[$field])) throw new RuntimeException('Draft envelope is incomplete. Nothing was sent.');
        if ($m[$field] === []) {
            $patch[$field] = [['emailAddress'=>['address'=>$address]]];
            $m[$field] = $patch[$field];
        }
    }
    foreach (['ccRecipients','bccRecipients'] as $field) {
        if (!isset($m[$field]) || $m[$field] !== []) throw new RuntimeException('Unexpected or unavailable CC/BCC recipients. Nothing was sent.');
    }
    booking_graph_message_identity($m,$row);
    if ($patch) {
        $etag = $r['body']['@odata.etag'] ?? null;
        $body = json_encode($patch,JSON_THROW_ON_ERROR);
        booking_graph_draft_patch_guard($path,$body,$etag);
        $updated = $graph('PATCH',$path,$body,$etag);
        if ($updated['status'] !== 200) throw new RuntimeException('The draft changed or its recipient could not be restored. Nothing was sent.');
        $r = $graph('GET',$path.$query);
        if ($r['status'] !== 200 || ($r['body']['isDraft'] ?? false) !== true
            || ($r['body']['id'] ?? '') !== $row['provider_message_id']
            || ($r['body']['ccRecipients'] ?? null) !== [] || ($r['body']['bccRecipients'] ?? null) !== []) {
            throw new RuntimeException('Repaired draft could not be verified. Nothing was sent.');
        }
        booking_graph_message_identity($r['body'],$row);
    }
}

/** Same provider ID, no new draft, and no send retry after submission starts. */
function booking_communication_send_saved_draft(PDO $db, string $key, callable $graph): void
{
    $row = booking_communication_get($db,$key);
    if (!$row || !booking_communication_unsent_draft($row)) throw new InvalidArgumentException('Only a saved draft with no send attempt can continue. Use Recover Booking Status.');
    try {
        booking_communication_verify_draft($row,$graph);
        $s = $db->prepare("UPDATE booking_communications SET submission_state='submitting',submission_attempted_at=? WHERE communication_key=? AND provider_message_id=? AND submission_state IN ('draft','draft_blocked') AND submission_attempted_at IS NULL AND provider_accepted_at IS NULL AND sent_observed_at IS NULL AND sent_at IS NULL");
        $s->execute([gmdate('c'),$key,$row['provider_message_id']]);
        if ($s->rowCount() !== 1) throw new RuntimeException('Saved draft changed before submission.');
        $r = $graph('POST','/users/'.rawurlencode($row['sender']).'/messages/'.rawurlencode($row['provider_message_id']).'/send','');
        if ($r['status'] !== 202) throw new RuntimeException('Graph submission was not acknowledged.');
        booking_communication_update($db,$key,['submission_state'=>'accepted','provider_accepted_at'=>gmdate('c'),'provider_request_id'=>$r['request_id'] ?? null]);
    } catch (Throwable $e) {
        $state = booking_communication_get($db,$key)['submission_state'];
        if (in_array($state,['draft','draft_blocked'],true)) {
            booking_communication_update($db,$key,['submission_state'=>'draft_blocked']);
            throw new InvalidArgumentException($e->getMessage());
        }
        if ($state === 'submitting') booking_communication_update($db,$key,['submission_state'=>'uncertain']);
        throw new RuntimeException('Send result is uncertain. Recover Booking Status; do not send again.');
    }
}

function booking_graph_message_identity(array $m, array $row): void
{
    $to = array_map(static fn($v)=>strtolower($v['emailAddress']['address'] ?? ''),$m['toRecipients'] ?? []);
    $reply = array_map(static fn($v)=>strtolower($v['emailAddress']['address'] ?? ''),$m['replyTo'] ?? []);
    if (strcasecmp($m['from']['emailAddress']['address'] ?? '',$row['sender']) !== 0 || $to !== [strtolower($row['recipient'])]
        || !empty($m['ccRecipients']) || !empty($m['bccRecipients'])
        || ($m['subject'] ?? '') !== $row['subject'] || ($row['kind'] !== 'original' && $reply !== [strtolower($row['sender'])])) {
        throw new RuntimeException('Provider message identity does not match the saved communication.');
    }
}

/** GET only. A 202, a missing copy or a remaining draft is never delivery proof. */
function booking_communication_reconcile(PDO $db, string $key, callable $graph): array
{
    $row = booking_communication_get($db,$key);
    if (!$row || !$row['provider_message_id']) throw new InvalidArgumentException('No provider message ID was saved. Inspect the mailbox; no retry is authorized.');
    $r = $graph('GET','/users/'.rawurlencode($row['sender']).'/messages/'.rawurlencode($row['provider_message_id'])
        .'?%24select=id,isDraft,from,toRecipients,ccRecipients,bccRecipients,replyTo,subject,internetMessageId,sentDateTime');
    if ($r['status'] !== 200) throw new RuntimeException('Sent copy is not yet available. No message was resent.');
    $m = $r['body']; booking_graph_message_identity($m,$row);
    if (($m['isDraft'] ?? true) !== false || empty($m['internetMessageId']) || empty($m['sentDateTime'])
        || strtotime($m['sentDateTime']) === false) throw new RuntimeException('A sent copy has not been verified. No message was resent.');
    booking_communication_update($db,$key,['submission_state'=>'sent_observed','sent_observed_at'=>gmdate('c'),
        'sent_at'=>$m['sentDateTime'],'internet_message_id'=>$m['internetMessageId']]);
    if ($row['kind'] === 'invitation') {
        $db->prepare("UPDATE booking_confirmations SET invitation_state='sent',invitation_sent_at=COALESCE(invitation_sent_at,?) WHERE reference=? AND invitation_state IN ('sending','uncertain')")
            ->execute([$m['sentDateTime'],$row['reference']]);
    }
    return booking_communication_get($db,$key);
}

/** Read only the expressly configured test recipient's mailbox for this exact message. */
function booking_communication_delivery(PDO $db, string $key, callable $graph): void
{
    $row = booking_communication_get($db,$key);
    if (!$row || !$row['internet_message_id'] || $row['recipient'] !== 'sales@re.sitesee.ai') throw new InvalidArgumentException('Exact test message identity is required.');
    $filter = "internetMessageId eq '" . str_replace("'","''",$row['internet_message_id']) . "'";
    $r = $graph('GET','/users/'.rawurlencode($row['recipient']).($row['kind'] === 'original' ? '/mailFolders/deleteditems/messages?' : '/mailFolders/inbox/messages?').http_build_query([
        '$filter'=>$filter,'$select'=>'id,internetMessageId,from,toRecipients,subject,receivedDateTime,parentFolderId,isDraft','$top'=>10],'','&',PHP_QUERY_RFC3986));
    if ($r['status'] !== 200 || !isset($r['body']['value']) || isset($r['body']['@odata.nextLink'])) throw new RuntimeException('Recipient evidence is incomplete.');
    $matches = array_values(array_filter($r['body']['value'],static fn($m)=>($m['internetMessageId'] ?? '') === $row['internet_message_id']
        && ($m['isDraft'] ?? true) === false && !empty($m['receivedDateTime']) && ($m['subject'] ?? '') === $row['subject']
        && strcasecmp($m['from']['emailAddress']['address'] ?? '',$row['sender']) === 0));
    if (count($matches) !== 1) throw new RuntimeException('Exactly one recipient copy was not found. Delivery remains unverified.');
    booking_communication_update($db,$key,['delivery_state'=>'recipient_copy_observed','delivery_evidence_json'=>json_encode([
        'checked_at'=>gmdate('c'),'mailbox'=>$row['recipient'],'message_id'=>$matches[0]['id'],
        'internet_message_id'=>$row['internet_message_id'],'received_at'=>$matches[0]['receivedDateTime'],
        'folder_id'=>$matches[0]['parentFolderId'] ?? null],JSON_THROW_ON_ERROR)]);
}
