<?php
/**
 * Manager Dashboard - PrintFlow
 * Branch-filtered dashboard for Branch Managers.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';

// Only Managers allowed here
require_role('Manager');

// ── Branch Context (Manager is always locked to their branch) ────
$branchCtx = init_branch_context(false);
$branchId  = $branchCtx['selected_branch_id']; // always an int for Manager
$basePath  = defined('BASE_PATH') ? BASE_PATH : '';

// Rolling 30-day window (aligned with admin/dashboard.php KPIs)
$kpiDateFrom  = date('Y-m-d 00:00:00', strtotime('-29 days'));
$kpiDateToEnd = date('Y-m-d 23:59:59');

// ── KPI: Total Customers (distinct, with activity in window) ───
try {
    [$bSqlFrag, $bT, $bP] = branch_where_parts('o', $branchId);
    [$jSqlFrag, $jT, $jP] = branch_where_parts('jo', $branchId);
    $customerTypes = 'ss' . ($bT ?: '') . 'ss' . ($jT ?: '');
    $customerParams = array_merge([$kpiDateFrom, $kpiDateToEnd], $bP ?: [], [$kpiDateFrom, $kpiDateToEnd], $jP ?: []);
    $total_customers = db_query(
        "SELECT COUNT(DISTINCT src.customer_id) as cnt
         FROM (
             SELECT o.customer_id
             FROM orders o
             WHERE o.customer_id IS NOT NULL
               AND o.order_date BETWEEN ? AND ? {$bSqlFrag}
             UNION
             SELECT jo.customer_id
             FROM job_orders jo
             WHERE jo.customer_id IS NOT NULL
               AND jo.created_at BETWEEN ? AND ? {$jSqlFrag}
         ) src",
        $customerTypes,
        $customerParams
    )[0]['cnt'] ?? 0;
} catch (Exception $e) { $total_customers = 0; }

// ── KPI: Total Revenue (store + customizations, paid/completed in window) ─
try {
    [$bSqlO, $bTO, $bPO] = branch_where_parts('o', $branchId);
    [$bSqlJ, $bTJ, $bPJ] = branch_where_parts('j', $branchId);
    $store_revenue = db_query(
        "SELECT COALESCE(SUM(o.total_amount),0) as total
         FROM orders o
         WHERE (o.payment_status = 'Paid' OR o.status = 'Completed')
           AND o.order_date BETWEEN ? AND ? {$bSqlO}",
        'ss' . ($bTO ?: ''),
        array_merge([$kpiDateFrom, $kpiDateToEnd], $bPO ?: [])
    )[0]['total'] ?? 0;
    $custom_revenue = db_query(
        "SELECT COALESCE(SUM(COALESCE(NULLIF(j.amount_paid,0), j.estimated_total, 0)),0) as total
         FROM job_orders j
         WHERE (j.payment_status = 'PAID' OR j.status = 'COMPLETED')
           AND j.created_at BETWEEN ? AND ? {$bSqlJ}",
        'ss' . ($bTJ ?: ''),
        array_merge([$kpiDateFrom, $kpiDateToEnd], $bPJ ?: [])
    )[0]['total'] ?? 0;
    $total_revenue = (float)$store_revenue + (float)$custom_revenue;
} catch (Exception $e) { $total_revenue = 0; }

// ── KPI: Total Orders (count in window) ───────────────────────
try {
    [$bSqlFrag, $bT3, $bP3] = branch_where_parts('o', $branchId);
    [$jSqlFrag, $jT3, $jP3] = branch_where_parts('j', $branchId);
    $orderTypes = 'ss' . ($bT3 ?: '') . 'ss' . ($jT3 ?: '');
    $orderParams = array_merge([$kpiDateFrom, $kpiDateToEnd], $bP3 ?: [], [$kpiDateFrom, $kpiDateToEnd], $jP3 ?: []);
    $total_orders = db_query(
        "SELECT (
             (SELECT COUNT(*) FROM orders o WHERE o.order_date BETWEEN ? AND ? {$bSqlFrag}) +
             (SELECT COUNT(*) FROM job_orders j WHERE j.created_at BETWEEN ? AND ? {$jSqlFrag})
         ) AS cnt",
        $orderTypes,
        $orderParams
    )[0]['cnt'] ?? 0;
} catch (Exception $e) { $total_orders = 0; }

// ── KPI: Pending store orders (created in window, still Pending) ─
try {
    [$bSqlFrag, $bT4, $bP4] = branch_where_parts('o', $branchId);
    $pending_orders = db_query(
        "SELECT COUNT(*) as cnt
         FROM orders o
         WHERE o.status = 'Pending'
           AND o.order_date BETWEEN ? AND ? {$bSqlFrag}",
        'ss' . ($bT4 ?: ''),
        array_merge([$kpiDateFrom, $kpiDateToEnd], $bP4 ?: [])
    )[0]['cnt'] ?? 0;
} catch (Exception $e) { $pending_orders = 0; }

// ── Sales Revenue (Last 30 days, branch-filtered) ─────────────
try {
    [$bSqlFrag, $bT5, $bP5] = branch_where_parts('o', $branchId);
    $daily_sales = db_query(
        "SELECT DATE(o.order_date) as day, SUM(o.total_amount) as revenue, COUNT(*) as orders
         FROM orders o WHERE o.payment_status='Paid' AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         {$bSqlFrag}
         GROUP BY DATE(o.order_date) ORDER BY day",
        $bT5 ?: null, $bP5 ?: null
    ) ?: [];
} catch (Exception $e) { $daily_sales = []; }

// ── Order Status Breakdown (branch-filtered) ──────────────────
try {
    [$bSqlFrag, $bT6, $bP6] = branch_where_parts('o', $branchId);
    $order_status = db_query(
        "SELECT o.status, COUNT(*) as cnt FROM orders o WHERE 1=1 {$bSqlFrag} GROUP BY o.status",
        $bT6 ?: null, $bP6 ?: null
    ) ?: [];
} catch (Exception $e) { $order_status = []; }

// ── Sales by Product Category ──────────────────────────────────
try {
    $category_sales = pf_dashboard_sales_by_product_category($branchId);
} catch (Exception $e) { $category_sales = []; }

// ── Top Customers (by spending) ────────────────────────────────
try {
    [$bSqlFrag_c, $bT_c, $bP_c] = branch_where_parts('o', $branchId);
    [$bSqlFrag_j, $bT_j, $bP_j] = branch_where_parts('j', $branchId);
    $types = ($bT_c ?: '') . ($bT_j ?: '');
    $params = array_merge($bP_c ?: [], $bP_j ?: []);
    $top_customers = db_query(
        "SELECT customer_name as name, COUNT(id) as orders, SUM(spent) as spent
         FROM (
             SELECT CONCAT(c.first_name, ' ', c.last_name) COLLATE utf8mb4_unicode_ci as customer_name, o.order_id as id, o.total_amount as spent
             FROM customers c JOIN orders o ON c.customer_id = o.customer_id
             WHERE o.payment_status = 'Paid' {$bSqlFrag_c}
             UNION ALL
             SELECT j.customer_name COLLATE utf8mb4_unicode_ci, j.id, j.amount_paid as spent
             FROM job_orders j
             WHERE j.payment_status = 'PAID' AND j.customer_name IS NOT NULL AND j.customer_name != '' {$bSqlFrag_j}
         ) as all_orders
         GROUP BY customer_name ORDER BY spent DESC LIMIT 5",
        $types ?: null, $params ?: null
    ) ?: [];
} catch (Exception $e) { $top_customers = []; }

// ── Top Selling Products (by store revenue / sales) ────────────
try {
    [$bSqlFrag_tp, $bT_tp, $bP_tp] = branch_where_parts('o', $branchId);
    $top_products = db_query(
        "SELECT p.name as product_name, p.sku,
                SUM(oi.quantity) as qty_sold,
                SUM(oi.quantity * oi.unit_price) as revenue
         FROM order_items oi
         JOIN products p ON oi.product_id = p.product_id
         JOIN orders o ON oi.order_id = o.order_id
         WHERE o.payment_status = 'Paid' {$bSqlFrag_tp}
         GROUP BY p.product_id, p.name, p.sku
         ORDER BY revenue DESC, qty_sold DESC LIMIT 5",
        $bT_tp ?: null, $bP_tp ?: null
    ) ?: [];
} catch (Exception $e) { $top_products = []; }

try {
    $top_products_full = pf_reports_top_products_merged('', '', $branchId, 10);
} catch (Exception $e) {
    $top_products_full = [];
}

$dashboard_sales_bar = !empty($category_sales)
    ? array_slice($category_sales, 0, 8)
    : array_slice($top_products_full, 0, 8);
$dashboard_sales_bar_is_category = !empty($category_sales);

$customer_locations = [];
try {
    $locFrom = date('Y-m-d', strtotime('-30 days'));
    $locTo = date('Y-m-d') . ' 23:59:59';
    $customer_locations = pf_reports_customer_locations_merged($locFrom, $locTo, $branchId, 8, false);
} catch (Exception $e) {}

$statusColors = [
    // Keep this in sync with Admin Reports status donut palette.
    'Completed'            => '#22c55e',
    'Processing'           => '#3b82f6',
    'Ready for Pickup'     => '#06b6d4',
    'Pending'              => '#f59e0b',
    'Pending Review'       => '#6b7280',
    'Downpayment Submitted'=> '#8b5cf6',
    'Cancelled'            => '#ef4444',
    'Design Approved'      => '#6366f1',
];

// ── Recent Orders (last 5, branch-filtered) ──────────────────
try {
    [$bSqlFrag, $bT7, $bP7] = branch_where_parts('o', $branchId);
    $recent_orders = db_query(
        "SELECT o.order_id, CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                o.order_date, o.total_amount, o.payment_status, o.status, b.branch_name
         FROM orders o
         LEFT JOIN customers c ON o.customer_id = c.customer_id
         LEFT JOIN branches b  ON o.branch_id  = b.id
         WHERE 1=1 {$bSqlFrag}
         ORDER BY o.order_date DESC LIMIT 5",
        $bT7 ?: null, $bP7 ?: null
    ) ?: [];
} catch (Exception $e) { $recent_orders = []; }

// ── Low Stock Alerts ──────────────────────────────────────────
try {
    require_once __DIR__ . '/../includes/InventoryManager.php';
    $all_items = db_query(
        "SELECT i.id, i.name as material_name, i.reorder_level as low_limit, i.unit_of_measure as unit,
                ic.name as category_name
         FROM inv_items i
         LEFT JOIN inv_categories ic ON i.category_id = ic.id
         WHERE i.status = 'ACTIVE' AND i.reorder_level > 0"
    ) ?: [];
    $low_stock = [];
    foreach ($all_items as $item) {
        $soh = InventoryManager::getStockOnHand((int)$item['id'], (int)$branchId);
        if ($soh <= $item['low_limit']) {
            $item['current_stock'] = $soh;
            $item['ratio'] = ((float)$item['low_limit'] > 0) ? ($soh / (float)$item['low_limit']) : 0;
            $low_stock[] = $item;
        }
    }
    usort($low_stock, fn($a, $b) => $a['ratio'] <=> $b['ratio']);
    $low_stock = array_slice($low_stock, 0, 5);
} catch (Exception $e) { $low_stock = []; }

$page_title = 'Dashboard - Manager | PrintFlow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/assets/css/output.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.0/dist/apexcharts.min.js"></script>
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>
    <style>
        /* KPI Row */
        .kpi-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px; }
        @media (max-width:768px) { .kpi-row { grid-template-columns:repeat(2, 1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#00232b,#53C5E0); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#e11d48,#fb7185); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }
        a.kpi-card.kpi-card--link { display:block; text-decoration:none; color:inherit; cursor:pointer; box-shadow:0 1px 3px rgba(0,35,43,.06); transition:transform .25s ease, box-shadow .25s ease, filter .2s ease, opacity .2s ease; -webkit-tap-highlight-color: rgba(83, 197, 224, 0.25); }
        a.kpi-card.kpi-card--link:hover { transform:scale(1.02); box-shadow:0 10px 28px rgba(0,35,43,.12); }
        a.kpi-card.kpi-card--link:focus { outline:none; }
        a.kpi-card.kpi-card--link:focus-visible { outline:2px solid #53C5E0; outline-offset:3px; }
        a.kpi-card.kpi-card--link:active { transform:scale(0.99); box-shadow:0 4px 14px rgba(0,35,43,.08); }
        a.kpi-card.kpi-card--link.is-kpi-navigating { pointer-events:none; opacity:0.92; }
        .kpi-card--link .kpi-card-inner { position:relative; display:block; padding-bottom:0; }
        .kpi-card--link .kpi-label, .kpi-card--link .kpi-value, .kpi-card--link .kpi-sub { display:block; }
        .kpi-card-cta { position:static; display:block; margin-top:8px; font-size:11px; font-weight:600; color:#6b7280; letter-spacing:.02em; transition:opacity .25s ease, color .25s ease; }
        @media (hover: hover) {
            a.kpi-card.kpi-card--link .kpi-card-cta { opacity:0.4; }
            a.kpi-card.kpi-card--link:hover .kpi-card-cta,
            a.kpi-card.kpi-card--link:focus-visible .kpi-card-cta { opacity:1; color:#00232b; }
        }
        @media (hover: none) {
            a.kpi-card.kpi-card--link .kpi-card-cta { opacity:0.75; }
        }
        .dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; align-items:stretch; }
        @media (max-width:1024px) { .dash-grid { grid-template-columns:1fr; } }
        .dash-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; display:flex; flex-direction:column; height:100%; min-width:0; }
        .dash-card-title { font-size:15px; font-weight:700; color:#1f2937; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
        .dash-card-title svg { width:18px; height:18px; color:#53C5E0; }
        .ana-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:box-shadow .2s; display:flex; flex-direction:column; padding:0; }
        .ana-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
        .ana-hd { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid #f3f4f6; gap:10px; flex-wrap:wrap; flex-shrink:0; }
        .ana-hd h3 { margin:0; font-size:14px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; white-space:nowrap; }
        .ana-hd h3 svg { width:16px; height:16px; color:#53C5E0; flex-shrink:0; }
        .ana-bd { padding:20px; flex:1; display:flex; flex-direction:column; min-height:0; }
        .ch-box { width:100%; position:relative; }
        .dash-full { grid-column: 1 / -1; }
        .dash-card-body-fill { flex:1 1 auto; min-height:0; }
        .dash-card-empty { flex:1 1 auto; display:flex; align-items:center; justify-content:center; text-align:center; }
        .mini-table { width:100%; border-collapse:collapse; font-size:13px; }
        .mini-table th { text-align:left; padding:8px 10px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:#9ca3af; border-bottom:1px solid #f3f4f6; }
        .mini-table td { padding:8px 10px; border-bottom:1px solid #f9fafb; }
        .mini-table tr:hover { background:#f9fafb; }
        .chart-wrap { position:relative; height:250px; transform:translateZ(0); }
        .chart-loading { position:absolute; inset:0; background:rgba(255,255,255,.9); display:flex; align-items:center; justify-content:center; z-index:2; border-radius:8px; }
        .chart-loading.hidden { display:none; }
        .chart-loading-spinner { width:28px; height:28px; border:3px solid #e5e7eb; border-top-color:#53C5E0; border-radius:50%; animation:chart-spin .7s linear infinite; }
        @keyframes chart-spin { to { transform:rotate(360deg); } }
        .chart-nodata { position:absolute; inset:0; display:none; align-items:center; justify-content:center; flex-direction:column; gap:8px; color:#9ca3af; font-size:13px; z-index:1; }
        .chart-nodata.visible { display:flex; }
        .chart-select { padding:6px 10px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; font-weight:600; background:#fff; color:#374151; width:auto; min-width:4em; max-width:100%; }
        .chart-select-period { min-width:160px; }
        .chart-select-month { min-width:92px; }
        .chart-select-year { min-width:88px; }
        .chart-header-row { justify-content:space-between; align-items:center; flex-wrap:nowrap; gap:12px; margin-bottom:14px; }
        .chart-title-nowrap { white-space:nowrap; flex-shrink:0; display:flex; align-items:center; gap:8px; }
        .chart-filters { display:flex; flex-wrap:nowrap; align-items:center; gap:10px; flex-shrink:0; }
        .chart-filter-label { font-size:12px; font-weight:600; color:#6b7280; white-space:nowrap; }
        .chart-filter-group { display:flex; gap:8px; align-items:center; flex-shrink:0; }
        .chart-badge { margin-left:8px; padding:3px 8px; background:#EBF8FF; color:#2C5282; border-radius:6px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .badge { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600; }
        .badge-green { background:#d1fae5; color:#065f46; }
        .badge-yellow { background:#fef3c7; color:#92400e; }
        .badge-blue { background:#dbeafe; color:#1e40af; }
        .badge-red { background:#fee2e2; color:#991b1b; }
        .badge-gray { background:#f3f4f6; color:#374151; }
        .stock-bar { height:6px; background:#f3f4f6; border-radius:3px; overflow:hidden; width:80px; }
        .stock-bar-fill { height:100%; border-radius:3px; }
        .stock-bar-fill.danger { background:#ef4444; }
        .stock-bar-fill.warning { background:#f59e0b; }
        .pf-wide-chart-canvas { position:relative; width:100%; height:100%; min-width:0; }
        .pf-wide-chart-canvas canvas { width:100% !important; height:100% !important; display:block; }
        .loc-list { display:flex; flex-direction:column; gap:12px; }
        .loc-row { display:flex; flex-direction:column; gap:6px; }
        .loc-header { display:flex; justify-content:space-between; align-items:center; }
        .loc-name { display:flex; align-items:center; gap:8px; flex:1; }
        .loc-rank { font-size:11px; font-weight:800; color:#9ca3af; }
        .loc-city { font-size:13px; font-weight:600; color:#1f2937; }
        .loc-value { font-size:13px; font-weight:700; color:#0f172a; }
        .loc-bar-wrap { width:100%; height:24px; background:#f1f5f9; border-radius:6px; overflow:hidden; }
        .loc-bar { height:100%; background:linear-gradient(90deg, #00232b 0%, #0F4C5C 50%, #53C5E0 100%); border-radius:6px; }
        .products-chart { height:300px; }
        .performer-toggle { display:flex; gap:4px; background:#f3f4f6; padding:4px; border-radius:8px; }
        .performer-btn { padding:4px 12px; font-size:12px; font-weight:600; border-radius:6px; border:none; cursor:pointer; transition:all 0.2s; color:#6b7280; background:transparent; }
        .performer-btn.is-active { background:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.05); color:#00232b; }
        .performer-panel[hidden] { display:none !important; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/manager_sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Dashboard</h1>
            <?php render_branch_selector($branchCtx); ?>
        </header>

        <main>
            <!-- Branch context banner -->
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>

            <!-- KPI Summary Row -->
            <div class="kpi-row">
                <a class="kpi-card indigo kpi-card--link"
                   href="<?php echo htmlspecialchars($basePath . '/manager/customers.php'); ?>"
                   aria-label="View branch customers"
                   title="View customers">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Branch Customers</span>
                        <span class="kpi-value"><?php echo number_format($total_customers); ?></span>
                        <span class="kpi-sub">Active in last 30 days</span>
                        <span class="kpi-card-cta" aria-hidden="true">View details &rarr;</span>
                    </span>
                </a>
                <a class="kpi-card emerald kpi-card--link"
                   href="<?php echo htmlspecialchars($basePath . '/manager/reports.php'); ?>"
                   aria-label="View branch revenue reports"
                   title="View revenue report">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Branch Revenue</span>
                        <span class="kpi-value">₱<?php echo number_format((float)$total_revenue, 2); ?></span>
                        <span class="kpi-sub">Last 30 days</span>
                        <span class="kpi-card-cta" aria-hidden="true">View details →</span>
                    </span>
                </a>
                <a class="kpi-card amber kpi-card--link"
                   href="<?php echo htmlspecialchars($basePath . '/manager/orders.php'); ?>"
                   aria-label="View branch orders"
                   title="View orders">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Orders</span>
                        <span class="kpi-value"><?php echo number_format($total_orders); ?></span>
                        <span class="kpi-sub">Last 30 days</span>
                        <span class="kpi-card-cta" aria-hidden="true">View details &rarr;</span>
                    </span>
                </a>
                <a class="kpi-card rose kpi-card--link"
                   href="<?php echo htmlspecialchars($basePath . '/manager/orders.php?status=Pending'); ?>"
                   aria-label="View pending branch orders"
                   title="View pending orders">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Pending Orders</span>
                        <span class="kpi-value"><?php echo number_format($pending_orders); ?></span>
                        <span class="kpi-sub">Pending in last 30 days</span>
                        <span class="kpi-card-cta" aria-hidden="true">View details &rarr;</span>
                    </span>
                </a>
            </div>

            <!-- Sales Revenue (Full Width) -->
            <div class="ana-card dash-full" style="margin-bottom:28px;">
                <div class="ana-hd chart-header-row" style="margin-bottom:0;">
                    <h3 class="chart-title-nowrap">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        Branch Revenue
                        <span class="chart-badge">Live Period</span>
                    </h3>
                    <div class="chart-filters">
                        <label class="chart-filter-label">Period</label>
                        <select id="dash-chart-period" class="chart-select chart-select-period">
                            <option value="today">Today</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="6months">Last 6 Months</option>
                            <option value="yearly">Yearly</option>
                        </select>
                        <span id="dash-year-month" class="chart-filter-group">
                            <select id="dash-chart-month" class="chart-select chart-select-month" style="display:none;" title="Month">
                                <?php foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $i => $m): ?>
                                <option value="<?php echo $i+1; ?>" <?php echo ($i+1)==date('n')?'selected':''; ?>><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="dash-chart-year" class="chart-select chart-select-year" title="Year">
                                <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y==date('Y')?'selected':''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </span>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="chart-wrap ch-box" id="dash-sales-chart-wrap" style="height:320px;">
                        <div class="chart-loading" id="dash-sales-loading">
                            <div class="chart-loading-spinner"></div>
                        </div>
                        <div class="chart-nodata" id="dash-sales-nodata">
                            <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" opacity="0.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            <span>No sales data for this period</span>
                        </div>
                        <div class="pf-wide-chart-canvas"><canvas id="salesChart"></canvas></div>
                    </div>
                </div>
            </div>

            <!-- Order Status + Top Location -->
            <div class="dash-grid">
                <!-- Order Status Breakdown -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Order Status Breakdown
                    </div>
                    <div class="chart-wrap" style="height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div id="status-legend" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:center; gap:12px; padding:0 10px;"></div>
                </div>

                <!-- Top Customer Locations -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Top Customer Locations
                    </div>
                    <?php if (!empty($customer_locations)): ?>
                    <?php $max_orders = max(array_column($customer_locations, 'orders')); ?>
                    <div class="loc-list">
                        <?php foreach (array_slice($customer_locations, 0, 5) as $index => $loc):
                            $pct = $max_orders > 0 ? ($loc['orders'] / $max_orders) * 100 : 0;
                        ?>
                        <div class="loc-row">
                            <div class="loc-header">
                                <div class="loc-name">
                                    <span class="loc-rank">#<?php echo $index + 1; ?></span>
                                    <span class="loc-city"><?php echo htmlspecialchars(trim($loc['city'])); ?></span>
                                </div>
                                <div class="loc-value"><?php echo $loc['orders']; ?></div>
                            </div>
                            <div class="loc-bar-wrap">
                                <div class="loc-bar" style="width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="dash-card-empty" style="color:#9ca3af; font-size:13px;">No location data yet</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Best Selling Services + Inventory Alerts -->
            <div class="dash-grid">
                <!-- Best Selling Services -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                        Best Selling Services
                    </div>
                    <?php if (!empty($dashboard_sales_bar)): ?>
                    <div class="products-chart"><div id="productsChart"></div></div>
                    <?php else: ?>
                    <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No product data</div>
                    <?php endif; ?>
                </div>

                <!-- Inventory Alerts -->
                <div class="dash-card">
                    <div class="dash-card-title" style="justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            Inventory Alerts
                        </span>
                        <?php if (!empty($low_stock)):
                            // Check if any item is out of stock (0)
                            $has_out_of_stock = false;
                            foreach ($low_stock as $ls) {
                                if ((float)$ls['current_stock'] <= 0) {
                                    $has_out_of_stock = true;
                                    break;
                                }
                            }
                            $stock_filter = $has_out_of_stock ? 'out' : 'low';
                        ?>
                        <a href="<?php echo htmlspecialchars($basePath . '/manager/inventory_items.php?stock_status=' . $stock_filter); ?>" style="font-size:13px; font-weight:600; color:#0d9488; text-decoration:none;">See all &rarr;</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($low_stock)): ?>
                    <table class="mini-table">
                        <thead><tr><th>Material</th><th>Stock</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($low_stock as $ls):
                                $stock = (float)$ls['current_stock'];
                                $limit = (float)$ls['low_limit'];
                                $pct = $limit > 0 ? ($stock / $limit) * 100 : 0;
                                $barClass = $stock <= 0 ? 'danger' : 'warning';
                                $statusText = $stock <= 0 ? 'OUT OF STOCK' : 'LOW';
                                $statusColor = $stock <= 0 ? '#ef4444' : '#d97706';
                            ?>
                            <tr>
                                <td style="font-weight:600;" title="<?php echo htmlspecialchars($ls['material_name']); ?>">
                                    <?php echo mb_strlen($ls['material_name']) > 15 ? htmlspecialchars(mb_substr($ls['material_name'], 0, 15)) . '...' : htmlspecialchars($ls['material_name']); ?>
                                    <div style="font-size:10px; color:#9ca3af;"><?php echo htmlspecialchars($ls['category_name'] ?: 'General'); ?></div>
                                </td>
                                <td style="color:<?php echo $stock <= 0 ? '#ef4444' : '#d97706'; ?>; font-weight:700; white-space:nowrap;">
                                    <?php echo number_format($stock, 1); ?> <small><?php echo htmlspecialchars($ls['unit']); ?></small>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <div class="stock-bar" style="width:50px;"><div class="stock-bar-fill <?php echo $barClass; ?>" style="width:<?php echo min(100, max($pct, 10)); ?>%;"></div></div>
                                        <span style="font-size:10px; font-weight:700; color:<?php echo $statusColor; ?>;">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align:center; color:#059669; padding:40px 0; font-size:13px;">
                        <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin:0 auto 6px; display:block;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        All stock levels are healthy!
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dash-grid">
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        Sales by Product Category
                    </div>
                    <?php if (!empty($category_sales)): ?>
                    <div style="position:relative; height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;"><canvas id="categoryChart"></canvas></div>
                    <div id="category-legend" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:center; gap:12px; padding:0 10px;"></div>
                    <?php else: ?>
                    <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No product sales data yet</div>
                    <?php endif; ?>
                </div>

                <div class="dash-card">
                    <div class="dash-card-title" style="justify-content: space-between;">
                        <span style="display:flex; align-items:center; gap:8px;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                            Top Performers
                        </span>
                        <div class="performer-toggle">
                            <button type="button" class="performer-btn is-active" data-perf-target="products">Products</button>
                            <button type="button" class="performer-btn" data-perf-target="customers">Customers</button>
                        </div>
                    </div>
                    <div class="performer-panel" data-perf-panel="products">
                        <?php if (!empty($top_products)): ?>
                        <table class="mini-table">
                            <thead><tr><th>#</th><th>Product</th><th>Qty Sold</th><th style="text-align:right;">Revenue</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_products as $i => $tp): ?>
                                <tr>
                                    <td style="font-weight:700; color:#9ca3af;"><?php echo $i + 1; ?></td>
                                    <td style="font-weight:600;" title="<?php echo htmlspecialchars($tp['product_name']); ?>">
                                        <?php echo mb_strlen($tp['product_name']) > 25 ? htmlspecialchars(mb_substr($tp['product_name'], 0, 25)) . '...' : htmlspecialchars($tp['product_name']); ?>
                                        <div style="font-size:10px; color:#9ca3af;"><?php echo htmlspecialchars($tp['sku'] ?? ''); ?></div>
                                    </td>
                                    <td><?php echo (int)$tp['qty_sold']; ?></td>
                                    <td style="text-align:right; font-weight:700; color:#059669;">₱<?php echo number_format((float)$tp['revenue'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No product sales data yet</div>
                        <?php endif; ?>
                    </div>
                    <div class="performer-panel" data-perf-panel="customers" hidden>
                        <?php if (!empty($top_customers)): ?>
                        <table class="mini-table">
                            <thead><tr><th>#</th><th>Customer</th><th>Orders</th><th style="text-align:right;">Spent</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_customers as $i => $tc): ?>
                                <tr>
                                    <td style="font-weight:700; color:#9ca3af;"><?php echo $i + 1; ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($tc['name']); ?></td>
                                    <td><?php echo $tc['orders']; ?></td>
                                    <td style="text-align:right; font-weight:700; color:#059669;">₱<?php echo number_format((float)$tc['spent'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No customer sales data yet</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dash-grid">
                <div class="dash-card dash-full">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Recent Orders
                    </div>
                    <?php if (!empty($recent_orders)): ?>
                    <table class="mini-table">
                        <thead><tr><th>ID</th><th>Customer</th><th>Status</th><th style="text-align:right;">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_orders as $ro):
                                $sBadge = match($ro['status']) {
                                    'Completed'        => 'badge-green',
                                    'Processing'       => 'badge-blue',
                                    'Pending'          => 'badge-yellow',
                                    'Ready for Pickup' => 'badge-blue',
                                    'Cancelled'        => 'badge-red',
                                    default            => 'badge-gray'
                                };
                            ?>
                            <tr>
                                <td style="font-weight:700; color:#00232b;"><?php echo $ro['order_id']; ?></td>
                                <td style="font-weight:500;"><?php echo htmlspecialchars($ro['customer_name'] ?? 'N/A'); ?></td>
                                <td><span class="badge <?php echo $sBadge; ?>"><?php echo $ro['status']; ?></span></td>
                                <td style="text-align:right; font-weight:700;">&#8369;<?php echo number_format((float)$ro['total_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="dash-card-empty" style="color:#9ca3af; font-size:13px;">No orders yet</div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
(function () {
    var dashCtrl = null;
    var salesFirstFetch = true;
    window.printflowTeardownDashboardCharts = function () {
        if (window.__pfDashRevealIOs && window.__pfDashRevealIOs.length) {
            window.__pfDashRevealIOs.forEach(function (io) {
                try { io.disconnect(); } catch (e) {}
            });
            window.__pfDashRevealIOs = [];
        }
        if (window.__pfDashChartIO) {
            try { window.__pfDashChartIO.disconnect(); } catch (e) {}
            window.__pfDashChartIO = null;
        }
        if (window.__pfDashMainRO) {
            try { window.__pfDashMainRO.disconnect(); } catch (e) {}
            window.__pfDashMainRO = null;
        }
        if (window.__pfDashScrollKick) {
            try { window.removeEventListener('resize', window.__pfDashScrollKick); } catch (e) {}
            window.__pfDashScrollKick = null;
        }
        if (window.__pfDashScrollSettledHandler) {
            var mc0 = document.querySelector('.main-content');
            if (mc0) {
                try { mc0.removeEventListener('scroll', window.__pfDashScrollSettledHandler); } catch (e) {}
            }
            window.__pfDashScrollSettledHandler = null;
        }
        if (window.__pfDashScrollSettleTimer) {
            try { clearTimeout(window.__pfDashScrollSettleTimer); } catch (e) {}
            window.__pfDashScrollSettleTimer = null;
        }
        if (window.__pfDashLayoutTimer) {
            try { clearTimeout(window.__pfDashLayoutTimer); } catch (e) {}
            window.__pfDashLayoutTimer = null;
        }
        if (dashCtrl) {
            try { dashCtrl.abort(); } catch (e) {}
            dashCtrl = null;
        }
        salesFirstFetch = true;
        if (window.__pfDashSalesChart) {
            try { window.__pfDashSalesChart.destroy(); } catch (e) {}
            window.__pfDashSalesChart = null;
        }
        if (window.__pfDashStatusChart) {
            try { window.__pfDashStatusChart.destroy(); } catch (e) {}
            window.__pfDashStatusChart = null;
        }
        if (window.__pfDashCategoryChart) {
            try { window.__pfDashCategoryChart.destroy(); } catch (e) {}
            window.__pfDashCategoryChart = null;
        }
    };
    window.printflowInitDashboardCharts = function () {
        if (!document.getElementById('salesChart')) return;
        if (typeof Chart === 'undefined') {
            setTimeout(function () {
                if (typeof window.printflowInitDashboardCharts === 'function') window.printflowInitDashboardCharts();
            }, 40);
            return;
        }

        function normalizePesoText(raw) {
            var text = String(raw || '').trim();
            if (!text) return text;
            var numeric = text.replace(/^[^0-9-]+/, '');
            return numeric ? ('₱' + numeric) : text;
        }

        function normalizeDashboardCurrency() {
            var revenueValue = document.querySelector('.kpi-card.emerald .kpi-value');
            if (revenueValue) {
                revenueValue.textContent = normalizePesoText(revenueValue.textContent);
            }
            document.querySelectorAll('.dash-card.dash-full .mini-table tbody td:last-child').forEach(function (cell) {
                cell.textContent = normalizePesoText(cell.textContent);
            });
        }

        function normalizePesoText(raw) {
            var text = String(raw || '').trim();
            if (!text) return text;
            var numeric = text.replace(/^[^0-9-]+/, '');
            return numeric ? ('₱' + numeric) : text;
        }

        function normalizeDashboardCurrency() {
            var revenueValue = document.querySelector('.kpi-card.emerald .kpi-value');
            if (revenueValue) {
                revenueValue.textContent = normalizePesoText(revenueValue.textContent);
            }
            document.querySelectorAll('.mini-table tbody td:last-child').forEach(function (cell) {
                cell.textContent = normalizePesoText(cell.textContent);
            });
        }

        window.printflowTeardownDashboardCharts();
        window.__pfDashRevealIOs = [];
        dashCtrl = new AbortController();
        var sig = { signal: dashCtrl.signal };
        var DASH_BRANCH_ID = <?php echo (int)$branchId; ?>;
        normalizeDashboardCurrency();
        // Keep this palette aligned with Admin Reports "Revenue Distribution" chart.
        var ADMIN_REVENUE_DISTRIBUTION_PALETTE = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#8ED6E6', '#6B7C85', '#F39C12', '#2ECC71'];

        var dashAnimLong = 1750;
        var dashAnimShort = 680;
        var doughnutAnim = { animateRotate: true, animateScale: true, duration: 1500 };

        function bindWhenVisible(target, onFirst) {
            if (!target || typeof onFirst !== 'function') return;
            if (typeof IntersectionObserver === 'undefined') {
                requestAnimationFrame(onFirst);
                return;
            }
            var root = document.querySelector('.main-content');
            var fired = false;
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting || fired) return;
                    fired = true;
                    try { io.disconnect(); } catch (e) {}
                    onFirst();
                });
            }, { root: root || null, threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
            io.observe(target);
            window.__pfDashRevealIOs.push(io);
        }

        async function loadSalesChart(period) {
            if (!window.__pfDashSalesChart) return;
            var loadingEl = document.getElementById('dash-sales-loading');
            var noDataEl = document.getElementById('dash-sales-nodata');
            var yearEl = document.getElementById('dash-chart-year');
            var monthEl = document.getElementById('dash-chart-month');
            if (loadingEl) loadingEl.classList.remove('hidden');
            if (noDataEl) noDataEl.classList.remove('visible');
            var year = yearEl ? yearEl.value : new Date().getFullYear();
            var month = monthEl ? monthEl.value : new Date().getMonth() + 1;
            var url = '/printflow/admin/api_revenue_chart.php?period=' + encodeURIComponent(period) + '&year=' + encodeURIComponent(year);
            if (period === 'monthly') url += '&month=' + encodeURIComponent(month);
            if (DASH_BRANCH_ID) url += '&branch_id=' + DASH_BRANCH_ID;
            try {
                var resp = await fetch(url, { credentials: 'same-origin', signal: dashCtrl.signal });
                var text = await resp.text();
                var data;
                try { data = JSON.parse(text); } catch (e) {
                    if (noDataEl) { noDataEl.querySelector('span').textContent = 'Failed to load chart data'; noDataEl.classList.add('visible'); }
                    return;
                }
                var labels = data.labels || [];
                var revBranch = [];
                if (Array.isArray(data.revenue) && data.revenue.length) {
                    revBranch = data.revenue.map(function (v) { return Number(v) || 0; });
                } else {
                    var rs = Array.isArray(data.revenue_store) ? data.revenue_store : [];
                    var rc = Array.isArray(data.revenue_custom) ? data.revenue_custom : [];
                    for (var bi = 0; bi < labels.length; bi++) {
                        revBranch.push(Number(rs[bi] || 0) + Number(rc[bi] || 0));
                    }
                }
                if (!window.__pfDashSalesChart) return;
                window.__pfDashSalesChart.data.labels = labels;
                window.__pfDashSalesChart.data.datasets[0].data = revBranch;
                var dur = salesFirstFetch ? dashAnimLong : dashAnimShort;
                salesFirstFetch = false;
                if (window.__pfDashSalesChart.options && window.__pfDashSalesChart.options.animation) {
                    window.__pfDashSalesChart.options.animation.duration = dur;
                }
                window.__pfDashSalesChart.update();
                requestAnimationFrame(function () {
                    try {
                        if (window.__pfDashSalesChart && typeof window.__pfDashSalesChart.resize === 'function') {
                            window.__pfDashSalesChart.resize();
                        }
                    } catch (e2) {}
                });
                if (noDataEl) noDataEl.classList.toggle('visible', labels.length === 0);
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                if (noDataEl) { noDataEl.querySelector('span').textContent = 'Failed to load chart data'; noDataEl.classList.add('visible'); }
            } finally {
                if (loadingEl) loadingEl.classList.add('hidden');
            }
        }

        function updateChartYearMonthVisibility(period) {
            var wrap = document.getElementById('dash-year-month');
            var monthEl = document.getElementById('dash-chart-month');
            if (!wrap) return;
            wrap.style.display = ['monthly', '6months', 'yearly'].includes(period) ? 'flex' : 'none';
            if (monthEl) monthEl.style.display = period === 'monthly' ? 'inline-block' : 'none';
        }

        function getChartPeriod() {
            var sel = document.getElementById('dash-chart-period');
            return sel ? sel.value : 'monthly';
        }

        document.getElementById('dash-chart-period')?.addEventListener('change', function () {
            var period = getChartPeriod();
            updateChartYearMonthVisibility(period);
            if (window.__pfDashSalesChart) loadSalesChart(period);
        }, sig);
        document.getElementById('dash-chart-year')?.addEventListener('change', function () {
            if (window.__pfDashSalesChart) loadSalesChart(getChartPeriod());
        }, sig);
        document.getElementById('dash-chart-month')?.addEventListener('change', function () {
            if (window.__pfDashSalesChart) loadSalesChart(getChartPeriod());
        }, sig);

        updateChartYearMonthVisibility('monthly');

        bindWhenVisible(document.getElementById('dash-sales-chart-wrap'), function () {
            salesFirstFetch = true;
            window.__pfDashSalesChart = new Chart(document.getElementById('salesChart').getContext('2d'), {
                type: 'line',
                data: { labels: [], datasets: [
                    {
                        label: 'Branch revenue (₱)', data: [],
                        borderColor: ADMIN_REVENUE_DISTRIBUTION_PALETTE[0],
                        backgroundColor: 'rgba(0,35,43,.10)',
                        borderWidth: 2.5, fill: true, tension: 0.35,
                        pointBackgroundColor: ADMIN_REVENUE_DISTRIBUTION_PALETTE[0], pointRadius: 3, pointHoverRadius: 6, yAxisID: 'y'
                    }
                ]},
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: dashAnimLong, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: { animation: { duration: 180 }, padding: 10, cornerRadius: 8, displayColors: true }
                    },
                    scales: {
                        y:  { beginAtZero: true, ticks: { font: { size: 11 }, callback: function (v) { return '₱' + v.toLocaleString(); } }, grid: { color: '#f3f4f6' } },
                        x:  { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } }
                    }
                }
            });
            loadSalesChart(getChartPeriod());
        });

        <?php if (!empty($order_status)): ?>
        (function () {
            var w = document.getElementById('statusChart') && document.getElementById('statusChart').closest('.chart-wrap');
            bindWhenVisible(w, function () {
                var statusLabels = <?php echo json_encode(array_map(fn($d) => $d['status'], $order_status)); ?>;
                var statusValues = <?php echo json_encode(array_map(fn($d) => (int)$d['cnt'], $order_status)); ?>;
                // Match Admin Reports palette, but normalize status text to avoid fallback-gray on variants.
                var adminStatusPalette = {
                    'completed': '#22c55e',
                    'processing': '#3b82f6',
                    'in production': '#3b82f6',
                    'printing': '#3b82f6',
                    'ready for pickup': '#06b6d4',
                    'ready for pick up': '#06b6d4',
                    'pending': '#f59e0b',
                    'pending review': '#6b7280',
                    'pending approval': '#6b7280',
                    'for revision': '#6b7280',
                    'downpayment submitted': '#8b5cf6',
                    'pending verification': '#8b5cf6',
                    'to verify': '#8b5cf6',
                    'cancelled': '#ef4444',
                    'rejected': '#ef4444',
                    'design approved': '#6366f1',
                    'approved': '#6366f1',
                    'to pay': '#6366f1'
                };
                function normalizeStatusKey(v) {
                    return String(v || '')
                        .toLowerCase()
                        .replace(/[–—]/g, '-')
                        .replace(/\s+/g, ' ')
                        .trim();
                }
                var statusColorsResolved = statusLabels.map(function (label) {
                    var k = normalizeStatusKey(label);
                    return adminStatusPalette[k] || '#6B7C85';
                });

                window.__pfDashStatusChart = new Chart(document.getElementById('statusChart').getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data: statusValues,
                            backgroundColor: statusColorsResolved,
                            borderWidth: 2, borderColor: '#fff', hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '70%',
                        animation: doughnutAnim,
                        plugins: {
                            legend: { display: false },
                            tooltip: { animation: { duration: 160 }, cornerRadius: 8 }
                        }
                    }
                });
                updateStatusLegend(statusLabels, statusValues, statusColorsResolved);
            });
        })();
        <?php endif; ?>

        function updateStatusLegend(labels, counts, colors) {
            var legendContainer = document.getElementById('status-legend');
            if (!legendContainer) return;
            var html = '';
            labels.forEach(function(label, i) {
                html += '<div style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">';
                html += '<span style="width:10px; height:10px; border-radius:50%; background:' + colors[i] + ';"></span>';
                html += '<span style="font-weight:600; color:#374151;">' + label + '</span>';
                html += '</div>';
            });
            legendContainer.innerHTML = html;
        }

        <?php if (!empty($category_sales)): ?>
        (function () {
            var cv = document.getElementById('categoryChart');
            var w = cv ? cv.parentElement : null;
            var catColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
            var catLabels = <?php echo json_encode(array_map(fn($c) => $c['category'] ?? 'Store items', $category_sales)); ?>;
            bindWhenVisible(w, function () {
                window.__pfDashCategoryChart = new Chart(cv.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: catLabels,
                        datasets: [{
                            data: <?php echo json_encode(array_map(fn($c) => (float)$c['total'], $category_sales)); ?>,
                            backgroundColor: catColors.slice(0, <?php echo count($category_sales); ?>),
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        animation: doughnutAnim,
                        plugins: { legend: { display: false }, tooltip: { animation: { duration: 160 }, cornerRadius: 8 } }
                    }
                });
                var legendContainer = document.getElementById('category-legend');
                if (legendContainer) {
                    var html = '';
                    catLabels.forEach(function(label, i) {
                        html += '<div style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">';
                        html += '<span style="width:10px; height:10px; border-radius:50%; background:' + catColors[i % catColors.length] + ';"></span>';
                        html += '<span style="font-weight:600; color:#374151;">' + label + '</span>';
                        html += '</div>';
                    });
                    legendContainer.innerHTML = html;
                }
            });
        })();
        <?php endif; ?>

        <?php if (!empty($dashboard_sales_bar)): ?>
        (function () {
            var el = document.getElementById('productsChart');
            if (!el || typeof ApexCharts === 'undefined') return;
            bindWhenVisible(el.parentElement, function () {
                var chart = new ApexCharts(el, {
                    chart: { type: 'bar', height: 300, toolbar: { show: false } },
                    series: [{ name: 'Sales (₱)', data: <?php echo json_encode(array_map(function ($r) {
                        return round((float)($r['total'] ?? $r['revenue'] ?? 0), 2);
                    }, $dashboard_sales_bar)); ?> }],
                    xaxis: {
                        categories: <?php echo json_encode(array_map(function ($r) use ($dashboard_sales_bar_is_category) {
                            if ($dashboard_sales_bar_is_category) {
                                $label = trim((string)($r['category'] ?? ''));
                                return mb_substr($label !== '' ? $label : 'Uncategorized product', 0, 20);
                            }
                            return mb_substr((string)($r['product_name'] ?? ''), 0, 20);
                        }, $dashboard_sales_bar)); ?>,
                        labels: { style: { fontSize: '11px' } }
                    },
                    yaxis: { labels: { maxWidth: 160, style: { fontSize: '11px' } } },
                    colors: ['#00232b'],
                    plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '64%' } },
                    dataLabels: { enabled: true, style: { fontSize: '11px', fontWeight: 700 } },
                    grid: { borderColor: '#f3f4f6', padding: { left: 8, right: 12 } },
                    tooltip: { theme: 'dark' }
                });
                chart.render();
            });
        })();
        <?php endif; ?>

        document.querySelectorAll('.performer-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-perf-target');
                document.querySelectorAll('.performer-btn').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                document.querySelectorAll('.performer-panel').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-perf-panel') !== target;
                });
            });
        });

        (function attachDashboardChartLayout() {
            var mainEl = document.querySelector('.main-content');
            function runDashResize() {
                ['__pfDashSalesChart', '__pfDashStatusChart', '__pfDashCategoryChart'].forEach(function (k) {
                    var c = window[k];
                    if (c && typeof c.resize === 'function') {
                        try { c.resize(); } catch (e) {}
                    }
                });
            }
            function debouncedDashResize() {
                if (window.__pfDashLayoutTimer) clearTimeout(window.__pfDashLayoutTimer);
                window.__pfDashLayoutTimer = setTimeout(function () {
                    window.__pfDashLayoutTimer = null;
                    runDashResize();
                }, 240);
            }
            window.__pfDashScrollKick = debouncedDashResize;
            window.addEventListener('resize', debouncedDashResize);
            if (mainEl && typeof ResizeObserver !== 'undefined') {
                window.__pfDashMainRO = new ResizeObserver(function () {
                    debouncedDashResize();
                });
                window.__pfDashMainRO.observe(mainEl);
            }
        })();
    };
})();
</script>
</body>
</html>
