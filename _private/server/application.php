<?php
declare(strict_types=1);
require_once __DIR__.'/application-mode.php';

/** Routing/configuration only. Existing handlers retain all authentication and write guards. */
function site_application_routes(): array
{
    return [
        'account.php' => ['handler'=>'server/portal-app.php', 'role'=>'customer', 'portal'=>true],
        'vendor.php' => ['handler'=>'server/vendor-app.php', 'role'=>'vendor', 'portal'=>true],
        'staff-bookings.php' => ['handler'=>'server/booking-staff.php', 'role'=>'staff'],
        'staff-production.php' => ['handler'=>'server/booking-staff.php', 'role'=>'staff', 'flag'=>'SITESEE_PRODUCTION_PAGE'],
        'staff-vendors.php' => ['handler'=>'server/booking-staff.php', 'role'=>'staff', 'flag'=>'SITESEE_VENDOR_ADMIN_PAGE'],
        'staff-test-bookings.php' => ['handler'=>'server/booking-staff.php', 'role'=>'staff'],
        'staff-test-production.php' => ['handler'=>'server/booking-staff.php', 'role'=>'staff', 'flag'=>'SITESEE_PRODUCTION_PAGE'],
        'staff-test-vendors.php' => ['handler'=>'server/booking-staff.php', 'role'=>'staff', 'flag'=>'SITESEE_VENDOR_ADMIN_PAGE'],
        'manage-appointment.php' => ['handler'=>'server/booking-manage.php', 'role'=>'signed-management-link'],
        'booking-pay.php' => ['handler'=>'server/booking-pay.php', 'role'=>'signed-payment-link'],
        'booking-live-pay.php' => ['handler'=>'server/booking-pay.php', 'role'=>'signed-payment-link'],
        'manage-live-appointment.php' => ['handler'=>'server/booking-manage.php', 'role'=>'signed-management-link'],
        'pricing-live-approve.php' => ['handler'=>'server/pricing-approve.php', 'role'=>'signed-approval-link'],
        'pricing-live-confirm.php' => ['handler'=>'server/pricing-confirm.php', 'role'=>'signed-confirmation-link'],
        'booking-live-webhook.php' => ['handler'=>'server/booking-webhook.php', 'role'=>'signed-provider-webhook'],
        'booking-webhook.php' => ['handler'=>'server/booking-webhook.php', 'role'=>'signed-provider-webhook'],
        'booking-availability.php' => ['handler'=>'server/booking-availability-check.php', 'role'=>'pricing-session'],
        'pricing.php' => ['handler'=>'views/pricing.php', 'role'=>'pricing-session'],
        'pricing-request.php' => ['handler'=>'server/pricing-request.php', 'role'=>'public'],
        'pricing-approve.php' => ['handler'=>'server/pricing-approve.php', 'role'=>'signed-approval-link'],
        'pricing-confirm.php' => ['handler'=>'server/pricing-confirm.php', 'role'=>'signed-confirmation-link'],
        'pricing-logout.php' => ['handler'=>'server/pricing-logout.php', 'role'=>'pricing-session'],
        'pricing-asset.php' => ['handler'=>'server/pricing-asset.php', 'role'=>'pricing-session'],
        'quote-submit.php' => ['handler'=>'server/quote-submit.php', 'role'=>'pricing-session'],
        'contact-submit.php' => ['handler'=>'server/contact-submit.php', 'role'=>'public'],
    ];
}

function site_application_bootstrap(string $root): void
{
    if (realpath($root) !== dirname(__DIR__)) throw new RuntimeException('Application root differs from loaded source.');
    // Keep the same inode locked throughout each request; installer holds its exclusive lock.
    static $applicationLock=null;
    if($applicationLock===null){
        $applicationLock=fopen($root,'r');
        if(!$applicationLock||!flock($applicationLock,LOCK_SH|LOCK_NB)){
            if($applicationLock)fclose($applicationLock);$applicationLock=null;
            throw new RuntimeException('Application update is in progress.');
        }
    }
    if(file_exists($root.'/.unified-maintenance.json'))throw new RuntimeException('Application update requires completion.');
    // Consolidation cannot enable a release or turn an integration on.
    $stage = getenv('SITESEE_APPLICATION_STAGE');
    if ($stage !== false && $stage !== '' && $stage !== site_runtime_control()['stage']) {
        throw new RuntimeException('Application stage differs from its private activation.');
    }
    foreach (['SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET', 'SITESEE_REAL_ESTATE_STRIPE_PUBLISHABLE_KEY'] as $name) {
        $key = (string)getenv($name);
        if (!site_runtime_live() && (str_starts_with($key, 'sk_live_') || str_starts_with($key, 'pk_live_') || str_starts_with($key, 'rk_live_'))) {
            throw new RuntimeException('LIVE Stripe credentials are unavailable in this TEST release.');
        }
    }
}

function site_application_route_target(string $route): string
{
    $routes = site_application_routes();
    if (!isset($routes[$route])) throw new InvalidArgumentException('Unknown application route.');
    $entry = $routes[$route];
    if (!empty($entry['portal'])) {
        require_once __DIR__ . '/portal-release.php';
        // Preserve the physical, default-off release gate and override inherited flags.
        putenv('SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED=' . (portal_release_enabled(site_runtime_path('portal-test.json')) ? '1' : '0'));
    }
    if (isset($entry['flag'])) define($entry['flag'], true);
    return dirname(__DIR__) . '/' . $entry['handler'];
}
