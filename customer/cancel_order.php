<?php
/**
 * Handle Order Cancellation
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!isset($_POST['confirm_cancel']) && !isset($_POST['ajax']))) {
    redirect('orders.php');
}

$is_ajax = isset($_POST['ajax']);

$respond_json = static function (array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
};

try {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if ($is_ajax) {
            $respond_json(['success' => false, 'error' => 'Invalid CSRF token']);
        }
        die("Invalid CSRF token");
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $customer_id = (int)get_user_id();
    $reason = trim((string)($_POST['reason'] ?? 'Other'));
    $details = trim((string)($_POST['details'] ?? ''));

    if ($order_id <= 0 || $customer_id <= 0) {
        if ($is_ajax) {
            $respond_json(['success' => false, 'error' => 'Invalid request.']);
        }
        redirect('orders.php');
    }

    $cancel_reason = ($reason === 'Other' && $details !== '') ? ('Other: ' . $details) : $reason;

    $order_result = db_query("SELECT * FROM orders WHERE order_id = ? AND customer_id = ?", 'ii', [$order_id, $customer_id]);
    if (empty($order_result)) {
        if ($is_ajax) {
            $respond_json(['success' => false, 'error' => 'Order not found']);
        }
        redirect('orders.php');
    }
    $order = $order_result[0];

    if (!can_customer_cancel_order($order)) {
        $msg = "Order #{$order_id} can no longer be cancelled (it is already ready to pay or in production).";
        if ($is_ajax) {
            $respond_json(['success' => false, 'error' => $msg]);
        }
        $_SESSION['error'] = $msg;
        redirect("order_details.php?id=$order_id");
    }

    $sql = "UPDATE orders SET status = 'Cancelled', cancelled_by = 'Customer', cancel_reason = ?, cancelled_at = NOW() WHERE order_id = ? AND customer_id = ?";
    $success = db_execute($sql, 'sii', [$cancel_reason, $order_id, $customer_id]);

    if (!$success) {
        if ($is_ajax) {
            $respond_json(['success' => false, 'error' => 'Failed to cancel order. Please try again.']);
        }
        $_SESSION['error'] = "Failed to cancel order. Please try again.";
        redirect("order_details.php?id=$order_id");
    }

    // Restriction logic based on existing helper (last 30 days cancellations).
    $new_count = get_customer_cancel_count($customer_id);
    if ($new_count >= 7) {
        db_execute("UPDATE customers SET is_restricted = 1 WHERE customer_id = ?", 'i', [$customer_id]);
        log_activity($customer_id, 'Account Restricted', "Customer reached $new_count cancellations and is now permanently blocked.");
    }

    // Non-critical side effects should not break cancellation if they fail.
    try {
        create_notification($customer_id, 'Customer', "Order #{$order_id} has been cancelled.", 'Order', false, false, $order_id);
    } catch (Throwable $e) {
        error_log('Cancel order customer notification failed: ' . $e->getMessage());
    }

    try {
        notify_shop_users(
            "Order #{$order_id} was cancelled by the customer. Reason: $cancel_reason",
            'Order',
            false,
            false,
            $order_id,
            ['Staff', 'Admin', 'Manager']
        );
    } catch (Throwable $e) {
        error_log('Cancel order shop notification failed: ' . $e->getMessage());
    }

    if ($is_ajax) {
        $respond_json(['success' => true, 'status' => 'Cancelled', 'order_id' => $order_id]);
    }

    $_SESSION['success'] = "Order #{$order_id} has been successfully cancelled. The shop staff has been notified.";
    redirect("order_details.php?id=$order_id");
} catch (Throwable $e) {
    error_log('cancel_order.php fatal: ' . $e->getMessage());
    if ($is_ajax) {
        $respond_json(['success' => false, 'error' => 'Server error while cancelling order.']);
    }
    $_SESSION['error'] = "Server error while cancelling order.";
    redirect('orders.php');
}
