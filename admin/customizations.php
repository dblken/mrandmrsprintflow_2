<?php
/**
 * Admin: Customizations (Job Orders) — Read-Only View
 * PrintFlow - Printing Shop PWA
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

function admin_customization_order_code(array $row): string {
    $orderId = (int)($row['order_id'] ?? 0);
    if ($orderId > 0) {
        return (string)(printflow_get_order_inventory_reference($orderId)['code'] ?? ('ORD-' . $orderId));
    }

    $jobId = (int)($row['id'] ?? 0);
    if ($jobId > 0) {
        return (string)(printflow_get_job_inventory_reference($jobId)['code'] ?? ('JOB-' . $jobId));
    }

    return 'N/A';
}

// Filters
$search         = trim($_GET['search'] ?? '');
$status_filter  = $_GET['status'] ?? '';
$payment_filter = $_GET['payment'] ?? '';
$date_from      = $_GET['date_from'] ?? '';
$date_to        = $_GET['date_to'] ?? '';
$sort_by        = $_GET['sort'] ?? 'newest';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 15;

// ── Branch Context (operational page) ─────────────────
$branchCtx = init_branch_context(false); // analytics-style — allow All
$branchId  = $branchCtx['selected_branch_id'];
// Keep dataset aligned with staff/customizations.php
$jobCustomizationScopeSql = " AND (
    jo.order_id IS NULL
    OR EXISTS (
        SELECT 1
        FROM orders o_scope
        JOIN order_items oi_scope ON oi_scope.order_id = o_scope.order_id
        LEFT JOIN products p_scope ON p_scope.product_id = oi_scope.product_id
        WHERE o_scope.order_id = jo.order_id
          AND o_scope.order_type = 'custom'
          AND COALESCE(LOWER(TRIM(p_scope.product_type)), 'custom') <> 'fixed'
    )
)";

// Build query (branch from job row or linked store order)
$sql = "SELECT jo.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, c.email AS customer_email,
               COALESCE(jo.branch_id, jo_ord.branch_id) AS branch_display_id,
               b.branch_name AS branch_name
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.customer_id
        LEFT JOIN orders jo_ord ON jo_ord.order_id = jo.order_id
        LEFT JOIN branches b ON b.id = COALESCE(jo.branch_id, jo_ord.branch_id)
        WHERE 1=1" . $jobCustomizationScopeSql;
$params = []; $types = '';

$joBranchExpr = "COALESCE(jo.branch_id, (SELECT ord2.branch_id FROM orders ord2 WHERE ord2.order_id = jo.order_id LIMIT 1))";

// ── Branch filter (all = non-archived branches only; allow NULL effective branch) ──────────────────────────────────
if ($branchId !== 'all') {
    $sql .= " AND {$joBranchExpr} = ?";
    $params[] = (int)$branchId;
    $types .= 'i';
} else {
    $sql .= " AND ({$joBranchExpr} IS NULL OR {$joBranchExpr} IN (SELECT id FROM branches WHERE status != 'Archived'))";
}

if (!empty($search)) {
    $s = '%' . $search . '%';
    $sql .= " AND (CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR jo.service_type LIKE ? OR jo.id LIKE ?)";
    $params = array_merge($params, [$s,$s,$s,$s,$s]);
    $types .= 'sssss';
}

if (!empty($status_filter)) {
    $sql .= " AND jo.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($payment_filter)) {
    $sql .= " AND jo.payment_status = ?";
    $params[] = $payment_filter;
    $types .= 's';
}

if (!empty($date_from)) {
    $sql .= " AND DATE(jo.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $sql .= " AND DATE(jo.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Count
$count_sql = preg_replace('/SELECT jo\.\*.*?FROM/s', 'SELECT COUNT(*) as total FROM', $sql);
$total_filtered = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;
$total_pages = max(1, ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Sorting
$order_clause = match($sort_by) {
    'oldest' => "jo.created_at ASC",
    'az'     => "c.first_name ASC, c.last_name ASC",
    'za'     => "c.first_name DESC, c.last_name DESC",
    default  => "jo.created_at DESC"
};
$sql .= " ORDER BY $order_clause LIMIT $per_page OFFSET $offset";
$jobs = db_query($sql, $types ?: null, $params ?: null) ?: [];
foreach ($jobs as &$jobRow) {
    $jobRow['order_code'] = admin_customization_order_code($jobRow);
}
unset($jobRow);

// AJAX check
if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <table class="w-full text-sm customs-table">
        <thead>
            <tr style="border-bottom: 1px solid #e5e7eb;">
                <th class="text-left py-3 order-code-header" style="width:1%;">Order Code</th>
                <th class="text-left py-3">Customer</th>
                <th class="text-left py-3">Service</th>
                <th class="text-center py-3">Date Submitted</th>
                <?php if ($branchId !== 'all'): ?>
                <th class="text-left py-3">Branch</th>
                <?php endif; ?>
                <th class="text-right py-3">Amount</th>
                <th class="text-center py-3">Payment</th>
                <th class="text-center py-3">Status</th>
                <th class="text-right py-3">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="<?php echo $branchId === 'all' ? '8' : '9'; ?>" class="py-12 text-center text-gray-400">No customizations found</td></tr>
            <?php else: ?>
                <?php foreach ($jobs as $jo): ?>
                        <?php $adminOrderCode = (string)($jo['order_code'] ?? admin_customization_order_code($jo)); ?>
                                    <tr class="hover:bg-gray-50" style="border-bottom: 1px solid #f3f4f6; cursor:pointer;" @click="openModal(<?php echo $jo['id']; ?>, <?php echo htmlspecialchars(json_encode($adminOrderCode), ENT_QUOTES, 'UTF-8'); ?>)">
                        <td class="py-3 text-gray-900">
                            <span class="order-code-text" title="<?php echo htmlspecialchars($adminOrderCode); ?>">
                                <?php echo htmlspecialchars($adminOrderCode); ?>
                            </span>
                        </td>
                        <td class="py-3">
                            <div class="text-gray-900" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['customer_name'] ?: 'Walk-in Customer'); ?>">
                                <?php echo htmlspecialchars($jo['customer_name'] ?: 'Walk-in Customer'); ?>
                            </div>
                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($jo['customer_email'] ?: ''); ?></div>
                        </td>
                        <td class="py-3">
                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['service_type']); ?>">
                                <?php echo htmlspecialchars($jo['service_type']); ?>
                            </div>
                            <?php if ($jo['quantity'] > 1): ?>
                                <div class="text-xs text-gray-400">Qty: <?php echo $jo['quantity']; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-center text-gray-500 text-xs"><?php echo date('M j, Y', strtotime($jo['created_at'])); ?></td>
                        <?php if ($branchId !== 'all'): ?>
                        <td class="py-3" style="max-width:140px;">
                            <?php
                            $joBid = (int)($jo['branch_display_id'] ?? 0);
                            $joBn = trim((string)($jo['branch_name'] ?? ''));
                            if ($joBid > 0 && $joBn !== '') {
                                echo get_branch_badge_html($joBid, $joBn);
                            } elseif ($joBid > 0) {
                                echo '<span class="text-xs text-gray-600">#' . $joBid . '</span>';
                            } else {
                                echo '<span class="text-gray-400 text-xs">Unassigned</span>';
                            }
                            ?>
                        </td>
                        <?php endif; ?>
                        <td class="py-3 text-right">
                            <?php echo $jo['estimated_total'] ? '₱' . number_format($jo['estimated_total'], 2) : '<span class="text-gray-400 text-xs">Pending</span>'; ?>
                        </td>
                        <td class="py-3 text-center">
                            <?php
                                $pc = match($jo['payment_status']) {
                                    'UNPAID'               => 'background:#fee2e2;color:#991b1b;',
                                    'PENDING_VERIFICATION' => 'background:#fef9c3;color:#854d0e;',
                                    'PARTIAL'              => 'background:#fef3c7;color:#b45309;',
                                    'PAID'                 => 'background:#dcfce7;color:#166534;',
                                    default                => 'background:#fef9c3;color:#854d0e;'
                                };
                            ?>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500;<?php echo $pc; ?>">
                                <?php echo ucwords(strtolower(str_replace('_',' ',$jo['payment_status']))); ?>
                            </span>
                        </td>
                        <td class="py-3 text-center">
                            <?php
                                $sc = match($jo['status']) {
                                    'PENDING'       => 'background:#fef9c3;color:#92400e;',
                                    'APPROVED'      => 'background:#dbeafe;color:#1e40af;',
                                    'TO_PAY'        => 'background:#fce7f3;color:#9d174d;',
                                    'VERIFY_PAY'    => 'background:#e0e7ff;color:#4338ca;',
                                    'IN_PRODUCTION' => 'background:#d1fae5;color:#065f46;',
                                    'TO_RECEIVE'    => 'background:#ede9fe;color:#5b21b6;',
                                    'COMPLETED'     => 'background:#dcfce7;color:#166534;',
                                    'CANCELLED'     => 'background:#fee2e2;color:#991b1b;',
                                    default         => 'background:#f3f4f6;color:#374151;'
                                };
                            ?>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $sc; ?>">
                                <?php echo ucwords(strtolower(str_replace('_',' ',$jo['status']))); ?>
                            </span>
                        </td>
                        <td class="py-3 text-right">
                            <button type="button" @click.stop="openModal(<?php echo $jo['id']; ?>, <?php echo htmlspecialchars(json_encode($adminOrderCode), ENT_QUOTES, 'UTF-8'); ?>)" class="btn-action blue">View</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();

    ob_start();
    $pagination_params = array_filter(['search'=>$search, 'status'=>$status_filter, 'payment'=>$payment_filter, 'date_from'=>$date_from, 'date_to'=>$date_to, 'sort'=>$sort_by], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $pagination_params);
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success'    => true,
        'table'      => $table_html,
        'pagination' => $pagination_html,
        'count'      => number_format($total_filtered),
        'badge'      => count(array_filter([$status_filter, $payment_filter, $search, $date_from, $date_to], function($v) { return $v !== null && $v !== ''; }))
    ]);
    exit;
}

// KPIs (same branch scope + customization filter as the table — was wrongly using undefined $branch_filter → branch_id = 0)
$kpiBranchSql = '';
$kpiTypes = '';
$kpiParams = [];
if ($branchId !== 'all') {
    $kpiBranchSql = " AND {$joBranchExpr} = ?";
    $kpiTypes = 'i';
    $kpiParams = [(int)$branchId];
} else {
    $kpiBranchSql = " AND ({$joBranchExpr} IS NULL OR {$joBranchExpr} IN (SELECT id FROM branches WHERE status != 'Archived'))";
}
$kpi_total   = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE 1=1" . $jobCustomizationScopeSql . $kpiBranchSql, $kpiTypes ?: null, $kpiParams ?: null)[0]['c'] ?? 0;
$kpi_pending = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE jo.status IN ('PENDING','APPROVED')" . $jobCustomizationScopeSql . $kpiBranchSql, $kpiTypes ?: null, $kpiParams ?: null)[0]['c'] ?? 0;
$kpi_active  = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE jo.status IN ('IN_PRODUCTION','PROCESSING','PRINTING')" . $jobCustomizationScopeSql . $kpiBranchSql, $kpiTypes ?: null, $kpiParams ?: null)[0]['c'] ?? 0;
$kpi_done    = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE jo.status = 'COMPLETED'" . $jobCustomizationScopeSql . $kpiBranchSql, $kpiTypes ?: null, $kpiParams ?: null)[0]['c'] ?? 0;

$page_title = 'Customizations - Admin | PrintFlow';

// Status badge helper (Local rename to avoid conflict)
function custom_status_badge($status) {
    $map = [
        'PENDING'      => ['bg:#fef9c3;color:#92400e', 'Pending'],
        'APPROVED'     => ['bg:#dbeafe;color:#1e40af', 'Approved'],
        'TO_PAY'       => ['bg:#fce7f3;color:#9d174d', 'To Pay'],
        'IN_PRODUCTION'=> ['bg:#d1fae5;color:#065f46', 'In Production'],
        'TO_RECEIVE'   => ['bg:#ede9fe;color:#5b21b6', 'To Receive'],
        'COMPLETED'    => ['bg:#f0fdf4;color:#166534', 'Completed'],
        'CANCELLED'    => ['bg:#fee2e2;color:#991b1b', 'Cancelled'],
    ];
    [$style, $label] = $map[$status] ?? ['bg:#f3f4f6;color:#6b7280', $status];
    return "<span style=\"display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;$style\">$label</span>";
}

function custom_payment_badge($status) {
    $map = [
        'UNPAID'               => ['bg:#fee2e2;color:#991b1b', 'Unpaid'],
        'PENDING_VERIFICATION' => ['bg:#fef9c3;color:#92400e', 'Verifying'],
        'PARTIAL'              => ['bg:#fef3c7;color:#b45309', 'Partial'],
        'PAID'                 => ['bg:#d1fae5;color:#065f46', 'Paid'],
    ];
    [$style, $label] = $map[$status] ?? ['bg:#f3f4f6;color:#6b7280', $status];
    return "<span style=\"display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;$style\">$label</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>
    <style>
        .btn-action {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 6px 12px; border: 1px solid transparent; background: transparent;
            border-radius: 6px; font-size: 12px; font-weight: 500; transition: all 0.2s;
            cursor: pointer; text-decoration: none;
        }
        .btn-action.blue  { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.blue:hover  { background:#3b82f6; color:white; }
        .btn-action.teal  { color:#14b8a6; border-color:#14b8a6; }
        .btn-action.teal:hover  { background:#14b8a6; color:white; }

        .modal-overlay { position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px; }
        .modal-panel { background:#fff;border-radius:12px;box-shadow:0 25px 50px rgba(0,0,0,0.25);width:100%;max-width:640px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;position:relative;overflow:hidden; }
        .modal-content { overflow-y:auto;flex:1; }
        [x-cloak] { display:none !important; }
        @keyframes spin { to { transform:rotate(360deg); } }

        .search-box { position:relative; }
        .search-box input { padding-left:36px;width:240px;height:38px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;background:#fff;transition:border-color 0.2s; }
        .search-box input:focus { border-color:#3b82f6;outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.1); }
        .search-box .search-icon { position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none; }

        .kpi-row { display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px; }
        @media(max-width:768px){ .kpi-row{grid-template-columns:repeat(2,1fr);} }
        .kpi-card { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;position:relative;overflow:hidden; }
        .kpi-card::before { content:'';position:absolute;top:0;left:0;right:0;height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.amber::before  { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.blue::before   { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
        .kpi-card.emerald::before{ background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-label { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px; }
        .kpi-sub   { font-size:12px;color:#6b7280;margin-top:4px; }

        .ro-badge { display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:500;color:#6366f1;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:3px 8px; }

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

        /* Sort Dropdown */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 180px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 200;
            padding: 6px;
        }
        .sort-option {
            padding: 9px 12px;
            font-size: 13px;
            color: #4b5563;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sort-option:hover { background: #f9fafb; color: #111827; }
        .sort-option.selected { background: #f0fdfa; color: #0d9488; font-weight: 600; }
        .sort-option svg.check { color: #0d9488; }

        /* Filter Panel */
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
        /* Table hover + clickable rows (inventory-style) */
        .customs-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .customs-table tbody tr:hover td { background: #f9fafb; }
        .order-code-header,
        .order-code-text { white-space: nowrap; }
        .order-code-text {
            display: inline-block;
            max-width: 130px;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
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
            main[x-data="custModal()"] .modal-overlay {
                align-items: flex-start !important;
                padding: 10px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            main[x-data="custModal()"] .modal-panel {
                width: 100% !important;
                max-width: calc(100vw - 20px) !important;
                max-height: calc(100dvh - 76px) !important;
                margin: 56px auto 16px !important;
                border-radius: 8px !important;
            }
            main[x-data="custModal()"] .modal-content {
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            main[x-data="custModal()"] .modal-content > div {
                padding-left: 16px !important;
                padding-right: 16px !important;
            }
            main[x-data="custModal()"] .modal-content > div:first-child {
                align-items: flex-start !important;
                gap: 12px !important;
            }
            main[x-data="custModal()"] .modal-content > div:first-child > div:first-child {
                min-width: 0 !important;
                flex: 1 1 auto !important;
            }
            main[x-data="custModal()"] .modal-content > div:first-child h3 {
                font-size: 16px !important;
                line-height: 1.25 !important;
                overflow-wrap: anywhere !important;
            }
            main[x-data="custModal()"] .modal-content h3,
            main[x-data="custModal()"] .modal-content h4,
            main[x-data="custModal()"] .modal-content p,
            main[x-data="custModal()"] .modal-content div,
            main[x-data="custModal()"] .modal-content span,
            main[x-data="custModal()"] .modal-content td,
            main[x-data="custModal()"] .modal-panel h3,
            main[x-data="custModal()"] .modal-panel p,
            main[x-data="custModal()"] .modal-panel span {
                min-width: 0 !important;
                overflow-wrap: anywhere !important;
                word-break: break-word !important;
                white-space: normal !important;
            }
            main[x-data="custModal()"] .modal-content > div[style*="padding:24px"] {
                padding: 16px !important;
            }
            main[x-data="custModal()"] .detail-row {
                flex-direction: column !important;
                gap: 10px !important;
                margin-bottom: 10px !important;
            }
            main[x-data="custModal()"] .detail-block {
                width: 100% !important;
                min-width: 0 !important;
            }
            main[x-data="custModal()"] .detail-block span,
            main[x-data="custModal()"] .history-item div {
                overflow-wrap: anywhere !important;
            }
            main[x-data="custModal()"] .modal-content [style*="display:flex"][style*="gap:24px"] {
                flex-direction: column !important;
                gap: 16px !important;
            }
            main[x-data="custModal()"] .modal-content [style*="min-width:200px"] {
                min-width: 0 !important;
                width: 100% !important;
            }
            main[x-data="custModal()"] .modal-content [style*="width:120px"][style*="height:120px"] {
                width: min(120px, 40vw) !important;
                height: min(120px, 40vw) !important;
            }
            main[x-data="custModal()"] .modal-content > div:last-child,
            main[x-data="custModal()"] .modal-panel > div:last-child[style*="border-top"] {
                text-align: left !important;
                justify-content: stretch !important;
            }
            main[x-data="custModal()"] .modal-content > div:last-child .btn-secondary,
            main[x-data="custModal()"] .modal-panel > div:last-child[style*="border-top"] .btn-secondary {
                width: 100% !important;
                justify-content: center !important;
            }
            main[x-data="custModal()"] .history-item {
                align-items: flex-start !important;
                gap: 12px !important;
            }
            main[x-data="custModal()"] .history-item > div {
                min-width: 0 !important;
            }
            main[x-data="custModal()"] .history-item > div:last-child {
                flex: 0 0 auto !important;
                max-width: 42% !important;
            }
            main[x-data="custModal()"] .tab-btn {
                flex: 1 1 0 !important;
                min-width: 0 !important;
                padding: 8px 10px !important;
                text-align: center !important;
            }
            .custom-image-viewer {
                z-index: 11020 !important;
            }
            .custom-image-viewer-backdrop {
                left: 0 !important;
                padding: 12px !important;
            }
            .custom-image-viewer-panel {
                max-width: calc(100vw - 24px) !important;
                max-height: calc(100dvh - 24px) !important;
                border-radius: 8px !important;
            }
            .custom-image-viewer-panel img {
                max-width: calc(100vw - 24px) !important;
                max-height: calc(100dvh - 24px) !important;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <script>
        function custModal() {
            return {
                showModal: false,
                loading: false,
                errorMsg: '',
                job: null,

                // Sort & Filter UI State
                sortOpen: false,
                filterOpen: false,
                activeSort: '<?php echo $sort_by; ?>',
                hasActiveFilters: <?php echo count(array_filter([$status_filter, $payment_filter, $search, $date_from, $date_to], function($v) { return $v !== null && $v !== ''; })) > 0 ? 'true' : 'false'; ?>,

                // History Modal State
                showHistory: false,
                historyLoading: false,
                historyTab: 'orders',
                historyCustomerName: '',
                historyOrders: [],
                historyCustoms: [],

                // Image Viewer State
                showImageViewer: false,
                currentImage: '',

                modalOrderCode: '',
                openModal(id, orderCode = '') {
                    this.showModal = true;
                    this.loading = true;
                    this.errorMsg = '';
                    this.job = null;
                    this.modalOrderCode = String(orderCode || '').trim();

                    fetch('<?php echo $base_path; ?>/admin/job_orders_api.php?action=get_order&id=' + id)
                        .then(async (r) => {
                            const text = await r.text();
                            let data;
                            try {
                                data = JSON.parse(text);
                            } catch (e) {
                                this.loading = false;
                                this.errorMsg = 'Invalid response from server. Check console for details.';
                                console.error('JSON parse error:', e, 'Response:', text);
                                return;
                            }
                            this.loading = false;
                            if (data.success) {
                                this.job = data.data;
                            } else {
                                this.errorMsg = data.error || 'Could not load details.';
                            }
                        })
                        .catch(() => {
                            this.loading = false;
                            this.errorMsg = 'Failed to connect to server.';
                        });
                },

                async openHistory(customerId, name) {
                    this.showHistory = true;
                    this.historyLoading = true;
                    this.historyCustomerName = name;
                    this.historyOrders = [];
                    this.historyCustoms = [];
                    this.historyTab = 'orders';

                    try {
                        const [ordersRes, customsRes] = await Promise.all([
                            fetch(`<?php echo $base_path; ?>/admin/api_order_details.php?customer_id=${customerId}`),
                            fetch(`<?php echo $base_path; ?>/admin/job_orders_api.php?action=list_orders&customer_id=${customerId}`)
                        ]);
                        
                        const ordersData = await ordersRes.json();
                        const customsData = await customsRes.json();

                        this.historyOrders = Array.isArray(ordersData) ? ordersData : (ordersData.data || []);
                        this.historyCustoms = customsData.data || [];
                    } catch (e) {
                        console.error("History fetch error", e);
                    } finally {
                        this.historyLoading = false;
                    }
                },

                statusBadge(status) {
                    const map = {
                        PENDING:       'background:#fef9c3;color:#92400e',
                        APPROVED:      'background:#dbeafe;color:#1e40af',
                        TO_PAY:        'background:#fce7f3;color:#9d174d',
                        IN_PRODUCTION: 'background:#d1fae5;color:#065f46',
                        TO_RECEIVE:    'background:#ede9fe;color:#5b21b6',
                        COMPLETED:     'background:#f0fdf4;color:#166534',
                        CANCELLED:     'background:#fee2e2;color:#991b1b',
                    };
                    const labels = {
                        PENDING:'Pending',APPROVED:'Approved',TO_PAY:'To Pay',
                        IN_PRODUCTION:'In Production',TO_RECEIVE:'To Receive',
                        COMPLETED:'Completed',CANCELLED:'Cancelled'
                    };
                    const s = map[status] || 'background:#f3f4f6;color:#6b7280';
                    return `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;${s}">${labels[status] || status}</span>`;
                },

                paymentBadge(status) {
                    const map = {
                        UNPAID:               'background:#fee2e2;color:#991b1b',
                        PENDING_VERIFICATION: 'background:#fef9c3;color:#92400e',
                        PARTIAL:              'background:#fef3c7;color:#b45309',
                        PAID:                 'background:#d1fae5;color:#065f46',
                    };
                    const labels = {UNPAID:'Unpaid',PENDING_VERIFICATION:'Verifying',PARTIAL:'Partial',PAID:'Paid'};
                    const s = map[status] || 'background:#f3f4f6;color:#6b7280';
                    return `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;${s}">${labels[status] || status}</span>`;
                },

                viewImage(url) {
                    this.currentImage = url;
                    this.showImageViewer = true;
                }
            };
        }
        window.custModal = custModal;

        function buildFilterURL(overrides = {}, isAjax = false) {
            const params = new URLSearchParams(window.location.search);
            const fields = {
                status:    () => document.getElementById('fp_status')?.value   || '',
                payment:   () => document.getElementById('fp_payment')?.value  || '',
                search:    () => document.getElementById('fp_search')?.value   || '',
                date_from: () => document.getElementById('fp_date_from')?.value || '',
                date_to:   () => document.getElementById('fp_date_to')?.value   || '',
            };
            for (const [key, getter] of Object.entries(fields)) {
                const val = (overrides[key] !== undefined) ? overrides[key] : getter();
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
                    const tableContainer = document.getElementById('customsTableContainer');
                    const paginationContainer = document.getElementById('customizationsPagination');
                    const filterBadgeContainer = document.getElementById('filterBadgeContainer');
                    if (tableContainer) {
                        tableContainer.innerHTML = data.table;
                        if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                            Alpine.initTree(tableContainer);
                        }
                    }
                    if (paginationContainer) paginationContainer.innerHTML = data.pagination;
                    if (filterBadgeContainer) {
                        filterBadgeContainer.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
                    }
                    const root = document.querySelector('[x-data="custModal()"]');
                    if (root && root._x_dataStack) {
                        root._x_dataStack[0].hasActiveFilters = (data.badge > 0);
                    }
                    const displayUrl = buildFilterURL(overrides, false);
                    window.history.replaceState({ path: displayUrl }, '', displayUrl);
                }
            } catch (e) { console.error('Error updating table:', e); }
        }

        function applyFilters(resetAll = false) {
            if (resetAll) {
                const base = window.location.pathname;
                const branch = new URLSearchParams(window.location.search).get('branch_id');
                const target = base + (branch ? '?branch_id=' + encodeURIComponent(branch) : '');
                window.location.href = target;
            } else { fetchUpdatedTable(); }
        }

        function applySortFilter(sortKey) {
            const root = document.querySelector('[x-data="custModal()"]');
            if (root && root._x_dataStack) {
                const data = root._x_dataStack[0];
                data.activeSort = sortKey;
                data.sortOpen = false;
            }
            fetchUpdatedTable({ sort: sortKey });
        }

        function resetFilterField(fields) {
            fields.forEach(f => {
                const el = document.getElementById('fp_' + f);
                if (el) el.value = '';
            });
            fetchUpdatedTable();
        }

        function printflowInitCustomizationsPage() {
            if (typeof Alpine === 'undefined' || typeof Alpine.initTree !== 'function') return;
            var main = document.querySelector('main[x-data="custModal()"]');
            if (main && !main._x_dataStack) {
                try { Alpine.initTree(main); } catch (e1) { console.error(e1); }
            }
            /* #customsTableContainer is inside main; fetchUpdatedTable still initTree after AJAX. */
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', printflowInitCustomizationsPage);
        } else { printflowInitCustomizationsPage(); }

        function printflowOpenJobFromQuery() {
            var oj = new URLSearchParams(window.location.search).get('open_job');
            if (!oj) return;
            var jid = parseInt(oj, 10);
            if (!(jid > 0)) return;
            requestAnimationFrame(function () {
                var main = document.querySelector('main[x-data="custModal()"]');
                if (main && main._x_dataStack && main._x_dataStack[0]) {
                    main._x_dataStack[0].openModal(jid);
                }
            });
        }
        printflowOpenJobFromQuery();
        </script>
        <header class="pf-mobile-branch-inline">
            <h1 class="page-title">Customizations</h1>
            <?php if (!defined('MANAGER_PANEL') || !MANAGER_PANEL) { render_branch_selector($branchCtx); } ?>
        </header>

        <main x-data="custModal()">
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>
            <!-- KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label">Total</div>
                    <div class="kpi-value"><?php echo number_format($kpi_total); ?></div>
                    <div class="kpi-sub">All customizations</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-label">Pending / Approved</div>
                    <div class="kpi-value"><?php echo number_format($kpi_pending); ?></div>
                    <div class="kpi-sub">Awaiting production</div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-label">In Production</div>
                    <div class="kpi-value"><?php echo number_format($kpi_active); ?></div>
                    <div class="kpi-sub">Currently printing</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">Completed</div>
                    <div class="kpi-value"><?php echo number_format($kpi_done); ?></div>
                    <div class="kpi-sub">Finished orders</div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">
                        Customizations List
                    </h3>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">

                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'newest') }" @click="sortOpen = !sortOpen; filterOpen = false" id="sortBtn" style="height:38px;">
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
                            <button type="button" class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen; sortOpen = false" id="filterBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php
                                    $active_filters_count = count(array_filter([$status_filter, $payment_filter, $search, $date_from, $date_to], function($v) { return $v !== null && $v !== ''; }));
                                    if ($active_filters_count > 0): ?>
                                    <span class="filter-badge"><?php echo $active_filters_count; ?></span>
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
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>" @change="applyFilters()">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>" @change="applyFilters()">
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Type -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Payment</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['payment'])">Reset</button>
                                    </div>
                                    <select id="fp_payment" class="filter-select" @change="applyFilters()">
                                        <option value="">All payments</option>
                                        <option value="UNPAID" <?php echo $payment_filter === 'UNPAID' ? 'selected' : ''; ?>>Unpaid</option>
                                        <option value="PENDING_VERIFICATION" <?php echo $payment_filter === 'PENDING_VERIFICATION' ? 'selected' : ''; ?>>Verifying</option>
                                        <option value="PARTIAL" <?php echo $payment_filter === 'PARTIAL' ? 'selected' : ''; ?>>Partial</option>
                                        <option value="PAID" <?php echo $payment_filter === 'PAID' ? 'selected' : ''; ?>>Paid</option>
                                    </select>
                                </div>

                                <!-- Status -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Status</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['status'])">Reset</button>
                                    </div>
                                    <select id="fp_status" class="filter-select" @change="applyFilters()">
                                        <option value="">All statuses</option>
                                        <?php foreach(['PENDING','APPROVED','TO_PAY','IN_PRODUCTION','TO_RECEIVE','COMPLETED','CANCELLED'] as $s): ?>
                                            <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>>
                                                <?php echo ucwords(strtolower(str_replace('_',' ',$s))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Keyword Search -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" @input.debounce.500ms="applyFilters()">
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

                <div class="overflow-x-auto" id="customsTableContainer">
                    <table class="w-full text-sm customs-table">
                        <thead>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <th class="text-left py-3 order-code-header" style="width:1%;">Order Code</th>
                                <th class="text-left py-3">Customer</th>
                                <th class="text-left py-3">Service</th>
                                <th class="text-center py-3">Date Submitted</th>
                                <?php if ($branchId !== 'all'): ?>
                                <th class="text-left py-3">Branch</th>
                                <?php endif; ?>
                                <th class="text-right py-3">Amount</th>
                                <th class="text-center py-3">Payment</th>
                                <th class="text-center py-3">Status</th>
                                <th class="text-right py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="customizationsTableBody">
                            <?php if (empty($jobs)): ?>
                                <tr id="emptyCustomizationsRow"><td colspan="<?php echo $branchId === 'all' ? '8' : '9'; ?>" class="py-12 text-center text-gray-400" style="border-bottom: 1px solid #f3f4f6;">
                                    <?php echo $search ? 'No customizations found matching "' . htmlspecialchars($search) . '"' : 'No customizations found'; ?>
                                </td></tr>
                            <?php else: ?>
                                <tr id="emptyCustomizationsRow" style="display:none;"><td colspan="<?php echo $branchId === 'all' ? '8' : '9'; ?>" class="py-12 text-center text-gray-400" style="border-bottom: 1px solid #f3f4f6;">
                                    No customizations found
                                </td></tr>
                                <?php foreach ($jobs as $jo): 
                                    // Fetch regular order count for history
                                    $order_count = 0;
                                    if ($jo['customer_id']) {
                                        $order_count = db_query("SELECT COUNT(*) as c FROM orders WHERE customer_id = ?", "i", [$jo['customer_id']])[0]['c'] ?? 0;
                                    }
                                ?>
                                    <?php $adminOrderCode = (string)($jo['order_code'] ?? admin_customization_order_code($jo)); ?>
                                    <tr class="clickable-row" style="cursor:pointer;border-bottom: 1px solid #f3f4f6;" @click="openModal(<?php echo $jo['id']; ?>, <?php echo htmlspecialchars(json_encode($adminOrderCode), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <td class="py-3 text-gray-900">
                                            <span class="order-code-text" title="<?php echo htmlspecialchars($adminOrderCode); ?>">
                                                <?php echo htmlspecialchars($adminOrderCode); ?>
                                            </span>
                                        </td>
                                        <td class="py-3">
                                            <div class="text-gray-900" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['customer_name'] ?: 'Walk-in Customer'); ?>">
                                                <?php echo htmlspecialchars($jo['customer_name'] ?: 'Walk-in Customer'); ?>
                                            </div>
                                            <div class="text-xs text-gray-400" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['customer_email'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($jo['customer_email'] ?? ''); ?>
                                            </div>
                                        </td>
                                        <td class="py-3">
                                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['service_type']); ?>">
                                                <?php echo htmlspecialchars($jo['service_type']); ?>
                                            </div>
                                            <?php if ($jo['quantity'] > 1): ?>
                                                <div class="text-xs text-gray-400">Qty: <?php echo $jo['quantity']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 text-center text-gray-500 text-xs"><?php echo date('M j, Y', strtotime($jo['created_at'])); ?></td>
                                        <?php if ($branchId !== 'all'): ?>
                                        <td class="py-3" style="max-width:140px;">
                                            <?php
                                            $joBid = (int)($jo['branch_display_id'] ?? 0);
                                            $joBn = trim((string)($jo['branch_name'] ?? ''));
                                            if ($joBid > 0 && $joBn !== '') {
                                                echo get_branch_badge_html($joBid, $joBn);
                                            } elseif ($joBid > 0) {
                                                echo '<span class="text-xs text-gray-600">#' . $joBid . '</span>';
                                            } else {
                                                echo '<span class="text-gray-400 text-xs">Unassigned</span>';
                                            }
                                            ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="py-3 text-right">
                                            <?php echo $jo['estimated_total'] ? '₱' . number_format($jo['estimated_total'], 2) : '<span class="text-gray-400 text-xs">Pending</span>'; ?>
                                        </td>
                                        <td class="py-3 text-center">
                                            <?php
                                                $pc = match($jo['payment_status']) {
                                                    'UNPAID'               => 'background:#fee2e2;color:#991b1b;',
                                                    'PENDING_VERIFICATION' => 'background:#fef9c3;color:#854d0e;',
                                                    'PARTIAL'              => 'background:#fef3c7;color:#b45309;',
                                                    'PAID'                 => 'background:#dcfce7;color:#166534;',
                                                    default                => 'background:#fef9c3;color:#854d0e;'
                                                };
                                            ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500;<?php echo $pc; ?>">
                                                <?php echo ucwords(strtolower(str_replace('_',' ',$jo['payment_status']))); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-center">
                                            <?php
                                                $sc = match($jo['status']) {
                                                    'PENDING'       => 'background:#fef9c3;color:#92400e;',
                                                    'APPROVED'      => 'background:#dbeafe;color:#1e40af;',
                                                    'TO_PAY'        => 'background:#fef3c7;color:#b45309;',
                                                    'IN_PRODUCTION' => 'background:#d1fae5;color:#065f46;',
                                                    'TO_RECEIVE'    => 'background:#ede9fe;color:#5b21b6;',
                                                    'COMPLETED'     => 'background:#dcfce7;color:#166534;',
                                                    'CANCELLED'     => 'background:#fee2e2;color:#991b1b;',
                                                    default         => 'background:#fef9c3;color:#854d0e;'
                                                };
                                            ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500;<?php echo $sc; ?>">
                                                <?php echo ucwords(strtolower(str_replace('_',' ',$jo['status']))); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-right">
                                            <button type="button" @click.stop="openModal(<?php echo $jo['id']; ?>, <?php echo htmlspecialchars(json_encode($adminOrderCode), ENT_QUOTES, 'UTF-8'); ?>)" class="btn-action blue">
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div id="customizationsPagination">
                    <?php
                    if (!empty($jobs)) {
                        $pagination_params = array_filter(['search'=>$search, 'status'=>$status_filter, 'payment'=>$payment_filter, 'date_from'=>$date_from, 'date_to'=>$date_to, 'sort'=>$sort_by], function($v) { return $v !== null && $v !== ''; });
                        echo render_pagination($page, $total_pages, $pagination_params);
                    }
                    ?>
                </div>
            </div>

<!-- View Details Modal (inside main x-data so Alpine binds showModal; avoids full-screen overlay blocking clicks) -->
<div x-show="showModal" x-cloak>
    <div class="modal-overlay" @click.self="showModal = false">
        <div class="modal-panel" @click.stop>
            <!-- Loading -->
            <div x-show="loading" style="padding:48px;text-align:center;">
                <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                <p style="color:#6b7280;font-size:14px;">Loading details...</p>
            </div>
            <!-- Error -->
            <div x-show="errorMsg && !loading" style="padding:32px;text-align:center;">
                <p style="color:#ef4444;font-size:14px;margin-bottom:12px;" x-text="errorMsg"></p>
                <button @click="showModal = false" class="btn-secondary">Close</button>
            </div>
            <!-- Content -->
            <div x-show="job && !loading" class="modal-content">
                <!-- Header -->
                <div style="padding:18px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                    <div>
                        <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0 0 2px;" x-text="(job?.order_code || modalOrderCode || ('Customization #' + (job?.id ?? '')) )"></h3>
                        <div x-html="statusBadge(job?.status)" style="display:inline-block;"></div>
                    </div>
                    <button @click="showModal = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <!-- Body -->
                <div style="padding:24px;">
                    <!-- Customer -->
                    <div style="margin-bottom:18px;">
                        <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;">Customer</p>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <template x-if="job?.customer_picture">
                                <img :src="job.customer_picture" alt="Customer" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                            </template>
                            <template x-if="!job?.customer_picture">
                                <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;" x-text="job?.customer_name?.charAt(0)?.toUpperCase() || 'W'"></div>
                            </template>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;color:#1f2937;word-wrap:break-word;overflow-wrap:break-word;" x-text="job?.customer_name || 'Walk-in Customer'"></div>
                                <div style="font-size:12px;color:#6b7280;word-wrap:break-word;overflow-wrap:break-word;" x-text="job?.customer_email || ''"></div>
                            </div>
                        </div>
                    </div>
                    <!-- Details Grid -->
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Branch</label>
                            <span x-text="job?.branch_name || (job?.branch_display_id ? ('Branch #' + job.branch_display_id) : 'Unassigned')"></span>
                        </div>
                        <div class="detail-block">
                            <label>Service Type</label>
                            <span style="word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="job?.service_type"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Quantity</label>
                            <span x-text="job?.quantity ?? 1"></span>
                        </div>
                        <div class="detail-block">
                            <label>Dimensions</label>
                            <span x-text="job?.width_ft && job?.height_ft ? job.width_ft + ' ft × ' + job.height_ft + ' ft' : '—'"></span>
                            <div style="font-size:12px;color:#6b7280;margin-top:4px;" x-text="job?.total_sqft ? (job.total_sqft + ' sqft') : ''"></div>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Estimated Total</label>
                            <span x-text="job?.estimated_total ? '₱' + parseFloat(job.estimated_total).toFixed(2) : 'Pending'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Amount Paid</label>
                            <span x-text="'₱' + parseFloat(job?.amount_paid ?? 0).toFixed(2)"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Payment Status</label>
                            <span x-html="paymentBadge(job?.payment_status)"></span>
                        </div>
                        <div class="detail-block">
                            <label>Priority</label>
                            <span x-text="job?.priority ?? 'NORMAL'"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Date Submitted</label>
                            <span x-text="job?.created_at ? new Date(job.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Due Date</label>
                            <span x-text="job?.due_date ? new Date(job.due_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : 'Not set'"></span>
                        </div>
                    </div>
                    <!-- Notes -->
                    <div x-show="job?.notes" style="margin-top:4px;">
                        <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:6px;">Notes</p>
                        <div style="background:#f9fafb;border-radius:8px;padding:12px 14px;font-size:13px;color:#374151;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:break-word;" x-text="job?.notes"></div>
                    </div>
                    <!-- Design Files & Payment Proof -->
                    <div x-show="(job?.artwork_path || job?.payment_proof_path || (job?.items && job.items.length > 0 && job.items.some(i => i.design_url || i.reference_url)))" style="margin-top:18px;">
                        <div style="display:flex;gap:24px;flex-wrap:wrap;">
                            <!-- Design Files -->
                            <div x-show="(job?.artwork_path || (job?.items && job.items.length > 0 && job.items.some(i => i.design_url || i.reference_url)))" style="flex:1;min-width:200px;">
                                <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;">Design Files</p>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                    <template x-if="job?.artwork_path">
                                        <div style="position:relative;width:120px;height:120px;border-radius:8px;overflow:hidden;border:2px solid #e5e7eb;cursor:pointer;" @click="viewImage(job.artwork_path)">
                                            <img :src="job.artwork_path" style="width:100%;height:100%;object-fit:cover;" alt="Artwork">
                                            <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:white;font-size:10px;padding:4px;text-align:center;">Artwork</div>
                                        </div>
                                    </template>
                                    <template x-if="job?.items && job.items.length > 0">
                                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                            <template x-for="(item, idx) in job.items" :key="idx">
                                                <div>
                                                    <template x-if="item.design_url">
                                                        <div style="position:relative;width:120px;height:120px;border-radius:8px;overflow:hidden;border:2px solid #e5e7eb;cursor:pointer;margin-bottom:8px;" @click="viewImage(item.design_url)">
                                                            <img :src="item.design_url" style="width:100%;height:100%;object-fit:cover;" alt="Design">
                                                            <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:white;font-size:10px;padding:4px;text-align:center;">Design</div>
                                                        </div>
                                                    </template>
                                                    <template x-if="item.reference_url">
                                                        <div style="position:relative;width:120px;height:120px;border-radius:8px;overflow:hidden;border:2px solid #e5e7eb;cursor:pointer;" @click="viewImage(item.reference_url)">
                                                            <img :src="item.reference_url" style="width:100%;height:100%;object-fit:cover;" alt="Reference">
                                                            <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:white;font-size:10px;padding:4px;text-align:center;">Reference</div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <!-- Payment Proof -->
                            <div x-show="job?.payment_proof_path" style="flex:0 0 auto;min-width:200px;">
                                <p style="font-size:11px;font-weight:600;color:#10b981;text-transform:uppercase;margin-bottom:8px;">Payment Proof</p>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                    <template x-if="job?.payment_proof_path">
                                        <div style="position:relative;width:120px;height:120px;border-radius:8px;overflow:hidden;border:2px solid #10b981;cursor:pointer;box-shadow:0 2px 8px rgba(16,185,129,0.15);" @click="viewImage(job.payment_proof_path)">
                                            <img :src="job.payment_proof_path" style="width:100%;height:100%;object-fit:cover;" alt="Payment Proof">
                                            <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(16,185,129,0.95);color:white;font-size:10px;padding:4px;text-align:center;font-weight:600;">
                                                <svg style="width:12px;height:12px;display:inline-block;margin-right:3px;vertical-align:middle;" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                </svg>
                                                Payment Proof
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Footer -->
                <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;">
                    <button @click="showModal = false" class="btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div x-show="showImageViewer" x-cloak class="custom-image-viewer" style="position:fixed;top:0;bottom:0;left:0;right:0;z-index:10000;" @click="showImageViewer = false">
    <div class="custom-image-viewer-backdrop" style="position:absolute;top:0;bottom:0;left:240px;right:0;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;">
        <div class="custom-image-viewer-panel" style="position:relative;max-width:85%;max-height:85%;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,0.5);" @click.stop>
            <button @click="showImageViewer = false" style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,0.8);border:none;color:white;width:40px;height:40px;border-radius:50%;cursor:pointer;z-index:1;display:flex;align-items:center;justify-content:center;transition:background 0.2s;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <img :src="currentImage" style="max-width:100%;max-height:85vh;display:block;" alt="Full view">
        </div>
    </div>
</div>

<!-- Transaction History Modal -->
<div x-show="showHistory" x-cloak>
    <div class="modal-overlay" @click.self="showHistory = false">
        <div class="modal-panel" style="max-width: 500px;" @click.stop>
            <div style="padding: 18px 24px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between;">
                <h3 style="font-size: 16px; font-weight: 700; color: #1f2937; margin: 0;" x-text="'History: ' + historyCustomerName"></h3>
                <button @click="showHistory = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding: 16px 24px;">
                <!-- Tabs -->
                <div style="display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #f3f4f6; padding-bottom: 8px;">
                    <button class="tab-btn" :class="{ 'active': historyTab === 'orders' }" @click="historyTab = 'orders'">Store Products</button>
                    <button class="tab-btn" :class="{ 'active': historyTab === 'customs' }" @click="historyTab = 'customs'">Customizations</button>
                </div>

                <div x-show="historyLoading" style="padding: 32px; text-align: center;">
                    <div style="width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                </div>

                <div x-show="!historyLoading">
                    <!-- Orders List -->
                    <div x-show="historyTab === 'orders'">
                        <template x-if="historyOrders.length === 0">
                            <p style="text-align:center; padding: 20px; color:#9ca3af; font-size:13px;">No store orders found.</p>
                        </template>
                        <template x-for="ord in (historyOrders || [])" :key="ord.order_id">
                            <div class="history-item">
                                <div>
                                    <div style="font-size: 13px; font-weight: 700; color: #1f2937;" x-text="'Order #' + ord.order_id"></div>
                                    <div style="font-size: 11px; color: #9ca3af;" x-text="new Date(ord.order_date).toLocaleDateString()"></div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 13px; font-weight: 700; color: #1f2937;" x-text="'₱' + parseFloat(ord.total_amount).toFixed(2)"></div>
                                    <div x-html="statusBadge(ord.status)" style="transform: scale(0.85); transform-origin: right;"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Customizations List -->
                    <div x-show="historyTab === 'customs'">
                        <template x-if="historyCustoms.length === 0">
                            <p style="text-align:center; padding: 20px; color:#9ca3af; font-size:13px;">No customizations found.</p>
                        </template>
                        <template x-for="cst in (historyCustoms || [])" :key="cst.id">
                            <div class="history-item">
                                <div>
                                    <div style="font-size: 13px; font-weight: 700; color: #1f2937;" x-text="cst.service_type"></div>
                                    <div style="font-size: 11px; color: #9ca3af;" x-text="new Date(cst.created_at).toLocaleDateString()"></div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 13px; font-weight: 700; color: #1f2937;" x-text="cst.estimated_total ? '₱' + parseFloat(cst.estimated_total).toFixed(2) : 'Pending'"></div>
                                    <div x-html="statusBadge(cst.status)" style="transform: scale(0.85); transform-origin: right;"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid #f3f4f6; text-align: right;">
                <button @click="showHistory = false" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
