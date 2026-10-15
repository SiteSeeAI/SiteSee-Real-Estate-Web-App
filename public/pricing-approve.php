<?php
declare(strict_types=1);
// Route alias retained; authentication belongs to the existing private handler.
define('SITESEE_REQUEST_MODE','TEST');
require __DIR__ . '/application-entry.php';
if(site_runtime_control()['stage']==='LIVE'){http_response_code(410);header('Cache-Control: no-store');exit('This TEST pricing link is retired. Sign in at https://re.sitesee.ai/account.php for LIVE orders. Existing TEST payments and appointment-management links remain separate.');}
require site_application_route_target('pricing-approve.php');
