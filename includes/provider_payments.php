<?php
/**
 * Shared PayMongo Test Mode payment ledger for customer and POS orders.
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

    return [
        'payment_id' => (int)($payment['id'] ?? 0),
        'order_id' => (int)($payment['order_id'] ?? 0),
        'channel' => (string)($payment['channel'] ?? ''),
        'mode' => (string)($payment['mode'] ?? ''),
        'test_mode' => (string)($payment['mode'] ?? '') === 'test',
        'status' => $status,
        'payment_status' => $paymentStatus,
        'provider_status' => $providerStatus,
        'payment_method' => $method,
        'payment_method_label' => $methodLabel,
        'amount' => (int)($payment['amount_centavos'] ?? 0),
        'currency' => (string)($payment['currency'] ?? 'PHP'),
        'payment_link_id' => (string)($payment['link_id'] ?? ''),
        'checkout_url' => (string)($payment['checkout_url'] ?? ''),
        'created_at' => $payment['created_at'] ?? null,
        'paid_at' => $payment['paid_at'] ?? null,
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

function printflow_provider_payment_revalidation_errors(array $payment, array $verified): array {
    $errors = [];
    if (($payment['provider'] ?? '') !== 'paymongo' || ($payment['mode'] ?? '') !== 'test') {
        $errors[] = 'mode';
    }
    if (($verified['test_mode'] ?? false) !== true || !empty($verified['livemode'])) {
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

function printflow_provider_payment_find(string $subjectType, int $subjectId, string $channel): array {
    if (!printflow_provider_payments_ready()) {
        return [];
    }
    $rows = db_query(
        "SELECT * FROM provider_payments
         WHERE subject_type = ? AND subject_id = ? AND channel = ?
           AND provider = 'paymongo' AND mode = 'test'
         LIMIT 1",
        'sis',
        [$subjectType, $subjectId, $channel]
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
           AND provider = 'paymongo' AND mode = 'test'
         ORDER BY id DESC LIMIT 1",
        'isi',
        [$customerId, $subjectType, $subjectId]
    ) ?: [];
    return $rows[0] ?? [];
}

function printflow_provider_payment_load_subject(string $subjectType, int $subjectId): array {
    if ($subjectType === 'order') {
        $rows = db_query(
            "SELECT order_id AS subject_id, order_id, NULL AS job_order_id,
                    customer_id, branch_id, total_amount, payment_status,
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
    if (!printflow_provider_payments_ready()) {
        return ['ok' => false, 'http_status' => 503, 'message' => 'The payment migration has not been applied.'];
    }
    if (!printflow_paymongo_test_mode()) {
        return ['ok' => false, 'http_status' => 400, 'message' => 'PayMongo Test Mode is required.'];
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

    $existing = printflow_provider_payment_find($subjectType, $subjectId, $channel);
    if (!empty($existing)) {
        if (in_array((string)$existing['status'], ['awaiting_payment', 'paid'], true)) {
            return ['ok' => true, 'reused' => true, 'payment' => printflow_provider_payment_public($existing)];
        }
        if ((string)$existing['status'] === 'generating') {
            return ['ok' => false, 'http_status' => 409, 'message' => 'Payment Link generation is already in progress.'];
        }
        $claimed = db_execute_affected_rows(
            "UPDATE provider_payments
             SET status = 'generating', amount_centavos = ?, last_error_code = NULL,
                 checkout_url = NULL, link_id = NULL, updated_at = NOW()
             WHERE id = ? AND status IN ('failed', 'cancelled')",
            'ii',
            [$amountCentavos, (int)$existing['id']]
        );
        if ($claimed !== 1) {
            return ['ok' => false, 'http_status' => 409, 'message' => 'Payment Link generation is already in progress.'];
        }
        $ledgerId = (int)$existing['id'];
    } else {
        global $conn;
        $created = db_execute(
            "INSERT INTO provider_payments
                (subject_type, subject_id, order_id, job_order_id, customer_id, branch_id,
                 channel, amount_centavos, status, created_by)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, 'generating', ?)",
            'siiiiisii',
            [
                $subjectType,
                $subjectId,
                (int)($subject['order_id'] ?? 0) ?: null,
                (int)($subject['job_order_id'] ?? 0) ?: null,
                (int)$subject['customer_id'],
                (int)($subject['branch_id'] ?? 0),
                $channel,
                $amountCentavos,
                $createdBy,
            ]
        );
        if (!$created) {
            $raced = printflow_provider_payment_find($subjectType, $subjectId, $channel);
            if (!empty($raced) && in_array((string)$raced['status'], ['awaiting_payment', 'paid'], true)) {
                return ['ok' => true, 'reused' => true, 'payment' => printflow_provider_payment_public($raced)];
            }
            return ['ok' => false, 'http_status' => 409, 'message' => 'Payment Link generation is already in progress.'];
        }
        $ledgerId = (int)$conn->insert_id;
    }

    $orderLabel = $subjectType === 'order' ? 'Order' : 'Job Order';
    $apiResult = printflow_paymongo_create_order_payment_link(
        $amountCentavos,
        "Mr. and Mrs. Print {$orderLabel} #{$subjectId}",
        "PrintFlow {$channel} Test Mode payment",
        [
            'printflow_payment_id' => (string)$ledgerId,
            'subject_type' => $subjectType,
            'subject_id' => (string)$subjectId,
            'order_id' => (string)((int)($subject['order_id'] ?? 0)),
            'channel' => $channel,
            'mode' => 'test',
        ]
    );

    if (empty($apiResult['ok']) || !empty($apiResult['livemode'])
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

    $stored = db_execute(
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
        'A PayMongo Test Payment Link is ready for order #' . ((int)($subject['order_id'] ?? 0) ?: $subjectId) . '.',
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
    string $paymentMethod = ''
): array {
    global $conn;
    if (!printflow_order_status_supports('Payment Confirmed')) {
        return ['ok' => false, 'message' => 'The payment-confirmed workflow status is unavailable.'];
    }
    $transactionOpen = false;
    $shouldNotify = false;
    $payment = [];
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
        if (($payment['provider'] ?? '') !== 'paymongo' || ($payment['mode'] ?? '') !== 'test') {
            throw new RuntimeException('Only PayMongo Test Mode payments can be finalized.');
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
        if (printflow_money_to_centavos($subject['total_amount'] ?? '') !== (int)$payment['amount_centavos']) {
            throw new RuntimeException('The linked order amount does not match the verified payment.');
        }

        $alreadyPaid = (string)($payment['status'] ?? '') === 'paid';
        $normalizedMethod = strtolower(trim($paymentMethod));
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $normalizedMethod)) {
            $normalizedMethod = strtolower(trim((string)($payment['payment_method'] ?? '')));
        }
        $setParts = [
            "status = 'paid'",
            'provider_payment_id = ?',
            'paid_at = COALESCE(paid_at, NOW())',
            'updated_at = NOW()',
        ];
        $types = 's';
        $params = [$providerPaymentId];
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
        $params[] = $ledgerId;
        $types .= 'i';
        if (!db_execute(
            'UPDATE provider_payments SET ' . implode(', ', $setParts) . ' WHERE id = ?',
            $types,
            $params
        )) {
            throw new RuntimeException('The payment ledger could not be updated.');
        }

        $orderId = (int)($payment['order_id'] ?? 0);
        if ($orderId > 0) {
            $isPos = (string)$payment['channel'] === 'pos';
            $isProduct = strtolower((string)($subject['order_type'] ?? '')) === 'product';
            if ($isProduct) {
                $nextStatus = $isPos ? 'Payment Confirmed' : 'Ready for Pickup';
                if (!db_execute(
                    "UPDATE orders
                     SET payment_status = 'Paid', payment_method = 'PayMongo Test',
                         payment_reference = ?, status = ?, updated_at = NOW()
                     WHERE order_id = ? AND status <> 'Cancelled'",
                    'ssi',
                    [$providerPaymentId, $nextStatus, $orderId]
                )) {
                    throw new RuntimeException('The paid product order could not be synchronized.');
                }
            } else {
                if (!db_execute(
                    "UPDATE orders
                     SET payment_status = 'Paid', payment_method = 'PayMongo Test',
                         payment_reference = ?,
                         status = CASE
                             WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                                  ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                                   'DESIGN_APPROVED', 'TO_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                                   'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                             THEN 'Payment Confirmed'
                             ELSE status
                         END,
                         updated_at = NOW()
                     WHERE order_id = ?",
                    'si',
                    [$providerPaymentId, $orderId]
                )) {
                    throw new RuntimeException('The paid customization order could not be synchronized.');
                }
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

            if (!$isProduct) {
                $jobStatus = 'PAYMENT_CONFIRMED';
                if (db_table_has_column('job_orders', 'payment_method')
                    && db_table_has_column('job_orders', 'payment_reference')) {
                    if (!db_execute(
                        "UPDATE job_orders
                         SET payment_status = 'PAID', amount_paid = estimated_total,
                             payment_method = 'PayMongo Test', payment_reference = ?,
                             status = CASE
                                 WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                                      ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                                       'TO_PAY', 'VERIFY_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                                       'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                                 THEN ?
                                 ELSE status
                             END,
                             updated_at = NOW()
                         WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                        'ssi',
                        [$providerPaymentId, $jobStatus, $orderId]
                    )) {
                        throw new RuntimeException('The linked production job could not be synchronized.');
                    }
                } else {
                    if (!db_execute(
                        "UPDATE job_orders
                         SET payment_status = 'PAID', amount_paid = estimated_total,
                             status = CASE
                                 WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                                      ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                                       'TO_PAY', 'VERIFY_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                                       'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                                 THEN ?
                                 ELSE status
                             END,
                             updated_at = NOW()
                         WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                        'si',
                        [$jobStatus, $orderId]
                    )) {
                        throw new RuntimeException('The linked production job could not be synchronized.');
                    }
                }
            }
        }

        if ((string)$payment['subject_type'] === 'job_order') {
            if (db_table_has_column('job_orders', 'payment_method')
                && db_table_has_column('job_orders', 'payment_reference')) {
                if (!db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = estimated_total,
                         payment_method = 'PayMongo Test', payment_reference = ?,
                         status = CASE
                             WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                                  ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                                   'TO_PAY', 'VERIFY_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                                   'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                             THEN 'PAYMENT_CONFIRMED'
                             ELSE status
                         END,
                         updated_at = NOW()
                     WHERE id = ?",
                    'si',
                    [$providerPaymentId, (int)$payment['subject_id']]
                )) {
                    throw new RuntimeException('The paid job order could not be synchronized.');
                }
            } else {
                if (!db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = estimated_total,
                         status = CASE
                             WHEN UPPER(REPLACE(TRIM(status), ' ', '_')) IN
                                  ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                                   'TO_PAY', 'VERIFY_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                                   'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED')
                             THEN 'PAYMENT_CONFIRMED'
                             ELSE status
                         END,
                         updated_at = NOW()
                     WHERE id = ?",
                    'i',
                    [(int)$payment['subject_id']]
                )) {
                    throw new RuntimeException('The paid job order could not be synchronized.');
                }
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
        $shouldNotify = !$alreadyPaid || $transitionInserted;
        $conn->commit();
        $transactionOpen = false;

        if ($shouldNotify) {
            $displayCode = $orderId > 0
                ? printflow_format_order_code($orderId, '')
                : printflow_format_job_code((int)$payment['subject_id']);
            $amountLabel = "\xE2\x82\xB1" . number_format(((int)$payment['amount_centavos']) / 100, 2);
            create_notification(
                (int)$payment['customer_id'],
                'Customer',
                "Your payment of {$amountLabel} for order {$displayCode} has been confirmed.",
                'Payment',
                false,
                false,
                $orderId
            );
            notify_shop_users(
                "PayMongo payment confirmed for {$displayCode}. The order is ready to start production.",
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
            || (string)($payment['mode'] ?? '') !== 'test') {
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

function printflow_paymongo_verify_webhook_signature(
    string $rawBody,
    string $signatureHeader,
    int $toleranceSeconds = 300
): bool {
    $secret = printflow_paymongo_env('PAYMONGO_WEBHOOK_SECRET');
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }
    $timestamp = isset($parts['t']) && ctype_digit($parts['t']) ? (int)$parts['t'] : 0;
    $testSignature = (string)($parts['te'] ?? '');
    if ($timestamp <= 0 || $testSignature === '' || abs(time() - $timestamp) > $toleranceSeconds) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $testSignature);
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
