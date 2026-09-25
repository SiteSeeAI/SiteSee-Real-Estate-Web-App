<?php
declare(strict_types=1);
/** Read-only deployment check, deliberately usable while feedback is disabled. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = is_dir(dirname(__DIR__) . '/_private/server') ? dirname(__DIR__) . '/_private' : dirname(__DIR__);
require_once $root . '/server/booking-calendar-client.php';
require_once $root . '/server/booking-feedback.php';
$directory = null;
$exitCode = 0;
try {
    $config = booking_calendar_config($root . '/zoho-calendar.json');
    echo "Private connection configuration: PASS\n";
    $enabled = $config['enabled'];
    $config['enabled'] = true; // In-memory probe only. The configuration file is never written.
    $directory = sys_get_temp_dir() . '/sitesee-calendar-probe-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Could not create temporary private probe directory.');
    $range = booking_availability_range('2026-09-29', 70, 6);
    $snapshot = booking_calendar_snapshot($config, $range, null, $directory . '/runtime.json');
    $expected = [
        ['2026-09-29T09:00:00-05:00','2026-09-29T10:00:00-05:00'],
        ['2026-09-30T00:00:00-05:00','2026-10-01T00:00:00-05:00'],
        ['2026-10-02T09:00:00-05:00','2026-10-02T10:00:00-05:00'],
        ['2026-10-03T09:00:00-05:00','2026-10-03T10:00:00-05:00'],
        ['2026-10-04T09:00:00-05:00','2026-10-04T10:00:00-05:00'],
    ];
    foreach ($expected as $times) {
        $interval = array_map(static fn(string $value): int => (new DateTimeImmutable($value))->getTimestamp(), $times);
        if (!in_array($interval, $snapshot['busy'], true)) throw new RuntimeException('A known test interval is missing or changed; leave test events in place.');
    }
    echo "Timed event, all-day boundaries and three recurring instances: PASS\n";
    $payload = ['market'=>'residential','date'=>'2026-09-30','time'=>'09:00','rush'=>false,
        'state'=>['category'=>'average','package'=>'custom','sqft'=>2000,'selected'=>['photo']]];
    // Isolate just the full-day fixture for deterministic planner validation.
    $allDayInterval = array_map(static fn(string $value): int => (new DateTimeImmutable($value))->getTimestamp(), $expected[1]);
    $reader = static fn(array $range): array => $range + ['busy' => [$allDayInterval]];
    $result = booking_feedback($payload, $reader, new DateTimeImmutable('2026-09-25T09:00:00-05:00'));
    if ($result['selected_available'] !== false || $result['date_windows'] !== [] || ($result['alternatives'][0]['date'] ?? '') !== '2026-10-01') {
        throw new RuntimeException('Full-day alternative selection failed.');
    }
    echo "Server-calculated duration and next-day alternative: PASS\n";
    echo 'Availability feedback remains ' . ($enabled ? 'ENABLED' : 'DISABLED') . ".\n";
    echo "No configuration, calendar events, invitations or payment settings were changed.\n";
} catch (Throwable $error) {
    // Fixed output avoids credentials or private event details from any upstream error.
    fwrite(STDERR, "STOP: Calendar feedback verification failed. Keep feedback disabled and report this message.\n");
    $exitCode = 1;
} finally {
    if ($directory !== null && is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}
exit($exitCode);
