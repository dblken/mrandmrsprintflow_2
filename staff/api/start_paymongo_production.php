<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/provider_payments.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/JobOrderService.php';
require_once __DIR__ . '/../../includes/production_requirements.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$respond = static function (int $status, string $message, array $missingFields = [], array $extra = []): void {
    http_response_code($status);
    $payload = array_merge([
        'success' => false,
        'message' => $message,
    ], $extra);
    if ($missingFields !== []) {
        $payload['missing_fields'] = $missingFields;
    }
    echo json_encode($payload);
    exit;
};

$normalizeStatus = static function ($status): string {
    $normalized = strtoupper(trim((string)$status));
    return trim((string)preg_replace('/[^A-Z0-9]+/', '_', $normalized), '_');
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, 'Method not allowed.');
}
if (!is_logged_in() || !in_array(get_user_type(), ['Admin', 'Staff', 'Manager'], true)) {
    $respond(403, 'Staff access is required.');
}

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;
if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
    $respond(403, 'Invalid security token.');
}

$orderId = (int)($input['order_id'] ?? 0);
if ($orderId <= 0) {
    $respond(400, 'A valid order is required.');
}

printflow_assert_order_branch_access($orderId);
$requiredBranchId = printflow_branch_filter_for_user();
$paymentMode = '';
if (!printflow_revision_ensure_schema()) {
    $respond(503, 'The revision workflow is unavailable. Production cannot be started safely.');
}

$hasPaidAmount = db_table_has_column('provider_payments', 'paid_amount_centavos');
$hasProviderPaidAt = db_table_has_column('provider_payments', 'provider_paid_at');
$hasPaymentStatus = db_table_has_column('provider_payments', 'payment_status');
$hasProviderStatus = db_table_has_column('provider_payments', 'provider_status');

$validatePayment = static function (array $order, array $payment) use (
    $orderId,
    &$paymentMode,
    $hasPaidAmount,
    $hasProviderPaidAt,
    $hasPaymentStatus,
    $hasProviderStatus
): array {
    $errors = [];
    $amountDue = printflow_money_to_centavos($order['total_amount'] ?? '');
    $ledgerAmount = (int)($payment['amount_centavos'] ?? 0);
    $paidAmount = $hasPaidAmount
        ? (int)($payment['paid_amount_centavos'] ?? 0)
        : ((string)($payment['status'] ?? '') === 'paid' ? $ledgerAmount : 0);
    $paidAt = trim((string)(
        ($hasProviderPaidAt ? ($payment['provider_paid_at'] ?? null) : null)
        ?: ($payment['paid_at'] ?? '')
    ));

    if ((string)($payment['provider'] ?? '') !== 'paymongo'
        || (string)($payment['channel'] ?? '') !== 'online'
        || (string)($payment['mode'] ?? '') !== $paymentMode
        || (string)($payment['subject_type'] ?? '') !== 'order'
        || (int)($payment['subject_id'] ?? 0) !== $orderId
        || (int)($payment['order_id'] ?? 0) !== $orderId) {
        $errors['payment'] = 'The PayMongo payment does not belong to this order and environment.';
    }
    if ((int)($payment['customer_id'] ?? 0) !== (int)($order['customer_id'] ?? 0)
        || ((int)($payment['branch_id'] ?? 0) > 0
            && (int)$payment['branch_id'] !== (int)($order['branch_id'] ?? 0))) {
        $errors['payment'] = 'The PayMongo payment ownership does not match this order.';
    }
    if ((string)($payment['status'] ?? '') !== 'paid'
        || ($hasPaymentStatus && (string)($payment['payment_status'] ?? '') !== 'paid')
        || ($hasProviderStatus && (string)($payment['provider_status'] ?? '') !== 'paid')
        || strcasecmp((string)($order['payment_status'] ?? ''), 'Paid') !== 0) {
        $errors['payment'] = 'A fully verified PayMongo payment is required.';
    }
    if ($amountDue <= 0 || $ledgerAmount <= 0 || $amountDue !== $ledgerAmount) {
        $errors['final_price'] = 'A valid final price matching the PayMongo payment is required.';
    }
    if ($paidAmount <= 0 || $paidAmount !== $ledgerAmount) {
        $errors['paid_amount'] = 'The full PayMongo amount has not been verified.';
    }
    if (strtoupper(trim((string)($payment['currency'] ?? 'PHP'))) !== 'PHP') {
        $errors['payment'] = 'The verified payment currency is invalid.';
    }
    if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', (string)($payment['provider_payment_id'] ?? ''))) {
        $errors['payment_reference'] = 'A verified PayMongo payment reference is required.';
    }
    if ($paidAt === '') {
        $errors['payment_date'] = 'A verified PayMongo payment date is required.';
    }
    if (function_exists('printflow_order_price_is_final')
        && !printflow_order_price_is_final($order, $payment)) {
        $errors['final_price'] = 'The order price has not been finalized.';
    }
    return $errors;
};

$loadRevisionBlocker = static function (bool $forUpdate = false) use ($orderId): array {
    $sql = "SELECT revision_request_id, request_status
            FROM order_revision_requests
            WHERE order_id = ?
              AND (active_flag = 1 OR request_status IN
                   ('Requested', 'Customer Updating Details', 'Resubmitted for Review', 'Staff Reviewing'))
            ORDER BY revision_request_id DESC
            LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return db_query($sql, 'i', [$orderId]) ?: [];
};

$loadCustomizations = static function (bool $forUpdate = false) use ($orderId): array {
    $sql = 'SELECT status FROM customizations WHERE order_id = ? ORDER BY customization_id ASC';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return db_query($sql, 'i', [$orderId]) ?: [];
};

$validateApproval = static function (array $order, array $customizations) use ($normalizeStatus): array {
    $errors = [];
    $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
    $requiresCustomizationApproval = in_array($orderType, ['custom', 'service', 'customization'], true)
        || $customizations !== [];
    if (!$requiresCustomizationApproval) {
        return $errors;
    }

    $designStatus = $normalizeStatus($order['design_status'] ?? '');
    if (!in_array($designStatus, ['APPROVED', 'DESIGN_APPROVED', 'APPROVED_DESIGN'], true)) {
        $errors['customization'] = 'The customer customization or design must be approved first.';
    }
    if ($customizations === []) {
        $errors['customization'] = 'An approved customization record is required.';
        return $errors;
    }

    $approvedStatuses = ['APPROVED', 'DESIGN_APPROVED', 'TO_PAY', 'PAYMENT_CONFIRMED'];
    foreach ($customizations as $customization) {
        if (!in_array($normalizeStatus($customization['status'] ?? ''), $approvedStatuses, true)) {
            $errors['customization'] = 'Every customization must be approved before production starts.';
            break;
        }
    }
    return $errors;
};

$payment = printflow_provider_payment_find('order', $orderId, 'online');
if ($payment === []) {
    $respond(409, 'A verified PayMongo payment is required.', [
        'payment' => 'No PayMongo payment exists for this order in the current environment.',
    ]);
}
$paymentMode = strtolower((string)($payment['mode'] ?? ''));
if (!in_array($paymentMode, ['test', 'live'], true)
    || !printflow_provider_payment_mode_supported($paymentMode)) {
    $respond(503, 'The saved PayMongo payment environment is unsupported or the migration is incomplete.');
}

$shouldReconcile = (string)($payment['status'] ?? '') === 'paid'
    || ((string)($payment['status'] ?? '') === 'awaiting_payment'
        && printflow_provider_payment_claim_reconciliation((int)$payment['id'], 5));
if ($shouldReconcile) {
    printflow_provider_payment_reconcile($payment);
}
$refreshedPayment = db_query(
    'SELECT * FROM provider_payments WHERE id = ? LIMIT 1',
    'i',
    [(int)$payment['id']]
) ?: [];
$payment = $refreshedPayment[0] ?? $payment;

$orderRows = db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]) ?: [];
if ($orderRows === []) {
    $respond(404, 'Order not found.');
}
$order = $orderRows[0];
$missing = $validatePayment($order, $payment);
if ($missing !== []) {
    $respond(409, 'The PayMongo payment is not ready for production.', $missing);
}

$normalizedOrderStatus = $normalizeStatus($order['status'] ?? '');
$terminalStatuses = ['CANCELLED', 'REJECTED', 'COMPLETED'];
$productionStatuses = ['PROCESSING', 'IN_PRODUCTION', 'PRINTING', 'PAID_IN_PROCESS'];
if (in_array($normalizedOrderStatus, $terminalStatuses, true)) {
    $respond(409, 'This order can no longer be moved into production.', [
        'status' => 'The order is cancelled, rejected, or completed.',
    ]);
}

$alreadyStarted = in_array($normalizedOrderStatus, $productionStatuses, true);
$jobIds = [];
if (!$alreadyStarted) {
    if ($normalizedOrderStatus !== 'PAYMENT_CONFIRMED') {
        $missing['status'] = 'The order must be payment-confirmed and awaiting production.';
    }
    if ($loadRevisionBlocker() !== []) {
        $missing['revision'] = 'Resolve the active customer revision before starting production.';
    }
    $missing = array_merge($missing, $validateApproval($order, $loadCustomizations()));
    if ($missing !== []) {
        $respond(409, 'Complete the required review steps before starting production.', $missing);
    }

    $ensuredJobId = (int)(JobOrderService::ensureJobsForStoreOrder($orderId) ?? 0);
    $jobRows = db_query(
        "SELECT id FROM job_orders
         WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')
         ORDER BY id ASC",
        'i',
        [$orderId]
    ) ?: [];
    $jobIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        $jobRows
    )));
    if ($jobIds === [] && $ensuredJobId > 0) {
        $jobIds = [$ensuredJobId];
    }
    if ($jobIds === []) {
        $respond(409, 'A linked production job is required before production can start.', [
            'production' => 'A linked production job is required.',
        ]);
    }

    foreach ($jobIds as $jobId) {
        foreach (printflow_job_production_assignment_errors($jobId) as $key => $message) {
            $missing[$key] = $message;
        }
    }
    if ($missing !== []) {
        $respond(409, 'Complete the required production details before starting production.', $missing);
    }
}

global $conn;
$transactionOpen = false;
$transitionInserted = false;
$historyTableExists = !empty(db_query("SHOW TABLES LIKE 'provider_payment_status_history'"));
$historySupportsEventKey = $historyTableExists
    && db_table_has_column('provider_payment_status_history', 'event_key');

try {
    $conn->begin_transaction();
    $transactionOpen = true;

    $paymentRows = db_query(
        "SELECT * FROM provider_payments
         WHERE id = ? AND order_id = ? AND provider = 'paymongo'
           AND mode = ? AND channel = 'online'
         LIMIT 1 FOR UPDATE",
        'iis',
        [(int)$payment['id'], $orderId, $paymentMode]
    ) ?: [];
    $orderRows = db_query(
        'SELECT * FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE',
        'i',
        [$orderId]
    ) ?: [];
    if ($paymentRows === [] || $orderRows === []) {
        throw new RuntimeException('The paid order could not be locked for production.');
    }

    $payment = $paymentRows[0];
    $order = $orderRows[0];
    if ($requiredBranchId !== null && $requiredBranchId > 0
        && (int)($order['branch_id'] ?? 0) !== $requiredBranchId) {
        throw new RuntimeException('This order is no longer assigned to your branch.');
    }

    $missing = $validatePayment($order, $payment);
    if ($missing !== []) {
        throw new RuntimeException('The confirmed payment no longer matches this order.');
    }

    $normalizedOrderStatus = $normalizeStatus($order['status'] ?? '');
    if (in_array($normalizedOrderStatus, $terminalStatuses, true)) {
        throw new RuntimeException('This order can no longer be moved into production.');
    }
    if (in_array($normalizedOrderStatus, $productionStatuses, true)) {
        $conn->commit();
        $transactionOpen = false;
        echo json_encode([
            'success' => true,
            'already_started' => true,
            'status' => 'IN_PRODUCTION',
            'message' => 'Production has already started.',
        ]);
        exit;
    }
    if ($normalizedOrderStatus !== 'PAYMENT_CONFIRMED') {
        $missing['status'] = 'The order must be payment-confirmed and awaiting production.';
        throw new RuntimeException('The order is not ready for production.');
    }
    if ($loadRevisionBlocker(true) !== []) {
        $missing['revision'] = 'Resolve the active customer revision before starting production.';
        throw new RuntimeException('An active customer revision must be resolved first.');
    }

    $lockedCustomizations = $loadCustomizations(true);
    $approvalErrors = $validateApproval($order, $lockedCustomizations);
    if ($approvalErrors !== []) {
        $missing = array_merge($missing, $approvalErrors);
        throw new RuntimeException('The customer customization or design has not been approved.');
    }

    $lockedJobs = db_query(
        "SELECT id, status FROM job_orders
         WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')
         ORDER BY id ASC FOR UPDATE",
        'i',
        [$orderId]
    ) ?: [];
    if ($lockedJobs === []) {
        $missing['production'] = 'A linked production job is required.';
        throw new RuntimeException('A linked production job is required before production can start.');
    }
    foreach ($lockedJobs as $job) {
        if (in_array($normalizeStatus($job['status'] ?? ''), $productionStatuses, true)) {
            throw new RuntimeException('A linked production job has already started. Refresh the order details.');
        }
        $jobId = (int)($job['id'] ?? 0);
        foreach (printflow_job_production_assignment_errors($jobId) as $key => $message) {
            $missing[$key] = $message;
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('Required production details are incomplete.');
    }

    $updatedJobIds = JobOrderService::syncStoreOrderToStatus($orderId, 'IN_PRODUCTION', null, '', true);
    if ($updatedJobIds === []) {
        throw new RuntimeException('No production job was updated.');
    }
    if ($lockedCustomizations !== [] && !db_execute(
        "UPDATE customizations
         SET status = 'In Production', updated_at = NOW()
         WHERE order_id = ? AND status NOT IN ('Completed', 'Cancelled', 'Rejected')",
        'i',
        [$orderId]
    )) {
        throw new RuntimeException('The customization workflow could not be updated.');
    }

    $transitionInserted = printflow_provider_payment_record_transition(
        (int)$payment['id'],
        $orderId,
        'start_production',
        (string)$order['status'],
        'In Production',
        (string)get_user_type(),
        (int)get_user_id()
    );
    if ($historySupportsEventKey && !$transitionInserted) {
        $existingTransition = db_query(
            "SELECT id FROM provider_payment_status_history
             WHERE provider_payment_id = ? AND event_key = 'start_production'
             LIMIT 1",
            'i',
            [(int)$payment['id']]
        ) ?: [];
        if ($existingTransition === []) {
            throw new RuntimeException('The production transition audit could not be recorded.');
        }
    }

    $conn->commit();
    $transactionOpen = false;

    $orderCode = printflow_format_order_code($orderId, '');
    $notificationMessage = "Your order {$orderCode} is now in production.";
    $shouldNotify = $transitionInserted || !$historySupportsEventKey;
    if ($shouldNotify) {
        $notificationExists = [];
        if (db_table_has_column('notifications', 'data_id')) {
            $notificationExists = db_query(
                "SELECT notification_id FROM notifications
                 WHERE customer_id = ? AND type = 'Order' AND message = ?
                   AND COALESCE(data_id, 0) = ?
                 ORDER BY notification_id DESC LIMIT 1",
                'isi',
                [(int)$order['customer_id'], $notificationMessage, $orderId]
            ) ?: [];
        }
        if ($notificationExists === []) {
            create_notification(
                (int)$order['customer_id'],
                'Customer',
                $notificationMessage,
                'Order',
                false,
                false,
                $orderId
            );
        }

        $systemMessageExists = db_query(
            "SELECT message_id FROM order_messages
             WHERE order_id = ? AND sender = 'System' AND message = ?
             ORDER BY message_id DESC LIMIT 1",
            'is',
            [$orderId, $notificationMessage]
        ) ?: [];
        if ($systemMessageExists === []) {
            add_order_system_message($orderId, $notificationMessage);
        }
        log_activity(
            (int)get_user_id(),
            'Start Production',
            "Order {$orderCode} moved to production after PayMongo payment confirmation."
        );
    }

    echo json_encode([
        'success' => true,
        'already_started' => false,
        'status' => 'IN_PRODUCTION',
        'message' => 'Production started successfully.',
    ]);
} catch (Throwable $error) {
    if ($transactionOpen) {
        $conn->rollback();
    }
    error_log('PayMongo production gate rejected Order #' . $orderId . ': ' . $error->getMessage());
    $publicMessage = $missing !== []
        ? (string)reset($missing)
        : 'Production could not be started safely. Refresh the order and try again.';
    $respond(409, $publicMessage, $missing);
}
