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
    return [
        'payment_id' => (int)($payment['id'] ?? 0),
        'order_id' => (int)($payment['order_id'] ?? 0),
        'channel' => (string)($payment['channel'] ?? ''),
        'mode' => (string)($payment['mode'] ?? ''),
        'test_mode' => (string)($payment['mode'] ?? '') === 'test',
        'status' => (string)($payment['status'] ?? ''),
        'amount' => (int)($payment['amount_centavos'] ?? 0),
        'currency' => (string)($payment['currency'] ?? 'PHP'),
        'payment_link_id' => (string)($payment['link_id'] ?? ''),
        'checkout_url' => (string)($payment['checkout_url'] ?? ''),
        'created_at' => $payment['created_at'] ?? null,
        'paid_at' => $payment['paid_at'] ?? null,
    ];
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
    string $providerPaymentId
): array {
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
        if ((string)$payment['status'] === 'paid') {
            $conn->commit();
            return ['ok' => true, 'already_processed' => true, 'payment' => $payment];
        }

        $subject = printflow_provider_payment_load_subject(
            (string)$payment['subject_type'],
            (int)$payment['subject_id']
        );
        if (empty($subject)) {
            throw new RuntimeException('The linked order no longer exists.');
        }

        db_execute(
            "UPDATE provider_payments
             SET status = 'paid', provider_payment_id = ?, paid_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            'si',
            [$providerPaymentId, $ledgerId]
        );

        $orderId = (int)($payment['order_id'] ?? 0);
        if ($orderId > 0) {
            $isPos = (string)$payment['channel'] === 'pos';
            $isProduct = strtolower((string)($subject['order_type'] ?? '')) === 'product';
            if ($isPos) {
                $nextStatus = $isProduct ? 'Completed' : 'Pending';
            } else {
                $nextStatus = $isProduct ? 'Ready for Pickup' : 'Processing';
            }
            db_execute(
                "UPDATE orders
                 SET payment_status = 'Paid', payment_method = 'PayMongo Test',
                     payment_reference = ?, status = ?, updated_at = NOW()
                 WHERE order_id = ?",
                'ssi',
                [$providerPaymentId, $nextStatus, $orderId]
            );

            if ($isPos && $isProduct && empty($payment['fulfillment_applied_at'])) {
                require_once __DIR__ . '/product_branch_stock.php';
                $items = db_query(
                    'SELECT product_id, quantity FROM order_items WHERE order_id = ?',
                    'i',
                    [$orderId]
                ) ?: [];
                foreach ($items as $item) {
                    $productId = (int)$item['product_id'];
                    $quantity = (int)$item['quantity'];
                    if (!printflow_product_deduct_stock_for_branch(
                        $productId,
                        (int)($payment['branch_id'] ?? 0),
                        $quantity
                    )) {
                        throw new RuntimeException('Inventory could not be finalized for the paid POS order.');
                    }
                    printflow_record_product_inventory_transaction(
                        $productId,
                        'OUT',
                        $quantity,
                        'ORDER',
                        $orderId,
                        'PayMongo POS sale: Order #' . $orderId,
                        0,
                        date('Y-m-d'),
                        (int)($payment['branch_id'] ?? 0)
                    );
                }
                db_execute(
                    'UPDATE provider_payments SET fulfillment_applied_at = NOW() WHERE id = ?',
                    'i',
                    [$ledgerId]
                );
            }

            if (!$isProduct) {
                $customizationStatus = $isPos ? 'Pending' : 'In Production';
                $jobStatus = $isPos ? 'PENDING' : 'IN_PRODUCTION';
                db_execute(
                    'UPDATE customizations SET status = ?, updated_at = NOW() WHERE order_id = ?',
                    'si',
                    [$customizationStatus, $orderId]
                );
                if (db_table_has_column('job_orders', 'payment_method')
                    && db_table_has_column('job_orders', 'payment_reference')) {
                    db_execute(
                        "UPDATE job_orders
                         SET payment_status = 'PAID', amount_paid = estimated_total,
                             payment_method = 'PayMongo Test', payment_reference = ?,
                             status = ?, updated_at = NOW()
                         WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                        'ssi',
                        [$providerPaymentId, $jobStatus, $orderId]
                    );
                } else {
                    db_execute(
                        "UPDATE job_orders
                         SET payment_status = 'PAID', amount_paid = estimated_total,
                             status = ?, updated_at = NOW()
                         WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                        'si',
                        [$jobStatus, $orderId]
                    );
                }
            }
        }

        if ((string)$payment['subject_type'] === 'job_order') {
            if (db_table_has_column('job_orders', 'payment_method')
                && db_table_has_column('job_orders', 'payment_reference')) {
                db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = estimated_total,
                         payment_method = 'PayMongo Test', payment_reference = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    'si',
                    [$providerPaymentId, (int)$payment['subject_id']]
                );
            } else {
                db_execute(
                    "UPDATE job_orders
                     SET payment_status = 'PAID', amount_paid = estimated_total, updated_at = NOW()
                     WHERE id = ?",
                    'i',
                    [(int)$payment['subject_id']]
                );
            }
        }

        $conn->commit();
        $transactionOpen = false;

        create_notification(
            (int)$payment['customer_id'],
            'Customer',
            'PayMongo Test payment confirmed for order #' . ((int)($payment['order_id'] ?? 0) ?: (int)$payment['subject_id']) . '.',
            'Payment',
            false,
            false,
            (int)($payment['order_id'] ?? 0)
        );
        notify_shop_users(
            'PayMongo Test payment confirmed for order #' . ((int)($payment['order_id'] ?? 0) ?: (int)$payment['subject_id']) . '.',
            'Payment',
            false,
            false,
            (int)($payment['order_id'] ?? 0)
        );

        return ['ok' => true, 'already_processed' => false, 'payment' => $payment];
    } catch (Throwable $error) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        error_log('PayMongo payment finalization failed for ledger #' . $ledgerId);
        return ['ok' => false, 'message' => 'The verified payment could not be finalized.'];
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
