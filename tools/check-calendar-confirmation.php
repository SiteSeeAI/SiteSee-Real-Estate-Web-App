<?php
declare(strict_types=1);
/** Read-only release and connection probe. Does not open the booking ledger. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = is_dir(dirname(__DIR__) . '/_private/server') ? dirname(__DIR__) . '/_private' : dirname(__DIR__);
$temp = null;
try {
    if (PHP_VERSION_ID < 80200 || !extension_loaded('curl') || !extension_loaded('pdo_sqlite')) throw new RuntimeException('PHP 8.2+, cURL and PDO SQLite are required.');
    $manifest = json_decode((string)file_get_contents($root . '/calendar-confirmation-release.json'), true, 16, JSON_THROW_ON_ERROR);
    if (($manifest['release'] ?? '') !== 'sitesee-calendar-confirmation-test-v1' || !is_array($manifest['files'] ?? null)) throw new RuntimeException('Release manifest is missing or invalid.');
    foreach ($manifest['files'] as $path => $hash) {
        if (!preg_match('~^(?:server|tools)/[a-z0-9.-]+\.(?:php|py)$~D', $path) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Release manifest path is invalid.');
        $raw = @file_get_contents($root . '/' . $path);
        if (!is_string($raw) || !hash_equals($hash, hash('sha256', str_replace("\r\n", "\n", $raw)))) throw new RuntimeException('Uploaded release file mismatch: ' . $path);
        if (str_ends_with($path, '.php')) token_get_all($raw, TOKEN_PARSE);
    }
    echo 'Uploaded confirmation files and PHP syntax: PASS (' . count($manifest['files']) . " files)\n";
    require_once $root . '/server/booking-calendar-client.php';
    $readerPath = $root . '/zoho-calendar.json';
    $original = hash_file('sha256', $readerPath);
    $reader = booking_calendar_config($readerPath);
    if (!$reader['enabled']) throw new RuntimeException('The verified availability connection is not enabled.');
    $temp = sys_get_temp_dir() . '/sitesee-confirm-probe-' . bin2hex(random_bytes(12));
    if (!mkdir($temp, 0700)) throw new RuntimeException('Private probe directory is unavailable.');
    $date = (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->format('Y-m-d');
    booking_calendar_snapshot($reader, booking_availability_range($date, 60, 2), null, $temp . '/reader.json');
    echo "Existing availability connection: PASS\n";
    $writerPath = $root . '/zoho-confirmation.json';
    if (file_exists($writerPath)) {
        $writer = booking_calendar_config($writerPath);
        if ($writer['calendar_uid'] !== $reader['calendar_uid'] || ($writer['confirmation_stage'] ?? '') !== 'test'
            || ($writer['calendar_owner_id'] ?? '') !== ($reader['calendar_owner_id'] ?? '')
            || !is_bool($writer['confirmation_enabled'] ?? null) || !is_bool($writer['invitations_enabled'] ?? null)
            || !filter_var($writer['test_recipient_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !in_array('ZohoCalendar.event.CREATE', $writer['requested_scopes'] ?? [], true)) {
            throw new RuntimeException('Writer configuration does not match the test stage.');
        }
        booking_calendar_snapshot($writer, booking_availability_range($date, 60, 2), null, $temp . '/writer.json');
        echo "Separate writer identity and read access: PASS\n";
        echo 'Calendar confirmation: ' . ($writer['confirmation_enabled'] ? 'ENABLED' : 'DISABLED') . ".\n";
        echo 'Invitations: ' . ($writer['invitations_enabled'] ? 'ENABLED' : 'DISABLED') . ".\n";
        echo "Event creation still requires the controlled staff test.\n";
    } else echo "Writer connection not configured yet. Calendar confirmation and invitations remain DISABLED.\n";
    if (!hash_equals($original, hash_file('sha256', $readerPath))) throw new RuntimeException('Active availability configuration changed concurrently.');
    echo "Existing availability credentials unchanged: PASS\nNo bookings, calendar events, invitations, payment settings or mail settings were changed.\n";
} catch (Throwable $error) {
    // Only our own bounded release-path diagnostics are suitable for output.
    $message = $error instanceof RuntimeException && str_starts_with($error->getMessage(), 'Uploaded release file mismatch:')
        ? $error->getMessage() : 'Confirmation verification failed. Leave the new controls disabled and report this output.';
    fwrite(STDERR, 'STOP: ' . $message . "\n");
    $failed = true;
} finally {
    if ($temp && is_dir($temp)) { foreach (glob($temp . '/*') as $file) unlink($file); rmdir($temp); }
}
exit(empty($failed) ? 0 : 1);
