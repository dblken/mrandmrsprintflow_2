<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/revision_workflow.php';

require_role('Staff');
$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "Invalid request. Please try again.";
        redirect($_SERVER['HTTP_REFERER'] ?? 'orders.php');
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $revision_reason = sanitize($_POST['revision_reason'] ?? '');

    if (!$order_id || !$revision_reason) {
        $_SESSION['error'] = "Order ID and revision reason are required.";
        redirect($_SERVER['HTTP_REFERER'] ?? 'orders.php');
    }

    if (!printflow_order_in_branch($order_id, $staffBranchId)) {
        $_SESSION['error'] = 'You cannot modify orders from another branch.';
        redirect($_SERVER['HTTP_REFERER'] ?? 'orders.php');
    }

    try {
        $revisionRequest = printflow_revision_create_request(
            $order_id,
            (int) get_user_id(),
            $revision_reason,
            $revision_reason,
            ['uploaded_design'],
            $revision_reason
        );
        $revision_reason = (string) $revisionRequest['legacy_reason'];
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirect($_SERVER['HTTP_REFERER'] ?? 'orders.php');
    }

    // Update order status to 'Revision Requested' and 'For Revision'


    $sql = "UPDATE orders SET 
            status = 'For Revision', 
            design_status = 'Revision Requested', 
            revision_reason = ?, 
            reviewed_by = ?, 
            reviewed_at = NOW() 
            WHERE order_id = ? AND branch_id = ?";
    $success = db_execute($sql, 'siii', [$revision_reason, get_user_id(), $order_id, $staffBranchId]);

    if ($success) {
        // Log Activity
        log_activity(get_user_id(), 'Revision Requested', "Requested revision for Order #$order_id. Reason: $revision_reason");

        // Notify Customer
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
                "Additional details requested for Order #$order_id: $revision_reason", 
                'Order', 
                true, // Send email
                false, // Send SMS
                $order_id
            );
        }

        $_SESSION['success'] = "Additional details request sent successfully.";
    } else {
        $_SESSION['error'] = "Failed to update order status.";
    }

    redirect(BASE_PATH . '/staff/customizations_v2.php?order_id=' . $order_id);
} else {
    redirect('orders.php');
}
