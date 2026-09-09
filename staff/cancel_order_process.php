<?php
/**
 * Handle Staff Order Cancellation
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role('Staff');
$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_cancel'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Invalid security token");
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $reason   = $_POST['reason'] ?? '';
    $notes    = $_POST['notes'] ?? '';
    $full_reason = $reason . ($notes ? ": " . $notes : "");

    if (!$order_id || empty($reason)) {
        $_SESSION['error'] = "Order ID and reason are required.";
        redirect("order_details.php?id=$order_id");
    }

    if (!printflow_order_in_branch($order_id, $staffBranchId)) {
        $_SESSION['error'] = 'You cannot cancel orders from another branch.';
        redirect("order_details.php?id=$order_id");
    }

    $orderRows = db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1', 'i', [$order_id]) ?: [];
    if ($orderRows === [] || printflow_is_ready_made_product_order($orderRows[0])) {
        $_SESSION['error'] = 'Ready-made online product orders cannot be cancelled through the normal workflow.';
        redirect("order_details.php?id=$order_id");
    }

    // Use the central update function
    if (update_order_status($order_id, 'Cancelled', get_user_id(), $full_reason)) {
        // Find customer for notification
        $order_data = db_query(
            "SELECT customer_id FROM orders WHERE order_id = ? AND branch_id = ?",
            'ii',
            [$order_id, $staffBranchId]
        );
        if (!empty($order_data)) {
            $customer_id = $order_data[0]['customer_id'];
            create_notification(
                $customer_id, 
                'Customer', 
                "Your order #{$order_id} has been cancelled by staff. Reason: $full_reason", 
                'Order', 
                true, 
                false,
                $order_id
            );
        }

        $_SESSION['success'] = "Order #{$order_id} has been successfully cancelled. The customer will be notified of the reason provided.";
        log_activity(get_user_id(), 'Cancel Order', "Staff cancelled Order #{$order_id}. Reason: $full_reason");
    } else {
        $_SESSION['error'] = "Failed to cancel order. Please check the system logs for details.";
    }

    redirect("order_details.php?id=$order_id");
} else {
    redirect("orders.php");
}
