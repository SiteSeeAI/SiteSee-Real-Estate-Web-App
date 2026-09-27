<?php
declare(strict_types=1);
/** Staged Microsoft connection. No booking controller calls this adapter yet. */
require_once __DIR__ . '/booking-mail-client.php';

const BOOKING_MS_MAILBOX = 'sales@re.sitesee.ai';
const BOOKING_MS_APP = '125a86f5-2a29-4b1e-8b8a-26026e3fa59c';
const BOOKING_MS_CALENDAR = 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0AhxzFTH7h2UC_-_f2aEjbcQAAAAA0UAAA';
const BOOKING_MS_BASE = '/v1.0/users/sales%40re.sitesee.ai';

final class BookingMicrosoftCalendarError extends RuntimeException {}

function booking_ms_need(bool $condition, string $message): void
{
    if (!$condition) throw new BookingMicrosoftCalendarError($message);
}

function booking_ms_private_json(string $path): array
{
    clearstatcache(true, $path);
    $s = @lstat($path); $parent = @stat(dirname($path));
    booking_ms_need(is_array($s) && is_array($parent) && ($s['mode'] & 0170000) === 0100000
        && ($s['mode'] & 0077) === 0 && $s['nlink'] === 1 && $s['uid'] === $parent['uid']
        && $s['size'] <= 65536, 'Private Microsoft connection file is missing or unsafe.');
    $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    booking_ms_need(is_array($data), 'Private Microsoft connection file is invalid.');
    return $data;
}

function booking_ms_validate_config(array $c): void
{
    booking_ms_need(($c['schema'] ?? null) === 1 && ($c['stage'] ?? '') === 'test'
        && ($c['mailbox'] ?? '') === BOOKING_MS_MAILBOX && ($c['application_id'] ?? '') === BOOKING_MS_APP
        && ($c['calendar_id'] ?? '') === BOOKING_MS_CALENDAR
        && ($c['graph_credentials'] ?? '') === '/home/sitesee/.sitesee-graph-mail.json'
        && ($c['scheduling_enabled'] ?? null) === false && ($c['invitations_enabled'] ?? null) === false,
        'Microsoft connection identity or disabled TEST gates differ.');
}

function booking_ms_calendar_path(): string
{
    return BOOKING_MS_BASE . '/calendars/' . rawurlencode(BOOKING_MS_CALENDAR);
}

function booking_ms_event_path(string $id): string
{
    booking_ms_need((bool)preg_match('/^[\x21-\x7e]{1,2048}$/D', $id), 'Microsoft event identifier is invalid.');
    return booking_ms_calendar_path() . '/events/' . rawurlencode($id);
}

function booking_ms_mailbox_event_path(string $id): string
{
    booking_ms_event_path($id);
    return BOOKING_MS_BASE . '/events/' . rawurlencode($id);
}

/** Next links are opaque, but must stay on the same pinned calendar collection. */
function booking_ms_path(string $path, ?string $collection = null): string
{
    booking_ms_need(strlen($path) <= 16384 && !preg_match('/[^\x21-\x7e]/', $path), 'Microsoft request path is invalid.');
    $u = parse_url($path);
    booking_ms_need(is_array($u) && !isset($u['fragment'])
        && !isset($u['user']) && !isset($u['pass']) && !isset($u['port'])
        && ((!isset($u['host']) && !isset($u['scheme']))
            || (($u['scheme'] ?? '') === 'https' && ($u['host'] ?? '') === 'graph.microsoft.com')),
        'Microsoft request left the authorized host.');
    $p = $u['path'] ?? '';
    $decoded = rawurldecode($p); $base = rawurldecode(booking_ms_calendar_path());
    $allowed = $collection !== null ? $decoded === rawurldecode($collection)
        : in_array($decoded, [rawurldecode(BOOKING_MS_BASE . '/calendar'), $base, $base . '/calendarView', $base . '/events'], true)
            || (str_starts_with($p, booking_ms_calendar_path() . '/events/')
                && (bool)preg_match('#^[A-Za-z0-9_.~%+-]+$#D', substr($p, strlen(booking_ms_calendar_path() . '/events/'))))
            || (str_starts_with($p, BOOKING_MS_BASE . '/events/')
                && (bool)preg_match('#^[A-Za-z0-9_.~%+-]+$#D', substr($p, strlen(BOOKING_MS_BASE . '/events/'))));
    booking_ms_need($allowed, 'Microsoft request left the authorized mailbox/calendar endpoints.');
    return $p . (isset($u['query']) ? '?' . $u['query'] : '');
}

function booking_ms_connection(array $config, array $secret, ?callable $http = null): Closure
{
    booking_ms_validate_config($config);
    booking_ms_need((bool)preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', (string)($secret['tenant_id'] ?? ''))
        && strtolower((string)($secret['client_id'] ?? '')) === BOOKING_MS_APP
        && is_string($secret['client_secret'] ?? null) && $secret['client_secret'] !== '', 'Existing Microsoft application identity differs.');
    $http ??= 'booking_provider_http';
    $r = $http('POST', 'https://login.microsoftonline.com/' . $secret['tenant_id'] . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id'=>$secret['client_id'], 'client_secret'=>$secret['client_secret'],
            'scope'=>'https://graph.microsoft.com/.default', 'grant_type'=>'client_credentials']));
    $token = $r['body']['access_token'] ?? null;
    booking_ms_need(($r['status'] ?? 0) === 200 && is_string($token)
        && (bool)preg_match('/^[\x21-\x7e]{1,32768}$/D', $token), 'Microsoft authentication failed.');
    return static function (string $method, string $path, ?array $body = null) use ($http, $token): array {
        $safe = booking_ms_path($path); $plain = explode('?', $safe, 2)[0];
        $collection = booking_ms_calendar_path() . '/events';
        $allowed = $method === 'GET' && $body === null;
        if ($method === 'POST' && $safe === $collection && is_array($body)) {
            $tx = $body['transactionId'] ?? '';
            $start = isset($body['start']) ? booking_ms_timestamp($body['start']) : 0;
            $allowed = is_string($tx) && (bool)preg_match('/^[a-f0-9]{32}$/D', $tx)
                && $body === booking_ms_probe_payload($tx, $start);
        }
        if ($method === 'DELETE') $allowed = $safe === $plain && str_starts_with($plain, $collection . '/') && $body === null;
        booking_ms_need($allowed, 'Microsoft calendar operation is outside the staged connection.');
        return $http($method, 'https://graph.microsoft.com' . $safe,
            ['Authorization: Bearer ' . $token, 'Prefer: IdType="ImmutableId", outlook.timezone="UTC"',
             'Accept: application/json', 'Content-Type: application/json'],
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    };
}

function booking_ms_ok(array $reply, string $label, int $expected = 200): array
{
    $status = (int)($reply['status'] ?? 0);
    booking_ms_need($status === $expected && is_array($reply['body'] ?? null) && !isset($reply['body']['error']),
        $label . ' failed (HTTP ' . $status . ').');
    return $reply['body'];
}

function booking_ms_verify_calendar(callable $request): void
{
    foreach ([BOOKING_MS_BASE . '/calendar', booking_ms_calendar_path()] as $path) {
        $c = booking_ms_ok($request('GET', $path . '?%24select=id,owner,canEdit,isDefaultCalendar'), 'Calendar identity read');
        booking_ms_need(($c['id'] ?? '') === BOOKING_MS_CALENDAR && ($c['canEdit'] ?? null) === true
            && ($c['isDefaultCalendar'] ?? null) === true
            && strtolower($c['owner']['address'] ?? '') === BOOKING_MS_MAILBOX, 'Default calendar identity or editable flag differs.');
    }
}

/** UTC is explicitly requested; never guess at a missing or unexpected time zone. */
function booking_ms_timestamp(array $value): int
{
    $raw = $value['dateTime'] ?? null;
    booking_ms_need(($value['timeZone'] ?? '') === 'UTC' && is_string($raw)
        && (bool)preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,7})?(?:Z|\+00:00)?$/D', $raw), 'Microsoft returned an unsupported event time.');
    $base = substr($raw, 0, 19);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $base, new DateTimeZone('UTC'));
    booking_ms_need($d !== false && $d->format('Y-m-d\TH:i:s') === $base, 'Microsoft returned an invalid event time.');
    return $d->getTimestamp();
}

function booking_ms_view(callable $request, array $range, string $fields): array
{
    booking_ms_need(is_int($range['start'] ?? null) && is_int($range['end'] ?? null)
        && $range['start'] < $range['end'] && $range['end'] - $range['start'] <= 31 * 86400,
        'Microsoft availability range is invalid.');
    $collection = booking_ms_calendar_path() . '/calendarView';
    $path = $collection . '?' . http_build_query(['startDateTime'=>gmdate('Y-m-d\TH:i:s\Z', $range['start']),
        'endDateTime'=>gmdate('Y-m-d\TH:i:s\Z', $range['end']), '$select'=>$fields, '$top'=>100]);
    $seen = []; $items = [];
    for ($page = 0; $page < 100; $page++) {
        $path = booking_ms_path($path, $collection);
        booking_ms_need(!isset($seen[$path]), 'Microsoft calendar repeated a page; availability is incomplete.');
        $seen[$path] = true;
        $data = booking_ms_ok($request('GET', $path), 'Calendar event read');
        booking_ms_need(is_array($data['value'] ?? null) && array_is_list($data['value']), 'Microsoft event page is incomplete.');
        foreach ($data['value'] as $item) {
            booking_ms_need(is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '', 'Microsoft event page contains an invalid record.');
            booking_ms_need(!isset($items[$item['id']]), 'Microsoft event appeared twice; retry the complete read.');
            $items[$item['id']] = $item;
        }
        if (!array_key_exists('@odata.nextLink', $data)) return array_values($items);
        booking_ms_need(is_string($data['@odata.nextLink']) && $data['@odata.nextLink'] !== '', 'Microsoft continuation is invalid.');
        $path = $data['@odata.nextLink'];
    }
    throw new BookingMicrosoftCalendarError('Microsoft calendar exceeds the page limit; availability is incomplete.');
}

/** Provider-only snapshot. The activation release must add local reservations. */
function booking_ms_snapshot(callable $request, array $range): array
{
    $busy = [];
    foreach (booking_ms_view($request, $range, 'id,start,end,showAs,isCancelled,type') as $event) {
        booking_ms_need(is_bool($event['isCancelled'] ?? null)
            && in_array($event['type'] ?? '', ['singleInstance','occurrence','exception'], true), 'Microsoft event status is incomplete.');
        if ($event['isCancelled']) continue;
        booking_ms_need(in_array($event['showAs'] ?? '', ['free','tentative','busy','oof','workingElsewhere','unknown'], true), 'Microsoft event availability is incomplete.');
        if ($event['showAs'] === 'free') continue;
        $start = booking_ms_timestamp($event['start'] ?? []); $end = booking_ms_timestamp($event['end'] ?? []);
        booking_ms_need($end > $start, 'Microsoft returned an invalid event duration.');
        if ($start < $range['end'] && $end > $range['start']) $busy[] = ['start'=>$start, 'end'=>$end];
    }
    usort($busy, static fn($a, $b) => $a['start'] <=> $b['start']);
    return ['start'=>$range['start'], 'end'=>$range['end'], 'busy'=>$busy];
}

function booking_ms_probe_payload(string $tx, int $start): array
{
    return ['subject'=>'SiteSee TEST calendar connection ' . $tx,
        'body'=>['contentType'=>'text', 'content'=>'Temporary connection check. No customer or booking. Do not edit.'],
        'start'=>['dateTime'=>gmdate('Y-m-d\TH:i:s', $start), 'timeZone'=>'UTC'],
        'end'=>['dateTime'=>gmdate('Y-m-d\TH:i:s', $start + 300), 'timeZone'=>'UTC'],
        'transactionId'=>$tx, 'attendees'=>[], 'sensitivity'=>'private', 'showAs'=>'free',
        'isReminderOn'=>false, 'isOnlineMeeting'=>false, 'isAllDay'=>false, 'responseRequested'=>false,
        'allowNewTimeProposals'=>false];
}
