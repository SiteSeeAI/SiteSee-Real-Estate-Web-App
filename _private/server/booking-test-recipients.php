<?php
declare(strict_types=1);
require_once __DIR__.'/application-mode.php';

/** Explicitly approved TEST recipients. This grants no mailbox-reading access. */
function booking_test_recipients(): array
{
    return ['sales@re.sitesee.ai', 'info@1789media.com'];
}

function booking_test_recipient_allowed(string $email): bool
{
    return in_array(strtolower($email), booking_test_recipients(), true);
}

/** Preserve legacy exact matches; extend only the existing RE primary configuration. */
function booking_test_recipient_matches(string $email, string $configured): bool
{
    return strcasecmp($email, $configured) === 0
        || (strcasecmp($configured, 'sales@re.sitesee.ai') === 0 && booking_test_recipient_allowed($email));
}

/** LIVE permission to send never grants permission to read a customer's mailbox. */
function booking_recipient_allowed(string $email): bool
{
    return site_runtime_live()?(bool)filter_var($email,FILTER_VALIDATE_EMAIL):booking_test_recipient_allowed($email);
}
function booking_recipient_matches(string $email,string $configured): bool
{
    return site_runtime_live()?($configured===''&&booking_recipient_allowed($email)):booking_test_recipient_matches($email,$configured);
}
