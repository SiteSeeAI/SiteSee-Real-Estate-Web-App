<?php
declare(strict_types=1);

require_once __DIR__ . '/../_private/real-estate-pricing.php';

$state = ['package'=>'gold', 'category'=>'average', 'selected'=>['platform'], 'platformMonths'=>6];
$quote = real_estate_residential_quote($state);
if ($quote['jobCents'] !== 49900 || $quote['platformCents'] !== 29400 || $quote['totalCents'] !== 79300) {
    throw new RuntimeException('The platform term must not change the one-time Gold package fee.');
}
$platformLines = array_values(array_filter($quote['lines'], static fn(array $line): bool => $line['key'] === 'platform'));
if (count($platformLines) !== 1 || $platformLines[0]['label'] !== 'SiteSee Experience Platform · 6 Months at $49 / Month') {
    throw new RuntimeException('The server quote must describe the selected subscription term.');
}
$long = real_estate_residential_quote(array_replace($state, ['platformMonths'=>18]));
if ($long['platformCents'] !== 88200 || $long['jobCents'] !== 49900) {
    throw new RuntimeException('The 18-month platform term must be priced separately.');
}
try {
    real_estate_residential_quote(array_replace($state, ['platformMonths'=>19]));
    throw new RuntimeException('An invalid platform term was accepted.');
} catch (InvalidArgumentException $e) {
    // Expected validation failure.
}
fwrite(STDOUT, "Residential platform server pricing: PASS\n");
