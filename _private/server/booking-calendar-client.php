<?php
declare(strict_types=1);
require_once __DIR__ . '/booking-availability.php';

/** Zoho's event route requires a literal @; encoded %40 returns a non-JSON 404. */
function booking_calendar_event_path(string $calendarUid, string $eventUid): string
{
    if (!preg_match('/^[A-Za-z0-9=_-]{1,128}$/D', $calendarUid)
        || !preg_match('/^[A-Za-z0-9@._-]{1,256}$/D', $eventUid)
        || in_array($eventUid, ['.', '..'], true)) {
        throw new BookingCalendarUnavailable('Calendar event identity is invalid.');
    }
    return '/api/v1/calendars/' . rawurlencode($calendarUid) . '/events/'
        . str_replace('%40', '@', rawurlencode($eventUid));
}

/** Private read-only connection. No CRM writes, calendar writes or invitations. */
function booking_calendar_config(?string $path = null): array
{
    $path ??= dirname(__DIR__) . '/zoho-calendar.json';
    $info = @lstat($path);
    if (!$info || ($info['mode'] & 0170000) !== 0100000 || ($info['mode'] & 0077) !== 0
        || $info['nlink'] !== 1 || $info['uid'] !== @fileowner(dirname($path)) || $info['size'] > 32768) {
        throw new BookingCalendarUnavailable('Calendar configuration is unavailable.');
    }
    $raw = @file_get_contents($path);
    $config = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($config) || ($config['schema_version'] ?? null) !== 1
        || ($config['setup'] ?? null) !== 'sitesee-calendar-readonly'
        || !is_bool($config['enabled'] ?? null) || ($config['timezone'] ?? null) !== 'America/Chicago'
        || ($config['accounts_base'] ?? null) !== 'https://accounts.zoho.com'
        || ($config['calendar_base'] ?? null) !== 'https://calendar.zoho.com'
        || !is_int($config['connection_verified_at'] ?? null) || $config['connection_verified_at'] <= 0) {
        throw new BookingCalendarUnavailable('Calendar configuration is unavailable.');
    }
    foreach (['client_id', 'client_secret', 'refresh_token', 'calendar_uid'] as $key) {
        if (!is_string($config[$key] ?? null) || $config[$key] === '' || strlen($config[$key]) > 4096
            || preg_match('/[^\x21-\x7e]/', $config[$key])) {
            throw new BookingCalendarUnavailable('Calendar configuration is unavailable.');
        }
    }
    return $config;
}

function booking_calendar_feedback_enabled(): bool
{
    try { return booking_calendar_config()['enabled']; }
    catch (Throwable) { return false; }
}

/** Fixed endpoints, TLS verification, no redirects, bounded time and response size. */
function booking_zoho_request(string $url, ?array $form = null, ?string $token = null): array
{
    $parts = parse_url($url);
    $oauth = $url === 'https://accounts.zoho.com/oauth/v2/token';
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
        || ($form !== null ? (!$oauth || $token !== null) :
            (($parts['host'] ?? '') !== 'calendar.zoho.com' || !str_starts_with($parts['path'] ?? '', '/api/v1/calendars/') || $token === null))
        || !function_exists('curl_init')) {
        throw new BookingCalendarUnavailable('Calendar request is unavailable.');
    }
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        if ($token === '' || preg_match('/[^\x21-\x7e]/', $token)) throw new BookingCalendarUnavailable('Calendar authorization is unavailable.');
        $headers[] = 'Authorization: Zoho-oauthtoken ' . $token;
    }
    $curl = curl_init($url);
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($form !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $success = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = json_decode($body, true);
    if ($success === false || !is_array($decoded)) throw new BookingCalendarUnavailable('Calendar could not be checked.');
    return ['status' => $status, 'body' => $decoded];
}

/** Private cache holds an OAuth token and busy intervals only; no event details. */
function booking_calendar_snapshot(array $config, array $range, ?callable $request = null, ?string $cachePath = null): array
{
    if (($config['enabled'] ?? false) !== true) throw new BookingCalendarUnavailable('Calendar feedback is disabled.');
    $request ??= 'booking_zoho_request';
    $cachePath ??= dirname(__DIR__) . '/zoho-calendar-runtime.json';
    $info = @lstat($cachePath);
    if ($info && (($info['mode'] & 0170000) !== 0100000 || ($info['mode'] & 0077) !== 0
        || $info['nlink'] !== 1 || $info['uid'] !== @fileowner(dirname($cachePath)))) {
        throw new BookingCalendarUnavailable('Calendar cache is unavailable.');
    }
    $oldMask = umask(0077);
    $handle = @fopen($cachePath, 'c+');
    umask($oldMask);
    if (!$handle) throw new BookingCalendarUnavailable('Calendar cache is unavailable.');
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) throw new BookingCalendarUnavailable('Calendar is being checked. Try again shortly.');
        $raw = stream_get_contents($handle, 2097153);
        if (!is_string($raw) || strlen($raw) > 2097152) throw new BookingCalendarUnavailable('Calendar cache is unavailable.');
        $cache = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($cache)) $cache = [];
        $identity = hash('sha256', $config['calendar_uid'] . "\0" . $config['client_id'] . "\0" . $config['refresh_token']);
        if (($cache['identity'] ?? '') !== $identity) $cache = ['identity' => $identity];
        $save = static function () use ($handle, &$cache): void {
            $data = json_encode($cache, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
                throw new BookingCalendarUnavailable('Calendar cache could not be saved.');
            }
        };
        $now = time();
        $snapshot = $cache['snapshot'] ?? null;
        if (is_array($snapshot) && ($cache['checked_at'] ?? 0) > $now - 30 && ($cache['checked_at'] ?? 0) <= $now
            && ($snapshot['start'] ?? null) === $range['start'] && ($snapshot['end'] ?? null) === $range['end']
            && is_array($snapshot['busy'] ?? null) && array_is_list($snapshot['busy'])) return $snapshot;
        if (!is_string($cache['access_token'] ?? null) || ($cache['token_expires'] ?? 0) < $now + 90) {
            $reply = $request('https://accounts.zoho.com/oauth/v2/token', [
                'grant_type' => 'refresh_token', 'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'], 'refresh_token' => $config['refresh_token'],
            ], null);
            $token = $reply['body']['access_token'] ?? null;
            $expires = $reply['body']['expires_in'] ?? null;
            if (($reply['status'] ?? null) !== 200 || !is_string($token) || $token === ''
                || preg_match('/[^\x21-\x7e]/', $token) || !is_numeric($expires) || (int)$expires < 120
                || array_key_exists('error', $reply['body'] ?? []) || array_key_exists('errors', $reply['body'] ?? [])) {
                throw new BookingCalendarUnavailable('Calendar authorization could not be refreshed.');
            }
            $cache['access_token'] = $token;
            $cache['token_expires'] = $now + min(3600, (int)$expires);
            $save();
        }
        $get = static function (string $path, array $query) use ($request, &$cache, $save): array {
            $reply = $request('https://calendar.zoho.com' . $path . '?' . http_build_query($query), null, $cache['access_token']);
            if (($reply['status'] ?? null) === 401) {
                unset($cache['access_token'], $cache['token_expires'], $cache['snapshot']);
                $save();
            }
            return $reply;
        };
        $snapshot = booking_calendar_read_busy($get, $config['calendar_uid'], $range);
        $cache['snapshot'] = $snapshot;
        $cache['checked_at'] = time();
        $save();
        return $snapshot;
    } catch (Throwable) {
        // No provider response bodies, credentials or appointment details in errors/logs.
        throw new BookingCalendarUnavailable('Calendar could not be checked.');
    } finally {
        fclose($handle);
    }
}
