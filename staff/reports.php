<?php
/**
 * Staff Reports - Visual Analytics Dashboard
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role('Staff');
printflow_require_staff_module('reports');
require_once __DIR__ . '/../includes/staff_pending_check.php';

$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
$staffOrderScopeSql = printflow_staff_order_source_sql('o');
$range = $_GET['range'] ?? 'week';
$report_date = $_GET['date'] ?? date('Y-m-d');

if ($range === 'month') {
    $interval_label = 'This Month';
    $group_by = "DATE(o.order_date)";
    $date_condition = "YEAR(o.order_date) = YEAR(CURDATE()) AND MONTH(o.order_date) = MONTH(CURDATE())";
} elseif ($range === 'today') {
    $interval_label = 'Today';
    $group_by = "HOUR(o.order_date)";
    $date_condition = "DATE(o.order_date) = CURDATE()";
} else {
    $range = 'week';
    $interval_label = 'This Week';
    $group_by = "DATE(o.order_date)";
    $date_condition = "YEARWEEK(o.order_date, 1) = YEARWEEK(CURDATE(), 1)";
}

$status_filter = $_GET['status'] ?? 'ALL';
$status_where = "";
$status_p = [];
$status_t = "";
if ($status_filter !== 'ALL' && !empty($status_filter)) {
    $status_where = " AND o.status = ? ";
    $status_p = [$status_filter];
    $status_t = "s";
}
function staff_reports_service_status_sql(string $status_filter): string {
    if ($status_filter === 'ALL' || $status_filter === '') return " AND so.status NOT IN ('Cancelled', 'Rejected')";
    if ($status_filter === 'Pending') return " AND so.status IN ('Pending', 'Pending Review', 'Pending Approval', 'For Revision')";
    if ($status_filter === 'Processing') return " AND so.status IN ('Processing', 'In Production', 'Printing')";
    if ($status_filter === 'Ready for Pickup') return " AND so.status IN ('Ready for Pickup', 'Ready For Pickup')";
    if ($status_filter === 'Cancelled') return " AND so.status IN ('Cancelled', 'Rejected')";
    return " AND so.status = '" . db_escape($status_filter) . "'";
}
$service_status_sql = staff_reports_service_status_sql($status_filter);
function staff_reports_job_status_sql(string $status_filter): string {
    if ($status_filter === 'ALL' || $status_filter === '') return " AND jo.status != 'CANCELLED'";
    if ($status_filter === 'Pending') return " AND jo.status = 'PENDING'";
    if ($status_filter === 'Processing') return " AND jo.status = 'IN_PRODUCTION'";
    if ($status_filter === 'Ready for Pickup') return " AND jo.status = 'TO_RECEIVE'";
    if ($status_filter === 'Completed') return " AND jo.status = 'COMPLETED'";
    if ($status_filter === 'Cancelled') return " AND jo.status = 'CANCELLED'";
    if ($status_filter === 'Approved') return " AND jo.status = 'APPROVED'";
    if ($status_filter === 'To Pay') return " AND jo.status = 'TO_PAY'";
    return " AND jo.status = '" . db_escape(strtoupper($status_filter)) . "'";
}
$job_status_sql = staff_reports_job_status_sql($status_filter);

// ---- 1. RANGE-AWARE KPI METRICS (DYNAMIC) ----
// Total revenue for THE SELECTED PERIOD (Paid only)
$rev_res = db_query("SELECT SUM(t.total) AS total FROM (
    SELECT COALESCE(SUM(o.total_amount), 0) as total FROM orders o WHERE $date_condition AND o.payment_status = 'Paid' AND o.branch_id = ? AND {$staffOrderScopeSql} $status_where
    UNION ALL
    SELECT COALESCE(SUM(so.total_price), 0) as total FROM service_orders so WHERE " . str_replace('o.order_date', 'so.created_at', $date_condition) . " AND (so.branch_id = ? OR so.branch_id IS NULL) {$service_status_sql}
) t", ($status_t ? "ii" . $status_t : "ii"), array_merge([$staffBranchId, $staffBranchId], $status_p));
$period_revenue = (float)($rev_res[0]['total'] ?? 0);

// Total orders count for THE SELECTED PERIOD
$ord_res = db_query("SELECT SUM(t.cnt) AS count FROM (
    SELECT COUNT(*) as cnt FROM orders o WHERE $date_condition AND o.branch_id = ? AND {$staffOrderScopeSql} $status_where
    UNION ALL
    SELECT COUNT(*) as cnt FROM service_orders so WHERE " . str_replace('o.order_date', 'so.created_at', $date_condition) . " AND (so.branch_id = ? OR so.branch_id IS NULL) {$service_status_sql}
) t", ($status_t ? "ii" . $status_t : "ii"), array_merge([$staffBranchId, $staffBranchId], $status_p));
$period_orders = (int)($ord_res[0]['count'] ?? 0);

// Pending/Active orders received in THE SELECTED PERIOD (if status is not filtered specifically)
$active_statuses_sql = "status IN ('Pending', 'Pending Review', 'Pending Verification', 'Approved', 'Downpayment Submitted', 'In Production')";
if ($status_filter !== 'ALL') {
    $pend_res = db_query("SELECT COUNT(*) as count FROM orders o WHERE o.status = ? AND o.branch_id = ? AND {$staffOrderScopeSql} AND $date_condition", 'si', [$status_filter, $staffBranchId]);
} else {
    $pend_res = db_query("SELECT COUNT(*) as count FROM orders o WHERE $active_statuses_sql AND o.branch_id = ? AND {$staffOrderScopeSql} AND $date_condition", 'i', [$staffBranchId]);
}
$pending_period_orders = (int)($pend_res[0]['count'] ?? 0);

// GLOBAL Backlog (All pending/active orders ever)
$global_back_res = db_query("SELECT SUM(t.cnt) AS count FROM (
    SELECT COUNT(*) as cnt FROM orders o WHERE o.status NOT IN ('Completed', 'Cancelled') AND o.branch_id = ? AND {$staffOrderScopeSql} $status_where
    UNION ALL
    SELECT COUNT(*) as cnt FROM service_orders so WHERE so.status NOT IN ('Completed', 'Cancelled', 'Rejected') AND (so.branch_id = ? OR so.branch_id IS NULL) {$service_status_sql}
) t", ($status_t ? "ii" . $status_t : "ii"), array_merge([$staffBranchId, $staffBranchId], $status_p));
$global_backlog = (int)($global_back_res[0]['count'] ?? 0);

// Low stock finished goods alert (This is ALWAYS current status)
$stock_res = db_query("SELECT COUNT(*) as count FROM products WHERE status = 'Activated' AND stock_quantity < 20");
$low_stock_count = $stock_res[0]['count'] ?? 0;

// ---- 2. REVENUE TREND (DYNAMIC) ----
$trend_res = db_query("
    SELECT $group_by as dte, COALESCE(SUM(total_amount), 0) as daily_total 
    FROM orders o 
    WHERE $date_condition AND branch_id = ?
    AND {$staffOrderScopeSql}
    $status_where
    GROUP BY dte
    ORDER BY dte ASC
", ($status_t ? "i" . $status_t : "i"), array_merge([$staffBranchId], $status_p));

// Fill empty data points so the chart doesn't break
$trend_dates = [];
$trend_totals = [];

if ($range === 'today') {
    for ($i = 0; $i <= date('H'); $i++) {
        $h_str = str_pad($i, 2, '0', STR_PAD_LEFT);
        $trend_dates[] = $h_str . ':00';
        $found = 0;
        foreach ($trend_res as $r) { if ((int)$r['dte'] === $i) { $found = (float)$r['daily_total']; break; } }
        $trend_totals[] = $found;
    }
} elseif ($range === 'month') {
    $days_in_month = (int)date('d'); // Plot up to today
    $current_month_prefix = date('Y-m');
    for ($i = 1; $i <= $days_in_month; $i++) {
        $day_str = str_pad($i, 2, '0', STR_PAD_LEFT);
        $date_str = $current_month_prefix . '-' . $day_str;
        $trend_dates[] = date('M d', strtotime($date_str));
        
        $found = 0;
        foreach ($trend_res as $r) { if ($r['dte'] === $date_str) { $found = (float)$r['daily_total']; break; } }
        $trend_totals[] = $found;
    }
} else {
    $monday = strtotime('monday this week');
    $days_to_plot = (int)date('N', strtotime('today')); // 1=Mon, 7=Sun
    for ($i = 0; $i < $days_to_plot; $i++) {
        $date_str = date('Y-m-d', strtotime("+$i days", $monday));
        $trend_dates[] = date('D', strtotime($date_str));
        
        $found = 0;
        foreach ($trend_res as $r) { if ($r['dte'] === $date_str) { $found = (float)$r['daily_total']; break; } }
        $trend_totals[] = $found;
    }
}

// ---- 3. ORDER STATUS DISTRIBUTION (FIXED LABELS) ----
$std_statuses = [
    'Pending', 'Processing', 'Ready for Pickup', 'Completed', 'Cancelled',
    'Pending Review', 'Approved', 'Downpayment Submitted', 'To Pay'
];

$status_res = db_query("
    SELECT status, COUNT(*) as status_count 
    FROM orders o
    WHERE $date_condition AND branch_id = ?
    AND {$staffOrderScopeSql}
    GROUP BY status
", 'i', [$staffBranchId]);

$status_map = [];
foreach ($status_res as $s) {
    if ($s['status']) $status_map[$s['status']] = (int)$s['status_count'];
}

$status_labels = $std_statuses;
$status_counts = array_map(fn($s) => $status_map[$s] ?? 0, $std_statuses);

// Use 'No Data Yet' only if EVERYTHING is zero across the period
if (array_sum($status_counts) === 0) {
    $status_labels = ['No Data Yet'];
    $status_counts = [1];
}

// ---- 4. TOP 5 BEST SELLING PRODUCTS (DYNAMIC) ----
$top_products = db_query("
    SELECT p.name, SUM(oi.quantity) as total_sold
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN products p ON oi.product_id = p.product_id
    WHERE $date_condition AND o.branch_id = ?
    AND {$staffOrderScopeSql}
    GROUP BY oi.product_id
    ORDER BY total_sold DESC
    LIMIT 5
", 'i', [$staffBranchId]);

// ---- 5. TOP 5 BEST SELLING SERVICES / CUSTOMIZATIONS (DYNAMIC) ----
$top_services_map = [];

$job_service_rows = db_query("
    SELECT COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization') AS service_name,
           SUM(COALESCE(jo.quantity, 1)) AS total_sold
    FROM job_orders jo
    WHERE " . str_replace('o.order_date', 'jo.created_at', $date_condition) . "
      AND jo.branch_id = ?
      {$job_status_sql}
    GROUP BY COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization')
    ORDER BY total_sold DESC
", 'i', [$staffBranchId]);

foreach ($job_service_rows as $row) {
    $service_name = trim((string)($row['service_name'] ?? ''));
    if ($service_name === '') {
        $service_name = 'Customization';
    }
    $service_key = function_exists('mb_strtolower')
        ? mb_strtolower($service_name, 'UTF-8')
        : strtolower($service_name);
    $top_services_map[$service_key] = [
        'name' => $service_name,
        'total_sold' => (int)($row['total_sold'] ?? 0),
    ];
}

$service_order_rows = db_query("
    SELECT COALESCE(NULLIF(TRIM(so.service_name), ''), 'Service') AS service_name,
           COUNT(*) AS total_sold
    FROM service_orders so
    WHERE " . str_replace('o.order_date', 'so.created_at', $date_condition) . "
      AND (so.branch_id = ? OR so.branch_id IS NULL)
      {$service_status_sql}
    GROUP BY COALESCE(NULLIF(TRIM(so.service_name), ''), 'Service')
    ORDER BY total_sold DESC
", 'i', [$staffBranchId]);

foreach ($service_order_rows as $row) {
    $service_name = trim((string)($row['service_name'] ?? ''));
    if ($service_name === '') {
        $service_name = 'Service';
    }
    $service_key = function_exists('mb_strtolower')
        ? mb_strtolower($service_name, 'UTF-8')
        : strtolower($service_name);
    if (!isset($top_services_map[$service_key])) {
        $top_services_map[$service_key] = [
            'name' => $service_name,
            'total_sold' => 0,
        ];
    }
    $top_services_map[$service_key]['total_sold'] += (int)($row['total_sold'] ?? 0);
}

$top_services = array_values($top_services_map);
usort($top_services, static fn($a, $b) => ($b['total_sold'] <=> $a['total_sold']));
$top_services = array_slice($top_services, 0, 5);
$page_title = 'Visual Reports & Analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .rpt-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        @media (max-width: 1024px) { .rpt-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px) { .rpt-kpi-grid { grid-template-columns: 1fr; } }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr; /* 2/3 width for primary chart, 1/3 for secondary */
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
        .top-lists-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
            margin-bottom: 24px;
            align-items: stretch;
        }
        @media (max-width: 1024px) { .top-lists-grid { grid-template-columns: 1fr; } }

        /* Split Export Button Styles */
        .btn-excel-split { transition: transform 0.2s; }
        .btn-excel-split:hover { transform: translateY(-1px); }
        .btn-excel-split:hover span:first-child { background: #1f2937 !important; }
        .btn-excel-split:hover span:last-child { background: #058f8f !important; }
        
        .chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .chart-title { font-size: 16px; font-weight: 800; color: #0f172a; }
        .chart-subtitle { font-size: 13px; color: #64748b; font-weight: 600; }
        
        .chart-container-large { position: relative; height: 350px; width: 100%; }
        .chart-container-small { position: relative; height: 380px; width: 100%; padding-bottom: 20px; }

        /* Top Products List Styling */
        .top-list-card { min-height: 560px; display: flex; flex-direction: column; }
        .top-list-body { flex: 1; display: flex; flex-direction: column; }
        .top-product-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .top-product-row:last-child { border-bottom: none; }
        .tp-name { font-size: 14px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .tp-rank { width: 24px; height: 24px; background: #f1f5f9; color: #475569; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; }
        .tp-sold { font-size: 14px; font-weight: 800; color: #059669; }
        .top-list-empty { flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; color: #94a3b8; font-size: 14px; }
        .top-list-empty { flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; color: #94a3b8; font-size: 14px; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <div class="main-content" x-data="{ filterOpen: false, activeStatus: '<?php echo $_GET['status'] ?? 'ALL'; ?>', activeRange: '<?php echo $range; ?>', hasActiveFilters: <?php echo (($_GET['status']??'ALL') !== 'ALL' || $range !== 'week') ? 'true' : 'false'; ?> }">
        <header style="display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 24px !important; flex-wrap: wrap !important; gap: 16px !important; width: 100% !important;">
            <div style="flex: 0 1 auto !important;">
                <h1 class="page-title">Visual Reports & Analytics</h1>
                <p class="page-subtitle">A quick overview of business performance and metrics.</p>
            </div>
            
            <div class="toolbar-group" style="display: flex !important; gap: 10px !important; align-items: center !important; margin-left: auto !important; justify-content: flex-end !important;">
                <!-- Filter Button -->
                <div style="position:relative;">
                    <button class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen" style="height: 38px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filter
                        <template x-if="hasActiveFilters">
                            <span class="filter-badge"><?php echo (($_GET['status']??'ALL')!=='ALL' ? 1 : 0) + ($range!=='week' ? 1 : 0); ?></span>
                        </template>
                    </button>

                    <!-- Filter Panel -->
                    <div class="dropdown-panel filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                        <form id="reports-filter-form" method="GET" action="reports.php">
                            <div class="filter-header">
                                <span>Filter Analytics</span>
                                <button type="button" class="filter-close-btn" @click="filterOpen = false">×</button>
                            </div>
                            
                            <!-- Status -->
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-label" style="margin:0;">Status</span>
                                    <button type="button" @click="activeStatus = 'ALL'; document.getElementById('reports-filter-form').submit()" class="filter-reset-link">Reset</button>
                                </div>
                                <select name="status" class="filter-select" x-model="activeStatus" @change="document.getElementById('reports-filter-form').submit()">
                                    <option value="ALL">All Statuses</option>
                                    <?php 
                                    $all_opts = ['Pending', 'Processing', 'Ready for Pickup', 'Completed', 'Cancelled', 'Pending Review', 'Approved', 'Downpayment Submitted', 'To Pay'];
                                    foreach($all_opts as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Range -->
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-label" style="margin:0;">Time Range</span>
                                    <button type="button" @click="activeRange = 'week'; document.getElementById('reports-filter-form').submit()" class="filter-reset-link">Reset</button>
                                </div>
                                <select name="range" class="filter-select" x-model="activeRange" @change="document.getElementById('reports-filter-form').submit()">
                                    <option value="today">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                </select>
                            </div>

                            <div class="filter-footer">
                                <a href="reports.php" class="filter-btn-reset" style="display:flex; align-items:center; justify-content:center; text-decoration:none; width: 100%;">Reset all filters</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Export Buttons Group -->
                <div style="display: flex; gap: 8px;">
                    <a href="export_reports.php?range=<?php echo $range; ?>&status=<?php echo $_GET['status'] ?? 'ALL'; ?>" class="toolbar-btn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; color:#fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); height: 38px; font-weight: 700;">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="stroke-width: 2;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Excel
                    </a>
                    
                    <a href="export_order_summary_pdf.php?range=<?php echo $range; ?>&status=<?php echo $_GET['status'] ?? 'ALL'; ?>" class="toolbar-btn" style="background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border: none; color:#fff; box-shadow: 0 4px 12px rgba(244, 63, 94, 0.2); height: 38px; font-weight: 700;" target="_blank">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="stroke-width: 2;"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        PDF
                    </a>
                </div>
            </div>
        </header>

        <main>
            <!-- ROW 1: QUICK PERFORMANCE METRICS -->
            <div class="kpi-row">
                <div class="kpi-card emerald">
                    <span class="kpi-card-inner">
                        <span class="kpi-label"><?php echo $interval_label; ?> Revenue</span>
                        <span class="kpi-value"><?php echo format_currency($period_revenue); ?></span>
                        <span class="kpi-sub">Total branch earnings</span>
                    </span>
                </div>
                <div class="kpi-card blue">
                    <span class="kpi-card-inner">
                        <span class="kpi-label"><?php echo $interval_label; ?> Orders</span>
                        <span class="kpi-value"><?php echo $period_orders; ?> <small style="font-size:14px;color:#94a3b8;font-weight:600;">orders</small></span>
                        <span class="kpi-sub">Requests received</span>
                    </span>
                </div>
                <div class="kpi-card amber">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Active Jobs</span>
                        <span class="kpi-value"><?php echo $pending_period_orders; ?></span>
                        <span class="kpi-sub"><?php echo $global_backlog; ?> total backlog</span>
                    </span>
                </div>
                <div class="kpi-card rose">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Low Stock</span>
                        <span class="kpi-value"><?php echo $low_stock_count; ?> <small style="font-size:14px;color:#94a3b8;font-weight:600;">items</small></span>
                        <span class="kpi-sub">Finished goods alert</span>
                    </span>
                </div>
            </div>

            <!-- ROW 2: VISUAL CHARTS -->
            <div class="dashboard-grid">
                
                <!-- Main Line Chart: Revenue Trend -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Revenue Trend</div>
                            <div class="chart-subtitle">
                                <?php 
                                    if ($range === 'month') echo "Income over this month";
                                    elseif ($range === 'today') echo "Income generated today";
                                    else echo "Income over this week"; 
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container-large">
                        <canvas id="revenueLineChart"></canvas>
                    </div>
                </div>

                <!-- Secondary Chart: Order Status Doughnut -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Order Status</div>
                            <div class="chart-subtitle">
                                <?php 
                                    if ($range === 'month') echo "Distribution over this month";
                                    elseif ($range === 'today') echo "Distribution for today";
                                    else echo "Distribution over this week"; 
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container-small">
                        <canvas id="statusDoughnutChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ROW 3: LISTS & INSIGHTS -->
            <div class="top-lists-grid">
                
                <!-- Top Selling Products -->
                <div class="chart-card top-list-card">
                    <div class="chart-header" style="margin-bottom:12px;">
                        <div>
                            <div class="chart-title">Top Selling Products</div>
                            <div class="chart-subtitle">
                                <?php 
                                    if ($range === 'month') echo "Most popular items ordered this month";
                                    elseif ($range === 'today') echo "Most popular items ordered today";
                                    else echo "Most popular items ordered this week"; 
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="top-list-body">
                        <?php if (!empty($top_products)): ?>
                            <?php $rank = 1; foreach ($top_products as $tp): ?>
                            <div class="top-product-row">
                                <div class="tp-name truncate-ellipsis" title="<?php echo htmlspecialchars($tp['name']); ?>">
                                    <span class="tp-rank">#<?php echo $rank++; ?></span>
                                    <?php echo htmlspecialchars($tp['name']); ?>
                                </div>
                                <div class="tp-sold">
                                    <?php echo (int)$tp['total_sold']; ?> <span style="font-size:12px;color:#64748b;font-weight:600;">sold</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="top-list-empty">No products sold for this selected period.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Selling Services -->
                <div class="chart-card top-list-card">
                    <div class="chart-header" style="margin-bottom:12px;">
                        <div>
                            <div class="chart-title">Top Selling Services</div>
                            <div class="chart-subtitle">
                                <?php 
                                    if ($range === 'month') echo "Most popular customized products and services this month";
                                    elseif ($range === 'today') echo "Most popular customized products and services today";
                                    else echo "Most popular customized products and services this week"; 
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="top-list-body">
                        <?php if (!empty($top_services)): ?>
                            <?php $rank = 1; foreach ($top_services as $service): ?>
                            <div class="top-product-row">
                                <div class="tp-name truncate-ellipsis" title="<?php echo htmlspecialchars($service['name']); ?>">
                                    <span class="tp-rank">#<?php echo $rank++; ?></span>
                                    <?php echo htmlspecialchars($service['name']); ?>
                                </div>
                                <div class="tp-sold">
                                    <?php echo (int)$service['total_sold']; ?> <span style="font-size:12px;color:#64748b;font-weight:600;">sold</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="top-list-empty">No customized products or services found for this selected period.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- ==========================================
     INITIALIZE CHART.JS VISUALIZATIONS 
=========================================== -->
<script>
/**
 * Global variables to store chart instances.
 * Using 'var' to prevent SyntaxError: Identifier '...' has already been declared
 * when Turbo re-executes this script on navigation.
 */
var revenueChartInstance = null;
var statusChartInstance = null;

function renderReportsCharts() {
    // ⚙️ Global Chart.js Defaults
    Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
    Chart.defaults.color = "#64748b";

    // --- 1. REVENUE LINE CHART ---
    const revCanvas = document.getElementById('revenueLineChart');
    if (revCanvas) {
        const revCtx = revCanvas.getContext('2d');
        if (revenueChartInstance && typeof revenueChartInstance.destroy === 'function') {
            revenueChartInstance.destroy();
        }

        const gradient = revCtx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(6, 161, 161, 0.4)');
        gradient.addColorStop(1, 'rgba(6, 161, 161, 0.0)');

        revenueChartInstance = new Chart(revCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_dates); ?>,
                datasets: [{
                    label: 'Total Revenue (₱)',
                    data: <?php echo json_encode($trend_totals); ?>,
                    borderColor: '#06A1A1',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#06A1A1',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 12,
                        titleFont: { size: 13 },
                        bodyFont: { size: 14, weight: 'bold' },
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return '₱ ' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9', drawBorder: false },
                        ticks: {
                            callback: function(value) { return '₱' + value.toLocaleString(); }
                        }
                    },
                    x: {
                        grid: { display: false, drawBorder: false }
                    }
                }
            }
        });
    }

    // --- 2. ORDER STATUS DOUGHNUT CHART ---
    const statusCanvas = document.getElementById('statusDoughnutChart');
    if (statusCanvas) {
        const statusCtx = statusCanvas.getContext('2d');
        if (statusChartInstance && typeof statusChartInstance.destroy === 'function') {
            statusChartInstance.destroy();
        }

        const statusColors = {
            'Pending': '#fef08a',
            'Pending Review': '#fde047',
            'Approved': '#86efac',
            'Downpayment Submitted': '#67e8f9',
            'Processing': '#3b82f6',
            'In Production': '#2563eb',
            'Ready for Pickup': '#a855f7',
            'Completed': '#22c55e',
            'Cancelled': '#ef4444',
            'No Data Yet': '#e2e8f0'
        };

        const rawLabels = <?php echo json_encode($status_labels); ?>;
        const rawData = <?php echo json_encode($status_counts); ?>;
        const bgColors = rawLabels.map(label => statusColors[label] || '#94a3b8');

        statusChartInstance = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: rawLabels,
                datasets: [{
                    data: rawData,
                    backgroundColor: bgColors,
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
                options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                layout: {
                    padding: {
                        top: 10,
                        bottom: 10,
                        left: 10,
                        right: 10
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 16,
                            font: { size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return ' ' + (context.parsed || 0) + ' Orders';
                            }
                        }
                    }
                }
            }
        });
    }
}

function initTopServicesLiveTable() {
    var mount = document.getElementById('topServicesTableMount');
    if (!mount) return;

    if (topServicesRefreshTimer) {
        clearInterval(topServicesRefreshTimer);
        topServicesRefreshTimer = null;
    }

    var liveLabel = document.getElementById('top-services-live-label');
    var refreshUrl = 'reports.php?ajax=top_services&range=<?php echo rawurlencode($range); ?>&status=<?php echo rawurlencode($status_filter); ?>';

    function refreshTopServices() {
        mount.classList.add('top-service-refreshing');
        fetch(refreshUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        })
        .then(function(response) {
            return response.ok ? response.json() : Promise.reject(new Error('Failed to load top services'));
        })
        .then(function(payload) {
            if (!payload || payload.success !== true || typeof payload.html !== 'string') {
                throw new Error('Invalid response');
            }
            mount.innerHTML = payload.html;
            if (liveLabel) {
                var now = new Date();
                liveLabel.textContent = 'Live data • updated ' + now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            }
        })
        .catch(function() {
            if (liveLabel) {
                liveLabel.textContent = 'Live data unavailable';
            }
        })
        .finally(function() {
            mount.classList.remove('top-service-refreshing');
        });
    }

    topServicesRefreshTimer = window.setInterval(refreshTopServices, 30000);
}

// Initial Load + Turbo Navigation
if (typeof renderReportsChartsScheduled === 'undefined') {
    var renderReportsChartsScheduled = true;
    document.addEventListener('DOMContentLoaded', renderReportsCharts);
    window.addEventListener('turbo:load', renderReportsCharts);
    window.addEventListener('pageshow', function(event) {
        if (event.persisted || (!revenueChartInstance && !statusChartInstance)) {
            renderReportsCharts();
        }
    });
} else {
    // If script re-runs via Turbo, just run the direct call
    renderReportsCharts();
}
</script>

</body>
</html>
