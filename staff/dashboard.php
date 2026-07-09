<?php
/**
 * Staff Dashboard
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

// Require staff access
require_role('Staff');
printflow_require_staff_module('dashboard');
require_once __DIR__ . '/../includes/staff_pending_check.php';

$staffCtx = init_branch_context();
$staffBranchId = $staffCtx['selected_branch_id'] === 'all' ? (int)($_SESSION['branch_id'] ?? 1) : (int)$staffCtx['selected_branch_id'];
$branch_name = $staffCtx['branch_name'];
$staffAccessMeta = printflow_get_staff_access_meta();
$staffOrderScopeSql = printflow_staff_order_source_sql('o', $staffAccessMeta['key'] ?? null);
$staffOrderScopeSqlNoAlias = printflow_staff_order_source_sql('orders', $staffAccessMeta['key'] ?? null);

// Some production databases may not have `orders.order_type` (older schema).
$hasOrderType = function_exists('db_table_has_column') ? db_table_has_column('orders', 'order_type') : true;

// --- 1. SET DATE RANGE & FILTERS ---
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$status_filter = $_GET['status'] ?? '';
$search_filter = $_GET['search'] ?? '';
$timeframe = $_GET['timeframe'] ?? 'today';

$today = date('Y-m-d');
$range_start = $today;
$range_end = $today;
$timeframe_label = "Today (" . date('F j, Y') . ")";
$short_label = "Today";

switch ($timeframe) {
    case 'week': 
        $range_start = date('Y-m-d', strtotime('monday this week'));
        $range_end = date('Y-m-d', strtotime('sunday this week'));
        
        $start_day = date('j', strtotime($range_start));
        $end_day = date('j', strtotime($range_end));
        $start_month = date('F', strtotime($range_start));
        $end_month = date('F', strtotime($range_end));
        $year = date('Y', strtotime($range_end));
        
        if ($start_month === $end_month) {
            $timeframe_label = "This Week ($start_month $start_day-$end_day, $year)";
        } else {
            $timeframe_label = "This Week ($start_month $start_day - $end_month $end_day, $year)";
        }
        $short_label = "This Week";
        break;
    case 'month': 
        $range_start = date('Y-m-01');
        $range_end = date('Y-m-t');
        $timeframe_label = "This Month (" . date('F Y') . ")"; 
        $short_label = "This Month";
        break;
    case 'year':
        $range_start = date('Y-01-01');
        $range_end = date('Y-12-31');
        $timeframe_label = "This Year (" . date('Y') . ")";
        $short_label = "This Year";
        break;
    case 'all':
        $range_start = null;
        $range_end = null;
        $timeframe_label = 'All Time';
        $short_label = 'All Time';
        break;
}

if (!function_exists('staff_dashboard_chart_title')) {
    function staff_dashboard_chart_title(string $timeframe): string {
        return match ($timeframe) {
            'today' => 'Sales Trend (Today)',
            'week' => 'Sales Trend (7 Days)',
            'month' => 'Sales Trend (This Month)',
            'year' => 'Sales Trend (This Year)',
            default => 'Sales Trend',
        };
    }
}

$has_timeframe_range = ($range_start !== null && $range_end !== null);
$timeframe_sql = "DATE(o.order_date) BETWEEN ? AND ?";
$timeframe_sql_no_alias = "DATE(order_date) BETWEEN ? AND ?";

// Get dashboard statistics (scoped to this staff member's branch)
$pending_orders_result = db_query(
    "SELECT COUNT(*) as count FROM orders WHERE status IN ('Pending', 'Pending Review') AND branch_id = ? AND {$staffOrderScopeSqlNoAlias}",
    'i',
    [$staffBranchId]
);
$pending_orders = $pending_orders_result[0]['count'] ?? 0;

$processing_orders_result = db_query(
    "SELECT COUNT(*) as count FROM orders WHERE status = 'Processing' AND branch_id = ? AND {$staffOrderScopeSqlNoAlias}",
    'i',
    [$staffBranchId]
);
$processing_orders = $processing_orders_result[0]['count'] ?? 0;

$ready_orders_result = db_query(
    "SELECT COUNT(*) as count FROM orders WHERE status = 'Ready for Pickup' AND branch_id = ? AND {$staffOrderScopeSqlNoAlias}",
    'i',
    [$staffBranchId]
);
$ready_orders = $ready_orders_result[0]['count'] ?? 0;

// Get today's completed orders
$today_completed_sql = "SELECT COUNT(*) as count FROM orders WHERE status = 'Completed' AND branch_id = ? AND {$staffOrderScopeSqlNoAlias}";
$today_completed_types = 'i';
$today_completed_params = [$staffBranchId];
if ($has_timeframe_range) {
    $today_completed_sql .= " AND $timeframe_sql_no_alias";
    $today_completed_types .= 'ss';
    $today_completed_params[] = $range_start;
    $today_completed_params[] = $range_end;
}
$today_completed_result = db_query($today_completed_sql, $today_completed_types, $today_completed_params);
$completed_today = $today_completed_result[0]['count'] ?? 0;

// Total Orders Today (Scoped)
$today_orders_sql = "SELECT COUNT(*) as count FROM orders WHERE branch_id = ? AND {$staffOrderScopeSqlNoAlias}";
$today_orders_types = 'i';
$today_orders_params = [$staffBranchId];
if ($has_timeframe_range) {
    $today_orders_sql .= " AND $timeframe_sql_no_alias";
    $today_orders_types .= 'ss';
    $today_orders_params[] = $range_start;
    $today_orders_params[] = $range_end;
}
$today_orders_res = db_query($today_orders_sql, $today_orders_types, $today_orders_params);
$total_orders_today = $today_orders_res[0]['count'] ?? 0;

// Total Sales Today (Scoped)
$sales_today_sql = "SELECT SUM(total_amount) as total FROM orders WHERE status != 'Cancelled' AND branch_id = ? AND {$staffOrderScopeSqlNoAlias}";
$sales_today_types = 'i';
$sales_today_params = [$staffBranchId];
if ($has_timeframe_range) {
    $sales_today_sql .= " AND $timeframe_sql_no_alias";
    $sales_today_types .= 'ss';
    $sales_today_params[] = $range_start;
    $sales_today_params[] = $range_end;
}
$sales_today_res = db_query($sales_today_sql, $sales_today_types, $sales_today_params);
$total_sales_today = $sales_today_res[0]['total'] ?? 0;

// --- Dashboard Global/Summary Metrics ---
$completed_products_sql = $hasOrderType
    ? "
        SELECT COUNT(DISTINCT o.order_id) as count 
        FROM orders o 
        JOIN order_items oi ON o.order_id = oi.order_id
        JOIN products p ON oi.product_id = p.product_id
        WHERE o.status = 'Completed' AND o.branch_id = ? AND {$staffOrderScopeSql} AND o.order_type = 'product'"
    : "
        SELECT COUNT(DISTINCT o.order_id) as count 
        FROM orders o 
        JOIN order_items oi ON o.order_id = oi.order_id
        JOIN products p ON oi.product_id = p.product_id
        WHERE o.status = 'Completed' AND o.branch_id = ? AND {$staffOrderScopeSql}";
$completed_products_types = 'i';
$completed_products_params = [$staffBranchId];
if ($has_timeframe_range) {
    $completed_products_sql .= " AND $timeframe_sql_no_alias";
    $completed_products_types .= 'ss';
    $completed_products_params[] = $range_start;
    $completed_products_params[] = $range_end;
}
$completed_products_res = db_query($completed_products_sql, $completed_products_types, $completed_products_params);
$completed_products_count = $completed_products_res[0]['count'] ?? 0;

$completed_custom_sql = "
    SELECT COUNT(DISTINCT o.order_id) as count 
    FROM orders o 
    JOIN order_items oi ON o.order_id = oi.order_id
    LEFT JOIN job_orders jo ON oi.order_item_id = jo.order_item_id
    LEFT JOIN services s ON oi.product_id = s.service_id";
$completed_custom_types = 'i';
$completed_custom_params = [$staffBranchId];
$completed_custom_sql .= "
    WHERE o.status = 'Completed' AND o.branch_id = ? AND {$staffOrderScopeSql}";
if ($has_timeframe_range) {
    $completed_custom_sql .= " AND $timeframe_sql_no_alias";
    $completed_custom_types .= 'ss';
    $completed_custom_params[] = $range_start;
    $completed_custom_params[] = $range_end;
}
$completed_custom_sql .= $hasOrderType
    ? "
          AND (s.service_id IS NOT NULL OR jo.id IS NOT NULL OR o.order_type = 'custom')"
    : "
          AND (s.service_id IS NOT NULL OR jo.id IS NOT NULL)";
$completed_custom_res = db_query($completed_custom_sql, $completed_custom_types, $completed_custom_params);
$completed_custom_count = $completed_custom_res[0]['count'] ?? 0;
$pending_reviews_res = db_query("SELECT COUNT(*) as count FROM reviews");
$pending_reviews_count = $pending_reviews_res[0]['count'] ?? 0;

// Sales Overview (Last 7 Days) for Trend Chart (Scoped)
$trend_labels = [];
$trend_values = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[] = date('D', strtotime($date));
    $res = db_query("SELECT SUM(total_amount) as total FROM orders WHERE DATE(order_date) = ? AND status != 'Cancelled' AND branch_id = ? AND {$staffOrderScopeSqlNoAlias}", 'si', [$date, $staffBranchId]);
    $trend_values[] = (float)($res[0]['total'] ?? 0);
}

// Top Sales (Dynamic Timeframe) (Scoped)
$top_services_sql = "
    SELECT COALESCE(p.name, s.name, 'Custom Product') as name, COUNT(*) as order_count
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    LEFT JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN services s ON oi.product_id = s.service_id
    WHERE o.branch_id = ?
      AND {$staffOrderScopeSql}
      AND (
          (p.product_id IS NOT NULL AND p.status = 'Activated')
          OR (s.service_id IS NOT NULL AND s.status = 'Activated')
          OR (p.product_id IS NULL AND s.service_id IS NULL)
      )
    GROUP BY name
    ORDER BY order_count DESC
    LIMIT 5";
$top_services_types = 'i';
$top_services_params = [$staffBranchId];
if ($has_timeframe_range) {
    $top_services_sql = str_replace('WHERE o.branch_id = ?', 'WHERE o.branch_id = ? AND ' . $timeframe_sql, $top_services_sql);
    $top_services_types .= 'ss';
    $top_services_params[] = $range_start;
    $top_services_params[] = $range_end;
}
$top_services = db_query($top_services_sql, $top_services_types, $top_services_params);

// Recent Orders with filters (Scoped)
$sql_cond = " WHERE o.branch_id = ? AND {$staffOrderScopeSql}";
$params = [$staffBranchId];
$types = "i";

if ($status_filter) {
    $sql_cond .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}
if ($has_timeframe_range) {
    $sql_cond .= " AND " . $timeframe_sql;
    $params[] = $range_start;
    $params[] = $range_end;
    $types .= "ss";
}
if ($search_filter) {
    $sql_cond .= " AND (o.order_id LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)";
    $lk = "%$search_filter%";
    $params[] = $lk;
    $params[] = $lk;
    $types .= "ss";
}

// Fetch recent orders
$total_rows_res = db_query("SELECT COUNT(*) as count FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id" . $sql_cond, $types, $params);
$total_rows = $total_rows_res[0]['count'] ?? 0;
$total_pages = ceil($total_rows / $limit);

$recent_orders = db_query("
    SELECT o.*, CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    (SELECT COALESCE(p.name, 'Custom Service') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id LIMIT 1) as service_type
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.customer_id 
    $sql_cond
    ORDER BY o.order_date DESC 
    LIMIT $limit OFFSET $offset
", $types, $params);

// Get low-stock products (view-only)
$low_stock = db_query("
    SELECT name, sku, stock_quantity, category 
    FROM products 
    WHERE status = 'Activated' AND stock_quantity < 10 
    ORDER BY stock_quantity ASC LIMIT 5
");

// Define missing variables for KPI cards (Staff Dashboard)
$active_orders_count = $pending_orders + $processing_orders + $ready_orders;
$all_products_count = db_query("SELECT COUNT(*) as cnt FROM products WHERE status = 'Activated'")[0]['cnt'] ?? 0;
$pending_reviews_count = db_query("SELECT COUNT(*) as cnt FROM reviews")[0]['cnt'] ?? 0;

$page_title = 'Staff Dashboard - PrintFlow';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo (defined('ASSET_PATH') ? ASSET_PATH : '/public/assets'); ?>/css/output.css">
    <?php /* Chart.js: includes/admin_style.php */ ?>
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        /* Full-Width Executive Layout Extensions */
        .chart-wrap { position: relative; height: 350px; width: 100%; margin-top: 10px; }
        
        .service-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1f5f9; gap: 12px; }
        .service-item:last-child { border-bottom: none; }
        .service-info { font-size: 13px; font-weight: 700; color: #1e293b; flex: 1; min-width: 0; }
        .service-count { font-size: 12px; font-weight: 800; color: var(--staff-primary); background: #e6f6f6; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }

        .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .filter-bar .input-field, .filter-bar select { width: auto; min-width: 140px; height: 34px !important; font-size: 12px; }

        /* Full Width Card Adjustments */
        .card { padding: 20px; border-radius: 16px; margin-bottom: 16px; height: 100%; border: 1px solid #f1f5f9; position: relative; min-width: 0; }
        .grid-cols-3 { display: grid; grid-template-columns: 7fr 3fr; gap: 16px; width: 100%; }
        @media (max-width: 1024px) { .grid-cols-3 { grid-template-columns: 1fr; } }

        /* Real-time Loading Transitions: Modern & Responsive */
        .loading-progress {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(to right, transparent, #06A1A1, transparent);
            background-size: 200% 100%;
            animation: loadingMove 1.5s linear infinite;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 20;
            border-radius: 16px 16px 0 0;
        }
        .is-loading .loading-progress { opacity: 1; }
        
        @keyframes loadingMove {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .content-transition { transition: opacity 0.3s ease; }
        .is-loading .content-transition { opacity: 0.6; }

        /* KPI Pulse Effect */
        @keyframes kpiPulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(0.98); opacity: 0.6; }
            100% { transform: scale(1); opacity: 1; }
        }
        .metric-pulse { animation: kpiPulse 0.4s ease-in-out; }
        [x-cloak] { display: none !important; }

        .truncate-ellipsis {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }
        
        .truncate-table-cell {
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .page-subtitle {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <div class="main-content" x-data="{ 
        sortOpen: false, 
        filterOpen: false, 
        activeStatus: '<?php echo $status_filter; ?>', 
        activeTimeframe: '<?php echo $timeframe; ?>',
        getButtonLabel(type) {
            if (type === 'today') return 'Today';
            if (type === 'week') return 'This Week';
            if (type === 'month') return 'This Month';
            if (type === 'year') return 'This Year';
            return type.charAt(0).toUpperCase() + type.slice(1);
        },
        getStatusLabel(status) {
            if (!status) return 'All';
            if (status === 'PENDING') return 'Pending';
            if (status === 'APPROVED') return 'Approved';
            if (status === 'TO_PAY') return 'To Pay';
            if (status === 'READY') return 'Ready';
            if (status === 'COMPLETED') return 'Completed';
            if (status === 'CANCELLED') return 'Cancelled / Rejected';
            return status;
        }
    }">
        <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle" id="kpi-subtitle">Metrics for <?php echo htmlspecialchars($timeframe_label); ?> at <?php echo htmlspecialchars($branch_name); ?></p>
            </div>

            <div class="toolbar-group" style="display: flex; gap: 10px; align-items: center; margin-left: auto;">
                <!-- Timeframe (Sort) -->
                <div style="position:relative;">
                    <button class="toolbar-btn" :class="{ active: sortOpen }" @click="sortOpen = !sortOpen; filterOpen = false" style="background: #fff; border: 1px solid #e2e8f0; color: #475569; font-weight: 600; height: 38px;">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="stroke-width:2;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-text="getButtonLabel(activeTimeframe)">Today</span>
                    </button>
                    <div class="dropdown-panel sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false" style="right: 0; left: auto;">
                        <a href="#" class="sort-option" :class="{ active: activeTimeframe === 'today' }" @click.prevent="activeTimeframe = 'today'; sortOpen = false; refreshDashboard(1, activeStatus, 'today')">Today</a>
                        <a href="#" class="sort-option" :class="{ active: activeTimeframe === 'week' }" @click.prevent="activeTimeframe = 'week'; sortOpen = false; refreshDashboard(1, activeStatus, 'week')">This Week</a>
                        <a href="#" class="sort-option" :class="{ active: activeTimeframe === 'month' }" @click.prevent="activeTimeframe = 'month'; sortOpen = false; refreshDashboard(1, activeStatus, 'month')">This Month</a>
                        <a href="#" class="sort-option" :class="{ active: activeTimeframe === 'year' }" @click.prevent="activeTimeframe = 'year'; sortOpen = false; refreshDashboard(1, activeStatus, 'year')">This Year</a>
                    </div>
                </div>

                <!-- Status Filter -->
                <div style="position:relative;">
                    <button class="toolbar-btn" :class="{ active: filterOpen || activeStatus }" @click="filterOpen = !filterOpen; sortOpen = false" style="background: #fff; border: 1px solid #e2e8f0; color: #475569; font-weight: 600; height: 38px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Status: <span x-text="getStatusLabel(activeStatus)"></span>
                    </button>
                    <div class="dropdown-panel sort-dropdown" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" style="right: 0; left: auto;">
                        <a href="#" class="sort-option" :class="{ active: !activeStatus }" @click.prevent="activeStatus = ''; filterOpen = false; refreshDashboard(1, '', activeTimeframe)">All Statuses</a>
                        <a href="#" class="sort-option" :class="{ active: activeStatus === 'PENDING' }" @click.prevent="activeStatus = 'PENDING'; filterOpen = false; refreshDashboard(1, 'PENDING', activeTimeframe)">Pending</a>
                        <a href="#" class="sort-option" :class="{ active: activeStatus === 'APPROVED' }" @click.prevent="activeStatus = 'APPROVED'; filterOpen = false; refreshDashboard(1, 'APPROVED', activeTimeframe)">Approved</a>
                        <a href="#" class="sort-option" :class="{ active: activeStatus === 'TO_PAY' }" @click.prevent="activeStatus = 'TO_PAY'; filterOpen = false; refreshDashboard(1, 'TO_PAY', activeTimeframe)">To Pay</a>
                        <a href="#" class="sort-option" :class="{ active: activeStatus === 'READY' }" @click.prevent="activeStatus = 'READY'; filterOpen = false; refreshDashboard(1, 'READY', activeTimeframe)">Ready</a>
                        <a href="#" class="sort-option" :class="{ active: activeStatus === 'COMPLETED' }" @click.prevent="activeStatus = 'COMPLETED'; filterOpen = false; refreshDashboard(1, 'COMPLETED', activeTimeframe)">Completed</a>
                        <a href="#" class="sort-option" :class="{ active: activeStatus === 'CANCELLED' }" @click.prevent="activeStatus = 'CANCELLED'; filterOpen = false; refreshDashboard(1, 'CANCELLED', activeTimeframe)">Cancelled / Rejected</a>
                    </div>
                </div>

                <!-- Hidden inputs for JS to read state -->
                <input type="hidden" id="filter-status" :value="activeStatus">
                <input type="hidden" id="filter-timeframe" :value="activeTimeframe">

                <?php if (($staffAccessMeta['key'] ?? '') === 'pos'): ?>
                <a href="pos.php" class="toolbar-btn" style="background: linear-gradient(135deg, #0d9488 0%, #065f46 100%); border: none; color:#fff; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.2); font-weight: 700; height: 38px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="stroke-width:2;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    POS
                </a>
                <?php endif; ?>
            </div>
        </header>

        <main id="dashboard-main">
            <!-- Loading Indicator -->
            <div class="loading-progress"></div>
            
            <div style="margin-bottom: 24px;">

                <div class="kpi-row content-transition">
                    <!-- 1. Completed Product Orders -->
                    <a href="orders.php?type=products&status=COMPLETED" class="kpi-card indigo kpi-card--link" title="View product orders">
                        <span class="kpi-card-inner">
                            <span class="kpi-label" id="stat-products-label">Completed Product Orders</span>
                            <span class="kpi-value" id="stat-completed-products"><?php echo number_format($completed_products_count); ?></span>
                            <span class="kpi-sub">Fixed products</span>
                            <span class="kpi-card-cta">View details →</span>
                        </span>
                    </a>

                    <!-- 2. Completed Customized Orders -->
                    <a href="customizations.php" class="kpi-card emerald kpi-card--link" title="View customized orders">
                        <span class="kpi-card-inner">
                            <span class="kpi-label" id="stat-custom-label">Completed Customized Orders</span>
                            <span class="kpi-value" id="stat-completed-custom"><?php echo number_format($completed_custom_count); ?></span>
                            <span class="kpi-sub">Customizable services</span>
                            <span class="kpi-card-cta">View details →</span>
                        </span>
                    </a>

                    <!-- 3. Reviews -->
                    <a href="reviews.php" class="kpi-card amber kpi-card--link" title="View reviews">
                        <span class="kpi-card-inner">
                            <span class="kpi-label" id="stat-reviews-label">Reviews</span>
                            <span class="kpi-value" id="stat-pending"><?php echo number_format($pending_reviews_count); ?></span>
                            <span class="kpi-sub">Service feedback</span>
                            <span class="kpi-card-cta">Action Needed →</span>
                        </span>
                    </a>

                    <!-- 4. Revenue / Reports -->
                    <a href="reports.php" class="kpi-card blue kpi-card--link" title="View reports">
                        <span class="kpi-card-inner">
                            <span class="kpi-label">Total Revenue</span>
                            <span class="kpi-value" id="stat-revenue">₱<?php echo number_format($total_sales_today, 2); ?></span>
                            <span class="kpi-sub">Gross branch income</span>
                            <span class="kpi-card-cta">View Reports →</span>
                        </span>
                    </a>
                </div>
            </div>

            <!-- SALES & TOP SERVICES ROW -->
            <div class="grid-cols-3" style="margin-bottom: 16px;">
                <div class="card">
                    <div class="loading-progress"></div>
                    <div class="content-transition">
                        <div id="chart-title" style="font-size: 16px; font-weight: 700; color: #013a3a; margin-bottom: 16px;"><?php echo htmlspecialchars(staff_dashboard_chart_title($timeframe)); ?></div>
                        <div class="chart-wrap" id="chart-pulse-wrap">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="loading-progress"></div>
                    <div class="content-transition">
                        <div id="top-sales-title" style="font-size: 16px; font-weight: 700; color: #013a3a; margin-bottom: 16px;">Top Sales (<?php echo $short_label; ?>)</div>
                        <div style="margin-top: 10px;" id="top-services-list">
                            <?php if (!empty($top_services)): ?>
                                <?php foreach ($top_services as $service): ?>
                                    <div class="service-item">
                                        <span class="service-info truncate-ellipsis" title="<?php echo htmlspecialchars($service['name']); ?>"><?php echo htmlspecialchars($service['name']); ?></span>
                                        <span class="service-count"><?php echo $service['order_count']; ?> Orders</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: #94a3b8; font-size: 14px; text-align: center; padding: 20px;">No data available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RECENT ORDERS SECTION -->
            <div class="card">
                <div class="loading-progress"></div>
                <div class="content-transition">
                    <div style="font-size: 16px; font-weight: 700; color: #013a3a; margin-bottom: 16px;">Recent Orders Activity</div>
                    
                    <div class="table-responsive">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer Name</th>
                                    <th>Service Type</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="recent-orders-tbody">
                                <?php if (!empty($recent_orders)): ?>
                                    <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td style="font-weight:700;">#<?php echo $order['order_id']; ?></td>
                                            <td style="font-weight:600;"><div class="truncate-table-cell" title="<?php echo htmlspecialchars($order['customer_name'] ?: 'Guest'); ?>"><?php echo htmlspecialchars($order['customer_name'] ?: 'Guest'); ?></div></td>
                                            <td style="font-weight:500;"><div class="truncate-table-cell" title="<?php echo htmlspecialchars($order['service_type'] ?: 'General'); ?>"><?php echo htmlspecialchars($order['service_type'] ?: 'General'); ?></div></td>
                                            <td style="color:#64748b; font-size:13px;"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                            <td style="font-weight:800; color:#013a3a;">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                             <td>
                                                 <?php echo status_badge($order['status'], 'order'); ?>
                                             </td>
                                            <td style="text-align:right;">
                                                <a href="<?php echo htmlspecialchars(printflow_staff_order_management_url((int)$order['order_id'], true)); ?>" class="btn-staff-action btn-staff-action-blue">Manage</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;">No orders found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div id="paginationWrapper">
                        <?php 
                        $pp = array_filter(['status'=>$status_filter, 'timeframe'=>$timeframe, 'search'=>$search_filter], function($v) { return $v !== null && $v !== ''; });
                        echo render_pagination($page, $total_pages, $pp); 
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div><script>
var salesChartInstance = null;
var dashAbortController = null;

async function refreshDashboard(page = 1, status = null, timeframe = null) {
    const main = document.getElementById('dashboard-main');
    if (!main) return;

    // 1. Cancel previous pending request if it exists
    if (dashAbortController) dashAbortController.abort();
    dashAbortController = new AbortController();

    // Use passed values or fallback to hidden inputs
    if (status === null) {
        const sEl = document.getElementById('filter-status');
        status = sEl ? sEl.value : '';
    }
    if (timeframe === null) {
        const tEl = document.getElementById('filter-timeframe');
        timeframe = tEl ? tEl.value : 'today';
    }

    // 2. Visual Feedback (Immediate)
    main.classList.add('is-loading');
    
    try {
        const response = await fetch(`<?php echo BASE_PATH; ?>/staff/api_dashboard_stats.php?page=${page}&status=${encodeURIComponent(status)}&timeframe=${encodeURIComponent(timeframe)}&_=${Date.now()}`, {
            signal: dashAbortController.signal,
            cache: 'no-store'
        });
        if (!response.ok) throw new Error('Refresh failed');
        const data = await response.json();
        
        if (data.error) throw new Error(data.error);

        // 3. Update DOM with micro-animations
        updateMetric('stat-revenue', data.stats.formatted_revenue || '₱0.00');
        updateMetric('stat-completed-products', data.stats.product_orders);
        updateMetric('stat-completed-custom', data.stats.custom_orders);
        updateMetric('stat-pending', data.stats.reviews);
        
        const productsLabelEl = document.getElementById('stat-products-label');
        if (productsLabelEl && data.stats.product_label) {
            productsLabelEl.textContent = data.stats.product_label;
        }

        const customLabelEl = document.getElementById('stat-custom-label');
        if (customLabelEl && data.stats.custom_label) {
            customLabelEl.textContent = data.stats.custom_label;
        }

        const reviewsLabelEl = document.getElementById('stat-reviews-label');
        if (reviewsLabelEl && data.stats.review_label) {
            reviewsLabelEl.textContent = data.stats.review_label;
        }

        const subtitleEl = document.getElementById('kpi-subtitle');
        if (subtitleEl) subtitleEl.textContent = `Metrics for ${data.timeframe_label} at <?php echo addslashes($branch_name); ?>`;

        const salesTitleEl = document.getElementById('top-sales-title');
        if (salesTitleEl) salesTitleEl.textContent = `Top Sales (${data.short_label || 'Today'})`;

        // Top Services list update
        const servicesList = document.getElementById('top-services-list');
        if (servicesList) {
            servicesList.style.opacity = '0';
            setTimeout(() => {
                if (data.top_services && data.top_services.length > 0) {
                    servicesList.innerHTML = data.top_services.map(s => `
                        <div class="service-item">
                            <span class="service-info truncate-ellipsis" title="${s.name}">${s.name}</span>
                            <span class="service-count">${s.order_count} Orders</span>
                        </div>
                    `).join('');
                } else {
                    servicesList.innerHTML = '<p style="color: #94a3b8; font-size: 14px; text-align: center; padding: 20px;">No data available.</p>';
                }
                servicesList.style.opacity = '1';
            }, 100);
        }

        // Table update
        const tbody = document.getElementById('recent-orders-tbody');
        if (tbody) {
            if (data.orders && data.orders.length > 0) {
                tbody.innerHTML = data.orders.map(o => `
                    <tr>
                        <td style="font-weight:700;">#${o.order_id}</td>
                        <td style="font-weight:600;"><div class="truncate-table-cell" title="${o.customer_name || 'Guest'}">${o.customer_name || 'Guest'}</div></td>
                        <td style="font-weight:500;"><div class="truncate-table-cell" title="${o.service_type || 'General'}">${o.service_type || 'General'}</div></td>
                        <td style="color:#64748b; font-size:13px;">${o.formatted_date}</td>
                        <td style="font-weight:800; color:#013a3a;">${o.formatted_total}</td>
                        <td>${o.status_html}</td>
                        <td style="text-align:right;">
                            <a href="${o.manage_url}" class="btn-staff-action btn-staff-action-blue">Manage</a>
                        </td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;">No orders found.</td></tr>';
            }
        }

        // 4. Update Chart (Fluidly without destroy)
        if (data.chart && data.chart.labels) {
            const ctEl = document.getElementById('chart-title');
            if (ctEl && data.chart.title) ctEl.textContent = data.chart.title;
            updateSalesChart(data.chart.labels, data.chart.values);
        }
        
        // 5. Update Pagination
        const pagWrapper = document.getElementById('paginationWrapper');
        if (pagWrapper && data.pagination.total_pages > 1) {
            let pagHtml = '<div style="display:flex; justify-content:center; gap:4px; margin-top:20px;">';
            for (let i = 1; i <= data.pagination.total_pages; i++) {
                const active = i === data.pagination.current_page ? 'background:#06A1A1; color:#fff; border-color:#06A1A1;' : 'background:#fff; color:#64748b; border-color:#e2e8f0;';
                pagHtml += `<button onclick="refreshDashboard(${i}, null, null)" style="width:32px; height:32px; border:1px solid; border-radius:8px; cursor:pointer; font-weight:700; font-size:12px; transition:all 0.2s; ${active}">${i}</button>`;
            }
            pagHtml += '</div>';
            pagWrapper.innerHTML = pagHtml;
        } else if (pagWrapper) {
            pagWrapper.innerHTML = '';
        }

    } catch (err) {
        if (err.name !== 'AbortError') console.error('Dashboard error:', err);
    } finally {
        setTimeout(() => main.classList.remove('is-loading'), 300);
    }
}

function updateMetric(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.textContent === value.toString()) return;
    
    // Smooth transition
    el.classList.add('metric-pulse');
    setTimeout(() => {
        el.textContent = value;
        setTimeout(() => el.classList.remove('metric-pulse'), 400);
    }, 150);
}

function updateSalesChart(labels, values) {
    const canvas = document.getElementById('salesChart');
    if (!canvas) return;

    if (salesChartInstance) {
        // Fluid transition: update data instead of destroy
        salesChartInstance.data.labels = labels;
        salesChartInstance.data.datasets[0].data = values;
        salesChartInstance.update();
        return;
    }

    const ctx = canvas.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(6, 161, 161, 0.2)');
    gradient.addColorStop(1, 'rgba(6, 161, 161, 0.05)');

    salesChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Gross Revenue',
                data: values,
                borderColor: '#06A1A1',
                borderWidth: 3,
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#06A1A1',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 800 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: '#013a3a',
                    callbacks: { label: (ctx) => ` ₱${ctx.parsed.y.toLocaleString()}` }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)', borderDash: [4, 4] },
                    ticks: {
                        callback: (v) => '₱' + v.toLocaleString(),
                        font: { size: 10, weight: '600' }
                    }
                },
                x: { grid: { display: false }, ticks: { font: { size: 10, weight: '600' } } }
            }
        }
    });
}

function initDashboardInteractions() {
    refreshDashboard(1);
    
    const sEl = document.getElementById('filter-status');
    const tEl = document.getElementById('filter-timeframe');
    if (sEl) sEl.addEventListener('change', () => refreshDashboard(1));
    if (tEl) tEl.addEventListener('change', () => refreshDashboard(1));
    
    const fForm = document.getElementById('filter-form');
    if (fForm) fForm.addEventListener('submit', (e) => { e.preventDefault(); refreshDashboard(1); });
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initDashboardInteractions();
} else {
    document.addEventListener('DOMContentLoaded', initDashboardInteractions);
}
document.addEventListener('turbo:load', initDashboardInteractions);
</script>

</body>
</html>
