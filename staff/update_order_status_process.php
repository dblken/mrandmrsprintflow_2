<?php
/**
 * Staff order-status mutation endpoint.
 * Core status changes are synchronous and never depend on realtime delivery.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/product_option_stock.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/JobOrderService.php';

if (!is_logged_in()) {
    printflow_json_response(['success' => false, 'error' => 'Authentication required.'], 401);
}
if (!in_array((string)get_user_type(), ['Staff', 'Admin', 'Manager'], true)) {
    printflow_json_response(['success' => false, 'error' => 'Forbidden.'], 403);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    printflow_json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$orderId = max(0, (int)($_POST['order_id'] ?? 0));
$newStatus = trim((string)($_POST['status'] ?? ''));
$cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    printflow_json_response([
        'success' => false,
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ], 419);
}
if ($orderId <= 0 || $newStatus === '') {
    printflow_json_response(['success' => false, 'error' => 'Missing required fields.'], 422);
}

$allowedStatuses = [
    'Pending', 'Pending Approval', 'Approved', 'To Pay', 'To Verify',
    'Processing', 'In Production', 'Ready for Pickup', 'Completed', 'Cancelled',
];
if (!in_array($newStatus, $allowedStatuses, true)) {
    printflow_json_response(['success' => false, 'error' => 'Invalid order status.'], 422);
}

printflow_assert_order_branch_access($orderId);

global $conn;
$transactionStarted = !printflow_db_in_transaction($conn);

try {
    if ($transactionStarted && !$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start order update transaction.');
    }

    $rows = db_query(
        'SELECT status, payment_status, branch_id, customer_id, order_type FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE',
        'i',
        [$orderId]
    ) ?: [];
    if ($rows === []) {
        if ($transactionStarted) $conn->rollback();
        printflow_json_response(['success' => false, 'error' => 'Order not found.'], 404);
    }

    $order = $rows[0];
    $oldStatus = trim((string)($order['status'] ?? ''));
    $customerId = (int)($order['customer_id'] ?? 0);
    $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
    $isProductOrder = $orderType === 'product';
    $isServiceOrder = $orderType === 'custom';

    if (strcasecmp($oldStatus, $newStatus) === 0) {
        if ($transactionStarted) $conn->commit();
        printflow_json_response([
            'success' => true,
            'changed' => false,
            'already_completed' => strcasecmp($newStatus, 'Completed') === 0,
            'order_id' => $orderId,
            'status' => $newStatus,
            'message' => "Order #{$orderId} is already {$newStatus}.",
        ]);
    }

    if ($newStatus === 'Completed') {
        $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));
        if (!in_array($paymentStatus, ['paid', 'fully paid'], true)) {
            if ($transactionStarted) $conn->rollback();
            printflow_json_response([
                'success' => false,
                'error' => 'Cannot mark as Completed: payment must be Paid.',
                'payment_status' => (string)($order['payment_status'] ?? 'Unpaid'),
            ], 409);
        }
    }

    $updatedJobs = [];
    if ($isServiceOrder && $newStatus === 'Completed') {
        $updatedJobs = JobOrderService::syncStoreOrderToStatus($orderId, 'COMPLETED');
        if ($updatedJobs === []) {
            $ok = db_execute("UPDATE orders SET status = 'Completed', updated_at = NOW() WHERE order_id = ?", 'i', [$orderId]);
            if (!$ok) throw new RuntimeException('Failed to persist the completed order status.');
        }
    } elseif ($isServiceOrder && $newStatus === 'Cancelled') {
        $updatedJobs = JobOrderService::syncStoreOrderToStatus($orderId, 'CANCELLED', null, $cancelReason);
        $ok = db_execute(
            "UPDATE orders SET status = 'Cancelled', cancelled_by = 'Staff', cancel_reason = ?, cancelled_at = NOW(), updated_at = NOW() WHERE order_id = ?",
            'si',
            [$cancelReason, $orderId]
        );
        if (!$ok) throw new RuntimeException('Failed to persist the cancelled order status.');
    } else {
        $updateSql = 'UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?';
        $updateTypes = 'si';
        $updateParams = [$newStatus, $orderId];
        if ($newStatus === 'Cancelled') {
            $updateSql = "UPDATE orders SET status = ?, cancel_reason = ?, cancelled_by = 'Staff', cancelled_at = NOW(), updated_at = NOW() WHERE order_id = ?";
            $updateTypes = 'ssi';
            $updateParams = [$newStatus, $cancelReason, $orderId];
        }
        if (!db_execute($updateSql, $updateTypes, $updateParams)) {
            throw new RuntimeException('Failed to update order status.');
        }

        if ($newStatus === 'Completed') {
            $branchId = (int)($order['branch_id'] ?? 0);
            $orderRef = printflow_get_order_inventory_reference($orderId);
            $orderLabel = $orderRef['label'] ?? ('Order #' . printflow_format_order_code($orderId, ''));
            $items = db_query(
                'SELECT oi.product_id, oi.quantity, oi.customization_data, p.name AS product_name FROM order_items oi LEFT JOIN products p ON p.product_id = oi.product_id WHERE oi.order_id = ?',
                'i',
                [$orderId]
            ) ?: [];

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) continue;

                $productName = (string)($item['product_name'] ?? ('Product #' . $productId));
                $customization = !empty($item['customization_data'])
                    ? (json_decode((string)$item['customization_data'], true) ?: [])
                    : [];
                $variant = printflow_product_option_stock_deduct($productId, $branchId, $customization, $quantity);
                if (!empty($variant['handled'])) {
                    if (empty($variant['success'])) {
                        throw new RuntimeException((string)($variant['message'] ?? 'Failed to deduct selected size stock.'));
                    }
                    printflow_record_product_inventory_transaction(
                        $productId, 'OUT', (float)$quantity, 'ORDER', $orderId,
                        "{$orderLabel} completed - {$productName} ({$variant['field_label']}: {$variant['option_value']}) {$variant['previous_stock']} -> {$variant['new_stock']}",
                        (int)($_SESSION['user_id'] ?? 0), date('Y-m-d'), $branchId
                    );
                    continue;
                }
                if (printflow_product_deduct_stock_for_branch($productId, $branchId, $quantity)) {
                    printflow_record_product_inventory_transaction(
                        $productId, 'OUT', (float)$quantity, 'ORDER', $orderId,
                        "{$orderLabel} completed - {$productName}",
                        (int)($_SESSION['user_id'] ?? 0), date('Y-m-d'), $branchId
                    );
                }
            }
        }

        $notification = get_order_status_notification_payload($orderId, $newStatus);
        if ($customerId > 0) {
            create_notification($customerId, 'Customer', $notification['message'], $notification['type'], false, false, $orderId);
        }
        add_order_system_message($orderId, $notification['message']);

        $chatSteps = [
            'Approved' => 'approved', 'To Pay' => 'send_to_payment',
            'Processing' => 'in_production', 'In Production' => 'in_production',
            'Ready for Pickup' => 'ready_to_pickup', 'Completed' => 'completed',
        ];
        $chatStep = $chatSteps[$newStatus] ?? null;
        if ($chatStep !== null) {
            if ($isProductOrder && $chatStep === 'completed') {
                db_execute(
                    "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Staff', ?, ?, 'order_update', 0)",
                    'iis',
                    [$orderId, (int)($_SESSION['user_id'] ?? 0), 'Order Completed. Your order has been successfully picked up. We hope you are satisfied with our service! Feel free to share your feedback to help us improve even more.']
                );
            } else {
                printflow_send_order_update($orderId, $chatStep);
                if ($chatStep === 'completed') printflow_send_order_update($orderId, 'rate');
            }
        }
    }

    if ($transactionStarted) $conn->commit();
    printflow_json_response([
        'success' => true,
        'changed' => true,
        'already_completed' => false,
        'order_id' => $orderId,
        'status' => $newStatus,
        'job_ids' => $updatedJobs,
        'message' => "Order #{$orderId} marked as {$newStatus}.",
    ]);
} catch (Throwable $exception) {
    if ($transactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
    $reference = printflow_json_error_reference();
    error_log(sprintf(
        '[order-status][%s] order=%d target=%s class=%s code=%s',
        $reference, $orderId, preg_replace('/[^A-Za-z ]/', '', $newStatus),
        get_class($exception), (string)$exception->getCode()
    ));
    printflow_json_response([
        'success' => false,
        'error' => 'The order status could not be updated.',
        'error_reference' => $reference,
    ], 500);
}
