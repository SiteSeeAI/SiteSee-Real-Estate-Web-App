<?php
declare(strict_types=1);

const BOOKING_MAIL_SENDER = 'sales@re.sitesee.ai';

/** Fixed provider hosts, no redirects, bounded responses, no credentials in errors. */
function booking_provider_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $host = parse_url($url, PHP_URL_HOST);
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host,
        ['graph.microsoft.com', 'login.microsoftonline.com', 'accounts.zoho.com', 'www.zohoapis.com'], true)) {
        throw new RuntimeException('Provider host is not permitted.');
    }
    $curl = curl_init($url); $reply = ''; $requestId = '';
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>25, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function ($c, string $chunk) use (&$reply): int {
            if (strlen($reply) + strlen($chunk) > 2097152) return 0;
            $reply .= $chunk; return strlen($chunk);
        }, CURLOPT_HEADERFUNCTION=>static function ($c, string $line) use (&$requestId): int {
            if (preg_match('/^request-id:\s*([a-zA-Z0-9-]+)\s*$/i', trim($line), $m)) $requestId = $m[1];
            return strlen($line);
        }]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    try { $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); }
    finally { curl_close($curl); }
    if ($ok === false) throw new RuntimeException('Provider response was not received.');
    return ['status'=>$status, 'body'=>$reply === '' ? [] : json_decode($reply, true, 64, JSON_THROW_ON_ERROR), 'request_id'=>$requestId];
}

function booking_private_json(string $path): array
{
    if (is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 65536) {
        throw new RuntimeException('Private integration configuration is unavailable or permissions are unsafe.');
    }
    $config = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) throw new RuntimeException('Private integration configuration is invalid.');
    return $config;
}

function booking_mail_config(bool $sending = false): array
{
    $c = booking_private_json(dirname(__DIR__) . '/booking-mail.json');
    if (($c['sender'] ?? '') !== BOOKING_MAIL_SENDER || ($c['stage'] ?? '') !== 'test'
        || ($c['graph_credentials'] ?? '') !== '/home/sitesee/.sitesee-graph-mail.json'
        || ($c['test_recipient_email'] ?? '') !== 'sales@re.sitesee.ai'
        || !is_bool($c['enabled'] ?? null) || ($sending && !$c['enabled'])) {
        throw new InvalidArgumentException('The dedicated booking mail path is not enabled or verified.');
    }
    return $c;
}

function booking_graph_client(array $config): callable
{
    $secret = booking_private_json($config['graph_credentials']);
    foreach (['tenant_id','client_id'] as $key) {
        if (!preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', (string)($secret[$key] ?? ''))) {
            throw new RuntimeException('Existing Graph application identity is invalid.');
        }
    }
    if (empty($secret['client_secret'])) throw new RuntimeException('Existing Graph credential is missing.');
    $r = booking_provider_http('POST', 'https://login.microsoftonline.com/' . $secret['tenant_id'] . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id'=>$secret['client_id'], 'client_secret'=>$secret['client_secret'],
            'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']));
    if ($r['status'] !== 200 || empty($r['body']['access_token'])) throw new RuntimeException('Graph authentication failed.');
    $token = $r['body']['access_token'];
    return static function (string $method, string $path, ?string $body = null, ?string $ifMatch = null) use ($token): array {
        // Only booking sender and the expressly designated test recipient/original sender.
        if (!preg_match('~^/users/(sales%40re\.sitesee\.ai|cro%40sitesee\.ai)/(messages|mailFolders/[^/?]+/messages)(?:[/?].*)?$~D', $path)
            || !in_array($method, ['GET','POST','PATCH'], true)
            || ($method === 'POST' && !str_starts_with($path, '/users/sales%40re.sitesee.ai/messages'))) {
            throw new RuntimeException('Graph operation is outside the booking mail path.');
        }
        if ($method === 'PATCH') {
            booking_graph_draft_patch_guard($path, $body, $ifMatch);
        } elseif ($ifMatch !== null) throw new RuntimeException('Conditional header is only supported for draft repair.');
        return booking_provider_http($method, 'https://graph.microsoft.com/v1.0' . $path,
            ['Authorization: Bearer ' . $token, 'Prefer: IdType="ImmutableId"',
                'Content-Type: ' . ($method === 'PATCH' ? 'application/json' : 'text/plain'), 'Accept: application/json',
                ...($ifMatch !== null ? ['If-Match: ' . $ifMatch] : [])], $body);
    };
}

/** Only restore missing envelope fields on an individually identified RE draft. */
function booking_graph_draft_patch_guard(string $path, ?string $body, ?string $ifMatch): void
{
    if (!preg_match('~^/users/sales%40re\.sitesee\.ai/messages/[A-Za-z0-9_%=-]+$~D', $path)
        || !$ifMatch || strlen($ifMatch) > 512 || preg_match('/[\r\n]/', $ifMatch)) {
        throw new RuntimeException('Draft repair identity or version is unavailable.');
    }
    $fields = json_decode((string)$body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($fields) || !$fields || array_diff(array_keys($fields), ['toRecipients','replyTo'])) {
        throw new RuntimeException('Only missing draft recipients may be repaired.');
    }
    foreach ($fields as $value) {
        if ($value !== [['emailAddress'=>['address'=>BOOKING_MAIL_SENDER]]]) {
            throw new RuntimeException('Draft repair requires the authorized RE business address.');
        }
    }
}

function booking_crm_config(): array
{
    $c = booking_private_json(dirname(__DIR__) . '/zoho-crm.json');
    foreach (['client_id','client_secret','refresh_token','org_id','user_id'] as $k) {
        if (empty($c[$k]) || !is_string($c[$k])) throw new RuntimeException('CRM authorization is incomplete.');
    }
    foreach (['org_id','user_id'] as $k) booking_crm_id($c[$k]);
    if (($c['api_domain'] ?? '') !== 'https://www.zohoapis.com'
        || ($c['accounts_domain'] ?? '') !== 'https://accounts.zoho.com'
        || !in_array($c['sync_mode'] ?? '', ['api','native'], true)
        || !in_array($c['original_sync_mode'] ?? '', ['api','native'], true)
        || empty($c['sync_reviewed_at']) || empty($c['owner_verified_at'])) {
        throw new RuntimeException('CRM organization, email owner and synchronization must be reviewed first.');
    }
    return $c;
}

function booking_crm_id(string $id): string
{
    if (!preg_match('/^[0-9]{1,30}$/D', $id)) throw new InvalidArgumentException('A verified CRM numeric ID is required.');
    return $id;
}

function booking_crm_client(array $config): callable
{
    $r = booking_provider_http('POST', 'https://accounts.zoho.com/oauth/v2/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type'=>'refresh_token','client_id'=>$config['client_id'],
            'client_secret'=>$config['client_secret'],'refresh_token'=>$config['refresh_token']]));
    if ($r['status'] !== 200 || empty($r['body']['access_token'])
        || (isset($r['body']['api_domain']) && $r['body']['api_domain'] !== $config['api_domain'])) {
        throw new RuntimeException('CRM authentication or region validation failed.');
    }
    $token = $r['body']['access_token'];
    return static function (string $method, string $path, ?array $body = null) use ($token): array {
        $read = preg_match('~^/(org|users(?:\?type=CurrentUser)?|Contacts/(?:search\?[^\r\n]+|[0-9]+(?:/Emails(?:\?[^\r\n]*)?)?))$~D', $path);
        $write = preg_match('~^/Contacts/[0-9]+/actions/associate_email$~D', $path);
        if (!(($method === 'GET' && $read) || ($method === 'POST' && $write))) throw new RuntimeException('CRM operation is not permitted.');
        return booking_provider_http($method, 'https://www.zohoapis.com/crm/v8' . $path,
            ['Authorization: Zoho-oauthtoken ' . $token,'Content-Type: application/json','Accept: application/json'],
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    };
}
