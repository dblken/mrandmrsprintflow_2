<?php
/**
 * AJAX: Update Order Status (Staff)
 * Handles status changes and stock deduction when completed
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/product_option_stock.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/JobOrderService.php';

require_role(['Staff', 'Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$new_status = $_POST['status'] ?? '';
$cancel_reason = trim((string)($_POST['cancel_reason'] ?? ''));
$csrf_token = $_POST['csrf_token'] ?? '';

if (!verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if (!$order_id || !$new_status) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

printflow_assert_order_branch_access($order_id);

// 1. Get current status to avoid double-deduction
$order_row = db_query("SELECT status, branch_id, customer_id, order_type FROM orders WHERE order_id = ?", 'i', [$order_id]);
if (empty($order_row)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$old_status  = $order_row[0]['status'];
$customer_id = (int)($order_row[0]['customer_id'] ?? 0);
$order_type = strtolower(trim((string)($order_row[0]['order_type'] ?? '')));
$is_product_order = $order_type === 'product';
$is_service_order = $order_type === 'custom';

// Service/custom orders must flow through job_orders so material deductions
// and inventory ledger writes stay consistent for POS and online jobs.
if ($is_service_order && $new_status === 'Completed' && $old_status !== 'Completed') {
    try {
        $updatedJobs = JobOrderService::syncStoreOrderToStatus($order_id, 'COMPLETED');
        echo json_encode([
            'success' => true,
            'message' => 'Service order marked as Completed',
            'job_ids' => $updatedJobs
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($is_service_order && $new_status === 'Cancelled' && $old_status !== 'Cancelled') {
    try {
        $updatedJobs = JobOrderService::syncStoreOrderToStatus($order_id, 'CANCELLED', null, $cancel_reason);
        db_execute(
            "UPDATE orders
             SET status = 'Cancelled',
                 cancelled_by = 'Staff',
                 cancel_reason = ?,
                 cancelled_at = NOW(),
                 updated_at = NOW()
             WHERE order_id = ?",
            'si',
            [$cancel_reason, $order_id]
        );
        echo json_encode([
            'success' => true,
            'message' => 'Service order marked as Cancelled',
            'job_ids' => $updatedJobs
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}


// 2. Update Status
$update_sql = "UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?";
$update_types = 'si';
$update_params = [$new_status, $order_id];
if ($new_status === 'Cancelled') {
    $update_sql = "UPDATE orders
                   SET status = ?, cancel_reason = ?, cancelled_by = 'Staff', cancelled_at = NOW(), updated_at = NOW()
                   WHERE order_id = ?";
    $update_types = 'ssi';
    $update_params = [$new_status, $cancel_reason, $order_id];
}
$result = db_execute($update_sql, $update_types, $update_params);

if (!$result) {
    echo json_encode(['success' => false, 'error' => 'Failed to update order status']);
    exit;
}


// 3. Stock Deduction Logic
if ($new_status === 'Completed' && $old_status !== 'Completed') {
    $branch_id = (int)$order_row[0]['branch_id'];
    $orderRef = printflow_get_order_inventory_reference($order_id);
    $orderLabel = $orderRef['label'] ?? ('Order #' . printflow_format_order_code($order_id, ''));
    $items = db_query(
        "SELECT oi.product_id, oi.quantity, oi.customization_data, p.name AS product_name
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ?",
        'i',
        [$order_id]
    );
    
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        $productName = (string)($item['product_name'] ?? ('Product #' . $pid));
        $customization = [];
        if (!empty($item['customization_data'])) {
            $customization = json_decode((string)$item['customization_data'], true) ?: [];
        }
        
        if ($pid > 0 && $qty > 0) {
            $variantDeduction = printflow_product_option_stock_deduct($pid, $branch_id, $customization, $qty);
            if (!empty($variantDeduction['handled'])) {
                if (!$variantDeduction['success']) {
                    echo json_encode(['success' => false, 'error' => $variantDeduction['message'] ?? 'Failed to deduct selected size stock']);
                    exit;
                }
                printflow_record_product_inventory_transaction(
                    $pid,
                    'OUT',
                    (float)$qty,
                    'ORDER',
                    $order_id,
                    "{$orderLabel} completed - {$productName} ({$variantDeduction['field_label']}: {$variantDeduction['option_value']}) {$variantDeduction['previous_stock']} -> {$variantDeduction['new_stock']}",
                    (int)($_SESSION['user_id'] ?? 0),
                    date('Y-m-d'),
                    $branch_id
                );
                continue;
            }
            // Use branch-aware deduction
            if (printflow_product_deduct_stock_for_branch($pid, $branch_id, $qty)) {
                printflow_record_product_inventory_transaction(
                    $pid,
                    'OUT',
                    (float)$qty,
                    'ORDER',
                    $order_id,
                    "{$orderLabel} completed - {$productName}",
                    (int)($_SESSION['user_id'] ?? 0),
                    date('Y-m-d'),
                    $branch_id
                );
            }
        }
    }
}

// 4. System Message
$notif = get_order_status_notification_payload($order_id, $new_status);
if ($customer_id > 0) {
    create_notification($customer_id, 'Customer', $notif['message'], $notif['type'], false, false, $order_id);
}
add_order_system_message($order_id, $notif['message']);

// Automated Chat Update (Shopee/Messenger Style)
$chat_steps = [
    'Approved'         => 'approved',
    'To Pay'           => 'send_to_payment',
    'Processing'       => 'in_production',
    'In Production'    => 'in_production',
    'Ready for Pickup' => 'ready_to_pickup',
    'Completed'        => 'completed',
];
$chat_step = $chat_steps[$new_status] ?? null;
if ($chat_step) {
    if ($is_product_order && $chat_step === 'completed') {
        // Fixed product order: use specific completion message from staff
        $staff_sender_id = (int)($_SESSION['user_id'] ?? 0);
        $prod_complete_msg = "Order Completed. Your order has been successfully picked up. We hope you are satisfied with our service! Feel free to share your feedback to help us improve even more.";
        db_execute(
            "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Staff', ?, ?, 'order_update', 0)",
            'iis', [$order_id, $staff_sender_id, $prod_complete_msg]
        );
    } else {
        printflow_send_order_update($order_id, $chat_step);
        if ($chat_step === 'completed') {
            printflow_send_order_update($order_id, 'rate');
        }
    }
}

echo json_encode(['success' => true, 'message' => "Order #$order_id marked as $new_status"]);
