<?php
declare(strict_types=1);

require_once __DIR__ . '/../_private/real-estate-pricing.php';

$count = 0;
$same = static function (mixed $actual, mixed $expected, string $label) use (&$count): void {
    $count++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
};
$contains = static function (string $haystack, string $needle, string $label) use (&$count): void {
    $count++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . "\nMissing: " . $needle);
    }
};
$throws = static function (callable $callback, string $label) use (&$count): void {
    $count++;
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($label . "\nExpected InvalidArgumentException.");
};

$details = [
    'first'=>'Test',
    'last'=>'Agent',
    'company'=>'Example Realty',
    'email'=>'agent@example.com',
    'phone'=>'555-555-0100',
    'street'=>'123 Example Street',
    'city'=>'Two Rivers',
    'state'=>'WI',
    'zip'=>'54241',
    'optOut'=>'Yes',
];
$appointment = ['date'=>'2099-01-15','time'=>'10:00'];
$now = new DateTimeImmutable('2026-09-21 12:00', new DateTimeZone('America/Chicago'));

$residentialState = [
    'category'=>'average',
    'package'=>'custom',
    'sqft'=>'2680',
    'matterportSqft'=>'',
    'selected'=>['photo'],
    'videoSeconds'=>60,
    'images'=>1,
];
$residential = real_estate_prepare_submission([
    'version'=>1,
    'action'=>'email_quote',
    'market'=>'residential',
    'details'=>$details,
    'appointment'=>$appointment,
    'state'=>$residentialState,
], $now);
$same($residential['quote']['totalCents'], 25690, 'Average-home interpolation matches the browser engine.');
$same($residential['subject'], 'Residential SiteSee Real Estate Quote', 'Residential subject is exact.');
$contains($residential['plain'], 'Estimated total: $256.90', 'Residential body contains the server total.');
$contains($residential['plain'], 'Preferred time: 10:00 Central Time', 'Residential body contains the required appointment.');

$luxury = real_estate_residential_quote(array_replace($residentialState, ['category'=>'luxury','sqft'=>'10000','selected'=>['photo','mp']]));
$same($luxury['totalCents'], 129400, 'Luxury photography and Matterport retain approved caps.');
$same($luxury['knownMinutes'], 440, 'Luxury caps do not cap capture time.');

$package = real_estate_residential_quote(array_replace($residentialState, ['package'=>'platinum','sqft'=>'0','matterportSqft'=>'10000','selected'=>['mp']]));
$same($package['totalCents'], 149400, 'Platinum keeps Matterport separate and capped.');
$same($package['matterportMinutes'], 90, 'Package Matterport uses the submitted scan area for time.');

$commercialState = [
    'category'=>'mid',
    'photoCount'=>'46',
    'matterportSqft'=>'20000',
    'views360'=>'1',
    'selected'=>['photo','platform','mp','video','drone','website'],
    'videoSeconds'=>120,
    'videos'=>'2',
    'aerialImages'=>'2',
    'delivery'=>'website',
    'platformMonths'=>'12',
    'plans'=>'1',
    'licenseType'=>'unlimited',
    'licenseMonths'=>'6',
    'hostingMonths'=>'18',
    'hostingPrepaid'=>true,
];
$commercial = real_estate_prepare_submission([
    'version'=>1,
    'action'=>'request_appointment',
    'market'=>'commercial',
    'details'=>$details,
    'appointment'=>$appointment,
    'state'=>$commercialState,
], $now);
$same($commercial['quote']['totalCents'], 778881, 'Commercial server total matches fixed photography, add-ons, license and hosting.');
$same($commercial['quote']['licenseCents'], 165531, 'Commercial unlimited license uses only its approved media base.');
$same($commercial['quote']['hostingCents'], 5988, 'Commercial prepaid hosting prices only months after the first six.');
$same($commercial['quote']['knownMinutes'], 275, 'Commercial time uses the category minimum plus selected capture.');
$same($commercial['quote']['knownMinutesMax'], 335, 'Commercial time range retains the category maximum.');
$same($commercial['subject'], 'Commercial SiteSee Real Estate Quote', 'Commercial subject is exact.');
$contains($commercial['plain'], 'Additional Photography · 1 Photos at $26.70 Each', 'Commercial body includes added photographs.');
$contains($commercial['plain'], '20,000 sq ft scanned', 'Commercial body includes independent Matterport coverage.');
$contains($commercial['plain'], 'Exclude from mailing lists: Yes', 'Commercial body preserves the mailing-list choice.');

$throws(static fn() => real_estate_prepare_submission([
    'version'=>1,'action'=>'email_quote','market'=>'residential','details'=>$details - ['company'=>true],
    'appointment'=>$appointment,'state'=>$residentialState,
], $now), 'Company remains required on the server.');
$throws(static fn() => real_estate_prepare_submission([
    'version'=>1,'action'=>'email_quote','market'=>'residential','details'=>$details,
    'appointment'=>['date'=>'2026-09-21','time'=>'11:45'],'state'=>$residentialState,
], $now), 'Past Central Time appointments are rejected.');
$throws(static fn() => real_estate_prepare_submission([
    'version'=>1,'action'=>'email_quote','market'=>'residential','details'=>$details,
    'appointment'=>['date'=>'2099-01-15','time'=>'10:07'],'state'=>$residentialState,
], $now), 'Appointments remain on 15-minute increments.');
$throws(static fn() => real_estate_commercial_quote(array_replace($commercialState, ['photoCount'=>'56'])), 'Commercial category photograph caps are enforced.');
$throws(static fn() => real_estate_commercial_quote(array_replace($commercialState, ['matterportSqft'=>'20001'])), 'Commercial scan coverage remains capped at 20,000 sq ft.');

fwrite(STDOUT, "Passed {$count} server-form assertions.\n");
