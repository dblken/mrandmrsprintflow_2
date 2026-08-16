<?php

/**
 * Database-free receipt encoding helpers. Kept separate so time and ESC/POS QR
 * behavior can be regression-tested without application credentials.
 */

function printflow_receipt_qr_payload(int $orderId): string {
    return $orderId > 0 ? 'PF1:ORDER:' . $orderId : '';
}

function printflow_receipt_format_datetime($value): string {
    $timezoneName = date_default_timezone_get() ?: 'Asia/Manila';
    try {
        $timezone = new DateTimeZone($timezoneName);
        $raw = trim((string)$value);
        $dateTime = $raw === ''
            ? new DateTimeImmutable('now', $timezone)
            : new DateTimeImmutable($raw, $timezone);
        return $dateTime->setTimezone($timezone)->format('M j, Y h:i A');
    } catch (Throwable $e) {
        error_log('[receipt-format] Invalid date/time value; using current application time.');
        return date('M j, Y h:i A');
    }
}

function printflow_receipt_labeled_value_lines(string $label, string $value, int $columns): array {
    $columns = max(16, $columns);
    $label = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $label) ?? '');
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    if (strlen($label) + 1 + strlen($value) <= $columns) {
        return [str_pad($label, $columns - strlen($value), ' ') . $value];
    }
    return [
        substr($label, 0, $columns),
        str_pad(substr($value, 0, $columns), $columns, ' ', STR_PAD_LEFT),
    ];
}

function printflow_receipt_escpos_qr_commands(string $payload, int $moduleSize = 5): string {
    $payload = trim($payload);
    if ($payload === '') return '';
    $moduleSize = max(3, min(6, $moduleSize));
    $storeLength = strlen($payload) + 3;
    $pL = chr($storeLength & 0xff);
    $pH = chr(($storeLength >> 8) & 0xff);

    return "\x1Ba\x01"
        . "\n"
        . "\x1D(k\x04\x00\x31\x41\x32\x00"
        . "\x1D(k\x03\x00\x31\x43" . chr($moduleSize)
        . "\x1D(k\x03\x00\x31\x45\x31"
        . "\x1D(k" . $pL . $pH . "\x31\x50\x30" . $payload
        . "\x1D(k\x03\x00\x31\x51\x30"
        . "\n\n"
        . "\x1Ba\x00";
}

function printflow_receipt_escpos_base64(string $text, string $qrPayload = ''): string {
    $body = '';
    $qrInserted = false;
    foreach (explode("\n", $text) as $line) {
        $body .= $line . "\n";
        if (!$qrInserted && $qrPayload !== '' && trim($line) === 'RECEIPT INFO') {
            $body .= printflow_receipt_escpos_qr_commands($qrPayload);
            $qrInserted = true;
        }
    }
    $raw = "\x1B@" . "\x1Ba\x00" . $body . "\n" . "\x1DV\x00";
    return base64_encode($raw);
}
