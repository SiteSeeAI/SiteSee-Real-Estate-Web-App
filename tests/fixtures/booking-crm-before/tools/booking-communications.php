<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=is_dir(dirname(__DIR__).'/_private/server') ? dirname(__DIR__).'/_private' : dirname(__DIR__);
// CLI uses no pricing tokens; these process-local values only satisfy shared bootstrap.
putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.bin2hex(random_bytes(24)));
umask(0077);
require_once $root.'/server/booking-invitation.php';
function communication_report(PDO $db, string $reference): void {
    $s=$db->prepare('SELECT communication_key,sender,recipient,submission_state,provider_message_id,internet_message_id,provider_accepted_at,sent_at,delivery_state,crm_state,crm_org_id,crm_contact_id,crm_message_id,crm_error,calendar_uid,event_uid FROM booking_communications WHERE reference=? ORDER BY created_at');
    $s->execute([$reference]); echo json_encode($s->fetchAll(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}
try {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== fileowner($root)) throw new RuntimeException('Run this command as the sitesee account using runuser, not as root.');
    $action=$argv[1] ?? 'help'; $arg=$argv[2] ?? '';
    if ($action==='help') {
        echo "Commands:\n  lookup REFERENCE\n  link REFERENCE CONTACT_ID\n  import-original E6E183EF8E\n  send-probe E6E183EF8E cro@sitesee.ai\n  recheck KEY\n  delivery KEY\n  crm KEY\n  history KEY\n  accept-existing KEY CRM_MESSAGE_ID\n  enable probe:E6E183EF8E\n  report REFERENCE\n\nOnly send-probe sends an email (no calendar invitation). No command recreates an appointment or replays payment.\n"; exit;
    }
    $db=booking_db(); booking_communication_schema($db);
    if ($action==='report') { communication_report($db,$arg); exit; }
    if (in_array($action,['lookup','link','crm','history','accept-existing','send-probe'],true)) {
        $cfg=booking_crm_config(); $crm=booking_crm_client($cfg); booking_crm_verify_org($cfg,$crm);
    }
    if ($action==='lookup' || $action==='link') {
        $row=booking_get($db,$arg); if (!$row) throw new InvalidArgumentException('Booking not found.');
        if ($action==='link') { booking_crm_link($db,$arg,$argv[3] ?? '',$cfg,$crm); echo "Verified CRM contact linked locally. No CRM record or email created.\n"; }
        else {
            echo 'Verified CRM organization: '.$cfg['org_id']."\n";
            foreach (booking_crm_candidates($row['email'],$crm) as $c) echo json_encode([
                'id'=>$c['id'],'name'=>$c['Full_Name'] ?? trim(($c['First_Name'] ?? '').' '.($c['Last_Name'] ?? '')),
                'email'=>$c['Email'],'account'=>$c['Account_Name']['name'] ?? null],JSON_UNESCAPED_SLASHES)."\n";
            echo "Choose the correct existing contact. An empty result requires CRM review; no contact is created.\n";
        }
        exit;
    }
    if ($action==='crm' || $action==='accept-existing') {
        booking_communication_crm($db,$arg,$cfg,$crm,$action==='accept-existing' ? ($argv[3] ?? '') : null);
    } elseif ($action==='history') {
        $row=booking_communication_get($db,$arg); if (!$row) throw new InvalidArgumentException('Communication not found.');
        $link=booking_crm_linked($db,$row['reference'],$row['recipient'],$cfg);
        foreach (booking_crm_history($link['contact_id'],$crm) as $e) if (booking_crm_history_candidate($e,$row)) echo json_encode($e,JSON_UNESCAPED_SLASHES)."\n";
        echo "Review this candidate in Zoho CRM before using accept-existing.\n"; exit;
    } elseif ($action==='enable') {
        $row=booking_communication_get($db,$arg);
        if (!$row || $row['kind']!=='probe' || $row['sender']!==BOOKING_MAIL_SENDER || $row['recipient']!=='cro@sitesee.ai'
            || $row['submission_state']!=='sent_observed' || $row['delivery_state']!=='recipient_copy_observed' || $row['crm_state']!=='associated') {
            throw new RuntimeException('Verify probe submission, recipient receipt and CRM association before enabling invitations.');
        }
        $c=booking_mail_config(); $c['enabled']=true; $c['validated_probe']=$arg; $c['enabled_at']=gmdate('c');
        $path=$root.'/booking-mail.json';$tmp=tempnam($root,'.booking-mail-');
        try { if (file_put_contents($tmp,json_encode($c,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n")===false) throw new RuntimeException('Configuration save failed.'); chmod($tmp,0600);if (!rename($tmp,$path)) throw new RuntimeException('Configuration activation failed.'); }
        finally { if (is_file($tmp)) unlink($tmp); }
        echo "Dedicated booking mail enabled. Existing calendar invitation switch remains unchanged. No email sent.\n"; exit;
    } else {
        $mail=booking_mail_config();$graph=booking_graph_client($mail);
        if ($action==='recheck') booking_communication_reconcile($db,$arg,$graph);
        elseif ($action==='delivery') booking_communication_delivery($db,$arg,$graph);
        elseif ($action==='import-original') {
            if ($arg!=='E6E183EF8E') throw new InvalidArgumentException('This import is restricted to the verified original booking.');
            $saved=booking_confirmation_get($db,$arg);$row=booking_get($db,$arg);
            if (!$saved || $saved['invitation_state']!=='sent' || $row['email']!=='cro@sitesee.ai') throw new RuntimeException('Original booking state changed.');
            $key='original:'.$arg;
            if (booking_communication_get($db,$key)) { communication_report($db,$arg); exit; }
            $internet='<BN7PR08MB523649F3D9D13D0FAE12140ADA8F2@BN7PR08MB5236.namprd08.prod.outlook.com>';
            $r=$graph('GET','/users/cro%40sitesee.ai/mailFolders/sentitems/messages?'.http_build_query([
                '$filter'=>"internetMessageId eq '".$internet."'",'$select'=>'id,internetMessageId,from,toRecipients,replyTo,subject,body,isDraft,sentDateTime','$top'=>2],'','&',PHP_QUERY_RFC3986));
            if ($r['status']!==200 || count($r['body']['value'] ?? [])!==1 || isset($r['body']['@odata.nextLink'])) throw new RuntimeException('Exactly one original sent invitation was not found.');
            $m=$r['body']['value'][0];
            $expected=['sender'=>'cro@sitesee.ai','recipient'=>'cro@sitesee.ai','kind'=>'original','subject'=>'[TEST] SiteSee Photography Arrival Window | E6E183EF8E'];
            booking_graph_message_identity($m,$expected);
            if (($m['isDraft'] ?? true)!==false || $m['internetMessageId']!==$internet || $m['sentDateTime']!=='2026-09-26T16:31:35Z' || empty($m['body']['content'])) throw new RuntimeException('Original invitation evidence differs from the handoff.');
            $html=strtolower($m['body']['contentType'] ?? '')==='html' ? $m['body']['content'] : '<pre>'.htmlspecialchars($m['body']['content'],ENT_QUOTES,'UTF-8').'</pre>';
            $db->exec('BEGIN IMMEDIATE');
            try {
                booking_communication_enqueue($db,$arg,'original',['from'=>'cro@sitesee.ai','to'=>$row['email'],'subject'=>$m['subject'],'html'=>$html],$saved);
                booking_communication_update($db,$key,['submission_state'=>'sent_observed','provider_message_id'=>$m['id'],'internet_message_id'=>$internet,
                    'provider_accepted_at'=>$saved['invitation_sent_at'],'sent_observed_at'=>gmdate('c'),'sent_at'=>$m['sentDateTime']]);
                $db->exec('COMMIT');
            } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
            echo "Original message imported from Sent Items. Its actual sender is preserved; booking and event unchanged.\n";
            communication_report($db,$arg);exit;
        } elseif ($action==='send-probe') {
            if ($arg!=='E6E183EF8E' || ($argv[3] ?? '')!=='cro@sitesee.ai') throw new InvalidArgumentException('Use the designated booking and explicitly confirm cro@sitesee.ai.');
            $row=booking_get($db,$arg); if (!$row || $row['email']!==$mail['test_recipient_email']) throw new InvalidArgumentException('Verified test booking email required.');
            $link=booking_crm_linked($db,$arg,$row['email'],$cfg);booking_crm_verify_contact($link['contact_id'],$row['email'],$crm);
            $key='probe:'.$arg;
            if (booking_communication_get($db,$key)) throw new InvalidArgumentException('Probe already exists. Use recheck, delivery and crm; do not send again.');
            $plain='This is the controlled SiteSee Real Estate mail and CRM connection test. No appointment is created or changed. Reference: '.$arg;
            $message=['from'=>BOOKING_MAIL_SENDER,'to'=>$row['email'],'subject'=>'[TEST] SiteSee Real Estate Mail Connection | '.$arg,
                'headers'=>['From: SiteSee Real Estate <'.BOOKING_MAIL_SENDER.'>','Reply-To: '.BOOKING_MAIL_SENDER,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8'],
                'body'=>$plain,'plain'=>$plain,'html'=>'<p>'.$plain.'</p>'];
            booking_communication_enqueue($db,$arg,'probe',$message);
            booking_communication_submit($db,$key,$graph);
            echo "Probe submitted once. Run recheck, delivery and crm to verify each result.\n";
            communication_report($db,$arg);exit;
        } else throw new InvalidArgumentException('Unknown command; use help.');
    }
    $row=booking_communication_get($db,$arg);communication_report($db,$row['reference']);
} catch (Throwable $e) {
    // Provider functions expose only fixed diagnostics; never dump provider payloads or traces.
    fwrite(STDERR,'STOP: '.$e->getMessage()."\n"); exit(1);
}
