<?php
declare(strict_types=1);
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
