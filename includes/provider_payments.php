<?php
/**
 * Shared PayMongo payment ledger for customer and POS orders.
 *
 * This module never returns or logs API credentials or webhook secrets.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paymongo.php';

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
        ? 'QRPh'
        : ($method !== '' ? strtoupper($method) : 'PayMongo');

    $mode = (string)($payment['mode'] ?? '');
    $amountDue = (int)($payment['amount_centavos'] ?? 0);
    $paidAmount = array_key_exists('paid_amount_centavos', $payment)
        && $payment['paid_amount_centavos'] !== null
        ? (int)$payment['paid_amount_centavos']
        : ($status === 'paid' ? $amountDue : 0);
    $remaining = max(0, $amountDue - $paidAmount);
    $providerPaymentId = (string)($payment['provider_payment_id'] ?? '');

    return [
        // Keep payment_id as the legacy internal ledger id and expose an
        // unambiguous name for new clients.
        'payment_id' => (int)($payment['id'] ?? 0),
        'ledger_payment_id' => (int)($payment['id'] ?? 0),
        'order_id' => (int)($payment['order_id'] ?? 0),
        'channel' => (string)($payment['channel'] ?? ''),
        'mode' => $mode,
        'test_mode' => $mode === 'test',
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
        'checkout_url' => $status === 'paid' ? '' : (string)($payment['checkout_url'] ?? ''),
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

function printflow_provider_payment_for_customer(
    int $customerId,
    string $subjectType,
    int $subjectId
): array {
    if (!printflow_provider_payments_ready() || $customerId <= 0 || $subjectId <= 0) {
        return [];
    }
    $rows = db_query(
        "SELECT * FROM provider_payments
         WHERE customer_id = ? AND subject_type = ? AND subject_id = ?
           AND provider = 'paymongo'
         ORDER BY CASE WHEN status = 'paid' THEN 0 WHEN status = 'awaiting_payment' THEN 1 ELSE 2 END,
                  id DESC LIMIT 1",
        'isi',
        [$customerId, $subjectType, $subjectId]
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
        if (in_array((string)$existing['status'], ['awaiting_payment', 'paid'], true)) {
            if ((int)($existing['amount_centavos'] ?? 0) !== $amountCentavos) {
                $conn->rollback();
                return [
                    'ok' => false,
                    'http_status' => 409,
                    'message' => 'The final price no longer matches the existing payment link. Resolve the payment before changing the price.',
                ];
            }
            $conn->commit();
            return ['ok' => true, 'reused' => true, 'payment' => printflow_provider_payment_public($existing)];
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
                $conn->rollback();
                return ['ok' => false, 'http_status' => 409, 'message' => 'Payment Link generation is already in progress.'];
            }
            $ledgerId = (int)$existing['id'];
        } else {
            $claimed = db_execute_affected_rows(
                "UPDATE provider_payments
                 SET status = 'generating', amount_centavos = ?, last_error_code = NULL,
                     checkout_url = NULL, link_id = NULL, updated_at = NOW()
                 WHERE id = ? AND status IN ('failed', 'cancelled')",
                'ii',
                [$amountCentavos, (int)$existing['id']]
            );
            if ($claimed !== 1) {
                $conn->rollback();
                return ['ok' => false, 'http_status' => 409, 'message' => 'Payment Link generation is already in progress.'];
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
            if (!empty($raced) && in_array((string)$raced['status'], ['awaiting_payment', 'paid'], true)) {
                $conn->commit();
                return ['ok' => true, 'reused' => true, 'payment' => printflow_provider_payment_public($raced)];
            }
            $conn->rollback();
            return ['ok' => false, 'http_status' => 409, 'message' => 'Payment Link generation is already in progress.'];
        }
        $ledgerId = (int)$conn->insert_id;
    }
    $idempotencyKey = 'printflow-link-' . $mode . '-' . $subjectType . '-' . $subjectId . '-ledger-' . $ledgerId;
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
        $errorCode = substr((string)($apiResult['error_code'] ?? 'link_creation_failed'), 0, 100);
        db_execute(
            "UPDATE provider_payments
             SET status = 'failed', last_error_code = ?, updated_at = NOW()
             WHERE id = ? AND status = 'generating'",
            'si',
            [$errorCode, $ledgerId]
        );
        return [
            'ok' => false,
            'http_status' => (int)($apiResult['http_status'] ?? 502),
            'message' => (string)($apiResult['message'] ?? 'PayMongo could not create the Payment Link.'),
            'error_code' => $errorCode,
        ];
    }

    $setReference = db_table_has_column('provider_payments', 'reference_number');
    $stored = $setReference
        ? db_execute(
            "UPDATE provider_payments
             SET status = 'awaiting_payment', link_id = ?, checkout_url = ?, reference_number = NULLIF(?, ''),
                 last_error_code = NULL, updated_at = NOW()
             WHERE id = ? AND status = 'generating'",
            'sssi',
            [(string)$apiResult['id'], (string)$apiResult['url'], (string)($apiResult['reference_number'] ?? ''), $ledgerId]
        )
        : db_execute(
            "UPDATE provider_payments
             SET status = 'awaiting_payment', link_id = ?, checkout_url = ?,
                 last_error_code = NULL, updated_at = NOW()
             WHERE id = ? AND status = 'generating'",
            'ssi',
            [(string)$apiResult['id'], (string)$apiResult['url'], $ledgerId]
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
