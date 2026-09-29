<?php
declare(strict_types=1);
/* Public route only. No configuration, records or session files belong here. */
require '/home/sitesee/.sitesee-real-estate/server/portal-release.php';
// Explicitly override inherited flags: disabling this release must close the route.
putenv('SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED=' . (portal_release_enabled('/home/sitesee/.sitesee-real-estate/portal-test.json') ? '1' : '0'));
require '/home/sitesee/.sitesee-real-estate/server/portal-app.php';
