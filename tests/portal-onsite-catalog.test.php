<?php
declare(strict_types=1);
require __DIR__.'/../_private/real-estate-pricing.php';
require __DIR__.'/../_private/server/booking-job-catalog.php';
function booking_request(array $row): array {return json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $call,string $message): void {try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Accepted: '.$message);}
function row(array $quote): array {return ['market'=>$quote['market'],'request_json'=>json_encode(['quote'=>$quote],JSON_THROW_ON_ERROR)];}
function price(array $row,array $items): array {return booking_job_catalog_lines($row,$items,[]);}
function item(string $service,array $inputs=[]): array {return compact('service','inputs');}

$res=row(real_estate_residential_quote(['package'=>'gold','selected'=>[]]));
check(array_keys(booking_job_catalog($res)['services'])===['photo','platform','website','drone','zillow','video','floor','twilight','mp'],'All nine residential services, including originals');
$lines=price($res,[item('drone'),item('mp',['matterportSqft'=>2500]),item('video',['videoMinutes'=>2]),item('twilight',['images'=>2]),item('platform')]);
check(array_column($lines,'cents')===[12000,15000,28750,7000,0],'Residential exact fees without original package repricing');
check(booking_job_commission($lines)['commission_cents']===6120,'Only new drone, Matterport and twilight earn 18%; package video and monthly platform excluded');
check(booking_job_commission($lines)['additional_monthly_cents']===4900,'Residential subscription remains separate');
check(price($res,[item('mp',['matterportSqft'=>1])])[0]['cents']===6900,'Residential Matterport minimum');
check(price($res,[item('mp',['matterportSqft'=>10000])])[0]['cents']===49900,'Residential Matterport cap');
check(price($res,[item('video',['videoMinutes'=>1,'videoSecondsPart'=>1])])[0]['cents']===22604,'Video exact second rounding matches ordering');
rejects(fn()=>price($res,[item('drone'),item('drone')]),'Duplicate onsite service');
rejects(fn()=>price($res,[item('made_up')]),'Unknown onsite service');
rejects(fn()=>price($res,[item('mp',['matterportSqft'=>0])]),'Zero Matterport area');
rejects(fn()=>price($res,[item('video',['videoMinutes'=>3,'videoSecondsPart'=>1])]),'Video over three minutes');
$forged=item('drone')+['cents'=>1,'commission_base_cents'=>999999,'label'=>'Forged'];
check(price($res,[$forged])[0]['cents']===12000,'Client price/commission/label cannot alter server fee');
$all=[];
foreach(array_keys(booking_job_catalog($res)['services']) as $key)$all[]=item($key,match($key){'photo'=>['sqft'=>1200],'mp'=>['matterportSqft'=>2000],default=>[]});
check(count(price($res,$all))===9,'Every residential catalog service can be selected in the same draft');

$com=row(real_estate_commercial_quote(['category'=>'mid','selected'=>[],'licenseType'=>'unlimited']));
check(array_keys(booking_job_catalog($com)['services'])===['photo','platform','mp','views360','drone','video','floor','website'],'All eight commercial services');
$lines=price($com,[item('video',['videoMinutes'=>2]),item('mp',['matterportSqft'=>1990,'hostingMonths'=>18,'hostingPrepaid'=>'yes']),item('platform')]);
check(array_column($lines,'cents')===[149994,25888,29400],'Commercial media plus license, scan plus hosting, platform term');
check(array_column($lines,'commission_base_cents')===[99996,19900,0],'Subscriptions, hosting and licensing excluded from commission basis');
check(booking_job_commission($lines)['commission_cents']===21581,'18% of $1,198.96 rounded once, never of subscription/license/hosting fees');
check(price($com,[item('drone',['licenseType'=>'unlimited','licenseMonths'=>''])])[0]['cents']===6300,'Unlimited ignores hidden license term, matching canonical pricing');
$plain=row(real_estate_commercial_quote(['category'=>'small','selected'=>[]]));
rejects(fn()=>price($plain,[item('views360')]),'360 without required services');
rejects(fn()=>price($plain,[item('mp',['matterportSqft'=>10001])]),'Small commercial scan cap');
$all=[];foreach(array_keys(booking_job_catalog($plain)['services']) as $key)$all[]=item($key,$key==='mp'?['matterportSqft'=>2000]:[]);
check(count(price($plain,$all))===8,'Every commercial catalog service selectable with dependencies');
$preordered=row(real_estate_commercial_quote(['category'=>'small','selected'=>['platform','mp'],'matterportSqft'=>2000]));
check(price($preordered,[item('views360',['views360'=>4])])[0]['cents']===10000,'Original subscriptions/scans satisfy 360 dependencies');
check(booking_job_commission(price($preordered,[item('mp',['matterportSqft'=>2000]),item('platform')]))['commission_cents']===0,'Previously ordered scan and subscription earn no commission');
$legacy=['id'=>'legacy123','label'=>'Saved custom addition','cents'=>8400];
$saved=booking_job_catalog_lines($res,[['legacy_id'=>'legacy123']],[$legacy]);
check($saved===[$legacy]&&booking_job_commission($saved)['commission_cents']===0&&booking_job_commission($saved)['commission_unclassified'],'Legacy amount preserved without inventing commission');
rejects(fn()=>booking_job_catalog_lines($res,[['legacy_id'=>'unknown']],[$legacy]),'Unknown legacy service');
echo "portal-onsite-catalog: PASS (full catalogs, exact prices, duplicates, quantities, package overlap, commission exclusions, dependency rules, legacy preservation)\n";
