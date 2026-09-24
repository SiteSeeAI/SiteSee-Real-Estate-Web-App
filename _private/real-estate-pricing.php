<?php
declare(strict_types=1);

function real_estate_invalid(string $message): never
{
    throw new InvalidArgumentException($message);
}

function real_estate_clean(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return trim(preg_replace('/[ \t]+/', ' ', $value) ?? '');
}

function real_estate_string(array $source, string $key, int $max, string $message): string
{
    $value = real_estate_clean((string)($source[$key] ?? ''));
    if ($value === '' || strlen($value) > $max) {
        real_estate_invalid($message);
    }
    return $value;
}

function real_estate_integer(mixed $value, int $min, int $max, string $message): int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
        $number = (int)$value;
    } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
        $number = (int)$value;
    } else {
        real_estate_invalid($message);
    }
    if ($number < $min || $number > $max) {
        real_estate_invalid($message);
    }
    return $number;
}

function real_estate_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2, '.', ',');
}

function real_estate_video_duration(int $seconds): string
{
    return intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function real_estate_duration(float $minutes, string $empty = 'No on-site capture'): string
{
    $rounded = (int)(ceil($minutes / 5) * 5);
    if ($rounded === 0) {
        return $empty;
    }
    $hours = intdiv($rounded, 60);
    $remaining = $rounded % 60;
    $parts = [];
    if ($hours) {
        $parts[] = $hours . ' hr' . ($hours === 1 ? '' : 's');
    }
    if ($remaining) {
        $parts[] = $remaining . ' min';
    }
    return implode(' ', $parts);
}

function real_estate_duration_range(float $minimum, float $maximum): string
{
    $lower = real_estate_duration($minimum, 'Confirmed With Your Appointment');
    $upper = real_estate_duration($maximum, 'Confirmed With Your Appointment');
    return $lower === $upper ? $lower : $lower . '–' . $upper;
}

function real_estate_selected(array $state, array $allowed): array
{
    $raw = $state['selected'] ?? [];
    if (!is_array($raw)) {
        real_estate_invalid('Choose valid services for this quote.');
    }
    $selected = [];
    foreach ($raw as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            real_estate_invalid('Choose valid services for this quote.');
        }
        if (!in_array($key, $selected, true)) {
            $selected[] = $key;
        }
    }
    return $selected;
}

function real_estate_validate_details(array $details): array
{
    $states = [
        'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS',
        'KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC',
        'ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
    ];
    $validated = [
        'first' => real_estate_string($details, 'first', 100, 'Enter your first name.'),
        'last' => real_estate_string($details, 'last', 100, 'Enter your last name.'),
        'company' => real_estate_string($details, 'company', 140, 'Enter your company name.'),
        'email' => strtolower(trim((string)($details['email'] ?? ''))),
        'phone' => real_estate_string($details, 'phone', 35, 'Enter your phone number.'),
        'street' => real_estate_string($details, 'street', 180, 'Enter the property street address.'),
        'city' => real_estate_string($details, 'city', 100, 'Enter the property city.'),
        'state' => strtoupper(trim((string)($details['state'] ?? ''))),
        'zip' => trim((string)($details['zip'] ?? '')),
        'optOut' => (string)($details['optOut'] ?? ''),
    ];
    if (!filter_var($validated['email'], FILTER_VALIDATE_EMAIL) || strlen($validated['email']) > 180 || preg_match('/[\r\n]/', $validated['email'])) {
        real_estate_invalid('Enter a valid email address.');
    }
    $phoneDigits = preg_replace('/\D+/', '', $validated['phone']) ?? '';
    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 18) {
        real_estate_invalid('Enter a phone number with at least 10 digits.');
    }
    if (!in_array($validated['state'], $states, true)) {
        real_estate_invalid('Select a valid state.');
    }
    if (!preg_match('/^\d{5}(?:-\d{4})?$/', $validated['zip'])) {
        real_estate_invalid('Enter a valid ZIP code.');
    }
    if (!in_array($validated['optOut'], ['Yes', 'No'], true)) {
        real_estate_invalid('Select your mailing-list preference.');
    }
    return $validated;
}

function real_estate_validate_appointment(array $appointment, ?DateTimeImmutable $now = null): array
{
    $date = trim((string)($appointment['date'] ?? ''));
    $time = trim((string)($appointment['time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        real_estate_invalid('Choose your preferred date and time.');
    }
    $zone = new DateTimeZone('America/Chicago');
    $selected = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
    $formatErrors = DateTimeImmutable::getLastErrors();
    if (
        !$selected ||
        ($formatErrors !== false && (($formatErrors['warning_count'] ?? 0) > 0 || ($formatErrors['error_count'] ?? 0) > 0)) ||
        $selected->format('Y-m-d H:i') !== $date . ' ' . $time ||
        ((int)$selected->format('i')) % 15 !== 0
    ) {
        real_estate_invalid('Choose a valid date and a time in 15-minute increments.');
    }
    $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    if ($selected <= $now) {
        real_estate_invalid('Choose a future date and time in Central Time.');
    }
    return ['date' => $date, 'time' => $time];
}

function real_estate_residential_quote(array $state): array
{
    $categories = [
        'small' => ['label' => 'Small Home / Condo', 'min' => 1, 'max' => 1999, 'photos' => '25–30 photos'],
        'average' => ['label' => 'Average Home', 'min' => 2000, 'max' => 4000, 'photos' => '30–50 photos'],
        'large' => ['label' => 'Large Home', 'min' => 4000, 'max' => 5000, 'photos' => '50–60 photos'],
        'luxury' => ['label' => 'Luxury Home', 'min' => 5000, 'max' => 10000, 'photos' => '50+ photos'],
    ];
    $packages = [
        'custom' => ['label' => 'Individual Services', 'cents' => 0, 'includes' => [], 'photos' => null, 'minutes' => 0],
        'silver' => ['label' => 'Silver', 'cents' => 22000, 'includes' => ['photo','website'], 'photos' => '25 HDR photos', 'minutes' => 0],
        'gold' => ['label' => 'Gold', 'cents' => 49900, 'includes' => ['photo','website','video','floor'], 'photos' => '35 HDR photos', 'minutes' => 1],
        'platinum' => ['label' => 'Platinum', 'cents' => 99500, 'includes' => ['photo','website','video','floor','drone'], 'photos' => '50+ HDR photos', 'minutes' => 2],
    ];
    $services = [
        'photo' => 'Property Photography',
        'platform' => 'SiteSee Experience Platform',
        'website' => 'Property Website',
        'drone' => 'Drone / Aerial Photos',
        'zillow' => 'Zillow 3D Home',
        'video' => 'Property Video',
        'floor' => '2D Schematic Floor Plan',
        'twilight' => 'Virtual Twilight',
        'mp' => 'Matterport 3D Experience',
    ];

    $categoryKey = (string)($state['category'] ?? '');
    $packageKey = (string)($state['package'] ?? '');
    if (!isset($packages[$packageKey])) {
        real_estate_invalid('Choose a package or individual services.');
    }
    $package = $packages[$packageKey];
    $bundled = $packageKey !== 'custom';
    if (!$bundled && !isset($categories[$categoryKey])) {
        real_estate_invalid('Choose a property category.');
    }
    $category = $categories[$categoryKey] ?? null;
    $sqft = $bundled ? 0 : real_estate_integer($state['sqft'] ?? null, $category['min'], $category['max'], 'Enter a whole-number property size within the selected category.');

    $selected = real_estate_selected($state, array_keys($services));
    $chosen = ['photo'];
    foreach (array_merge($package['includes'], $selected) as $key) {
        if (!in_array($key, $chosen, true)) {
            $chosen[] = $key;
        }
    }
    $matterportSqft = $bundled
        ? (in_array('mp', $chosen, true) ? real_estate_integer($state['matterportSqft'] ?? null, 1, 10000, 'Enter Matterport coverage from 1 to 10,000 sq ft.') : 0)
        : $sqft;
    $videoSeconds = in_array('video', $chosen, true) && !in_array('video', $package['includes'], true)
        ? real_estate_integer($state['videoSeconds'] ?? null, 60, 180, 'Choose a video length from 1:00 to 3:00.')
        : ($package['minutes'] * 60);
    $platformMonths = in_array('platform', $chosen, true)
        ? real_estate_integer($state['platformMonths'] ?? 6, 6, 18, 'Choose a platform term from 6 to 18 months.')
        : 0;
    $images = in_array('twilight', $chosen, true)
        ? real_estate_integer($state['images'] ?? null, 1, 100, 'Choose 1–100 twilight images.')
        : 0;

    $photoCents = 0;
    if (!$bundled) {
        if ($categoryKey === 'small') {
            $photoCents = max(15000, (int)round($sqft * 9.52, 0, PHP_ROUND_HALF_UP));
        } elseif ($categoryKey === 'average') {
            $photoCents = (int)round(24500 + ($sqft - 2000) * 1.75, 0, PHP_ROUND_HALF_UP);
        } elseif ($categoryKey === 'large') {
            $photoCents = (int)round(35000 + ($sqft - 4000) * 7.5, 0, PHP_ROUND_HALF_UP);
        } else {
            $photoCents = min(79500, (int)round(42500 + ($sqft - 5000) * 11.76, 0, PHP_ROUND_HALF_UP));
        }
    }
    $rates = [
        'photo' => $photoCents,
        'platform' => $platformMonths * 4900,
        'website' => 6500,
        'drone' => 12000,
        'zillow' => 9500,
        'video' => (int)round(22500 + ($videoSeconds - 60) * 12500 / 120, 0, PHP_ROUND_HALF_UP),
        'floor' => 5000,
        'twilight' => $images * 3500,
        'mp' => min(49900, max(6900, $matterportSqft * 6)),
    ];

    $subtotal = $package['cents'];
    $lines = [];
    foreach (array_keys($services) as $key) {
        if (!in_array($key, $chosen, true)) {
            continue;
        }
        $included = in_array($key, $package['includes'], true);
        $cents = $included ? 0 : $rates[$key];
        $subtotal += $cents;
        $label = $services[$key];
        if ($key === 'platform') {
            $label .= ' · ' . $platformMonths . ' Months at $49 / Month';
        } elseif ($key === 'video') {
            $label .= ' · ' . real_estate_video_duration($included ? $package['minutes'] * 60 : $videoSeconds);
        } elseif ($key === 'twilight') {
            $label .= ' · ' . $images . ($images === 1 ? ' image' : ' images');
        } elseif ($key === 'photo') {
            $label .= ' · ' . ($included ? $package['photos'] : $category['photos']);
        } elseif ($key === 'mp' && $bundled) {
            $label .= ' · ' . number_format($matterportSqft) . ' sq ft scanned';
        }
        $lines[] = ['key' => $key, 'label' => $label, 'included' => $included, 'cents' => $cents];
    }

    $photographyMinutes = $sqft * 35 / 1000;
    $matterportMinutes = in_array('mp', $chosen, true) ? $matterportSqft * 9 / 1000 : 0;
    $videoMinutes = 0;
    if (in_array('video', $chosen, true)) {
        $videoMinutes = in_array('video', $package['includes'], true) ? $package['minutes'] * 15 : $videoSeconds * 15 / 60;
    }
    $droneMinutes = in_array('drone', $chosen, true) ? 20 : 0;
    $additionalCapture = array_merge($bundled ? ['photo'] : [], array_values(array_filter(['zillow','floor'], static fn(string $key): bool => in_array($key, $chosen, true))));
    $knownMinutes = (int)(ceil(($photographyMinutes + $matterportMinutes + $videoMinutes + $droneMinutes) / 5) * 5);

    return [
        'market' => 'residential',
        'subject' => 'Residential SiteSee Real Estate Quote',
        'sqft' => $sqft,
        'category' => $bundled ? null : $category['label'],
        'package' => $package['label'],
        'packageCents' => $package['cents'],
        'platformMonths' => $platformMonths,
        'platformCents' => $platformMonths * 4900,
        'jobCents' => $subtotal - $platformMonths * 4900,
        'matterportSqft' => in_array('mp', $chosen, true) ? $matterportSqft : 0,
        'lines' => $lines,
        'subtotalCents' => $subtotal,
        'totalCents' => $subtotal,
        'photographyMinutes' => $photographyMinutes,
        'matterportMinutes' => $matterportMinutes,
        'videoMinutes' => $videoMinutes,
        'droneMinutes' => $droneMinutes,
        'knownMinutes' => $knownMinutes,
        'additionalCapture' => $additionalCapture,
        'services' => $services,
    ];
}

function real_estate_commercial_quote(array $state): array
{
    $categories = [
        'small' => ['label'=>'Small Commercial / Retail','min'=>1,'max'=>10000,'photoCents'=>75000,'photoMin'=>25,'included'=>30,'photoMax'=>35,'extraCents'=>3000],
        'mid' => ['label'=>'Warehouse / Office','min'=>10000,'max'=>50000,'photoCents'=>120000,'photoMin'=>30,'included'=>45,'photoMax'=>55,'extraCents'=>2670],
        'large' => ['label'=>'Factory / Industrial','min'=>50000,'max'=>250000,'photoCents'=>250000,'photoMin'=>45,'included'=>55,'photoMax'=>65,'extraCents'=>2500],
    ];
    $services = [
        'photo' => 'Property Photography',
        'platform' => 'SiteSee Platform',
        'mp' => 'Matterport 3D Experience',
        'views360' => 'Single 360° Views',
        'drone' => 'Drone / Aerial Photos',
        'video' => 'Cinematic B2B Video Walkthrough',
        'floor' => 'Schematic Floor Plans',
        'website' => 'Independent Property Website',
    ];
    $categoryKey = (string)($state['category'] ?? '');
    if (!isset($categories[$categoryKey])) {
        real_estate_invalid('Choose a commercial property category.');
    }
    $category = $categories[$categoryKey];
    $selected = real_estate_selected($state, array_keys($services));
    $chosen = ['photo'];
    foreach ($selected as $key) {
        if (!in_array($key, $chosen, true)) {
            $chosen[] = $key;
        }
    }
    $deliveryRequested = (string)($state['delivery'] ?? 'files');
    if (!in_array($deliveryRequested, ['files','website'], true)) {
        real_estate_invalid('Choose media files or an independent property website.');
    }
    if ($deliveryRequested === 'website' && !in_array('website', $chosen, true)) {
        $chosen[] = 'website';
    }

    $photoCount = real_estate_integer($state['photoCount'] ?? $category['included'], $category['photoMin'], $category['photoMax'], 'Choose a valid total photograph count for this category.');
    $extraPhotos = max(0, $photoCount - $category['included']);
    $extraPhotoCents = $extraPhotos * $category['extraCents'];
    $scanLimit = min(20000, $category['max']);
    $matterportSqft = in_array('mp', $chosen, true)
        ? real_estate_integer($state['matterportSqft'] ?? null, 1, $scanLimit, 'Choose a valid whole-number Matterport area.')
        : 0;
    $platformMonths = in_array('platform', $chosen, true)
        ? real_estate_integer($state['platformMonths'] ?? 6, 6, 18, 'Choose a platform term from 6 to 18 months.')
        : 0;
    if (in_array('views360', $chosen, true) && (!in_array('platform', $chosen, true) || !in_array('mp', $chosen, true))) {
        real_estate_invalid('Single 360° views require Matterport and a SiteSee platform subscription.');
    }
    $views = in_array('views360', $chosen, true)
        ? real_estate_integer($state['views360'] ?? null, 1, 100, 'Choose 1–100 individual 360° photos.')
        : 0;
    $images = in_array('drone', $chosen, true)
        ? real_estate_integer($state['aerialImages'] ?? null, 1, 100, 'Choose 1–100 aerial images.')
        : 0;
    $videos = in_array('video', $chosen, true)
        ? real_estate_integer($state['videos'] ?? null, 1, 20, 'Choose 1–20 finished videos.')
        : 0;
    $videoSeconds = in_array('video', $chosen, true)
        ? real_estate_integer($state['videoSeconds'] ?? null, 60, 180, 'Choose a video length from 1:00 to 3:00.')
        : 0;
    $plans = in_array('floor', $chosen, true)
        ? real_estate_integer($state['plans'] ?? null, 1, 20, 'Choose 1–20 property layout sets.')
        : 0;
    $hostingMonths = in_array('mp', $chosen, true)
        ? real_estate_integer($state['hostingMonths'] ?? 6, 6, 18, 'Choose a Matterport hosting term from 6 to 18 months.')
        : 0;
    $hostingPrepaid = $state['hostingPrepaid'] ?? false;
    if (!is_bool($hostingPrepaid)) {
        real_estate_invalid('Choose whether to pay for hosting in advance.');
    }
    $licenseType = (string)($state['licenseType'] ?? 'term');
    if (!in_array($licenseType, ['term','unlimited'], true)) {
        real_estate_invalid('Choose an extended license or an unlimited media license.');
    }
    $licenseMonths = $licenseType === 'term'
        ? real_estate_integer($state['licenseMonths'] ?? 6, 6, 18, 'Choose a total license term from 6 to 18 months.')
        : 6;

    $videoEachCents = $videos ? max(50000, (int)round($videoSeconds * 833.3, 0, PHP_ROUND_HALF_UP)) : 0;
    $fees = [
        'photo' => $category['photoCents'],
        'platform' => $platformMonths * 4900,
        'mp' => $matterportSqft ? max(19900, $matterportSqft * 10) : 0,
        'views360' => $views * 2500,
        'drone' => $images * 4200,
        'video' => $videos * $videoEachCents,
        'floor' => $plans * 15000,
        'website' => 17500,
    ];
    $lines = [];
    foreach (array_keys($services) as $key) {
        if (!in_array($key, $chosen, true)) {
            continue;
        }
        $label = $services[$key];
        if ($key === 'photo') {
            $label .= ' · ' . min($photoCount, $category['included']) . ' Photos';
        } elseif ($key === 'platform') {
            $label .= ' · ' . $platformMonths . ' Months at $49 / Month';
        } elseif ($key === 'mp') {
            $label .= ' · ' . number_format($matterportSqft) . ' sq ft scanned';
        } elseif ($key === 'views360') {
            $label .= ' · ' . $views . ($views === 1 ? ' photo' : ' photos');
        } elseif ($key === 'drone') {
            $label .= ' · ' . $images . ($images === 1 ? ' image' : ' images');
        } elseif ($key === 'video') {
            $label .= ' · ' . $videos . ($videos === 1 ? ' video' : ' videos') . ' · ' . real_estate_video_duration($videoSeconds) . ' each';
        } elseif ($key === 'floor') {
            $label .= ' · ' . $plans . ($plans === 1 ? ' layout set' : ' layout sets');
        }
        $lines[] = ['key'=>$key,'label'=>$label,'cents'=>$fees[$key],'included'=>false];
    }
    if ($extraPhotos) {
        array_splice($lines, 1, 0, [[
            'key'=>'extraPhotos',
            'label'=>'Additional Photography · ' . $extraPhotos . ' Photos at ' . real_estate_money($category['extraCents']) . ' Each',
            'cents'=>$extraPhotoCents,
            'included'=>false,
        ]]);
    }
    $subtotal = array_reduce($lines, static fn(int $sum, array $line): int => $sum + $line['cents'], 0);
    $mediaBase = $fees['photo'] + $extraPhotoCents + (in_array('drone', $chosen, true) ? $fees['drone'] : 0) + (in_array('video', $chosen, true) ? $fees['video'] : 0);
    $licenseCents = (int)round($licenseType === 'unlimited' ? $mediaBase / 2 : $mediaBase * max(0, $licenseMonths - 6) / 40, 0, PHP_ROUND_HALF_UP);
    $licenseLabel = $licenseType === 'unlimited' ? 'Unlimited Media License' : $licenseMonths . '-Month Media License · First Six Months Included';
    $lines[] = ['key'=>'license','label'=>$licenseLabel,'cents'=>$licenseCents,'included'=>$licenseCents === 0];
    $subtotal += $licenseCents;

    $hostingExtraMonths = in_array('mp', $chosen, true) ? $hostingMonths - 6 : 0;
    $hostingMonthlyCents = $hostingPrepaid ? 499 : 699;
    $hostingCents = $hostingExtraMonths * $hostingMonthlyCents;
    if (in_array('mp', $chosen, true)) {
        $hostingLabel = 'Matterport Hosting · ' . $hostingMonths . ' Months Total';
        $hostingLabel .= $hostingExtraMonths
            ? ' · ' . $hostingExtraMonths . ' Additional at ' . real_estate_money($hostingMonthlyCents) . '/Month · ' . ($hostingPrepaid ? 'Pay In Advance' : 'Billed Monthly')
            : ' · First Six Included';
        $lines[] = ['key'=>'hosting','label'=>$hostingLabel,'cents'=>$hostingCents,'included'=>$hostingExtraMonths === 0];
        $subtotal += $hostingCents;
    }

    $photographyMinutes = $category['min'] * 1.5 / 1000;
    $photographyMinutesMax = $category['max'] * 1.5 / 1000;
    $matterportMinutes = $matterportSqft * 9 / 1000;
    $videoMinutes = $videos * $videoSeconds * 15 / 60;
    $droneMinutes = $images ? 20 : 0;
    $knownMinutes = (int)(ceil(($photographyMinutes + $matterportMinutes + $videoMinutes + $droneMinutes) / 5) * 5);
    $knownMinutesMax = (int)(ceil(($photographyMinutesMax + $matterportMinutes + $videoMinutes + $droneMinutes) / 5) * 5);
    $additionalCapture = array_values(array_filter(['floor','views360'], static fn(string $key): bool => in_array($key, $chosen, true)));
    $delivery = in_array('platform', $chosen, true) ? 'platform' : (in_array('website', $chosen, true) ? 'website' : 'files');

    return [
        'market'=>'commercial',
        'subject'=>'Commercial SiteSee Real Estate Quote',
        'category'=>$category['label'],
        'lines'=>$lines,
        'subtotalCents'=>$subtotal,
        'totalCents'=>$subtotal,
        'delivery'=>$delivery,
        'photoCount'=>$photoCount,
        'views360'=>$views,
        'licenseType'=>$licenseType,
        'licenseMonths'=>$licenseMonths,
        'licenseCents'=>$licenseCents,
        'licenseBaseCents'=>$mediaBase,
        'matterportSqft'=>$matterportSqft,
        'matterportIncludedMonths'=>$matterportSqft ? 6 : 0,
        'hostingMonths'=>$hostingMonths,
        'hostingExtraMonths'=>$hostingExtraMonths,
        'hostingMonthlyCents'=>$matterportSqft ? $hostingMonthlyCents : 0,
        'hostingPrepaid'=>$matterportSqft > 0 && $hostingPrepaid,
        'hostingCents'=>$hostingCents,
        'photographyMinutes'=>$photographyMinutes,
        'photographyMinutesMax'=>$photographyMinutesMax,
        'matterportMinutes'=>$matterportMinutes,
        'videoMinutes'=>$videoMinutes,
        'droneMinutes'=>$droneMinutes,
        'knownMinutes'=>$knownMinutes,
        'knownMinutesMax'=>$knownMinutesMax,
        'additionalCapture'=>$additionalCapture,
        'services'=>$services,
    ];
}

function real_estate_quote_body(array $quote, array $details, array $appointment): string
{
    $lines = [
        $quote['subject'],
        '',
        'Agent: ' . $details['first'] . ' ' . $details['last'],
        'Company: ' . $details['company'],
        'Email: ' . $details['email'],
        'Phone: ' . $details['phone'],
        'Property: ' . $details['street'] . ', ' . $details['city'] . ', ' . $details['state'] . ' ' . $details['zip'],
    ];
    if ($quote['market'] === 'residential') {
        if ($quote['packageCents'] === 0) {
            $lines[] = 'Property size: ' . number_format($quote['sqft']) . ' sq ft';
            $lines[] = 'Category: ' . $quote['category'];
        }
        $lines[] = 'Selection: ' . $quote['package'];
        if ($quote['packageCents']) {
            $lines[] = 'Package: ' . real_estate_money($quote['packageCents']);
        }
        if ($quote['platformMonths']) {
            $lines[] = 'SiteSee Experience Platform: $49 per month; the full selected term is included in this estimate. Subscription payments are separate from the job.';
        }
    } else {
        $lines[] = 'Category: ' . $quote['category'];
        $lines[] = 'Requested photography: ' . $quote['photoCount'] . ' total photos';
    }

    foreach ($quote['lines'] as $line) {
        $lines[] = $line['label'] . ': ' . ($line['included'] ? 'Included' : real_estate_money($line['cents']));
    }
    if ($quote['market'] === 'commercial') {
        $deliveryLabels = ['files'=>'Media Files Only','website'=>'Independent Property Website','platform'=>'SiteSee Platform'];
        $lines[] = 'Delivery: ' . $deliveryLabels[$quote['delivery']];
        if ($quote['delivery'] === 'platform') {
            $lines[] = 'SiteSee platform: $49 per month; the full selected term is included in this estimate. Matterport hosting is separate.';
        }
        if ($quote['views360']) {
            $lines[] = 'Individual 360° photos are placed as views within the subscribed SiteSee Experience.';
        }
        $lines[] = 'Media licensing covers still photography, aerial images and videography. First six months included; extended terms charge only additional months. Matterport, individual 360° views, floor plans, website and platform fees are excluded from the license surcharge.';
    }

    $lines[] = '';
    $lines[] = 'Estimated total: ' . real_estate_money($quote['totalCents']);
    if ($quote['market'] === 'commercial' && $quote['matterportIncludedMonths']) {
        $lines[] = 'Matterport hosting: six months included. Selected term: ' . $quote['hostingMonths'] . ' months total; ' . $quote['hostingExtraMonths'] . ' additional months at ' . real_estate_money($quote['hostingMonthlyCents']) . '/month ' . ($quote['hostingPrepaid'] ? 'paid in advance' : 'billed monthly') . '. Hosting term cost: ' . real_estate_money($quote['hostingCents']) . '. This quote does not collect payment.';
    }
    $lines[] = 'Estimated Time On Site: ' . ($quote['market'] === 'commercial'
        ? real_estate_duration_range($quote['knownMinutes'], $quote['knownMinutesMax'])
        : (!$quote['knownMinutes'] && $quote['additionalCapture'] ? 'Confirmed With Your Appointment' : real_estate_duration($quote['knownMinutes'])));
    foreach ([['photographyMinutes','Photography'],['matterportMinutes','Matterport'],['videoMinutes','Video'],['droneMinutes','Drone / Aerial']] as [$key, $label]) {
        if ($quote[$key]) {
            $lines[] = $label . ': ' . ($quote['market'] === 'commercial' && $key === 'photographyMinutes'
                ? real_estate_duration_range($quote[$key], $quote['photographyMinutesMax'])
                : real_estate_duration($quote[$key]));
        }
    }
    if ($quote['market'] === 'commercial') {
        $lines[] = 'Photography time reflects the selected category’s size range.';
    }
    if ($quote['additionalCapture']) {
        $names = array_map(static fn(string $key): string => $quote['services'][$key], $quote['additionalCapture']);
        $lines[] = 'Time to be confirmed for: ' . implode(', ', $names);
    }
    $lines[] = 'Preferred date: ' . $appointment['date'];
    $lines[] = 'Preferred time: ' . $appointment['time'] . ' Central Time';
    $lines[] = 'Appointment is requested, not confirmed.';
    $lines[] = 'Exclude from mailing lists: ' . $details['optOut'];
    $lines[] = '';
    $lines[] = $quote['market'] === 'commercial'
        ? 'Final property scope, licensing and appointment availability are confirmed by SiteSee.'
        : 'Final property scope and appointment availability are confirmed by SiteSee.';
    return implode("\n", $lines);
}

function real_estate_prepare_submission(array $payload, ?DateTimeImmutable $now = null): array
{
    if (($payload['version'] ?? null) !== 1) {
        real_estate_invalid('Refresh the pricing page and try again.');
    }
    $action = (string)($payload['action'] ?? '');
    if (!in_array($action, ['email_quote','request_appointment'], true)) {
        real_estate_invalid('Choose a valid quote action.');
    }
    $market = (string)($payload['market'] ?? '');
    if (!in_array($market, ['residential','commercial'], true)) {
        real_estate_invalid('Choose residential or commercial pricing.');
    }
    $detailsSource = $payload['details'] ?? null;
    $appointmentSource = $payload['appointment'] ?? null;
    $state = $payload['state'] ?? null;
    if (!is_array($detailsSource) || !is_array($appointmentSource) || !is_array($state)) {
        real_estate_invalid('Complete the quote before sending it.');
    }
    $details = real_estate_validate_details($detailsSource);
    $appointment = real_estate_validate_appointment($appointmentSource, $now);
    $quote = $market === 'residential' ? real_estate_residential_quote($state) : real_estate_commercial_quote($state);
    return [
        'action'=>$action,
        'market'=>$market,
        'details'=>$details,
        'appointment'=>$appointment,
        'quote'=>$quote,
        'subject'=>$quote['subject'],
        'plain'=>real_estate_quote_body($quote, $details, $appointment),
    ];
}
