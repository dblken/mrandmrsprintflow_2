<?php
/**
 * Shared PayMongo payment ledger for customer and POS orders.
 *
 * This module never returns or logs API credentials or webhook secrets.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paymongo.php';

/**
 * Customer-facing online payment selector.
 *
 * PayMongo is the primary online flow. The manual proof system remains
 * available as an explicit fallback through ONLINE_PAYMENT_MODE=manual_gcash.
 */
function printflow_online_payment_mode(): string {
    $mode = strtolower(trim((string)printflow_env('ONLINE_PAYMENT_MODE')));
    return in_array($mode, ['paymongo', 'manual_gcash'], true) ? $mode : 'paymongo';
}

function printflow_manual_online_payment_enabled(): bool {
    return printflow_online_payment_mode() === 'manual_gcash';
}

function printflow_paymongo_online_payment_enabled(): bool {
    return printflow_online_payment_mode() === 'paymongo';
}

function printflow_provider_payments_ready(): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $payments = db_query("SHOW TABLES LIKE 'provider_payments'") ?: [];
    $events = db_query("SHOW TABLES LIKE 'provider_webhook_events'") ?: [];
    return $ready = !empty($payments) && !empty($events);
}

function printflow_provider_payment_mode_supported(string $mode): bool {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['test', 'live'], true) || !printflow_provider_payments_ready()) {
        return false;
    }
    $columns = db_query("SHOW COLUMNS FROM provider_payments LIKE 'mode'") ?: [];
    $type = strtolower((string)($columns[0]['Type'] ?? ''));
    return $type !== '' && str_contains($type, "'{$mode}'");
}

function printflow_money_to_centavos($amount): int {
    $value = trim((string)$amount);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        return 0;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    if (strlen($whole) > 7) {
        return 0;
    }
    return ((int)$whole * 100) + (int)substr($fraction, 0, 2);
}

function printflow_provider_payment_public(array $payment): array {
    $status = (string)($payment['status'] ?? '');
    $paymentStatus = (string)($payment['payment_status'] ?? $status);
    $providerStatus = (string)($payment['provider_status'] ?? $status);
    $method = strtolower(trim((string)($payment['payment_method'] ?? '')));
    $methodLabel = $method === 'qrph'
        ? 'QR Ph'
        : ($method !== '' ? strtoupper($method) : 'PayMongo');

    $mode = (string)($payment['mode'] ?? '');
    $amountDue = (int)($payment['amount_centavos'] ?? 0);
    $paidAmount = array_key_exists('paid_amount_centavos', $payment)
        && $payment['paid_amount_centavos'] !== null
        ? (int)$payment['paid_amount_centavos']
        : ($status === 'paid' ? $amountDue : 0);
    $remaining = max(0, $amountDue - $paidAmount);
    $providerPaymentId = (string)($payment['provider_payment_id'] ?? '');
    $paymentFlow = (string)($payment['payment_flow'] ?? 'payment_link');
    $qrExpiresAt = (string)($payment['qr_expires_at'] ?? '');
    $qrExpiresEpoch = $qrExpiresAt !== '' ? strtotime($qrExpiresAt) : false;
    $qrIsActive = $paymentFlow === 'payment_intent'
        && $status === 'awaiting_payment'
        && !empty($payment['qr_image_url'])
        && ($qrExpiresEpoch === false || $qrExpiresEpoch > time());

    return [
        // Keep payment_id as the legacy internal ledger id and expose an
        // unambiguous name for new clients.
        'payment_id' => (int)($payment['id'] ?? 0),
        'ledger_payment_id' => (int)($payment['id'] ?? 0),
        'order_id' => (int)($payment['order_id'] ?? 0),
        'channel' => (string)($payment['channel'] ?? ''),
        'mode' => $mode,
        'test_mode' => $mode === 'test',
        'payment_flow' => $paymentFlow,
        'status' => $status,
        'payment_status' => $paymentStatus,
        'provider_status' => $providerStatus,
        'payment_method' => $method,
        'payment_method_label' => $methodLabel,
        'amount' => $amountDue,
        'amount_due_centavos' => $amountDue,
        'paid_amount_centavos' => $paidAmount,
        'remaining_balance_centavos' => $remaining,
        'currency' => (string)($payment['currency'] ?? 'PHP'),
        'payment_link_id' => (string)($payment['link_id'] ?? ''),
        'payment_intent_id' => (string)($payment['payment_intent_id'] ?? ''),
        'payment_method_id' => (string)($payment['payment_method_id'] ?? ''),
        'qr_image_url' => $qrIsActive ? (string)$payment['qr_image_url'] : '',
        'qr_expires_at' => $qrExpiresAt !== '' ? $qrExpiresAt : null,
        'qr_expires_at_epoch' => $qrExpiresEpoch !== false ? $qrExpiresEpoch : null,
        'retryable' => in_array($status, ['failed', 'expired', 'cancelled'], true),
        'terminal' => in_array($status, ['paid', 'failed', 'expired', 'cancelled'], true),
        'checkout_url' => $status !== 'paid'
            && $paymentFlow === 'payment_link'
            && printflow_paymongo_checkout_url_is_safe((string)($payment['checkout_url'] ?? ''))
                ? (string)$payment['checkout_url']
                : '',
        'provider_payment_id' => $providerPaymentId,
        'payment_reference' => $providerPaymentId,
        'reference_number' => (string)($payment['reference_number'] ?? ''),
        'created_at' => $payment['created_at'] ?? null,
        'paid_at' => $payment['paid_at'] ?? null,
        'provider_paid_at' => $payment['provider_paid_at'] ?? ($payment['paid_at'] ?? null),
        'reconciliation_error_code' => (string)($payment['reconciliation_error_code'] ?? ''),
        'pos_completed' => !empty($payment['fulfillment_applied_at']),
    ];
}

function printflow_provider_payment_claim_reconciliation(int $ledgerId, int $minimumSeconds = 5): bool {
    if ($ledgerId <= 0) {
        return false;
    }
    if (!db_table_has_column('provider_payments', 'last_reconciled_at')) {
        return true;
    }
    $minimumSeconds = max(3, min(60, $minimumSeconds));
    return db_execute_affected_rows(
        "UPDATE provider_payments
         SET last_reconciled_at = NOW()
         WHERE id = ?
           AND (last_reconciled_at IS NULL
                OR last_reconciled_at <= DATE_SUB(NOW(), INTERVAL ? SECOND))",
        'ii',
        [$ledgerId, $minimumSeconds]
    ) === 1;
}

function printflow_provider_payment_set_reconciliation_error(int $ledgerId, array $errors): void {
    if ($ledgerId <= 0 || !db_table_has_column('provider_payments', 'reconciliation_error_code')) {
        return;
    }
    $safe = array_values(array_unique(array_filter(array_map(
        static fn($error): string => substr((string)preg_replace('/[^a-z0-9_-]/i', '', (string)$error), 0, 40),
        $errors
    ))));
    $code = $safe === [] ? null : substr(implode(',', $safe), 0, 100);
    db_execute(
        'UPDATE provider_payments SET reconciliation_error_code = ? WHERE id = ?',
        'si',
        [$code, $ledgerId]
    );
}

/**
 * Reconcile one immutable ledger row against PayMongo. The browser never
 * supplies a success flag; every transition is based on the provider GET.
 */
function printflow_provider_payment_reconcile(array $payment): array {
    if (empty($payment['id'])) {
        return ['ok' => false, 'paid' => false, 'errors' => ['payment_not_found']];
    }
    if ((string)($payment['status'] ?? '') === 'paid' && !empty($payment['provider_payment_id'])) {
        $result = printflow_provider_payment_mark_paid(
            (int)$payment['id'],
            (string)$payment['provider_payment_id'],
            (string)($payment['payment_method'] ?? ''),
            isset($payment['paid_amount_centavos']) ? (int)$payment['paid_amount_centavos'] : null,
            (string)($payment['reference_number'] ?? ''),
            $payment['provider_paid_at'] ?? null
        );
        return [
            'ok' => !empty($result['ok']),
            'paid' => !empty($result['ok']),
            'result' => $result,
            'errors' => empty($result['ok']) ? ['finalization_failed'] : [],
        ];
    }
    if ((string)($payment['payment_flow'] ?? 'payment_link') === 'payment_intent') {
        return printflow_provider_payment_reconcile_intent($payment);
    }
    if ((string)($payment['status'] ?? '') !== 'awaiting_payment'
        || empty($payment['link_id'])) {
        return ['ok' => true, 'paid' => false, 'errors' => []];
    }

    $verified = printflow_paymongo_get_paid_link_payment(
        (string)$payment['link_id'],
        (string)($payment['mode'] ?? '')
    );
    $errors = printflow_provider_payment_revalidation_errors($payment, $verified);
    if ($errors !== []) {
        // provider_status means the link is simply not paid yet; it is not an
        // operational error. Persist all other codes for staff diagnostics.
        $diagnosticErrors = array_values(array_diff($errors, ['provider_status']));
        printflow_provider_payment_set_reconciliation_error((int)$payment['id'], $diagnosticErrors);
        return [
            'ok' => $diagnosticErrors === [],
            'paid' => false,
            'errors' => $errors,
            'verified' => $verified,
        ];
    }

    $result = printflow_provider_payment_mark_paid(
        (int)$payment['id'],
        (string)$verified['payment_id'],
        (string)($verified['payment_method'] ?? ''),
        (int)($verified['amount'] ?? 0),
        (string)($verified['reference_number'] ?? ''),
        $verified['provider_paid_at'] ?? null
    );
    printflow_provider_payment_set_reconciliation_error(
        (int)$payment['id'],
        empty($result['ok']) ? ['finalization_failed'] : []
    );
    return [
        'ok' => !empty($result['ok']),
        'paid' => !empty($result['ok']),
        'result' => $result,
        'verified' => $verified,
        'errors' => empty($result['ok']) ? ['finalization_failed'] : [],
    ];
}

function printflow_provider_payment_revalidation_errors(array $payment, array $verified): array {
    $errors = [];
    $mode = strtolower((string)($payment['mode'] ?? ''));
    if (($payment['provider'] ?? '') !== 'paymongo' || !in_array($mode, ['test', 'live'], true)) {
        $errors[] = 'mode';
    }
    $expectedLive = $mode === 'live';
    if ((bool)($verified['livemode'] ?? !$expectedLive) !== $expectedLive
        || (string)($verified['mode'] ?? $mode) !== $mode) {
        $errors[] = 'livemode';
    }
    if (empty($verified['ok']) || empty($verified['paid'])
        || strtolower((string)($verified['status'] ?? '')) !== 'paid') {
        $errors[] = 'provider_status';
    }
    if ((int)($verified['amount'] ?? 0) !== (int)($payment['amount_centavos'] ?? 0)) {
        $errors[] = 'amount';
    }
    if (strtoupper((string)($verified['currency'] ?? '')) !== 'PHP') {
        $errors[] = 'currency';
    }
    if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', (string)($verified['payment_id'] ?? ''))) {
        $errors[] = 'payment_id';
    }

    $subject = printflow_provider_payment_load_subject(
        (string)($payment['subject_type'] ?? ''),
        (int)($payment['subject_id'] ?? 0)
    );
    if (empty($subject)) {
        $errors[] = 'subject';
        return array_values(array_unique($errors));
    }
    if ((int)($subject['customer_id'] ?? 0) !== (int)($payment['customer_id'] ?? 0)) {
        $errors[] = 'customer';
    }
    if ((int)($payment['order_id'] ?? 0) > 0
        && (int)($subject['order_id'] ?? 0) !== (int)$payment['order_id']) {
        $errors[] = 'order';
    }
    $currentAmount = printflow_money_to_centavos($subject['total_amount'] ?? '');
    if ($currentAmount <= 0 || $currentAmount !== (int)($payment['amount_centavos'] ?? 0)) {
        $errors[] = 'subject_amount';
    }
    return array_values(array_unique($errors));
}

function printflow_provider_payment_record_transition(
    int $ledgerId,
    int $orderId,
    string $eventKey,
    string $oldStatus,
    string $newStatus,
    string $actorType,
    int $actorId = 0
): bool {
    if (!db_table_has_column('provider_payment_status_history', 'event_key')) {
        return false;
    }
    return db_execute_affected_rows(
        "INSERT IGNORE INTO provider_payment_status_history
            (provider_payment_id, order_id, event_key, old_status, new_status, actor_type, actor_id)
         VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, NULLIF(?, 0))",
        'iissssi',
        [$ledgerId, $orderId, $eventKey, $oldStatus, $newStatus, $actorType, $actorId]
    ) === 1;
}

function printflow_order_status_supports(string $status): bool {
    $columns = db_query("SHOW COLUMNS FROM orders LIKE 'status'") ?: [];
    $type = (string)($columns[0]['Type'] ?? '');
    if ($type === '' || stripos($type, 'enum(') !== 0) {
        return $type !== '';
    }
    if (stripos($type, "'" . $status . "'") !== false) {
        return true;
    }
    if (!ensure_order_status_values([$status])) {
        return false;
    }
    $columns = db_query("SHOW COLUMNS FROM orders LIKE 'status'") ?: [];
    return stripos((string)($columns[0]['Type'] ?? ''), "'" . $status . "'") !== false;
}

function printflow_provider_payment_find(
    string $subjectType,
    int $subjectId,
    string $channel,
    string $mode = ''
): array {
    if (!printflow_provider_payments_ready()) {
        return [];
    }
    $requestedMode = strtolower(trim($mode));
    $modeSql = in_array($requestedMode, ['test', 'live'], true) ? ' AND mode = ?' : '';
    $types = 'sis' . ($modeSql !== '' ? 's' : '');
    $params = [$subjectType, $subjectId, $channel];
    if ($modeSql !== '') {
        $params[] = $requestedMode;
    }
    $rows = db_query(
        "SELECT * FROM provider_payments
         WHERE subject_type = ? AND subject_id = ? AND channel = ?
           AND provider = 'paymongo'{$modeSql}
         ORDER BY CASE WHEN status = 'paid' THEN 0 WHEN status = 'awaiting_payment' THEN 1 ELSE 2 END,
                  id DESC
         LIMIT 1",
        $types,
        $params
    ) ?: [];
    return $rows[0] ?? [];
}

/**
 * Safely deactivate an awaiting opposite PayMongo flow before reusing the
 * subject ledger for the method the customer explicitly selected.
 */
function printflow_provider_payment_supersede_active_flow(
    string $subjectType,
    int $subjectId,
    string $channel,
    string $mode,
    string $targetFlow,
    int $actorId = 0
): array {
    $existing = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
    if (empty($existing)) {
        return ['ok' => true];
    }
    $existingFlow = (string)($existing['payment_flow'] ?? 'payment_link');
    $existingStatus = (string)($existing['status'] ?? '');
    if ($existingFlow === $targetFlow || !in_array($existingStatus, ['generating', 'awaiting_payment', 'paid'], true)) {
        return ['ok' => true];
    }
    if ($existingStatus === 'paid') {
        return [
            'ok' => false,
            'http_status' => 409,
            'error_code' => 'payment_already_paid',
            'message' => 'This order already has a confirmed PayMongo payment.',
        ];
    }
    if ($existingStatus === 'generating') {
        return [
            'ok' => false,
            'http_status' => 409,
            'error_code' => 'payment_switch_in_progress',
            'message' => 'The previous payment method is still being prepared. Please wait a moment and try again.',
        ];
    }

    $providerResult = [];
    $providerStatus = '';
    $expectedProviderId = '';
    if ($existingFlow === 'payment_link') {
        $expectedProviderId = trim((string)($existing['link_id'] ?? ''));
        $providerResult = printflow_paymongo_archive_payment_link($expectedProviderId, $mode);
        $providerStatus = strtolower(trim((string)($providerResult['status'] ?? '')));
        $providerConfirmed = !empty($providerResult['ok'])
            && hash_equals($expectedProviderId, (string)($providerResult['id'] ?? ''))
            && $providerStatus === 'archived';
    } elseif ($existingFlow === 'payment_intent') {
        $expectedProviderId = trim((string)($existing['payment_intent_id'] ?? ''));
        $providerResult = printflow_paymongo_cancel_payment_intent($expectedProviderId, $mode);
        $providerStatus = strtolower(trim((string)($providerResult['status'] ?? '')));
        $providerConfirmed = !empty($providerResult['ok'])
            && hash_equals($expectedProviderId, (string)($providerResult['id'] ?? ''))
            && in_array($providerStatus, ['cancelled', 'canceled'], true);
    } else {
        return [
            'ok' => false,
            'http_status' => 409,
            'error_code' => 'active_payment_flow_conflict',
            'message' => 'The active PayMongo payment type cannot be switched automatically.',
        ];
    }

    if (!$providerConfirmed) {
        $providerHttpStatus = (int)($providerResult['http_status'] ?? 502);
        error_log('[paymongo-switch] ' . json_encode([
            'ledger_id' => (int)$existing['id'],
            'from_flow' => $existingFlow,
            'target_flow' => $targetFlow,
            'provider_http_status' => $providerHttpStatus,
            'provider_error_code' => (string)($providerResult['error_code'] ?? 'invalid_switch_response'),
        ], JSON_UNESCAPED_SLASHES));
        return [
            'ok' => false,
            'http_status' => $providerHttpStatus >= 400 && $providerHttpStatus < 500 ? 409 : 502,
            'error_code' => 'payment_flow_switch_failed',
            'message' => 'The previous payment method could not be closed safely. Please try again.',
        ];
    }

    $cancelled = db_execute_affected_rows(
        "UPDATE provider_payments
         SET status = 'cancelled', payment_status = 'cancelled', provider_status = ?,
             last_error_code = ?, checkout_url = NULL, qr_image_url = NULL, updated_at = NOW()
         WHERE id = ? AND status = 'awaiting_payment' AND payment_flow = ?",
        'ssis',
        [
            $providerStatus,
            'superseded_by_' . $targetFlow,
            (int)$existing['id'],
            $existingFlow,
        ]
    );
    if ($cancelled !== 1) {
        $raced = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
        if ((string)($raced['status'] ?? '') === 'paid') {
            return [
                'ok' => false,
                'http_status' => 409,
                'error_code' => 'payment_already_paid',
                'message' => 'Payment was confirmed while the payment method was being changed.',
            ];
        }
        return [
            'ok' => false,
            'http_status' => 409,
            'error_code' => 'payment_flow_changed',
            'message' => 'The payment state changed. Refresh and try again.',
        ];
    }
    printflow_provider_payment_record_transition(
        (int)$existing['id'],
        (int)($existing['order_id'] ?? 0),
        'flow-switch-' . (int)$existing['id'] . '-' . $targetFlow,
        'awaiting_payment',
        'cancelled',
        $channel === 'online' ? 'customer' : 'staff',
        $actorId
    );
    return ['ok' => true, 'superseded' => true];
}

function printflow_provider_payment_for_customer(
    int $customerId,
    string $subjectType,
    int $subjectId
): array {
    if (!printflow_provider_payments_ready() || $customerId <= 0 || $subjectId <= 0) {
        return [];
    }
    $mode = printflow_paymongo_mode();
    if (!in_array($mode, ['test', 'live'], true)) {
        return [];
    }
    $rows = db_query(
        "SELECT * FROM provider_payments
         WHERE customer_id = ? AND subject_type = ? AND subject_id = ?
           AND channel = 'online' AND provider = 'paymongo' AND mode = ?
         ORDER BY CASE WHEN status = 'paid' THEN 0 WHEN status = 'awaiting_payment' THEN 1 ELSE 2 END,
                  id DESC LIMIT 1",
        'isis',
        [$customerId, $subjectType, $subjectId, $mode]
    ) ?: [];
    return $rows[0] ?? [];
}

function printflow_provider_payment_load_subject(string $subjectType, int $subjectId): array {
    if ($subjectType === 'order') {
        $priceAuditSelect = db_table_has_column('orders', 'price_finalized_at')
            && db_table_has_column('orders', 'price_finalized_by')
            ? 'price_finalized_at, price_finalized_by,'
            : 'NULL AS price_finalized_at, NULL AS price_finalized_by,';
        $rows = db_query(
            "SELECT order_id AS subject_id, order_id, NULL AS job_order_id,
                    customer_id, branch_id, total_amount, payment_status,
                    {$priceAuditSelect}
                    status AS order_status, order_type, order_source
             FROM orders WHERE order_id = ? LIMIT 1",
            'i',
            [$subjectId]
        ) ?: [];
    } elseif ($subjectType === 'job_order') {
        $rows = db_query(
            "SELECT id AS subject_id, order_id, id AS job_order_id,
                    customer_id, branch_id, estimated_total AS total_amount,
                    payment_status, status AS order_status,
                    'custom' AS order_type, 'online' AS order_source
             FROM job_orders WHERE id = ? LIMIT 1",
            'i',
            [$subjectId]
        ) ?: [];
    } else {
        return [];
    }

    return $rows[0] ?? [];
}

function printflow_provider_payment_manual_review_pending(array $subject): bool {
    if (!printflow_provider_payments_ready()) {
        return false;
    }
    $orderId = (int)($subject['order_id'] ?? 0);
    $jobOrderId = (int)($subject['job_order_id'] ?? 0);
    $rows = db_query(
        "SELECT id FROM payment_submissions
         WHERE ((? > 0 AND order_id = ?) OR (? > 0 AND job_order_id = ?))
           AND verification_status IN ('Pending Review', 'Needs Review', 'Matched')
         ORDER BY id DESC LIMIT 1",
        'iiii',
        [$orderId, $orderId, $jobOrderId, $jobOrderId]
    ) ?: [];
    return !empty($rows);
}

function printflow_provider_payment_create_link(
    string $subjectType,
    int $subjectId,
    string $channel,
    int $createdBy
): array {
    global $conn;
    if (!printflow_provider_payments_ready()) {
        return ['ok' => false, 'http_status' => 503, 'message' => 'The payment migration has not been applied.'];
    }
    $mode = printflow_paymongo_mode();
    if ($mode === '' || !printflow_provider_payment_mode_supported($mode)) {
        return [
            'ok' => false,
            'http_status' => 503,
            'message' => 'PayMongo is not configured for this environment or the payment migration is incomplete.',
        ];
    }
    if (!in_array($channel, ['online', 'pos'], true)) {
        return ['ok' => false, 'http_status' => 400, 'message' => 'Unsupported payment channel.'];
    }

    $subject = printflow_provider_payment_load_subject($subjectType, $subjectId);
    if (empty($subject)) {
        return ['ok' => false, 'http_status' => 404, 'message' => 'The order was not found.'];
    }
    if (strcasecmp((string)($subject['payment_status'] ?? ''), 'Paid') === 0) {
        return ['ok' => false, 'http_status' => 409, 'message' => 'This order is already paid.'];
    }
    $normalizedOrderStatus = strtoupper(str_replace(' ', '_', trim((string)($subject['order_status'] ?? ''))));
    if (in_array($normalizedOrderStatus, ['CANCELLED', 'REJECTED', 'COMPLETED'], true)) {
        return ['ok' => false, 'http_status' => 409, 'message' => 'This order can no longer be paid.'];
    }
    if ($channel === 'online'
        && !in_array($normalizedOrderStatus, ['TO_PAY', 'PAYMENT_CONFIRMED'], true)) {
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => 'The final price must be approved before creating a payment link.',
        ];
    }
    if (function_exists('printflow_order_price_is_final')
        && !printflow_order_price_is_final($subject)) {
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => 'The final price must be approved before creating a payment link.',
        ];
    }
    if (printflow_provider_payment_manual_review_pending($subject)) {
        return [
            'ok' => false,
            'http_status' => 409,
            'manual_proof_under_review' => true,
            'message' => 'A manual payment proof is under review. Resolve it before generating a PayMongo link.',
        ];
    }

    $amountCentavos = printflow_money_to_centavos($subject['total_amount'] ?? '');
    if ($amountCentavos < 100) {
        return ['ok' => false, 'http_status' => 400, 'message' => 'A final amount of at least PHP 1.00 is required.'];
    }

    $switch = printflow_provider_payment_supersede_active_flow(
        $subjectType,
        $subjectId,
        $channel,
        $mode,
        'payment_link',
        $createdBy
    );
    if (empty($switch['ok'])) {
        return $switch;
    }

    // Serialize price finalization and link creation on the subject row. The
    // pricing endpoint takes the same lock, so a link can never be created for
    // a stale amount during a concurrent staff edit.
    $conn->begin_transaction();
    $lockRows = $subjectType === 'order'
        ? (db_query('SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE', 'i', [$subjectId]) ?: [])
        : (db_query('SELECT id FROM job_orders WHERE id = ? FOR UPDATE', 'i', [$subjectId]) ?: []);
    $lockedSubject = !empty($lockRows)
        ? printflow_provider_payment_load_subject($subjectType, $subjectId)
        : [];
    $lockedStatus = strtoupper(str_replace(' ', '_', trim((string)($lockedSubject['order_status'] ?? ''))));
    $lockedAmount = printflow_money_to_centavos($lockedSubject['total_amount'] ?? '');
    if (empty($lockedSubject)
        || strcasecmp((string)($lockedSubject['payment_status'] ?? ''), 'Paid') === 0
        || in_array($lockedStatus, ['CANCELLED', 'REJECTED', 'COMPLETED'], true)
        || ($channel === 'online' && !in_array($lockedStatus, ['TO_PAY', 'PAYMENT_CONFIRMED'], true))
        || (function_exists('printflow_order_price_is_final') && !printflow_order_price_is_final($lockedSubject))
        || $lockedAmount < 100
        || printflow_provider_payment_manual_review_pending($lockedSubject)) {
        $conn->rollback();
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => 'The order changed before the payment link was created. Refresh and try again.',
        ];
    }
    $subject = $lockedSubject;
    $amountCentavos = $lockedAmount;

    $crossChannelRows = db_query(
        "SELECT id FROM provider_payments
         WHERE subject_type = ? AND subject_id = ? AND channel <> ?
           AND provider = 'paymongo'
           AND status IN ('generating', 'awaiting_payment', 'paid')
         ORDER BY id DESC LIMIT 1 FOR UPDATE",
        'sis',
        [$subjectType, $subjectId, $channel]
    ) ?: [];
    if (!empty($crossChannelRows)) {
        $conn->rollback();
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => 'This order already has an active PayMongo payment in another channel.',
        ];
    }

    $crossModeRows = db_query(
        "SELECT id FROM provider_payments
         WHERE subject_type = ? AND subject_id = ? AND channel = ?
           AND provider = 'paymongo' AND mode <> ?
           AND status IN ('generating', 'awaiting_payment', 'paid')
         ORDER BY id DESC LIMIT 1 FOR UPDATE",
        'siss',
        [$subjectType, $subjectId, $channel, $mode]
    ) ?: [];
    if (!empty($crossModeRows)) {
        $conn->rollback();
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => 'This order already has a PayMongo payment in another environment. Reconcile or cancel it before creating a new link.',
        ];
    }

    $existing = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
    if (!empty($existing)) {
        $existingFlow = (string)($existing['payment_flow'] ?? 'payment_link');
        if ($existingFlow !== 'payment_link'
            && in_array((string)$existing['status'], ['generating', 'awaiting_payment', 'paid'], true)) {
            $conn->rollback();
            return [
                'ok' => false,
                'http_status' => 409,
                'error_code' => 'active_payment_flow_conflict',
                'message' => 'This order already has an active PayMongo Payment Intent.',
            ];
        }
        if (in_array((string)$existing['status'], ['awaiting_payment', 'paid'], true)) {
            if ((int)($existing['amount_centavos'] ?? 0) !== $amountCentavos) {
                $conn->rollback();
                return [
                    'ok' => false,
                    'http_status' => 409,
                    'message' => 'The final price no longer matches the existing payment link. Resolve the payment before changing the price.',
                ];
            }
            $linkIsReusable = (string)$existing['status'] === 'paid'
                || printflow_paymongo_checkout_url_is_safe((string)($existing['checkout_url'] ?? ''));
            if ($linkIsReusable) {
                $conn->commit();
                return ['ok' => true, 'reused' => true, 'payment' => printflow_provider_payment_public($existing)];
            }
            db_execute(
                "UPDATE provider_payments SET status = 'failed', last_error_code = 'invalid_stored_checkout_url', updated_at = NOW()
                 WHERE id = ? AND status = 'awaiting_payment'",
                'i',
                [(int)$existing['id']]
            );
            $existing['status'] = 'failed';
        }
        if ((string)$existing['status'] === 'generating') {
            $reclaimed = db_execute_affected_rows(
                "UPDATE provider_payments SET updated_at = NOW()
                 WHERE id = ? AND status = 'generating'
                   AND updated_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)",
                'i',
                [(int)$existing['id']]
            );
            if ($reclaimed !== 1) {
                $conn->commit();
                return [
                    'ok' => true,
                    'reused' => true,
                    'in_progress' => true,
                    'payment' => printflow_provider_payment_public($existing),
                ];
            }
            $ledgerId = (int)$existing['id'];
        } else {
            $resetParts = [
                "status = 'generating'",
                'amount_centavos = ?',
                'last_error_code = NULL',
                'checkout_url = NULL',
                'link_id = NULL',
            ];
            if (db_table_has_column('provider_payments', 'payment_flow')) {
                $resetParts[] = "payment_flow = 'payment_link'";
            }
            if (db_table_has_column('provider_payments', 'payment_status')) {
                $resetParts[] = "payment_status = 'generating'";
            }
            if (db_table_has_column('provider_payments', 'provider_status')) {
                $resetParts[] = "provider_status = 'generating'";
            }
            foreach (['provider_payment_id', 'payment_method', 'reference_number'] as $column) {
                if (db_table_has_column('provider_payments', $column)) {
                    $resetParts[] = "{$column} = NULL";
                }
            }
            foreach (['payment_intent_id', 'payment_method_id', 'qr_image_url', 'qr_expires_at', 'client_key'] as $column) {
                if (db_table_has_column('provider_payments', $column)) {
                    $resetParts[] = "{$column} = NULL";
                }
            }
            $resetParts[] = 'updated_at = NOW()';
            $claimed = db_execute_affected_rows(
                "UPDATE provider_payments
                 SET " . implode(', ', $resetParts) . "
                 WHERE id = ? AND status IN ('failed', 'expired', 'cancelled')",
                'ii',
                [$amountCentavos, (int)$existing['id']]
            );
            if ($claimed !== 1) {
                $conn->rollback();
                $raced = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
                if (!empty($raced) && in_array((string)($raced['status'] ?? ''), ['generating', 'awaiting_payment'], true)) {
                    return ['ok' => true, 'reused' => true, 'in_progress' => true, 'payment' => printflow_provider_payment_public($raced)];
                }
                return ['ok' => false, 'http_status' => 500, 'error_code' => 'link_retry_claim_failed', 'message' => 'Secure checkout could not be prepared safely.'];
            }
            $ledgerId = (int)$existing['id'];
        }
    } else {
        $insertColumns = [
            'subject_type', 'subject_id', 'order_id', 'job_order_id', 'customer_id', 'branch_id',
            'channel', 'mode', 'amount_centavos', 'status', 'created_by',
        ];
        $insertValues = "?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, 'generating', ?";
        $created = db_execute(
            "INSERT INTO provider_payments
                (" . implode(', ', $insertColumns) . ")
             VALUES ({$insertValues})",
            'siiiiissii',
            [
                $subjectType,
                $subjectId,
                (int)($subject['order_id'] ?? 0) ?: null,
                (int)($subject['job_order_id'] ?? 0) ?: null,
                (int)$subject['customer_id'],
                (int)($subject['branch_id'] ?? 0),
                $channel,
                $mode,
                $amountCentavos,
                $createdBy,
            ]
        );
        if (!$created) {
            $raced = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
            if (!empty($raced) && in_array((string)$raced['status'], ['generating', 'awaiting_payment', 'paid'], true)) {
                $conn->commit();
                return [
                    'ok' => true,
                    'reused' => true,
                    'in_progress' => (string)$raced['status'] === 'generating',
                    'payment' => printflow_provider_payment_public($raced),
                ];
            }
            $conn->rollback();
            return ['ok' => false, 'http_status' => 500, 'error_code' => 'link_ledger_creation_failed', 'message' => 'Secure checkout could not be prepared safely.'];
        }
        $ledgerId = (int)$conn->insert_id;
    }
    $idempotencyKey = 'printflow-link-' . $mode . '-' . $subjectType . '-' . $subjectId . '-ledger-' . $ledgerId;
    $flowUpdates = [];
    if (db_table_has_column('provider_payments', 'payment_flow')) $flowUpdates[] = "payment_flow = 'payment_link'";
    if (db_table_has_column('provider_payments', 'payment_status')) $flowUpdates[] = "payment_status = 'generating'";
    if (db_table_has_column('provider_payments', 'provider_status')) $flowUpdates[] = "provider_status = 'generating'";
    if ($flowUpdates !== [] && !db_execute(
        'UPDATE provider_payments SET ' . implode(', ', $flowUpdates) . ' WHERE id = ?',
        'i',
        [$ledgerId]
    )) {
        $conn->rollback();
        return ['ok' => false, 'http_status' => 500, 'error_code' => 'link_flow_persistence_failed', 'message' => 'Secure checkout could not be prepared safely.'];
    }
    if (db_table_has_column('provider_payments', 'idempotency_key')) {
        if (!db_execute(
            'UPDATE provider_payments SET idempotency_key = ? WHERE id = ?',
            'si',
            [$idempotencyKey, $ledgerId]
        )) {
            $conn->rollback();
            return ['ok' => false, 'http_status' => 500, 'message' => 'The payment request could not be prepared safely.'];
        }
    }
    $conn->commit();

    $orderLabel = $subjectType === 'order' ? 'Order' : 'Job Order';
    $apiResult = printflow_paymongo_create_order_payment_link(
        $amountCentavos,
        "Mr. and Mrs. Print {$orderLabel} #{$subjectId}",
        "PrintFlow {$channel} " . ucfirst($mode) . " Mode payment",
        [
            'printflow_payment_id' => (string)$ledgerId,
            'subject_type' => $subjectType,
            'subject_id' => (string)$subjectId,
            'order_id' => (string)((int)($subject['order_id'] ?? 0)),
            'channel' => $channel,
            'mode' => $mode,
        ],
        $mode,
        $idempotencyKey
    );

    if (empty($apiResult['ok']) || (bool)($apiResult['livemode'] ?? true) !== ($mode === 'live')
        || empty($apiResult['id']) || empty($apiResult['url'])
        || (int)($apiResult['amount'] ?? 0) !== $amountCentavos) {
        $providerHttpStatus = (int)($apiResult['http_status'] ?? 0);
        $providerRejected = empty($apiResult['ok']);
        $errorCode = substr((string)($apiResult['error_code'] ?? (
            $providerRejected ? 'link_creation_failed' : 'invalid_payment_link_response'
        )), 0, 100);
        db_execute(
            "UPDATE provider_payments
             SET status = 'failed', last_error_code = ?, updated_at = NOW()
             WHERE id = ? AND status = 'generating'",
            'si',
            [$errorCode, $ledgerId]
        );
        error_log('[paymongo-link] ' . json_encode([
            'action' => 'create_link',
            'payment_flow' => 'payment_link',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ledger_id' => $ledgerId,
            'mode' => $mode,
            'provider_http_status' => $providerHttpStatus,
            'provider_error_code' => $errorCode,
        ], JSON_UNESCAPED_SLASHES));
        return [
            'ok' => false,
            'http_status' => $providerRejected && in_array($providerHttpStatus, [400, 401, 403, 404, 409, 422, 429, 500, 502, 503], true)
                ? ($providerHttpStatus === 429 ? 503 : ($providerHttpStatus >= 500 ? 502 : $providerHttpStatus))
                : 502,
            'message' => $providerRejected
                ? (string)($apiResult['message'] ?? 'PayMongo could not create the Payment Link.')
                : 'PayMongo returned an invalid Payment Link response. Please try again.',
            'error_code' => $errorCode,
        ];
    }

    $setReference = db_table_has_column('provider_payments', 'reference_number');
    $stored = $setReference
        ? db_execute(
            "UPDATE provider_payments
             SET status = 'awaiting_payment', payment_status = 'awaiting_payment', provider_status = ?,
                 payment_flow = 'payment_link', link_id = ?, checkout_url = ?, reference_number = NULLIF(?, ''),
                 last_error_code = NULL, updated_at = NOW()
             WHERE id = ? AND status = 'generating'",
            'ssssi',
            [(string)($apiResult['status'] ?? 'active'), (string)$apiResult['id'], (string)$apiResult['url'], (string)($apiResult['reference_number'] ?? ''), $ledgerId]
        )
        : db_execute(
            "UPDATE provider_payments
             SET status = 'awaiting_payment', payment_status = 'awaiting_payment', provider_status = ?,
                 payment_flow = 'payment_link', link_id = ?, checkout_url = ?,
                 last_error_code = NULL, updated_at = NOW()
             WHERE id = ? AND status = 'generating'",
            'sssi',
            [(string)($apiResult['status'] ?? 'active'), (string)$apiResult['id'], (string)$apiResult['url'], $ledgerId]
        );
    if (!$stored) {
        return [
            'ok' => false,
            'http_status' => 500,
            'message' => 'The Payment Link was created but could not be saved. Do not retry until staff reconciles this payment.',
            'error_code' => 'link_persistence_failed',
        ];
    }
    create_notification(
        (int)$subject['customer_id'],
        'Customer',
        'A PayMongo ' . ($mode === 'test' ? 'Test ' : '') . 'Payment Link is ready for order #' . ((int)($subject['order_id'] ?? 0) ?: $subjectId) . '.',
        'Payment',
        false,
        false,
        (int)($subject['order_id'] ?? 0)
    );
    $createdPayment = db_query("SELECT * FROM provider_payments WHERE id = ? LIMIT 1", 'i', [$ledgerId]) ?: [];

    return [
        'ok' => true,
        'reused' => false,
        'payment' => printflow_provider_payment_public($createdPayment[0] ?? []),
    ];
}

function printflow_provider_payment_intent_schema_ready(): bool {
    foreach ([
        'payment_flow', 'payment_intent_id', 'payment_method_id', 'qr_image_url',
        'qr_expires_at', 'client_key', 'idempotency_key', 'payment_status', 'provider_status',
    ] as $column) {
        if (!db_table_has_column('provider_payments', $column)) {
            return false;
        }
    }
    return true;
}

function printflow_provider_payment_creation_error(
    array $subject,
    string $channel,
    string $noun = 'payment request'
): array {
    if (empty($subject)) {
        return ['ok' => false, 'http_status' => 404, 'message' => 'The order was not found.'];
    }
    if (strcasecmp((string)($subject['payment_status'] ?? ''), 'Paid') === 0) {
        return ['ok' => false, 'http_status' => 409, 'message' => 'This order is already paid.'];
    }
    $status = strtoupper(str_replace(' ', '_', trim((string)($subject['order_status'] ?? ''))));
    if (in_array($status, ['CANCELLED', 'REJECTED', 'COMPLETED'], true)) {
        return ['ok' => false, 'http_status' => 409, 'message' => 'This order can no longer be paid.'];
    }
    if ($channel === 'online' && !in_array($status, ['TO_PAY', 'PAYMENT_CONFIRMED'], true)) {
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => "The final price must be approved before creating this {$noun}.",
        ];
    }
    if (function_exists('printflow_order_price_is_final')
        && !printflow_order_price_is_final($subject)) {
        return [
            'ok' => false,
            'http_status' => 409,
            'message' => "The final price must be approved before creating this {$noun}.",
        ];
    }
    if (printflow_provider_payment_manual_review_pending($subject)) {
        return [
            'ok' => false,
            'http_status' => 409,
            'manual_proof_under_review' => true,
            'message' => 'A manual payment proof is under review. Resolve it before creating a PayMongo payment.',
        ];
    }
    if (printflow_money_to_centavos($subject['total_amount'] ?? '') < 100) {
        return [
            'ok' => false,
            'http_status' => 400,
            'message' => 'A final amount of at least PHP 1.00 is required.',
        ];
    }
    return [];
}

function printflow_provider_payment_create_intent(
    string $subjectType,
    int $subjectId,
    string $channel,
    int $createdBy,
    string $paymentMethod = 'qrph'
): array {
    global $conn;
    $paymentMethod = strtolower(trim($paymentMethod));
    if (!printflow_provider_payments_ready() || !printflow_provider_payment_intent_schema_ready()) {
        return [
            'ok' => false,
            'http_status' => 503,
            'message' => 'The Payment Intent migration has not been applied.',
        ];
    }
    $mode = printflow_paymongo_mode();
    if ($mode === '' || !printflow_provider_payment_mode_supported($mode)) {
        return ['ok' => false, 'http_status' => 503, 'message' => 'PayMongo is not configured for this environment.'];
    }
    if (!in_array($channel, ['online', 'pos'], true)
        || !in_array($subjectType, ['order', 'job_order'], true)) {
        return ['ok' => false, 'http_status' => 400, 'message' => 'Unsupported payment subject or channel.'];
    }
    if ($paymentMethod !== 'qrph'
        || !in_array($paymentMethod, printflow_paymongo_enabled_methods($mode), true)) {
        return ['ok' => false, 'http_status' => 400, 'message' => 'QRPh is not enabled for this environment.'];
    }

    $subject = printflow_provider_payment_load_subject($subjectType, $subjectId);
    $validation = printflow_provider_payment_creation_error($subject, $channel, 'Payment Intent');
    if ($validation !== []) {
        return $validation;
    }

    $switch = printflow_provider_payment_supersede_active_flow(
        $subjectType,
        $subjectId,
        $channel,
        $mode,
        'payment_intent',
        $createdBy
    );
    if (empty($switch['ok'])) {
        return $switch;
    }

    $transactionOpen = false;
    try {
        $conn->begin_transaction();
        $transactionOpen = true;
        $lockRows = $subjectType === 'order'
            ? (db_query('SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE', 'i', [$subjectId]) ?: [])
            : (db_query('SELECT id FROM job_orders WHERE id = ? FOR UPDATE', 'i', [$subjectId]) ?: []);
        $subject = !empty($lockRows)
            ? printflow_provider_payment_load_subject($subjectType, $subjectId)
            : [];
        $validation = printflow_provider_payment_creation_error($subject, $channel, 'Payment Intent');
        if ($validation !== []) {
            $conn->rollback();
            $transactionOpen = false;
            return $validation;
        }
        $amountCentavos = printflow_money_to_centavos($subject['total_amount'] ?? '');

        $crossChannelRows = db_query(
            "SELECT id FROM provider_payments
             WHERE subject_type = ? AND subject_id = ? AND channel <> ?
               AND provider = 'paymongo'
               AND status IN ('generating', 'awaiting_payment', 'paid')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            'sis',
            [$subjectType, $subjectId, $channel]
        ) ?: [];
        if (!empty($crossChannelRows)) {
            $conn->rollback();
            $transactionOpen = false;
            return [
                'ok' => false,
                'http_status' => 409,
                'message' => 'This order already has an active PayMongo payment in another channel.',
            ];
        }

        $crossModeRows = db_query(
            "SELECT id FROM provider_payments
             WHERE subject_type = ? AND subject_id = ? AND channel = ?
               AND provider = 'paymongo' AND mode <> ?
               AND status IN ('generating', 'awaiting_payment', 'paid')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            'siss',
            [$subjectType, $subjectId, $channel, $mode]
        ) ?: [];
        if (!empty($crossModeRows)) {
            $conn->rollback();
            $transactionOpen = false;
            return [
                'ok' => false,
                'http_status' => 409,
                'message' => 'This order already has a PayMongo payment in another environment.',
            ];
        }

        $existing = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
        $ledgerId = 0;
        $reuseIntent = false;
        $preparedForAttachment = false;
        $previousIntentId = '';
        if (!empty($existing)) {
            $sameFlow = (string)($existing['payment_flow'] ?? 'payment_link') === 'payment_intent';
            $sameMethod = (string)($existing['payment_method'] ?? '') === $paymentMethod;
            $previousIntentId = (string)($existing['payment_intent_id'] ?? '');
            if (in_array((string)$existing['status'], ['awaiting_payment', 'paid'], true)) {
                if (!$sameFlow || !$sameMethod) {
                    $conn->rollback();
                    $transactionOpen = false;
                    return [
                        'ok' => false,
                        'http_status' => 409,
                        'message' => 'This order already has an active PayMongo Payment Link or another payment flow.',
                    ];
                }
                if ((int)($existing['amount_centavos'] ?? 0) !== $amountCentavos) {
                    $conn->rollback();
                    $transactionOpen = false;
                    return [
                        'ok' => false,
                        'http_status' => 409,
                        'message' => 'The final price no longer matches the existing Payment Intent.',
                    ];
                }
                $qrIsUsable = !empty($existing['qr_image_url'])
                    && (empty($existing['qr_expires_at'])
                        || strtotime((string)$existing['qr_expires_at']) > time());
                if ((string)$existing['status'] === 'paid' || $qrIsUsable) {
                    $conn->commit();
                    $transactionOpen = false;
                    return [
                        'ok' => true,
                        'reused' => true,
                        'ledger' => $existing,
                        'payment' => printflow_provider_payment_public($existing),
                    ];
                }
                $ledgerId = (int)$existing['id'];
                $qrHasExpired = !empty($existing['qr_expires_at'])
                    && strtotime((string)$existing['qr_expires_at']) <= time();
                $reuseIntent = !$qrHasExpired
                    && !empty($existing['payment_intent_id'])
                    && !empty($existing['client_key']);
                $resetExpiredIntentSql = $qrHasExpired
                    ? ', payment_intent_id = NULL, client_key = NULL'
                    : '';
                $claimed = db_execute_affected_rows(
                    "UPDATE provider_payments
                     SET status = 'generating', payment_status = 'generating', provider_status = 'generating',
                         payment_method_id = NULL, qr_image_url = NULL, qr_expires_at = NULL,
                         last_error_code = NULL, reconciliation_error_code = NULL{$resetExpiredIntentSql},
                         updated_at = NOW()
                     WHERE id = ? AND status = 'awaiting_payment'",
                    'i',
                    [$ledgerId]
                );
                if ($claimed !== 1) {
                    $conn->rollback();
                    $transactionOpen = false;
                    return ['ok' => false, 'http_status' => 409, 'message' => 'QRPh regeneration is already in progress.'];
                }
                $preparedForAttachment = true;
            }
            if ($ledgerId <= 0) {
                $ledgerId = (int)$existing['id'];
            }
            if (!$preparedForAttachment && (string)$existing['status'] === 'generating') {
                $claimed = db_execute_affected_rows(
                    "UPDATE provider_payments SET updated_at = NOW()
                     WHERE id = ? AND status = 'generating'
                       AND updated_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)",
                    'i',
                    [$ledgerId]
                );
                if ($claimed !== 1) {
                    // Another request already owns this short-lived creation
                    // lease. This is an idempotent in-progress state, not a
                    // business conflict. Return the same ledger so callers can
                    // poll it instead of creating a duplicate Payment Intent.
                    $conn->commit();
                    $transactionOpen = false;
                    return [
                        'ok' => true,
                        'reused' => true,
                        'in_progress' => true,
                        'ledger' => $existing,
                        'payment' => printflow_provider_payment_public($existing),
                    ];
                }
                $reuseIntent = $sameFlow && !empty($existing['payment_intent_id']) && !empty($existing['client_key']);
            } elseif (!$preparedForAttachment) {
                $reuseIntent = $sameFlow
                    && !empty($existing['payment_intent_id'])
                    && !empty($existing['client_key']);
                $claimed = db_execute_affected_rows(
                    "UPDATE provider_payments
                     SET status = 'generating', payment_status = 'generating', provider_status = 'generating',
                         payment_flow = 'payment_intent', payment_method = ?, amount_centavos = ?,
                         link_id = NULL, checkout_url = NULL, provider_payment_id = NULL,
                         payment_intent_id = NULL, payment_method_id = NULL, client_key = NULL,
                         qr_image_url = NULL, qr_expires_at = NULL,
                         last_error_code = NULL, reconciliation_error_code = NULL, updated_at = NOW()
                     WHERE id = ? AND status IN ('failed', 'expired', 'cancelled')",
                    'sii',
                    [$paymentMethod, $amountCentavos, $ledgerId]
                );
                if ($claimed !== 1) {
                    $conn->rollback();
                    $transactionOpen = false;
                    $raced = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
                    if (!empty($raced) && in_array((string)($raced['status'] ?? ''), ['generating', 'awaiting_payment'], true)) {
                        return [
                            'ok' => true,
                            'reused' => true,
                            'in_progress' => true,
                            'ledger' => $raced,
                            'payment' => printflow_provider_payment_public($raced),
                        ];
                    }
                    return ['ok' => false, 'http_status' => 500, 'message' => 'The Payment Intent retry could not be claimed safely.'];
                }
                $reuseIntent = false;
            }
        } else {
            $created = db_execute(
                "INSERT INTO provider_payments
                    (subject_type, subject_id, order_id, job_order_id, customer_id, branch_id,
                     channel, mode, amount_centavos, status, payment_status, provider_status,
                     payment_flow, payment_method, created_by)
                 VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, NULLIF(?, 0), ?, ?, ?,
                         'generating', 'generating', 'generating', 'payment_intent', ?, ?)",
                'siiiiissisi',
                [
                    $subjectType,
                    $subjectId,
                    (int)($subject['order_id'] ?? 0),
                    (int)($subject['job_order_id'] ?? 0),
                    (int)$subject['customer_id'],
                    (int)($subject['branch_id'] ?? 0),
                    $channel,
                    $mode,
                    $amountCentavos,
                    $paymentMethod,
                    $createdBy,
                ]
            );
            if (!$created) {
                $conn->rollback();
                $transactionOpen = false;
                $raced = printflow_provider_payment_find($subjectType, $subjectId, $channel, $mode);
                if (!empty($raced) && in_array((string)($raced['status'] ?? ''), ['generating', 'awaiting_payment'], true)) {
                    return [
                        'ok' => true,
                        'reused' => true,
                        'in_progress' => true,
                        'ledger' => $raced,
                        'payment' => printflow_provider_payment_public($raced),
                    ];
                }
                return ['ok' => false, 'http_status' => 500, 'message' => 'The Payment Intent ledger could not be created safely.'];
            }
            $ledgerId = (int)$conn->insert_id;
        }

        $orderId = (int)($subject['order_id'] ?? 0);
        $identity = $orderId > 0 ? 'order-' . $orderId : 'job-order-' . $subjectId;
        $idempotencyKey = 'printflow-intent-' . $mode . '-' . $identity
            . '-ledger-' . $ledgerId . '-' . $paymentMethod;
        if (!empty($previousIntentId) && !$reuseIntent) {
            $idempotencyKey .= '-retry-' . substr(hash('sha256', $previousIntentId), 0, 12);
        }
        if (!db_execute(
            'UPDATE provider_payments SET idempotency_key = ? WHERE id = ?',
            'si',
            [$idempotencyKey, $ledgerId]
        )) {
            throw new RuntimeException('The Payment Intent idempotency key could not be stored.');
        }
        $conn->commit();
        $transactionOpen = false;

        if (!$reuseIntent) {
            $intent = printflow_paymongo_create_payment_intent(
                $amountCentavos,
                'Mr. and Mrs. Print ' . ($subjectType === 'order' ? 'Order' : 'Job Order') . " #{$subjectId}",
                [
                    'printflow_payment_id' => (string)$ledgerId,
                    'subject_type' => $subjectType,
                    'subject_id' => (string)$subjectId,
                    'order_id' => (string)$orderId,
                    'job_order_id' => (string)((int)($subject['job_order_id'] ?? 0)),
                    'customer_id' => (string)((int)$subject['customer_id']),
                    'channel' => $channel,
                    'mode' => $mode,
                    'payment_flow' => 'payment_intent',
                    'payment_method' => $paymentMethod,
                ],
                [$paymentMethod],
                $mode,
                $idempotencyKey
            );
            $validIntent = !empty($intent['ok'])
                && (bool)($intent['livemode'] ?? true) === ($mode === 'live')
                && !empty($intent['id'])
                && !empty($intent['client_key'])
                && (int)($intent['amount'] ?? 0) === $amountCentavos
                && strtoupper((string)($intent['currency'] ?? '')) === 'PHP';
            if (!$validIntent) {
                $errorCode = substr((string)($intent['error_code'] ?? 'intent_creation_failed'), 0, 100);
                printflow_provider_payment_mark_failed($ledgerId, $errorCode, 'intent_creation_failed');
                return [
                    'ok' => false,
                    'http_status' => (int)($intent['http_status'] ?? 502),
                    'message' => (string)($intent['message'] ?? 'PayMongo could not create the Payment Intent.'),
                    'error_code' => $errorCode,
                ];
            }
            $stored = db_execute(
                "UPDATE provider_payments
                 SET payment_intent_id = ?, client_key = ?, provider_status = ?,
                     last_error_code = NULL, updated_at = NOW()
                 WHERE id = ? AND status = 'generating'",
                'sssi',
                [(string)$intent['id'], (string)$intent['client_key'], (string)$intent['status'], $ledgerId]
            );
            if (!$stored) {
                return [
                    'ok' => false,
                    'http_status' => 500,
                    'message' => 'The Payment Intent was created but could not be saved. Do not retry until staff reconciles it.',
                    'error_code' => 'intent_persistence_failed',
                ];
            }
        }

        $rows = db_query('SELECT * FROM provider_payments WHERE id = ? LIMIT 1', 'i', [$ledgerId]) ?: [];
        $ledger = $rows[0] ?? [];
        return [
            'ok' => !empty($ledger),
            'reused' => $reuseIntent,
            'ledger' => $ledger,
            'payment' => printflow_provider_payment_public($ledger),
        ];
    } catch (Throwable $error) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        error_log('PayMongo Payment Intent preparation failed for subject #' . $subjectId);
        return ['ok' => false, 'http_status' => 500, 'message' => 'The Payment Intent could not be prepared safely.'];
    }
}

function printflow_provider_payment_create_qrph(
    string $subjectType,
    int $subjectId,
    string $channel,
    int $createdBy,
    int $expirySeconds = 1800
): array {
    $expirySeconds = max(60, min(9000, $expirySeconds));
    $intentResult = printflow_provider_payment_create_intent(
        $subjectType,
        $subjectId,
        $channel,
        $createdBy,
        'qrph'
    );
    if (empty($intentResult['ok'])) {
        return $intentResult;
    }
    $ledger = isset($intentResult['ledger']) && is_array($intentResult['ledger'])
        ? $intentResult['ledger']
        : [];
    $ledgerId = (int)($ledger['id'] ?? 0);
    if ($ledgerId <= 0) {
        return ['ok' => false, 'http_status' => 500, 'message' => 'The payment ledger could not be loaded.'];
    }
    if (!empty($intentResult['in_progress'])
        && (string)($ledger['status'] ?? '') === 'generating') {
        return [
            'ok' => true,
            'reused' => true,
            'in_progress' => true,
            'payment' => printflow_provider_payment_public($ledger),
            'qr_image_url' => '',
            'qr_expires_at' => null,
        ];
    }
    if ((string)($ledger['status'] ?? '') === 'paid') {
        return [
            'ok' => true,
            'reused' => true,
            'payment' => printflow_provider_payment_public($ledger),
            'qr_image_url' => '',
            'qr_expires_at' => $ledger['qr_expires_at'] ?? null,
        ];
    }
    if ((string)($ledger['status'] ?? '') === 'awaiting_payment'
        && !empty($ledger['qr_image_url'])
        && (empty($ledger['qr_expires_at']) || strtotime((string)$ledger['qr_expires_at']) > time())) {
        return [
            'ok' => true,
            'reused' => true,
            'payment' => printflow_provider_payment_public($ledger),
            'qr_image_url' => (string)$ledger['qr_image_url'],
            'qr_expires_at' => $ledger['qr_expires_at'],
        ];
    }

    $mode = (string)$ledger['mode'];
    $paymentMethod = printflow_paymongo_create_payment_method(
        'qrph',
        [],
        $expirySeconds,
        $mode,
        ''
    );
    $validMethod = !empty($paymentMethod['ok'])
        && (bool)($paymentMethod['livemode'] ?? true) === ($mode === 'live')
        && (string)($paymentMethod['type'] ?? '') === 'qrph'
        && !empty($paymentMethod['id']);
    if (!$validMethod) {
        $errorCode = substr((string)($paymentMethod['error_code'] ?? 'payment_method_creation_failed'), 0, 100);
        printflow_provider_payment_mark_failed($ledgerId, $errorCode, 'payment_method_creation_failed');
        return [
            'ok' => false,
            'http_status' => (int)($paymentMethod['http_status'] ?? 502),
            'message' => (string)($paymentMethod['message'] ?? 'PayMongo could not create the QRPh Payment Method.'),
            'error_code' => $errorCode,
        ];
    }
    if (!db_execute(
        "UPDATE provider_payments SET payment_method_id = ?, updated_at = NOW()
         WHERE id = ? AND status = 'generating' AND payment_intent_id = ?",
        'sis',
        [(string)$paymentMethod['id'], $ledgerId, (string)$ledger['payment_intent_id']]
    )) {
        printflow_provider_payment_mark_failed($ledgerId, 'payment_method_persistence_failed', 'payment_method_persistence_failed');
        return [
            'ok' => false,
            'http_status' => 500,
            'message' => 'The QRPh Payment Method was created but could not be saved.',
            'error_code' => 'payment_method_persistence_failed',
        ];
    }

    $attached = printflow_paymongo_attach_payment_method(
        (string)$ledger['payment_intent_id'],
        (string)$paymentMethod['id'],
        (string)$ledger['client_key'],
        $mode,
        ''
    );
    $validAttachment = !empty($attached['ok'])
        && (bool)($attached['livemode'] ?? true) === ($mode === 'live')
        && (string)($attached['id'] ?? '') === (string)$ledger['payment_intent_id']
        && (int)($attached['amount'] ?? 0) === (int)$ledger['amount_centavos']
        && strtoupper((string)($attached['currency'] ?? '')) === 'PHP'
        && (string)($attached['status'] ?? '') === 'awaiting_next_action'
        && !empty($attached['qr_image_url']);
    if (!$validAttachment) {
        $errorCode = substr((string)($attached['error_code'] ?? 'payment_method_attach_failed'), 0, 100);
        printflow_provider_payment_mark_failed($ledgerId, $errorCode, 'payment_method_attach_failed');
        return [
            'ok' => false,
            'http_status' => (int)($attached['http_status'] ?? 502),
            'message' => (string)($attached['message'] ?? 'PayMongo could not attach the QRPh Payment Method.'),
            'error_code' => $errorCode,
        ];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + $expirySeconds);
    $stored = db_execute(
        "UPDATE provider_payments
         SET status = 'awaiting_payment', payment_status = 'awaiting_payment', provider_status = ?,
             qr_image_url = ?, qr_expires_at = ?, last_error_code = NULL,
             reconciliation_error_code = NULL, updated_at = NOW()
         WHERE id = ? AND status = 'generating' AND payment_intent_id = ? AND payment_method_id = ?",
        'sssiss',
        [
            (string)$attached['status'],
            (string)$attached['qr_image_url'],
            $expiresAt,
            $ledgerId,
            (string)$ledger['payment_intent_id'],
            (string)$paymentMethod['id'],
        ]
    );
    if (!$stored) {
        return [
            'ok' => false,
            'http_status' => 500,
            'message' => 'The QRPh code was created but could not be saved. Do not retry until staff reconciles it.',
            'error_code' => 'qrph_persistence_failed',
        ];
    }
    $rows = db_query('SELECT * FROM provider_payments WHERE id = ? LIMIT 1', 'i', [$ledgerId]) ?: [];
    return [
        'ok' => true,
        'reused' => false,
        'payment' => printflow_provider_payment_public($rows[0] ?? []),
        'qr_image_url' => (string)$attached['qr_image_url'],
        'qr_expires_at' => $expiresAt,
    ];
}

function printflow_provider_payment_reconcile_intent(array $payment): array {
    $ledgerId = (int)($payment['id'] ?? 0);
    $intentId = (string)($payment['payment_intent_id'] ?? '');
    $mode = (string)($payment['mode'] ?? '');
    if ($ledgerId <= 0 || !preg_match('/^pi_[A-Za-z0-9_-]+$/', $intentId)) {
        return ['ok' => false, 'paid' => false, 'errors' => ['payment_intent_id']];
    }
    $intent = printflow_paymongo_get_payment_intent($intentId, $mode);
    $errors = [];
    if (empty($intent['ok']) || (string)($intent['id'] ?? '') !== $intentId) {
        $errors[] = 'payment_intent';
    }
    if ((bool)($intent['livemode'] ?? ($mode !== 'live')) !== ($mode === 'live')
        || (string)($intent['mode'] ?? $mode) !== $mode) {
        $errors[] = 'livemode';
    }
    if ((int)($intent['amount'] ?? 0) !== (int)($payment['amount_centavos'] ?? 0)) {
        $errors[] = 'amount';
    }
    if (strtoupper((string)($intent['currency'] ?? '')) !== 'PHP') {
        $errors[] = 'currency';
    }
    $metadata = isset($intent['metadata']) && is_array($intent['metadata']) ? $intent['metadata'] : [];
    if (isset($metadata['printflow_payment_id'])
        && (int)$metadata['printflow_payment_id'] !== $ledgerId) {
        $errors[] = 'metadata';
    }
    if ($errors !== []) {
        printflow_provider_payment_set_reconciliation_error($ledgerId, $errors);
        return ['ok' => false, 'paid' => false, 'errors' => array_values(array_unique($errors))];
    }

    $status = strtolower((string)($intent['status'] ?? ''));
    db_execute(
        'UPDATE provider_payments SET provider_status = ?, updated_at = NOW() WHERE id = ?',
        'si',
        [$status, $ledgerId]
    );
    if ($status === 'succeeded') {
        $providerPaymentId = (string)($intent['payment_id'] ?? '');
        if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $providerPaymentId)) {
            printflow_provider_payment_set_reconciliation_error($ledgerId, ['provider_payment_id']);
            return ['ok' => false, 'paid' => false, 'errors' => ['provider_payment_id']];
        }
        $verified = printflow_paymongo_get_payment($providerPaymentId, $mode);
        $paymentIntentId = (string)($verified['payment_intent_id'] ?? '');
        $errors = printflow_provider_payment_revalidation_errors($payment, $verified);
        if ($paymentIntentId !== '' && $paymentIntentId !== $intentId) {
            $errors[] = 'payment_intent';
        }
        if ($errors !== []) {
            printflow_provider_payment_set_reconciliation_error($ledgerId, $errors);
            return ['ok' => false, 'paid' => false, 'errors' => array_values(array_unique($errors))];
        }
        $result = printflow_provider_payment_mark_paid(
            $ledgerId,
            $providerPaymentId,
            (string)($verified['payment_method'] ?? 'qrph'),
            (int)($verified['amount'] ?? 0),
            (string)($verified['reference_number'] ?? ''),
            $verified['provider_paid_at'] ?? null
        );
        printflow_provider_payment_set_reconciliation_error(
            $ledgerId,
            empty($result['ok']) ? ['finalization_failed'] : []
        );
        return [
            'ok' => !empty($result['ok']),
            'paid' => !empty($result['ok']),
            'result' => $result,
            'errors' => empty($result['ok']) ? ['finalization_failed'] : [],
        ];
    }
    if (in_array($status, ['failed', 'cancelled'], true)) {
        $result = printflow_provider_payment_mark_failed($ledgerId, 'provider_' . $status, 'provider_' . $status);
        return ['ok' => !empty($result['ok']), 'paid' => false, 'result' => $result, 'errors' => []];
    }
    if (!empty($payment['qr_expires_at']) && strtotime((string)$payment['qr_expires_at']) <= time()) {
        $result = printflow_provider_payment_mark_expired($ledgerId, 'qrph_expired');
        return ['ok' => !empty($result['ok']), 'paid' => false, 'result' => $result, 'errors' => []];
    }
    printflow_provider_payment_set_reconciliation_error($ledgerId, []);
    return ['ok' => true, 'paid' => false, 'errors' => [], 'provider_status' => $status];
}

function printflow_provider_payment_mark_terminal(
    int $ledgerId,
    string $status,
    string $errorCode,
    string $providerStatus
): array {
    global $conn;
    if ($ledgerId <= 0 || !in_array($status, ['failed', 'expired'], true)) {
        return ['ok' => false, 'message' => 'Invalid terminal payment state.'];
    }
    $errorCode = substr((string)preg_replace('/[^a-z0-9_-]/i', '', $errorCode), 0, 100);
    $providerStatus = substr((string)preg_replace('/[^a-z0-9_-]/i', '', $providerStatus), 0, 30);
    $transactionOpen = false;
    try {
        $conn->begin_transaction();
        $transactionOpen = true;
        $rows = db_query('SELECT * FROM provider_payments WHERE id = ? FOR UPDATE', 'i', [$ledgerId]) ?: [];
        if (empty($rows)) {
            throw new RuntimeException('Payment record not found.');
        }
        $payment = $rows[0];
        if ((string)($payment['status'] ?? '') === 'paid') {
            $conn->commit();
            return ['ok' => true, 'already_processed' => true, 'payment' => $payment];
        }
        $oldStatus = (string)($payment['status'] ?? '');
        if (!db_execute(
            "UPDATE provider_payments
             SET status = ?, payment_status = ?, provider_status = ?, last_error_code = ?, updated_at = NOW()
             WHERE id = ? AND status <> 'paid'",
            'ssssi',
            [$status, $status, $providerStatus !== '' ? $providerStatus : $status, $errorCode ?: null, $ledgerId]
        )) {
            throw new RuntimeException('Payment terminal state could not be saved.');
        }
        printflow_provider_payment_record_transition(
            $ledgerId,
            (int)($payment['order_id'] ?? 0),
            'payment_' . $status,
            $oldStatus,
            $status,
            'PayMongo',
            0
        );
        $conn->commit();
        $transactionOpen = false;
        $refreshed = db_query('SELECT * FROM provider_payments WHERE id = ? LIMIT 1', 'i', [$ledgerId]) ?: [];
        return ['ok' => true, 'already_processed' => $oldStatus === $status, 'payment' => $refreshed[0] ?? $payment];
    } catch (Throwable $error) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        error_log('PayMongo terminal state update failed for ledger #' . $ledgerId);
        return ['ok' => false, 'message' => 'The payment state could not be updated.'];
    }
}

function printflow_provider_payment_mark_failed(
    int $ledgerId,
    string $errorCode = 'payment_failed',
    string $providerStatus = 'failed'
): array {
    return printflow_provider_payment_mark_terminal($ledgerId, 'failed', $errorCode, $providerStatus);
}

function printflow_provider_payment_mark_expired(
    int $ledgerId,
    string $providerStatus = 'qrph_expired'
): array {
    return printflow_provider_payment_mark_terminal($ledgerId, 'expired', 'qrph_expired', $providerStatus);
}

function printflow_provider_payment_mark_paid(
    int $ledgerId,
    string $providerPaymentId,
    string $paymentMethod = '',
    ?int $paidAmountCentavos = null,
    string $referenceNumber = '',
    $providerPaidAt = null
): array {
    global $conn;
    if (!printflow_order_status_supports('Payment Confirmed')) {
        return ['ok' => false, 'message' => 'The payment-confirmed workflow status is unavailable.'];
    }

    $transactionOpen = false;
    $payment = [];
    $transitionInserted = false;
    try {
        $conn->begin_transaction();
        $transactionOpen = true;
        $rows = db_query(
            'SELECT * FROM provider_payments WHERE id = ? FOR UPDATE',
            'i',
            [$ledgerId]
        ) ?: [];
        if (empty($rows)) {
            throw new RuntimeException('Payment record not found.');
        }
        $payment = $rows[0];
        $mode = strtolower((string)($payment['mode'] ?? ''));
        if (($payment['provider'] ?? '') !== 'paymongo' || !in_array($mode, ['test', 'live'], true)) {
            throw new RuntimeException('This is not a supported PayMongo payment.');
        }
        if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $providerPaymentId)) {
            throw new RuntimeException('The provider payment identifier is invalid.');
        }

        $subject = printflow_provider_payment_load_subject(
            (string)$payment['subject_type'],
            (int)$payment['subject_id']
        );
        if (empty($subject)) {
            throw new RuntimeException('The linked order no longer exists.');
        }
        if ((int)($subject['customer_id'] ?? 0) !== (int)($payment['customer_id'] ?? 0)
            || ((int)($payment['order_id'] ?? 0) > 0
                && (int)($subject['order_id'] ?? 0) !== (int)$payment['order_id'])) {
            throw new RuntimeException('The linked order does not match the payment record.');
        }
        $subjectStatus = strtoupper(str_replace(' ', '_', trim((string)($subject['order_status'] ?? ''))));
        if (in_array($subjectStatus, ['CANCELLED', 'REJECTED'], true)) {
            throw new RuntimeException('A cancelled or rejected order cannot be marked paid automatically.');
        }
        if (printflow_money_to_centavos($subject['total_amount'] ?? '') !== (int)$payment['amount_centavos']) {
            throw new RuntimeException('The linked order amount does not match the verified payment.');
        }

        $paidAmountCentavos = $paidAmountCentavos ?? (int)($payment['paid_amount_centavos'] ?? 0);
        if ($paidAmountCentavos <= 0) {
            $paidAmountCentavos = (int)$payment['amount_centavos'];
        }
        if ($paidAmountCentavos !== (int)$payment['amount_centavos']) {
            throw new RuntimeException('The verified paid amount does not match the payment request.');
        }

        $alreadyPaid = (string)($payment['status'] ?? '') === 'paid';
        if ($alreadyPaid && !empty($payment['provider_payment_id'])
            && (string)$payment['provider_payment_id'] !== $providerPaymentId) {
            throw new RuntimeException('The payment was already finalized with a different provider transaction.');
        }
        $normalizedMethod = strtolower(trim($paymentMethod));
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $normalizedMethod)) {
            $normalizedMethod = strtolower(trim((string)($payment['payment_method'] ?? '')));
        }
        $providerPaidAtString = is_string($providerPaidAt) ? trim($providerPaidAt) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $providerPaidAtString)) {
            $providerPaidAtString = '';
        }

        $setParts = ["status = 'paid'", 'provider_payment_id = ?', 'updated_at = NOW()'];
        $types = 's';
        $params = [$providerPaymentId];
        if ($providerPaidAtString !== '') {
            $setParts[] = 'paid_at = COALESCE(paid_at, ?)';
            $types .= 's';
            $params[] = $providerPaidAtString;
        } else {
            $setParts[] = 'paid_at = COALESCE(paid_at, NOW())';
        }
        if (db_table_has_column('provider_payments', 'paid_amount_centavos')) {
            $setParts[] = 'paid_amount_centavos = ?';
            $types .= 'i';
            $params[] = $paidAmountCentavos;
        }
        if (db_table_has_column('provider_payments', 'reference_number')) {
            $setParts[] = "reference_number = COALESCE(NULLIF(?, ''), reference_number)";
            $types .= 's';
            $params[] = substr(trim($referenceNumber), 0, 100);
        }
        if (db_table_has_column('provider_payments', 'provider_paid_at')) {
            if ($providerPaidAtString !== '') {
                $setParts[] = 'provider_paid_at = COALESCE(provider_paid_at, ?)';
                $types .= 's';
                $params[] = $providerPaidAtString;
            } else {
                $setParts[] = 'provider_paid_at = COALESCE(provider_paid_at, NOW())';
            }
        }
        if (db_table_has_column('provider_payments', 'payment_status')) {
            $setParts[] = "payment_status = 'paid'";
        }
        if (db_table_has_column('provider_payments', 'provider_status')) {
            $setParts[] = "provider_status = 'paid'";
        }
        if (db_table_has_column('provider_payments', 'payment_method') && $normalizedMethod !== '') {
            $setParts[] = 'payment_method = ?';
            $types .= 's';
            $params[] = $normalizedMethod;
        }
        if (db_table_has_column('provider_payments', 'reconciliation_error_code')) {
            $setParts[] = 'reconciliation_error_code = NULL';
        }
        $types .= 'i';
        $params[] = $ledgerId;
        if (!db_execute(
            'UPDATE provider_payments SET ' . implode(', ', $setParts) . ' WHERE id = ?',
            $types,
            $params
        )) {
            throw new RuntimeException('The payment ledger could not be updated.');
        }

        $orderId = (int)($payment['order_id'] ?? 0);
        $paidAmount = $paidAmountCentavos / 100;
        $orderPaymentMethod = $normalizedMethod === 'qrph'
            ? 'QRPh'
            : ($normalizedMethod !== '' ? 'PayMongo ' . strtoupper($normalizedMethod) : 'PayMongo');
        if ($orderId > 0) {
            if (!db_execute(
                "UPDATE orders
                 SET payment_status = 'Paid', payment_method = ?, payment_reference = ?,
                     status = CASE
                         WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                              ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                               'DESIGN_APPROVED', 'TO_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                               'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                         THEN 'Payment Confirmed'
                         ELSE status
                     END,
                     updated_at = NOW()
                 WHERE order_id = ? AND status NOT IN ('Cancelled', 'Rejected')",
                'ssi',
                [$orderPaymentMethod, $providerPaymentId, $orderId]
            )) {
                throw new RuntimeException('The paid order could not be synchronized.');
            }

            if (strtolower((string)($subject['order_type'] ?? '')) !== 'product') {
                if (!db_execute(
                    "UPDATE customizations
                     SET status = CASE
                         WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                              ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                               'TO_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                               'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                         THEN 'Payment Confirmed'
                         ELSE status
                     END,
                     updated_at = NOW()
                     WHERE order_id = ?",
                    'i',
                    [$orderId]
                )) {
                    throw new RuntimeException('The customization payment status could not be synchronized.');
                }
            }

            // Payment and production are intentionally separate. Do not write
            // PAYMENT_CONFIRMED into the legacy production-status enum here.
            if (db_table_has_column('job_orders', 'payment_method')
                && db_table_has_column('job_orders', 'payment_reference')) {
                if (!db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = ?,
                         payment_method = ?, payment_reference = ?, updated_at = NOW()
                     WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                    'dssi',
                    [$paidAmount, $orderPaymentMethod, $providerPaymentId, $orderId]
                )) {
                    throw new RuntimeException('The linked production job payment could not be synchronized.');
                }
            } elseif (!db_execute(
                "UPDATE job_orders
                 SET payment_status = 'PAID', amount_paid = ?, updated_at = NOW()
                 WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                'di',
                [$paidAmount, $orderId]
            )) {
                throw new RuntimeException('The linked production job payment could not be synchronized.');
            }
        }

        if ((string)$payment['subject_type'] === 'job_order') {
            if (db_table_has_column('job_orders', 'payment_method')
                && db_table_has_column('job_orders', 'payment_reference')) {
                $jobUpdated = db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = ?,
                         payment_method = ?, payment_reference = ?, updated_at = NOW()
                     WHERE id = ? AND status <> 'CANCELLED'",
                    'dssi',
                    [$paidAmount, $orderPaymentMethod, $providerPaymentId, (int)$payment['subject_id']]
                );
            } else {
                $jobUpdated = db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = ?, updated_at = NOW()
                     WHERE id = ? AND status <> 'CANCELLED'",
                    'di',
                    [$paidAmount, (int)$payment['subject_id']]
                );
            }
            if (!$jobUpdated) {
                throw new RuntimeException('The paid job order could not be synchronized.');
            }
        }

        $transitionInserted = printflow_provider_payment_record_transition(
            $ledgerId,
            $orderId,
            'payment_confirmed',
            $alreadyPaid ? 'paid' : (string)($payment['status'] ?? 'awaiting_payment'),
            'paid',
            'PayMongo',
            0
        );
        $shouldNotify = $transitionInserted
            || (!$alreadyPaid && !db_table_has_column('provider_payment_status_history', 'event_key'));
        $conn->commit();
        $transactionOpen = false;

        if ($shouldNotify) {
            $displayCode = $orderId > 0
                ? printflow_format_order_code($orderId, '')
                : printflow_format_job_code((int)$payment['subject_id']);
            $amountLabel = "\xE2\x82\xB1" . number_format($paidAmount, 2);
            create_notification(
                (int)$payment['customer_id'],
                'Customer',
                "Your payment of {$amountLabel} for order {$displayCode} has been received. Your order is awaiting production.",
                'Payment',
                false,
                false,
                $orderId
            );
            notify_shop_users(
                "PayMongo payment confirmed for {$displayCode}. The order is awaiting staff production approval.",
                'Payment',
                false,
                false,
                $orderId
            );
        }

        $refreshed = db_query('SELECT * FROM provider_payments WHERE id = ? LIMIT 1', 'i', [$ledgerId]) ?: [];
        return [
            'ok' => true,
            'already_processed' => $alreadyPaid,
            'payment' => $refreshed[0] ?? $payment,
        ];
    } catch (Throwable $error) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        printflow_provider_payment_set_reconciliation_error($ledgerId, ['finalization_failed']);
        error_log('PayMongo payment finalization failed for ledger #' . $ledgerId);
        return ['ok' => false, 'message' => 'The verified payment could not be finalized.'];
    }
}

function printflow_provider_payment_complete_pos(int $ledgerId, int $staffId): array {
    global $conn;
    $transactionOpen = false;
    try {
        $conn->begin_transaction();
        $transactionOpen = true;
        $rows = db_query(
            'SELECT * FROM provider_payments WHERE id = ? FOR UPDATE',
            'i',
            [$ledgerId]
        ) ?: [];
        if (empty($rows)) {
            throw new RuntimeException('Payment record not found.');
        }
        $payment = $rows[0];
        if ((string)($payment['channel'] ?? '') !== 'pos'
            || (string)($payment['provider'] ?? '') !== 'paymongo'
            || !in_array((string)($payment['mode'] ?? ''), ['test', 'live'], true)) {
            throw new RuntimeException('This is not a PayMongo POS payment.');
        }
        if ((string)($payment['status'] ?? '') !== 'paid'
            || empty($payment['provider_payment_id'])
            || empty($payment['paid_at'])) {
            throw new RuntimeException('Payment has not been verified.');
        }
        if (!empty($payment['fulfillment_applied_at'])) {
            $conn->commit();
            return ['ok' => true, 'already_completed' => true, 'payment' => $payment];
        }

        $subject = printflow_provider_payment_load_subject(
            (string)$payment['subject_type'],
            (int)$payment['subject_id']
        );
        if (empty($subject) || (int)($subject['order_id'] ?? 0) <= 0) {
            throw new RuntimeException('The linked POS order no longer exists.');
        }
        if ((int)($subject['branch_id'] ?? 0) !== (int)($payment['branch_id'] ?? 0)
            || printflow_money_to_centavos($subject['total_amount'] ?? '') !== (int)$payment['amount_centavos']) {
            throw new RuntimeException('The POS order no longer matches the verified payment.');
        }
        $normalizedStatus = strtoupper(str_replace(' ', '_', trim((string)($subject['order_status'] ?? ''))));
        if ($normalizedStatus === 'CANCELLED') {
            throw new RuntimeException('A cancelled order cannot be completed.');
        }

        $orderId = (int)$subject['order_id'];
        $isProduct = strtolower((string)($subject['order_type'] ?? '')) === 'product';
        if ($isProduct) {
            require_once __DIR__ . '/product_branch_stock.php';
            $items = db_query(
                'SELECT product_id, quantity FROM order_items WHERE order_id = ?',
                'i',
                [$orderId]
            ) ?: [];
            if (empty($items)) {
                throw new RuntimeException('The POS order has no items.');
            }
            foreach ($items as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                if ($productId <= 0 || $quantity <= 0
                    || !printflow_product_deduct_stock_for_branch(
                        $productId,
                        (int)$payment['branch_id'],
                        $quantity
                    )
                    || !printflow_record_product_inventory_transaction(
                        $productId,
                        'OUT',
                        $quantity,
                        'ORDER',
                        $orderId,
                        'PayMongo POS sale: Order #' . $orderId,
                        $staffId,
                        date('Y-m-d'),
                        (int)$payment['branch_id']
                    )) {
                    throw new RuntimeException('Inventory could not be finalized for the paid POS order.');
                }
            }
            if (!db_execute(
                "UPDATE orders SET status = 'Completed', updated_at = NOW()
                 WHERE order_id = ? AND status <> 'Cancelled'",
                'i',
                [$orderId]
            )) {
                throw new RuntimeException('The POS order could not be completed.');
            }
        }

        if (db_execute_affected_rows(
            'UPDATE provider_payments
             SET fulfillment_applied_at = NOW(), updated_at = NOW()
             WHERE id = ? AND fulfillment_applied_at IS NULL',
            'i',
            [$ledgerId]
        ) !== 1) {
            throw new RuntimeException('The POS completion marker could not be stored.');
        }
        printflow_provider_payment_record_transition(
            $ledgerId,
            $orderId,
            'pos_transaction_completed',
            'paid',
            $isProduct ? 'completed' : 'payment_confirmed',
            'Staff',
            $staffId
        );
        $conn->commit();
        $transactionOpen = false;
        $refreshed = db_query('SELECT * FROM provider_payments WHERE id = ? LIMIT 1', 'i', [$ledgerId]) ?: [];
        return ['ok' => true, 'already_completed' => false, 'payment' => $refreshed[0] ?? $payment];
    } catch (Throwable $error) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        error_log('PayMongo POS completion failed for ledger #' . $ledgerId);
        return ['ok' => false, 'message' => 'The paid POS transaction could not be completed.'];
    }
}

function printflow_paymongo_webhook_secret_for_mode(string $mode): string {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['test', 'live'], true)) {
        return '';
    }
    $specific = printflow_paymongo_env(
        $mode === 'live' ? 'PAYMONGO_LIVE_WEBHOOK_SECRET' : 'PAYMONGO_TEST_WEBHOOK_SECRET'
    );
    if ($specific !== '') {
        return $specific;
    }

    // Backward compatibility is scoped to the configured mode so the same
    // legacy secret can never authenticate both test and live callbacks.
    $configuredMode = strtolower(printflow_paymongo_env('PAYMONGO_MODE'));
    return $configuredMode === $mode ? printflow_paymongo_env('PAYMONGO_WEBHOOK_SECRET') : '';
}

function printflow_paymongo_verify_webhook_signature(
    string $rawBody,
    string $signatureHeader,
    string $expectedMode = 'test',
    int $toleranceSeconds = 300
): bool {
    $expectedMode = strtolower(trim($expectedMode));
    if (!in_array($expectedMode, ['test', 'live'], true)
        || ($expectedMode === 'live' && !printflow_paymongo_live_enabled())) {
        return false;
    }
    $secret = printflow_paymongo_webhook_secret_for_mode($expectedMode);
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }
    $timestamp = isset($parts['t']) && ctype_digit($parts['t']) ? (int)$parts['t'] : 0;
    $signatureKey = $expectedMode === 'live' ? 'li' : 'te';
    $signature = strtolower((string)($parts[$signatureKey] ?? ''));
    if ($timestamp <= 0 || !preg_match('/^[a-f0-9]{64}$/', $signature)
        || abs(time() - $timestamp) > max(30, $toleranceSeconds)) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $signature);
}

function printflow_find_paymongo_link_id($value): string {
    if (is_string($value) && preg_match('/^link_[A-Za-z0-9_-]+$/', $value)) {
        return $value;
    }
    if (!is_array($value)) {
        return '';
    }
    foreach ($value as $item) {
        $found = printflow_find_paymongo_link_id($item);
        if ($found !== '') {
            return $found;
        }
    }
    return '';
}
