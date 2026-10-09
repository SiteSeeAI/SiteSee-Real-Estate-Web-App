<?php
declare(strict_types=1);
/** Only the installed Graph bridge may hand off verification mail; no SMTP fallback. */
function portal_send_email_code(string $email, string $code): bool
{
    $email=portal_normalize_email($email);
    if (!preg_match('/^[0-9]{8}$/D',$code)
        || !preg_match('~^/usr/local/bin/sitesee-graph-sendmail(?:\s+-(?:t|i))*$~D',trim((string)ini_get('sendmail_path')))) return false;
    $message=portal_email_message($email,$code);
    return @mail($email,$message['subject'],$message['body'],implode("\r\n",$message['headers']));
}

function portal_email_message(string $email, string $code): array
{
    $email=portal_normalize_email($email);
    if (!preg_match('/^[0-9]{8}$/D',$code)) throw new InvalidArgumentException('Invalid verification code.');
    return ['subject'=>'Verify Your SiteSee Contact Email',
        'headers'=>['From: SiteSee Real Estate <sales@re.sitesee.ai>','Reply-To: sales@re.sitesee.ai',
            'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8'],
        'body'=>"Your SiteSee verification code is: ".$code."\r\n\r\nReturn to Account > Change Email in the same signed-in browser and enter this code within 15 minutes.\r\n\r\nYour contact email will change to ".$email." only after verification. Your cell-phone sign-in stays the same.\r\n\r\nIf you did not request this change, ignore this email. Do not share the code.\r\n\r\nSiteSee Real Estate\r\nShow More. Decide Faster."];
}

/** Login mail uses the existing pricing mail transport; it never replays booking notices. */
function portal_send_login(string $email, string $token): bool
{
    $link = SITESEE_REAL_ESTATE_SITE_URL . '/account.php?view=verify#' . $token;
    $safe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;color:#01111e;max-width:600px;margin:auto"><h1>Sign In To SiteSee</h1>'
        . '<p>Open your account to view your orders.</p><p><a href="'.$safe.'" style="display:inline-block;background:#ffc107;color:#01111e;padding:15px 24px;text-decoration:none">Continue To Sign In</a></p>'
        . '<p>This link expires in 15 minutes and can be used once. If you did not request it, you can ignore this email.</p>'
        . '<p>If the button does not work, open the link below and paste its code into the sign-in form.</p><p>'.$safe.'</p></div>';
    $plain = "Sign in to SiteSee:\n".$link."\n\nThis link expires in 15 minutes and can be used once. If you did not request it, ignore this email.";
    return real_estate_send_mail($email, SITESEE_REAL_ESTATE_SALES_EMAIL, 'Your SiteSee Sign In Link', $html, $plain);
}

/** Only newly bound orders use this adapter. Existing CRM association remains in staff review. */
function portal_send_order(string $recipient,array $submission,string $reference): bool
{
    $staff=$recipient==='staff';
    $link=SITESEE_REAL_ESTATE_SITE_URL.($staff?'/staff-bookings.php?reference='.rawurlencode($reference):'/account.php?view=order&reference='.rawurlencode($reference));
    $plain="Reference: ".$reference."\n\n".($staff?$submission['salesPlain']:$submission['plain'])
        ."\n\nYour request is saved. The TEST deposit and SiteSee schedule review are still required. Your arrival window is not confirmed.\n".$link;
    $html='<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto"><h1>'.($staff?'New Preferred-Date Request':'Your SiteSee Request Is Saved').'</h1><pre style="font-family:Arial,sans-serif;white-space:pre-wrap">'.htmlspecialchars($plain,ENT_QUOTES,'UTF-8').'</pre></div>';
    return real_estate_send_mail($staff?SITESEE_REAL_ESTATE_SALES_EMAIL:$submission['details']['email'],
        $staff?$submission['details']['email']:SITESEE_REAL_ESTATE_SALES_EMAIL,booking_property_subject($submission['quote']['subject'],$submission['details']),$html,$plain);
}
