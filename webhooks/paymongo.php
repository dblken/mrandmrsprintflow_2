<?php
declare(strict_types=1);

/**
 * Shared PayMongo Payment Link and Payment Intent webhook processor.
 *
 * The existing endpoint is intentionally the Test Mode endpoint. The live
 * endpoint defines PRINTFLOW_PAYMONGO_WEBHOOK_MODE before loading this file.
 * Never select an environment from unsigned request data.
 */

require_once __DIR__ . '/../includes/provider_payments.php';
require_once __DIR__ . '/../includes/paymongo_webhook_events.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const PRINTFLOW_PAYMONGO_WEBHOOK_MAX_BYTES = 1048576;
const PRINTFLOW_PAYMONGO_WEBHOOK_STALE_MINUTES = 5;

function printflow_paymongo_webhook_respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $error): void {
    error_log('[paymongo-webhook] unexpected_exception ' . json_encode([
        'type' => get_class($error),
        'message' => substr((string)$error->getMessage(), 0, 180),
    ], JSON_UNESCAPED_SLASHES));
    printflow_paymongo_webhook_respond(500, [
        'success' => false,
        'processed' => false,
        'error_code' => 'internal_error',
    ]);
});

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
            'payment_flow',
            'payment_intent_id',
            'payment_method_id',
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
            'payment_intent_id',
            'payment_method_id',
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
    string $linkId,
    string $paymentIntentId = '',
    string $paymentMethodId = ''
): array {
    global $conn;

    // A duplicate delivery is normal. Use a no-op duplicate-key branch so it
    // does not get reported as a database error before the durable claim below.
    $inserted = db_execute_affected_rows(
        "INSERT INTO provider_webhook_events
            (provider, event_id, event_type, mode, status, raw_event_json,
             payload_sha256, payment_link_id, payment_intent_id, payment_method_id,
             attempt_count, last_attempt_at, updated_at)
         VALUES ('paymongo', ?, ?, ?, 'processing', ?, ?, NULLIF(?, ''),
                 NULLIF(?, ''), NULLIF(?, ''), 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE id = provider_webhook_events.id",
        'ssssssss',
        [
            $eventId,
            $eventType,
            $mode,
            $safePayloadJson,
            $payloadSha256,
            $linkId,
            $paymentIntentId,
            $paymentMethodId,
        ]
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
             payment_link_id = NULLIF(?, ''), payment_intent_id = NULLIF(?, ''),
             payment_method_id = NULLIF(?, ''), attempt_count = attempt_count + 1,
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
        'sssssi',
        [
            $safePayloadJson,
            $payloadSha256,
            $linkId,
            $paymentIntentId,
            $paymentMethodId,
            (int)$existing['id'],
        ]
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
             payment_intent_id = NULLIF(?, ''),
             payment_method_id = NULLIF(?, ''),
             paid_amount_centavos = ?,
             currency = NULLIF(?, ''),
             payment_method = NULLIF(?, ''),
             reference_number = NULLIF(?, ''),
             provider_paid_at = NULLIF(?, ''),
             last_error_code = NULLIF(?, ''),
             processed_at = NOW(), updated_at = NOW()
         WHERE id = ? AND status = 'processing'",
        'sissssisssssi',
        [
            $status,
            (int)($metadata['ledger_id'] ?? 0),
            printflow_paymongo_webhook_safe_text($metadata['provider_transaction_id'] ?? '', 100),
            printflow_paymongo_webhook_safe_text($metadata['link_id'] ?? '', 100),
            printflow_paymongo_webhook_safe_text($metadata['payment_intent_id'] ?? '', 100),
            printflow_paymongo_webhook_safe_text($metadata['payment_method_id'] ?? '', 100),
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

function printflow_paymongo_webhook_lookup_intent_ledger(
    array $context,
    array $verifiedPayment,
    string $mode
): array {
    $intentIds = array_values(array_unique(array_filter([
        (string)($context['payment_intent_id'] ?? ''),
        (string)($verifiedPayment['payment_intent_id'] ?? ''),
    ], static fn(string $id): bool => preg_match('/^pi_[A-Za-z0-9_-]+$/', $id) === 1)));
    foreach ($intentIds as $intentId) {
        $rows = db_query(
            "SELECT * FROM provider_payments
             WHERE payment_intent_id = ? AND provider = 'paymongo' AND mode = ?
               AND payment_flow = 'payment_intent' LIMIT 1",
            'ss',
            [$intentId, $mode]
        ) ?: [];
        if (!empty($rows)) {
            return $rows[0];
        }
    }

    $metadataIds = [];
    if ((int)($context['ledger_id'] ?? 0) > 0) {
        $metadataIds[] = (int)$context['ledger_id'];
    }
    $verifiedMetadata = isset($verifiedPayment['metadata']) && is_array($verifiedPayment['metadata'])
        ? $verifiedPayment['metadata']
        : [];
    if (isset($verifiedMetadata['printflow_payment_id'])
        && ctype_digit((string)$verifiedMetadata['printflow_payment_id'])) {
        $metadataIds[] = (int)$verifiedMetadata['printflow_payment_id'];
    }
    foreach (array_values(array_unique($metadataIds)) as $ledgerId) {
        $rows = db_query(
            "SELECT * FROM provider_payments
             WHERE id = ? AND provider = 'paymongo' AND mode = ?
               AND payment_flow = 'payment_intent' LIMIT 1",
            'is',
            [$ledgerId, $mode]
        ) ?: [];
        if (!empty($rows)) {
            return $rows[0];
        }
    }

    $providerPaymentIds = array_values(array_unique(array_filter([
        (string)($verifiedPayment['payment_id'] ?? ''),
        (string)($context['payment_id'] ?? ''),
    ], static fn(string $id): bool => preg_match('/^pay_[A-Za-z0-9_-]+$/', $id) === 1)));
    foreach ($providerPaymentIds as $providerPaymentId) {
        $rows = db_query(
            "SELECT * FROM provider_payments
             WHERE provider_payment_id = ? AND provider = 'paymongo' AND mode = ?
               AND payment_flow = 'payment_intent' LIMIT 1",
            'ss',
            [$providerPaymentId, $mode]
        ) ?: [];
        if (!empty($rows)) {
            return $rows[0];
        }
    }

    $paymentMethodId = (string)($context['payment_method_id'] ?? '');
    if (preg_match('/^pm_[A-Za-z0-9_-]+$/', $paymentMethodId)) {
        $rows = db_query(
            "SELECT * FROM provider_payments
             WHERE payment_method_id = ? AND provider = 'paymongo' AND mode = ?
               AND payment_flow = 'payment_intent' LIMIT 1",
            'ss',
            [$paymentMethodId, $mode]
        ) ?: [];
        if (!empty($rows)) {
            return $rows[0];
        }
    }
    return [];
}

function printflow_paymongo_webhook_audit_metadata(
    array $ledger,
    array $context,
    array $verifiedPayment = []
): array {
    return [
        'ledger_id' => (int)($ledger['id'] ?? 0),
        'provider_transaction_id' => (string)(
            $verifiedPayment['payment_id'] ?? $context['payment_id'] ?? ''
        ),
        'link_id' => '',
        'payment_intent_id' => (string)(
            $ledger['payment_intent_id'] ?? $context['payment_intent_id'] ?? ''
        ),
        'payment_method_id' => (string)(
            $context['payment_method_id']
            ?: ($verifiedPayment['payment_method_id']
                ?? $ledger['payment_method_id']
                ?? '')
        ),
        'paid_amount_centavos' => ($context['event_type'] ?? '') === 'payment.paid'
            ? (int)($verifiedPayment['amount'] ?? 0)
            : null,
        'currency' => (string)($verifiedPayment['currency'] ?? $context['currency'] ?? ''),
        'payment_method' => (string)(
            $verifiedPayment['payment_method'] ?? $context['payment_method'] ?? $ledger['payment_method'] ?? ''
        ),
        'reference_number' => (string)(
            $verifiedPayment['reference_number'] ?? $context['reference_number'] ?? ''
        ),
        'provider_paid_at' => (string)(
            $verifiedPayment['provider_paid_at'] ?? $context['provider_paid_at'] ?? ''
        ),
    ];
}

function printflow_paymongo_webhook_fail(
    int $eventRowId,
    string $errorCode,
    array $metadata = [],
    int $httpStatus = 409
): void {
    printflow_paymongo_webhook_finish_event($eventRowId, 'failed', $errorCode, $metadata);
    error_log('[paymongo-webhook] request_failed ' . json_encode([
        'event_row_id' => $eventRowId,
        'error_code' => printflow_paymongo_webhook_safe_text($errorCode, 100),
        'http_status' => $httpStatus,
        'ledger_id' => (int)($metadata['ledger_id'] ?? 0),
        'payment_intent_id' => printflow_paymongo_webhook_safe_text($metadata['payment_intent_id'] ?? '', 100),
        'payment_method_id' => printflow_paymongo_webhook_safe_text($metadata['payment_method_id'] ?? '', 100),
        'link_id' => printflow_paymongo_webhook_safe_text($metadata['link_id'] ?? '', 100),
    ], JSON_UNESCAPED_SLASHES));
    printflow_paymongo_webhook_respond($httpStatus, [
        'success' => false,
        'processed' => false,
        'error_code' => $errorCode,
    ]);
}

function printflow_paymongo_webhook_complete(
    int $eventRowId,
    array $metadata,
    bool $duplicate = false,
    string $acceptedCode = ''
): void {
    if (!printflow_paymongo_webhook_finish_event($eventRowId, 'processed', $acceptedCode, $metadata)) {
        printflow_paymongo_webhook_respond(500, [
            'success' => false,
            'processed' => false,
            'error_code' => 'event_completion_failed',
        ]);
    }
    $payload = [
        'success' => true,
        'processed' => true,
        'duplicate' => $duplicate,
    ];
    if ($acceptedCode !== '') {
        $payload['accepted_code'] = $acceptedCode;
    }
    printflow_paymongo_webhook_respond(200, $payload);
}

function printflow_paymongo_webhook_handle_intent_event(
    string $eventType,
    array $eventData,
    string $expectedMode,
    int $eventRowId,
    array $claim
): void {
    $context = printflow_paymongo_webhook_event_context($eventType, $eventData, $expectedMode);
    $verifiedPayment = [];
    if (in_array($eventType, ['payment.paid', 'payment.failed'], true)) {
        $eventPaymentId = (string)($context['payment_id'] ?? '');
        if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $eventPaymentId)) {
            printflow_paymongo_webhook_fail($eventRowId, 'payment_id_missing', [], 400);
        }
        $verifiedPayment = printflow_paymongo_get_payment($eventPaymentId, $expectedMode);
        if (empty($verifiedPayment['ok'])
            || (string)($verifiedPayment['payment_id'] ?? '') !== $eventPaymentId) {
            printflow_paymongo_webhook_fail(
                $eventRowId,
                'payment_retrieval_failed',
                ['provider_transaction_id' => $eventPaymentId],
                503
            );
        }
        if (empty($context['payment_intent_id'])) {
            $context['payment_intent_id'] = (string)($verifiedPayment['payment_intent_id'] ?? '');
        }
        if (empty($context['payment_method_id'])) {
            $context['payment_method_id'] = (string)($verifiedPayment['payment_method_id'] ?? '');
        }
    }

    $ledger = printflow_paymongo_webhook_lookup_intent_ledger($context, $verifiedPayment, $expectedMode);
    if (empty($ledger)) {
        printflow_paymongo_webhook_fail(
            $eventRowId,
            'ledger_not_found',
            printflow_paymongo_webhook_audit_metadata([], $context, $verifiedPayment),
            503
        );
    }
    $auditMetadata = printflow_paymongo_webhook_audit_metadata($ledger, $context, $verifiedPayment);
    $action = printflow_paymongo_webhook_transition_action(
        (string)($ledger['status'] ?? ''),
        $eventType,
        (string)($ledger['provider_payment_id'] ?? ''),
        (string)($verifiedPayment['payment_id'] ?? $context['payment_id'] ?? '')
    );
    if ($action === 'provider_payment_conflict') {
        printflow_paymongo_webhook_complete(
            $eventRowId,
            $auditMetadata,
            !empty($claim['duplicate']),
            'provider_payment_conflict'
        );
    }
    if (in_array($action, ['already_paid', 'already_terminal'], true)) {
        printflow_paymongo_webhook_complete(
            $eventRowId,
            $auditMetadata,
            !empty($claim['duplicate']),
            $action
        );
    }

    $intentId = (string)($ledger['payment_intent_id'] ?? '');
    $verifiedIntent = printflow_paymongo_get_payment_intent($intentId, $expectedMode);
    if (empty($verifiedIntent['ok']) || (string)($verifiedIntent['id'] ?? '') !== $intentId) {
        printflow_paymongo_webhook_fail($eventRowId, 'intent_retrieval_failed', $auditMetadata, 503);
    }
    if ($eventType === 'qrph.expired'
        && strtolower((string)($verifiedIntent['status'] ?? '')) === 'succeeded') {
        $reconciled = printflow_provider_payment_reconcile_intent($ledger);
        if (!empty($reconciled['paid'])) {
            printflow_paymongo_webhook_complete($eventRowId, $auditMetadata, !empty($claim['duplicate']));
        }
        printflow_paymongo_webhook_fail($eventRowId, 'paid_reconciliation_pending', $auditMetadata, 503);
    }

    $subject = printflow_provider_payment_load_subject(
        (string)($ledger['subject_type'] ?? ''),
        (int)($ledger['subject_id'] ?? 0)
    );
    $paymentMethodId = (string)(
        $context['payment_method_id']
        ?: ($verifiedPayment['payment_method_id'] ?? '')
    );
    $verificationErrors = printflow_paymongo_webhook_intent_errors(
        $ledger,
        $verifiedPayment,
        $verifiedIntent,
        $subject,
        $eventType,
        $expectedMode,
        $paymentMethodId
    );
    if ($eventType === 'payment.paid') {
        $verificationErrors = array_merge(
            $verificationErrors,
            printflow_provider_payment_revalidation_errors($ledger, $verifiedPayment)
        );
    }
    $verificationErrors = array_values(array_unique($verificationErrors));
    if ($verificationErrors !== []) {
        printflow_provider_payment_set_reconciliation_error((int)$ledger['id'], $verificationErrors);
        $errorCode = printflow_paymongo_webhook_error_code($verificationErrors);
        $retryable = in_array('intent_status', $verificationErrors, true);
        if ($retryable) {
            printflow_paymongo_webhook_fail($eventRowId, $errorCode, $auditMetadata, 503);
        }
        printflow_paymongo_webhook_complete($eventRowId, $auditMetadata, !empty($claim['duplicate']), $errorCode);
    }

    if ($paymentMethodId !== '') {
        $storedMethodId = (string)($ledger['payment_method_id'] ?? '');
        if ($storedMethodId !== '' && $storedMethodId !== $paymentMethodId) {
            printflow_paymongo_webhook_complete(
                $eventRowId,
                $auditMetadata,
                !empty($claim['duplicate']),
                'payment_method_conflict'
            );
        }
        if (!db_execute(
            "UPDATE provider_payments
             SET payment_method_id = COALESCE(payment_method_id, ?), updated_at = NOW()
             WHERE id = ? AND provider = 'paymongo' AND mode = ? AND payment_intent_id = ?",
            'siss',
            [$paymentMethodId, (int)$ledger['id'], $expectedMode, $intentId]
        )) {
            printflow_paymongo_webhook_fail($eventRowId, 'payment_method_persistence_failed', $auditMetadata, 500);
        }
        $auditMetadata['payment_method_id'] = $paymentMethodId;
    }

    if ($action === 'mark_paid') {
        $providerPaidAt = (string)($verifiedPayment['provider_paid_at'] ?? '');
        $result = printflow_provider_payment_mark_paid(
            (int)$ledger['id'],
            (string)$verifiedPayment['payment_id'],
            (string)($verifiedPayment['payment_method'] ?? $context['payment_method'] ?? ''),
            (int)($verifiedPayment['amount'] ?? 0),
            (string)($verifiedPayment['reference_number'] ?? $context['reference_number'] ?? ''),
            $providerPaidAt !== '' ? $providerPaidAt : null
        );
    } elseif ($action === 'mark_failed') {
        $failureCode = (string)($verifiedPayment['failure_code'] ?? $context['failure_code'] ?? 'payment_failed');
        $result = printflow_provider_payment_mark_failed(
            (int)$ledger['id'],
            $failureCode,
            'failed'
        );
    } elseif ($action === 'mark_expired') {
        $result = printflow_provider_payment_mark_expired((int)$ledger['id'], 'qrph_expired');
    } else {
        printflow_paymongo_webhook_complete(
            $eventRowId,
            $auditMetadata,
            !empty($claim['duplicate']),
            'unsupported_transition'
        );
    }
    if (empty($result['ok'])) {
        printflow_provider_payment_set_reconciliation_error((int)$ledger['id'], ['finalization_failed']);
        printflow_paymongo_webhook_fail($eventRowId, 'finalization_failed', $auditMetadata, 500);
    }
    printflow_provider_payment_set_reconciliation_error((int)$ledger['id'], []);
    printflow_paymongo_webhook_complete($eventRowId, $auditMetadata, !empty($claim['duplicate']));
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
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    printflow_paymongo_webhook_respond(400, ['success' => false, 'message' => 'Malformed webhook payload.']);
}
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
if (!in_array($eventType, [
    'link.payment.paid',
    'payment.paid',
    'payment.failed',
    'qrph.expired',
], true)) {
    printflow_paymongo_webhook_respond(200, ['success' => true, 'processed' => false, 'ignored' => true]);
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
$eventContext = printflow_paymongo_webhook_event_context($eventType, $eventData, $expectedMode);
$linkId = $eventType === 'link.payment.paid'
    ? printflow_find_paymongo_link_id($eventData)
    : '';
$paymentIntentId = (string)($eventContext['payment_intent_id'] ?? '');
$paymentMethodId = (string)($eventContext['payment_method_id'] ?? '');
$safeEnvelope = json_encode([
    'payload_version' => 2,
    'event_id' => $eventId,
    'event_type' => $eventType,
    'mode' => $expectedMode,
    'livemode' => $expectedLivemode,
    'link_id' => $linkId,
    'payment_id' => (string)($eventContext['payment_id'] ?? ''),
    'payment_intent_id' => $paymentIntentId,
    'payment_method_id' => $paymentMethodId,
], JSON_UNESCAPED_SLASHES);
$safeEnvelope = is_string($safeEnvelope) ? $safeEnvelope : '{}';

$claim = printflow_paymongo_webhook_claim_event(
    $eventId,
    $eventType,
    $expectedMode,
    $safeEnvelope,
    hash('sha256', $rawBody),
    $linkId,
    $paymentIntentId,
    $paymentMethodId
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
    if ($code === 'event_processing_in_progress') {
        printflow_paymongo_webhook_respond(200, [
            'success' => true,
            'processed' => false,
            'duplicate' => true,
            'accepted_code' => $code,
        ]);
    }
    printflow_paymongo_webhook_respond(
        $code === 'event_mode_conflict' ? 409 : 503,
        ['success' => false, 'processed' => false, 'error_code' => $code]
    );
}
$eventRowId = (int)($claim['event_row_id'] ?? 0);

if (in_array($eventType, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
    printflow_paymongo_webhook_handle_intent_event(
        $eventType,
        $eventData,
        $expectedMode,
        $eventRowId,
        $claim
    );
}

if ($linkId === '') {
    printflow_paymongo_webhook_complete(
        $eventRowId,
        ['link_id' => $linkId],
        !empty($claim['duplicate']),
        'link_id_missing'
    );
}

$paymentRows = db_query(
    "SELECT * FROM provider_payments
     WHERE link_id = ? AND provider = 'paymongo' AND mode = ?
     LIMIT 1",
    'ss',
    [$linkId, $expectedMode]
) ?: [];
if (empty($paymentRows)) {
    printflow_paymongo_webhook_complete(
        $eventRowId,
        ['link_id' => $linkId],
        !empty($claim['duplicate']),
        'ledger_not_found'
    );
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
    $providerPendingOnly = array_values(array_unique($verificationErrors)) === ['provider_status'];
    if ($providerPendingOnly) {
        printflow_paymongo_webhook_fail($eventRowId, $errorCode, $verifiedMetadata, 503);
    }
    printflow_paymongo_webhook_complete(
        $eventRowId,
        $verifiedMetadata,
        !empty($claim['duplicate']),
        $errorCode
    );
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
