<?php
declare(strict_types=1);

/** Exercise the actual handler over localhost with mail and booking capture stubbed. */
$temp = sys_get_temp_dir() . '/sitesee-receipts-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
mkdir($temp . '/server', 0700);
mkdir($temp . '/sessions', 0700);
copy(__DIR__ . '/../_private/server/quote-submit.php', $temp . '/server/quote-submit.php');
copy(__DIR__ . '/../_private/server/quote-receipts.php', $temp . '/server/quote-receipts.php');
copy(__DIR__ . '/../_private/real-estate-pricing.php', $temp . '/real-estate-pricing.php');
file_put_contents($temp . '/real-estate-form-config.php', <<<'PHP'
<?php
define('SITESEE_REAL_ESTATE_SALES_EMAIL', 'sales@example.com');
function fixture_event(string $kind): void {
    file_put_contents(__DIR__ . '/events.ndjson', json_encode(['kind'=>$kind]) . "\n", FILE_APPEND | LOCK_EX);
}
function real_estate_same_origin(): bool { return true; }
function real_estate_pricing_has_access(): bool {
    session_save_path(__DIR__ . '/sessions');
    session_name('sitesee_receipts_test');
    session_start();
    return ($_SERVER['HTTP_X_TEST_EXPIRED'] ?? '') !== '1';
}
function real_estate_rate_allowed(string $ip, string $email): bool {
    fixture_event('rate');
    return ($_SERVER['HTTP_X_TEST_RATE_DENY'] ?? '') !== '1';
}
function real_estate_send_mail(string $to, string $reply, string $subject, string $html, string $plain): bool {
    fixture_event('mail');
    if (($_SERVER['HTTP_X_TEST_FAIL_MAIL'] ?? '') === '1') return false;
    return !(($_SERVER['HTTP_X_TEST_FAIL_COPY'] ?? '') === '1' && $to !== SITESEE_REAL_ESTATE_SALES_EMAIL);
}
PHP
);
file_put_contents($temp . '/server/booking-store.php', <<<'PHP'
<?php
function booking_test_enabled(): bool { return true; }
function booking_db() { return null; }
function booking_capture($db, array $submission, string $reference, bool $depositFirst = false): ?string { fixture_event('booking'); return $depositFirst ? str_repeat('a', 64) : null; }
PHP
);

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) throw new RuntimeException($message);
};
$events = static function () use ($temp): array {
    $counts = ['mail'=>0, 'booking'=>0, 'rate'=>0];
    foreach (is_file($temp . '/events.ndjson') ? file($temp . '/events.ndjson', FILE_IGNORE_NEW_LINES) : [] as $line) {
        $event = json_decode($line, true);
        $counts[$event['kind']]++;
    }
    return $counts;
};
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException('Cannot reserve localhost test port: ' . $error);
$address = stream_socket_get_name($listener, false);
fclose($listener);
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $temp], [
    0=>['file', '/dev/null', 'r'],
    1=>['file', $temp . '/server.log', 'a'],
    2=>['file', $temp . '/server.log', 'a'],
], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start isolated receipt test server.');
try {
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(50000);
    }
    $assert($ready, 'Isolated test server starts.');
    $post = static function (array $payload, string $session, array $extraHeaders = []) use ($address): array {
        $headers = array_merge([
            'Content-Type: application/json',
            'Cookie: sitesee_receipts_test=' . $session,
            'Connection: close',
        ], $extraHeaders);
        $context = stream_context_create(['http'=>[
            'method'=>'POST', 'header'=>implode("\r\n", $headers),
            'content'=>json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors'=>true, 'timeout'=>5,
        ]]);
        $raw = file_get_contents('http://' . $address . '/server/quote-submit.php', false, $context);
        preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $status);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new RuntimeException('Non-JSON fixture response: ' . $raw);
        return ['status'=>(int)($status[1] ?? 0), 'data'=>$data];
    };
    $base = [
        'version'=>1, 'action'=>'email_quote',
        'details'=>[
            'first'=>'Test', 'last'=>'Agent', 'company'=>'Example Realty', 'email'=>'agent@example.com',
            'phone'=>'4145550100', 'street'=>'123 Example Street', 'unit'=>'', 'propertyId'=>'',
            'city'=>'Milwaukee', 'state'=>'WI', 'zip'=>'53202', 'optOut'=>'Yes',
        ],
        'appointment'=>['date'=>'2099-01-15', 'time'=>'10:00', 'meetPhotographer'=>'Yes', 'cancellationAccepted'=>true],
    ];
    foreach (['residential', 'commercial'] as $market) {
        $payload = $base;
        $payload['market'] = $market;
        $payload['state'] = $market === 'residential'
            ? ['category'=>'average', 'package'=>'custom', 'sqft'=>'2680', 'matterportSqft'=>'',
                'selected'=>['photo'], 'videoSeconds'=>60, 'images'=>1]
            : ['category'=>'mid', 'photoCount'=>'45', 'matterportSqft'=>'20000', 'views360'=>'0',
                'selected'=>['photo'], 'videoSeconds'=>60, 'videos'=>'1', 'aerialImages'=>'1',
                'delivery'=>'files', 'platformMonths'=>'6', 'plans'=>'1', 'licenseType'=>'term',
                'licenseMonths'=>'6', 'hostingMonths'=>'6', 'hostingPrepaid'=>false];
        $before = $events();
        $first = $post($payload, $market . 'email');
        $assert($first['status'] === 200 && $first['data']['ok'] === true, $market . ': first email succeeds.');
        $again = $post($payload, $market . 'email', ['X-Test-Rate-Deny: 1']);
        $assert($again['data']['reference'] === $first['data']['reference'] && $again['data']['replayed'] === true, $market . ': repeat email reuses receipt before rate limit.');
        $assert($events()['mail'] === $before['mail'] + 1, $market . ': repeated email only sends once.');
        $assert($events()['rate'] === $before['rate'] + 1, $market . ': repeat does not consume rate allowance.');

        $changed = $payload; $changed['details']['street'] = '456 Different Street';
        $different = $post($changed, $market . 'email');
        $assert($different['data']['reference'] !== $first['data']['reference'], $market . ': changed property gets a new receipt.');
        $otherSession = $post($payload, $market . 'other');
        $assert($otherSession['data']['reference'] !== $first['data']['reference'], $market . ': receipt does not cross pricing sessions.');
        $expired = $post($payload, $market . 'email', ['X-Test-Expired: 1']);
        $assert($expired['status'] === 403, $market . ': expired pricing access cannot replay a receipt.');

        $request = $payload; $request['action'] = 'request_appointment';
        $before = $events();
        $accepted = $post($request, $market . 'request');
        $assert($accepted['status'] === 200 && $accepted['data']['copy_sent'] === true, $market . ': appointment receipt succeeds.');
        $repeat = $post($request, $market . 'request');
        $assert($repeat['data']['reference'] === $accepted['data']['reference'], $market . ': repeated appointment keeps reference.');
        $assert($events()['booking'] === $before['booking'] + 1, $market . ': successful repeat captures only one booking.');
        $assert($events()['mail'] === $before['mail'] + 2, $market . ': successful repeat sends only sales and customer emails.');
        $emailAfterRequest = $post($payload, $market . 'request');
        $assert($emailAfterRequest['data']['reference'] === $accepted['data']['reference'] && $emailAfterRequest['data']['action'] === 'email_quote', $market . ': request copy also satisfies email-quote action.');
        $assert($events()['mail'] === $before['mail'] + 2, $market . ': email button after request cannot send an unnecessary copy.');

        $before = $events();
        $failed = $post($payload, $market . 'retry', ['X-Test-Fail-Mail: 1']);
        $retried = $post($payload, $market . 'retry');
        $assert($failed['status'] === 503 && $retried['status'] === 200, $market . ': failed standalone email can retry.');
        $assert($events()['mail'] === $before['mail'] + 2, $market . ': failure is not cached as success.');

        $before = $events();
        $withoutCopy = $post($request, $market . 'nocopy', ['X-Test-Fail-Copy: 1']);
        $withoutCopyAgain = $post($request, $market . 'nocopy');
        $assert($withoutCopy['data']['copy_sent'] === false && $withoutCopyAgain['data']['copy_sent'] === false, $market . ': missing copy stays honest on replay.');
        $assert($events()['mail'] === $before['mail'] + 2 && $events()['booking'] === $before['booking'] + 1, $market . ': no repeat sales email or booking when only copy failed.');
        $recoverCopy = $post($payload, $market . 'nocopy');
        $assert($recoverCopy['status'] === 200 && $events()['mail'] === $before['mail'] + 3, $market . ': separate email action remains available when request copy failed.');
    }
    $depositRequest = $request;
    $depositRequest['version'] = 2;
    $depositRequest['appointment']['time'] = '09:00';
    $before = $events();
    $firstDeposit = $post($depositRequest, 'depositmailfailure', ['X-Test-Fail-Mail: 1']);
    $secondDeposit = $post($depositRequest, 'depositmailfailure');
    $assert($firstDeposit['status'] === 200 && $firstDeposit['data']['copy_sent'] === false, 'Saved deposit request survives all mail failures honestly.');
    $assert(str_starts_with($firstDeposit['data']['payment_url'], '/booking-pay.php?reference='), 'New flow returns payment link before staff approval.');
    $assert($secondDeposit['data']['payment_url'] === $firstDeposit['data']['payment_url'], 'Retry after mail failure preserves same payment link.');
    $assert($events()['booking'] === $before['booking'] + 1, 'Mail failure does not create duplicate booking.');
    $assert($events()['mail'] === $before['mail'] + 2, 'Replay does not repeat failed request emails.');
    echo "Quote receipt handler: $count assertions passed.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}

