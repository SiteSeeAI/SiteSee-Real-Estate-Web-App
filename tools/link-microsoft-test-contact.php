<?php
declare(strict_types=1);
/** Reuse the user's prior CRM selection for this one TEST booking; never send. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function sitesee_link_test_contact(PDO $db,array $config,callable $crm): array
{
    $target='D32FFC7458'; $source='E4E51A0481'; $email='cro@sitesee.ai';
    $before=booking_get($db,$target);$prior=booking_get($db,$source);
    if (!$before || !$prior || $before['status']!=='deposit_paid_test' || !$before['deposit_paid_at']
        || !$before['approved_at'] || strcasecmp($before['email'],$email)!==0 || strcasecmp($prior['email'],$email)!==0) {
        throw new InvalidArgumentException('The expected paid, reviewed TEST booking or prior test recipient differs. Nothing was linked.');
    }
    $claim=booking_confirmation_get($db,$target);$oldClaim=booking_confirmation_get($db,$source);
    if (!$claim || $claim['state']!=='confirmed' || $claim['calendar_uid']!=='microsoft:'.BOOKING_MS_CALENDAR
        || !$oldClaim || $oldClaim['state']!=='confirmed' || $oldClaim['invitation_state']!=='sent'
        || strcasecmp((string)$oldClaim['invitation_recipient'],$email)!==0) {
        throw new InvalidArgumentException('Microsoft confirmation or the prior verified invitation differs. Nothing was linked.');
    }
    $messages=$db->prepare('SELECT COUNT(*) FROM booking_communications WHERE reference=?');$messages->execute([$target]);
    if ($claim['invitation_state']!=='none' || (int)$messages->fetchColumn()!==0) {
        throw new InvalidArgumentException('An invitation or communication is already recorded for this booking. Do not resend; inspect its saved status. Nothing was reset.');
    }
    // This exact previous booking's contact was already selected and verified by the user.
    $link=booking_crm_linked($db,$source,$email,$config);
    if (empty($link['verified_at']) || !strtotime($link['verified_at']) || ($prior['crm_contact_id']??null)!==$link['contact_id']) {
        throw new InvalidArgumentException('The prior CRM verification is incomplete. No contact was guessed or created.');
    }
    if (!empty($before['crm_contact_id']) && $before['crm_contact_id']!==$link['contact_id']) {
        throw new InvalidArgumentException('A different CRM contact is already recorded. It was preserved for review.');
    }
    // Fresh organization, current-user, complete search and exact contact-email verification.
    booking_crm_link($db,$target,$link['contact_id'],$config,$crm);
    $saved=booking_crm_linked($db,$target,$email,$config);
    if ($saved['contact_id']!==$link['contact_id']) throw new RuntimeException('Local CRM link verification failed.');
    $current=booking_confirmation_get($db,$target);$messages->execute([$target]);
    return ['reference'=>$target,'source'=>$source,'email'=>$email,'contact_id'=>$saved['contact_id'],
        'invitation_state'=>$current['invitation_state'],'communications'=>(int)$messages->fetchColumn()];
}

if (!defined('SITESEE_CONTACT_LINK_TEST_LIBRARY')) {
    ini_set('display_errors','0');$phase='private application checks';
    try {
        $root='/home/sitesee/.sitesee-real-estate';
        if (!function_exists('posix_geteuid') || posix_geteuid()!==fileowner($root)) {
            throw new InvalidArgumentException('Use the supplied runuser command to run as sitesee.');
        }
        // Process-local CLI bootstrap only; no configuration file or payment gate changes.
        putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');
        putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.bin2hex(random_bytes(24)));
        require_once $root.'/server/booking-invitation.php';
        $path=$root.'/data/bookings.sqlite';$info=lstat($path);
        if (!$info || ($info['mode']&0170000)!==0100000 || ($info['mode']&0077)!==0
            || $info['nlink']!==1 || $info['uid']!==posix_geteuid()) throw new InvalidArgumentException('Existing booking database ownership or permissions differ.');
        $db=new PDO('sqlite:file:'.$path.'?mode=rw',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5]);
        $phase='verify existing TEST scheduling configuration';
        $claim=booking_confirmation_get($db,'D32FFC7458');
        $calendar=booking_scheduling_config($claim);
        if (!booking_scheduling_is_microsoft($calendar)) throw new InvalidArgumentException('This booking is not assigned to Microsoft.');
        $phase='verify the existing CRM contact and link this booking';
        $config=booking_crm_config();$result=sitesee_link_test_contact($db,$config,booking_crm_client($config));
        echo "FINAL RESULTS\nBooking: ".$result['reference']."\nCalendar: confirmed / Microsoft\n";
        echo 'CRM contact: verified and linked using the prior selection for '.$result['source']."\n";
        echo 'Recipient: '.$result['email']."\nSaved invitation state: ".$result['invitation_state']."\n";
        echo 'Saved communication records: '.$result['communications']."\n";
        echo "No email, invitation, calendar event, payment, credential or CRM record was created or changed.\nOnly this booking's local CRM link was saved.\n";
        echo $result['invitation_state']==='none' && $result['communications']===0
            ? "Next: refresh this booking and click Send Test Calendar Invitation once.\n"
            : "Next: an invitation state changed during the check. Review its saved status; do not resend.\n";
    } catch (Throwable $error) {
        echo "FINAL RESULTS\nCRM link: STOPPED\nPhase: ".$phase."\n";
        echo $error instanceof InvalidArgumentException ? $error->getMessage()."\n" : "The existing CRM connection or local verification did not complete. No sending was attempted.\n";
        exit(1);
    }
}
