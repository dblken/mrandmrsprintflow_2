<?php
/**
 * Staff Orders Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role('Staff');
printflow_require_staff_module('orders');
require_once __DIR__ . '/../includes/staff_pending_check.php';

$branch_ctx    = init_branch_context(false);
$staffBranchId = (int)$branch_ctx['selected_branch_id'];
$branchName    = $branch_ctx['branch_name'];
$staffOrderScopeSql = printflow_staff_order_source_sql('o');
$staffAccessMeta = printflow_get_staff_access_meta();
$is_pos_staff = ($staffAccessMeta['key'] ?? '') === 'pos';

// Auto-open modal if order_id is in URL
$deepLinkOrderId = (int)($_GET['order_id'] ?? 0);

// Handle status update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $order_id = (int)$_POST['order_id'];
        $new_status = $_POST['status'];
        $staff_id = get_user_id();

        // Fetch current status and customer ID (only if order is in this branch)
        $order_info = db_query(
            "SELECT customer_id, status FROM orders WHERE order_id = ? AND branch_id = ? AND {$staffOrderScopeSql}",
            'ii',
            [$order_id, $staffBranchId]
        );
        
        if (!empty($order_info)) {
            $current_status = $order_info[0]['status'];
            $customer_id = $order_info[0]['customer_id'];

            // Only proceed if the status is actually changing
            if ($current_status !== $new_status) {
                // Use the centralized update_order_status logic
                $success = db_execute("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?", 'si', [$new_status, $order_id]);

                if ($success) {
                    // Log activity
                    log_activity($staff_id, 'Order Status Update', "Updated Order #{$order_id} to {$new_status}");
                    $notif = get_order_status_notification_payload($order_id, $new_status);
                    $notif_type = $notif['type'];
                    $notif_message = $notif['message'];

                    // Notify customer
                    if ($new_status === 'To Pay') {
                        $msg = "💳 Your order #{$order_id} has been approved! Please prepare your payment upon pickup.";
                    } else {
                        $msg = "Your order #{$order_id} status has been updated to: {$new_status}";
                    }
                    
                    // Pass order_id as data_id for shortcut linking
                    $msg = $notif_message;
                    create_notification($customer_id, 'Customer', $msg, $notif_type, false, false, $order_id);
                    add_order_system_message($order_id, $msg);
                }
            } else {
                // Status is already the same, consider it a "soft" success
                $success = true;
            }
        } else {
            $success = false;
        }

        if ($success) {
            // If AJAX, return JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'new_status' => $new_status]);
                exit;
            }

            redirect(BASE_PATH . '/staff/orders.php?success=1');
        } else {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Database update failed']);
                exit;
            }
            redirect(BASE_PATH . '/staff/orders.php?error=1');
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }
}

// Get filter parameters
$status_filter = trim((string)($_GET['status'] ?? ($is_pos_staff ? 'COMPLETED' : 'ALL')));
$valid_status_filters = $is_pos_staff
    ? ['COMPLETED']
    : ['ALL', 'TO_VERIFY', 'COMPLETED', 'CANCELLED'];
if ($status_filter === '' || !in_array($status_filter, $valid_status_filters, true)) {
    $status_filter = $is_pos_staff ? 'COMPLETED' : 'ALL';
}
$date_from_filter = $_GET['date_from'] ?? '';
$date_to_filter        = $_GET['date_to']   ?? '';
$customer_filter       = $_GET['customer']  ?? '';
$sort_by               = $_GET['sort']           ?? 'newest';

$active_filters = [];
if ($date_from_filter !== '')      $active_filters['date_from']      = $date_from_filter;
if ($date_to_filter !== '')        $active_filters['date_to']        = $date_to_filter;
if ($customer_filter !== '')       $active_filters['customer']       = $customer_filter;
if ($sort_by !== 'newest')         $active_filters['sort']           = $sort_by;
$active_filter_badge_count = count(array_filter([$customer_filter, $date_from_filter, $date_to_filter], function($v) { return $v !== null && $v !== ''; }));

/**
 * SQL predicate: store order row is in "payment proof rejected — awaiting resubmission".
 * Matches job_orders REJECTED and/or orders To Pay with a persisted rejection reason.
 */
function staff_orders_sql_payment_proof_rejected(string $oAlias = 'o'): string {
    $parts = [];

    $parts[] = "({$oAlias}.status = 'Rejected')";

    // Primary signal: persisted on orders row when staff rejects (see staff/api_verify_payment.php).
    if (function_exists('db_table_has_column') && db_table_has_column('orders', 'payment_proof_needs_resubmit')) {
        $parts[] = "({$oAlias}.payment_proof_needs_resubmit = 1)";
    }

    // Durable anchor names must stay VARCHAR(10)-friendly: pay_reject
    $parts[] = "EXISTS (SELECT 1 FROM order_messages om WHERE om.order_id = {$oAlias}.order_id "
        . "AND om.message_type IN ('pay_reject','staff_pay_rejected'))";

    // Legacy rejects (before staff_pay_rejected): match product reject copy only while order is still awaiting payment again.
    $parts[] = "({$oAlias}.status = 'To Pay' AND EXISTS (SELECT 1 FROM order_messages om2 WHERE om2.order_id = {$oAlias}.order_id "
        . "AND om2.sender = 'Staff' AND om2.message_type = 'order_update' "
        . "AND om2.message LIKE '%Your payment has been rejected%' LIMIT 1))";

    $jobPieces = [];
    if (function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_proof_status')) {
        $jobPieces[] = "jo.payment_proof_status IN ('REJECTED','Rejected')";
    }
    if (function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_rejection_reason')) {
        $jobPieces[] = "(TRIM(COALESCE(jo.payment_rejection_reason, '')) <> '' "
            . "AND jo.status IN ('TO_PAY','TO PAY'))";
    }
    if ($jobPieces !== []) {
        $parts[] = 'EXISTS (SELECT 1 FROM job_orders jo WHERE jo.order_id = ' . $oAlias . '.order_id '
            . "AND jo.status NOT IN ('COMPLETED','CANCELLED') "
            . 'AND (' . implode(' OR ', $jobPieces) . '))';
    }

    $reasonParts = [];
    if (function_exists('db_table_has_column')) {
        if (db_table_has_column('orders', 'rejection_reason')) {
            $reasonParts[] = "(TRIM(COALESCE({$oAlias}.rejection_reason, '')) <> '')";
        }
        if (db_table_has_column('orders', 'payment_rejection_reason')) {
            $reasonParts[] = "(TRIM(COALESCE({$oAlias}.payment_rejection_reason, '')) <> '')";
        }
    }
    if ($reasonParts !== []) {
        $parts[] = "({$oAlias}.status = 'To Pay' AND (" . implode(' OR ', $reasonParts) . '))';
    }

    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Flag orders in the current result set whose payment proof was rejected (same rules as SQL predicate).
 *
 * @param array<int, array<string,mixed>> $orders
 */
function staff_orders_attach_payment_rejected_flags(array &$orders): void {
    if ($orders === []) {
        return;
    }
    $ids = [];
    foreach ($orders as $row) {
        $oid = (int)($row['order_id'] ?? 0);
        if ($oid > 0) {
            $ids[$oid] = true;
        }
    }
    $idList = array_keys($ids);
    if ($idList === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $types = str_repeat('i', count($idList));

    $staffPayRejected = [];
    $qr = db_query(
        "SELECT DISTINCT order_id FROM order_messages WHERE order_id IN ($placeholders) "
            . "AND message_type IN ('pay_reject','staff_pay_rejected')",
        $types,
        $idList
    ) ?: [];
    foreach ($qr as $r) {
        $staffPayRejected[(int)($r['order_id'] ?? 0)] = true;
    }

    $legacyRejectedMsg = [];
    $qr = db_query(
        "SELECT DISTINCT order_id FROM order_messages WHERE order_id IN ($placeholders) "
            . "AND sender = 'Staff' AND message_type = 'order_update' AND message LIKE '%Your payment has been rejected%'",
        $types,
        $idList
    ) ?: [];
    foreach ($qr as $r) {
        $legacyRejectedMsg[(int)($r['order_id'] ?? 0)] = true;
    }

    $fromJob = [];
    $jobPieces = [];
    if (function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_proof_status')) {
        $jobPieces[] = "payment_proof_status IN ('REJECTED','Rejected')";
    }
    if (function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_rejection_reason')) {
        $jobPieces[] = "(TRIM(COALESCE(payment_rejection_reason, '')) <> '' AND status IN ('TO_PAY','TO PAY'))";
    }
    if ($jobPieces !== []) {
        $qr = db_query(
            'SELECT DISTINCT order_id FROM job_orders WHERE order_id IN (' . $placeholders . ') '
                . "AND status NOT IN ('COMPLETED','CANCELLED') AND (" . implode(' OR ', $jobPieces) . ')',
            $types,
            $idList
        ) ?: [];
        foreach ($qr as $r) {
            $fromJob[(int)($r['order_id'] ?? 0)] = true;
        }
    }

    foreach ($orders as &$row) {
        $oid = (int)($row['order_id'] ?? 0);
        $st = (string)($row['status'] ?? '');

        $hit = !empty((int)($row['payment_proof_needs_resubmit'] ?? 0));
        $hit = $hit || (strcasecmp($st, 'Rejected') === 0);
        $hit = $hit || isset($staffPayRejected[$oid]) || isset($fromJob[$oid]);
        if (!$hit && $st === 'To Pay') {
            if (isset($legacyRejectedMsg[$oid])) {
                $hit = true;
            }
            foreach (['rejection_reason', 'payment_rejection_reason'] as $col) {
                if (!$hit && array_key_exists($col, $row) && trim((string)$row[$col]) !== '') {
                    $hit = true;
                    break;
                }
            }
        }
        $row['_staff_pay_rejected'] = $hit;
    }
    unset($row);
}

$sql_conditions = " AND o.order_type = 'product' AND {$staffOrderScopeSql}";
$params = [];
$types = '';
if ($status_filter !== 'ALL') {
    if ($status_filter === 'TO_VERIFY') {
        $sql_conditions .= " AND o.status IN ('To Verify', 'Pending Verification', 'Verify Pay')";
    } elseif ($status_filter === 'COMPLETED') {
        $sql_conditions .= " AND o.status = 'Completed'";
    } elseif ($status_filter === 'CANCELLED') {
        $sql_conditions .= " AND o.status = 'Cancelled'";
    }
}
$order_code_search_sql = "CONCAT(
    COALESCE(NULLIF((
        SELECT GROUP_CONCAT(DISTINCT p2.sku ORDER BY p2.sku SEPARATOR '-')
        FROM order_items oi2
        LEFT JOIN products p2 ON oi2.product_id = p2.product_id
        WHERE oi2.order_id = o.order_id
    ), ''), 'ORD'),
    '-',
    o.order_id
)";

// Apply branch filtering
$sql_conditions .= branch_where('o', $staffBranchId, $types, $params);
$sql_conditions .= " AND o.status = 'Completed'";
if ($date_from_filter !== '') {
    $sql_conditions .= " AND DATE(o.order_date) >= ?";
    $params[] = $date_from_filter;
    $types .= 's';
}
if ($date_to_filter !== '') {
    $sql_conditions .= " AND DATE(o.order_date) <= ?";
    $params[] = $date_to_filter;
    $types .= 's';
}
if ($customer_filter !== '') {
    $sql_conditions .= " AND (
        o.order_id LIKE ?
        OR {$order_code_search_sql} LIKE ?
        OR EXISTS (
            SELECT 1
            FROM order_items oi2
            LEFT JOIN products p2 ON oi2.product_id = p2.product_id
            WHERE oi2.order_id = o.order_id
              AND (p2.name LIKE ? OR p2.sku LIKE ?)
        )
    )";
    $like = '%' . $customer_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$sql = "SELECT o.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''), 'Walk-in Customer (Guest)') as customer_name,
        (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) as order_sku,
        (SELECT GROUP_CONCAT(COALESCE(p.name, 'Custom Product') SEPARATOR ', ') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) as item_names,
        (SELECT oi.customization_data FROM order_items oi WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) as first_item_customization
        FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id WHERE 1=1" . $sql_conditions;

$count_sql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id WHERE 1=1" . $sql_conditions;

// Pagination settings
$items_per_page = 15;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $items_per_page;

$total_result = db_query($count_sql, $types, $params);
$total_items = $total_result[0]['total'] ?? 0;
$total_pages = ceil($total_items / $items_per_page);

$sort_clause = match($sort_by) {
    'oldest' => " ORDER BY o.order_date ASC",
    'az'     => " ORDER BY customer_name ASC",
    'za'     => " ORDER BY customer_name DESC",
    default  => " ORDER BY o.order_date DESC"
};

$sql .= $sort_clause . " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= 'ii';

$orders = db_query($sql, $types, $params);
if (!is_array($orders)) {
    $orders = [];
}
staff_orders_attach_payment_rejected_flags($orders);
foreach ($orders as &$order) {
    $order['order_code'] = printflow_format_order_code($order['order_id'] ?? 0, $order['order_sku'] ?? '');
}
unset($order);

// Get KPI statistics (branch-specific)
// Note: o.order_type = 'product' filter from line 108 is preserved in $sql_conditions
$kpi_conditions = " AND o.order_type = 'product' AND {$staffOrderScopeSql}";
$kpi_types = '';
$kpi_params = [];
$kpi_conditions .= branch_where('o', $staffBranchId, $kpi_types, $kpi_params);

$all_counts = [
    'ALL' => db_query("SELECT COUNT(*) as count FROM orders o WHERE 1=1 {$kpi_conditions}", $kpi_types ?: null, $kpi_params ?: null)[0]['count'] ?? 0,
    'TO_VERIFY' => db_query("SELECT COUNT(*) as count FROM orders o WHERE o.status IN ('To Verify', 'Pending Verification', 'Verify Pay') {$kpi_conditions}", $kpi_types ?: null, $kpi_params ?: null)[0]['count'] ?? 0,
    'COMPLETED' => db_query("SELECT COUNT(*) as count FROM orders o WHERE o.status = 'Completed' {$kpi_conditions}", $kpi_types ?: null, $kpi_params ?: null)[0]['count'] ?? 0,
    'CANCELLED' => db_query("SELECT COUNT(*) as count FROM orders o WHERE o.status = 'Cancelled' {$kpi_conditions}", $kpi_types ?: null, $kpi_params ?: null)[0]['count'] ?? 0,
];
$total_count = $all_counts['ALL'];
$total_revenue = db_query(
    "SELECT COALESCE(SUM(o.total_amount), 0) as total FROM orders o WHERE o.status = 'Completed' {$kpi_conditions}",
    $kpi_types ?: null,
    $kpi_params ?: null
)[0]['total'] ?? 0;

function staff_orders_display_status(string $status): string {
    $status = trim($status);
    $knownStatuses = [
        'Completed',
        'Cancelled',
        'To Verify',
        'Pending Verification',
        'Verify Pay',
        'To Pickup',
        'Ready for Pickup',
        'Rejected',
        'Payment Rejected'
    ];
    if (in_array($status, $knownStatuses, true)) {
        return $status;
    }
    return 'Pending';
}

function staff_orders_status_pill_style(string $displayStatus): string {
    return match (strtoupper(trim($displayStatus))) {
        'PENDING' => 'background:#fef3c7;color:#92400e;',
        'REJECTED' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
        'TO VERIFY' => 'background:#fef9c3;color:#92400e;',
        'PENDING VERIFICATION' => 'background:#fef9c3;color:#92400e;',
        'VERIFY PAY' => 'background:#fef9c3;color:#92400e;',
        'APPROVED' => 'background:#dbeafe;color:#1e40af;',
        'PAYMENT REJECTED' => 'background:#ffe4e6;color:#9f1239;border:1px solid #fecdd3;',
        'TO PAY' => 'background:#fef3c7;color:#b45309;',
        'TO PICK UP' => 'background:#ede9fe;color:#5b21b6;',
        'READY FOR PICKUP' => 'background:#dcfce7;color:#15803d;',
        'IN PRODUCTION' => 'background:#d1fae5;color:#065f46;',
        'COMPLETED' => 'background:#dcfce7;color:#166534;',
        'CANCELLED' => 'background:#fee2e2;color:#991b1b;',
        'RATED' => 'background:#e9d5ff;color:#6b21a8;',
        default => 'background:#f3f4f6;color:#374151;',
    };
}

function staff_orders_status_pill_html(string $displayStatus): string {
    $style = staff_orders_status_pill_style($displayStatus);
    $label = htmlspecialchars(ucwords(strtolower(trim($displayStatus))));
    return '<span class="pf-pill" style="' . $style . '">' . $label . '</span>';
}

function staff_orders_product_name(array $order): string {
    $display_items = (string)($order['item_names'] ?? '');
    if ($display_items === '') {
        return 'Custom Product';
    }

    if ($display_items === 'Custom Product' || $display_items === 'Custom Order') {
        $firstCustomization = $order['first_item_customization'] ?? '{}';
        $display_items = get_service_name_from_customization($firstCustomization, $display_items);
        $cJson = json_decode((string)$firstCustomization, true);
        if (is_array($cJson) && !empty($cJson['product_type']) && $cJson['product_type'] !== $display_items) {
            $display_items .= ' (' . $cJson['product_type'] . ')';
        }
    }

    return $display_items;
}

// Handle specific AJAX request for drawing the table
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ob_start();
    if (empty($orders)) {
        echo '<tr><td colspan="8" style="text-align:center; padding: 48px; color:#64748b; font-size:14px; font-weight:600;">No orders found matching your filters.</td></tr>';
    } else {
        foreach ($orders as $order) {
            ?>
            <tr class="staff-order-row" onclick="openOrderModal(<?php echo $order['order_id']; ?>)">
                <td class="pl-6 pr-4 py-4 relative">
                    <div class="row-indicator"></div>
                    <div class="order-info-cell">
                        <div class="order-id-wrap">
                            <span class="truncate-ellipsis" title="<?php echo htmlspecialchars($order['order_code']); ?>"><?php echo htmlspecialchars($order['order_code']); ?></span>
                            <?php 
                            $unread = get_unread_chat_count($order['order_id'], 'User');
                            if ($unread > 0): 
                            ?>
                                <span style="background: #ef4444; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; animation: pulse 2s infinite;" title="<?php echo $unread; ?> new messages from customer">
                                    <?php echo $unread; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-4">
                    <?php $display_product_name = staff_orders_product_name($order); ?>
                    <div class="table-text-main truncate-ellipsis" title="<?php echo htmlspecialchars($display_product_name); ?>">
                        <?php echo htmlspecialchars(strlen($display_product_name) > 120 ? substr($display_product_name, 0, 120) . '...' : $display_product_name); ?>
                    </div>
                </td>
                <td class="px-4 py-4">
                    <div class="table-text-main truncate-ellipsis" title="<?php echo htmlspecialchars($order['customer_name']); ?>">
                        <?php echo htmlspecialchars($order['customer_name']); ?>
                    </div>
                </td>
                <td class="px-4 py-4 status-col-cell">
                    <?php if (($order['order_source'] ?? '') === 'pos'): ?>
                        <span class="pf-pill source-badge-pill pos">Pos</span>
                    <?php else: ?>
                        <span class="pf-pill source-badge-pill online">Online</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-4">
                    <div class="table-text-sub truncate-ellipsis" title="<?php echo htmlspecialchars(format_date($order['order_date'])); ?>">
                        <?php echo format_date($order['order_date']); ?>
                    </div>
                </td>
                <td class="px-4 py-4">
                    <div class="table-text-main truncate-ellipsis" title="<?php echo htmlspecialchars(format_currency($order['total_amount'])); ?>">
                        <?php echo format_currency($order['total_amount']); ?>
                    </div>
                </td>
                <td class="px-4 py-4 status-col-cell">
                    <?php
                    // Counter staff uses a simplified Pending/Completed display.
                    $display_order_status = staff_orders_display_status((string)$order['status']);
                    ?>
                    <div class="status-col-inner">
                        <?php echo staff_orders_status_pill_html($display_order_status); ?>
                        <?php if (($order['design_status'] ?? '') === 'Revision Submitted'): ?>
                            <div>
                                <?php echo staff_orders_status_pill_html('Revision Submitted'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-4 py-4 action-col-cell">
                    <div class="action-cell">
                        <button
                            onclick="event.stopPropagation(); window.openStaffOrderManage(<?php echo $order['order_id']; ?>, '<?php echo addslashes($order['status']); ?>');"
                            class="table-action-btn alt"
                        >
                            View
                        </button>
                        <a href="<?php echo BASE_PATH; ?>/staff/chats.php?order_id=<?php echo $order['order_id']; ?>"
                            onclick="event.stopPropagation();"
                            class="table-action-btn"
                        >
                            Message
                        </a>
                    </div>
                </td>
            </tr>
            <?php
        }
    }
    $tbody = ob_get_clean();
    $pagination = render_pagination($current_page, $total_pages, $active_filters);
    
    header('Content-Type: application/json');
    echo json_encode([
        'tbody'      => $tbody, 
        'pagination' => $pagination, 
        'total'      => number_format($total_items),
        'counts'     => $all_counts,
        'badge'      => $active_filter_badge_count
    ]);
    exit;
}

$page_title = 'Orders - Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-visit-control" content="reload">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/chat.css'); ?>">
    <style>
        /* ── Tabs for Status Filtering ─── */
        .pf-custom-tabs {
            display: flex !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 10px !important;
            margin-bottom: 24px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            padding-bottom: 16px !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            width: 100% !important;
        }
        .pf-custom-tabs::-webkit-scrollbar {
            display: none !important;
        }
        .toolbar-group--tabs {
            display: flex !important;
            justify-content: center !important;
            width: 100% !important;
        }
        .pill-tab {
            flex-shrink: 0 !important;
            white-space: nowrap !important;
        }
        .toolbar-group {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
        }
        .pill-tab { 
            position: relative;
            padding: 8px 16px; 
            font-weight: 500; 
            font-size: 11px; 
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #3f5f5f; 
            border-radius: 9999px; 
            transition: all 0.2s; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px;
            background: #ffffff;
            border: 1px solid transparent;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            user-select: none;
        }
        .pill-tab > :first-child {
            display: inline-block;
            max-width: 110px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .pill-tab:hover { background: #eef8f6; color: #023d3d; border-color: rgba(6, 161, 161, 0.22); }
        .pill-tab.active { background: linear-gradient(135deg, #f7fefb 0%, #e5f9f2 42%, #d4f0e6 100%); color: #023d3d; border-color: #06A1A1; box-shadow: 0 6px 18px rgba(6,161,161,0.12); }
        .tab-count { 
            background: #e7f3f0; 
            color: #035f5f; 
            font-size: 10px; 
            padding: 2px 7px; 
            border-radius: 9999px; 
            font-weight: 700;
        }
        .pill-tab.active .tab-count { background: #06A1A1; color: white; }

        @media (max-width: 768px) {
            .toolbar-group--tabs {
                justify-content: flex-start !important;
            }
            .pf-custom-tabs {
                justify-content: flex-start !important;
            }
        }

        /* ── Toolbar Buttons (Sort / Filter) ─── */
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }
        .toolbar-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .toolbar-group--actions {
            margin-left: auto;
        }
        .pf-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            max-width: 100%;
        }

        /* ── Filter Panel ─── */
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
            overflow: hidden;
        }
        .filter-header {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }
        .filter-section {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .filter-section:last-of-type { border-bottom: none; }
        .filter-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-reset-link {
            font-size: 12px;
            font-weight: 600;
            color: #0d9488;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            box-sizing: border-box;
        }
        .filter-input:focus { outline: none; border-color: #0d9488; }

        .filter-date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        .filter-select {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            background: #fff;
            box-sizing: border-box;
            cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: #0d9488; }

        .filter-search-wrap { position: relative; }
        .filter-search-wrap svg {
            position: absolute;
            left: 9px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }
        .filter-search-input {
            width: 100%;
            height: 38px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            padding: 0 12px;
            color: #1f2937;
            box-sizing: border-box;
            transition: all 0.2s;
        }
        .filter-search-input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1); }
        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }
        .filter-btn-reset {
            flex: 1;
            height: 40px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 400;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-btn-reset:hover { background: #f9fafb; border-color: #d1d5db; }

        .kpi-row {
            gap: 16px;
            margin-bottom: 24px;
        }

        .staff-orders-table-card {
            margin-top: 12px;
        }

        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .filter-close-btn {
            border: none;
            background: transparent;
            color: #374151;
            cursor: pointer;
            font-size: 26px;
            line-height: 1;
            padding: 0;
            margin: -2px 0 0;
        }
        .filter-close-btn:hover {
            color: #111827;
        }

        /* ── Sort Dropdown ─── */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 200px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
            padding: 6px 0;
            overflow: hidden;
        }
        .sort-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
            transition: background 0.1s;
        }
        .sort-option:hover { background: #f9fafb; }
        .sort-option.selected,
        .sort-option.active { color: #0d9488; font-weight: 600; background: #f0fdfa; }
        .sort-option .check { margin-left: auto; color: #0d9488; }

        /* ── Active filter badge ─── */
        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d9488;
            color: #fff;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }

        /* ── Table improvements (match customizations table) ─── */
        .orders-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; table-layout: fixed; }
        .orders-table thead th {
            padding: 18px 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            text-align: left;
            background: #f9fafb;
            border-bottom: 2px solid #f3f4f6;
            white-space: nowrap;
        }
        .orders-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #374151;
        }
        .status-col-cell {
            text-align: center;
            overflow: hidden;
        }
        .status-col-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            min-width: 0;
        }
        .status-col-inner > * {
            max-width: 100%;
        }
        .action-col-cell {
            text-align: center;
            white-space: nowrap;
        }
        .orders-table tbody tr { cursor: pointer; transition: background-color 0.18s ease, box-shadow 0.18s ease; }
        .orders-table tbody tr:hover { background: linear-gradient(90deg, rgba(6, 161, 161, 0.06) 0%, rgba(158, 215, 196, 0.12) 100%); }
        .orders-table tbody tr:last-child td { border-bottom: none; }

        .table-text-main { font-size: 13px; color: #111827; font-weight: 500; }
        .table-text-sub { font-size: 11px; color: #6b7280; font-weight: 400; }
        .truncate-ellipsis {
            display: block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .source-badge-pill {
            min-width: 76px;
            text-align: center;
        }
        .source-badge-pill.online {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .source-badge-pill.pos {
            background: #fef3c7;
            color: #92400e;
        }

        .row-indicator {
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 2px;
            width: 3px;
            background: linear-gradient(180deg, #035f5f 0%, #06A1A1 55%, #9ED7C4 100%);
            border-radius: 0 4px 4px 0;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .orders-table tbody tr:hover .row-indicator { opacity: 1; }

        .action-cell { display: flex; justify-content: center; gap: 6px; }
        .table-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 5px 12px;
            border: 1px solid #06A1A1;
            border-radius: 6px;
            background: transparent;
            color: #06A1A1;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none !important;
            transition: all 0.15s ease;
        }
        .table-action-btn:hover {
            background: #06A1A1;
            color: #fff !important;
            border-color: #06A1A1;
        }
        .table-action-btn.alt {
            border-color: #0f766e;
            color: #0f766e;
        }
        .table-action-btn.alt:hover {
            background: #0f766e;
            color: #fff !important;
            border-color: #0f766e;
        }
        .order-info-cell { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .order-id-wrap { font-weight: 500; color: #111827; font-size: 13px; display: flex; align-items: center; gap: 8px; min-width: 0; }
        .order-id-wrap > span:first-child,
        .order-id-wrap > :first-child {
            min-width: 0;
        }
        .order-items-sub { font-size: 11px; color: #6b7280; font-weight: 400; }

        /* ── Order Detail Modal ─────────────────────────────────── */
        #orderModal {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            opacity: 0; pointer-events: none;
            transition: opacity 0.25s ease;
        }
        #orderModal.open { opacity: 1; pointer-events: all; }

        .om-backdrop {
            position: absolute; inset: 0;
            background: rgba(0,0,0,0.5);
        }

        .om-panel {
            position: relative; z-index: 1;
            background: #fff;
            border-radius: 12px;
            width: 100%; max-width: 640px;
            max-height: 88vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
            transform: translateY(8px);
            transition: transform 0.22s ease, opacity 0.22s ease;
            opacity: 0;
        }
        #orderModal.open .om-panel {
            transform: translateY(0);
            opacity: 1;
        }

        .om-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #f3f4f6;
            position: sticky; top: 0; background: #fff; border-radius: 12px 12px 0 0; z-index: 2;
        }
        .om-title { font-size: 18px; font-weight: 700; color: #1f2937; }
        .om-subtitle { font-size: 13px; color: #64748b; margin-top: 4px; }
        .om-close {
            width: 40px; height: 40px; border-radius: 10px;
            border: 1px solid #e5e7eb; background: #fff; color: #64748b;
            cursor: pointer; font-size: 20px; display: flex; align-items: center; justify-content: center;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
        }
        .om-close:hover { background: #f9fafb; color: #1f2937; border-color: #d1d5db; }

        .om-body { padding: 24px; }
        .om-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 700px) { .om-grid { grid-template-columns: 1fr; } }

        /* ═══════════════════════════════════════════════
           MOBILE FIXES — Staff Orders Page
           Target: iPhone / small screens (≤768px)
           ─────────────────────────────────────────────
           RULE: Nothing must ever overflow the viewport.
           All long text uses ellipsis. View + Message
           buttons are ALWAYS visible side-by-side.
        ═══════════════════════════════════════════════ */
        @media (max-width: 768px) {

            /* 1. Stop horizontal scroll at the ROOT level */
            html, body {
                max-width: 100vw !important;
                overflow-x: hidden !important;
            }

            .dashboard-container {
                max-width: 100vw !important;
                overflow-x: hidden !important;
            }

            .main-content {
                max-width: 100vw !important;
                overflow-x: hidden !important;
            }

            .main-content header {
                padding: 14px 12px !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 8px !important;
                margin-bottom: 4px !important;
                max-width: 100vw !important;
                box-sizing: border-box !important;
            }

            #mobileBurger {
                position: static !important;
                margin-bottom: 0 !important;
            }

            .page-title { font-size: 18px !important; }

            .main-content main {
                padding: 0 10px 24px !important;
                max-width: 100vw !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }

            /* 2. KPI: 2 columns × 2 rows */
            .kpi-row {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px !important;
                margin-bottom: 14px !important;
            }

            .kpi-card {
                padding: 10px 12px !important;
                border-radius: 10px !important;
                min-width: 0 !important;
                width: 100% !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
            }

            .kpi-card .kpi-value {
                font-size: 18px !important;
                line-height: 1.2 !important;
                word-break: break-all !important;
            }

            .kpi-card .kpi-label,
            .kpi-card .kpi-sub {
                font-size: 9px !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                display: block !important;
            }

            /* 3. Toolbar — keep Sort+Filter buttons in one row */
            .toolbar-container {
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 8px !important;
                flex-wrap: nowrap !important;
            }

            .toolbar-group--title {
                flex: 1 1 auto !important;
                min-width: 0 !important;
            }

            .toolbar-group--actions {
                flex: 0 0 auto !important;
                display: flex !important;
                gap: 6px !important;
            }

            .toolbar-btn {
                padding: 7px 10px !important;
                font-size: 12px !important;
            }

            /* ── CRITICAL: Contain the whole card and table ── */
            .staff-orders-table-card { padding: 10px !important; overflow: hidden !important; max-width: 100vw !important; width: 100% !important; box-sizing: border-box !important; }

            /* Kill the global min-width that causes overflow */
            html.printflow-staff .orders-table, .orders-table { display: block !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; overflow: hidden !important; box-sizing: border-box !important; }
            html.printflow-staff .orders-table thead, .orders-table thead { display: none !important; }
            html.printflow-staff .orders-table tbody, .orders-table tbody { display: block !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; overflow: hidden !important; }

            /* Each order row = a card */
            html.printflow-staff .orders-table tr, .orders-table tr { display: flex !important; flex-direction: column !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; box-sizing: border-box !important; margin-bottom: 10px !important; border: 1px solid #e2e8f0 !important; border-radius: 10px !important; background: #fff !important; overflow: hidden !important; padding: 0 !important; gap: 0 !important; }

            /* Every cell: full-width, contained, never overflows */
            html.printflow-staff .orders-table td, .orders-table td { display: flex !important; align-items: center !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; box-sizing: border-box !important; padding: 6px 12px !important; border-bottom: 1px solid #f1f5f9 !important; overflow: hidden !important; font-size: 12px !important; color: #374151 !important; }
            html.printflow-staff .orders-table td:last-child, .orders-table td:last-child { border-bottom: none !important; }

            /* ── Row 0: Order ID (header bar of card) ── */
            html.printflow-staff .orders-table td:first-child, .orders-table td:first-child { order: 0 !important; background: #f8fafc !important; padding: 8px 12px !important; font-weight: 700 !important; color: #1e293b !important; gap: 6px !important; }
            .orders-table td:first-child::before { content: none !important; }
            .orders-table td:first-child .row-indicator { top: 0 !important; bottom: 0 !important; left: 0 !important; width: 3px !important; border-radius: 0 !important; opacity: 1 !important; }

            /* ── Row 1: Product name – single line with ellipsis ── */
            html.printflow-staff .orders-table td:nth-child(2), .orders-table td:nth-child(2) { order: 1 !important; padding: 7px 12px !important; overflow: hidden !important; }
            html.printflow-staff .orders-table td:nth-child(2)::before, .orders-table td:nth-child(2)::before { display: none !important; }
            html.printflow-staff .orders-table td:nth-child(2) .table-text-main, .orders-table td:nth-child(2) .table-text-main { font-size: 12px !important; font-weight: 600 !important; color: #111827 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; max-width: 220px !important; width: 100% !important; display: block !important; }

            /* ── Row 2: Customer name ── */
            html.printflow-staff .orders-table td:nth-child(3), .orders-table td:nth-child(3) { order: 2 !important; overflow: hidden !important; }
            html.printflow-staff .orders-table td:nth-child(3)::before, .orders-table td:nth-child(3)::before { content: "Customer  " !important; font-size: 9px !important; font-weight: 700 !important; text-transform: uppercase !important; color: #94a3b8 !important; flex-shrink: 0 !important; white-space: nowrap !important; margin-right: 4px !important; }
            html.printflow-staff .orders-table td:nth-child(3) .table-text-main, .orders-table td:nth-child(3) .table-text-main { white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; max-width: 160px !important; width: 100% !important; display: block !important; }

            /* ── HIDE Source and Date ── */
            html.printflow-staff .orders-table td:nth-child(4), .orders-table td:nth-child(4), html.printflow-staff .orders-table td:nth-child(5), .orders-table td:nth-child(5) { display: none !important; }

            /* ── Row 3: Amount ── */
            html.printflow-staff .orders-table td:nth-child(6), .orders-table td:nth-child(6) { order: 3 !important; overflow: hidden !important; }
            .orders-table td:nth-child(6)::before { content: "Amount  " !important; font-size: 9px !important; font-weight: 700 !important; text-transform: uppercase !important; color: #94a3b8 !important; flex-shrink: 0 !important; white-space: nowrap !important; margin-right: 4px !important; }

            /* ── Row 4: Status ── */
            html.printflow-staff .orders-table td.status-col-cell, .orders-table td.status-col-cell { order: 4 !important; justify-content: flex-start !important; gap: 6px !important; overflow: hidden !important; }
            .orders-table td.status-col-cell::before { content: "Status  " !important; font-size: 9px !important; font-weight: 700 !important; text-transform: uppercase !important; color: #94a3b8 !important; flex-shrink: 0 !important; white-space: nowrap !important; margin-right: 4px !important; }

            /* ── Row 5: Action buttons — ALWAYS both visible ── */
            html.printflow-staff .orders-table td.action-col-cell, .orders-table td.action-col-cell { order: 10 !important; padding: 8px 10px !important; border-top: 1px solid #e8eef3 !important; border-bottom: none !important; overflow: visible !important; }
            .orders-table td.action-col-cell::before { display: none !important; }
            .action-cell { display: flex !important; width: 100% !important; gap: 6px !important; flex-wrap: nowrap !important; }
            
            /* High specificity to force View + Message onto one line equally */
            html.printflow-staff .orders-table .action-cell .table-action-btn, 
            html.printflow-staff .orders-table .action-cell a.table-action-btn, 
            .orders-table .action-cell .table-action-btn, 
            .orders-table .action-cell a.table-action-btn { 
                display: inline-flex !important; align-items: center !important; justify-content: center !important; 
                flex: 1 1 0 !important; width: calc(50% - 3px) !important; max-width: calc(50% - 3px) !important; 
                min-width: 0 !important; padding: 8px 4px !important; font-size: 12px !important; 
                font-weight: 600 !important; border-radius: 8px !important; white-space: nowrap !important; 
                overflow: hidden !important; text-overflow: ellipsis !important; min-height: 36px !important; 
                box-sizing: border-box !important; 
            }
        }


        .om-card {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 20px;
        }
        .om-card-title {
            font-size: 0.7rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.07em; color: #94a3b8; margin-bottom: 14px;
        }
        .om-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13.5px;
        }
        .om-row:last-child { border-bottom: none; }
        .om-label { color: #6b7280; }
        .om-value { font-weight: 600; color: #1e293b; text-align: right; }

        .om-notes {
            margin-top: 14px; padding: 14px 16px;
            background: linear-gradient(135deg,#fffbeb,#fef3c7);
            border: 1px solid #fde68a; border-radius: 12px;
            max-height: 180px; overflow-y: auto;
        }
        .om-notes-title { font-size: 12px; font-weight: 800; color: #92400e; margin-bottom: 6px; }
        .om-notes-text { font-size: 13px; color: #b45309; line-height: 1.6; overflow-wrap: anywhere; word-break: break-word; }

        .om-cust-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .om-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg,#667eea,#764ba2);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 16px; flex-shrink: 0;
        }



        /* Items table */
        .om-items-section { margin-top: 20px; }
        .om-items-title { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.07em; color: #94a3b8; margin-bottom: 12px; }
        .om-items-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .om-items-table th {
            text-align: left; padding: 8px 10px;
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #94a3b8;
            border-bottom: 2px solid #e2e8f0;
        }
        .om-items-table td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .om-items-table tr:last-child td { border-bottom: none; }
        .om-items-total td { border-top: 2px solid #e2e8f0 !important; font-weight: 700; }

        /* Design image */
        .om-design-wrap { margin-top: 10px; }
        .om-design-img {
            max-width: 140px; border-radius: 8px; border: 2px solid #e2e8f0;
            cursor: zoom-in; transition: transform 0.2s, box-shadow 0.2s;
        }
        .om-design-img:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(0,0,0,0.15); }

        /* Customs chips */
        .om-custom-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .om-chip {
            background: #e0e7ff; color: #4338ca;
            border-radius: 99px; padding: 2px 10px;
            font-size: 11px; font-weight: 600;
        }

        /* Loader */
        .om-loader { text-align: center; padding: 64px 0; }
        .om-spinner {
            width: 40px; height: 40px; border-radius: 50%;
            border: 3px solid #e2e8f0; border-top-color: #06A1A1;
            animation: om-spin 0.7s linear infinite; margin: 0 auto 12px;
        }
        @keyframes om-spin { to { transform: rotate(360deg); } }

        /* Alert flash inside modal */
        .om-alert { border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }
        .om-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .om-alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        /* Customer orders list */
        .om-cust-orders { margin-top: 14px; }
        .om-co-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 12.5px; }
        .om-co-row:last-child { border-bottom: none; }

        /* Status badge replicated in JS */
        .badge { display: inline-flex; align-items:center; justify-content:center; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; line-height:1; white-space:nowrap; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-gray { background: #f3f4f6; color: #374151; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }

        /* Table hover + clickable rows */
        .orders-table tbody tr { transition: background 0.15s; }
        .orders-table tbody tr:hover td { background: #f9fafb; }

        /* ── Centered Status Overlay ───────────────────────── */
        .om-status-overlay {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            z-index: 100; pointer-events: none; opacity: 0;
            transition: opacity 0.3s ease;
        }
        .om-status-overlay.active { opacity: 1; pointer-events: all; }
        
        .om-status-toast {
            background: rgba(15, 23, 42, 0.9);
            color: #fff; padding: 16px 24px; border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            display: flex; flex-direction: column; align-items: center; gap: 12px;
            transform: scale(0.9); transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
            max-width: 280px; text-align: center;
        }
        .om-status-overlay.active .om-status-toast { transform: scale(1); }
        .om-status-toast-icon { font-size: 2rem; }
        .om-status-toast-msg { font-size: 14px; font-weight: 600; line-height: 1.4; }
    </style>
    <script>
    /* ═══════════════════════════════════════════════════════
       Staff Orders Page — All functions defined in <head>
       so they are available before any onclick fires,
       regardless of Turbo Drive full vs partial navigation.
    ═══════════════════════════════════════════════════════ */

    // ── Navigate without Turbo Drive interception ────────
    const STAFF_BASE_PATH = <?php echo json_encode(BASE_PATH); ?>;
    function staffUrl(path) {
        return (STAFF_BASE_PATH || '') + '/' + String(path || '').replace(/^\/+/, '');
    }

    function getProfileImage(image) {
        if (!image || image === 'null' || image === 'undefined') {
            return staffUrl('public/assets/uploads/profiles/default.png');
        }
        if (typeof image !== 'string') return staffUrl('public/assets/uploads/profiles/default.png');
        if (image.startsWith('/') || image.startsWith('http')) return image;
        return staffUrl('public/assets/uploads/profiles/' + image);
    }

    function openStaffOrderManage(orderId, status = '') {
        // Always open the modal for all product order statuses
        openOrderModal(orderId);
    }

    // ── Status badge helper ──────────────────────────────
    function statusBadge(val) {
        var map = {
            'Completed':             'background: #dcfce7; color: #166534;',
            'Pending':               'background: #fef3c7; color: #92400e;',
            'Pending Review':        'background: #fef3c7; color: #92400e;',
            'Approved':              'background: #dbeafe; color: #1e40af;',
            'To Pay':                'background: #dbeafe; color: #1e40af;',
            'To Verify':             'background: #fef9c3; color: #854d0e;',
            'Downpayment Submitted': 'background: #fce7f3; color: #be185d;',
            'Pending Verification':  'background: #fef9c3; color: #854d0e;',
            'Processing':            'background: #e0e7ff; color: #4338ca;',
            'In Production':         'background: #cffafe; color: #0891b2;',
            'Printing':              'background: #cffafe; color: #0891b2;',
            'For Revision':          'background: #ffe4e6; color: #b91c1c;',
            'Revision Submitted':    'background: #fef3c7; color: #92400e; border: 1px solid #ffe58f;',
            'Ready for Pickup':      'background: #dcfce7; color: #15803d;',
            'To Pickup':             'background: #dcfce7; color: #15803d;',
            'Cancelled':             'background: #fee2e2; color: #991b1b;',
            'Paid':                  'background: #dcfce7; color: #166534;',
            'Unpaid':                'background: #fee2e2; color: #991b1b;',
            'Partially Paid':        'background: #fef3c7; color: #92400e;',
            'Partial':               'background: #fef3c7; color: #92400e;',
            'To Rate':               'background:#f3e8ff;color:#6b21a8;',
            'Rated':                 'background:#f3e8ff;color:#6b21a8;',
            'Rejected':              'background:#fee2e2;color:#991b1b;',
        };
        var display = val;
        if (val === 'Completed') display = 'Completed';
        else if (val === 'Cancelled') display = 'Cancelled';
        else display = 'Pending';
        var style = map[display] || 'background: #F3F4F6; color: #374151;';

        return '<span class="pf-pill" style="' + style + '">' + display + '</span>';
    }

    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    function formatCurrency(val) {
        return '₱' + parseFloat(val).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    function toggleAdvancedFilters() {
        var adv = document.getElementById('advancedFilters');
        if (!adv) return;
        if (adv.style.display === 'none') {
            adv.style.display = 'grid';
        } else {
            adv.style.display = 'none';
        }
    }

    // ── AJAX Table Updates ───────────────────────────────
    var searchDebounceTimer = null;
    var ordersFetchController = null;
    var ordersRequestSerial = 0;
    var staffOrdersRealtimeMs = 15000;
    var staffOrdersRealtimeTimer = null;
    var staffOrdersRealtimeBound = false;

    function buildFilterURL(overrides = {}, isAjax = false) {
        const params = new URLSearchParams(window.location.search);
        const fields = {
            status:         () => document.getElementById('fp_status')?.value         || '',
            customer:       () => document.getElementById('fp_customer')?.value       || '',
            date_from:      () => document.getElementById('fp_date_from')?.value      || '',
            date_to:        () => document.getElementById('fp_date_to')?.value        || '',
        };
        for (const [key, getter] of Object.entries(fields)) {
            let val = (overrides[key] !== undefined) ? overrides[key] : getter();
            if (val) params.set(key, val);
            else params.delete(key);
        }
        if (overrides.sort !== undefined) {
            if (overrides.sort && overrides.sort !== 'newest') params.set('sort', overrides.sort);
            else params.delete('sort');
        }
        if (overrides.page !== undefined) {
            if (overrides.page && overrides.page > 1) params.set('page', overrides.page);
            else params.delete('page');
        }
        if (isAjax) params.set('ajax', '1');
        else params.delete('ajax');
        
        return window.location.pathname + '?' + params.toString();
    }

    function getOrdersDashboardData() {
        const dashboardEl = document.querySelector('[x-data^="ordersPage"]');
        return (dashboardEl && dashboardEl.__x && dashboardEl.__x.$data) ? dashboardEl.__x.$data : null;
    }

    async function fetchUpdatedTable(overrides = {}, options = {}) {
        const silent = !!options.silent;
        const url = buildFilterURL(overrides, true);
        const container = document.querySelector('.orders-table tbody');
        if (!container) return;

        ordersRequestSerial += 1;
        const requestSerial = ordersRequestSerial;
        if (ordersFetchController) {
            ordersFetchController.abort();
        }
        ordersFetchController = new AbortController();

        if (!silent) {
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
        }

        try {
            const resp = await fetch(url, { signal: ordersFetchController.signal });
            const data = await resp.json();
            if (requestSerial !== ordersRequestSerial) return;

            container.innerHTML = data.tbody;

            const pag = document.querySelector('.pagination-container');
            if (pag && data.pagination) pag.outerHTML = data.pagination;

            const bc = document.getElementById('filterBadgeContainer');
            if (bc) bc.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';

            const countEl = document.getElementById('totalOrdersCount');
            if (countEl) countEl.textContent = data.total;

            const dashboard = getOrdersDashboardData();
            if (dashboard && data.counts) {
                dashboard.updateCounts(data.counts);
            }

            window.dispatchEvent(new CustomEvent('filter-badge-update', { detail: { badge: data.badge } }));

            const displayUrl = buildFilterURL(overrides, false);
            window.history.replaceState({ path: displayUrl }, '', displayUrl);
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.error('Error updating table:', e);
            }
        } finally {
            if (!silent) {
                container.style.opacity = '1';
                container.style.pointerEvents = 'all';
            }
            if (requestSerial === ordersRequestSerial) {
                ordersFetchController = null;
            }
        }
    }

    function applyFilters(resetAll = false) {
        if (resetAll) {
            window.location.href = window.location.pathname;
        } else { fetchUpdatedTable(); }
    }

    function applySortFilter(sortKey) {
        window.dispatchEvent(new CustomEvent('sort-changed', { detail: { sortKey } }));
        fetchUpdatedTable({ sort: sortKey });
    }

    function resetFilterField(fields) {
        fields.forEach(f => {
            const el = document.getElementById('fp_' + f);
            if (el) el.value = '';
        });
        fetchUpdatedTable();
    }

    function ordersPage() {
        return {
            filterOpen: false,
            sortOpen:   false,
            activeSort: '<?php echo $sort_by; ?>',
            hasActiveFilters: <?php echo $active_filter_badge_count > 0 ? 'true' : 'false'; ?>,
            activeTab: '<?php echo $status_filter; ?>',
            tabCounts: <?php echo json_encode($all_counts); ?>,
            statusTabs: {
                <?php if ($is_pos_staff): ?>
                'COMPLETED': 'COMPLETED'
                <?php else: ?>
                'ALL': 'ALL',
                'TO_VERIFY': 'TO VERIFY',
                'COMPLETED': 'COMPLETED',
                'CANCELLED': 'CANCELLED'
                <?php endif; ?>
            },
            getProfileImage(image) {
                if (!image || image === 'null' || image === 'undefined') {
                    return staffUrl('public/assets/uploads/profiles/default.png');
                }
                if (typeof image !== 'string') return staffUrl('public/assets/uploads/profiles/default.png');
                if (image.startsWith('/') || image.startsWith('http')) return image;
                return staffUrl('public/assets/uploads/profiles/' + image);
            },
            
            init() {
                window.addEventListener('filter-badge-update', e => { this.hasActiveFilters = (e.detail.badge > 0); });
                window.addEventListener('sort-changed', e => { this.activeSort = e.detail.sortKey; this.sortOpen = false; });
                
                // Watch for status select changes to sync tab
                const statusEl = document.getElementById('fp_status');
                if (statusEl) {
                    statusEl.addEventListener('change', (e) => {
                        this.activeTab = e.target.value || 'ALL';
                    });
                }

                // Add debounced search
                const searchEl = document.getElementById('fp_customer');
                if (searchEl) {
                    searchEl.addEventListener('input', () => {
                        clearTimeout(searchDebounceTimer);
                        searchDebounceTimer = setTimeout(() => { applyFilters(); }, 500);
                    });
                }
            },

            switchStatusTab(key) {
                this.activeTab = key;
                const statusEl = document.getElementById('fp_status');
                if (statusEl) {
                    statusEl.value = (key === 'ALL') ? '' : key;
                    applyFilters();
                }
            },

            updateCounts(counts) {
                this.tabCounts = counts;
            }
        };
    }

    window.ordersPage = ordersPage;

    function isOrderModalOpen() {
        var modal = document.getElementById('orderModal');
        return !!(modal && modal.classList.contains('open'));
    }

    function startStaffOrdersRealtime() {
        if (staffOrdersRealtimeTimer) {
            clearInterval(staffOrdersRealtimeTimer);
        }
        staffOrdersRealtimeTimer = setInterval(function() {
            if (document.visibilityState !== 'visible') return;
            if (isOrderModalOpen()) {
                if (currentOrderId) refreshOrderModalData(currentOrderId, true);
                return;
            }
            fetchUpdatedTable({}, { silent: true });
        }, staffOrdersRealtimeMs);
    }

    function initStaffOrdersRealtime() {
        startStaffOrdersRealtime();
        if (staffOrdersRealtimeBound) return;
        staffOrdersRealtimeBound = true;

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState !== 'visible') return;
            if (isOrderModalOpen()) {
                if (currentOrderId) refreshOrderModalData(currentOrderId, true);
                return;
            }
            fetchUpdatedTable({}, { silent: true });
        });

        window.addEventListener('focus', function() {
            if (isOrderModalOpen()) {
                if (currentOrderId) refreshOrderModalData(currentOrderId, true);
                return;
            }
            fetchUpdatedTable({}, { silent: true });
        });

        document.addEventListener('turbo:before-cache', function() {
            if (staffOrdersRealtimeTimer) {
                clearInterval(staffOrdersRealtimeTimer);
                staffOrdersRealtimeTimer = null;
            }
            if (ordersFetchController) {
                ordersFetchController.abort();
                ordersFetchController = null;
            }
        });
    }

    // ── Open / close order modal ─────────────────────────
    var currentOrderId = null;
    var currentOrderModalData = null;

    function refreshOrderModalData(orderId, preserveOpen) {
        fetch(staffUrl('staff/get_order_data.php?id=') + orderId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) {
            var ct = r.headers.get('content-type') || '';


            if (!ct.includes('application/json')) {
                return r.text().then(function(txt) {
                    console.error('Non-JSON response:', txt);
                    if (!preserveOpen) {
                        document.getElementById('omBody').innerHTML =
                            '<div class="om-alert om-alert-error">Server returned unexpected response (HTTP ' + r.status + '). Check console.</div>';
                    }
                    return null;
                });
            }
            return r.text().then(function(txt) {
                if (!txt.trim()) {
                    throw new Error('Empty server response (HTTP ' + r.status + ')');
                }

                try {
                    return JSON.parse(txt);
                } catch (err) {
                    console.error('Invalid JSON response:', txt);
                    throw new Error('Invalid server JSON response (HTTP ' + r.status + ')');
                }
            });
        })
        .then(function(data) {
            if (!data) return;
            if (data.error) {
                if (!preserveOpen) {
                    document.getElementById('omBody').innerHTML =
                        '<div class="om-alert om-alert-error">Error: ' + data.error + '</div>';
                }
                return;
            }
            var orderCode = data.order_code || ('ORD-' + orderId);
            document.getElementById('omSubtitle').textContent = orderCode + (data.order_date ? ' • ' + data.order_date : '');
            currentOrderModalData = data;
            try { renderOrderModal(data); }
            catch (err) {
                console.error('Render Error:', err);
                if (!preserveOpen) {
                    document.getElementById('omBody').innerHTML =
                        '<div class="om-alert om-alert-error">Rendering Error: ' + err.message + '</div>';
                }
            }
        })
        .catch(function(err) {
            console.error('Fetch Error:', err);
            if (!preserveOpen) {
                document.getElementById('omBody').innerHTML =
                    '<div class="om-alert om-alert-error">Network Error: ' + err.message + '</div>';
            }
        });
    }

    function openOrderModal(orderId) {
        currentOrderId = orderId;
        var modal = document.getElementById('orderModal');
        document.getElementById('omTitle').textContent = 'Order Details';
        document.getElementById('omSubtitle').textContent = 'Loading…';
        document.getElementById('omBody').innerHTML =
            '<div class="om-loader"><div class="om-spinner"></div>' +
            '<div style="color:#94a3b8;font-size:14px;">Fetching order details…</div></div>';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        refreshOrderModalData(orderId, false);
    }
    window.openOrderModal = openOrderModal;

    function closeOrderModal() {
        var modal = document.getElementById('orderModal');
        if (modal) modal.classList.remove('open');
        document.body.style.overflow = '';
        currentOrderId = null;
        currentOrderModalData = null;
    }
    window.closeOrderModal = closeOrderModal;

    initStaffOrdersRealtime();

    function showStatusOverlay(icon, msg) {
        var ov = document.getElementById('omStatusOverlay');
        if (!ov) return;
        document.getElementById('omStatusIcon').textContent = icon;
        document.getElementById('omStatusMsg').textContent = msg;
        ov.classList.add('active');
        setTimeout(function() { ov.classList.remove('active'); }, 2200);
    }

    // ── Revision modal ───────────────────────────────────
    function openRevisionModal(orderId, csrfToken) {
        document.getElementById('revOrderId').value = orderId;
        document.getElementById('revCsrfToken').value = csrfToken;
        document.getElementById('revisionModal').classList.add('open');
    }
    function closeRevisionModal() {
        document.getElementById('revisionModal').classList.remove('open');
        document.getElementById('revForm').reset();
        document.getElementById('revOtherWrapper').style.display = 'none';
    }
    function handleReasonChange(select) {
        var wrap  = document.getElementById('revOtherWrapper');
        var input = document.getElementById('revOtherInput');
        if (select.value === 'Other') {
            wrap.style.display = 'block';
            input.required = true;
        } else {
            wrap.style.display = 'none';
            input.required = false;
        }
    }

    // ── Design review actions ────────────────────────────
    async function approveDesign(orderId, csrfToken) {
        const confirmed = await pfConfirm({
            title: 'Approve Design',
            text: 'Are you sure you want to approve this design? This will notify the customer.',
            icon: '📐',
            iconBg: '#eff6ff',
            iconColor: '#3b82f6',
            confirmText: 'Yes, Approve'
        });
        if (!confirmed) return;

        var fd = new FormData();
        fd.append('order_id', orderId);
        fd.append('csrf_token', csrfToken);
        fetch(staffUrl('staff/approve_design_process.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                showStatusOverlay('✅', res.message);
                setTimeout(function() { openOrderModal(orderId); fetchUpdatedTable(); }, 1200);
            } else {
                alert(res.error || 'Failed to approve design');
            }
        })
        .catch(function() { alert('Network error occurred'); });
    }

    async function markOrderCompleted(orderId, csrfToken) {
        const confirmed = await pfConfirm({
            title: 'Complete Order',
            text: 'Mark this order as COMPLETED? This will deduct items from stock and finalize the order.',
            iconBg: '#ecfdf5',
            iconColor: '#10b981',
            confirmText: 'Yes, Complete',
            confirmColor: '#059669'
        });
        if (!confirmed) return;

        var fd = new FormData();
        fd.append('order_id', orderId);
        fd.append('status', 'Completed');
        fd.append('csrf_token', csrfToken);
        
        fetch(staffUrl('staff/update_order_status_process.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                showStatusOverlay('', res.message);
                setTimeout(function() { 
                    closeOrderModal(); 
                    fetchUpdatedTable(); 
                }, 1500);
            } else {
                alert(res.error || 'Failed to complete order');
            }
        })
        .catch(function() { alert('Network error occurred'); });
    }

    async function verifyPaymentProof(orderId, action) {
        if (action === 'Reject') {
            openPaymentRejectionModal(orderId);
            return;
        }
        
        const confirmed = await pfConfirm({
            title: 'Verify Payment',
            text: 'Are you sure you want to approve this payment proof? This will move the order to the next processing stage.',
            iconBg: '#f0fdf4',
            iconColor: '#16a34a',
            confirmText: 'Yes, Approve',
            confirmColor: '#06A1A1'
        });
        if (!confirmed) return;
        
        var fd = new FormData();
        fd.append('order_id', orderId);
        fd.append('action', 'Approve');
        
        fetch(staffUrl('staff/api_verify_payment.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                showStatusOverlay('', 'Payment Verified!');
                setTimeout(function() { openOrderModal(orderId); fetchUpdatedTable(); }, 1200);
            } else {
                alert(res.error || 'Failed to verify payment');
            }
        })
        .catch(function() { alert('Network error'); });
    }

    function openPaymentRejectionModal(orderId) {
        document.getElementById('payRejOrderId').value = orderId;
        var sel = document.getElementById('payRejReasonSelect');
        var otherWrapper = document.getElementById('payRejOtherWrapper');
        var otherInput = document.getElementById('payRejOtherInput');
        sel.value = '';
        otherInput.value = '';
        otherWrapper.style.display = 'none';
        document.getElementById('paymentRejectionModal').classList.add('open');
    }

    function closePaymentRejectionModal() {
        document.getElementById('paymentRejectionModal').classList.remove('open');
    }

    function handlePayRejReasonChange(sel) {
        var wrap = document.getElementById('payRejOtherWrapper');
        wrap.style.display = (sel.value === 'Other') ? 'block' : 'none';
    }

    function submitPaymentRejection() {
        var orderId = document.getElementById('payRejOrderId').value;
        var sel = document.getElementById('payRejReasonSelect');
        var reason = sel.value;
        if (reason === 'Other') {
            reason = document.getElementById('payRejOtherInput').value;
        }

        if (!reason) {
            alert('Please select or enter a reason for rejection.');
            return;
        }

        var fd = new FormData();
        fd.append('order_id', orderId);
        fd.append('action', 'Reject');
        fd.append('reason', reason);

        fetch(staffUrl('staff/api_verify_payment.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                closePaymentRejectionModal();
                showStatusOverlay('', 'Payment Rejected');
                setTimeout(function() { openOrderModal(orderId); fetchUpdatedTable(); }, 1200);
            } else {
                alert(res.error || 'Failed to reject payment');
            }
        })
        .catch(function() { alert('Network error'); });
    }

    function orderNeedsCustomizationRedirect(orderData) {
        if (!orderData || !Array.isArray(orderData.items) || !orderData.items.length) return false;
        return orderData.items.some(function(item) {
            var productType = String(item && item.product_type ? item.product_type : '').trim().toLowerCase();
            return productType !== '' && productType !== 'fixed';
        });
    }

    function buildCustomizationRedirectUrl(orderData) {
        var params = new URLSearchParams();
        params.set('order_id', orderData.order_id);
        params.set('from', 'pos');
        if (String(orderData.order_source || '').toLowerCase() === 'pos') {
            params.set('return_to_pos', '1');
        }
        var firstCustomItem = (orderData.items || []).find(function(item) {
            var productType = String(item && item.product_type ? item.product_type : '').trim().toLowerCase();
            return productType !== 'fixed';
        });
        if (firstCustomItem && Number(firstCustomItem.product_id) > 0) {
            params.set('product_id', firstCustomItem.product_id);
        }
        return staffUrl('staff/customizations.php?') + params.toString();
    }

    async function setOrderPrice(orderId) {
        var price = parseFloat(document.getElementById('omPriceInput').value);
        if (isNaN(price) || price <= 0) {
            alert('Please enter a valid price.');
            return;
        }

        const confirmed = await pfConfirm({
            title: 'Set Order Price',
            text: 'Are you sure you want to set the price to ₱' + price.toLocaleString() + ' and approve this order?',
            icon: '💰',
            iconBg: '#f0fdf4',
            iconColor: '#16a34a',
            confirmText: 'Yes, Set Price'
        });
        if (!confirmed) return;

        var fd = new FormData();
        fd.append('order_id', orderId);
        fd.append('price', price);
        fd.append('action', 'update_order_price');

        fetch(staffUrl('admin/job_orders_api.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                showStatusOverlay('', 'Price set successfully!');
                setTimeout(function() { openOrderModal(orderId); fetchUpdatedTable(); }, 1200);
            } else {
                alert(res.error || 'Failed to update price');
            }
        })
        .catch(function() { alert('Network error'); });
    }

    // renderOrderModal is defined after DOMContentLoaded since it
    // just builds HTML strings — safe to define here too:
    function renderOrderModal(d) {
        document.getElementById('omSubtitle').textContent = d.order_date;

        var isFixed = (d.items || []).some(function(item) { return item.product_type === 'fixed'; });

        var cancelBlock = '';
        if (d.status === 'Cancelled' && (d.cancelled_by || d.cancel_reason)) {
            cancelBlock = '<div style="margin-top:12px;padding:12px;background:#fef2f2;border:1px solid #fee2e2;border-radius:10px;">' +
                '<div style="font-weight:700;color:#ef4444;font-size:12px;margin-bottom:4px;">Cancellation Details</div>' +
                '<div style="font-size:12px;color:#b91c1c;"><b>By:</b> ' + esc(d.cancelled_by) +
                '<br><b>Reason:</b> ' + esc(d.cancel_reason) +
                (d.cancelled_at ? '<br><b>At:</b> ' + esc(d.cancelled_at) : '') + '</div></div>';
        }

        var notesBlock = d.notes ? '<div class="om-notes-section" style="margin-top:20px; padding:16px; border-radius:12px; border:1px solid #e5e7eb; background:#fff;">' +
            '<label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:8px;">Order Notes</label>' +
            '<div style="font-size:13px;color:#1f2937;line-height:1.5;background:#fefce8;padding:12px;border:1px solid #fef3c7;border-radius:8px;">' + esc(d.notes).replace(/\n/g,'<br>') + '</div>' +
            '</div>' : '';

        var payBlock = '';
        if (d.payment_proof && d.payment_proof !== 'null' && d.payment_proof !== 'undefined') {
            payBlock = '<div style="margin-top:20px; padding:16px; border-radius:12px; border:1px solid #e2e8f0; background:#f0fdf4;">' +
                '<label style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;display:block;margin-bottom:12px;">Payment Proof</label>' +
                '<a href="' + d.payment_proof + '" target="_blank" style="display:block;border-radius:10px;overflow:hidden;border:2px solid #bbf7d0;background:#fff;">' +
                '<img src="' + d.payment_proof + '" alt="Payment Proof" style="width:100%;height:auto;display:block;max-height:400px;object-fit:contain;"></a></div>';
        }

        // Use CSRF token from order data (already generated server-side)
        var csrf = d.csrf_token || '';

        var actionsHTML = '';
        var isViewOnlyStatus = ['Completed', 'Cancelled', 'Rejected'].includes(d.status);
        var verifyStatuses = ['To Verify', 'Pending Verification', 'Verify Pay'];
        var isVerifyStatus = verifyStatuses.includes(d.status);
        var canVerifyPayment = isVerifyStatus && d.payment_proof && d.payment_proof !== 'null' && d.payment_proof !== 'undefined';
        if (!isViewOnlyStatus) {
            if (isVerifyStatus) {
                if (canVerifyPayment) {
                    actionsHTML = '<div style="margin-top:28px; display:grid; gap:12px;">' +
                        '<button class="btn-primary" onclick="verifyPaymentProof(' + d.order_id + ', \'Approve\')" style="min-width:160px; background:#059669; color:white; border:none; padding:12px 16px; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px;">Approve</button>' +
                        '<button class="btn-primary" onclick="verifyPaymentProof(' + d.order_id + ', \'Reject\')" style="min-width:160px; background:#ef4444; border-color:#ef4444; color:white; border:none; padding:12px 16px; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px;">Reject</button>' +
                        '</div>';
                } else {
                    actionsHTML = '<div style="margin-top:20px; padding:16px; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc; color:#475569; font-size:13px;">This order is awaiting payment verification. No approval action is available until the customer uploads a payment proof.</div>';
                }
            } else if (d.order_source === 'pos' && d.total_raw <= 0) {
                actionsHTML = '<div style="margin-top:20px; padding:16px; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc;">' +
                    '<label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:12px;">Set Negotiated Price</label>' +
                    '<div style="position:relative; margin-bottom:16px;">' +
                        '<span style="position:absolute; left:12px; top:12px; font-weight:700; color:#94a3b8;">₱</span>' +
                        '<input type="number" id="omPriceInput" style="width:100%; padding:12px 12px 12px 28px; border:1px solid #cbd5e1; border-radius:10px; font-size:18px; font-weight:700; outline:none;" placeholder="0.00">' +
                    '</div>' +
                    '<button class="btn-primary" onclick="setOrderPrice(' + d.order_id + ')" style="width:100%; background:#06A1A1; color:white; border:none; padding:12px; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px;">Save Price</button>' +
                    '</div>';
            } else {
                actionsHTML = '<div style="margin-top:28px;">' +
                    '<button class="btn-primary" onclick="markOrderCompleted(' + d.order_id + ', \'' + csrf + '\')" style="width:100%; background:#06A1A1; color:white; border:none; padding:12px; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px;">Mark as Completed</button>' +
                    '</div>';
            }
        }

        // --- Start Building UI ---
        var contentHTML = '';

        // 1. Customer Profile Header (Avatar first style)
        contentHTML += '<div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid #f3f4f6;">' +
            '<div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#06A1A1,#047676);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:22px;flex-shrink:0;overflow:hidden;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,0.1);">' + 
              ((d.cust_profile_picture && d.cust_profile_picture !== "null" && d.cust_profile_picture !== "undefined") ? '<img src="' + getProfileImage(d.cust_profile_picture) + '" style="width:100%;height:100%;object-fit:cover;" onerror="this.src=\'' + staffUrl('public/assets/uploads/profiles/default.png') + '\'">' : esc(d.cust_initial || '?')) + 
            '</div>' +
            '<div>' +
                '<div style="font-size:16px;font-weight:700;color:#1f2937;">' + esc(d.cust_name) + '</div>' +
                '<div style="display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap;">' +
                    '<span style="font-size:12px;color:#6b7280;">' + esc(d.cust_phone) + '</span>' +
                '</div>' +
                (d.cust_address ? '<div style="font-size:12px;color:#6b7280;margin-top:6px;max-width:100%;word-break:break-word;">' + esc(d.cust_address) + '</div>' : '') +
            '</div>' +
        '</div>';

        // 2. Order Info Row
        contentHTML += '<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:14px; padding:16px; margin-bottom:20px;">' +
            '<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">' +
                '<div>' +
                    '<div style="font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;">Status</div>' +
                    '<div>' + statusBadge(d.status) + '</div>' +
                '</div>' +
                '<div>' +
                    '<div style="font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;">Grand Total</div>' +
                    '<div style="font-size:15px;font-weight:800;color:#111827;">₱ ' + parseFloat(d.total_raw || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</div>' +
                '</div>' +
                (d.payment_reference ? '<div><div style="font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;">Ref #</div><div style="font-size:13px;font-weight:700;color:#111827;">' + esc(d.payment_reference) + '</div></div>' : '') +
            '</div>' +
            cancelBlock +
        '</div>';

        // 3. Order Details Section
        var itemsHTML = '';
        (d.items || []).forEach(function(item) {
            var itemImg = item.product_image || item.design_url || staffUrl('public/assets/images/services/default.png');
            if (!itemImg || itemImg === 'null' || itemImg === 'undefined') {
                itemImg = staffUrl('public/assets/images/services/default.png');
            }
            if (!itemImg.startsWith(STAFF_BASE_PATH + '/') && !itemImg.startsWith('http') && !itemImg.startsWith('data:')) {
                itemImg = staffUrl(itemImg);
            }

            var specHTML = '';
            if (item.customization && Object.keys(item.customization).length) {
                var grid = '';
                Object.entries(item.customization).forEach(function(e2) {
                    var k = e2[0], v = e2[1];
                    if (!v || v === 'No' || v === 'None' || v === 'none' || k === 'branch_id') return;
                    var label = k.replace(/_/g, ' ');
                    grid += '<div style="padding:6px; background:#f9fafb; border:1px solid #e2e8f0; border-radius:6px;">' +
                        '<div style="font-size:9px; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-bottom:2px;">' + esc(label) + '</div>' +
                        '<div style="font-size:12px; font-weight:600; color:#1e293b;">' + esc(String(v)) + '</div>' +
                    '</div>';
                });
                if (grid) specHTML = '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-top:12px;">' + grid + '</div>';
            }

            itemsHTML += '<div style="padding:16px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:12px;">' +
                '<div style="display:flex; gap:16px; align-items:flex-start;">' +
                    '<div style="border-radius:10px; overflow:hidden; border:1px solid #eef2f6; flex-shrink:0; background:#f8fafc;">' +
                        '<img src="' + itemImg + '" style="width:70px; height:70px; object-fit:cover; display:block;">' +
                    '</div>' +
                    '<div style="flex:1;">' +
                        '<div style="font-size:14px; font-weight:700; color:#111827; margin-bottom:4px;">' + esc(item.product_name) + ' × ' + item.quantity + '</div>' +
                        '<div style="font-size:12px; color:#64748b;">₱' + parseFloat(item.unit_price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' each</div>' +
                    '</div>' +
                '</div>' +
                specHTML +
            '</div>';
        });

        var detailsHTML = '<div style="margin-bottom:20px; padding:16px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb;">' +
            '<label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:12px;">Order Details (Customer Specifications)</label>' +
            itemsHTML +
        '</div>';

        document.getElementById('omBody').innerHTML = contentHTML + detailsHTML + notesBlock + payBlock + actionsHTML;
    }

    // ── DOMContentLoaded: event listeners & auto-open ────
    document.addEventListener('DOMContentLoaded', function() {
        // Escape key closes modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeOrderModal();
        });

        // Revision form: combine reason fields
        var revForm = document.getElementById('revForm');
        if (revForm) {
            revForm.addEventListener('submit', function(e) {
                var submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    if (submitBtn.disabled) { e.preventDefault(); return false; }
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Sending...';
                    submitBtn.style.opacity = '0.7';
                }
                var sel = this.querySelector('select[name="revision_reason_select"]');
                var oth = this.querySelector('textarea[name="revision_reason_other"]');
                var finalReason = sel ? sel.value : '';
                if (finalReason === 'Other' && oth) finalReason = oth.value;
                var hidden = this.querySelector('input[name="revision_reason"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'revision_reason';
                    this.appendChild(hidden);
                }
                hidden.value = finalReason;
            });
        }

        // Auto-open modal if order_id is in URL
        var urlParams = new URLSearchParams(window.location.search);
        var orderId = urlParams.get('order_id');
        if (orderId) { openOrderModal(orderId); }
    });
    </script>
</head>
<body>

<div class="dashboard-container">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <header>
            <div>
                <h1 class="page-title">Walk-in Sales</h1>
                <p class="page-subtitle">View completed in-store product transactions.</p>
            </div>
        </header>

        <main x-data="ordersPage()" x-init="init()">
            <?php if (isset($_GET['success'])): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px;">
                    Order status updated successfully!
                </div>
            <?php endif; ?>

            <!-- Standardized KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Walk-in Sales</span>
                        <span class="kpi-value" id="totalOrdersCount"><?php echo number_format($total_count); ?></span>
                        <span class="kpi-sub">Completed product sales</span>
                    </span>
                </div>
                <div class="kpi-card blue">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Completed Sales</span>
                        <span class="kpi-value"><?php echo number_format($completed_count); ?></span>
                        <span class="kpi-sub">Paid and released</span>
                    </span>
                </div>
                <div class="kpi-card emerald">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Revenue</span>
                        <span class="kpi-value"><?php echo format_currency((float)$total_revenue); ?></span>
                        <span class="kpi-sub">Completed walk-in sales</span>
                    </span>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="card staff-orders-table-card overflow-visible">
                <div class="toolbar-container" style="display: flex !important; justify-content: space-between !important; align-items: center !important; flex-wrap: wrap !important; gap: 16px !important; width: 100% !important;">
                    <div class="toolbar-group toolbar-group--title" style="flex: 0 1 auto !important;">
                        <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0; white-space:nowrap;">Completed Walk-in Sales</h3>
                    </div>
                    <div class="toolbar-group toolbar-group--actions" style="display: flex !important; gap: 8px !important; margin-left: auto !important; flex: 0 1 auto !important; justify-content: flex-end !important;">

                            <!-- Sort Button -->
                            <div style="position:relative;">
                                <button class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'newest') }" @click="sortOpen = !sortOpen; filterOpen = false">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                                    Sort by
                                </button>
                                <div class="dropdown-panel sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                    <?php
                                    $sorts = [
                                        'newest' => 'Newest to Oldest',
                                        'oldest' => 'Oldest to Newest',
                                        'az'     => 'A to Z',
                                        'za'     => 'Z to A',
                                    ];
                                    foreach ($sorts as $key => $label): ?>
                                    <div class="sort-option" 
                                         :class="{ 'active': activeSort === '<?php echo $key; ?>' }"
                                         @click="applySortFilter('<?php echo $key; ?>')">
                                        <?php echo htmlspecialchars($label); ?>
                                        <svg x-show="activeSort === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Filter Button -->
                            <div style="position:relative;">
                                <button class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen; sortOpen = false">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    Filter
                                    <template x-if="hasActiveFilters">
                                        <span class="filter-badge"><?php echo $active_filter_badge_count; ?></span>
                                    </template>
                                </button>

                                <!-- Filter Panel -->
                                <div class="dropdown-panel filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                    <div class="filter-header">
                                        <span>Filter</span>
                                        <button type="button" class="filter-close-btn" @click="filterOpen = false" aria-label="Close filter">×</button>
                                    </div>
                                    
                                    <!-- Date Range -->
                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Date range</span>
                                            <button @click="resetFilterField(['date_from','date_to'])" class="filter-reset-link">Reset</button>
                                        </div>
                                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from_filter); ?>" @change="applyFilters()">
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to_filter); ?>" @change="applyFilters()">
                                        </div>
                                    </div>

                                    <!-- Keyword Search -->
                                    <input type="hidden" id="fp_status" value="<?php echo htmlspecialchars($status_filter); ?>">
                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Order code / Product name</span>
                                            <button @click="resetFilterField(['customer'])" class="filter-reset-link">Reset</button>
                                        </div>
                                        <input type="text" id="fp_customer" class="filter-input" placeholder="Order code or product name..." value="<?php echo htmlspecialchars($customer_filter); ?>" @change="applyFilters()">
                                    </div>

                                    <div class="filter-footer">
                                        <button class="filter-btn-reset" style="width:100%;" @click="applyFilters(true)">Reset all filters</button>
                                    </div>
                                </div>
                        </div>
                    </div>
                </div>

                <div class="toolbar-group toolbar-group--tabs" style="padding: 0 20px 12px;">
                    <div class="pf-custom-tabs" style="margin:0; padding-bottom:0; border-bottom:0; justify-content:flex-start; overflow-x: auto;">
                        <template x-for="(label, key) in statusTabs" :key="key">
                            <button type="button" 
                                    class="pill-tab" 
                                    :class="{ 'active': activeTab === key }"
                                    @click="switchStatusTab(key)">
                                <span x-text="label"></span>
                                <span class="tab-count" x-text="tabCounts[key] || 0"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="overflow-x-auto -mx-6 px-6" style="clear:both;">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th class="pl-6 pr-4 py-4 w-[16%] border-b border-gray-100">Order Code</th>
                                <th class="px-4 py-4 w-[16%] border-b border-gray-100">Product Name</th>
                                <th class="px-4 py-4 w-[14%] border-b border-gray-100">Customer</th>
                                <th class="px-4 py-4 w-[9%] border-b border-gray-100 text-center">Source</th>
                                <th class="px-4 py-4 w-[11%] border-b border-gray-100">Date</th>
                                <th class="px-4 py-4 w-[10%] border-b border-gray-100">Total</th>
                                <th class="px-4 py-4 w-[14%] border-b border-gray-100 text-center">Status</th>
                                <th class="px-4 py-4 w-[10%] border-b border-gray-100 text-center uppercase tracking-widest text-[10px]">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr class="staff-order-row" onclick="openOrderModal(<?php echo $order['order_id']; ?>)">
                                    <td class="pl-6 pr-4 py-4 relative">
                                        <div class="row-indicator"></div>
                                        <div class="order-info-cell">
                                            <div class="order-id-wrap">
                                                <span class="truncate-ellipsis" title="<?php echo htmlspecialchars($order['order_code']); ?>"><?php echo htmlspecialchars($order['order_code']); ?></span>
                                                <?php 
                                                $unread = get_unread_chat_count($order['order_id'], 'User');
                                                if ($unread > 0): 
                                                ?>
                                                    <span style="background: #ef4444; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; animation: pulse 2s infinite;" title="<?php echo $unread; ?> new messages from customer">
                                                        <?php echo $unread; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php $display_product_name = staff_orders_product_name($order); ?>
                                        <div class="table-text-main truncate-ellipsis" title="<?php echo htmlspecialchars($display_product_name); ?>">
                                            <?php echo htmlspecialchars(strlen($display_product_name) > 120 ? substr($display_product_name, 0, 120) . '...' : $display_product_name); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="table-text-main truncate-ellipsis" title="<?php echo htmlspecialchars($order['customer_name']); ?>">
                                            <?php echo htmlspecialchars($order['customer_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 status-col-cell">
                                        <?php if (($order['order_source'] ?? '') === 'pos'): ?>
                                            <span class="pf-pill source-badge-pill pos">Pos</span>
                                        <?php else: ?>
                                            <span class="pf-pill source-badge-pill online">Online</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="table-text-sub truncate-ellipsis" title="<?php echo htmlspecialchars(format_date($order['order_date'])); ?>">
                                            <?php echo format_date($order['order_date']); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="table-text-main truncate-ellipsis" title="<?php echo htmlspecialchars(format_currency($order['total_amount'])); ?>">
                                            <?php echo format_currency($order['total_amount']); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 status-col-cell">
                                        <?php $display_order_status2 = staff_orders_display_status((string)$order['status']); ?>
                                        <div class="status-col-inner">
                                            <?php echo staff_orders_status_pill_html($display_order_status2); ?>
                                            <?php if (($order['design_status'] ?? '') === 'Revision Submitted'): ?>
                                                <div>
                                                    <?php echo staff_orders_status_pill_html('Revision Submitted'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 action-col-cell">
                                        <div class="action-cell">
                                            <button onclick="event.stopPropagation(); openOrderModal(<?php echo $order['order_id']; ?>)" 
                                                    class="table-action-btn alt">
                                                View
                                            </button>
                                            <a href="<?php echo BASE_PATH; ?>/staff/chats.php?order_id=<?php echo $order['order_id']; ?>"
                                               onclick="event.stopPropagation();"
                                               class="table-action-btn">
                                                Message
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="pagination-wrapper" style="padding: 24px 0; border-top: 1px solid #f3f4f6;">
                <?php echo render_pagination($current_page, $total_pages, $active_filters); ?>
            </div>
        </main>
    </div>
</div>

<!-- ══════════════════════════════════════════
     ORDER DETAIL MODAL
═══════════════════════════════════════════ -->
<div id="orderModal" role="dialog" aria-modal="true" aria-labelledby="omTitle">
    <div class="om-backdrop" onclick="closeOrderModal()"></div>
    <div class="om-panel">
        <div class="om-header">
            <div>
                <div class="om-title" id="omTitle">Order Details</div>
                <div class="om-subtitle" id="omSubtitle">Loading…</div>
            </div>
            <button class="om-close" onclick="closeOrderModal()" aria-label="Close">✕</button>
        </div>
        <div class="om-body" id="omBody">
            <!-- Loader -->
            <div class="om-loader">
                <div class="om-spinner"></div>
                <div style="color:#94a3b8; font-size:14px;">Fetching order details…</div>
            </div>
        </div>

        <!-- Status Overlay (Centered Toast) -->
        <div id="omStatusOverlay" class="om-status-overlay">
            <div class="om-status-toast">
                <div id="omStatusIcon" class="om-status-toast-icon">✅</div>
                <div id="omStatusMsg" class="om-status-toast-msg">Status Updated!</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     REVISION MODAL
═══════════════════════════════════════════ -->
<style>
    #revisionModal {
        position: fixed; inset: 0; z-index: 10001;
        display: flex; align-items: center; justify-content: center;
        padding: 16px; opacity: 0; pointer-events: none;
        transition: opacity 0.2s ease;
    }
    #revisionModal.open { opacity: 1; pointer-events: all; }
    .rev-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
    .rev-panel {
        position: relative; z-index: 1; background: #fff; border-radius: 12px;
        width: 100%; max-width: 450px; padding: 24px;
        box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        transform: translateY(8px); transition: transform 0.2s ease, opacity 0.2s ease;
    }
    #revisionModal.open .rev-panel { transform: translateY(0); }
    #paymentRejectionModal.open { opacity: 1 !important; pointer-events: all !important; }
    #paymentRejectionModal.open .rev-panel { transform: translateY(0); }
    .rev-title { font-size: 18px; font-weight: 700; color: #1f2937; margin-bottom: 8px; }
    .rev-sub { font-size: 13px; color: #6b7280; margin-bottom: 20px; }
</style>

<div id="revisionModal" role="dialog" aria-modal="true">
    <div class="rev-backdrop" onclick="closeRevisionModal()"></div>
    <div class="rev-panel">
        <div class="rev-title">Request Design Revision</div>
        <p class="rev-sub">Please select a reason for the revision request. This will be sent to the customer.</p>
        
        <form id="revForm" action="request_revision_process.php" method="POST">
            <input type="hidden" name="order_id" id="revOrderId">
            <input type="hidden" name="csrf_token" id="revCsrfToken">
            
            <div style="margin-bottom: 20px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px;">Reason for Revision</label>
                <select name="revision_reason_select" class="input-field" required onchange="handleReasonChange(this)">
                    <option value="" disabled selected>Select a reason...</option>
                    <option value="Low image quality / Blurry file">Low image quality / Blurry file</option>
                    <option value="Incorrect dimensions / Size issue">Incorrect dimensions / Size issue</option>
                    <option value="Wrong file format">Wrong file format</option>
                    <option value="Design not print-ready">Design not print-ready</option>
                    <option value="Incomplete details">Incomplete details</option>
                    <option value="Copyright or restricted content">Copyright or restricted content</option>
                    <option value="Other">Other (Please specify)</option>
                </select>
            </div>

            <div id="revOtherWrapper" style="display:none; margin-bottom:20px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px;">Specify Other Reason</label>
                <textarea name="revision_reason_other" id="revOtherInput" class="input-field" style="height:80px;"></textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <button type="button" class="btn-secondary" onclick="closeRevisionModal()">Cancel</button>
                <button type="submit" class="btn-primary">Send Request</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════
     PAYMENT REJECTION MODAL
═══════════════════════════════════════════ -->
<div id="paymentRejectionModal" role="dialog" aria-modal="true" style="position: fixed; inset: 0; z-index: 10002; display: flex; align-items: center; justify-content: center; padding: 16px; opacity: 0; pointer-events: none; transition: opacity 0.2s ease;">
    <div class="rev-backdrop" onclick="closePaymentRejectionModal()" style="position: absolute; inset: 0;"></div>
    <div class="rev-panel" style="position: relative; z-index: 1; width: 100%; max-width: 450px;">
        <div class="rev-title">Reject Payment Proof</div>
        <p class="rev-sub">Please select a reason for rejecting the payment proof. The customer will be notified.</p>
        
        <div id="payRejectionForm">
            <input type="hidden" id="payRejOrderId">
            
            <div style="margin-bottom: 20px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px;">Reason for Rejection</label>
                <select id="payRejReasonSelect" class="input-field" style="width:100%; padding:10px; border-radius:8px; border:1px solid #e2e8f0;" onchange="handlePayRejReasonChange(this)">
                    <option value="" disabled selected>Select a reason...</option>
                    <option value="Invalid payment proof">Invalid payment proof</option>
                    <option value="Blurry image">Blurry image</option>
                    <option value="Wrong amount">Wrong amount</option>
                    <option value="Duplicate payment">Duplicate payment</option>
                    <option value="Other">Other (Please specify)</option>
                </select>
            </div>

            <div id="payRejOtherWrapper" style="display:none; margin-bottom:20px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px;">Specify Other Reason</label>
                <textarea id="payRejOtherInput" class="input-field" style="width:100%; height:80px; padding:10px; border-radius:8px; border:1px solid #e2e8f0;"></textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <button type="button" class="btn-secondary" onclick="closePaymentRejectionModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="submitPaymentRejection()" style="background:#ef4444; border-color:#ef4444;">Reject Payment</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     CUSTOM CONFIRMATION MODAL
═══════════════════════════════════════════ -->
<style>
    #pfConfirmModal {
        position: fixed; inset: 0; z-index: 20000;
        display: none; align-items: center; justify-content: center;
        padding: 24px;
    }
    #pfConfirmModal.open { display: flex; }
    .pf-confirm-backdrop { position: absolute; inset: 0; background: transparent; backdrop-filter: none; }
    .pf-confirm-panel {
        position: relative; z-index: 1; background: #fff; border-radius: 24px;
        width: 100%; max-width: 400px; padding: 32px; text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        transform: scale(0.95); transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    #pfConfirmModal.open .pf-confirm-panel { transform: scale(1); }
    .pf-confirm-icon {
        width: 72px; height: 72px; background: #f0fdf4; color: #10b981;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        margin: 0 auto 24px; font-size: 32px; border: 4px solid #fff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }
    .pf-confirm-title { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 12px; }
    .pf-confirm-text { font-size: 15px; color: #64748b; line-height: 1.6; margin-bottom: 32px; }
</style>

<div id="pfConfirmModal" role="dialog" aria-modal="true">
    <div class="pf-confirm-backdrop"></div>
    <div class="pf-confirm-panel">
        <div id="pfConfirmIcon" class="pf-confirm-icon">✓</div>
        <div id="pfConfirmTitle" class="pf-confirm-title">Are you sure?</div>
        <p id="pfConfirmText" class="pf-confirm-text">Please confirm you want to proceed with this action.</p>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <button id="pfConfirmBtnCancel" class="btn-secondary" style="padding: 12px; border-radius: 12px; font-weight: 700;">Cancel</button>
            <button id="pfConfirmBtnConfirm" class="btn-primary" style="padding: 12px; border-radius: 12px; font-weight: 700; background: #06A1A1;">Proceed</button>
        </div>
    </div>
</div>

<script>
/**
 * Custom Promise-based Confirmation Modal
 */
function pfConfirm(options) {
    return new Promise((resolve) => {
        const modal = document.getElementById('pfConfirmModal');
        const title = document.getElementById('pfConfirmTitle');
        const text = document.getElementById('pfConfirmText');
        const icon = document.getElementById('pfConfirmIcon');
        const confirmBtn = document.getElementById('pfConfirmBtnConfirm');
        const cancelBtn = document.getElementById('pfConfirmBtnCancel');

        // Setup
        title.textContent = options.title || 'Are you sure?';
        text.textContent = options.text || 'Do you want to continue?';
        icon.textContent = options.icon || '✓';
        icon.style.background = options.iconBg || '#f0fdf4';
        icon.style.color = options.iconColor || '#10b981';
        
        confirmBtn.textContent = options.confirmText || 'Proceed';
        confirmBtn.style.background = options.confirmColor || '#06A1A1';
        confirmBtn.style.borderColor = options.confirmColor || '#06A1A1';
        
        cancelBtn.textContent = options.cancelText || 'Cancel';

        modal.classList.add('open');

        // Cleanup
        const done = (val) => {
            modal.classList.remove('open');
            confirmBtn.onclick = null;
            cancelBtn.onclick = null;
            resolve(val);
        };

        confirmBtn.onclick = () => done(true);
        cancelBtn.onclick = () => done(false);
    });
}
</script>

</body>
</html>
