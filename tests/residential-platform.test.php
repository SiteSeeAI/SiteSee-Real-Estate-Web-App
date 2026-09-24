<?php
declare(strict_types=1);

require_once __DIR__ . '/../_private/real-estate-pricing.php';

$state = ['package'=>'gold', 'category'=>'average', 'selected'=>['platform']];
$quote = real_estate_residential_quote($state);
if ($quote['jobCents'] !== 49900 || $quote['platformCents'] !== 4900 || $quote['platformMonthlyCents'] !== 4900 || $quote['totalCents'] !== 49900) {
    throw new RuntimeException('The monthly platform charge must not change the one-time Gold package fee.');
}
$platformLines = array_values(array_filter($quote['lines'], static fn(array $line): bool => $line['key'] === 'platform'));
if (count($platformLines) !== 1 || $platformLines[0]['label'] !== 'SiteSee Experience Platform · Monthly Until Sold' || $platformLines[0]['cents'] !== 4900) {
    throw new RuntimeException('The server quote must describe the monthly subscription separately.');
}
$body = real_estate_quote_body($quote, ['first'=>'A','last'=>'Agent','company'=>'Broker','email'=>'a@example.com','phone'=>'5555550100','street'=>'1 Main','city'=>'Town','state'=>'WI','zip'=>'54241','optOut'=>'Yes'], ['date'=>'2026-10-01','time'=>'10:00']);
if (!str_contains($body, 'Estimated one-time job total: $499.00') || !str_contains($body, 'tell SiteSee when the property sells')) {
    throw new RuntimeException('The email must separate the job fee and explain when the monthly charge ends.');
}
$oldTerm = real_estate_residential_quote(array_replace($state, ['platformMonths'=>18]));
if ($oldTerm['platformMonthlyCents'] !== 4900 || $oldTerm['jobCents'] !== 49900) {
    throw new RuntimeException('An old browser must not create an 18-month charge.');
}
fwrite(STDOUT, "Residential platform server pricing: PASS\n");
