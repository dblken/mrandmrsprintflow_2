<?php
/**
 * Staff: Update Order Status API
 * For regular orders (orders table) from customizations modal.
 * Maps job-order style statuses (APPROVED, TO_RECEIVE, etc.) to orders table format.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

if (!defined('BASE_URL')) define('BASE_URL', '/printflow');
require_role(['Admin', 'Staff', 'Manager']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Accept FormData or JSON
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order_id   = (int)($input['order_id'] ?? $input['id'] ?? 0);
$raw_status = trim($input['status'] ?? '');

// Map job-order style status (from customizations modal) to orders table status
$status_map = [
    'APPROVED'     => 'Approved',
    'TO_PAY'       => 'To Pay',
    'TO_RECEIVE'   => 'Ready for Pickup',
    'IN_PRODUCTION'=> 'Processing',
    'COMPLETED'    => 'Completed',
    'CANCELLED'    => 'Cancelled',
];
$new_status = $status_map[$raw_status] ?? $raw_status;

$allowed = ['Pending', 'Pending Review', 'Pending Approval', 'For Revision', 'Approved', 'To Pay',
    'To Verify', 'Processing', 'In Production', 'Printing', 'Ready for Pickup', 'Completed', 'Cancelled'];

ensure_order_status_values($allowed);

if (!$order_id || !in_array($new_status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid order or status']);
    exit;
}

printflow_assert_order_branch_access($order_id);

$order = db_query("SELECT order_id, status, customer_id FROM orders WHERE order_id = ? AND " . printflow_order_archive_scope_sql('orders'), 'i', [$order_id]);
if (empty($order)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}
$order = $order[0];

$staff_id = get_user_id();
$current_status = $order['status'];
$customer_id = (int)$order['customer_id'];

if ($current_status === $new_status) {
    echo json_encode(['success' => true, 'new_status' => $new_status, 'message' => 'Status unchanged']);
    exit;
}

$ok = db_execute("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ? AND " . printflow_order_archive_scope_sql('orders'), 'si', [$new_status, $order_id]);
if (!$ok) {
    echo json_encode(['success' => false, 'error' => 'Database update failed']);
    exit;
}

log_activity($staff_id, 'Order Status Update', "Order #{$order_id} → {$new_status}");

$notif = get_order_status_notification_payload($order_id, $new_status);
create_notification($customer_id, 'Customer', $notif['message'], $notif['type'], false, false, $order_id);
add_order_system_message($order_id, $notif['message']);

// Automated Chat Update (Shopee/Messenger Style)
$chat_steps = [
    'Approved'          => 'approved',
    'To Pay'            => 'send_to_payment',
    'Processing'        => 'in_production',
    'In Production'     => 'in_production',
    'Ready for Pickup'  => 'ready_to_pickup',
    'Completed'         => 'completed',
];
$chat_step = $chat_steps[$new_status] ?? null;
if ($chat_step) {
    printflow_send_order_update($order_id, $chat_step);
    if ($chat_step === 'completed') {
        printflow_send_order_update($order_id, 'rate');
    }
}

echo json_encode([
    'success'    => true,
    'new_status' => $new_status,
    'message'    => "Order #{$order_id} updated to {$new_status}",
]);
