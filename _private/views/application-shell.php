<?php
declare(strict_types=1);

/** Presentation only: callers supply escaped, trusted application body markup. */
function site_application_shell(string $role, string $title, string $body, bool $authenticated = false, string $staffStyle = ''): string
{
    $roles = [
        'customer' => ['home'=>'/account.php', 'label'=>'Customer Account', 'asset'=>'/portal-assets/portal.css?v=20261002-polish-r1'],
        'vendor' => ['home'=>'/vendor.php', 'label'=>'Vendor Account', 'asset'=>'/portal-assets/vendor.css'],
        'staff' => ['home'=>'/staff-bookings.php', 'label'=>'Staff Access', 'asset'=>null],
    ];
    if (!isset($roles[$role])) throw new InvalidArgumentException('Unknown application page role.');
    if ($role !== 'staff' && $staffStyle !== '') throw new InvalidArgumentException('Embedded staff styles require the staff page role.');
    $page = $roles[$role];
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $head = $page['asset'] ? '<link rel="stylesheet" href="'.$page['asset'].'">' : '<style>'.$staffStyle.'</style>';
    $head .= '<link rel="stylesheet" href="/portal-assets/application.css?v=unified-shell-r1">';
    if ($role === 'customer') $head .= '<script src="/portal-assets/portal.js" defer></script>';
    $nav = '';
    if ($authenticated) {
        $nav = match ($role) {
            'customer' => '<nav aria-label="Customer Navigation"><a href="/account.php">My Orders</a><a href="/account.php?view=profile">Account</a><a class="new-order" href="/account.php?view=new">New Order</a></nav>',
            'vendor' => '<nav aria-label="Vendor Navigation"><a href="/vendor.php">My Jobs</a></nav>',
            'staff' => '<nav aria-label="Staff Navigation"><a href="/staff-bookings.php">Bookings</a><a href="/staff-production.php">Production</a><a href="/staff-vendors.php">Vendors</a></nav>',
        };
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>'.$escape($title).' | SiteSee</title>'.$head.'</head>'
        .'<body class="site-shell role-'.$role.'"><a class="skip" href="#content">Skip To Content</a><header><a class="brand" href="'.$page['home'].'" aria-label="SiteSee '.$page['label'].'">SiteSee<span>.</span><small>Show More. Decide Faster.</small></a>'.$nav.'</header>'
        .'<div class="test-notice">TEST ACCESS <span>Orders and payments remain in test mode.</span></div><main id="content"><h1>'.$escape($title).'</h1>'.$body.'</main>'
        .'<footer><span>SiteSee Real Estate · '.$page['label'].'</span><a href="mailto:sales@re.sitesee.ai">sales@re.sitesee.ai</a></footer></body></html>';
}
