<?php
declare(strict_types=1);
define('SITESEE_REQUEST_MODE','LIVE');
require __DIR__.'/application-entry.php';
require site_application_route_target('manage-live-appointment.php');
