<?php
declare(strict_types=1);

/**
 * Shared PayMongo Payment Link webhook processor.
 *
 * The existing endpoint is intentionally the Test Mode endpoint. The live
 * endpoint defines PRINTFLOW_PAYMONGO_WEBHOOK_MODE before loading this file.
 * Never select an environment from unsigned request data.
 */

require_once __DIR__ . '/../includes/provider_payments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const PRINTFLOW_PAYMONGO_WEBHOOK_MAX_BYTES = 1048576;
const PRINTFLOW_PAYMONGO_WEBHOOK_STALE_MINUTES = 5;

function printflow_paymongo_webhook_respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function printflow_paymongo_webhook_safe_text($value, int $maxLength = 100): string {
    $value = is_scalar($value) ? trim((string)$value) : '';
    $value = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    return substr(trim($value), 0, max(1, $maxLength));
}

function printflow_paymongo_webhook_error_code(array $errors): string {
    $errors = array_values(array_unique(array_filter(array_map(
        static fn($error): string => substr(
            (string)preg_replace('/[^a-z0-9_-]/i', '', (string)$error),
            0,
            40
        ),
        $errors
    ))));
    return substr($errors === [] ? 'verification_failed' : implode(',', $errors), 0, 100);
}

function printflow_paymongo_webhook_schema_ready(): bool {
    if (!printflow_provider_payments_ready()) {
        return false;
    }

    $required = [
        'provider_payments' => [
            'mode',
            'payment_status',
            'provider_status',
            'payment_method',
            'paid_amount_centavos',
            'reference_number',
            'provider_paid_at',
            'reconciliation_error_code',
        ],
        'provider_webhook_events' => [
            'mode',
            'attempt_count',
            'last_attempt_at',
            'updated_at',
            'last_error_code',
            'payload_sha256',
            'payment_link_id',
            'provider_transaction_id',
            'paid_amount_centavos',
            'currency',
            'payment_method',
            'reference_number',
            'provider_paid_at',
        ],
    ];

    foreach ($required as $table => $columns) {
        foreach ($columns as $column) {
            if (!db_table_has_column($table, $column)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Claim an inbox row exactly once, while allowing failed and stale processing
 * attempts to be retried. The raw provider payload is never persisted; only a
 * minimal normalized envelope and its SHA-256 fingerprint are stored.
 */
function printflow_paymongo_webhook_claim_event(
    string $eventId,
    string $eventType,
    string $mode,
    string $safePayloadJson,
    string $payloadSha256,
    string $linkId
): array {
    global $conn;

    // A duplicate delivery is normal. Use a no-op duplicate-key branch so it
    // does not get reported as a database error before the durable claim below.
    $inserted = db_execute_affected_rows(
        "INSERT INTO provider_webhook_events
            (provider, event_id, event_type, mode, status, raw_event_json,
             payload_sha256, payment_link_id, attempt_count, last_attempt_at, updated_at)
         VALUES ('paymongo', ?, ?, ?, 'processing', ?, ?, NULLIF(?, ''), 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE id = provider_webhook_events.id",
        'ssssss',
        [$eventId, $eventType, $mode, $safePayloadJson, $payloadSha256, $linkId]
    );
    if ($inserted === 1) {
        return [
            'ok' => true,
            'event_row_id' => (int)$conn->insert_id,
            'duplicate' => false,
        ];
    }

    $rows = db_query(
        "SELECT id, mode, status
         FROM provider_webhook_events
         WHERE provider = 'paymongo' AND event_id = ?
         LIMIT 1",
        's',
        [$eventId]
    ) ?: [];
    if (empty($rows)) {
        return ['ok' => false, 'error_code' => 'event_record_failed'];
    }

    $existing = $rows[0];
    if ((string)($existing['mode'] ?? '') !== $mode) {
        return ['ok' => false, 'error_code' => 'event_mode_conflict'];
    }
    if ((string)($existing['status'] ?? '') === 'processed') {
        return [
            'ok' => true,
            'event_row_id' => (int)$existing['id'],
            'duplicate' => true,
            'processed' => true,
        ];
    }

    $claimed = db_execute_affected_rows(
        "UPDATE provider_webhook_events
         SET status = 'processing', raw_event_json = ?, payload_sha256 = ?,
             payment_link_id = NULLIF(?, ''), attempt_count = attempt_count + 1,
             last_attempt_at = NOW(), processed_at = NULL, last_error_code = NULL,
             updated_at = NOW()
         WHERE id = ?
           AND (
                status IN ('failed', 'ignored')
                OR (
                    status = 'processing'
                    AND COALESCE(last_attempt_at, received_at)
                        <= DATE_SUB(NOW(), INTERVAL " . PRINTFLOW_PAYMONGO_WEBHOOK_STALE_MINUTES . " MINUTE)
                )
           )",
        'sssi',
        [$safePayloadJson, $payloadSha256, $linkId, (int)$existing['id']]
    );
    if ($claimed === 1) {
        return [
            'ok' => true,
            'event_row_id' => (int)$existing['id'],
            'duplicate' => true,
            'processed' => false,
        ];
    }

    return [
        'ok' => false,
        'event_row_id' => (int)$existing['id'],
        'error_code' => 'event_processing_in_progress',
    ];
}

function printflow_paymongo_webhook_finish_event(
    int $eventRowId,
    string $status,
    string $errorCode,
    array $metadata = []
): bool {
    if ($eventRowId <= 0 || !in_array($status, ['processed', 'failed'], true)) {
        return false;
    }

    $providerPaidAt = printflow_paymongo_webhook_safe_text($metadata['provider_paid_at'] ?? '', 19);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $providerPaidAt)) {
        $providerPaidAt = '';
    }

    $affected = db_execute_affected_rows(
        "UPDATE provider_webhook_events
         SET status = ?,
             provider_payment_id = NULLIF(?, 0),
             provider_transaction_id = NULLIF(?, ''),
             payment_link_id = NULLIF(?, ''),
             paid_amount_centavos = ?,
             currency = NULLIF(?, ''),
             payment_method = NULLIF(?, ''),
             reference_number = NULLIF(?, ''),
             provider_paid_at = NULLIF(?, ''),
             last_error_code = NULLIF(?, ''),
             processed_at = NOW(), updated_at = NOW()
         WHERE id = ? AND status = 'processing'",
        'sississsssi',
        [
            $status,
            (int)($metadata['ledger_id'] ?? 0),
            printflow_paymongo_webhook_safe_text($metadata['provider_transaction_id'] ?? '', 100),
            printflow_paymongo_webhook_safe_text($metadata['link_id'] ?? '', 100),
            array_key_exists('paid_amount_centavos', $metadata)
                ? (int)$metadata['paid_amount_centavos']
                : null,
            strtoupper(printflow_paymongo_webhook_safe_text($metadata['currency'] ?? '', 3)),
            strtolower(printflow_paymongo_webhook_safe_text($metadata['payment_method'] ?? '', 30)),
            printflow_paymongo_webhook_safe_text($metadata['reference_number'] ?? '', 100),
            $providerPaidAt,
            printflow_paymongo_webhook_safe_text($errorCode, 100),
            $eventRowId,
        ]
    );
    if ($affected === 1) {
        return true;
    }

    $rows = db_query(
        'SELECT status FROM provider_webhook_events WHERE id = ? LIMIT 1',
        'i',
        [$eventRowId]
    ) ?: [];
    return (string)($rows[0]['status'] ?? '') === $status;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    printflow_paymongo_webhook_respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$expectedMode = defined('PRINTFLOW_PAYMONGO_WEBHOOK_MODE')
    ? strtolower((string)constant('PRINTFLOW_PAYMONGO_WEBHOOK_MODE'))
    : 'test';
if (!in_array($expectedMode, ['test', 'live'], true)) {
    printflow_paymongo_webhook_respond(503, ['success' => false, 'message' => 'Webhook mode is unavailable.']);
}
if ($expectedMode === 'live'
    && (!function_exists('printflow_paymongo_live_enabled') || !printflow_paymongo_live_enabled())) {
    printflow_paymongo_webhook_respond(503, ['success' => false, 'message' => 'Live payments are not enabled.']);
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength !== null
    && (int)$contentLength > PRINTFLOW_PAYMONGO_WEBHOOK_MAX_BYTES) {
    printflow_paymongo_webhook_respond(413, ['success' => false, 'message' => 'Webhook payload is too large.']);
}

$rawBody = file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
if (!is_string($rawBody) || strlen($rawBody) > PRINTFLOW_PAYMONGO_WEBHOOK_MAX_BYTES
    || !printflow_paymongo_verify_webhook_signature($rawBody, $signature, $expectedMode)) {
    printflow_paymongo_webhook_respond(401, ['success' => false, 'message' => 'Invalid webhook signature.']);
}

$payload = json_decode($rawBody, true);
$event = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
$attributes = isset($event['attributes']) && is_array($event['attributes']) ? $event['attributes'] : [];
$eventId = printflow_paymongo_webhook_safe_text($event['id'] ?? '', 100);
$eventType = printflow_paymongo_webhook_safe_text($attributes['type'] ?? '', 100);
$livemode = $attributes['livemode'] ?? null;
$expectedLivemode = $expectedMode === 'live';

if (!preg_match('/^evt_[A-Za-z0-9_-]+$/', $eventId)
    || !is_bool($livemode)
    || $livemode !== $expectedLivemode) {
    printflow_paymongo_webhook_respond(409, ['success' => false, 'message' => 'Webhook environment mismatch.']);
}
if ($eventType !== 'link.payment.paid') {
    printflow_paymongo_webhook_respond(202, ['success' => true, 'processed' => false]);
}
if (!printflow_paymongo_webhook_schema_ready()) {
    printflow_paymongo_webhook_respond(503, [
        'success' => false,
        'message' => 'The PayMongo webhook migration has not been applied.',
    ]);
}

$eventData = isset($attributes['data']) && is_array($attributes['data'])
    ? $attributes['data']
    : [];
$linkId = printflow_find_paymongo_link_id($eventData);
$safeEnvelope = json_encode([
    'payload_version' => 2,
    'event_id' => $eventId,
    'event_type' => $eventType,
    'mode' => $expectedMode,
    'livemode' => $expectedLivemode,
    'link_id' => $linkId,
], JSON_UNESCAPED_SLASHES);
$safeEnvelope = is_string($safeEnvelope) ? $safeEnvelope : '{}';

$claim = printflow_paymongo_webhook_claim_event(
    $eventId,
    $eventType,
    $expectedMode,
    $safeEnvelope,
    hash('sha256', $rawBody),
    $linkId
);
if (!empty($claim['processed'])) {
    printflow_paymongo_webhook_respond(200, [
        'success' => true,
        'processed' => true,
        'duplicate' => true,
    ]);
}
if (empty($claim['ok'])) {
    $code = (string)($claim['error_code'] ?? 'event_claim_failed');
    printflow_paymongo_webhook_respond(
        $code === 'event_mode_conflict' ? 409 : 503,
        ['success' => false, 'processed' => false, 'error_code' => $code]
    );
}
$eventRowId = (int)($claim['event_row_id'] ?? 0);

if ($linkId === '') {
    printflow_paymongo_webhook_finish_event($eventRowId, 'failed', 'link_id_missing');
    printflow_paymongo_webhook_respond(503, [
        'success' => false,
        'processed' => false,
        'error_code' => 'link_id_missing',
    ]);
}

$paymentRows = db_query(
    "SELECT * FROM provider_payments
     WHERE link_id = ? AND provider = 'paymongo' AND mode = ?
     LIMIT 1",
    'ss',
    [$linkId, $expectedMode]
) ?: [];
if (empty($paymentRows)) {
    printflow_paymongo_webhook_finish_event(
        $eventRowId,
        'failed',
        'ledger_not_found',
        ['link_id' => $linkId]
    );
    printflow_paymongo_webhook_respond(503, [
        'success' => false,
        'processed' => false,
        'error_code' => 'ledger_not_found',
    ]);
}

$payment = $paymentRows[0];
$verified = printflow_paymongo_get_paid_link_payment($linkId, $expectedMode);
$verificationErrors = printflow_provider_payment_revalidation_errors($payment, $verified);
$referenceNumber = printflow_paymongo_webhook_safe_text(
    $verified['reference_number'] ?? ($payment['reference_number'] ?? ''),
    100
);
$verifiedMetadata = [
    'ledger_id' => (int)$payment['id'],
    'provider_transaction_id' => (string)($verified['payment_id'] ?? ''),
    'link_id' => $linkId,
    'paid_amount_centavos' => isset($verified['amount']) ? (int)$verified['amount'] : null,
    'currency' => (string)($verified['currency'] ?? ''),
    'payment_method' => (string)($verified['payment_method'] ?? ''),
    'reference_number' => $referenceNumber,
    'provider_paid_at' => (string)($verified['provider_paid_at'] ?? ''),
];

if (!empty($verificationErrors)) {
    $errorCode = printflow_paymongo_webhook_error_code($verificationErrors);
    if (function_exists('printflow_provider_payment_set_reconciliation_error')) {
        printflow_provider_payment_set_reconciliation_error((int)$payment['id'], $verificationErrors);
    }
    printflow_paymongo_webhook_finish_event($eventRowId, 'failed', $errorCode, $verifiedMetadata);
    $providerPendingOnly = array_values(array_unique($verificationErrors)) === ['provider_status'];
    printflow_paymongo_webhook_respond($providerPendingOnly ? 503 : 409, [
        'success' => false,
        'processed' => false,
        'error_code' => $errorCode,
    ]);
}

$providerPaidAt = (string)($verified['provider_paid_at'] ?? '');
$result = printflow_provider_payment_mark_paid(
    (int)$payment['id'],
    (string)$verified['payment_id'],
    (string)($verified['payment_method'] ?? ''),
    (int)($verified['amount'] ?? 0),
    $referenceNumber,
    $providerPaidAt !== '' ? $providerPaidAt : null
);
if (empty($result['ok'])) {
    if (function_exists('printflow_provider_payment_set_reconciliation_error')) {
        printflow_provider_payment_set_reconciliation_error((int)$payment['id'], ['finalization_failed']);
    }
    printflow_paymongo_webhook_finish_event(
        $eventRowId,
        'failed',
        'finalization_failed',
        $verifiedMetadata
    );
    printflow_paymongo_webhook_respond(500, [
        'success' => false,
        'processed' => false,
        'error_code' => 'finalization_failed',
    ]);
}

if (!printflow_paymongo_webhook_finish_event(
    $eventRowId,
    'processed',
    '',
    $verifiedMetadata
)) {
    printflow_paymongo_webhook_respond(500, [
        'success' => false,
        'processed' => false,
        'error_code' => 'event_completion_failed',
    ]);
}

printflow_paymongo_webhook_respond(200, [
    'success' => true,
    'processed' => true,
    'duplicate' => !empty($claim['duplicate']),
]);
