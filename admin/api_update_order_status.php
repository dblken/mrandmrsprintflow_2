<?php
/**
 * Admin: Update Order Status API
 * PrintFlow - Printing Shop PWA
 *
 * POST JSON endpoint (Admin role).
 * When status → 'Completed', triggers deduct_materials_by_variant().
 * If deduction fails, refuses the status change and returns error.
 */

require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/variant_functions.php';
require_once __DIR__ . '/../includes/TarpaulinService.php';
require_once __DIR__ . '/../includes/provider_payments.php';

require_role(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order_id   = (int)($input['order_id'] ?? 0);
$new_status = $input['status'] ?? '';
$allowed = ['Pending', 'Processing', 'Ready for Pickup', 'Completed', 'Cancelled', 'To Rate', 'Rated', 'Approved', 'To Pay', 'Pending Review', 'Pending Approval', 'For Revision', 'In Production', 'Printing'];
ensure_order_status_values($allowed);
if (!$order_id || !in_array($new_status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid order or status']);
    exit;
}

// Get current order
$order = db_query(
    "SELECT order_id, status, customer_id, branch_id, order_type, order_source, reference_id, payment_status
     FROM orders WHERE order_id = ?",
    'i',
    [$order_id]
);
if (empty($order)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}
$order = $order[0];
$isReadyMadeProductOrder = strtolower(trim((string)($order['order_type'] ?? ''))) === 'product'
    && printflow_is_ready_made_product_order($order);
if ($new_status === 'Completed' && $isReadyMadeProductOrder) {
    $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));
    if (!in_array($paymentStatus, ['paid', 'fully paid'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Cannot complete an unpaid ready-made product order.']);
        exit;
    }
    $providerPayment = printflow_provider_payment_find('order', $order_id, 'online', printflow_paymongo_mode());
    if (($providerPayment['status'] ?? '') !== 'paid') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Cannot complete this order before PayMongo verifies payment.']);
        exit;
    }
}

printflow_assert_order_branch_access($order_id);

$serviceCompletionTransactionStarted = false;
if ($new_status === 'Completed' && !$isReadyMadeProductOrder) {
    global $conn;
    if (!printflow_db_in_transaction($conn)) {
        $conn->begin_transaction();
        $serviceCompletionTransactionStarted = true;
    }
    $lockedOrderRows = db_query('SELECT status FROM orders WHERE order_id = ? FOR UPDATE', 'i', [$order_id]) ?: [];
    if ($lockedOrderRows === []) {
        if ($serviceCompletionTransactionStarted) $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    $order['status'] = (string)$lockedOrderRows[0]['status'];
}

// --- Safeguard: Check if all roll-based items have production specs ---
if ($new_status === 'Completed' && $order['status'] !== 'Completed' && !$isReadyMadeProductOrder) {
    $missing_specs = db_query("
        SELECT p.name 
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        LEFT JOIN order_tarp_details otd ON oi.order_item_id = otd.order_item_id
        WHERE oi.order_id = ? 
        AND (p.category LIKE '%TARPAULIN%' OR p.category LIKE '%STKR%')
        AND otd.order_item_id IS NULL
    ", 'i', [$order_id]);

    if (!empty($missing_specs)) {
        if ($serviceCompletionTransactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        $names = array_column($missing_specs, 'name');
        echo json_encode([
            'success' => false,
            'error'   => "Production specs missing for: " . implode(', ', $names) . ". Please configure dimensions and rolls first.",
        ]);
        exit;
    }
}

// --- Material deduction when marking Completed ---
if ($new_status === 'Completed' && $order['status'] !== 'Completed' && !$isReadyMadeProductOrder) {
    $deduction = deduct_materials_by_variant($order_id);
    if (!$deduction['success']) {
        if ($serviceCompletionTransactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        echo json_encode([
            'success' => false,
            'error'   => implode(' ', $deduction['errors']),
        ]);
        exit;
    }

    // --- Tarpaulin Roll Deduction ---
    try {
        TarpaulinService::deductInventoryForOrder($order_id);
    } catch (Exception $e) {
        // We log it but maybe continue? Or block?
        // Blocking is safer if the user wants strict inventory control.
        if ($serviceCompletionTransactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        echo json_encode([
            'success' => false,
            'error'   => "Tarpaulin deduction failed: " . $e->getMessage(),
        ]);
        exit;
    }

}

$inventoryTransactionStarted = false;
if ($new_status === 'Completed' && $order['status'] !== 'Completed' && $isReadyMadeProductOrder) {
    global $conn;
    if (!printflow_db_in_transaction($conn)) {
        $conn->begin_transaction();
        $inventoryTransactionStarted = true;
    }
    db_query('SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE', 'i', [$order_id]);

    $orderBranchId = (int)($order['branch_id'] ?? 0);
    $productItems = db_query(
        "SELECT oi.order_item_id, oi.product_id, oi.quantity
         FROM order_items oi WHERE oi.order_id = ?",
        'i',
        [$order_id]
    ) ?: [];

    try {
        foreach ($productItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) continue;

            printflow_apply_product_order_item_inventory(
                (int)$item['order_item_id'],
                $orderBranchId,
                (int)(get_user_id() ?? 0),
                'Admin product completion'
            );
        }
    } catch (Throwable $inventoryError) {
        if ($inventoryTransactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        echo json_encode(['success' => false, 'error' => $inventoryError->getMessage()]);
        exit;
    }

// Update status
    if (!db_execute(
        "UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?",
        'si',
        [$new_status, $order_id]
    )) {
        if ($inventoryTransactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to update order status.']);
        exit;
    }
    if ($inventoryTransactionStarted) $conn->commit();
} else {
    $statusUpdated = db_execute(
        "UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?",
        'si',
        [$new_status, $order_id]
    );
    if (!$statusUpdated) {
        if ($serviceCompletionTransactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to update order status.']);
        exit;
    }
    if ($serviceCompletionTransactionStarted) $conn->commit();
}

// Notify customer + add system message to chat
$customer_id = (int)$order['customer_id'];
if ($customer_id) {
    $notif = get_order_status_notification_payload($order_id, $new_status);
    create_notification($customer_id, 'Customer', $notif['message'], $notif['type'], false, false, $order_id);
    add_order_system_message($order_id, $notif['message']);
}

$admin_id = get_user_id();
log_activity($admin_id, 'Order Status Update', "Order #{$order_id} → {$new_status}");

echo json_encode([
    'success' => true,
    'message' => "Order #{$order_id} updated to {$new_status}",
]);
