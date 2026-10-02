<?php
declare(strict_types=1);

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
