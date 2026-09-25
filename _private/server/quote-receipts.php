<?php
declare(strict_types=1);

/**
 * Successful quote-action receipts belong to the existing verified pricing session.
 * Keep its native PHP session lock held until the response is stored and sent.
 * This serializes same-session retries without a new database or mail setting.
 */
function real_estate_quote_receipt_key(array $submission): string
{
    $appointment = $submission['appointment'];
    if ($submission['action'] === 'email_quote') {
        $appointment = ['date'=>$appointment['date'], 'time'=>$appointment['time']];
    }
    $identity = [
        'action'=>$submission['action'], 'market'=>$submission['market'],
        'details'=>$submission['details'], 'appointment'=>$appointment, 'quote'=>$submission['quote'],
    ];
    return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function real_estate_quote_cached_receipt(string $key): ?array
{
    $entry = $_SESSION['real_estate_quote_receipts'][$key] ?? null;
    if (!is_array($entry) || (int)($entry['at'] ?? 0) < time() - 43200 || !is_array($entry['response'] ?? null)) {
        return null;
    }
    return $entry['response'] + ['replayed'=>true];
}

function real_estate_quote_save_receipt(string $key, array $response): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('A pricing session is required to remember a quote request.');
    }
    if (($response['ok'] ?? false) !== true ||
        !in_array($response['action'] ?? '', ['email_quote', 'request_appointment'], true) ||
        !preg_match('/^[A-F0-9]{10}$/D', (string)($response['reference'] ?? ''))) {
        throw new InvalidArgumentException('Only a confirmed quote-action receipt can be remembered.');
    }
    $receipts = $_SESSION['real_estate_quote_receipts'] ?? [];
    $receipts = array_filter(is_array($receipts) ? $receipts : [], static fn($entry): bool =>
        is_array($entry) && (int)($entry['at'] ?? 0) >= time() - 43200);
    $receipts[$key] = ['at'=>time(), 'response'=>$response];
    // Bound session size; store response metadata, never the quote or property access details.
    while (count($receipts) > 128) array_shift($receipts);
    $_SESSION['real_estate_quote_receipts'] = $receipts;
}
