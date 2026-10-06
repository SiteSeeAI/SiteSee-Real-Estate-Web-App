<?php
declare(strict_types=1);
require '/home/sitesee/.sitesee-real-estate/server/portal-release.php';
putenv('SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED='.(portal_release_enabled('/home/sitesee/.sitesee-real-estate/portal-test.json')?'1':'0'));
require '/home/sitesee/.sitesee-real-estate/server/vendor-app.php';
