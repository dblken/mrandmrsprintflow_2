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

// Dashboard date filter - mirrors admin/dashboard.php while manager stays branch-locked.
$dashToday = date('Y-m-d');
$hasExplicitDateFilter = isset($_GET['preset']) || isset($_GET['from']) || isset($_GET['to']);
$dashPresetRaw = strtolower(trim((string)($_GET['preset'] ?? ($hasExplicitDateFilter ? '' : 'this_month'))));
$dashFromInput = trim((string)($_GET['from'] ?? ''));
$dashToInput = trim((string)($_GET['to'] ?? ''));
$dashPreset = 'this_month';
$dashboard_filter_label = 'This month';

$isValidDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
};

if ($dashPresetRaw === 'today') {
    $dashPreset = 'today';
    $dashboard_filter_label = 'Today';
    $dashFromDate = $dashToday;
    $dashToDate = $dashToday;
} elseif ($dashPresetRaw === 'this_week') {
    $dashPreset = 'this_week';
    $dashboard_filter_label = 'This week';
    $dashFromDate = date('Y-m-d', strtotime('monday this week'));
    $dashToDate = $dashToday;
} elseif ($dashPresetRaw === 'this_month') {
    $dashPreset = 'this_month';
    $dashboard_filter_label = 'This month';
    $dashFromDate = date('Y-m-01');
    $dashToDate = $dashToday;
} elseif ($isValidDate($dashFromInput) && $isValidDate($dashToInput)) {
    $dashPreset = '';
    $dashboard_filter_label = 'Custom range';
    $dashFromDate = $dashFromInput;
    $dashToDate = $dashToInput;
    if (strtotime($dashFromDate) > strtotime($dashToDate)) {
        [$dashFromDate, $dashToDate] = [$dashToDate, $dashFromDate];
    }
} else {
    $dashPreset = 'this_month';
    $dashboard_filter_label = 'This month';
    $dashFromDate = date('Y-m-01');
    $dashToDate = $dashToday;
}

$dashFromStart = $dashFromDate . ' 00:00:00';
$dashToEnd = $dashToDate . ' 23:59:59';
$dashboard_branch_display = (string)($branchCtx['branch_name'] ?? 'Assigned Branch');
$dashboard_context_label = $dashboard_branch_display
    . ' - '
    . date('M j, Y', strtotime($dashFromDate))
    . ' - '
    . date('M j, Y', strtotime($dashToDate))
    . ' ('
    . $dashboard_filter_label
    . ')';

$kpiDateFrom  = $dashFromStart;
$kpiDateToEnd = $dashToEnd;

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
         FROM orders o WHERE o.payment_status='Paid' AND o.order_date BETWEEN ? AND ?
         {$bSqlFrag}
         GROUP BY DATE(o.order_date) ORDER BY day",
        'ss' . ($bT5 ?: ''), array_merge([$dashFromStart, $dashToEnd], $bP5 ?: [])
    ) ?: [];
} catch (Exception $e) { $daily_sales = []; }

// ── Order Status Breakdown (branch-filtered) ──────────────────
try {
    [$bSqlFrag, $bT6, $bP6] = branch_where_parts('o', $branchId);
    $order_status = db_query(
        "SELECT o.status, COUNT(*) as cnt FROM orders o WHERE o.order_date BETWEEN ? AND ? {$bSqlFrag} GROUP BY o.status",
        'ss' . ($bT6 ?: ''), array_merge([$dashFromStart, $dashToEnd], $bP6 ?: [])
    ) ?: [];
} catch (Exception $e) { $order_status = []; }

// Sales by Product (official product list, branch-filtered)
try {
    $category_sales = pf_reports_sales_by_official_product($dashFromStart, $dashToEnd, $branchId);
} catch (Exception $e) { $category_sales = []; }

$dashboard_chart_value = static function (array $row): float {
    $total = (float)($row['total'] ?? 0);
    if ($total > 0) {
        return round($total, 2);
    }
    return (float)(($row['items_sold'] ?? null) ?? ($row['qty_sold'] ?? 0));
};

// Sales by Service Category (customization / job orders, branch-filtered)
try {
    $service_category_sales = pf_reports_sales_by_service_category($dashFromStart, $dashToEnd, $branchId);
    $service_category_sales = pf_reports_fold_demo_service_categories($service_category_sales, ['Eunsoyaaaaa', 'Ink']);
} catch (Exception $e) { $service_category_sales = []; }

// ── Top Customers (by spending) ────────────────────────────────
try {
    [$bSqlFrag_c, $bT_c, $bP_c] = branch_where_parts('o', $branchId);
    [$bSqlFrag_j, $bT_j, $bP_j] = branch_where_parts('j', $branchId);
    $types = 'ss' . ($bT_c ?: '') . 'ss' . ($bT_j ?: '');
    $params = array_merge([$dashFromStart, $dashToEnd], $bP_c ?: [], [$dashFromStart, $dashToEnd], $bP_j ?: []);
    $top_customers = db_query(
        "SELECT customer_name as name, COUNT(id) as orders, SUM(spent) as spent
         FROM (
             SELECT CONCAT(c.first_name, ' ', c.last_name) COLLATE utf8mb4_unicode_ci as customer_name, o.order_id as id, o.total_amount as spent
             FROM customers c JOIN orders o ON c.customer_id = o.customer_id
             WHERE o.payment_status = 'Paid' AND o.order_date BETWEEN ? AND ? {$bSqlFrag_c}
             UNION ALL
             SELECT j.customer_name COLLATE utf8mb4_unicode_ci, j.id, j.amount_paid as spent
             FROM job_orders j
             WHERE j.payment_status = 'PAID' AND j.created_at BETWEEN ? AND ? AND j.customer_name IS NOT NULL AND j.customer_name != '' {$bSqlFrag_j}
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
         WHERE o.payment_status = 'Paid' AND o.order_date BETWEEN ? AND ? {$bSqlFrag_tp}
         GROUP BY p.product_id, p.name, p.sku
         ORDER BY revenue DESC, qty_sold DESC LIMIT 5",
        'ss' . ($bT_tp ?: ''), array_merge([$dashFromStart, $dashToEnd], $bP_tp ?: [])
    ) ?: [];
} catch (Exception $e) { $top_products = []; }

try {
    $top_products_full = pf_reports_top_products_merged($dashFromDate, $dashToDate, $branchId, 10);
} catch (Exception $e) {
    $top_products_full = [];
}

$dashboard_sales_bar = pf_reports_category_sales_for_dashboard_bar_chart($service_category_sales, 8);
$dashboard_sales_bar_is_category = true;
$dashboard_branch_revenue_js = [[
    'branch_id' => (int)$branchId,
    'branch_name' => $dashboard_branch_display,
    'revenue' => round((float)$total_revenue, 2),
    'prev_revenue' => null,
    'growth_pct' => null,
    'product_revenue' => round((float)($store_revenue ?? 0), 2),
    'service_revenue' => round((float)($custom_revenue ?? 0), 2),
]];
$dashboard_branch_revenue_json = json_encode($dashboard_branch_revenue_js, JSON_UNESCAPED_UNICODE);
if ($dashboard_branch_revenue_json === false) {
    $dashboard_branch_revenue_json = '[]';
}

$customer_locations = [];
try {
    $locFrom = $dashFromDate;
    $locTo = $dashToEnd;
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        .dash-sales-revenue-card,
        .dash-sales-revenue-card .ana-hd h3,
        .dash-sales-revenue-card .chart-filter-label,
        .dash-sales-revenue-card .chart-select,
        .dash-sales-revenue-card .chart-badge,
        .dash-sales-revenue-card .chart-nodata,
        .dash-sales-revenue-card .chart-nodata span {
            font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        }
        .chart-filter-group { display:flex; gap:8px; align-items:center; flex-shrink:0; }
        .chart-badge { margin-left:8px; padding:3px 8px; background:#EBF8FF; color:#2C5282; border-radius:6px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .pf-branch-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .pf-branch-meta-badge { display:inline-flex; align-items:center; gap:6px; border:1px solid #e5e7eb; border-radius:10px; background:#fff; color:#334155; font-size:12px; font-weight:600; padding:8px 12px; }
        .pf-branch-meta-badge svg { width:14px; height:14px; color:#64748b; }
        .toolbar-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; height: 38px;
            border: 1px solid #e5e7eb; background: #fff; border-radius: 8px;
            font-size: 13px; font-weight: 500; color: #374151; cursor: pointer;
            transition: all 0.15s; white-space: nowrap; box-sizing: border-box;
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #00232b; color: #00232b; background: #ecf8fb; }
        .toolbar-btn svg { width:14px; height:14px; flex-shrink:0; }
        .filter-panel {
            position: absolute; top: calc(100% + 6px); right: 0; width: 320px;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12); z-index: 200; overflow: hidden;
        }
        .filter-panel-header { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; font-size: 14px; font-weight: 700; color: #111827; }
        .filter-section { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; }
        .filter-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .filter-section-label { font-size: 13px; font-weight: 600; color: #374151; }
        .filter-reset-link { font-size: 12px; font-weight: 600; color: #0d9488; cursor: pointer; background: none; border: none; padding: 0; }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-input { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 10px; color: #1f2937; box-sizing: border-box; }
        .filter-input:focus { outline: none; border-color: #0d9488; }
        .filter-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        .filter-actions { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #f3f4f6; }
        .filter-btn-reset { flex: 1; height: 36px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; }
        .filter-btn-reset:hover { background: #f9fafb; }
        .fp-preset-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; margin-top: 8px; }
        .fp-preset-btn {
            display: inline-flex; align-items: center; justify-content: center;
            height: 28px; padding: 0 8px;
            border: 1px solid #e5e7eb; background: #fff; border-radius: 6px;
            font-size: 11px; font-weight: 500; color: #374151; cursor: pointer;
            transition: all 0.15s; white-space: nowrap; box-sizing: border-box; width: 100%;
        }
        .fp-preset-btn:hover { border-color: #9ca3af; background: #f9fafb; color: #111827; }
        .fp-preset-btn.active { border-color: #00232b; background: #ecf8fb; color: #00232b; font-weight: 700; }
        [x-cloak] { display:none !important; }
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
            <div class="no-print" id="pf-dashboard-toolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;" x-data="dashboardFilterPanel('<?php echo htmlspecialchars($dashPreset); ?>')">
                <div id="pf-dashboard-toolbar-summary" class="pf-branch-meta-badge" title="Current dashboard branch and date filter">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/></svg>
                    <span id="pf-dashboard-toolbar-summary-text"><?php echo htmlspecialchars($dashboard_context_label); ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;position:relative;">
                    <button class="toolbar-btn" :class="{active: filterOpen}" @click="filterOpen = !filterOpen" style="height:38px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 01.8 1.6L14 13.5V20a1 1 0 01-1.447.894l-2-1A1 1 0 0110 19v-5.5L3.2 4.6A1 1 0 013 4z"/></svg>
                        Filter
                    </button>
                    <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                        <div class="filter-panel-header">Filter</div>
                        <form method="get" id="reportsFilterForm" action="<?php echo htmlspecialchars($basePath . '/manager/dashboard.php'); ?>">
                            <input type="hidden" name="branch_id" value="<?php echo (int)$branchId; ?>">
                            <input type="hidden" name="preset" id="dash_preset" value="<?php echo htmlspecialchars($dashPreset); ?>">
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <div class="filter-section-label">Date range</div>
                                    <button type="button" class="filter-reset-link" @click="resetDateRange()">Reset</button>
                                </div>
                                <div class="filter-date-row">
                                    <div>
                                        <div class="filter-date-label">From:</div>
                                        <input type="date" name="from" id="fp_from" class="filter-input" value="<?php echo htmlspecialchars($dashFromDate); ?>" @input="handleDateTyping(420)" @blur="handleDateTyping(120)">
                                    </div>
                                    <div>
                                        <div class="filter-date-label">To:</div>
                                        <input type="date" name="to" id="fp_to" class="filter-input" value="<?php echo htmlspecialchars($dashToDate); ?>" @input="handleDateTyping(420)" @blur="handleDateTyping(120)">
                                    </div>
                                </div>
                                <div style="margin-top:10px;font-size:12px;font-weight:600;color:#6b7280;">Quick presets</div>
                                <div class="fp-preset-grid">
                                    <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'today' }" @click="setPreset('today')">Today</button>
                                    <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'this_week' }" @click="setPreset('this_week')">This week</button>
                                    <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'this_month' }" @click="setPreset('this_month')">This month</button>
                                </div>
                            </div>
                            <div class="filter-actions">
                                <button type="button" class="filter-btn-reset" style="width:100%;" @click="resetDateRange()">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="pf-dashboard-content">

            <!-- KPI Summary Row -->
            <div class="kpi-row">
                <a class="kpi-card indigo kpi-card--link"
                   href="<?php echo htmlspecialchars($basePath . '/manager/customers.php'); ?>"
                   aria-label="View branch customers"
                   title="View customers">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Branch Customers</span>
                        <span class="kpi-value"><?php echo number_format($total_customers); ?></span>
                        <span class="kpi-sub"><?php echo htmlspecialchars($dashboard_filter_label); ?> period</span>
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
                        <span class="kpi-sub"><?php echo htmlspecialchars($dashboard_filter_label); ?> period</span>
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
                        <span class="kpi-sub"><?php echo htmlspecialchars($dashboard_filter_label); ?> period</span>
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
                        <span class="kpi-sub">Pending in <?php echo htmlspecialchars($dashboard_filter_label); ?></span>
                        <span class="kpi-card-cta" aria-hidden="true">View details &rarr;</span>
                    </span>
                </a>
            </div>

            <!-- Sales Revenue (Full Width) -->
            <div class="ana-card dash-full dash-sales-revenue-card" style="margin-bottom:28px;">
                <div class="ana-hd chart-header-row" style="margin-bottom:0;">
                    <h3 class="chart-title-nowrap">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        Branch Revenue
                        <span class="chart-badge"><?php echo htmlspecialchars($dashboard_filter_label); ?></span>
                    </h3>
                </div>
                <div class="ana-bd">
                    <div class="chart-wrap ch-box" id="dash-sales-chart-wrap" style="height:520px;" data-branch-revenue="<?php echo htmlspecialchars($dashboard_branch_revenue_json, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="chart-loading" id="dash-sales-loading">
                            <div class="chart-loading-spinner"></div>
                        </div>
                        <div class="chart-nodata" id="dash-sales-nodata">
                            <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" opacity="0.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            <span>No branch revenue data for this period</span>
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
                    <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No service sales data for this filter.</div>
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

            <!-- Category Sales -->
            <div class="dash-grid">
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        Sales by Product
                    </div>
                    <?php if (!empty($category_sales)): ?>
                    <div id="dash-product-single-chart" class="dash-single-chart-wrap" style="position:relative; height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;" data-category-labels="<?php echo htmlspecialchars(json_encode(array_map(static fn($c) => trim((string)($c['category'] ?? '')) !== '' ? trim((string)$c['category']) : 'Uncategorized product', $category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>" data-category-totals="<?php echo htmlspecialchars(json_encode(array_map($dashboard_chart_value, $category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>"><canvas id="categoryChart"></canvas></div>
                    <div id="category-legend" class="dash-single-chart-legend" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:flex-start; gap:12px; padding:0 10px;"></div>
                    <?php else: ?>
                    <div class="dash-empty-state">No official product sales data yet</div>
                    <?php endif; ?>
                </div>

                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4-4 4 4 4-8 4 8"/></svg>
                        Sales by Service Category
                    </div>
                    <?php if (!empty($service_category_sales)): ?>
                    <div id="dash-service-category-single-chart" class="dash-single-chart-wrap" style="position:relative; height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;" data-service-category-labels="<?php echo htmlspecialchars(json_encode(array_map(static fn($c) => trim((string)($c['category'] ?? '')) !== '' ? trim((string)$c['category']) : 'Customization', $service_category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>" data-service-category-totals="<?php echo htmlspecialchars(json_encode(array_map($dashboard_chart_value, $service_category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>"><canvas id="serviceCategoryChart"></canvas></div>
                    <div id="service-category-legend" class="dash-single-chart-legend" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:flex-start; gap:12px; padding:0 10px;"></div>
                    <?php else: ?>
                    <div class="dash-empty-state">No service category data for this filter.</div>
                    <?php endif; ?>
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
            </div>
        </main>
    </div>
</div>

<script>
(function () {
    var dashCtrl = null;
    var salesFirstFetch = true;
    var PF_DASH_CHART_FONT = 'Inter';
    function pfDashChartFont(size, weight) {
        return { family: PF_DASH_CHART_FONT, size: size || 11, weight: weight || '600' };
    }
    function pfApplyDashChartFontDefaults() {
        if (typeof Chart === 'undefined' || !Chart.defaults) return;
        Chart.defaults.font.family = PF_DASH_CHART_FONT;
        Chart.defaults.font.size = 11;
        Chart.defaults.font.weight = '600';
    }
    function parseJsonAttr(el, attr, fallback) {
        if (!el) return fallback;
        try { return JSON.parse(el.getAttribute(attr) || '[]'); } catch (e) { return fallback; }
    }
    function renderLegend(legendId, labels, colors) {
        var legendContainer = document.getElementById(legendId);
        if (!legendContainer) return;
        legendContainer.innerHTML = labels.map(function(label, i) {
            return '<div style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">' +
                '<span style="width:10px; height:10px; border-radius:50%; background:' + colors[i % colors.length] + ';"></span>' +
                '<span style="font-weight:600; color:#374151;">' + String(label || '') + '</span>' +
                '</div>';
        }).join('');
    }
    function destroyChart(key) {
        if (!window[key]) return;
        try { window[key].destroy(); } catch (e) {}
        window[key] = null;
    }
    window.printflowTeardownDashboardCharts = function () {
        if (dashCtrl) {
            try { dashCtrl.abort(); } catch (e) {}
            dashCtrl = null;
        }
        salesFirstFetch = true;
        ['__pfDashSalesChart', '__pfDashStatusChart', '__pfDashCategoryChart', '__pfDashServiceCategoryChart'].forEach(destroyChart);
        if (window.__pfDashProductsChart) {
            try { window.__pfDashProductsChart.destroy(); } catch (e) {}
            window.__pfDashProductsChart = null;
        }
    };
    window.printflowInitDashboardCharts = function () {
        if (typeof Chart === 'undefined') {
            setTimeout(function () {
                if (typeof window.printflowInitDashboardCharts === 'function') window.printflowInitDashboardCharts();
            }, 60);
            return;
        }
        pfApplyDashChartFontDefaults();
        window.printflowTeardownDashboardCharts();
        dashCtrl = new AbortController();
        var sig = { signal: dashCtrl.signal };
        var DASH_BRANCH_ID = <?php echo (int)$branchId; ?>;
        var colors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
        var doughnutAnim = { animateRotate: true, animateScale: true, duration: 1500 };

        function isDashMobile() {
            return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        }
        function pfReadDashBranchRevenue() {
            return parseJsonAttr(document.getElementById('dash-sales-chart-wrap'), 'data-branch-revenue', []);
        }
        function loadSalesChart() {
            if (!window.__pfDashSalesChart) return;
            var loadingEl = document.getElementById('dash-sales-loading');
            var noDataEl = document.getElementById('dash-sales-nodata');
            if (loadingEl) loadingEl.classList.remove('hidden');
            if (noDataEl) noDataEl.classList.remove('visible');
            try {
                var branchRows = pfReadDashBranchRevenue().slice().filter(function (row) {
                    return Number(row.revenue || 0) > 0;
                }).sort(function (a, b) {
                    return Number(b.revenue || 0) - Number(a.revenue || 0);
                });
                var labels = branchRows.map(function (row) {
                    var shortLabel = String(row.branch_name || 'Unknown Branch').replace(/\s+Branch$/i, '').trim();
                    return [shortLabel, 'Branch'];
                });
                var revenue = branchRows.map(function (row) { return Number(row.revenue) || 0; });
                var fills = revenue.map(function (_, index) { return index === 0 ? '#0F4C5C' : 'rgba(0,35,43,0.82)'; });
                var borders = revenue.map(function (_, index) { return index === 0 ? '#53C5E0' : '#00232b'; });
                var maxRevenue = revenue.reduce(function (max, value) { return Math.max(max, value); }, 0);
                var yStep = maxRevenue > 100000 ? 10000 : 5000;
                var yMax = maxRevenue > 0 ? Math.ceil((maxRevenue * 1.02) / yStep) * yStep : undefined;
                window.__pfDashSalesRows = branchRows;
                window.__pfDashSalesChart.data.labels = labels;
                window.__pfDashSalesChart.data.datasets[0].data = revenue;
                window.__pfDashSalesChart.data.datasets[0].backgroundColor = fills;
                window.__pfDashSalesChart.data.datasets[0].borderColor = borders;
                if (window.__pfDashSalesChart.options && window.__pfDashSalesChart.options.scales && window.__pfDashSalesChart.options.scales.y) {
                    window.__pfDashSalesChart.options.scales.y.max = yMax;
                }
                window.__pfDashSalesChart.options.animation.duration = salesFirstFetch ? 1750 : 680;
                salesFirstFetch = false;
                window.__pfDashSalesChart.update();
                if (noDataEl) noDataEl.classList.toggle('visible', labels.length === 0);
            } catch (e) {
                if (noDataEl) {
                    noDataEl.querySelector('span').textContent = 'Failed to load chart data';
                    noDataEl.classList.add('visible');
                }
            } finally {
                if (loadingEl) loadingEl.classList.add('hidden');
            }
        }
        function renderDoughnut(canvasId, legendId, labelAttr, valueAttr, key) {
            var cv = document.getElementById(canvasId);
            if (!cv) return;
            var labels = parseJsonAttr(cv.parentElement, labelAttr, []);
            var values = parseJsonAttr(cv.parentElement, valueAttr, []);
            if (!labels.length) return;
            window[key] = new Chart(cv.getContext('2d'), {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '70%', animation: doughnutAnim, plugins: { legend: { display: false }, tooltip: { animation: { duration: 160 }, cornerRadius: 8 } } }
            });
            renderLegend(legendId, labels, colors);
        }

        var salesCanvas = document.getElementById('salesChart');
        if (salesCanvas) {
            var currencyFmt = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });
            var compactCurrencyFmt = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP', notation: 'compact', maximumFractionDigits: 1 });
            window.__pfDashSalesChart = new Chart(salesCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: [], datasets: [{
                    label: 'Total sales revenue',
                    data: [],
                    borderColor: [],
                    backgroundColor: [],
                    borderWidth: 1.5,
                    borderRadius: 10,
                    borderSkipped: false,
                    barPercentage: 0.58,
                    categoryPercentage: 0.68
                }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 32, right: 18, bottom: 0, left: 8 } },
                    animation: { duration: 1750, easing: 'easeOutCubic' },
                    interaction: { mode: 'nearest', intersect: true },
                    hover: { mode: 'index', intersect: false, animationDuration: 400 },
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: '#00232b', font: pfDashChartFont(11, '700') } },
                        tooltip: {
                            animation: { duration: 180 },
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: false,
                            titleFont: pfDashChartFont(11, '600'),
                            bodyFont: pfDashChartFont(11, '600'),
                            callbacks: {
                                title: function (items) {
                                    if (!items[0]) return '';
                                    var raw = (window.__pfDashSalesRows || [])[items[0].dataIndex];
                                    return raw ? raw.branch_name : '';
                                },
                                label: function (ctx) {
                                    var idx = ctx.dataIndex;
                                    var rows = window.__pfDashSalesRows || [];
                                    var row = rows[idx] || {};
                                    var prev = row.prev_revenue != null ? Number(row.prev_revenue) : null;
                                    var growth = row.growth_pct != null ? Number(row.growth_pct) : null;
                                    var label = 'Total sales revenue: ' + currencyFmt.format(Number(ctx.parsed.y) || 0);
                                    if (prev !== null && !isNaN(prev)) label += '\nPrev: ' + currencyFmt.format(prev);
                                    if (growth !== null && !isNaN(growth)) label += '\nGrowth: ' + (growth > 0 ? '+' : '') + growth.toFixed(1) + '%';
                                    return label;
                                },
                                afterLabel: function (ctx) {
                                    var totalRevenue = (window.__pfDashSalesRows || []).reduce(function (sum, row) { return sum + (Number(row.revenue) || 0); }, 0);
                                    var pct = totalRevenue > 0 ? ((Number(ctx.parsed.y) || 0) / totalRevenue) * 100 : 0;
                                    return 'Contribution: ' + pct.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { font: pfDashChartFont(isDashMobile() ? 10 : 11, '600'), maxTicksLimit: isDashMobile() ? 5 : 7, callback: function (v) { return compactCurrencyFmt.format(Number(v) || 0); } }, grid: { color: '#f3f4f6' } },
                        x: { ticks: { color: '#334155', font: pfDashChartFont(isDashMobile() ? 9 : 11, '600'), maxRotation: 0, minRotation: 0 }, grid: { display: false } }
                    }
                },
                plugins: [{
                    id: 'pfBranchRevenueLabels',
                    afterDatasetsDraw: function (chart) {
                        var ctx = chart.ctx;
                        var meta = chart.getDatasetMeta(0);
                        var dataset = chart.data.datasets[0];
                        var rows = window.__pfDashSalesRows || [];
                        ctx.save();
                        ctx.font = '600 11px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        meta.data.forEach(function (bar, index) {
                            var value = Number(dataset.data[index]) || 0;
                            var row = rows[index] || {};
                            var growth = row.growth_pct != null ? Number(row.growth_pct) : null;
                            ctx.fillStyle = index === 0 ? '#0F172A' : '#334155';
                            ctx.fillText('\u20b1' + value.toLocaleString(undefined, { maximumFractionDigits: 0 }), bar.x, bar.y - 6);
                            if (growth !== null && !isNaN(growth)) {
                                ctx.save();
                                ctx.font = 'bold 10px sans-serif';
                                ctx.fillStyle = growth > 0 ? '#059669' : (growth < 0 ? '#dc2626' : '#64748b');
                                ctx.fillText((growth > 0 ? '+' : '') + growth.toFixed(1) + '%', bar.x, bar.y - 22);
                                ctx.restore();
                            }
                        });
                        ctx.restore();
                    }
                }]
            });
            loadSalesChart();
        }
        var statusCanvas = document.getElementById('statusChart');
        if (statusCanvas) {
            var statusLabels = <?php echo json_encode(array_map(fn($d) => $d['status'], $order_status)); ?>;
            var statusValues = <?php echo json_encode(array_map(fn($d) => (int)$d['cnt'], $order_status)); ?>;
            var catColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
            var statusColors = statusLabels.map(function(_, i) { return catColors[i % catColors.length]; });
            window.__pfDashStatusChart = new Chart(statusCanvas.getContext('2d'), { type: 'doughnut', data: { labels: statusLabels, datasets: [{ data: statusValues, backgroundColor: statusColors, borderWidth: 2, borderColor: '#fff', hoverOffset: 8 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '70%', animation: doughnutAnim, plugins: { legend: { display: false }, tooltip: { animation: { duration: 160 }, cornerRadius: 8 } } } });
            renderLegend('status-legend', statusLabels, statusColors);
        }

        renderDoughnut('categoryChart', 'category-legend', 'data-category-labels', 'data-category-totals', '__pfDashCategoryChart');
        renderDoughnut('serviceCategoryChart', 'service-category-legend', 'data-service-category-labels', 'data-service-category-totals', '__pfDashServiceCategoryChart');

        var productsEl = document.getElementById('productsChart');
        if (productsEl && typeof ApexCharts !== 'undefined') {
            window.__pfDashProductsChart = new ApexCharts(productsEl, {
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                series: [{ name: 'Sales (PHP)', data: <?php echo json_encode(array_map(function ($r) { return round((float)($r['total'] ?? $r['revenue'] ?? 0), 2); }, $dashboard_sales_bar)); ?> }],
                xaxis: { categories: <?php echo json_encode(array_map(function ($r) { $label = trim((string)($r['category'] ?? '')); return mb_substr($label !== '' ? $label : 'Customization', 0, 20); }, $dashboard_sales_bar)); ?>, labels: { style: { fontSize: '11px' } } },
                yaxis: { labels: { maxWidth: 160, style: { fontSize: '11px' } } },
                colors: ['#00232b'],
                plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '64%' } },
                dataLabels: { enabled: true, style: { fontSize: '11px', fontWeight: 700 } },
                grid: { borderColor: '#f3f4f6', padding: { left: 8, right: 12 } },
                tooltip: { theme: 'dark' }
            });
            window.__pfDashProductsChart.render();
        }

        document.querySelectorAll('.performer-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-perf-target');
                document.querySelectorAll('.performer-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                document.querySelectorAll('.performer-panel').forEach(function (panel) { panel.hidden = panel.getAttribute('data-perf-panel') !== target; });
            }, sig);
        });
    };
})();
</script><script>
window.__pfDashFilterTimer = null;
window.__pfDashLastSubmittedRange = null;
window.__pfDashDateRe = /^\d{4}-\d{2}-\d{2}$/;
window.__pfDashFilterFetchCtrl = null;
window.__pfDashFilterBusy = false;
window.__pfDashFilterQueued = false;

window.pfDashApplyFilterAjax = function() {
    var form = document.getElementById('reportsFilterForm');
    var fromEl = document.getElementById('fp_from');
    var toEl = document.getElementById('fp_to');
    if (!form || !fromEl || !toEl) return;

    var fromVal = String(fromEl.value || '').trim();
    var toVal = String(toEl.value || '').trim();
    if (!window.__pfDashDateRe.test(fromVal) || !window.__pfDashDateRe.test(toVal)) return;
    if (fromVal > toVal) return;

    var presetVal = document.getElementById('dash_preset')?.value || '';
    var key = fromVal + '|' + toVal + '|' + presetVal;
    if (window.__pfDashLastSubmittedRange === key && !window.__pfDashFilterQueued) return;
    window.__pfDashLastSubmittedRange = key;

    if (window.__pfDashFilterBusy) {
        window.__pfDashFilterQueued = true;
        return;
    }
    window.__pfDashFilterBusy = true;
    window.__pfDashFilterQueued = false;

    try {
        if (window.__pfDashFilterFetchCtrl) {
            try { window.__pfDashFilterFetchCtrl.abort(); } catch (e0) {}
        }
        window.__pfDashFilterFetchCtrl = new AbortController();
    } catch (e1) {
        window.__pfDashFilterFetchCtrl = null;
    }

    var params = new URLSearchParams(new FormData(form));
    params.set('_', String(Date.now()));
    var reqUrl = window.location.pathname + '?' + params.toString();

    fetch(reqUrl, {
        credentials: 'same-origin',
        signal: window.__pfDashFilterFetchCtrl ? window.__pfDashFilterFetchCtrl.signal : undefined
    })
    .then(function(resp) { return resp.text(); })
    .then(function(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');

        var nextContent = doc.querySelector('#pf-dashboard-content');
        var curContent = document.querySelector('#pf-dashboard-content');
        if (nextContent && curContent) {
            curContent.innerHTML = nextContent.innerHTML;
        }

        var nextSummaryText = doc.querySelector('#pf-dashboard-toolbar-summary-text');
        var curSummaryText = document.querySelector('#pf-dashboard-toolbar-summary-text');
        if (nextSummaryText && curSummaryText) {
            curSummaryText.textContent = nextSummaryText.textContent;
        }

        var cleanParams = new URLSearchParams(new FormData(form));
        var cleanUrl = window.location.pathname + '?' + cleanParams.toString();
        window.history.replaceState({}, '', cleanUrl);

        if (typeof window.printflowInitDashboardCharts === 'function') {
            window.printflowInitDashboardCharts();
        }
    })
    .catch(function(err) {
        if (err && err.name === 'AbortError') return;
        console.error('Dashboard live filter failed:', err);
    })
    .finally(function() {
        window.__pfDashFilterBusy = false;
        if (window.__pfDashFilterQueued) {
            window.__pfDashFilterQueued = false;
            window.pfDashApplyFilterAjax();
        }
    });
};

window.debouncedSubmitDashboardFilter = function(delay) {
    if (window.__pfDashFilterTimer) clearTimeout(window.__pfDashFilterTimer);
    window.__pfDashFilterTimer = setTimeout(function() {
        window.pfDashApplyFilterAjax();
    }, typeof delay === 'number' ? delay : 300);
};
function dashboardFilterPanel(initialPreset) {
    return {
        filterOpen: false,
        selectedPreset: initialPreset || 'this_month',
        handleDateTyping(delay) {
            this.selectedPreset = '';
            var p = document.getElementById('dash_preset');
            if (p) p.value = '';
            window.debouncedSubmitDashboardFilter(typeof delay === 'number' ? delay : 300);
        },
        resetDateRange() {
            this.setPreset('this_month');
        },
        setPreset(preset) {
            var today = new Date();
            var from = new Date(today);
            var to = new Date(today);
            if (preset === 'this_week') {
                var day = from.getDay();
                var diff = day === 0 ? 6 : (day - 1);
                from.setDate(from.getDate() - diff);
            } else if (preset === 'this_month') {
                from = new Date(today.getFullYear(), today.getMonth(), 1);
            } else {
                preset = 'today';
            }
            var fmt = function(d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            };
            var f = document.getElementById('fp_from');
            var t = document.getElementById('fp_to');
            var p = document.getElementById('dash_preset');
            if (f) f.value = fmt(from);
            if (t) t.value = fmt(to);
            if (p) p.value = preset;
            this.selectedPreset = preset;
            window.debouncedSubmitDashboardFilter(300);
        }
    };
}
</script>
</body>
</html>
