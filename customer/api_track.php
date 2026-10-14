<?php
/**
 * API for Order Tracker
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Order ID']);
        exit;
    }

    $customer_id = get_user_id();

    // Query to get order details, including the assigned staff's name if any
    $sql = "
        SELECT o.order_id, o.status, o.payment_status, o.estimated_completion,
               s.first_name as staff_first_name, s.last_name as staff_last_name
        FROM orders o
        LEFT JOIN staff s ON o.staff_id = s.staff_id
        WHERE o.order_id = ? AND o.customer_id = ? AND " . printflow_order_archive_scope_sql('o') . "
    ";
    
    $result = db_query($sql, 'ii', [$order_id, $customer_id]);

    if (!empty($result)) {
        echo json_encode(['success' => true, 'order' => $result[0]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Order not found or you do not have permission to view it.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
exit;

