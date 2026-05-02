<?php
/**
 * Admin Orders Management
 * PrintFlow - Printing Shop PWA  
 * Full CRUD for orders with status updates, filtering, and search (branch-aware)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();

// ── Branch Context (operational page) ─────────────────
$branchCtx = init_branch_context(false); // analytics-style — allow All
$branchId  = $branchCtx['selected_branch_id'];

// Get filter parameters
$status_filter  = trim((string)($_GET['status'] ?? ''));
$search         = $_GET['search']   ?? '';
$date_from      = $_GET['date_from'] ?? '';
$date_to        = $_GET['date_to']   ?? '';
$sort_by        = $_GET['sort']      ?? 'newest';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

function admin_order_status_groups(): array {
    return [
        'Pending' => ['Pending', 'Pending Review', 'Pending Approval', 'To Pay', 'To Verify', 'Downpayment Submitted'],
        'Ready for Pickup' => ['Ready for Pickup', 'Processing', 'In Production', 'Printing', 'Approved Design'],
        'Completed' => ['Completed'],
        'Cancelled' => ['Cancelled'],
    ];
}

function admin_resolve_status_filter(string $statusFilter): string {
    $statusFilter = trim($statusFilter);
    if ($statusFilter === '') {
        return '';
    }

    $normalized = strtolower($statusFilter);
    return match ($normalized) {
        'to verify', 'pending', 'pending orders' => 'Pending',
        'to pick up', 'to pickup', 'ready for pickup', 'processing' => 'Ready for Pickup',
        'completed' => 'Completed',
        'cancelled', 'canceled' => 'Cancelled',
        default => '',
    };
}

$status_filter = admin_resolve_status_filter($status_filter);

// Build query (always join branches)
$sql = "SELECT o.*, CONCAT(c.first_name, ' ', c.last_name) as customer_name, c.email as customer_email, b.branch_name,
               GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') as order_sku,
               GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as product_names
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id 
        LEFT JOIN branches b ON o.branch_id = b.id
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE 1=1
          AND o.order_type = 'product'";
$params = [];
$types = '';
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

// ── Branch filter (all = non-archived branches only) ──────────────────────────────────
[$branchListFrag, $branchListTypes, $branchListParams] = branch_where_parts('o', $branchId);
$sql .= $branchListFrag;
if ($branchListTypes !== '') {
    $types .= $branchListTypes;
}
foreach ($branchListParams as $p) {
    $params[] = $p;
}

if ($status_filter !== '') {
    $statusGroups = admin_order_status_groups();
    $allowedStatuses = $statusGroups[$status_filter] ?? [];
    if (!empty($allowedStatuses)) {
        $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
        $sql .= " AND o.status IN ({$placeholders})";
        foreach ($allowedStatuses as $allowedStatus) {
            $params[] = $allowedStatus;
            $types .= 's';
        }
    }
}

if (!empty($date_from)) {
    $sql .= " AND DATE(o.order_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $sql .= " AND DATE(o.order_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($search)) {
    $search_term = '%' . $search . '%';
    $sql .= " AND (o.order_id LIKE ? OR {$order_code_search_sql} LIKE ? OR p.sku LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR o.notes LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sssssss';
}

// Count total results - wrap the grouped query as subquery
$count_sql = "SELECT COUNT(*) as total FROM (
    SELECT o.order_id
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.customer_id 
    LEFT JOIN branches b ON o.branch_id = b.id
    LEFT JOIN order_items oi ON o.order_id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE 1=1
      AND o.order_type = 'product'";

if ($branchId !== 'all') {
    $count_sql .= " AND o.branch_id = " . (int)$branchId;
} else {
    $count_sql .= " AND o.branch_id IN (SELECT id FROM branches WHERE status != 'Archived')";
}

if ($status_filter !== '') {
    $statusGroups = admin_order_status_groups();
    $allowedStatuses = $statusGroups[$status_filter] ?? [];
    if (!empty($allowedStatuses)) {
        $escapedStatuses = array_map(
            static fn($s) => "'" . $conn->real_escape_string($s) . "'",
            $allowedStatuses
        );
        $count_sql .= " AND o.status IN (" . implode(',', $escapedStatuses) . ")";
    }
}

if (!empty($date_from)) {
    $count_sql .= " AND DATE(o.order_date) >= '" . $conn->real_escape_string($date_from) . "'";
}

if (!empty($date_to)) {
    $count_sql .= " AND DATE(o.order_date) <= '" . $conn->real_escape_string($date_to) . "'";
}

if (!empty($search)) {
    $search_term = $conn->real_escape_string($search);
    $count_sql .= " AND (o.order_id LIKE '%{$search_term}%' OR {$order_code_search_sql} LIKE '%{$search_term}%' OR p.sku LIKE '%{$search_term}%' OR c.first_name LIKE '%{$search_term}%' OR c.last_name LIKE '%{$search_term}%' OR CONCAT(c.first_name, ' ', c.last_name) LIKE '%{$search_term}%' OR o.notes LIKE '%{$search_term}%')";
}

$count_sql .= " GROUP BY o.order_id
) as count_wrap";

$total_orders = db_query($count_sql)[0]['total'];
$total_pages = max(1, ceil($total_orders / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sort_clause = match($sort_by) {
    'oldest'        => " ORDER BY o.order_date ASC",
    'az'            => " ORDER BY customer_name ASC",
    'za'            => " ORDER BY customer_name DESC",
    default         => " ORDER BY o.order_date DESC"
};
$sql .= " GROUP BY o.order_id" . $sort_clause . " LIMIT $per_page OFFSET $offset";

$orders = db_query($sql, $types, $params);

// Get statistics (branch-aware)
[$bSqlFrag, $bT, $bP] = branch_where_parts('o', $branchId);

$total_count      = db_query("SELECT COUNT(*) as count FROM orders o WHERE o.order_type = 'product' {$bSqlFrag}", $bT ?: null, $bP ?: null)[0]['count'] ?? 0;
$pending_count    = db_query("SELECT COUNT(*) as count FROM orders o WHERE o.order_type = 'product' AND o.status IN ('Pending', 'Pending Review', 'Pending Approval', 'To Pay', 'To Verify', 'Downpayment Submitted') {$bSqlFrag}", $bT ?: null, $bP ?: null)[0]['count'] ?? 0;
$ready_count      = db_query("SELECT COUNT(*) as count FROM orders o WHERE o.order_type = 'product' AND o.status IN ('Ready for Pickup', 'Processing', 'In Production', 'Printing', 'Approved Design') {$bSqlFrag}", $bT ?: null, $bP ?: null)[0]['count'] ?? 0;
$completed_count  = db_query("SELECT COUNT(*) as count FROM orders o WHERE o.order_type = 'product' AND o.status = 'Completed' {$bSqlFrag}", $bT ?: null, $bP ?: null)[0]['count'] ?? 0;

function admin_display_status(string $status): string {
    $toVerifyStatuses = ['Pending', 'Pending Review', 'Pending Approval', 'To Pay', 'To Verify', 'Downpayment Submitted'];
    if (in_array($status, $toVerifyStatuses, true)) {
        return 'TO VERIFY';
    }
    if (in_array($status, ['Ready for Pickup', 'Processing', 'In Production', 'Printing', 'Approved Design'], true)) {
        return 'TO PICK UP';
    }
    if ($status === 'Completed') {
        return 'COMPLETED';
    }
    if ($status === 'Cancelled') {
        return 'CANCELLED';
    }
    return strtoupper($status);
}

function admin_status_badge_style(string $displayStatus): string {
    return match ($displayStatus) {
        'TO VERIFY' => 'background:#fef3c7;color:#92400e;',
        'TO PICK UP' => 'background:#dcfce7;color:#15803d;',
        'COMPLETED' => 'background:#dcfce7;color:#166534;',
        'CANCELLED' => 'background:#fecaca;color:#b91c1c;',
        default => 'background:#f3f4f6;color:#374151;',
    };
}

$page_title = 'Orders Management - Admin';

// AJAX Partial Response
if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <table class="orders-table">
        <thead>
            <tr style="border-bottom: 1px solid #e5e7eb;">
                <th style="width:12%;white-space:nowrap;">Order Code</th>
                <th style="text-align:left;width:<?php echo $branchId === 'all' ? '20%' : '18%'; ?>;">Customer</th>
                <th style="text-align:left;width:<?php echo $branchId === 'all' ? '20%' : '18%'; ?>;">Product</th>
                <th style="width:<?php echo $branchId === 'all' ? '14%' : '12%'; ?>;">Date</th>
                <?php if ($branchId !== 'all'): ?>
                <th style="width:14%;">Branch</th>
                <?php endif; ?>
                <th style="width:<?php echo $branchId === 'all' ? '14%' : '12%'; ?>;">Amount</th>
                <th style="width:<?php echo $branchId === 'all' ? '14%' : '12%'; ?>;">Status</th>
                <th style="width:12%; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody id="ordersTableBody">
            <?php if (empty($orders)): ?>
                <tr id="emptyOrdersRow">
                    <td colspan="<?php echo $branchId === 'all' ? '7' : '8'; ?>" style="padding:40px; text-align:center; color:#9ca3af; font-size:14px; cursor:default;">
                        <?php echo $search ? 'No orders found matching "' . htmlspecialchars($search) . '"' : 'No orders found'; ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr id="emptyOrdersRow" style="display:none;">
                    <td colspan="<?php echo $branchId === 'all' ? '7' : '8'; ?>" style="padding:40px; text-align:center; color:#9ca3af; font-size:14px; cursor:default;">No orders found</td>
                </tr>
                <?php foreach ($orders as $order): ?>
                    <tr onclick="openOrderModal(<?php echo $order['order_id']; ?>)" title="Click to view Order #<?php echo $order['order_id']; ?>" style="border-bottom: 1px solid #f3f4f6;">
                        <?php $order_code = $order['order_sku'] ? $order['order_sku'] . '-' . $order['order_id'] : 'ORD-' . $order['order_id']; ?>
                        <td class="order-code-cell">
                            <span class="cell-ellipsis order-code-text" title="<?php echo htmlspecialchars($order_code); ?>"><?php echo htmlspecialchars($order_code); ?></span>
                        </td>
                        <td>
                            <div class="cell-ellipsis" style="color:#1f2937; max-width:160px;" title="<?php echo htmlspecialchars($order['customer_name']); ?>"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                            <div class="cell-ellipsis" style="font-size:11px; color:#9ca3af; max-width:160px;" title="<?php echo htmlspecialchars($order['customer_email']); ?>"><?php echo htmlspecialchars($order['customer_email']); ?></div>
                        </td>
                        <td>
                            <?php $product_names = trim((string)($order['product_names'] ?? '')); ?>
                            <div class="cell-ellipsis" style="color:#1f2937; max-width:180px;" title="<?php echo htmlspecialchars($product_names ?: '—'); ?>"><?php echo htmlspecialchars($product_names ?: '—'); ?></div>
                        </td>
                        <td style="color:#6b7280; font-size: 12px;"><?php echo format_date($order['order_date']); ?></td>
                        <?php if ($branchId !== 'all'): ?>
                        <td><?php
                            echo get_branch_badge_html(
                                (int)($order['branch_id'] ?? 0),
                                $order['branch_name'] ?? 'Main'
                            );
                        ?></td>
                        <?php endif; ?>
                        <td style="color:#1f2937;">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                        <td>
                            <?php
                                $display_status = admin_display_status((string)($order['status'] ?? ''));
                                $sc = admin_status_badge_style($display_status);
                            ?>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $sc; ?>" class="cell-ellipsis" title="<?php echo htmlspecialchars($display_status); ?>"><?php echo htmlspecialchars($display_status); ?></span>
                        </td>
                        <td style="text-align:right;">
                            <button 
                                onclick="event.stopPropagation(); openOrderModal(<?php echo $order['order_id']; ?>)"
                                class="btn-action blue"
                            >View</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();

    ob_start();
    $pagination_params = array_filter(['search'=>$search, 'status'=>$status_filter, 'date_from'=>$date_from, 'date_to'=>$date_to, 'sort'=>$sort_by], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $pagination_params); 
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success'    => true,
        'table'      => $table_html,
        'pagination' => $pagination_html,
        'count'      => number_format($total_orders),
        'badge'      => count(array_filter([$status_filter, $search, $date_from, $date_to], function($v) { return $v !== null && $v !== ''; }))
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-cache-control" content="no-cache">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>
    <style>
        /* KPI Row - matches reports page */
        .kpi-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px; align-items:start; }
        @media (max-width:768px) { .kpi-row { grid-template-columns:repeat(2, 1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px 16px; position:relative; overflow:hidden; min-height:0; box-sizing:border-box; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.blue::before { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-value { margin:0; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }
        /* Modal */
        [x-cloak] { display: none !important; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; padding:16px; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:720px; max-height:calc(100vh - 32px); display:flex; flex-direction:column; position:relative; overflow:hidden; }
        .modal-content { overflow-y:auto; flex:1; }
        
        /* Action Button Style */
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-action.blue {
            border-color: #3b82f6;
            color: #3b82f6;
        }
        .btn-action.blue:hover {
            background: #3b82f6;
            color: white;
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
        .filter-panel-header {
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
        .filter-section-label {
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
            transition: border-color 0.15s;
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
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 12px;
            color: #1f2937;
            box-sizing: border-box;
        }
        .filter-search-input:focus { outline: none; border-color: #0d9488; }
        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }
        .filter-btn-reset {
            flex: 1;
            height: 36px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }
        .filter-btn-reset:hover { background: #f9fafb; }
        .filter-btn-apply {
            flex: 1;
            height: 36px;
            border: none;
            background: #0d9488;
            color: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .filter-btn-apply:hover { background: #0f766e; }

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
        .sort-option.selected { color: #0d9488; font-weight: 600; background: #f0fdfa; }
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

        /* ── Table improvements ─── */
        .orders-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .orders-table th {
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }
        .orders-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151;
        }
        .orders-table tbody tr {
            cursor: pointer;
            transition: background 0.1s;
        }
        .orders-table tbody tr:hover { background: #f9fafb; }
        .orders-table tbody tr:last-child td { border-bottom: none; }
        .cell-ellipsis {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .order-code-cell {
            color: #1f2937;
            max-width: 0;
        }
        .order-code-text {
            display: block;
            max-width: 100%;
        }

        /* Pagination Styling */
        #ordersPagination nav {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 20px;
        }
        #ordersPagination nav a, 
        #ordersPagination nav span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #374151;
            transition: all 0.2s;
        }
        #ordersPagination nav a:hover {
            border-color: #0d9488;
            color: #0d9488;
            background: #f0fdfa;
        }
        #ordersPagination nav .active,
        #ordersPagination nav span[aria-current="page"] {
            background: #0d9488 !important;
            color: #fff !important;
            border-color: #0d9488 !important;
        }

        .mobile-header { display:none; }
        @media (max-width:768px) {
            .mobile-header { display:flex;position:fixed;top:0;left:0;right:0;height:60px;background:#fff;z-index:60;padding:0 20px;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e7eb; }
        }

        .detail-row { display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px; }
        .detail-block { flex:1;min-width:140px;background:#f9fafb;border-radius:8px;padding:12px 14px; }
        .detail-block label { font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px; }
        .detail-block span  { font-size:13px;font-weight:400;color:#1f2937; }

        /* Transaction History Tabs */
        .tab-btn { padding: 8px 16px; font-size: 13px; font-weight: 500; border-radius: 8px; transition: all 0.2s; cursor: pointer; border: 1px solid transparent; }
        .tab-btn.active { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
        .tab-btn:not(.active) { color: #6b7280; }
        .history-item { padding: 10px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; }
        .history-item:last-child { border-bottom: none; }

        @media (max-width: 768px) {
            main[x-data="ordersPage()"] .modal-overlay {
                align-items: flex-start !important;
                padding: 10px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            main[x-data="ordersPage()"] .modal-panel {
                width: 100% !important;
                max-width: calc(100vw - 20px) !important;
                max-height: calc(100dvh - 76px) !important;
                margin: 56px auto 16px !important;
                border-radius: 8px !important;
            }
            main[x-data="ordersPage()"] .modal-content {
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            main[x-data="ordersPage()"] .modal-content > div {
                padding-left: 16px !important;
                padding-right: 16px !important;
            }
            main[x-data="ordersPage()"] .modal-content > div:first-child {
                align-items: flex-start !important;
                gap: 12px !important;
            }
            main[x-data="ordersPage()"] .modal-content > div:first-child > div:first-child {
                min-width: 0 !important;
                flex: 1 1 auto !important;
            }
            main[x-data="ordersPage()"] .modal-content > div:first-child h3 {
                font-size: 16px !important;
                line-height: 1.25 !important;
                overflow-wrap: anywhere !important;
            }
            main[x-data="ordersPage()"] .modal-content h3,
            main[x-data="ordersPage()"] .modal-content h4,
            main[x-data="ordersPage()"] .modal-content p,
            main[x-data="ordersPage()"] .modal-content div,
            main[x-data="ordersPage()"] .modal-content span,
            main[x-data="ordersPage()"] .modal-content td {
                min-width: 0 !important;
                overflow-wrap: anywhere !important;
                word-break: break-word !important;
                white-space: normal !important;
            }
            main[x-data="ordersPage()"] .modal-content > div[style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
                padding-top: 16px !important;
                padding-bottom: 16px !important;
            }
            main[x-data="ordersPage()"] .modal-content > div[style*="padding:0 24px"] {
                padding-left: 16px !important;
                padding-right: 16px !important;
            }
            main[x-data="ordersPage()"] .modal-content [style*="border:1px"][style*="overflow:hidden"] {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            main[x-data="ordersPage()"] .modal-content table {
                min-width: 640px !important;
            }
            main[x-data="ordersPage()"] .modal-content [style*="max-width:180px"],
            main[x-data="ordersPage()"] .modal-content [style*="max-width:150px"],
            main[x-data="ordersPage()"] .modal-content [style*="max-width:120px"] {
                max-width: 180px !important;
                white-space: normal !important;
                text-overflow: clip !important;
            }
            main[x-data="ordersPage()"] .modal-content [style*="background:#fff"][style*="box-shadow"] {
                max-width: 100% !important;
            }
            main[x-data="ordersPage()"] .modal-content [style*="display:grid"][style*="grid-template-columns:1fr 1fr"] {
                grid-template-columns: 1fr !important;
            }
            main[x-data="ordersPage()"] .modal-content [style*="display:flex"][style*="justify-content:flex-end"] {
                flex-wrap: wrap !important;
            }
            main[x-data="ordersPage()"] .modal-content > div:last-child {
                justify-content: stretch !important;
            }
            main[x-data="ordersPage()"] .modal-content > div:last-child .btn-secondary {
                width: 100% !important;
                justify-content: center !important;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <!-- Main Content -->
    <div class="main-content">
        <script>
            var searchDebounceTimer = null;

            function buildFilterURL(overrides = {}, isAjax = false) {
                const params = new URLSearchParams(window.location.search);
                const fields = {
                    status:    () => document.getElementById('fp_status')?.value    || '',
                    payment:   () => document.getElementById('fp_payment')?.value   || '',
                    search:    () => document.getElementById('fp_search')?.value    || '',
                    date_from: () => document.getElementById('fp_date_from')?.value || '',
                    date_to:   () => document.getElementById('fp_date_to')?.value   || '',
                };
                for (const [key, getter] of Object.entries(fields)) {
                    var val = (overrides[key] !== undefined) ? overrides[key] : getter();
                    if (val) params.set(key, val);
                    else params.delete(key);
                }
                if (overrides.sort !== undefined) {
                    if (overrides.sort && overrides.sort !== 'newest') params.set('sort', overrides.sort);
                    else params.delete('sort');
                }
                if (isAjax) params.set('ajax', '1');
                else params.delete('ajax');
                params.delete('page');
                return window.location.pathname + '?' + params.toString();
            }

            async function fetchUpdatedTable(overrides = {}) {
                const url = buildFilterURL(overrides, true);
                try {
                    const resp = await fetch(url);
                    const data = await resp.json();
                    if (data.success) {
                        const tc = document.getElementById('ordersTableContainer');
                        if (tc) {
                            tc.innerHTML = data.table;
                            if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                                Alpine.initTree(tc);
                            }
                        }
                        const pc = document.getElementById('ordersPagination');
                        if (pc) pc.innerHTML = data.pagination;
                        const bc = document.getElementById('filterBadgeContainer');
                        if (bc) bc.innerHTML = data.badge > 0 ? '<span class="filter-badge">' + data.badge + '</span>' : '';
                        
                        window.dispatchEvent(new CustomEvent('filter-badge-update', { detail: { badge: data.badge } }));
                        const displayUrl = buildFilterURL(overrides, false);
                        window.history.replaceState({ path: displayUrl }, '', displayUrl);
                    }
                } catch (e) { console.error('Error updating table:', e); }
            }

            function applyFilters(resetAll = false) {
                if (resetAll) {
                    const base = window.location.pathname;
                    const params = new URLSearchParams(window.location.search);
                    const branch = params.get('branch_id');
                    const target = base + (branch ? '?branch_id=' + encodeURIComponent(branch) : '');
                    window.location.href = target;
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

            function filterPanel() {
                console.log('[orders] filterPanel() called');
                const data = {
                    filterOpen: false,
                    sortOpen:   false,
                    activeSort: '<?php echo $sort_by; ?>',
                    hasActiveFilters: <?php echo count(array_filter([$status_filter, $search, $date_from, $date_to])) > 0 ? 'true' : 'false'; ?>
                };
                console.log('[orders] filterPanel data:', data);
                return data;
            }

            function orderModal() {
                console.log('[orders] orderModal() called');
                const data = {
                    showModal: false,
                    loading: false,
                    errorMsg: '',
                    order: null,
                    items: [],
                    selectedStatus: 'Pending',
                    updatingStatus: false,
                    statusUpdateMsg: '',
                    statusUpdateError: false,

                    startTarpEdit(item) {
                        item.editingTarp = true;
                        if (item.tempWidth > 0 && item.availableRolls.length === 0) { this.fetchRolls(item); }
                    },

                    fetchRolls(item) {
                        if (!item.tempWidth || item.tempWidth <= 0) return;
                        fetch('<?php echo $base_path; ?>/admin/api_tarp_rolls.php?action=list_available&width=' + item.tempWidth)
                            .then(r => r.json())
                            .then(data => { if (data.success) item.availableRolls = data.rolls; });
                    },

                    async saveTarpSpecs(item) {
                        if (!item.tempWidth || !item.tempHeight || !item.tempRollId) { alert('Please fill all tarpaulin specifications.'); return; }
                        item.savingTarp = true;
                        try {
                            const resp = await fetch('<?php echo $base_path; ?>/admin/api_save_tarp_specs.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    order_item_id: item.order_item_id,
                                    roll_id: item.tempRollId,
                                    width_ft: item.tempWidth,
                                    height_ft: item.tempHeight,
                                    csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                                })
                            });
                            const data = await resp.json();
                            if (data.success) {
                                item.tarp_details = {
                                    width_ft: item.tempWidth,
                                    height_ft: item.tempHeight,
                                    roll_id: item.tempRollId,
                                    roll_code: item.availableRolls.find(r => r.id == item.tempRollId)?.roll_code || 'Assigned'
                                };
                                item.editingTarp = false;
                            } else { alert(data.error || 'Failed to save.'); }
                        } catch (e) { alert('Network error.'); }
                        item.savingTarp = false;
                    },

                    async updateStatus() {
                        if (!this.order) return;
                        this.updatingStatus = true;
                        this.statusUpdateMsg = '';
                        try {
                            const resp = await fetch('<?php echo $base_path; ?>/admin/api_update_order_status.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    order_id: this.order.order_id,
                                    status: this.selectedStatus,
                                    csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                                })
                            });
                            const data = await resp.json();
                            if (data.success) {
                                this.statusUpdateMsg = data.message;
                                this.statusUpdateError = false;
                                this.order.status = this.selectedStatus;
                                setTimeout(() => location.reload(), 1200);
                            } else { this.statusUpdateMsg = data.error || 'Update failed.'; this.statusUpdateError = true; }
                        } catch (e) { this.statusUpdateMsg = 'Network error.'; this.statusUpdateError = true; }
                        this.updatingStatus = false;
                    },

                    statusBadge(status, type) {
                        const normalizeOrderStatus = (raw) => {
                            const s = String(raw || '');
                            if (['Pending', 'Pending Review', 'Pending Approval', 'To Pay', 'To Verify', 'Downpayment Submitted'].includes(s)) {
                                return 'TO VERIFY';
                            }
                            if (['Ready for Pickup', 'Processing', 'In Production', 'Printing', 'Approved Design'].includes(s)) {
                                return 'TO PICK UP';
                            }
                            if (s === 'Completed') return 'COMPLETED';
                            if (s === 'Cancelled') return 'CANCELLED';
                            return s || 'N/A';
                        };
                        const colors = {
                            order: {
                                'TO VERIFY': 'background:#fef3c7;color:#92400e;',
                                'TO PICK UP': 'background:#dcfce7;color:#15803d;',
                                'COMPLETED': 'background:#dcfce7;color:#166534;',
                                'CANCELLED': 'background:#fecaca;color:#b91c1c;'
                            },
                            payment: {
                                'Pending': 'background:#fef3c7;color:#92400e;',
                                'Unpaid': 'background:#fee2e2;color:#991b1b;',
                                'Paid': 'background:#dcfce7;color:#166534;',
                                'Refunded': 'background:#f3f4f6;color:#374151;',
                                'Failed': 'background:#fee2e2;color:#991b1b;'
                            }
                        };
                        const displayStatus = (type === 'order') ? normalizeOrderStatus(status) : (status || 'N/A');
                        const style = (colors[type] && colors[type][displayStatus]) || 'background:#f3f4f6;color:#374151;';
                        return `<span style="display:inline-flex;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500;${style}">${displayStatus}</span>`;
                    },

                    openModal(orderId) {
                        this.showModal = true;
                        this.loading = true;
                        this.errorMsg = '';
                        this.statusUpdateMsg = '';
                        this.order = null;
                        this.items = [];
                        fetch('<?php echo $base_path; ?>/admin/api_order_details.php?id=' + orderId, {
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                            .then(async r => {
                                const contentType = (r.headers.get('content-type') || '').toLowerCase();
                                const text = await r.text();

                                if (!text.trim()) {
                                    throw new Error('Empty server response (HTTP ' + r.status + ')');
                                }

                                if (!contentType.includes('application/json')) {
                                    console.error('Order modal non-JSON response:', text);
                                    throw new Error('Response is not JSON (HTTP ' + r.status + ')');
                                }

                                let data;
                                try {
                                    data = JSON.parse(text);
                                } catch (err) {
                                    console.error('Order modal invalid JSON:', text);
                                    throw new Error('Invalid JSON response (HTTP ' + r.status + ')');
                                }

                                if (!r.ok) {
                                    throw new Error(data.error || ('HTTP ' + r.status));
                                }

                                return data;
                            })
                            .then(data => {
                                this.loading = false;
                                if (data.success) {
                                    this.order = data.order || {};
                                    this.items = (data.items || []).map(i => ({
                                        ...i,
                                        editingTarp: false,
                                        savingTarp: false,
                                        tempWidth: (i.tarp_details && i.tarp_details.width_ft) || 0,
                                        tempHeight: (i.tarp_details && i.tarp_details.height_ft) || 0,
                                        tempRollId: (i.tarp_details && i.tarp_details.roll_id) || '',
                                        availableRolls: []
                                    }));
                                    this.selectedStatus = data.order.status || 'Pending';
                                } else { 
                                    this.errorMsg = data.error || 'Failed to load order details.'; 
                                }
                            })
                            .catch(err => {
                                this.loading = false;
                                this.errorMsg = 'Network error: ' + err.message;
                                console.error('Order modal error:', err);
                            });
                    }
                };
                console.log('[orders] orderModal data:', data);
                return data;
            }

            function ordersPage() { 
                console.log('[orders] ordersPage() factory function called');
                
                const filterData = filterPanel();
                console.log('[orders] filterData received:', filterData);
                
                const modalData = orderModal();
                console.log('[orders] modalData received:', modalData);
                
                const component = {
                    // Explicitly list all properties to avoid spread operator issues
                    filterOpen: filterData.filterOpen,
                    sortOpen: filterData.sortOpen,
                    activeSort: filterData.activeSort,
                    hasActiveFilters: filterData.hasActiveFilters,
                    
                    showModal: modalData.showModal,
                    loading: modalData.loading,
                    errorMsg: modalData.errorMsg,
                    order: modalData.order,
                    items: modalData.items,
                    selectedStatus: modalData.selectedStatus,
                    updatingStatus: modalData.updatingStatus,
                    statusUpdateMsg: modalData.statusUpdateMsg,
                    statusUpdateError: modalData.statusUpdateError,
                    
                    // Methods from modalData
                    startTarpEdit: modalData.startTarpEdit,
                    fetchRolls: modalData.fetchRolls,
                    saveTarpSpecs: modalData.saveTarpSpecs,
                    updateStatus: modalData.updateStatus,
                    statusBadge: modalData.statusBadge,
                    openModal: modalData.openModal,
                    
                    init() {
                        console.log('[orders] Component init() called');
                        console.log('[orders] Component this:', this);
                        console.log('[orders] sortOpen value:', this.sortOpen);
                        console.log('[orders] filterOpen value:', this.filterOpen);
                        
                        window.addEventListener('open-order-modal', e => this.openModal(e.detail.orderId));
                        const openOrderId = printflowGetOpenOrderId();
                        if (openOrderId) {
                            this.$nextTick(() => this.openModal(openOrderId));
                        }
                        window.addEventListener('sort-changed', e => { 
                            this.activeSort = e.detail.sortKey; 
                            this.sortOpen = false; 
                        });
                        window.addEventListener('filter-badge-update', e => { 
                            this.hasActiveFilters = (e.detail.badge > 0); 
                        });
                    }
                };
                
                console.log('[orders] Returning component:', component);
                console.log('[orders] Component sortOpen:', component.sortOpen);
                console.log('[orders] Component filterOpen:', component.filterOpen);
                return component;
            }

            // ═══════════════════════════════════════════════════════════════
            // ORDERS PAGE INITIALIZATION - Simplified and Robust
            // ═══════════════════════════════════════════════════════════════
            
            function printflowInitOrdersPage() {
                console.log('[orders] Starting initialization...');
                
                // Check if we're on the orders page
                var main = document.querySelector('main[x-data="ordersPage()"]');
                if (!main) {
                    console.log('[orders] Not on orders page, skipping');
                    return;
                }
                
                console.log('[orders] Found main element with ordersPage()');
                console.log('[orders] Alpine dataStack:', main._x_dataStack);
                
                /* Bind filter inputs - these work independently of Alpine */
                const inputs = ['fp_status', 'fp_date_from', 'fp_date_to'];
                inputs.forEach(id => {
                    const el = document.getElementById(id);
                    if (el && !el._pf_bound) {
                        el._pf_bound = true;
                        el.addEventListener('change', () => {
                            console.log('[orders] Filter changed:', id);
                            fetchUpdatedTable();
                        });
                        console.log('[orders] Bound filter input:', id);
                    }
                });
                
                const searchInput = document.getElementById('fp_search');
                if (searchInput && !searchInput._pf_bound) {
                    searchInput._pf_bound = true;
                    searchInput.addEventListener('input', () => {
                        clearTimeout(searchDebounceTimer);
                        searchDebounceTimer = setTimeout(() => { 
                            console.log('[orders] Search triggered');
                            fetchUpdatedTable(); 
                        }, 500);
                    });
                    console.log('[orders] Bound search input');
                }
                
                console.log('[orders] Initialization complete');
            }
            
            // Wait for Alpine to be fully ready
            function waitForAlpineAndInitOrders() {
                // Check if we're on the orders page
                if (!document.querySelector('main[x-data="ordersPage()"]')) {
                    console.log('[orders] Not on orders page');
                    return;
                }
                
                // Check if Alpine is loaded
                if (typeof window.Alpine === 'undefined') {
                    console.log('[orders] Alpine not loaded yet, retrying...');
                    setTimeout(waitForAlpineAndInitOrders, 50);
                    return;
                }
                
                console.log('[orders] Alpine is loaded, version:', Alpine.version);
                
                // Check if Alpine has initialized the component
                var main = document.querySelector('main[x-data="ordersPage()"]');
                if (main && (!main._x_dataStack || main._x_dataStack.length === 0)) {
                    console.log('[orders] Alpine component not initialized yet, retrying...');
                    setTimeout(waitForAlpineAndInitOrders, 100);
                    return;
                }
                
                console.log('[orders] Alpine component is ready!');
                printflowInitOrdersPage();
            }
            
            // Start initialization
            console.log('[orders] Script loaded, readyState:', document.readyState);
            
            if (document.readyState === 'loading') { 
                document.addEventListener('DOMContentLoaded', function() {
                    console.log('[orders] DOMContentLoaded fired');
                    waitForAlpineAndInitOrders();
                }); 
            } else { 
                console.log('[orders] DOM already loaded');
                waitForAlpineAndInitOrders();
            }
            
            // Also listen for Turbo navigation
            document.addEventListener('printflow:page-init', function() {
                console.log('[orders] printflow:page-init event fired');
                waitForAlpineAndInitOrders();
            });

            function openOrderModal(orderId) { window.dispatchEvent(new CustomEvent('open-order-modal', { detail: { orderId } })); }
            function printflowGetOpenOrderId() {
                var oo = new URLSearchParams(window.location.search).get('open_order');
                if (!oo) return 0;
                var oid = parseInt(oo, 10);
                return oid > 0 ? oid : 0;
            }
            function printflowOpenOrderFromQuery() {
                var oid = printflowGetOpenOrderId();
                if (!oid) return;
                var main = document.querySelector('main[x-data="ordersPage()"]');
                if (main && main._x_dataStack && main._x_dataStack[0] && typeof main._x_dataStack[0].openModal === 'function') {
                    main._x_dataStack[0].openModal(oid);
                    return;
                }
                requestAnimationFrame(function () { openOrderModal(oid); });
            }
            document.addEventListener('printflow:page-init', printflowOpenOrderFromQuery);
        </script>
        <header class="pf-mobile-branch-inline">
            <h1 class="page-title">Orders <span class="pf-mobile-title-extra">Management</span></h1>
            <?php if (!defined('MANAGER_PANEL') || !MANAGER_PANEL) { render_branch_selector($branchCtx); } ?>
        </header>

        <main x-data="ordersPage()" x-init="console.log('[orders] Alpine x-init called'); console.log('[orders] Component data:', $data)">
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>
            

            <!-- KPI Summary Row (matches reports page style) -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label">TOTAL ORDERS</div>
                    <div class="kpi-value"><?php echo number_format($total_count); ?></div>
                    <div class="kpi-sub">Lifetime orders</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-label">TO VERIFY</div>
                    <div class="kpi-value"><?php echo number_format($pending_count); ?></div>
                    <div class="kpi-sub">Awaiting action</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">TO PICK UP</div>
                    <div class="kpi-value"><?php echo number_format($ready_count); ?></div>
                    <div class="kpi-sub">Awaiting customer</div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-label">COMPLETED</div>
                    <div class="kpi-value"><?php echo number_format($completed_count); ?></div>
                    <div class="kpi-sub">Processed successfully</div>
                </div>
            </div>

            <!-- Orders List & Filters -->
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">
                        Orders List
                    </h3>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'newest') }" @click="sortOpen = !sortOpen; filterOpen = false" id="sortBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php
                                $sorts = [
                                    'newest' => 'Newest to Oldest',
                                    'oldest' => 'Oldest to Newest',
                                    'az'     => 'A → Z',
                                    'za'     => 'Z → A',
                                ];
                                foreach ($sorts as $key => $label): ?>
                                <div class="sort-option" 
                                     :class="{ 'selected': activeSort === '<?php echo $key; ?>' }"
                                     @click="applySortFilter('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="activeSort === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen; sortOpen = false" id="filterBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php
                                    $active_filters = array_filter([$status_filter, $search, $date_from, $date_to], function($v) { return $v !== null && $v !== ''; });
                                    if (count($active_filters) > 0): ?>
                                    <span class="filter-badge"><?php echo count($active_filters); ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>

                            <!-- Filter Panel -->
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" id="filterPanel">
                                <div class="filter-panel-header">Filter</div>

                                <!-- Date Range -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['date_from','date_to'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div>
                                            <div class="filter-date-label">From:</div>
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Status</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['status'])">Reset</button>
                                    </div>
                                    <select id="fp_status" class="filter-select">
                                        <option value="">All statuses</option>
                                        <option value="Pending"          <?php echo $status_filter === 'Pending'          ? 'selected' : ''; ?>>TO VERIFY</option>
                                        <option value="Ready for Pickup" <?php echo $status_filter === 'Ready for Pickup' ? 'selected' : ''; ?>>TO PICK UP</option>
                                        <option value="Completed"        <?php echo $status_filter === 'Completed'        ? 'selected' : ''; ?>>COMPLETED</option>
                                        <option value="Cancelled"        <?php echo $status_filter === 'Cancelled'        ? 'selected' : ''; ?>>CANCELLED</option>
                                    </select>
                                </div>

                                <!-- Keyword Search -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="filter-actions">
                                    <button class="filter-btn-reset" style="width: 100%;" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto" id="ordersTableContainer">
                    <table class="orders-table">
                        <thead>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <th style="width:12%;white-space:nowrap;">Order Code</th>
                                <th style="text-align:left;width:<?php echo $branchId !== 'all' ? '18%' : '20%'; ?>;">Customer</th>
                                <th style="text-align:left;width:<?php echo $branchId !== 'all' ? '18%' : '20%'; ?>;">Product</th>
                                <th style="width:<?php echo $branchId !== 'all' ? '12%' : '14%'; ?>;">Date</th>
                                <?php if ($branchId !== 'all'): ?>
                                <th style="width:14%;">Branch</th>
                                <?php endif; ?>
                                <th style="width:<?php echo $branchId !== 'all' ? '12%' : '14%'; ?>;">Amount</th>
                                <th style="width:<?php echo $branchId !== 'all' ? '12%' : '14%'; ?>;">Status</th>
                                <th style="width:12%; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody">
                            <?php if (empty($orders)): ?>
                                <tr id="emptyOrdersRow">
                                    <td colspan="<?php echo $branchId !== 'all' ? '8' : '7'; ?>" style="padding:40px; text-align:center; color:#9ca3af; font-size:14px; cursor:default;">
                                        <?php echo $search ? 'No orders found matching "' . htmlspecialchars($search) . '"' : 'No orders found'; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): ?>
                                    <tr data-order-id="<?php echo $order['order_id']; ?>" @click="openModal(<?php echo $order['order_id']; ?>)" title="Click to view Order #<?php echo $order['order_id']; ?>" style="border-bottom: 1px solid #f3f4f6; cursor:pointer;">
                                        <?php $order_code = $order['order_sku'] ? $order['order_sku'] . '-' . $order['order_id'] : 'ORD-' . $order['order_id']; ?>
                                        <td class="order-code-cell">
                                            <span class="cell-ellipsis order-code-text" title="<?php echo htmlspecialchars($order_code); ?>"><?php echo htmlspecialchars($order_code); ?></span>
                                        </td>
                                        <td>
                                            <div class="cell-ellipsis" style="color:#1f2937; max-width:160px;" title="<?php echo htmlspecialchars($order['customer_name']); ?>"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                                            <div class="cell-ellipsis" style="font-size:11px; color:#9ca3af; max-width:160px;" title="<?php echo htmlspecialchars($order['customer_email']); ?>"><?php echo htmlspecialchars($order['customer_email']); ?></div>
                                        </td>
                                        <td>
                                            <?php $product_names = trim((string)($order['product_names'] ?? '')); ?>
                                            <div class="cell-ellipsis" style="color:#1f2937; max-width:180px;" title="<?php echo htmlspecialchars($product_names ?: '—'); ?>"><?php echo htmlspecialchars($product_names ?: '—'); ?></div>
                                        </td>
                                        <td style="color:#6b7280; font-size: 12px;"><?php echo format_date($order['order_date']); ?></td>
                                        <?php if ($branchId !== 'all'): ?>
                                        <td><?php
                                            echo get_branch_badge_html(
                                                (int)($order['branch_id'] ?? 0),
                                                $order['branch_name'] ?? 'Main'
                                            );
                                        ?></td>
                                        <?php endif; ?>
                                        <td style="color:#1f2937;">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <?php
                                                $display_status = admin_display_status((string)($order['status'] ?? ''));
                                                $sc = admin_status_badge_style($display_status);
                                            ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500;<?php echo $sc; ?>" class="cell-ellipsis" title="<?php echo htmlspecialchars($display_status); ?>"><?php echo htmlspecialchars($display_status); ?></span>
                                        </td>
                                        <td style="text-align:right;" @click.stop>
                                            <button 
                                                @click="openModal(<?php echo $order['order_id']; ?>)"
                                                class="btn-action blue"
                                            >View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="ordersPagination">
                    <?php 
                    $pagination_params = array_filter(['search'=>$search, 'status'=>$status_filter, 'date_from'=>$date_from, 'date_to'=>$date_to, 'sort'=>$sort_by], function($v) { return $v !== null && $v !== ''; });
                    echo render_pagination($page, $total_pages, $pagination_params); 
                    ?>
                </div>
            </div>

<!-- Order Details Modal (inside main x-data="ordersPage()" for Alpine scope) -->
<div x-show="showModal"
     x-cloak>
    
    <!-- Overlay -->
    <div class="modal-overlay" @click.self="showModal = false">
        <!-- Modal Panel -->
        <div class="modal-panel" @click.stop>
            
            <!-- Loading State -->
            <div x-show="loading" style="padding:48px;text-align:center;">
                <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                <p style="color:#6b7280;font-size:14px;">Loading order details...</p>
            </div>

            <!-- Error State -->
            <div x-show="errorMsg && !loading" style="padding:32px;text-align:center;">
                <p style="color:#ef4444;font-size:14px;margin-bottom:12px;" x-text="errorMsg"></p>
                <button @click="showModal = false" class="btn-secondary">Close</button>
            </div>

            <!-- Order Details Content -->
            <div x-show="order && !loading" class="modal-content">
                <!-- Header -->
                <div style="padding:20px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                    <div>
                        <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0;">Order Code: <span x-text="order ? (order.order_code || 'N/A') : 'N/A'"></span></h3>
                        <p style="font-size:13px;color:#6b7280;margin:2px 0 0;" x-text="order ? (order.order_date || 'N/A') : 'N/A'"></p>
                        <p style="font-size:12px;color:#4F46E5;margin:3px 0 0;font-weight:600;"><span x-text="order ? (order.branch_name || 'Main Branch') : 'Main Branch'"></span></p>
                    </div>
                    <button @click="showModal = false" style="width:32px;height:32px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7280;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- Customer & Order Info Grid -->
                <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <!-- Customer Info -->
                    <div style="background:#f9fafb;border-radius:10px;padding:16px;border:1px solid #f3f4f6;">
                        <h4 style="font-size:12px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin:0 0 12px;">Customer</h4>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <template x-if="order && order.customer_picture">
                                <img :src="order.customer_picture" alt="Customer" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                            </template>
                            <template x-if="!order || !order.customer_picture">
                                <div x-text="order ? (order.customer_initial || 'C') : 'C'" style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:14px;flex-shrink:0;"></div>
                            </template>
                            <div>
                                <div x-text="order ? (order.customer_name || 'N/A') : 'N/A'" style="font-weight:600;font-size:14px;color:#1f2937;"></div>
                                <div x-text="order ? (order.customer_email || 'N/A') : 'N/A'" style="font-size:12px;color:#6b7280;"></div>
                            </div>
                        </div>
                        <div style="font-size:13px;color:#6b7280;">
                            <span>Phone: </span><span x-text="order ? (order.customer_phone || 'N/A') : 'N/A'" style="color:#1f2937;font-weight:500;"></span>
                        </div>
                    </div>

                    <!-- Order Status -->
                    <div style="background:#f9fafb;border-radius:10px;padding:16px;border:1px solid #f3f4f6;">
                        <h4 style="font-size:12px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin:0 0 12px;">Order Status</h4>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:13px;color:#6b7280;">Status</span>
                                <span x-html="statusBadge(order ? order.status : 'N/A', 'order')"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:13px;color:#6b7280;">Payment</span>
                                <span x-html="statusBadge(order ? order.payment_status : 'N/A', 'payment')"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:13px;color:#6b7280;">Total</span>
                                <span x-text="order ? (order.total_amount || '₱0.00') : '₱0.00'" style="font-weight:700;font-size:16px;color:#1f2937;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items Table -->
                <div style="padding:0 24px 20px;">
                    <h4 style="font-size:12px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin:0 0 12px;">Order Items</h4>
                    <div style="border:1px solid #f3f4f6;border-radius:10px;overflow:hidden;">
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="background:#f9fafb;">
                                    <th style="text-align:left;padding:10px 14px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Product</th>
                                    <th style="text-align:center;padding:10px 14px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Qty</th>
                                    <th style="text-align:right;padding:10px 14px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Price</th>
                                    <th style="text-align:right;padding:10px 14px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-if="items.length === 0">
                                    <tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">No items found</td></tr>
                                </template>
                                <template x-for="item in (items || [])" :key="item.sku">
                                    <tr style="border-top:1px solid #f3f4f6;">
                                        <td style="padding:10px 14px;">
                                            <div x-text="item.product_name || 'Unknown'" style="font-weight:500;color:#1f2937;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="item.product_name || 'Unknown'"></div>
                                            <template x-if="item.variant_name">
                                                <div style="margin-top:3px;">
                                                    <span x-text="'📐 ' + item.variant_name"
                                                          style="display:inline-flex;align-items:center;background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="'📐 ' + item.variant_name"></span>
                                                </div>
                                            </template>
                                            <div x-text="item.category || '—'" style="font-size:11px;color:#9ca3af;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="item.category || '—'"></div>
                                            
                                            <!-- Tarpaulin/Sticker Specific Specs (Roll-based) -->
                                            <template x-if="item.category && (item.category.toUpperCase().includes('TARPAULIN') || item.category.toUpperCase().includes('STKR'))">
                                                <div style="margin-top:8px;">
                                                    <div x-show="!item.editingTarp" style="font-size:12px; background:#f0fdf4; padding:8px; border-radius:8px; border:1px solid #dcfce7;">
                                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                                            <div>
                                                                <template x-if="item.tarp_details">
                                                                    <div>
                                                                        <span style="color:#166534; font-weight:600;" x-text="(item.tarp_details.width_ft || 0) + ' x ' + (item.tarp_details.height_ft || 0) + ' ft'"></span>
                                                                        <span style="color:#6b7280; margin-left:8px;" x-text="'Roll: ' + (item.tarp_details.roll_code || 'Not Assigned')"></span>
                                                                    </div>
                                                                </template>
                                                                <template x-if="!item.tarp_details">
                                                                    <span style="color:#991b1b; font-weight:600;">Dimensions not set</span>
                                                                </template>
                                                            </div>
                                                            <button @click="startTarpEdit(item)" style="font-size:11px; color:#4F46E5; background:none; border:none; cursor:pointer; font-weight:600; text-decoration:underline;">Configure</button>
                                                        </div>
                                                    </div>
                                                    
                                                    <div x-show="item.editingTarp" style="font-size:12px; background:#fff; padding:12px; border-radius:12px; border:1px solid #e5e7eb; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
                                                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">
                                                            <div>
                                                                <label style="display:block; font-size:10px; color:#6b7280; margin-bottom:2px;">Width (FT)</label>
                                                                <input type="number" x-model="item.tempWidth" @change="fetchRolls(item)" style="width:100% !important; height:32px; border:1px solid #e5e7eb; border-radius:6px; padding:0 8px;">
                                                            </div>
                                                            <div>
                                                                <label style="display:block; font-size:10px; color:#6b7280; margin-bottom:2px;">Height (FT)</label>
                                                                <input type="number" x-model="item.tempHeight" style="width:100% !important; height:32px; border:1px solid #e5e7eb; border-radius:6px; padding:0 8px;">
                                                            </div>
                                                        </div>
                                                        <div style="margin-bottom:8px;">
                                                            <label style="display:block; font-size:10px; color:#6b7280; margin-bottom:2px;">Inventory Roll</label>
                                                            <select x-model="item.tempRollId" style="width:100% !important; height:32px; border:1px solid #e5e7eb; border-radius:6px; padding:0 8px; display:block;">
                                                                <option value="">Select a Roll</option>
                                                                <template x-for="roll in item.availableRolls || []" :key="roll.id">
                                                                    <option :value="roll.id" x-text="(roll.roll_code || 'Roll') + ' (' + (roll.remaining_length_ft || 0) + ' ft left)'"></option>
                                                                </template>
                                                            </select>
                                                        </div>
                                                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                                                            <button @click="item.editingTarp = false" style="padding:4px 10px; font-size:11px; background:#f3f4f6; border-radius:6px; border:none; cursor:pointer;">Cancel</button>
                                                            <button @click="saveTarpSpecs(item)" style="padding:4px 10px; font-size:11px; background:#4F46E5; color:white; border-radius:6px; border:none; cursor:pointer;" :disabled="item.savingTarp">Save</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </td>
                                        <td style="padding:10px 14px;text-align:center;" x-text="item.quantity || 0"></td>
                                        <td style="padding:10px 14px;text-align:right;color:#6b7280;" x-text="item.unit_price_formatted || '₱0.00'"></td>
                                        <td style="padding:10px 14px;text-align:right;font-weight:600;color:#1f2937;" x-text="item.subtotal_formatted || '₱0.00'"></td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot x-show="items && items.length > 0">
                                <tr style="border-top:2px solid #e5e7eb;background:#f9fafb;">
                                    <td colspan="3" style="padding:12px 14px;text-align:right;font-weight:600;font-size:14px;">Total</td>
                                    <td style="padding:12px 14px;text-align:right;font-weight:700;font-size:15px;color:#1f2937;" x-text="order ? (order.total_amount || '₱0.00') : '₱0.00'"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Notes -->
                <template x-if="order && order.notes">
                    <div style="padding:0 24px 20px;">
                        <h4 style="font-size:12px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin:0 0 8px;">Notes</h4>
                        <p x-text="order.notes" style="font-size:13px;color:#6b7280;background:#f9fafb;padding:12px;border-radius:8px;border:1px solid #f3f4f6;margin:0;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:break-word;"></p>
                    </div>
                </template>

                <!-- Footer -->
                <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;flex-shrink:0;">
                    <button @click="showModal = false" class="btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Force-hide the "Showing X of Y" text element */
    .flex.items-center.justify-between.pb-4 > span.text-sm.text-gray-700 {
        display: none !important;
    }
</style>

        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
