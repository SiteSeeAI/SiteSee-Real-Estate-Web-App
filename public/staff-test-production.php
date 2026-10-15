<?php
declare(strict_types=1);
// Fixed authenticated TEST recovery; request data cannot select a realm.
define('SITESEE_REQUEST_MODE','TEST');
require __DIR__.'/application-entry.php';
require site_application_route_target('staff-test-production.php');
