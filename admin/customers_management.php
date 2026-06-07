<?php
/**
 * Admin Customers Management  
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/customer_id_verification.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();
$viewerBranch = printflow_branch_filter_for_user();
$branchId = $viewerBranch ?? 'all';

if (function_exists('printflow_ensure_customers_auth_provider_column')) {
    printflow_ensure_customers_auth_provider_column();
}

function pf_admin_customer_sign_in_label(array $customer): string {
    $a = strtolower(trim((string)($customer['auth_provider'] ?? '')));
    return $a === 'google' ? 'Google' : 'Email / password';
}

function pf_build_customer_modal_payload(array $customer, string $base_path): array {
    $first_name = (string)($customer['first_name'] ?? '');
    $dob_raw = trim((string)($customer['dob'] ?? ''));
    $created_raw = trim((string)($customer['created_at'] ?? ''));
    $profile_picture_raw = trim((string)($customer['profile_picture'] ?? ''));
    $id_image_raw = trim((string)($customer['id_image'] ?? ''));

    return [
        'customer_id' => (int)($customer['customer_id'] ?? 0),
        'first_name' => $first_name,
        'middle_name' => (string)($customer['middle_name'] ?? ''),
        'last_name' => (string)($customer['last_name'] ?? ''),
        'email' => (string)($customer['email'] ?? ''),
        'contact_number' => (string)($customer['contact_number'] ?? ''),
        'address' => (string)($customer['address'] ?? ''),
        'dob' => ($dob_raw !== '' && $dob_raw !== '0000-00-00') ? date('m/d/Y', strtotime($dob_raw)) : '',
        'gender' => (string)($customer['gender'] ?? ''),
        'created_at' => $created_raw !== '' ? date('M j, Y', strtotime($created_raw)) : '',
        'profile_picture' => $profile_picture_raw !== '' ? $base_path . '/public/assets/uploads/profiles/' . ltrim($profile_picture_raw, '/') : null,
        'initial' => strtoupper(substr($first_name, 0, 1)),
        'id_status' => pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending'),
        'id_status_label' => pf_admin_id_verification_status_display($customer)['label'],
        'has_id_image' => $id_image_raw !== '',
        'id_type' => pf_decode_display_text((string)($customer['id_type'] ?? '')),
        'id_reject_reason' => pf_decode_display_text((string)($customer['id_reject_reason'] ?? '')),
        'id_image' => $id_image_raw !== '' ? $base_path . '/uploads/ids/' . ltrim($id_image_raw, '/') : null,
        'sign_in' => pf_admin_customer_sign_in_label($customer),
    ];
}

function pf_customer_payload_attr(array $customer, string $base_path): string {
    return htmlspecialchars(
        json_encode(
            pf_build_customer_modal_payload($customer, $base_path),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}',
        ENT_QUOTES,
        'UTF-8'
    );
}

// Get filter parameters
$search  = $_GET['search']  ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$status_filter = trim((string)($_GET['status_filter'] ?? ''));
$sort_by = $_GET['sort']    ?? 'newest';
$page    = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

// Build query (branch-scoped for manager/staff)
[$custBranchSql, $custBranchTypes, $custBranchParams] = ($viewerBranch)
    ? branch_customers_belong_where_sql((int)$viewerBranch, 'customers')
    : ['', '', []];
$sql = "SELECT * FROM customers WHERE 1=1" . $custBranchSql;
$params = $custBranchParams;
$types = $custBranchTypes;

if (!empty($search)) {
    $search_term = '%' . $search . '%';
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if (!empty($date_from)) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($status_filter !== '') {
    if ($status_filter === 'Verified' || $status_filter === 'Rejected') {
        $sql .= " AND COALESCE(NULLIF(id_status, ''), 'Pending') = ?";
        $params[] = $status_filter;
        $types .= 's';
    } elseif ($status_filter === 'Pending') {
        $sql .= " AND (id_status IS NULL OR id_status = '' OR id_status IN ('Pending', 'None', 'Unverified'))";
    }
}

// Count total results
$count_sql = "SELECT COUNT(*) as total FROM ({$sql}) as count_wrap";
$total_filtered = db_query($count_sql, $types, $params)[0]['total'];
$total_pages = max(1, ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sort_clause = match($sort_by) {
    'oldest' => " ORDER BY created_at ASC",
    'az'     => " ORDER BY first_name ASC, last_name ASC",
    'za'     => " ORDER BY first_name DESC, last_name DESC",
    default  => " ORDER BY created_at DESC"
};

$sql .= $sort_clause . " LIMIT $per_page OFFSET $offset";
$customers = db_query($sql, $types, $params);

// ── AJAX Response ──────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <table class="orders-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Contact</th>
                <th>Sign-in</th>
                <th>Registered</th>
                <th>Status</th>
                <th style="text-align:right;" class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody id="customersTableBody">
            <?php if (empty($customers)): ?>
                <tr id="emptyCustomersRow">
                    <td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No customers found</td>
                </tr>
            <?php else: ?>
                <tr id="emptyCustomersRow" style="display:none;">
                    <td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No customers found</td>
                </tr>
                <?php foreach ($customers as $customer): 
                    $status_display = pf_admin_id_verification_status_display($customer);
                    $customer_payload_attr = pf_customer_payload_attr($customer, $base_path);
                    $sign_in = pf_admin_customer_sign_in_label($customer);
                    $status_style = $status_display['style'];
                    $status_label = $status_display['label'];
                ?>
                    <tr class="customer-row" data-customer-id="<?php echo (int)$customer['customer_id']; ?>" data-customer="<?php echo $customer_payload_attr; ?>" onclick="openModal(<?php echo $customer['customer_id']; ?>, this)">
                        <td style="color:#1f2937;"><?php echo $customer['customer_id']; ?></td>
                        <td style="font-weight:500;color:#1f2937;" class="name-cell">
                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>">
                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                            </div>
                        </td>
                        <td class="email-cell" style="text-transform:lowercase;">
                            <div style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>">
                                <?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>
                            </div>
                        </td>
                        <td>
                            <div style="max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($customer['contact_number'] ?? 'N/A'); ?>">
                                <?php echo htmlspecialchars($customer['contact_number'] ?? 'N/A'); ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($sign_in === 'Google'): ?>
                                <span style="display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;background:#e8f0fe;color:#1967d2;">Google</span>
                            <?php else: ?>
                                <span style="display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;background:#f3f4f6;color:#4b5563;">Email</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#6b7280;font-size:12px;"><?php echo format_date($customer['created_at']); ?></td>
                        <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $status_style; ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                        <td style="text-align:right;" class="no-print actions" onclick="event.stopPropagation()">
                            <button type="button" onclick="event.stopPropagation();openModal(<?php echo $customer['customer_id']; ?>, this.closest('tr'))" class="btn-action blue">Profile</button>
                            <button type="button" onclick="event.stopPropagation();window.location.href='<?php echo $base_path; ?>/admin/customer_verification.php?open_customer=<?php echo (int)$customer['customer_id']; ?>'" class="btn-action amber">Verify</button>
                            <button type="button" onclick="event.stopPropagation();openTransactionModal(<?php echo $customer['customer_id']; ?>, this.closest('tr'))" class="btn-action teal">Transactions</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();

    ob_start();
    $pagination_params = array_filter(['search'=>$search, 'date_from'=>$date_from, 'date_to'=>$date_to, 'status_filter'=>$status_filter, 'sort'=>$sort_by], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $pagination_params); 
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success'    => true,
        'table'      => $table_html,
        'pagination' => $pagination_html,
        'count'      => number_format($total_filtered),
        'badge'      => count(array_filter([$search, $date_from, $date_to, $status_filter]))
    ]);
    exit;
}

// ── KPI Queries ──────────────────────────────────────
if ($viewerBranch) {
    $bid = (int)$viewerBranch;
    [$w, $t, $p] = branch_customers_belong_where_sql($bid, 'c');

    // 1. Total Customers (branch-scoped)
    $total_customers = (int)(db_query("SELECT COUNT(*) as count FROM customers c WHERE 1=1" . $w, $t, $p)[0]['count'] ?? 0);

    // 2. Returning Customers (branch-scoped)
    $new_this_month = (int)(db_query("
        SELECT COUNT(DISTINCT cur.customer_id) as count
        FROM (
            SELECT customer_id FROM orders
            WHERE order_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01') AND branch_id = ?
            UNION
            SELECT customer_id FROM job_orders
            WHERE created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01') AND customer_id IS NOT NULL AND branch_id = ?
        ) cur
        WHERE (
            EXISTS (
                SELECT 1 FROM orders o
                WHERE o.customer_id = cur.customer_id
                  AND o.branch_id = ?
                  AND o.order_date < DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
            )
            OR EXISTS (
                SELECT 1 FROM job_orders jo
                WHERE jo.customer_id = cur.customer_id
                  AND jo.branch_id = ?
                  AND jo.created_at < DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
            )
        )
    ", 'iiii', [$bid, $bid, $bid, $bid])[0]['count'] ?? 0);

    // 3. Active (Last 30 Days, branch-scoped)
    $active_30_days = (int)(db_query("
        SELECT COUNT(DISTINCT customer_id) as count FROM (
            SELECT customer_id FROM orders WHERE order_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) AND branch_id = ?
            UNION
            SELECT customer_id FROM job_orders WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) AND customer_id IS NOT NULL AND branch_id = ?
        ) active_customers
    ", 'ii', [$bid, $bid])[0]['count'] ?? 0);

    // 4. Average Spent per Customer (branch-scoped)
    $total_revenue_stats = (float)(db_query("
        SELECT COALESCE(SUM(amount), 0) as total FROM (
            SELECT total_amount as amount FROM orders WHERE payment_status = 'Paid' AND branch_id = ?
            UNION ALL
            SELECT amount_paid as amount FROM job_orders WHERE payment_status = 'PAID' AND customer_id IS NOT NULL AND branch_id = ?
        ) rev
    ", 'ii', [$bid, $bid])[0]['total'] ?? 0);
} else {
    // 1. Total Customers
    $total_customers = (int)(db_query("SELECT COUNT(*) as count FROM customers")[0]['count'] ?? 0);

    // 2. New This Month
    $new_this_month = (int)(db_query("SELECT COUNT(*) as count FROM customers WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")[0]['count'] ?? 0);

    // 3. Active (Last 30 Days)
    $active_30_days = (int)(db_query("
        SELECT COUNT(DISTINCT customer_id) as count FROM (
            SELECT customer_id FROM orders WHERE order_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
            UNION
            SELECT customer_id FROM job_orders WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) AND customer_id IS NOT NULL
        ) active_customers
    ")[0]['count'] ?? 0);

    // 4. Average Spent per Customer
    $total_revenue_stats = (float)(db_query("
        SELECT COALESCE(SUM(amount), 0) as total FROM (
            SELECT total_amount as amount FROM orders WHERE payment_status = 'Paid'
            UNION ALL
            SELECT amount_paid as amount FROM job_orders WHERE payment_status = 'PAID' AND customer_id IS NOT NULL
        ) rev
    ")[0]['total'] ?? 0);
}

$avg_spent = $total_customers > 0 ? ($total_revenue_stats / $total_customers) : 0;

$page_title = 'Customers Management - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        /* KPI Row - matches dashboard page */
        .kpi-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px; }
        @media (max-width:768px) { .kpi-row { grid-template-columns:repeat(2, 1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.violet::before { background:linear-gradient(90deg,#8b5cf6,#a78bfa); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }

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
        .btn-action.teal { color: #14b8a6; border-color: #14b8a6; }
        .btn-action.teal:hover { background: #14b8a6; color: white; }
        .btn-action.blue { color: #3b82f6; border-color: #3b82f6; }
        .btn-action.blue:hover { background: #3b82f6; color: white; }
        .btn-action.red { color: #ef4444; border-color: #ef4444; }
        .btn-action.red:hover { background: #ef4444; color: white; }
        .btn-action.amber { color: #d97706; border-color: #d97706; }
        .btn-action.amber:hover { background: #d97706; color: white; }
        .orders-table td.actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: nowrap;
        }
        .orders-table td.actions .btn-action {
            min-height: 30px;
            height: 30px;
            padding: 0 12px;
            box-sizing: border-box;
            line-height: 1;
            font-family: inherit;
            white-space: nowrap;
        }

        /* Toolbar Buttons */
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 16px;
            height: 38px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .toolbar-btn:hover { background: #f9fafb; border-color: #d1d5db; }
        .toolbar-btn.active { background: #f0fdfa; border-color: #0d9488; color: #0d9488; }
        
        /* Sort Dropdown */
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

        /* Filter Panel */
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 300px;
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
        .filter-panel-footer {
            padding: 14px 18px;
            background: #f9fafb;
            border-top: 1px solid #f3f4f6;
        }
        .reset-all-btn {
            width: 100%;
            height: 36px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: #374151;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .reset-all-btn:hover { background: #f3f4f6; border-color: #d1d5db; }

        /* Filter Badge */
        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d9488;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            border-radius: 50%;
            margin-left: 4px;
        }

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
        .filter-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
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

        /* Orders-style table */
        .orders-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .orders-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .orders-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; }
        .orders-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .orders-table tbody tr:hover { background: #f9fafb; }
        .orders-table tbody tr:last-child td { border-bottom: none; }

        [x-cloak] { display: none !important; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Clickable Row */
        .customer-row { cursor: pointer; transition: all 0.2s; }
        .customer-row:hover { background-color: #f8fafc !important; }
        .customer-row .actions { pointer-events: auto; }

        /* Tab Styles */
        .tab-btn { padding: 8px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; transition: all 0.2s; cursor: pointer; border: 1px solid transparent; margin-right: 8px; }
        .tab-btn.active { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
        .tab-btn:not(.active) { color: #6b7280; background: #f9fafb; }
        .tab-btn:hover:not(.active) { background: #f3f4f6; }
        .tab-content { min-height: 250px; }
        .history-item { padding: 10px 0; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; }
        .history-item:last-child { border-bottom: none; }

        /* Mobile Header */
        .mobile-header { display: none; }
        @media (max-width: 768px) {
            .mobile-header { display: flex; position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #fff; z-index: 60; padding: 0 20px; align-items: center; justify-content: space-between; border-bottom: 1px solid #e5e7eb; }
            .mobile-menu-btn { font-size: 24px; background: none; border: none; cursor: pointer; color: #1f2937; }
        }

        /* Print Styles */
        @media print {
            .sidebar, .mobile-header, .no-print, header, .search-box { display: none !important; }
            .main-content { margin-left: 0 !important; padding-top: 0 !important; }
            .dashboard-container { display: block !important; }
            .card { border: none !important; box-shadow: none !important; padding: 0 !important; }
            body { background: white !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th, td { border: 1px solid #ccc !important; padding: 8px 12px !important; font-size: 12px !important; }
            th { background: #f3f4f6 !important; font-weight: 700 !important; }
            .btn-action { display: none !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
            .print-header h2 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
            .print-header p { font-size: 12px; color: #6b7280; }
        }
        .print-header { display: none; }

        /* Modal Styles */
        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-height:88vh; overflow-y:auto; margin:16px; position:relative; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>



    <!-- Main Content -->
    <div class="main-content">

        <header>
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <h1 class="page-title">Customers Management</h1>
            </div>
        </header>

        <script>
            var searchDebounceTimer = null;

            function buildFilterURL(overrides = {}, isAjax = false) {
                const params = new URLSearchParams(window.location.search);
                params.delete('branch_id');
                const fields = {
                    search: () => document.getElementById('fp_search')?.value || '',
                    date_from: () => document.getElementById('fp_date_from')?.value || '',
                    date_to: () => document.getElementById('fp_date_to')?.value || '',
                    status_filter: () => document.getElementById('fp_status_filter')?.value || '',
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
                        const tc = document.getElementById('customersTableContainer');
                        if (tc) {
                            tc.innerHTML = data.table;
                            if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                                Alpine.initTree(tc);
                            }
                        }
                        const pc = document.getElementById('customersPagination');
                        if (pc) pc.innerHTML = data.pagination;
                        const bc = document.getElementById('filterBadgeContainer');
                        if (bc) bc.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
                        
                        window.dispatchEvent(new CustomEvent('filter-badge-update', { detail: { badge: data.badge } }));
                        const displayUrl = buildFilterURL(overrides, false);
                        window.history.replaceState({ path: displayUrl }, '', displayUrl);
                    }
                } catch (e) { console.error('Error updating table:', e); }
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

            /* var: safe when Turbo re-executes this inline script */
            var _activeSortKey = '<?php echo $sort_by; ?>';
            var _hasActiveFilters = <?php echo (!empty($search) || !empty($date_from) || !empty($date_to) || !empty($status_filter)) ? 'true' : 'false'; ?>;
            var _customerDetailsApiUrl = <?php echo json_encode(pf_admin_url('api_customer_details.php')); ?>;

            function customerModal() {
                return {
                    showModal: false,
                    loading: false,
                    errorMsg: '',
                    customer: null,
                    filterOpen: false,
                    sortOpen: false,
                    activeSort: _activeSortKey,
                    hasActiveFilters: _hasActiveFilters,

                    showTransactionModal: false,
                    transLoading: false,
                    transActiveTab: 'orders',
                    customerName: '',
                    tabLoading: false,
                    orders: [],
                    customizations: [],
                    ordersPagination: { current_page: 1, total_pages: 1 },
                    customizationsPagination: { current_page: 1, total_pages: 1 },

                    init() {
                        window.addEventListener('filter-badge-update', e => { this.hasActiveFilters = (e.detail.badge > 0); });
                        window.addEventListener('sort-changed', e => { this.activeSort = e.detail.sortKey; this.sortOpen = false; });
                    },

                    getCustomerFromRow(sourceEl, fallbackId = 0) {
                        let host = sourceEl?.dataset?.customer ? sourceEl : sourceEl?.closest?.('[data-customer]');
                        if (!host && fallbackId) {
                            host = document.querySelector('[data-customer-id="' + Number(fallbackId) + '"]');
                        }
                        if (!host?.dataset?.customer) return null;
                        try {
                            const parsed = JSON.parse(host.dataset.customer);
                            if (parsed && typeof parsed === 'object') {
                                if (!parsed.customer_id && fallbackId) parsed.customer_id = fallbackId;
                                return parsed;
                            }
                        } catch (err) {
                            console.error('Customer payload parse error:', err);
                        }
                        return null;
                    },

                    async fetchJsonResponse(url, options = {}) {
                        const fetchOptions = {
                            credentials: 'same-origin',
                            ...options,
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                ...(options.headers || {})
                            }
                        };
                        const response = await fetch(url, fetchOptions);
                        const raw = await response.text();
                        const body = raw.trim().replace(/^\uFEFF/, '');

                        if (!body) {
                            throw new Error('Empty server response');
                        }

                        let data = null;
                        try {
                            data = JSON.parse(body);
                        } catch (err) {
                            // Recover when server output includes notices/HTML before valid JSON.
                            let recovered = null;
                            const start = body.search(/[\[{]/);
                            const endObj = body.lastIndexOf('}');
                            const endArr = body.lastIndexOf(']');
                            const end = Math.max(endObj, endArr);
                            if (start !== -1 && end !== -1 && end >= start) {
                                const candidate = body.slice(start, end + 1).trim();
                                try {
                                    recovered = JSON.parse(candidate);
                                } catch (_ignored) {
                                    recovered = null;
                                }
                            }
                            if (recovered !== null) {
                                data = recovered;
                            } else {
                                console.error('Non-JSON response:', {
                                    url,
                                    status: response.status,
                                    snippet: body.slice(0, 500)
                                });
                                throw new Error('Response is not JSON');
                            }
                        }

                        if (!response.ok && !(data && data.success === false)) {
                            throw new Error(`Request failed (${response.status})`);
                        }

                        return data;
                    },

                    async openModal(customerId, sourceEl = null) {
                        this.showModal = true;
                        this.loading = true;
                        this.errorMsg = '';
                        this.customer = null;
                        const inlineCustomer = this.getCustomerFromRow(sourceEl, customerId);
                        if (inlineCustomer) {
                            this.customer = inlineCustomer;
                            this.loading = false;
                            return;
                        }

                        try {
                            const data = await this.fetchJsonResponse(_customerDetailsApiUrl + '?id=' + customerId);
                            this.loading = false;
                            if (data.success) {
                                this.customer = data.customer;
                            } else {
                                this.errorMsg = data.error || 'Failed to load details.';
                            }
                        } catch (err) {
                            this.loading = false;
                            const fallbackCustomer = this.getCustomerFromRow(null, customerId);
                            if (fallbackCustomer) {
                                this.customer = fallbackCustomer;
                                this.errorMsg = '';
                            } else {
                                this.errorMsg = 'Network error: ' + (err?.message || 'Unable to load customer details');
                            }
                        }
                    },

                    async openTransactionModal(id, sourceEl = null) {
                        this.showTransactionModal = true;
                        this.transLoading = true;
                        this.transActiveTab = 'orders';
                        this.customerName = '';
                        this.orders = [];
                        this.customizations = [];
                        const inlineCustomer = this.getCustomerFromRow(sourceEl, id);
                        if (inlineCustomer) {
                            this.customer = inlineCustomer;
                            this.customerName = (inlineCustomer.first_name || '') + ' ' + (inlineCustomer.last_name || '');
                            this.transLoading = false;
                            this.loadTransTabData('orders', 1);
                            return;
                        }

                        try {
                            const data = await this.fetchJsonResponse(_customerDetailsApiUrl + '?id=' + id);
                            this.transLoading = false;
                            if (data.success) {
                                this.customer = data.customer;
                                this.customerName = (data.customer.first_name || '') + ' ' + (data.customer.last_name || '');
                                this.loadTransTabData('orders', 1);
                            }
                        } catch (e) {
                            this.transLoading = false;
                            const fallbackCustomer = this.getCustomerFromRow(null, id);
                            if (fallbackCustomer) {
                                this.customer = fallbackCustomer;
                                this.customerName = (fallbackCustomer.first_name || '') + ' ' + (fallbackCustomer.last_name || '');
                                this.loadTransTabData('orders', 1);
                            } else {
                                console.error('Error:', e);
                            }
                        }
                    },

                    async loadTransTabData(tab, page = 1) {
                        if (!this.customer?.customer_id) return;
                        this.tabLoading = true;
                        try {
                            if (tab === 'orders') {
                                const res = await fetch(`<?php echo BASE_PATH; ?>/admin/api_order_details.php?customer_id=${this.customer.customer_id}&page=${page}`, { credentials: 'same-origin' });
                                const data = await res.json();
                                this.orders = data.data || [];
                                this.ordersPagination = data.pagination || { current_page: 1, total_pages: 1 };
                            } else if (tab === 'customizations') {
                                const res = await fetch(`<?php echo BASE_PATH; ?>/admin/job_orders_api.php?action=list_orders&customer_id=${this.customer.customer_id}&page=${page}`, { credentials: 'same-origin' });
                                const data = await res.json();
                                this.customizations = data.data || [];
                                this.customizationsPagination = data.pagination || { current_page: 1, total_pages: 1 };
                            }
                        } catch (e) { console.error(`Error loading ${tab}:`, e); } finally { this.tabLoading = false; }
                    },

                    getStatusBadge(status) {
                        const pc = { 'Pending': 'background:#fef9c3;color:#854d0e;', 'Paid': 'background:#dcfce7;color:#166534;', 'Failed': 'background:#fee2e2;color:#991b1b;', 'UNPAID': 'background:#fee2e2;color:#991b1b;', 'PARTIAL': 'background:#fef3c7;color:#b45309;', 'PAID': 'background:#dcfce7;color:#166534;' };
                        const sc = { 'Pending': 'background:#fef3c7;color:#92400e;', 'Pending Review': 'background:#fef3c7;color:#92400e;', 'To Pay': 'background:#fce7f3;color:#9d174d;', 'Processing': 'background:#dbeafe;color:#1e40af;', 'Ready for Pickup': 'background:#ede9fe;color:#5b21b6;', 'Completed': 'background:#dcfce7;color:#166534;', 'Cancelled': 'background:#fecaca;color:#b91c1c;', 'PENDING': 'background:#fef3c7;color:#92400e;', 'APPROVED': 'background:#dbeafe;color:#1e40af;', 'TO_PAY': 'background:#fce7f3;color:#9d174d;', 'IN_PRODUCTION': 'background:#d1fae5;color:#065f46;', 'TO_RECEIVE': 'background:#ede9fe;color:#5b21b6;', 'COMPLETED': 'background:#dcfce7;color:#166534;', 'CANCELLED': 'background:#fecaca;color:#b91c1c;' };
                        const style = pc[status] || sc[status] || 'background:#f3f4f6;color:#6b7280;';
                        const displayStatus = (status === 'Pending Review') ? 'Pending' : (status || 'N/A');
                        return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500;${style}">${displayStatus}</span>`;
                    }
                };
            }
            window.customerModal = customerModal;

            function callCustomerModalMethod(methodName, id, sourceEl) {
                function run() {
                    try {
                        var m = document.querySelector('main[x-data="customerModal()"]');
                        var st = m && m._x_dataStack;
                        if (st && st[0] && typeof st[0][methodName] === 'function') {
                            st[0][methodName](id, sourceEl);
                            return true;
                        }
                    } catch (e) { console.error(e); }
                    return false;
                }
                if (run()) return;
                printflowInitCustomersPage();
                if (run()) return;
                if (typeof Alpine !== 'undefined' && typeof Alpine.nextTick === 'function') {
                    Alpine.nextTick(function () { if (!run()) setTimeout(run, 50); });
                } else { setTimeout(run, 100); }
            }

            window.openModal = function (id, sourceEl) { callCustomerModalMethod('openModal', id, sourceEl); };
            window.openTransactionModal = function (id, sourceEl) { callCustomerModalMethod('openTransactionModal', id, sourceEl); };

            function autoOpenCustomerFromQuery() {
                if (window._pf_customer_auto_open_done) return;
                const params = new URLSearchParams(window.location.search);
                const customerId = parseInt(params.get('open_customer') || '0', 10);
                if (!customerId) return;
                window._pf_customer_auto_open_done = true;

                function tryOpen() {
                    const row = document.querySelector('[data-customer-id="' + customerId + '"]');
                    window.openModal(customerId, row || null);
                }

                if (typeof Alpine !== 'undefined' && typeof Alpine.nextTick === 'function') {
                    Alpine.nextTick(function () { setTimeout(tryOpen, 50); });
                } else {
                    setTimeout(tryOpen, 100);
                }
            }

            function printflowInitCustomersPage() {
                console.debug('[customers] Initializing...');
                var root = document.querySelector('main[x-data="customerModal()"]');
                if (root && !root._x_dataStack) { 
                    try { 
                        Alpine.initTree(root);
                        console.debug('[customers] Alpine initialized successfully');
                    } catch (e) { 
                        console.error('[customers] Alpine init error:', e); 
                    } 
                }
                /* #customersTableContainer has no x-data; initTree here double-binds after Alpine.start / turbo-init(.main-content). */
                        ['fp_date_from', 'fp_date_to', 'fp_status_filter'].forEach(id => {
                            const el = document.getElementById(id);
                    if (el && !el._pf_bound) {
                        el._pf_bound = true;
                        el.addEventListener('change', () => fetchUpdatedTable());
                    }
                });
                const searchInput = document.getElementById('fp_search');
                if (searchInput && !searchInput._pf_bound) {
                    searchInput._pf_bound = true;
                    searchInput.addEventListener('input', () => {
                        clearTimeout(searchDebounceTimer);
                        searchDebounceTimer = setTimeout(() => { fetchUpdatedTable(); }, 500);
                    });
                }
                autoOpenCustomerFromQuery();
            }
            
            // Initialize on page load
            if (document.readyState === 'loading') { 
                document.addEventListener('DOMContentLoaded', printflowInitCustomersPage); 
            } else { 
                printflowInitCustomersPage();
            }
        </script>

        <main x-data="customerModal()">
            <!-- Print Header (visible only when printing) -->
            <div class="print-header">
                <h2>PrintFlow - Customer List</h2>
                <p>Generated on <?php echo date('F j, Y g:i A'); ?> | Total Customers: <?php echo $total_customers; ?></p>
            </div>

            <!-- Messages -->
            <?php if (isset($success_msg)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4 no-print"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if (isset($error_msg)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 no-print"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <!-- KPI Summary Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label"><?php echo $viewerBranch ? 'Branch Customers' : 'Total Customers'; ?></div>
                    <div class="kpi-value"><?php echo number_format($total_customers); ?></div>
                    <div class="kpi-sub"><?php echo $viewerBranch ? 'Distinct customers' : 'Registered accounts'; ?></div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label"><?php echo $viewerBranch ? 'Returning Customers' : 'New This Month'; ?></div>
                    <div class="kpi-value"><?php echo number_format($new_this_month); ?></div>
                    <div class="kpi-sub"><?php echo $viewerBranch ? 'Repeat buyers this month' : 'Recent registrations'; ?></div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-label">Active Customers</div>
                    <div class="kpi-value"><?php echo number_format($active_30_days); ?></div>
                    <div class="kpi-sub">Ordered in last 30 days</div>
                </div>
                <div class="kpi-card violet">
                    <div class="kpi-label">Avg. Value</div>
                    <div class="kpi-value">₱<?php echo number_format((float)$avg_spent, 2); ?></div>
                    <div class="kpi-sub">Avg spent per customer</div>
                </div>
            </div>

            <!-- Customers Table -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                    <h3 style="font-size:16px; font-weight:700; color:#1f2937; margin:0;">Customers List</h3>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
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
                                     onclick="applySortFilter('<?php echo $key; ?>')">
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
                                    $active_filters_count = count(array_filter([$search, $date_from, $date_to, $status_filter], function($v) { return $v !== null && $v !== ''; }));
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
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from ?? ''); ?>">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Verification status</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['status_filter'])">Reset</button>
                                    </div>
                                    <select id="fp_status_filter" class="filter-input">
                                        <option value="">All statuses</option>
                                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Verified" <?php echo $status_filter === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>

                                <!-- Keyword Search -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" style="width: 100%;" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto" id="customersTableContainer">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Sign-in</th>
                                <th>Registered</th>
                                <th>Status</th>
                                <th style="text-align:right;" class="no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="customersTableBody">
                            <?php if (empty($customers)): ?>
                                <tr id="emptyCustomersRow">
                                    <td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No customers found</td>
                                </tr>
                            <?php else: ?>
                                <tr id="emptyCustomersRow" style="display:none;">
                                    <td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No customers found</td>
                                </tr>
                                <?php foreach ($customers as $customer):
                                    $status_display = pf_admin_id_verification_status_display($customer);
                                    $customer_payload_attr = pf_customer_payload_attr($customer, $base_path);
                                    $sign_in = pf_admin_customer_sign_in_label($customer);
                                    $status_style = $status_display['style'];
                                    $status_label = $status_display['label'];
                                ?>
                                    <tr class="customer-row" data-customer-id="<?php echo (int)$customer['customer_id']; ?>" data-customer="<?php echo $customer_payload_attr; ?>" onclick="openModal(<?php echo $customer['customer_id']; ?>, this)">
                                        <td style="color:#1f2937;"><?php echo $customer['customer_id']; ?></td>
                                        <td style="font-weight:500;color:#1f2937;" class="name-cell">
                                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>">
                                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                            </div>
                                        </td>
                                        <td class="email-cell" style="text-transform:lowercase;">
                                            <div style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>">
                                                <?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($customer['contact_number'] ?? 'N/A'); ?>">
                                                <?php echo htmlspecialchars($customer['contact_number'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($sign_in === 'Google'): ?>
                                                <span style="display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;background:#e8f0fe;color:#1967d2;">Google</span>
                                            <?php else: ?>
                                                <span style="display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;background:#f3f4f6;color:#4b5563;">Email</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color:#6b7280;font-size:12px;"><?php echo format_date($customer['created_at']); ?></td>
                                        <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $status_style; ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                                        <td style="text-align:right;" class="no-print actions" onclick="event.stopPropagation()">
                                            <button type="button" onclick="event.stopPropagation();openModal(<?php echo $customer['customer_id']; ?>, this.closest('tr'))" class="btn-action blue">Profile</button>
                                            <button type="button" onclick="event.stopPropagation();window.location.href='<?php echo $base_path; ?>/admin/customer_verification.php?open_customer=<?php echo (int)$customer['customer_id']; ?>'" class="btn-action amber">Verify</button>
                                            <button type="button" onclick="event.stopPropagation();openTransactionModal(<?php echo $customer['customer_id']; ?>, this.closest('tr'))" class="btn-action teal">Transactions</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="customersPagination">
                    <?php 
                    $pagination_params = array_filter(['search'=>$search, 'date_from'=>$date_from, 'date_to'=>$date_to, 'status_filter'=>$status_filter, 'sort'=>$sort_by], function($v) { return $v !== null && $v !== ''; });
                    echo render_pagination($page, $total_pages, $pagination_params); 
                    ?>
                </div>
            </div>

<!-- Customer Profile Modal (inside main x-data so Alpine can bind showModal / overlays do not block the page) -->
<div x-show="showModal" x-cloak>
    <div class="modal-overlay" @click.self="showModal = false">
        <div class="modal-panel" style="max-width: 650px;" @click.stop>
            <div x-show="loading" style="padding:48px;text-align:center;">
                <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                <p style="color:#6b7280;font-size:14px;">Loading customer profile...</p>
            </div>
            <div x-show="errorMsg && !loading" style="padding:32px;text-align:center;">
                <p style="color:#ef4444;font-size:14px;margin-bottom:12px;" x-text="errorMsg"></p>
                <button @click="showModal = false" class="btn-secondary">Close</button>
            </div>
            <div x-show="customer && !loading">
                <!-- Header -->
                <div style="padding:20px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                    <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0;">Customer Profile</h3>
                    <button @click="showModal = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                
                <!-- Customer Info -->
                <div style="padding:24px;border-bottom:1px solid #f3f4f6;">
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
                        <template x-if="customer?.profile_picture">
                            <img :src="customer.profile_picture" alt="Customer" style="width:56px;height:56px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        </template>
                        <template x-if="!customer?.profile_picture">
                            <div x-text="customer?.initial" style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:20px;"></div>
                        </template>
                        <div style="flex:1;">
                            <div x-text="(customer?.first_name || '') + (customer?.middle_name ? ' ' + customer.middle_name : '') + ' ' + (customer?.last_name || '')" style="font-size:20px;font-weight:700;color:#1f2937;margin-bottom:8px;"></div>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;font-size:13px;">
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">First Name *</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.first_name || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Middle Name</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.middle_name || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Last Name *</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.last_name || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Email</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.email || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Sign-in</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.sign_in || 'Email / password'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Contact Number</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.contact_number || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Date of Birth</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.dob || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Gender</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.gender || 'N/A'"></span>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;display:block;">Registered</label>
                            <span style="color:#1f2937;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;display:block;" x-text="customer?.created_at || 'N/A'"></span>
                        </div>
                    </div>
                    
                    <!-- Address Section -->
                    <div style="margin-top:20px;">
                        <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;display:block;">Address</label>
                        <div style="background:#f9fafb;border-radius:8px;padding:12px;border:1px solid #f3f4f6;">
                            <p style="color:#1f2937;font-weight:500;font-size:13px;margin:0;line-height:1.6;" x-text="customer?.address || 'No address provided'"></p>
                        </div>
                    </div>

                    <!-- ID Verification Section -->
                    <div style="margin-top:20px;padding-top:20px;border-top:1px solid #f3f4f6;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;">ID Verification</label>
                            <template x-if="customer">
                                <span :style="!customer?.id_image ? 'background:#f3f4f6;color:#6b7280;' : ((customer.id_status === 'Verified') ? 'background:#dcfce7;color:#166534;' : ((customer.id_status === 'Rejected') ? 'background:#fee2e2;color:#991b1b;' : 'background:#fef3c7;color:#92400e;'))" style="display:inline-flex;align-items:center;justify-content:center;padding:7px 16px;border-radius:9999px;font-size:12px;font-weight:500;line-height:1;border:none;box-shadow:none;" x-text="customer?.id_status_label || '—'"></span>
                            </template>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0 0 12px;">
                            <div>
                                <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin:0 0 4px;">ID Type</p>
                                <p style="font-size:13px;color:#1f2937;font-weight:600;margin:0;" x-text="customer?.id_type || 'Not provided'"></p>
                            </div>
                            <div>
                                <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin:0 0 4px;">Current Status</p>
                                <p style="font-size:13px;color:#1f2937;font-weight:600;margin:0;" x-text="customer?.id_status_label || '—'"></p>
                            </div>
                        </div>

                        <div x-show="customer?.id_image" style="margin-bottom:14px;">
                            <a :href="customer?.id_image" target="_blank" rel="noopener">
                                <img :src="customer?.id_image" alt="Customer ID" style="width:100%;max-height:200px;object-fit:contain;border-radius:8px;border:1px solid #e5e7eb;cursor:zoom-in;">
                            </a>
                        </div>

                        <p x-show="!customer?.id_image" style="font-size:13px;color:#9ca3af;font-style:italic;margin:0 0 14px;">No ID image uploaded.</p>

                        <p x-show="customer?.id_reject_reason" style="font-size:12px;color:#dc2626;margin:0 0 12px;">Rejection reason: <span x-text="customer?.id_reject_reason"></span></p>

                        <p x-show="customer?.id_status === 'Verified'" style="font-size:12px;color:#16a34a;font-weight:600;margin:8px 0 0;">&#10003; ID Verified</p>
                        <div style="margin-top:12px;">
                            <a :href="'<?php echo $base_path; ?>/admin/customer_verification.php?open_customer=' + (customer?.customer_id || '')" class="btn-action amber" style="display:inline-flex;">Manage verification</a>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;">
                    <button @click="showModal = false" class="btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Customer Transactions Modal -->
<div x-show="showTransactionModal" x-cloak>
    <div class="modal-overlay" @click.self="showTransactionModal = false">
        <div class="modal-panel" style="max-width: 650px;" @click.stop>
            <div x-show="transLoading" style="padding:48px;text-align:center;">
                <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                <p style="color:#6b7280;font-size:14px;">Loading transactions...</p>
            </div>
            
            <div x-show="!transLoading">
                <!-- Header -->
                <div style="padding:20px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0;">Customer Transactions</h3>
                        <p style="font-size:13px;color:#6b7280;margin:2px 0 0 0;" x-text="customerName"></p>
                    </div>
                    <button @click="showTransactionModal = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- Tabs -->
                <div style="padding:16px 24px 0;">
                    <div style="display:flex;border-bottom:2px solid #f3f4f6;margin-bottom:16px;">
                        <button class="tab-btn" :class="{ 'active': transActiveTab === 'orders' }" @click="transActiveTab = 'orders'; loadTransTabData('orders', 1)">Orders</button>
                        <button class="tab-btn" :class="{ 'active': transActiveTab === 'customizations' }" @click="transActiveTab = 'customizations'; loadTransTabData('customizations', 1)">Customizations</button>
                    </div>

                    <div class="tab-content" style="padding-bottom:16px;min-height:300px;">
                        <div x-show="tabLoading" style="padding:32px;text-align:center;">
                            <div style="width:24px;height:24px;border:2px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.6s linear infinite;margin:0 auto 8px;"></div>
                            <p style="font-size:12px;color:#9ca3af;">Loading...</p>
                        </div>

                        <!-- Orders Tab -->
                        <div x-show="transActiveTab === 'orders' && !tabLoading">
                            <template x-if="orders.length === 0">
                                <p style="text-align:center;padding:40px;color:#9ca3af;font-size:13px;">No orders found.</p>
                            </template>
                            <template x-for="order in (orders || [])" :key="order.order_id">
                                <div class="history-item" style="padding:12px; border:1px solid #f3f4f6; border-radius:8px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; cursor:pointer; transition:all 0.2s;" @click="window.location.href='<?php echo $base_path; ?>/admin/orders_management.php?open_order=' + order.order_id" @mouseenter="$el.style.background='#f9fafb'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.background=''; $el.style.borderColor='#f3f4f6'">
                                    <div>
                                        <div style="font-weight:600;color:#3b82f6;font-size:13px;margin-bottom:4px;" x-text="order.order_code"></div>
                                        <div style="font-size:12px;color:#6b7280;" x-text="new Date(order.order_date).toLocaleDateString()"></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-weight:600;color:#1f2937;font-size:13px;" x-text="'₱' + parseFloat(order.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></div>
                                        <div style="font-size:11px;" x-html="getStatusBadge(order.status)"></div>
                                    </div>
                                </div>
                            </template>
                            <!-- Orders Pagination -->
                            <div x-show="ordersPagination && ordersPagination.total_pages > 1" style="margin-top:16px;display:flex;align-items:center;justify-content:center;gap:8px;">
                                <template x-if="ordersPagination">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <button x-show="ordersPagination.current_page > 1" @click="loadTransTabData('orders', ordersPagination.current_page - 1)" style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;cursor:pointer;font-size:12px;">Previous</button>
                                        <span style="font-size:12px;color:#6b7280;" x-text="'Page ' + ordersPagination.current_page + ' of ' + ordersPagination.total_pages"></span>
                                        <button x-show="ordersPagination.current_page < ordersPagination.total_pages" @click="loadTransTabData('orders', ordersPagination.current_page + 1)" style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;cursor:pointer;font-size:12px;">Next</button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Customizations Tab -->
                        <div x-show="transActiveTab === 'customizations' && !tabLoading">
                            <template x-if="customizations.length === 0">
                                <p style="text-align:center;padding:40px;color:#9ca3af;font-size:13px;">No customizations found.</p>
                            </template>
                            <template x-for="custom in (customizations || [])" :key="custom.id">
                                <div class="history-item" style="padding:12px; border:1px solid #f3f4f6; border-radius:8px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; cursor:pointer; transition:all 0.2s;" @click="window.location.href='<?php echo $base_path; ?>/admin/customizations.php?open_job=' + custom.id" @mouseenter="$el.style.background='#f9fafb'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.background=''; $el.style.borderColor='#f3f4f6'">
                                    <div>
                                        <div style="font-weight:600;color:#3b82f6;font-size:13px;margin-bottom:4px;" x-text="'Customization #' + custom.id"></div>
                                        <div style="font-size:12px;color:#6b7280;" x-text="custom.service_type"></div>
                                        <div style="font-size:12px;color:#6b7280;" x-text="new Date(custom.created_at).toLocaleDateString()"></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-weight:600;color:#1f2937;font-size:13px;" x-text="custom.estimated_total ? '₱' + parseFloat(custom.estimated_total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'Pending'"></div>
                                        <div style="font-size:11px;" x-html="getStatusBadge(custom.status)"></div>
                                    </div>
                                </div>
                            </template>
                            <!-- Customizations Pagination -->
                            <div x-show="customizationsPagination && customizationsPagination.total_pages > 1" style="margin-top:16px;display:flex;align-items:center;justify-content:center;gap:8px;">
                                <template x-if="customizationsPagination">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <button x-show="customizationsPagination.current_page > 1" @click="loadTransTabData('customizations', customizationsPagination.current_page - 1)" style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;cursor:pointer;font-size:12px;">Previous</button>
                                        <span style="font-size:12px;color:#6b7280;" x-text="'Page ' + customizationsPagination.current_page + ' of ' + customizationsPagination.total_pages"></span>
                                        <button x-show="customizationsPagination.current_page < customizationsPagination.total_pages" @click="loadTransTabData('customizations', customizationsPagination.current_page + 1)" style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;cursor:pointer;font-size:12px;">Next</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;">
                    <button type="button" @click="showTransactionModal = false" class="btn-secondary">Close</button>
                </div>
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
