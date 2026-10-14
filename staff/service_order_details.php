<?php
/**
 * Staff - Service order detail (legacy URL).
 * Primary UX: modal on service_orders.php. This route opens that modal via ?open_id=
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role('Staff');
require_once __DIR__ . '/../includes/staff_pending_check.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/printflow');
}

$order_id = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if ($order_id < 1) {
    redirect(BASE_URL . '/staff/service_orders.php');
}

service_order_ensure_tables();
$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
$branchWhere = '(branch_id = ? OR branch_id IS NULL)';

// Legacy POST targets (forms posting here) — mirror staff/api/service_order_api.php behaviour
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $row = db_query("SELECT * FROM service_orders WHERE id = ? AND {$branchWhere} AND " . printflow_customer_exclusion_customer_scope_sql('service_orders.customer_id'), 'ii', [$order_id, $staffBranchId]);
    if (!empty($row)) {
        $row = $row[0];
        if (isset($_POST['approve'])) {
            db_execute("UPDATE service_orders SET status = 'Processing' WHERE id = ?", 'i', [$order_id]);
            if (function_exists('create_notification')) {
                create_notification(
                    (int)$row['customer_id'],
                    'Customer',
                    "Your service order #{$order_id} has been approved and is now processing.",
                    'Order',
                    true,
                    false
                );
            }
        } elseif (isset($_POST['reject'])) {
            db_execute("UPDATE service_orders SET status = 'Rejected' WHERE id = ?", 'i', [$order_id]);
            if (function_exists('create_notification')) {
                create_notification(
                    (int)$row['customer_id'],
                    'Customer',
                    "Your service order #{$order_id} has been rejected.",
                    'Order',
                    true,
                    false
                );
            }
        } elseif (isset($_POST['update_status'])) {
            $new_status = $_POST['status'] ?? '';
            if (in_array($new_status, ['Pending', 'Pending Review', 'Approved', 'Processing', 'Completed', 'Rejected'], true)) {
                db_execute('UPDATE service_orders SET status = ? WHERE id = ?', 'si', [$new_status, $order_id]);
            }
        }
    }
}

redirect(BASE_URL . '/staff/service_orders.php?open_id=' . $order_id);
