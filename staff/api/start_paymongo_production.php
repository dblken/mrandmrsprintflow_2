<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/provider_payments.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/JobOrderService.php';
require_once __DIR__ . '/../../includes/production_requirements.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!is_logged_in() || !in_array(get_user_type(), ['Admin', 'Staff', 'Manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Staff access is required.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;
if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$orderId = (int)($input['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid order is required.']);
    exit;
}

printflow_assert_order_branch_access($orderId);
$ledgerRows = db_query(
    "SELECT * FROM provider_payments
     WHERE order_id = ? AND provider = 'paymongo'
       AND mode = 'test' AND channel = 'online'
     ORDER BY id DESC LIMIT 1",
    'i',
    [$orderId]
) ?: [];
if (!empty($ledgerRows[0])
    && ($ledgerRows[0]['status'] ?? '') === 'paid'
    && !empty($ledgerRows[0]['provider_payment_id'])) {
    $reconciled = printflow_provider_payment_mark_paid(
        (int)$ledgerRows[0]['id'],
        (string)$ledgerRows[0]['provider_payment_id'],
        (string)($ledgerRows[0]['payment_method'] ?? '')
    );
    if (empty($reconciled['ok'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'The verified payment could not be reconciled.']);
        exit;
    }
}

$preflight = db_query(
    "SELECT o.order_id, o.status, o.payment_status, o.total_amount,
            pp.id AS provider_payment_id, pp.status AS provider_payment_status,
            pp.amount_centavos
     FROM orders o
     JOIN provider_payments pp ON pp.order_id = o.order_id
     WHERE o.order_id = ?
       AND pp.provider = 'paymongo'
       AND pp.mode = 'test'
       AND pp.channel = 'online'
     ORDER BY pp.id DESC
     LIMIT 1",
    'i',
    [$orderId]
) ?: [];
if (empty($preflight)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'A verified PayMongo payment is required.']);
    exit;
}
$preflight = $preflight[0];
if ((string)$preflight['provider_payment_status'] !== 'paid'
    || strcasecmp((string)$preflight['payment_status'], 'Paid') !== 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'The PayMongo payment has not been confirmed.']);
    exit;
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
if (empty($jobIds) && $ensuredJobId > 0) {
    $jobIds = [$ensuredJobId];
}
if (empty($jobIds)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'A linked production job is required before production can start.',
        'missing_fields' => ['production' => 'A linked production job is required.'],
    ]);
    exit;
}

$missing = [];
foreach ($jobIds as $jobId) {
    foreach (printflow_job_production_assignment_errors((int)$jobId) as $key => $message) {
        $missing[$key] = $message;
    }
}
if (printflow_money_to_centavos($preflight['total_amount'] ?? '') <= 0
    || printflow_money_to_centavos($preflight['total_amount'] ?? '') !== (int)$preflight['amount_centavos']) {
    $missing['final_price'] = 'A valid final price matching the confirmed payment is required.';
}
if (!empty($missing)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Complete the required production details before starting production.',
        'missing_fields' => $missing,
    ]);
    exit;
}

global $conn;
$transactionOpen = false;
try {
    $conn->begin_transaction();
    $transactionOpen = true;

    $paymentRows = db_query(
        "SELECT * FROM provider_payments
         WHERE id = ? AND order_id = ? AND provider = 'paymongo'
           AND mode = 'test' AND channel = 'online'
         LIMIT 1 FOR UPDATE",
        'ii',
        [(int)$preflight['provider_payment_id'], $orderId]
    ) ?: [];
    $orderRows = db_query(
        'SELECT order_id, customer_id, branch_id, status, payment_status, total_amount
         FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE',
        'i',
        [$orderId]
    ) ?: [];
    if (empty($paymentRows) || empty($orderRows)) {
        throw new RuntimeException('The paid order could not be locked for production.');
    }

    $payment = $paymentRows[0];
    $order = $orderRows[0];
    $normalizedOrderStatus = strtoupper(str_replace(' ', '_', trim((string)$order['status'])));
    if (in_array($normalizedOrderStatus, ['CANCELLED', 'REJECTED', 'COMPLETED'], true)) {
        throw new RuntimeException('This order can no longer be moved into production.');
    }
    if ((string)$payment['status'] !== 'paid'
        || strcasecmp((string)$order['payment_status'], 'Paid') !== 0
        || printflow_money_to_centavos($order['total_amount'] ?? '') !== (int)$payment['amount_centavos']) {
        throw new RuntimeException('The confirmed payment no longer matches this order.');
    }

    if (in_array($normalizedOrderStatus, ['PROCESSING', 'IN_PRODUCTION', 'PRINTING'], true)) {
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

    foreach ($jobIds as $jobId) {
        $errors = printflow_job_production_assignment_errors((int)$jobId);
        if (!empty($errors)) {
            throw new RuntimeException('Required production details are incomplete.');
        }
    }

    JobOrderService::syncStoreOrderToStatus($orderId, 'IN_PRODUCTION', null, '', true);
    if (!db_execute(
        "UPDATE customizations
         SET status = 'In Production', updated_at = NOW()
         WHERE order_id = ? AND status NOT IN ('Completed', 'Cancelled', 'Rejected')",
        'i',
        [$orderId]
    )) {
        throw new RuntimeException('The customization workflow could not be updated.');
    }

    printflow_provider_payment_record_transition(
        (int)$payment['id'],
        $orderId,
        'start_production',
        (string)$order['status'],
        'In Production',
        (string)get_user_type(),
        (int)get_user_id()
    );

    $conn->commit();
    $transactionOpen = false;

    $orderCode = printflow_format_order_code($orderId, '');
    create_notification(
        (int)$order['customer_id'],
        'Customer',
        "Your order {$orderCode} is now in production.",
        'Order',
        false,
        false,
        $orderId
    );
    add_order_system_message($orderId, "Your order {$orderCode} is now in production.");
    log_activity((int)get_user_id(), 'Start Production', "Order {$orderCode} moved to production after PayMongo payment confirmation.");

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
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage(),
        'missing_fields' => $missing,
    ]);
}
