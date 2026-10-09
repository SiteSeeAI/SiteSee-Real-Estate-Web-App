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
    'unit'=>'Suite 2',
    'propertyId'=>'MLS-009',
    'city'=>'Two Rivers',
    'state'=>'WI',
    'zip'=>'54241',
    'optOut'=>'Yes',
];

// Mailing preference is optional; default to updates welcome without overriding an opt-out.
$withoutPreference = $details;
unset($withoutPreference['optOut']);
$same(real_estate_validate_details($withoutPreference)['optOut'], 'No', 'Missing mailing preference defaults to updates welcome.');
$same(real_estate_validate_details(array_replace($details, ['optOut'=>'']))['optOut'], 'No', 'Blank mailing preference defaults to updates welcome.');
$same(real_estate_validate_details(array_replace($details, ['optOut'=>'No']))['optOut'], 'No', 'Explicit updates-welcome preference is retained.');
$same(real_estate_validate_details($details)['optOut'], 'Yes', 'An explicit mailing exclusion remains unchanged.');
$throws(static fn() => real_estate_validate_details(array_replace($details, ['optOut'=>'unexpected'])), 'Unknown mailing preference is rejected.');

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
$same($residential['subject'], 'Residential SiteSee Real Estate Quote | 123 Example Street Suite 2 Two Rivers WI 54241', 'Residential subject contains property address.');
$contains($residential['plain'], 'Estimated one-time job total: $256.90', 'Residential body contains the server total.');
$contains($residential['plain'], 'Preferred time: 10:00 Central Time', 'Residential body contains the required appointment.');
$contains($residential['plain'], 'Unit / Suite: Suite 2', 'Optional unit reaches the server quote.');
$contains($residential['plain'], 'MLS / Property ID: MLS-009', 'Optional property ID reaches the server quote.');

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
$requestAppointment = array_replace($appointment, ['meetPhotographer'=>'Yes','cancellationAccepted'=>true]);
$commercial = real_estate_prepare_submission([
    'version'=>1,
    'action'=>'request_appointment',
    'market'=>'commercial',
    'details'=>$details,
    'appointment'=>$requestAppointment,
    'state'=>$commercialState,
], $now);
$same($commercial['quote']['totalCents'], 778881, 'Commercial server total matches fixed photography, add-ons, license and hosting.');
$same($commercial['quote']['licenseCents'], 165531, 'Commercial unlimited license uses only its approved media base.');
$same($commercial['quote']['hostingCents'], 5988, 'Commercial prepaid hosting prices only months after the first six.');
$same($commercial['quote']['knownMinutes'], 275, 'Commercial time uses the category minimum plus selected capture.');
$same($commercial['quote']['knownMinutesMax'], 335, 'Commercial time range retains the category maximum.');
$same($commercial['subject'], 'Commercial SiteSee Real Estate Quote | 123 Example Street Suite 2 Two Rivers WI 54241', 'Commercial subject contains property address.');
$contains($commercial['plain'], 'Additional Photography · 1 Photos at $26.70 Each', 'Commercial body includes added photographs.');
$contains($commercial['plain'], '20,000 sq ft scanned', 'Commercial body includes independent Matterport coverage.');
$contains($commercial['plain'], 'Exclude from mailing lists: Yes', 'Commercial body preserves the mailing-list choice.');
$contains($commercial['plain'], 'Agent meets photographer: Yes', 'Commercial request records the meeting choice.');
$contains($commercial['plain'], '24-hour cancellation policy accepted.', 'Commercial request records policy acceptance.');

$residentialRequest = ['version'=>1,'action'=>'request_appointment','market'=>'residential',
    'details'=>$details,'appointment'=>$requestAppointment,'state'=>$residentialState];
$accessAppointment = array_replace($requestAppointment, [
    'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'0123456789',
    'specialRequests'=>'Please keep the dog indoors.','mustHaveShots'=>'Back patio and kitchen.',
    'onsiteDifferent'=>true,'onsiteName'=>'Property Manager','onsiteEmail'=>'manager@example.com','onsitePhone'=>'555-555-0123',
    'additionalDifferent'=>true,'additionalName'=>'Listing Assistant','additionalEmail'=>'assistant@example.com',
]);
$accessRequest = array_replace($residentialRequest, ['appointment'=>$accessAppointment]);
$withAccess = real_estate_prepare_submission($accessRequest, $now);
$same($withAccess['appointment']['lockboxCode'], '0123456789', 'Leading zeros in the ten-digit access code are preserved.');
$contains($withAccess['plain'], 'Lockbox code: Provided to SiteSee separately', 'Agent copy masks the access code.');
$contains($withAccess['salesPlain'], 'Lockbox code: 0123456789', 'SiteSee receives the access code.');
if (str_contains($withAccess['plain'], '0123456789')) {
    throw new RuntimeException('Customer copy disclosed the lockbox code.');
}
$contains($withAccess['plain'], 'On-site contact: Property Manager', 'On-site contact reaches the request.');
$contains($withAccess['plain'], 'Additional scheduling contact: Listing Assistant', 'Additional contact reaches the request.');
$contains($withAccess['plain'], 'Must-have shots: Back patio and kitchen.', 'Shot list is separate from special requests.');
$keyRequest = array_replace($residentialRequest, ['appointment'=>array_replace($accessAppointment, [
    'accessType'=>'Key','lockboxCode'=>'stale-code','keyLocation'=>"Under the front desk\nAsk for the building manager.",
])]);
$withKey = real_estate_prepare_submission($keyRequest, $now);
$same($withKey['appointment']['lockboxCode'], '', 'A stale lockbox value is discarded for key access.');
$contains($withKey['plain'], "Key location: Under the front desk\nAsk for the building manager.", 'Multiline key location is retained.');
$meetingRequest = array_replace($residentialRequest, ['appointment'=>array_replace($accessAppointment, [
    'meetPhotographer'=>'Yes','accessType'=>'Lockbox','lockboxCode'=>'0123456789',
])]);
$meeting = real_estate_prepare_submission($meetingRequest, $now);
$same($meeting['appointment']['accessType'], '', 'Meeting on site discards access details.');
$throws(static fn() => real_estate_prepare_submission(array_replace($residentialRequest,
    ['appointment'=>array_replace($requestAppointment, ['cancellationAccepted'=>false])]), $now), 'Policy acceptance is required for requests.');
$throws(static fn() => real_estate_prepare_submission(array_replace($residentialRequest,
    ['appointment'=>array_replace($accessAppointment, ['lockboxCode'=>'123456789'])]), $now), 'Lockbox code must contain ten digits.');
$throws(static fn() => real_estate_prepare_submission(array_replace($residentialRequest,
    ['appointment'=>array_replace($keyRequest['appointment'], ['keyLocation'=>str_repeat('k', 151)])]), $now), 'Key location is limited to 150 characters.');
$throws(static fn() => real_estate_prepare_submission(array_replace($residentialRequest,
    ['appointment'=>array_replace($requestAppointment, ['specialRequests'=>str_repeat('s', 251)])]), $now), 'Special requests are limited to 250 characters.');

$throws(static fn() => real_estate_prepare_submission([
    'version'=>1,'action'=>'email_quote','market'=>'residential','details'=>array_diff_key($details, ['company'=>true]),
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
