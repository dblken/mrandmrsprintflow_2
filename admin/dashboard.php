<?php
/**
 * Admin Dashboard - PrintFlow
 * Real-time data from the database  (branch-aware)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_path_helper.php'; // Dynamic paths
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/pos_customer_helpers.php';
require_once __DIR__ . '/../includes/admin_dashboard_cache.php';
require_once __DIR__ . '/../includes/reports_dashboard_indexes.php';
require_role(['Admin', 'Manager']);

// Managers must use their own panel — redirect them away from /admin/
if (is_manager()) {
    $mgr_branch = $_SESSION['selected_branch_id'] ?? $_SESSION['branch_id'] ?? null;
    $mgr_qs = $mgr_branch && $mgr_branch !== 'all' ? '?branch_id=' . (int)$mgr_branch : '';
    header('Location: ' . AUTH_REDIRECT_BASE . '/manager/dashboard.php' . $mgr_qs);
    exit();
}

// ── Branch Context (analytics page — allows "All") ────
if (is_admin() && !isset($_SESSION['selected_branch_id']) && !array_key_exists('branch_id', $_GET)) {
    $_SESSION['selected_branch_id'] = 'all';
}
$branchCtx = init_branch_context(false);
$branchId  = $branchCtx['selected_branch_id']; // 'all' | int

// ── Dashboard date filter (same behavior family as reports filter) ─────
$dashToday = date('Y-m-d');
$hasExplicitDateFilter = isset($_GET['preset']) || isset($_GET['from']) || isset($_GET['to']);
$dashPresetRaw = strtolower(trim((string)($_GET['preset'] ?? ($hasExplicitDateFilter ? '' : 'today'))));
$dashFromInput = trim((string)($_GET['from'] ?? ''));
$dashToInput = trim((string)($_GET['to'] ?? ''));
$dashPreset = 'today';
$dashboard_filter_label = 'Today';

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
    $dashPreset = 'today';
    $dashboard_filter_label = 'Today';
    $dashFromDate = $dashToday;
    $dashToDate = $dashToday;
}

$dashFromStart = $dashFromDate . ' 00:00:00';
$dashToEnd = $dashToDate . ' 23:59:59';
$dashRangeDays = max(1, (int)floor((strtotime($dashToDate . ' 12:00:00') - strtotime($dashFromDate . ' 12:00:00')) / 86400) + 1);

$dashboard_branch_display = ($branchId === 'all')
    ? 'All Branches'
    : ((string)($branchCtx['branch_name'] ?? 'Selected Branch'));
$dashboard_context_label = $dashboard_branch_display
    . ' · '
    . date('M j, Y', strtotime($dashFromDate))
    . ' - '
    . date('M j, Y', strtotime($dashToDate))
    . ' ('
    . $dashboard_filter_label
    . ')';

$dashboard_sales_revenue_title = ($branchId === 'all')
    ? 'Sales Revenue by Branch'
    : ('Sales Revenue in ' . $dashboard_branch_display);
$dashboard_branch_sidebar_mode = ($branchId === 'all') ? 'multi' : 'single';
$dashboard_branch_sidebar_title = ($branchId === 'all') ? 'Multi-Branch Summary' : 'Branch Summary';

// KPI drill-down links (branch preserved via query; uses pf_admin_url — no hardcoded host)
$kpiBranchQs      = ($branchId === 'all') ? [] : ['branch_id' => (int)$branchId];
$kpiHrefCustomers = pf_admin_url('customers_management.php', $kpiBranchQs);
$kpiHrefOrders    = pf_admin_url('customizations.php', array_merge($kpiBranchQs, ['date_from' => $dashFromDate, 'date_to' => $dashToDate]));
$kpiHrefPending   = pf_admin_url('customizations.php', array_merge($kpiBranchQs, ['status' => 'PENDING', 'date_from' => $dashFromDate, 'date_to' => $dashToDate]));
$kpiHrefRevenue   = pf_admin_url('reports.php', array_merge($kpiBranchQs, ['trend_metric' => 'revenue']), 'ch-trend');
$kpiDateFrom      = $dashFromStart;
$kpiDateToEnd     = $dashToEnd;

// Build reusable branch SQL parts
$bTypes = ''; $bParams = [];
$bSql = branch_where('o', $branchId, $bTypes, $bParams);

// Shared helpers used both while computing and while rendering (never cached).
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/inventory_stock_status.php';

$statusColors = [
    'Pending' => '#F39C12',
    'Processing' => '#3498DB',
    'Ready for Pickup' => '#53C5E0',
    'Completed' => '#2ECC71',
    'Cancelled' => '#E74C3C',
    'Design Approved' => '#6C5CE7',
];

$dashboard_chart_value = static function (array $row): float {
    $total = (float)($row['total'] ?? 0);
    if ($total > 0) {
        return round($total, 2);
    }
    return (float)(($row['items_sold'] ?? null) ?? ($row['qty_sold'] ?? 0));
};

// ── Short-lived summary cache (read-only derived data; see includes/admin_dashboard_cache.php) ──
$dashCacheVars = [
    'total_customers', 'total_revenue', 'total_orders', 'pending_orders',
    'daily_sales', 'order_status', 'category_sales', 'cat_total_sum', 'service_category_sales',
    'dashboard_branch_chart_payload', 'dashboard_branch_chart_json',
    'recent_orders', 'low_stock', 'low_stock_by_branch', 'top_customers',
    'dashboard_branch_perf', 'dashboard_branch_period_label', 'dashboard_branch_total_revenue',
    'dashboard_branch_count', 'dashboard_sales_revenue_footnote', 'dashboard_top_branch',
    'dashboard_low_branch', 'dashboard_avg_branch_revenue', 'dashboard_top_branches',
    'dashboard_total_product_revenue', 'dashboard_total_service_revenue',
    'dashboard_product_pct', 'dashboard_service_pct',
    'dashboard_branch_revenue_js', 'dashboard_branch_revenue_json',
    'dashboard_sales_bar', 'dashboard_sales_bar_is_category', 'dashboard_sales_bar_labels',
    'dashboard_sales_bar_values', 'customer_locations',
];
// A cold summary response must never execute secondary widget queries.
$dashSectionParam = (string)($_GET['dashboard_section'] ?? '');
$dashSecondaryRequest = $dashSectionParam === 'secondary';
$dashBranchChartsRequest = $dashSectionParam === 'branch_charts';
$dashSummaryVars = ['total_customers', 'total_revenue', 'total_orders', 'pending_orders'];
$dashSecondaryVars = array_values(array_diff($dashCacheVars, $dashSummaryVars));
$dashBranchChartsVars = ['dashboard_branch_chart_payload', 'dashboard_branch_chart_json'];
if ($dashBranchChartsRequest) {
    $dashSections = ['branch_charts'];
} elseif ($dashSecondaryRequest) {
    // Secondary AJAX must not cold-run KPI SQL; summary is loaded on the initial HTML request.
    $dashSections = ['secondary'];
} else {
    $dashSections = ['summary'];
}
$dashDeferBranchSalesCharts = ($branchId === 'all' && $dashRangeDays > 21);
// Phase timings are exposed as a Server-Timing header (DevTools / performance API only;
// nothing is rendered). Used to locate slow sections on the live server.
$dashTimingStart = microtime(true);
$dashTimings = [];
$dashTimingLast = $dashTimingStart;
$dashMark = static function (string $phase) use (&$dashTimings, &$dashTimingLast): void {
    $now = microtime(true);
    $dashTimings[$phase] = ($dashTimings[$phase] ?? 0.0) + (($now - $dashTimingLast) * 1000);
    $dashTimingLast = $now;
};
$dashTimings['bootstrap'] = (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? $dashTimingStart)) * 1000;
printflow_ensure_reports_dashboard_indexes();
$dashMark('schema_indexes');
$dashCacheState = 'miss';
$dashSectionStates = [];

if ($dashSecondaryRequest) {
    $total_customers = 0;
    $total_revenue = 0.0;
    $total_orders = 0;
    $pending_orders = 0;
    $dashSummaryHydrated = printflow_admin_dashboard_hydrate_summary_kpis($branchId, $dashFromDate, $dashToDate, $dashMark);
    if (is_array($dashSummaryHydrated)) {
        $total_customers = (int)$dashSummaryHydrated['total_customers'];
        $total_revenue = (float)$dashSummaryHydrated['total_revenue'];
        $total_orders = (int)$dashSummaryHydrated['total_orders'];
        $pending_orders = (int)$dashSummaryHydrated['pending_orders'];
    } elseif (!printflow_branch_value_is_all($branchId)) {
        try {
            $dashboard_sales_summary = pf_reports_canonical_official_sales_totals($dashFromDate, $dashToEnd, $branchId);
            $total_revenue = (float)($dashboard_sales_summary['total_sales'] ?? 0);
        } catch (Exception $e) {
            $total_revenue = 0.0;
        }
        $dashMark('summary_hydrate_revenue_only');
    }
}

foreach ($dashSections as $dashSection) {
$dashCacheState = 'miss';
$dashDbErrorCount = 0;
$dashCacheVars = match ($dashSection) {
    'summary' => $dashSummaryVars,
    'branch_charts' => $dashBranchChartsVars,
    default => $dashSecondaryVars,
};
$dashCacheKey = printflow_admin_dashboard_cache_key($branchId, $dashFromDate, $dashToDate, 'v3-' . $dashSection);
$dashCacheHit = false;
$dashCached = printflow_admin_dashboard_cache_get($dashCacheKey);
if (printflow_admin_dashboard_cache_bypass_requested()) {
    $dashCacheState = 'bypass';
} elseif (printflow_admin_dashboard_cache_ttl() <= 0) {
    $dashCacheState = 'disabled';
}
if (is_array($dashCached)) {
    $dashCacheHit = true;
    foreach ($dashCacheVars as $dashCacheVar) {
        if (!array_key_exists($dashCacheVar, $dashCached)) {
            $dashCacheHit = false;
            break;
        }
    }
    if ($dashCacheHit) {
        foreach ($dashCacheVars as $dashCacheVar) {
            $$dashCacheVar = $dashCached[$dashCacheVar];
        }
        $dashCacheState = 'hit';
    } else {
        $dashCacheState = 'stale-shape';
    }
}
$dashMark('cache_get');
$dashDbErrorsBefore = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;

if (!$dashCacheHit) {

if ($dashSection === 'summary') {
// ── KPI: Total Customers ──────────────────────────────
try {
    if ($branchId !== 'all') {
        [$customerBranchWhere, $customerTypes, $customerParams] = branch_customers_belong_where_sql((int)$branchId, 'c');
    } else {
        $customerBranchWhere = ' AND ' . printflow_customer_exclusion_customer_scope_sql('c.customer_id');
        $customerTypes = '';
        $customerParams = [];
    }
    $customerWhere = ' AND COALESCE(c.email_verified, 0) = 1'
        . printflow_pos_sql_exclude_placeholder_customers('c')
        . $customerBranchWhere
        . ' AND c.created_at BETWEEN ? AND ?';
    $customerTypes .= 'ss';
    $customerParams = array_merge($customerParams, [$kpiDateFrom, $kpiDateToEnd]);
    $total_customers = (int)(db_query(
        "SELECT COUNT(*) AS cnt FROM customers c WHERE 1=1 {$customerWhere}",
        $customerTypes ?: null,
        $customerParams ?: null
    )[0]['cnt'] ?? 0);
} catch (Exception $e) { $total_customers = 0; }
$dashMark('kpi_customers');

// ── KPI: Total Revenue (official sales rules — matches Reports & Sales pages) ─
try {
    // The full report builds item/branch breakdowns and fetches all transaction
    // rows before slicing them in PHP. Its total comes from this exact helper.
    $dashboard_sales_summary = pf_reports_canonical_official_sales_totals($dashFromDate, $dashToEnd, $branchId);
    $total_revenue = (float)($dashboard_sales_summary['total_sales'] ?? 0);
} catch (Exception $e) { $total_revenue = 0; }
$dashMark('kpi_revenue');

// ── KPI: Total Custom Orders (branch-filtered) ────────
try {
    $customOrderBranch = 'COALESCE(j.branch_id, j_ord.branch_id)';
    $customOrderTypes = 'ss';
    $customOrderParams = [$kpiDateFrom, $kpiDateToEnd];
    if ($branchId !== 'all') {
        $customOrderBranchSql = " AND {$customOrderBranch} = ?";
        $customOrderTypes .= 'i';
        $customOrderParams[] = (int)$branchId;
    } else {
        $customOrderBranchSql = " AND ({$customOrderBranch} IS NULL OR {$customOrderBranch} IN (SELECT id FROM branches WHERE status != 'Archived'))";
    }
    $customOrderScope = ' AND ' . printflow_order_archive_exclusion_sql('j.order_id') . ' AND ' . printflow_customer_exclusion_customer_scope_sql('j.customer_id') . " AND (
        j.order_id IS NULL
        OR EXISTS (
            SELECT 1 FROM orders custom_scope_order
            JOIN order_items custom_scope_item ON custom_scope_item.order_id = custom_scope_order.order_id
            LEFT JOIN products custom_scope_product ON custom_scope_product.product_id = custom_scope_item.product_id
            WHERE custom_scope_order.order_id = j.order_id
              AND custom_scope_order.order_type = 'custom'
              AND COALESCE(LOWER(TRIM(custom_scope_product.product_type)), 'custom') <> 'fixed'
        )
    )";
    $total_orders = (int)(db_query(
        "SELECT COUNT(*) AS cnt
         FROM job_orders j
         LEFT JOIN orders j_ord ON j_ord.order_id = j.order_id
         WHERE COALESCE(j_ord.order_date, j.created_at) BETWEEN ? AND ?
           {$customOrderBranchSql}{$customOrderScope}",
        $customOrderTypes,
        $customOrderParams
    )[0]['cnt'] ?? 0);
} catch (Exception $e) { $total_orders = 0; }

// ── KPI: Pending Custom Orders (branch-filtered) ─────
try {
    $pendingJobScope = ' AND ' . printflow_order_archive_exclusion_sql('j.order_id') . ' AND ' . printflow_customer_exclusion_customer_scope_sql('j.customer_id') . " AND (
        j.order_id IS NULL
        OR EXISTS (
            SELECT 1
            FROM orders pending_scope_order
            JOIN order_items pending_scope_item ON pending_scope_item.order_id = pending_scope_order.order_id
            LEFT JOIN products pending_scope_product ON pending_scope_product.product_id = pending_scope_item.product_id
            WHERE pending_scope_order.order_id = j.order_id
              AND pending_scope_order.order_type = 'custom'
              AND COALESCE(LOWER(TRIM(pending_scope_product.product_type)), 'custom') <> 'fixed'
        )
    )";
    $pendingJobStatus = "CASE WHEN j_ord.reference_id LIKE 'HGEN-%' THEN 'COMPLETED' ELSE j.status END";
    $pendingJobDate = 'COALESCE(j_ord.order_date, j.created_at)';
    $pendingJobBranch = 'COALESCE(j.branch_id, j_ord.branch_id)';
    $pendingTypes = 'ss';
    $pendingParams = [$kpiDateFrom, $kpiDateToEnd];
    if ($branchId !== 'all') {
        $pendingJobBranchSql = " AND {$pendingJobBranch} = ?";
        $pendingTypes .= 'i';
        $pendingParams[] = (int)$branchId;
    } else {
        $pendingJobBranchSql = " AND ({$pendingJobBranch} IS NULL OR {$pendingJobBranch} IN (SELECT id FROM branches WHERE status != 'Archived'))";
    }
    $pending_orders = (int)(db_query(
        "SELECT COUNT(*) AS cnt
         FROM job_orders j
         LEFT JOIN orders j_ord ON j_ord.order_id = j.order_id
         WHERE {$pendingJobStatus} = 'PENDING'
           AND {$pendingJobDate} BETWEEN ? AND ?
           {$pendingJobBranchSql}{$pendingJobScope}",
        $pendingTypes,
        $pendingParams
    )[0]['cnt'] ?? 0);
} catch (Exception $e) { $pending_orders = 0; }
$dashMark('kpi_orders');

} elseif ($dashSection === 'branch_charts') {
$dashboard_branch_chart_payload = [
    'products' => [],
    'services' => [],
    'statuses' => [],
    'locations' => [],
];
if ($branchId === 'all') {
    try {
        $branchChartSlices = pf_reports_dashboard_all_branches_product_service_slices(
            $dashFromStart,
            $dashToEnd,
            $branchCtx['branches_list'] ?? []
        );
        $dashboard_branch_chart_payload['products'] = $branchChartSlices['products'];
        $dashboard_branch_chart_payload['services'] = $branchChartSlices['services'];
    } catch (Exception $e) {
    }
}
$dashboard_branch_chart_json = json_encode($dashboard_branch_chart_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($dashboard_branch_chart_json === false) {
    $dashboard_branch_chart_json = '{"products":[],"services":[],"statuses":[],"locations":[]}';
}
$dashMark('branch_charts_products_services');

} else {
// Daily sales series and the server-side status breakdown are not rendered by
// this page (the status doughnut loads from api_order_status_chart.php), so
// their queries are skipped. Variables stay defined for template safety.
$daily_sales = [];
$order_status = [];

// ── Sales by Product (official product list) ──────────
$dashUsesBranchCharts = $branchId === 'all' && count(array_filter(
    $branchCtx['branches_list'] ?? [], static fn($row) => (int)($row['id'] ?? 0) > 0
)) > 0;
$category_sales = [];
$service_category_sales = [];
// All Branches displays the per-branch datasets below, not combined slices.
if (!$dashUsesBranchCharts) {
try {
    $category_sales = pf_reports_sales_by_official_product(
        $dashFromStart,
        $dashToEnd,
        $branchId
    );
} catch (Exception $e) { $category_sales = []; }

// ── Sales by Service Category (customization / job orders) ──
try {
    $service_category_sales = pf_reports_sales_by_service_category(
        $dashFromStart,
        $dashToEnd,
        $branchId
    );
    $service_category_sales = pf_reports_fold_demo_service_categories(
        $service_category_sales,
        ['Eunsoyaaaaa', 'Ink']
    );
} catch (Exception $e) { $service_category_sales = []; }
}
$cat_total_sum = array_sum(array_map(fn($c) => (float)$c['total'], $category_sales));
$dashMark('sales_by_category');

$dashboard_branch_chart_payload = [
    'products' => [],
    'services' => [],
    'statuses' => [],
    'locations' => [],
];

if ($branchId === 'all') {
    if (!$dashDeferBranchSalesCharts) {
        try {
            $branchChartSlices = pf_reports_dashboard_all_branches_product_service_slices(
                $dashFromStart,
                $dashToEnd,
                $branchCtx['branches_list'] ?? []
            );
            $dashboard_branch_chart_payload['products'] = $branchChartSlices['products'];
            $dashboard_branch_chart_payload['services'] = $branchChartSlices['services'];
        } catch (Exception $e) {
        }
    }
    foreach (($branchCtx['branches_list'] ?? []) as $branchRow) {
        $chartBranchId = (int)($branchRow['id'] ?? 0);
        if ($chartBranchId <= 0) {
            continue;
        }
        $chartBranchName = trim((string)($branchRow['branch_name'] ?? ''));
        if ($chartBranchName === '') {
            $chartBranchName = 'Branch ' . $chartBranchId;
        }

        try {
            [$statusBranchSql, $statusBranchTypes, $statusBranchParams] = branch_where_parts('o', $chartBranchId);
            $statusBranchSql .= ' AND ' . printflow_order_archive_scope_sql('o');
            $statusRows = db_query(
                "SELECT o.status, COUNT(*) AS cnt
                 FROM orders o
                 WHERE o.order_date BETWEEN ? AND ? {$statusBranchSql}
                 GROUP BY o.status
                 ORDER BY cnt DESC",
                'ss' . ($statusBranchTypes ?: ''),
                array_merge([$dashFromStart, $dashToEnd], $statusBranchParams ?: [])
            ) ?: [];
        } catch (Exception $e) {
            $statusRows = [];
        }
        $statusPayloadRows = array_values(array_filter(array_map(static function ($row) {
            $status = trim((string)($row['status'] ?? ''));
            return [
                'label' => $status !== '' ? $status : 'Unknown',
                'value' => (int)($row['cnt'] ?? 0),
            ];
        }, $statusRows), static fn($row) => (int)($row['value'] ?? 0) > 0));
        $dashboard_branch_chart_payload['statuses'][] = [
            'branch_id' => $chartBranchId,
            'branch_name' => $chartBranchName,
            'rows' => $statusPayloadRows,
        ];

        try {
            $locationRows = pf_reports_customer_locations_merged($dashFromDate, $dashToEnd, $chartBranchId, 5, false);
        } catch (Exception $e) {
            $locationRows = [];
        }
        $locationPayloadRows = array_values(array_filter(array_map(static function ($row) {
            $city = trim((string)($row['city'] ?? ''));
            return [
                'label' => $city !== '' ? $city : 'Unknown',
                'value' => (int)($row['orders'] ?? 0),
            ];
        }, $locationRows), static fn($row) => (int)($row['value'] ?? 0) > 0));
        $dashboard_branch_chart_payload['locations'][] = [
            'branch_id' => $chartBranchId,
            'branch_name' => $chartBranchName,
            'rows' => $locationPayloadRows,
        ];
    }
}

$dashboard_branch_chart_json = json_encode($dashboard_branch_chart_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($dashboard_branch_chart_json === false) {
    $dashboard_branch_chart_json = '{"products":[],"services":[],"statuses":[],"locations":[]}';
}
$dashMark('per_branch_charts');
// ── Recent Orders (last 5, branch-filtered) ──────────
try {
    [$bSqlFrag3, $bT3, $bP3] = branch_where_parts('o', $branchId);
    $bSqlFrag3 .= ' AND ' . printflow_order_archive_scope_sql('o');
    $recent_orders = db_query(
        "SELECT o.order_id, CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                o.order_date, o.total_amount, o.payment_status, o.status, b.branch_name
         FROM orders o
         LEFT JOIN customers c ON o.customer_id = c.customer_id
         LEFT JOIN branches b  ON o.branch_id  = b.id
         WHERE 1=1 {$bSqlFrag3}
         ORDER BY o.order_date DESC LIMIT 5",
        $bT3 ?: null, $bP3 ?: null
    ) ?: [];
} catch (Exception $e) { $recent_orders = []; }
$dashMark('recent_orders');

// ── Low Stock Alerts ──────────────────────────────────
$low_stock_by_branch = [];
try {
    // Dashboard reads must not perform inventory schema maintenance. Inventory
    // management pages own that setup; keeping it out of this request avoids
    // CREATE/ALTER metadata locks during normal dashboard loads.
    $all_items = db_query(
        "SELECT i.id, i.name as material_name, i.reorder_level, i.critical_level, i.unit_of_measure as unit,
                ic.name as category_name
         FROM inv_items i
         LEFT JOIN inv_categories ic ON i.category_id = ic.id
         WHERE i.status = 'ACTIVE'"
    ) ?: [];
    $allItemIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $all_items);

    // Grouped stock lookup (same rules as getStockOnHand); per-item lookup stays as fallback.
    $dashStockFor = static function (int $itemId, int $stockBranchId, ?array $bulk): float {
        if (is_array($bulk) && array_key_exists($itemId, $bulk)) {
            return (float)$bulk[$itemId];
        }
        return (float)InventoryManager::getStockOnHand($itemId, $stockBranchId);
    };
    $dashBulkStock = static function (array $ids, int $stockBranchId): ?array {
        if ($ids === [] || !method_exists('InventoryManager', 'getStockOnHandBulk')) {
            return null;
        }
        try {
            return InventoryManager::getStockOnHandBulk($ids, $stockBranchId);
        } catch (Throwable $e) {
            return null;
        }
    };

    $low_stock = [];
    $inventoryBranchId = ($branchId === 'all') ? 0 : (int)$branchId;
    $inventoryBulk = $dashBulkStock($allItemIds, $inventoryBranchId);
    foreach ($all_items as $item) {
        $soh = $dashStockFor((int)$item['id'], $inventoryBranchId, $inventoryBulk);
        $reorderLevel = max(1, (float)($item['reorder_level'] ?? 0));
        $criticalLevel = max(1, (float)($item['critical_level'] ?? 0));
        $stockStatus = printflow_resolve_stock_status($soh, $reorderLevel, $criticalLevel);
        $needsAlert = $soh <= 0 || in_array($stockStatus['key'], ['low', 'critical', 'out'], true);
        if (!$needsAlert) {
            continue;
        }
        $item['current_stock'] = $soh;
        $item['low_limit'] = $reorderLevel;
        $item['stock_status'] = $stockStatus;
        $item['ratio'] = $reorderLevel > 0 ? ($soh / $reorderLevel) : 0;
        $low_stock[] = $item;
    }
    usort($low_stock, static function ($a, $b) {
        $aOut = ((float)($a['current_stock'] ?? 0)) <= 0;
        $bOut = ((float)($b['current_stock'] ?? 0)) <= 0;
        if ($aOut !== $bOut) {
            return $aOut ? -1 : 1;
        }
        return ($a['ratio'] ?? 0) <=> ($b['ratio'] ?? 0);
    });
    $low_stock = array_slice($low_stock, 0, 5);

    if ($branchId === 'all') {
        foreach (($branchCtx['branches_list'] ?? []) as $branchRow) {
            $stockBranchId = (int)($branchRow['id'] ?? 0);
            if ($stockBranchId <= 0) {
                continue;
            }
            $stockBranchRows = [];
            $stockBranchBulk = $dashBulkStock($allItemIds, $stockBranchId);
            foreach ($all_items as $item) {
                $soh = $dashStockFor((int)$item['id'], $stockBranchId, $stockBranchBulk);
                $reorderLevel = max(1, (float)($item['reorder_level'] ?? 0));
                $criticalLevel = max(1, (float)($item['critical_level'] ?? 0));
                $stockStatus = printflow_resolve_stock_status($soh, $reorderLevel, $criticalLevel);
                $needsAlert = $soh <= 0 || in_array($stockStatus['key'], ['low', 'critical', 'out'], true);
                if (!$needsAlert) {
                    continue;
                }
                $branchItem = $item;
                $branchItem['current_stock'] = $soh;
                $branchItem['low_limit'] = $reorderLevel;
                $branchItem['stock_status'] = $stockStatus;
                $branchItem['ratio'] = $reorderLevel > 0 ? ($soh / $reorderLevel) : 0;
                $stockBranchRows[] = $branchItem;
            }
            usort($stockBranchRows, static function ($a, $b) {
                $aOut = ((float)($a['current_stock'] ?? 0)) <= 0;
                $bOut = ((float)($b['current_stock'] ?? 0)) <= 0;
                if ($aOut !== $bOut) {
                    return $aOut ? -1 : 1;
                }
                return ($a['ratio'] ?? 0) <=> ($b['ratio'] ?? 0);
            });
            $stockBranchRows = array_slice($stockBranchRows, 0, 5);
                $low_stock_by_branch[] = [
                    'branch_id' => $stockBranchId,
                    'branch_name' => trim((string)($branchRow['branch_name'] ?? 'Branch ' . $stockBranchId)),
                    'rows' => $stockBranchRows,
                ];
        }
    }
} catch (Exception $e) { $low_stock = []; $low_stock_by_branch = []; }
$dashMark('low_stock');

// Top Customers is not rendered on this page; its UNION query is skipped.
$top_customers = [];


$branchRevenueFrom = $dashFromDate;
$branchRevenueTo = $dashToDate;
$branchRevenueToEnd = $branchRevenueTo . ' 23:59:59';
$branchRevenuePrevFrom = null;
$branchRevenuePrevToEnd = null;
if ($dashRangeDays <= 31) {
    $branchRevenuePrevFrom = date('Y-m-d', strtotime($branchRevenueFrom) - (strtotime($branchRevenueTo) - strtotime($branchRevenueFrom)) - 86400);
    $branchRevenuePrevToEnd = date('Y-m-d', strtotime($branchRevenueFrom) - 86400) . ' 23:59:59';
}
$dashboard_branch_perf = pf_reports_branch_performance_merged(
    $branchRevenueFrom,
    $branchRevenueToEnd,
    $branchId,
    $branchRevenuePrevFrom,
    $branchRevenuePrevToEnd,
    true // Dashboard-only aggregate scope simplification; report callers retain their path.
);
$dashboard_branch_perf = array_values(array_filter($dashboard_branch_perf, static function ($branch) {
    return (float)($branch['revenue'] ?? 0) > 0;
}));
$dashboard_branch_period_label = date('M j, Y', strtotime($branchRevenueFrom)) . ' - ' . date('M j, Y', strtotime($branchRevenueTo));
$dashboard_branch_total_revenue = array_reduce($dashboard_branch_perf, static function ($carry, $branch) {
    return $carry + (float)($branch['revenue'] ?? 0);
}, 0.0);
// KPI and branch chart must match the same official sales breakdown rules.
if ((float)$total_revenue >= 0 && !printflow_branch_value_is_all($branchId)) {
    $dashboard_branch_total_revenue = (float)$total_revenue;
    if (!empty($dashboard_branch_perf[0])) {
        $dashboard_branch_perf[0]['revenue'] = (float)$total_revenue;
    }
}
$dashboard_branch_count = count($dashboard_branch_perf);
$dashboard_sales_revenue_footnote = ($branchId === 'all')
    ? ('Showing combined sales revenue across ' . (int)$dashboard_branch_count . ' branch' . ($dashboard_branch_count === 1 ? '' : 'es') . '.')
    : ('Showing sales revenue for ' . $dashboard_branch_display . '.');
$dashboard_top_branch = $dashboard_branch_perf[0] ?? null;
$dashboard_low_branch = $dashboard_branch_count > 0 ? $dashboard_branch_perf[$dashboard_branch_count - 1] : null;
$dashboard_avg_branch_revenue = $dashboard_branch_count > 0 ? ($dashboard_branch_total_revenue / $dashboard_branch_count) : 0.0;
$dashboard_top_branches = array_slice($dashboard_branch_perf, 0, 5);
$dashboard_total_product_revenue = array_reduce($dashboard_branch_perf, static function ($carry, $branch) {
    return $carry + (float)($branch['revenue_store'] ?? 0);
}, 0.0);
$dashboard_total_service_revenue = array_reduce($dashboard_branch_perf, static function ($carry, $branch) {
    return $carry + (float)($branch['revenue_jobs'] ?? 0);
}, 0.0);
$dashboard_product_pct = $dashboard_branch_total_revenue > 0 ? round(($dashboard_total_product_revenue / $dashboard_branch_total_revenue) * 100, 1) : 0.0;
$dashboard_service_pct = $dashboard_branch_total_revenue > 0 ? round(($dashboard_total_service_revenue / $dashboard_branch_total_revenue) * 100, 1) : 0.0;

$dashboard_branch_revenue_js = array_map(static function ($b) {
    return [
        'branch_name' => (string)($b['branch_name'] ?? 'Unknown Branch'),
        'revenue' => round((float)($b['revenue'] ?? 0), 2),
        'orders_store' => (int)($b['orders_store'] ?? 0),
        'orders_jobs' => (int)($b['orders_jobs'] ?? 0),
        'prev_revenue' => isset($b['prev_revenue']) ? (float)$b['prev_revenue'] : null,
        'growth_pct' => isset($b['growth_pct']) ? $b['growth_pct'] : null,
    ];
}, $dashboard_branch_perf);
$dashboard_branch_revenue_json = json_encode($dashboard_branch_revenue_js, JSON_UNESCAPED_UNICODE);
if ($dashboard_branch_revenue_json === false) {
    $dashboard_branch_revenue_json = '[]';
}
$dashMark('branch_performance');

// Best Selling Services bar chart — customization / job-order revenue by service category.
$dashboard_sales_bar = pf_reports_category_sales_for_dashboard_bar_chart($service_category_sales, 8);
$dashboard_sales_bar_is_category = true;
$dashboard_sales_bar_labels = array_map(static function ($r) {
    $label = trim((string)($r['category'] ?? ''));
    return mb_substr($label !== '' ? $label : 'Customization', 0, 20);
}, $dashboard_sales_bar);
$dashboard_sales_bar_values = array_map(static function ($r) {
    return round((float)($r['total'] ?? $r['revenue'] ?? 0), 2);
}, $dashboard_sales_bar);

// ── Customer Locations ────────────────────────────────
$customer_locations = [];
if (!$dashUsesBranchCharts) {
try {
    $locFrom = $dashFromDate;
    $locTo = $dashToEnd;
    $customer_locations = pf_reports_customer_locations_merged($locFrom, $locTo, $branchId, 8, false);
} catch (Exception $e) {}
}
$dashMark('customer_locations');

} // end secondary computation

// Store the computed summary unless a transient database failure (lost connection,
// timeout, deadlock, too many connections) happened during this build. Deterministic
// query errors reproduce identically on every request, so caching their result is
// no different from recomputing it, and they must not permanently disable the cache.
$dashDbErrorsAll = function_exists('printflow_db_errors') ? printflow_db_errors() : [];
$dashDbErrorsNew = array_slice($dashDbErrorsAll, $dashDbErrorsBefore);
$dashDbErrorCount = count($dashDbErrorsNew);
$dashTransientErrnos = [1040, 1053, 1203, 1205, 1213, 1317, 2002, 2003, 2006, 2013, 3024];
$dashHadTransientDbError = false;
foreach ($dashDbErrorsNew as $dashDbErr) {
    $dashErrno = (int)($dashDbErr['errno'] ?? 0);
    if (($dashDbErr['stage'] ?? '') === 'connect' || in_array($dashErrno, $dashTransientErrnos, true)) {
        $dashHadTransientDbError = true;
        break;
    }
}
if (!$dashHadTransientDbError) {
    $dashCachePayload = [];
    foreach ($dashCacheVars as $dashCacheVar) {
        $dashCachePayload[$dashCacheVar] = $$dashCacheVar ?? null;
    }
    $dashCachePayload['_branch_id'] = $branchId;
    $dashCacheState = printflow_admin_dashboard_cache_set($dashCacheKey, $dashCachePayload)
        ? $dashCacheState . '-stored'
        : $dashCacheState . '-store-failed';
} else {
    $dashCacheState .= '-store-skipped';
}
$dashMark('cache_set');
unset($dashDbErrorsAll, $dashDbErrorsNew, $dashDbErr, $dashErrno);

} // end if (!$dashCacheHit)
$dashSectionStates[$dashSection] = $dashCacheState;
} // end section cache loop

// Expose phase timings (headers only; never rendered). Example in DevTools: Network → Timing.
if (!headers_sent()) {
    $dashServerTiming = ['dash;desc="' . $dashCacheState . '"'];
    foreach ($dashSectionStates as $dashSectionName => $dashSectionState) {
        $dashServerTiming[] = $dashSectionName . '_cache;desc="' . $dashSectionState . '"';
    }
    $dashServerTiming[] = 'dberr;dur=' . (int)($dashDbErrorCount ?? 0);
    foreach ($dashTimings as $dashPhase => $dashMs) {
        $dashServerTiming[] = preg_replace('/[^a-z0-9_]/', '', (string)$dashPhase) . ';dur=' . round((float)$dashMs, 1);
    }
    $dashServerTiming[] = 'total;dur=' . round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? $dashTimingStart)) * 1000, 1);
    if (function_exists('printflow_db_perf_enabled') && printflow_db_perf_enabled()) {
        $dashDbPerf = printflow_db_perf_snapshot();
        $dashServerTiming[] = 'db_total;dur=' . round((float)$dashDbPerf['total_ms'], 1);
        $dashServerTiming[] = 'db_queries;desc="dashboard:' . (int)$dashDbPerf['count'] . '"';
    }
    header('Server-Timing: ' . implode(', ', $dashServerTiming));
}

// Opt-in, authenticated diagnostics mirror Server-Timing into inert DOM data.
// This permits inspection in browsers whose tools cannot expose response headers.
$dashDiagnostics = ($_GET['dashboard_perf'] ?? '') === '1';
$dashDiagnosticsSection = $dashBranchChartsRequest ? 'branch_charts' : ($dashSecondaryRequest ? 'secondary' : 'summary');
$dashDiagnosticsJson = $dashDiagnostics ? json_encode([
    'section' => $dashDiagnosticsSection,
    'cache' => $dashSectionStates,
    'phases_ms' => $dashTimings,
    'server_ms' => round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? $dashTimingStart)) * 1000, 1),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : null;
if ($dashBranchChartsRequest) {
    header('Cache-Control: private, no-store');
    header('Content-Type: text/html; charset=UTF-8');
    if ($dashDiagnostics) {
        echo '<script type="application/json" id="pf-dashboard-branch-charts-timing">' . htmlspecialchars((string)$dashDiagnosticsJson, ENT_NOQUOTES, 'UTF-8') . '</script>';
    }
    echo '<script type="application/json" id="pf-dashboard-branch-chart-data">' . htmlspecialchars($dashboard_branch_chart_json, ENT_NOQUOTES, 'UTF-8') . '</script>';
    exit;
}
if ($dashSecondaryRequest) {
    header('Cache-Control: private, no-store');
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/../includes/admin_dashboard_secondary_view.php';
    exit;
}
$page_title = 'Dashboard - Admin | PrintFlow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo pf_url('public/assets/css/output.css'); ?>">
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

        /* Clickable KPI cards — drill-down navigation */
        a.kpi-card.kpi-card--link {
            display: block;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,35,43,.06);
            transition: transform 0.25s ease, box-shadow 0.25s ease, filter 0.2s ease, opacity 0.2s ease;
            -webkit-tap-highlight-color: rgba(83, 197, 224, 0.25);
        }
        a.kpi-card.kpi-card--link:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 28px rgba(0,35,43,.12);
        }
        a.kpi-card.kpi-card--link:focus { outline: none; }
        a.kpi-card.kpi-card--link:focus-visible {
            outline: 2px solid #53C5E0;
            outline-offset: 3px;
        }
        a.kpi-card.kpi-card--link:active {
            transform: scale(0.99);
            box-shadow: 0 4px 14px rgba(0,35,43,.08);
        }
        a.kpi-card.kpi-card--link.is-kpi-navigating {
            pointer-events: none;
            opacity: 0.92;
        }
        .kpi-card--link .kpi-card-inner {
            position: relative;
            display: block;
            padding-bottom: 0;
        }
        .kpi-card--link .kpi-label,
        .kpi-card--link .kpi-value,
        .kpi-card--link .kpi-sub { display: block; }
        .kpi-card-cta {
            position: static;
            display: block;
            margin-top: 8px;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            letter-spacing: 0.02em;
            transition: opacity 0.25s ease, color 0.25s ease;
        }
        @media (hover: hover) {
            a.kpi-card.kpi-card--link .kpi-card-cta { opacity: 0.4; }
            a.kpi-card.kpi-card--link:hover .kpi-card-cta,
            a.kpi-card.kpi-card--link:focus-visible .kpi-card-cta { opacity: 1; color: #00232b; }
        }
        @media (hover: none) {
            a.kpi-card.kpi-card--link .kpi-card-cta { opacity: 0.75; }
        }

        /* Dashboard Grid */
        .dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px; }
        @media (max-width:1024px) { .dash-grid { grid-template-columns:1fr; } }
        .dash-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; }
        .dash-card-title { font-size:15px; font-weight:700; color:#1f2937; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
        .dash-card-title svg { width:18px; height:18px; color:#53C5E0; }

        /* Reports-style analytics card */
        .ana-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:box-shadow .2s; display:flex; flex-direction:column; padding:0; }
        .ana-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
        .ana-hd { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid #f3f4f6; gap:10px; flex-wrap:wrap; flex-shrink:0; }
        .ana-hd h3 { margin:0; font-size:14px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; white-space:nowrap; }
        .ana-hd h3 svg { width:16px; height:16px; color:#53C5E0; flex-shrink:0; }
        .ana-bd { padding:20px; flex:1; display:flex; flex-direction:column; min-height:0; }
        .ch-box { width:100%; position:relative; }

        /* Full width card */
        .dash-full { grid-column: 1 / -1; }

        /* Mini table */
        .mini-table { width:100%; border-collapse:collapse; font-size:13px; }
        .mini-table th { text-align:left; padding:8px 10px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:#9ca3af; border-bottom:1px solid #f3f4f6; }
        .mini-table td { padding:8px 10px; border-bottom:1px solid #f9fafb; }
        .mini-table tr:hover { background:#f9fafb; }

        /* Chart containers */
        .chart-wrap { position:relative; height:250px; transform:translateZ(0); }
        .chart-loading { position:absolute; inset:0; background:rgba(255,255,255,.9); display:flex; align-items:center; justify-content:center; z-index:2; border-radius:8px; }
        .chart-loading.hidden { display:none; }
        .chart-loading-spinner { width:28px; height:28px; border:3px solid #e5e7eb; border-top-color:#53C5E0; border-radius:50%; animation:chart-spin .7s linear infinite; }
        @keyframes chart-spin { to { transform:rotate(360deg); } }
        .chart-nodata { position:absolute; inset:0; display:none; align-items:center; justify-content:center; flex-direction:column; gap:8px; color:#9ca3af; font-size:13px; z-index:1; }
        .chart-nodata.visible { display:flex; }
        .dash-empty-state { min-height:180px; display:flex; align-items:center; justify-content:center; text-align:center; color:#9ca3af; font-size:13px; font-weight:600; padding:28px 12px; }
        .dash-empty-state--compact { min-height:110px; }
        .dash-branch-chart-grid { display:flex; flex-wrap:wrap; gap:14px; width:100%; align-items:flex-start; }
        .dash-branch-chart-card { flex:1 1 calc(50% - 7px); max-width:calc(50% - 7px); min-width:0; }
        .dash-branch-chart-title { text-align:left; color:#374151; font-size:12px; font-weight:700; margin:0 0 8px; }
        .dash-branch-chart-mount { position:relative; height:150px; max-width:220px; margin:0 auto; }
        .dash-branch-chart-legend { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:8px 12px; align-content:start; max-height:150px; overflow-y:auto; padding:8px 4px 0; font-size:12px; text-align:left; }
        .dash-branch-chart-legend > div { justify-content:flex-start; }
        .dash-branch-chart-legend::-webkit-scrollbar { width:6px; }
        .dash-branch-chart-legend::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
        .dash-branch-chart-grid.is-status .dash-branch-chart-mount { height:135px; max-width:190px; }
        .dash-single-chart-wrap.is-hidden,
        .products-chart.is-hidden,
        .dash-single-chart-legend.is-hidden,
        .dash-branch-chart-grid.is-hidden { display:none !important; }
        @media (max-width:900px) {
            .dash-branch-chart-card { flex-basis:100%; max-width:100%; }
        }

        /* Period dropdown (legacy tab class removed) */
        .chart-select { padding:6px 10px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; font-weight:600; background:#fff; color:#374151; width:auto; min-width:4em; max-width:100%; }
        .chart-select-period { min-width:160px; }
        .chart-select-month { min-width:92px; }
        .chart-select-year { min-width:104px; width:104px; padding-right:32px; }
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
        .period-tab { padding:5px 12px; border-radius:6px; font-size:12px; font-weight:600; border:1px solid #e5e7eb; background:#f9fafb; color:#6b7280; cursor:pointer; transition:all .15s; }
        .period-tab:hover { border-color:#53C5E0; color:#00232b; }
        .period-tab.active { background:#00232b; border-color:#00232b; color:#fff; }
        #dash-sales-chart-wrap { overflow:hidden; }
        .pf-branch-revenue-layout { display:grid; grid-template-columns:minmax(0, 1.9fr) minmax(280px, 0.9fr); gap:18px; align-items:stretch; min-height:520px; }
        .pf-branch-revenue-main { display:flex; flex-direction:column; min-width:0; }
        .pf-branch-revenue-sidebar { display:flex; flex-direction:column; gap:14px; padding:12px; border:1px solid #eef2f7; border-radius:14px; background:radial-gradient(circle at top right, rgba(83,197,224,0.12), transparent 34%), linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%); box-shadow:inset 0 1px 0 rgba(255,255,255,0.75); }
        .pf-branch-summary-title,
        .pf-branch-section-title { margin:0 0 10px; font-size:11px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; color:#475569; }
        .pf-branch-revenue-sidebar .pf-branch-summary-title { margin-bottom:10px; }
        .pf-branch-summary-grid { display:grid; gap:10px; }
        .toolbar-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; height: 38px;
            border: 1px solid #e5e7eb; background: #fff; border-radius: 8px;
            font-size: 13px; font-weight: 500; color: #374151; cursor: pointer;
            transition: all 0.15s; white-space: nowrap; box-sizing: border-box;
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #00232b; color: #00232b; background: #ecf8fb; }
        .toolbar-btn svg { flex-shrink: 0; }
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
        @media (max-width: 768px) {
            #pf-dashboard-toolbar {
                align-items: stretch !important;
                justify-content: space-between !important;
                flex-wrap: nowrap !important;
                gap: 8px !important;
            }
            #pf-dashboard-toolbar-summary {
                flex: 1 1 auto !important;
                width: auto !important;
                min-width: 0 !important;
            }
            #pf-dashboard-toolbar-summary-text {
                min-width: 0 !important;
                overflow-wrap: anywhere !important;
            }
            #pf-dashboard-toolbar > div:last-child {
                display: flex !important;
                flex: 0 0 auto !important;
                align-self: stretch !important;
                margin-left: auto !important;
            }
            #pf-dashboard-toolbar > div:last-child .toolbar-btn {
                height: auto !important;
                min-height: 100% !important;
            }
        }
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
        .pf-branch-stat { display:grid; grid-template-columns:42px minmax(0, 1fr); gap:12px; align-items:center; padding:12px; border-radius:12px; background:rgba(255,255,255,.88); border:1px solid rgba(226,232,240,.92); box-shadow:0 10px 25px rgba(15,23,42,.04); }
        .pf-branch-stat-icon { width:42px; height:42px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; }
        .pf-branch-stat-icon svg { width:18px; height:18px; }
        .pf-branch-stat-copy { min-width:0; }
        .pf-branch-stat-total .pf-branch-stat-icon { background:linear-gradient(180deg, #ecf8fb 0%, #f0fafc 100%); color:#00232b; }
        .pf-branch-stat-top .pf-branch-stat-icon { background:linear-gradient(180deg, #dcfce7 0%, #f0fdf4 100%); color:#16a34a; }
        .pf-branch-stat-low .pf-branch-stat-icon { background:linear-gradient(180deg, #f3e8ff 0%, #faf5ff 100%); color:#9333ea; }
        .pf-branch-stat-avg .pf-branch-stat-icon { background:linear-gradient(180deg, #ffedd5 0%, #fff7ed 100%); color:#f97316; }
        .pf-branch-stat-label { font-size:11px; font-weight:600; color:#64748b; margin-bottom:4px; line-height:1.35; }
        .pf-branch-stat-value { font-size:12px; font-weight:700; color:#00232b; line-height:1.25; }
        .pf-branch-stat-sub { font-size:11px; font-weight:600; margin-top:3px; line-height:1.35; }
        .pf-branch-stat-sub.pos { color:#16a34a; }
        .pf-branch-stat-sub.neg { color:#dc2626; }
        .pf-branch-stat-sub.neu { color:#475569; }
        .pf-branch-toplist,
        .pf-branch-breakdown { border-top:1px solid #e5e7eb; padding-top:14px; }
        .pf-branch-toplist-title { margin:0 0 10px; }
        .pf-branch-toplist-items { display:flex; flex-direction:column; gap:10px; }
        .pf-branch-toprow { display:grid; grid-template-columns:24px minmax(0,1fr) auto; gap:10px; align-items:center; }
        .pf-branch-rank { width:24px; height:24px; border-radius:6px; background:linear-gradient(180deg, #00232b 0%, #0F4C5C 100%); color:#fff; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
        .pf-branch-toprow-copy { min-width:0; }
        .pf-branch-toprow-name { font-size:12px; font-weight:700; color:#00232b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:5px; }
        .pf-branch-toprow-bar { height:6px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
        .pf-branch-toprow-bar > span { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg, #00232b 0%, #0F4C5C 100%); }
        .pf-branch-toprow-value { font-size:11px; font-weight:700; color:#475569; white-space:nowrap; }
        .pf-branch-breakdown-row { display:grid; gap:6px; margin-bottom:10px; }
        .pf-branch-breakdown-row:last-child { margin-bottom:0; }
        .pf-branch-breakdown-meta { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
        .pf-branch-breakdown-meta span { font-size:11px; font-weight:600; color:#64748b; }
        .pf-branch-breakdown-meta strong { font-size:12px; font-weight:700; color:#00232b; line-height:1.25; }
        .pf-branch-breakdown-bar { height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
        .pf-branch-breakdown-bar > span { display:block; height:100%; border-radius:inherit; }
        .pf-branch-breakdown-bar--product > span { background:linear-gradient(90deg, #00232b 0%, #0F4C5C 100%); }
        .pf-branch-breakdown-bar--service > span { background:linear-gradient(90deg, #53C5E0 0%, #3498DB 100%); }
        .pf-branch-footnote { display:flex; align-items:center; gap:8px; margin-top:14px; padding:10px 12px; border-top:1px solid #e5e7eb; color:#64748b; font-size:12px; }
        .pf-branch-footnote svg { width:14px; height:14px; color:#94a3b8; flex-shrink:0; }
        .pf-wide-chart-canvas--branch,
        .pf-wide-chart-canvas--branch canvas { width:100% !important; min-width:0 !important; height:100%; }

        /* Status badge */
        .badge { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600; }
        .badge-green { background:#d1fae5; color:#065f46; }
        .badge-yellow { background:#fef3c7; color:#92400e; }
        .badge-blue { background:#dbeafe; color:#1e40af; }
        .badge-red { background:#fee2e2; color:#991b1b; }
        .badge-gray { background:#f3f4f6; color:#374151; }

        /* Stock bar */
        .stock-bar { height:6px; background:#f3f4f6; border-radius:3px; overflow:hidden; width:80px; }
        .stock-bar-fill { height:100%; border-radius:3px; }
        .stock-bar-fill.danger { background:#ef4444; }
        .stock-bar-fill.warning { background:#f59e0b; }
        .stock-bar-fill.good { background:#10b981; }
        .inventory-alert-row { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:start; gap:6px 12px; padding:11px 0; border-bottom:1px solid #f3f4f6; }
        .inventory-alert-row:last-child { border-bottom:none; }
        .inventory-alert-main { min-width:0; }
        .inventory-alert-name { font-size:12px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .inventory-alert-sub { font-size:10px; color:#9ca3af; font-weight:700; text-transform:uppercase; margin-top:2px; }
        .inventory-alert-stock { font-size:12px; font-weight:800; white-space:nowrap; }
        .inventory-alert-status { grid-column:1 / -1; display:flex; align-items:center; gap:8px; min-width:0; justify-content:flex-start; }
        .inventory-alert-status .stock-bar { width:min(100%, 110px); flex:0 1 110px; }
        .inventory-alert-status span { font-size:10px; font-weight:800; white-space:nowrap; }
        .rev-donut-row { display:flex; flex-direction:column; gap:16px; }
        .rev-donut-chart { height:200px; margin-bottom:12px; }
        .rev-legend { font-size:12px; }
        .rev-legend-item { display:flex; justify-content:space-between; padding:6px 8px; border-radius:6px; margin-bottom:2px; }
        .rev-legend-item:hover { background:#f9fafb; }
        .rev-swatch { width:10px; height:10px; border-radius:3px; display:inline-block; margin-right:8px; }

        /* Customer Locations */
        .loc-list { display:flex; flex-direction:column; gap:12px; }
        .loc-list--branch { gap:10px; }
        .dash-location-branch-card, .dash-inventory-branch-card { padding-bottom:4px; }
        .dash-branch-title-row { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0 0 8px; }
        .dash-branch-title-row .dash-branch-chart-title { margin:0; }
        .dash-branch-see-all { font-size:11px; font-weight:700; color:#0d9488; text-decoration:none; white-space:nowrap; }
        .dash-branch-see-all:hover { text-decoration:underline; }
        .loc-row { display:flex; flex-direction:column; gap:6px; cursor:pointer; transition:all 0.2s; }
        .loc-row:hover { transform:translateY(-2px); }
        .loc-header { display:flex; justify-content:space-between; align-items:center; }
        .loc-name { display:flex; align-items:center; gap:8px; flex:1; }
        .loc-rank { font-size:11px; font-weight:800; color:#9ca3af; }
        .loc-city { font-size:13px; font-weight:600; color:#1f2937; }
        .loc-value { font-size:13px; font-weight:700; color:#0f172a; }
        .loc-bar-wrap { width:100%; height:24px; background:#f1f5f9; border-radius:6px; overflow:hidden; }
        .loc-bar { height:100%; background:linear-gradient(90deg, #00232b 0%, #0F4C5C 50%, #53C5E0 100%); border-radius:6px; transition:all 0.3s; }
        .loc-row:hover .loc-bar { filter:brightness(1.15); box-shadow:0 4px 12px rgba(0,35,43,0.2); }

        /* Best Selling Services (ApexCharts) */
        .products-chart { height:300px; }

        /* Top Performers toggle */
        .performer-toggle { display:flex; gap:4px; background:#f3f4f6; padding:4px; border-radius:8px; }
        .performer-btn { padding:4px 12px; font-size:12px; font-weight:600; border-radius:6px; border:1px solid transparent; cursor:pointer; transition:all 0.2s; color:#6b7280; background:transparent; line-height:1.4; }
        .performer-btn.is-active { background:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.05); color:#00232b; border-color:#e5e7eb; }
        .performer-btn:focus-visible { outline:2px solid #53C5E0; outline-offset:2px; }


        @media (max-width:768px) {
            header {
                flex-direction:row;
                align-items:center;
                justify-content:space-between;
                flex-wrap:nowrap !important;
                gap:10px;
                padding:12px 14px !important;
                min-height:64px;
            }
            header .page-title {
                flex:1 1 0 !important;
                min-width:0;
                font-size:20px !important;
                line-height:1.15;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }
            header .branch-selector-wrap {
                margin-left:auto;
                display:flex;
                justify-content:flex-end;
                flex:0 1 auto;
                max-width:46vw;
            }
            header .branch-selector-btn,
            header .branch-selector-static {
                min-width:0;
                width:auto;
                max-width:46vw;
                padding:7px 10px;
            }
            #branchSelectorLabel,
            header .branch-selector-static span:last-child {
                min-width:0;
                overflow:hidden;
                text-overflow:ellipsis;
                white-space:nowrap;
            }
            header .branch-dropdown {
                right:0;
                min-width:min(260px, calc(100vw - 32px));
            }
            .kpi-row {
                grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
                gap:12px !important;
            }
            .kpi-card {
                min-width:0;
                padding:14px 12px !important;
                border-radius:10px;
            }
            .kpi-card--link .kpi-card-inner {
                padding-bottom:0;
            }
            .kpi-label {
                font-size:10px;
                line-height:1.2;
                letter-spacing:.03em;
                overflow-wrap:anywhere;
            }
            .kpi-value {
                font-size:20px !important;
                line-height:1.15 !important;
                max-width:100%;
                white-space:normal !important;
                overflow-wrap:anywhere !important;
                word-break:break-word;
            }
            .kpi-sub {
                font-size:11px;
                line-height:1.3;
                overflow-wrap:anywhere;
            }
            .kpi-card-cta {
                position:static;
                display:block;
                margin-top:8px;
                line-height:1.2;
            }
            .dash-grid {
                gap:18px;
                margin-bottom:22px;
            }
            .dash-card {
                min-width:0;
                padding:18px !important;
                overflow:visible;
                border-radius:10px;
                box-shadow:0 8px 26px rgba(15, 23, 42, 0.07);
            }
            .dash-card-title {
                flex-wrap:nowrap !important;
                min-width:0;
                white-space:nowrap;
            }
            .dash-card-title > span:first-child,
            .chart-title-nowrap {
                min-width:0;
                white-space:nowrap !important;
                overflow:hidden;
                text-overflow:ellipsis;
            }
            .chart-header-row {
                flex-direction:row;
                align-items:center;
                justify-content:space-between;
                flex-wrap:wrap;
                gap:12px;
            }
            .chart-title-nowrap {
                white-space:nowrap;
                min-width:0;
            }
            .chart-filters {
                width:auto;
                flex-wrap:wrap;
                gap:8px;
                justify-content:flex-end;
            }
            .chart-filter-group {
                flex:0 1 auto;
                flex-wrap:wrap;
                justify-content:flex-end;
            }
            .chart-select {
                flex:0 1 auto;
                min-width:0;
                width:auto;
                max-width:130px;
            }
            .chart-select-period {
                min-width:136px;
                max-width:160px;
            }
            .chart-select-month {
                min-width:88px;
                max-width:96px;
            }
            .chart-select-year {
                min-width:104px;
                width:104px;
                max-width:112px;
                padding-right:32px;
            }
            .chart-wrap {
                height:330px !important;
                padding:10px 0 2px;
            }
            .chart-wrap canvas {
                min-width:0 !important;
                width:100% !important;
                max-width:100% !important;
            }
            #dash-sales-chart-wrap {
                overflow-x:auto !important;
                overflow-y:hidden !important;
                -webkit-overflow-scrolling:touch;
            }
            .pf-wide-chart-canvas {
                width:820px !important;
                min-width:820px !important;
                max-width:none !important;
                height:100% !important;
            }
            .pf-wide-chart-canvas canvas,
            #dash-sales-chart-wrap canvas {
                min-width:820px !important;
                width:820px !important;
                max-width:none !important;
            }
            .dash-card > div[style*="height:240px"] {
                height:280px !important;
            }
            .rev-donut-chart,
            .products-chart {
                height:320px !important;
                max-width:100%;
                overflow:visible;
            }
            #productsChart {
                min-height:320px;
            }
            .mini-table td {
                min-width:0;
                overflow-wrap:anywhere;
            }
            .stock-bar {
                max-width:100%;
            }
        }

        @media (max-width:420px) {
            header {
                gap:8px;
                padding-right:12px !important;
            }
            header .branch-selector-wrap {
                max-width:46vw;
            }
            header .branch-selector-btn,
            header .branch-selector-static {
                max-width:46vw;
            }
            .kpi-card {
                padding:12px 10px !important;
            }
            .kpi-value {
                font-size:18px !important;
            }
            .chart-header-row {
                align-items:flex-start;
            }
            .chart-filters {
                width:100%;
                justify-content:flex-start;
            }
            .chart-wrap {
                height:340px !important;
            }
            .products-chart {
                height:340px !important;
            }
        }
    </style>
</head>
<body class="printflow-admin-shell">

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <div class="main-content">
        <header class="pf-mobile-branch-inline">
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
                        <form method="get" id="reportsFilterForm" action="<?php echo htmlspecialchars(pf_admin_url('dashboard.php')); ?>">
                            <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars((string)$branchId); ?>">
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
            <?php if ($dashDiagnostics): ?><script type="application/json" id="pf-dashboard-summary-timing"><?php echo $dashDiagnosticsJson; ?></script><?php endif; ?>
            <!-- KPI Summary Row (entire card is a link; keyboard + screen-reader friendly) -->
            <div class="kpi-row">
                <a class="kpi-card indigo kpi-card--link"
                   href="<?php echo htmlspecialchars($kpiHrefCustomers); ?>"
                   aria-label="View all customers"
                   title="View customers">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Customers</span>
                        <span class="kpi-value"><?php echo number_format($total_customers); ?></span>
                        <span class="kpi-sub">Registered in <?php echo htmlspecialchars($dashboard_filter_label); ?></span>
                        <span class="kpi-card-cta" aria-hidden="true">View details →</span>
                    </span>
                </a>
                <a class="kpi-card emerald kpi-card--link"
                   href="<?php echo htmlspecialchars($kpiHrefRevenue); ?>"
                   aria-label="View revenue report and sales analytics"
                   title="View revenue report">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Revenue</span>
                        <span class="kpi-value">₱<?php echo number_format((float)$total_revenue, 2); ?></span>
                        <span class="kpi-sub"><?php echo htmlspecialchars($dashboard_filter_label); ?> period</span>
                        <span class="kpi-card-cta" aria-hidden="true">View details →</span>
                    </span>
                </a>
                <a class="kpi-card amber kpi-card--link"
                   href="<?php echo htmlspecialchars($kpiHrefOrders); ?>"
                   aria-label="View all custom orders"
                   title="View all custom orders">
                    <span class="kpi-card-inner">
      <span class="kpi-label">Total Custom Orders</span>
                        <span class="kpi-value"><?php echo number_format($total_orders); ?></span>
                        <span class="kpi-sub"><?php echo htmlspecialchars($dashboard_filter_label); ?> period</span>
                        <span class="kpi-card-cta" aria-hidden="true">View details →</span>
                    </span>
                </a>
                <a class="kpi-card rose kpi-card--link"
                   href="<?php echo htmlspecialchars($kpiHrefPending); ?>"
                   aria-label="View pending custom orders"
                   title="View pending custom orders">
                    <span class="kpi-card-inner">
      <span class="kpi-label">Pending Custom Orders</span>
                        <span class="kpi-value"><?php echo number_format($pending_orders); ?></span>
                        <span class="kpi-sub">Pending in <?php echo htmlspecialchars($dashboard_filter_label); ?></span>
                        <span class="kpi-card-cta" aria-hidden="true">View details →</span>
                    </span>
                </a>
            </div>

            <div id="pf-dashboard-secondary" data-url="<?php echo htmlspecialchars(pf_admin_url('dashboard.php', ['branch_id' => $branchId, 'from' => $dashFromDate, 'to' => $dashToDate, 'preset' => $dashPreset, 'dashboard_section' => 'secondary', 'dashboard_perf' => $dashDiagnostics ? '1' : null]), ENT_QUOTES, 'UTF-8'); ?>"<?php if ($dashDeferBranchSalesCharts): ?> data-defer-branch-charts-url="<?php echo htmlspecialchars(pf_admin_url('dashboard.php', ['branch_id' => $branchId, 'from' => $dashFromDate, 'to' => $dashToDate, 'preset' => $dashPreset, 'dashboard_section' => 'branch_charts', 'dashboard_perf' => $dashDiagnostics ? '1' : null]), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?> aria-busy="true">
                <?php require __DIR__ . '/../includes/admin_dashboard_skeleton.php'; ?>
            </div>
            </div>

        </main>
    </div>
</div>

<script src="../assets/js/admin-dashboard-secondary.js"></script>
<script>
    function printflowInitDashboardKPIs() {
        var links = document.querySelectorAll('a.kpi-card--link');
        var clearKpiNav = function (a) {
            delete a.dataset.kpiNav;
            a.classList.remove('is-kpi-navigating');
            a.removeAttribute('aria-busy');
        };
        links.forEach(function (a) {
            // Remove existing listener to avoid double-binding if it's a re-init
            a.removeEventListener('click', a._pfKpiHandler);
            a._pfKpiHandler = function (e) {
                if (a.dataset.kpiNav === '1') {
                    e.preventDefault();
                    return;
                }
                a.dataset.kpiNav = '1';
                a.classList.add('is-kpi-navigating');
                a.setAttribute('aria-busy', 'true');
                window.setTimeout(function () { clearKpiNav(a); }, 4000);
            };
            a.addEventListener('click', a._pfKpiHandler);
        });
        window.addEventListener('pageshow', function (ev) {
            if (!ev.persisted) return;
            links.forEach(clearKpiNav);
        });
    }
</script>
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
    function pfDashParseJsonAttr(el, attr, fallback) {
        if (!el) return fallback;
        var raw = el.getAttribute(attr);
        if (!raw) return fallback;
        try {
            var parsed = JSON.parse(raw);
            return parsed != null ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }
    function pfReadDashBranchRevenue() {
        return pfDashParseJsonAttr(document.getElementById('dash-sales-chart-wrap'), 'data-branch-revenue', []);
    }
    function pfDashFilterDates() {
        var fromEl = document.getElementById('fp_from');
        var toEl = document.getElementById('fp_to');
        var presetEl = document.getElementById('dash_preset');
        return {
            from: (fromEl && fromEl.value) ? fromEl.value : '<?php echo $dashFromDate; ?>',
            to: (toEl && toEl.value) ? toEl.value : '<?php echo $dashToDate; ?>',
            preset: (presetEl && presetEl.value) ? presetEl.value : '<?php echo $dashPreset; ?>'
        };
    }
    function pfWaitForChartJs(onReady, attempt) {
        if (typeof Chart !== 'undefined') {
            onReady();
            return;
        }
        var next = (attempt || 0) + 1;
        if (next > 60) return;
        setTimeout(function () { pfWaitForChartJs(onReady, next); }, 50);
    }
    function pfDashIsVisible(target) {
        if (!target || !target.getBoundingClientRect) return false;
        var rect = target.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return false;
        var root = document.querySelector('.main-content');
        if (root && root.getBoundingClientRect) {
            var rootRect = root.getBoundingClientRect();
            return rect.bottom > rootRect.top && rect.top < rootRect.bottom;
        }
        return rect.bottom > 0 && rect.top < (window.innerHeight || document.documentElement.clientHeight);
    }
    pfApplyDashChartFontDefaults();
    document.addEventListener('DOMContentLoaded', pfApplyDashChartFontDefaults);
    window.printflowTeardownDashboardCharts = function () {
        if (window.pfDashCancelSecondary) {
            try { window.pfDashCancelSecondary(); } catch (e) {}
        }
        if (window.pfDashCancelBranchCharts) {
            try { window.pfDashCancelBranchCharts(); } catch (e) {}
        }
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
        if (window.__pfDashServiceCategoryChart) {
            try { window.__pfDashServiceCategoryChart.destroy(); } catch (e) {}
            window.__pfDashServiceCategoryChart = null;
        }
        ['__pfDashBranchProductCharts', '__pfDashBranchStatusCharts', '__pfDashBranchServiceCharts', '__pfDashBranchServiceCategoryCharts'].forEach(function (key) {
            if (!window[key] || !window[key].length) return;
            window[key].forEach(function (chart) {
                try { chart.destroy(); } catch (e) {}
            });
            window[key] = [];
        });

    };
    window.printflowInitDashboardCharts = function () {
        var secondary = document.getElementById('pf-dashboard-secondary');
        if (secondary && secondary.dataset.loaded !== 'true') {
            window.pfDashLoadSecondary(secondary);
            return;
        }
        if (!document.getElementById('dashSalesChart')) return;

        printflowInitDashboardKPIs();

        pfWaitForChartJs(function () {
        window.printflowTeardownDashboardCharts();

        window.__pfDashRevealIOs = [];
        dashCtrl = new AbortController();
        var sig = { signal: dashCtrl.signal };
        var DASH_BRANCH_ID = <?php echo $branchId !== 'all' ? (int)$branchId : 'null'; ?>;
        function pfDashReadBranchCharts() {
            var dataEl = document.getElementById('pf-dashboard-branch-chart-data');
            if (!dataEl) return { products: [], services: [], statuses: [], locations: [] };
            try {
                var parsed = JSON.parse(dataEl.textContent || '{}');
                return parsed && typeof parsed === 'object' ? parsed : { products: [], services: [], statuses: [], locations: [] };
            } catch (e) {
                return { products: [], services: [], statuses: [], locations: [] };
            }
        }
        var DASH_BRANCH_CHARTS = pfDashReadBranchCharts();

        var dashAnimLong = 1750;
        var dashAnimShort = 680;
        var doughnutAnim = { animateRotate: true, animateScale: true, duration: 1500 };

        function isDashMobile() {
            return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        }

        function dashMoneyTick(v) {
            var peso = '\u20b1';
            if (!isDashMobile()) return peso + v.toLocaleString();
            if (v >= 1000000) return peso + (v / 1000000).toFixed(v >= 10000000 ? 0 : 1) + 'M';
            if (v >= 1000) return peso + (v / 1000).toFixed(v >= 10000 ? 0 : 1) + 'K';
            return peso + v.toLocaleString();
        }

        function dashEscapeHtml(value) {
            return String(value == null ? '' : value).replace(/[&<>'"]/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[ch];
            });
        }

        function dashLegendHtml(rows, colors) {
            return rows.map(function (row, index) {
                var color = colors[index % colors.length];
                return '<div style="display:flex; align-items:center; gap:6px; min-width:0;">' +
                    '<span style="width:10px; height:10px; border-radius:50%; background:' + color + '; flex:0 0 10px;"></span>' +
                    '<span style="font-weight:600; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + dashEscapeHtml(row.label) + '">' + dashEscapeHtml(row.label) + '</span>' +
                    '</div>';
            }).join('');
        }

        function dashNoDataLabel(kind) {
            if (kind === 'products') return 'No product sales';
            if (kind === 'services') return 'No service sales';
            if (kind === 'statuses') return 'No orders';
            return 'No data';
        }
        function dashSetBranchChartMode(gridId, singleWrapId, singleLegendId, enabled) {
            var grid = document.getElementById(gridId);
            var singleWrap = document.getElementById(singleWrapId);
            var singleLegend = singleLegendId ? document.getElementById(singleLegendId) : null;
            if (grid) grid.classList.toggle('is-hidden', !enabled);
            if (singleWrap) singleWrap.classList.toggle('is-hidden', enabled);
            if (singleLegend) singleLegend.classList.toggle('is-hidden', enabled);
            return grid;
        }

        function dashRenderBranchDoughnuts(kind, gridId, singleWrapId, singleLegendId, storeKey, mountHeight) {
            var branches = (DASH_BRANCH_CHARTS && DASH_BRANCH_CHARTS[kind]) || [];
            if (DASH_BRANCH_ID !== null || !branches.length) return false;
            var grid = dashSetBranchChartMode(gridId, singleWrapId, singleLegendId, true);
            if (!grid) return false;
            var colors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
            grid.innerHTML = '';
            window[storeKey] = [];
            branches.forEach(function (branch, branchIndex) {
                var rows = (branch.rows || []).filter(function (row) { return Number(row.value || 0) > 0; });
                var hasData = rows.length > 0;
                if (!hasData) rows = [{ label: dashNoDataLabel(kind), value: 1 }];
                var chartColors = hasData ? colors : ['#e5e7eb'];
                var card = document.createElement('div');
                card.className = 'dash-branch-chart-card';
                var canvasId = gridId + '-canvas-' + branchIndex;
                card.innerHTML = '<div class="dash-branch-chart-title">' + dashEscapeHtml(branch.branch_name || 'Branch') + '</div>' +
                    '<div class="dash-branch-chart-mount" style="height:' + (mountHeight || 150) + 'px;"><canvas id="' + canvasId + '"></canvas></div>' +
                    '<div class="dash-branch-chart-legend">' + dashLegendHtml(rows, chartColors) + '</div>';
                grid.appendChild(card);
                var cv = document.getElementById(canvasId);
                if (!cv) return;
                var chart = new Chart(cv.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: rows.map(function (row) { return row.label; }),
                        datasets: [{
                            data: rows.map(function (row) { return Number(row.value) || 0; }),
                            backgroundColor: rows.map(function (_, i) { return chartColors[i % chartColors.length]; }),
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        animation: doughnutAnim,
                        plugins: { legend: { display: false }, tooltip: { animation: { duration: 160 }, cornerRadius: 8, enabled: hasData } }
                    }
                });
                window[storeKey].push(chart);
            });
            return true;
        }
        function dashRenderBranchServiceBars() {
            var branches = (DASH_BRANCH_CHARTS && DASH_BRANCH_CHARTS.services) || [];
            if (DASH_BRANCH_ID !== null || !branches.length || typeof ApexCharts === 'undefined') return false;
            var grid = dashSetBranchChartMode('dash-service-branch-charts', 'dash-service-single-chart', null, true);
            if (!grid) return false;
            grid.innerHTML = '';
            window.__pfDashBranchServiceCharts = [];
            branches.forEach(function (branch, branchIndex) {
                var rows = (branch.rows || []).filter(function (row) { return Number(row.value || 0) > 0; }).slice(0, 8);
                var card = document.createElement('div');
                card.className = 'dash-branch-chart-card';
                var mountId = 'dash-service-branch-chart-' + branchIndex;
                if (!rows.length) {
                    card.innerHTML = '<div class="dash-branch-chart-title">' + dashEscapeHtml(branch.branch_name || 'Branch') + '</div><div class="dash-empty-state dash-empty-state--compact">No service sales for this branch.</div>';
                    grid.appendChild(card);
                    return;
                }
                card.innerHTML = '<div class="dash-branch-chart-title">' + dashEscapeHtml(branch.branch_name || 'Branch') + '</div>' +
                    '<div class="dash-branch-chart-mount" style="height:190px; max-width:100%;"><div id="' + mountId + '"></div></div>';
                grid.appendChild(card);
                var chart = new ApexCharts(document.getElementById(mountId), {
                    chart: { type: 'bar', height: 190, toolbar: { show: false } },
                    series: [{ name: 'Sales (PHP)', data: rows.map(function (row) { return Number(row.value) || 0; }) }],
                    xaxis: { categories: rows.map(function (row) { return String(row.label || '').slice(0, 20); }), labels: { style: { fontSize: '10px' } } },
                    yaxis: { labels: { maxWidth: 118, style: { fontSize: '10px' } } },
                    colors: ['#00232b'],
                    plotOptions: { bar: { horizontal: true, borderRadius: 5, barHeight: '58%' } },
                    dataLabels: { enabled: false },
                    grid: { borderColor: '#f3f4f6', padding: { left: 0, right: 4 } },
                    tooltip: { theme: 'dark' }
                });
                chart.render();
                window.__pfDashBranchServiceCharts.push(chart);
            });
            return true;
        }

        function bindWhenVisible(target, onFirst) {
            if (!target || typeof onFirst !== 'function') return;
            var fired = false;
            function runOnce() {
                if (fired) return;
                fired = true;
                onFirst();
            }
            if (pfDashIsVisible(target)) {
                requestAnimationFrame(runOnce);
                return;
            }
            if (typeof IntersectionObserver === 'undefined') {
                requestAnimationFrame(runOnce);
                return;
            }
            var root = document.querySelector('.main-content');
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting || fired) return;
                    try { io.disconnect(); } catch (e) {}
                    runOnce();
                });
            }, { root: root || null, threshold: 0.08, rootMargin: '0px 0px 0px 0px' });
            io.observe(target);
            window.__pfDashRevealIOs.push(io);
        }

        function loadSalesChart(period) {
            if (!window.__pfDashSalesChart) return;
            var loadingEl = document.getElementById('dash-sales-loading');
            var noDataEl = document.getElementById('dash-sales-nodata');
            if (loadingEl) loadingEl.classList.remove('hidden');
            if (noDataEl) noDataEl.classList.remove('visible');

            try {
                var branchRows = pfReadDashBranchRevenue().slice();
                branchRows = branchRows.filter(function (row) {
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
                if (!window.__pfDashSalesChart) return;
                window.__pfDashSalesRows = branchRows;
                window.__pfDashSalesChart.data.labels = labels;
                window.__pfDashSalesChart.data.datasets[0].data = revenue;
                window.__pfDashSalesChart.data.datasets[0].backgroundColor = fills;
                window.__pfDashSalesChart.data.datasets[0].borderColor = borders;
                if (window.__pfDashSalesChart.options && window.__pfDashSalesChart.options.scales && window.__pfDashSalesChart.options.scales.y) {
                    window.__pfDashSalesChart.options.scales.y.max = yMax;
                }
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
                console.error('loadSalesChart error:', e);
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
            var currencyFmt = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });
            var compactCurrencyFmt = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP', notation: 'compact', maximumFractionDigits: 1 });
            window.__pfDashSalesChart = new Chart(document.getElementById('dashSalesChart').getContext('2d'), {
                type: 'bar',
                data: { labels: [], datasets: [
                    {
                        label: 'Total sales revenue',
                        data: [],
                        borderColor: [],
                        backgroundColor: [],
                        borderWidth: 1.5,
                        borderRadius: 10,
                        borderSkipped: false,
                        barPercentage: 0.58,
                        categoryPercentage: 0.68
                    }
                ]},
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 32, right: 18, bottom: 0, left: 8 } },
                    animation: { duration: dashAnimLong, easing: 'easeOutCubic' },
                    interaction: { mode: 'nearest', intersect: true },
                    hover: { mode: 'index', intersect: false, animationDuration: 400 },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                color: '#00232b',
                                font: pfDashChartFont(11, '700')
                            }
                        },
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
                                    if (prev !== null && !isNaN(prev)) {
                                        label += '\nPrev: ' + currencyFmt.format(prev);
                                    }
                                    if (growth !== null && !isNaN(growth)) {
                                        label += '\nGrowth: ' + (growth > 0 ? '+' : '') + growth.toFixed(1) + '%';
                                    }
                                    return label;
                                },
                                afterLabel: function (ctx) {
                                    var totalRevenue = (window.__pfDashSalesRows || []).reduce(function (sum, row) {
                                        return sum + (Number(row.revenue) || 0);
                                    }, 0);
                                    var pct = totalRevenue > 0 ? ((Number(ctx.parsed.y) || 0) / totalRevenue) * 100 : 0;
                                    return 'Contribution: ' + pct.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y:  { beginAtZero: true, ticks: { font: pfDashChartFont(isDashMobile() ? 10 : 11, '600'), maxTicksLimit: isDashMobile() ? 5 : 7, callback: function (v) { return compactCurrencyFmt.format(Number(v) || 0); } }, grid: { color: '#f3f4f6' } },
                        x:  { ticks: { color: '#334155', font: pfDashChartFont(isDashMobile() ? 9 : 11, '600'), maxRotation: 0, minRotation: 0 }, grid: { display: false } }
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
            loadSalesChart(getChartPeriod());
        });



        // Order Status Chart - Dynamic (reloads with branch changes)
        (function () {
            if (dashRenderBranchDoughnuts('statuses', 'dash-status-branch-charts', 'dash-status-single-chart', 'status-legend', '__pfDashBranchStatusCharts', 135)) return;
            var statusChartCanvas = document.getElementById('statusChart');
            var w = statusChartCanvas ? statusChartCanvas.parentElement : null;
            bindWhenVisible(w, function () {
                window.__pfDashStatusChart = new Chart(statusChartCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: [],
                        datasets: [{
                            data: [],
                            backgroundColor: [],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        animation: doughnutAnim,
                        plugins: {
                            legend: { display: false },
                            tooltip: { animation: { duration: 160 }, cornerRadius: 8 }
                        }
                    }
                });
                loadStatusChart();
            });
        })();

        async function loadStatusChart() {
            if (!window.__pfDashStatusChart) return;
            var filterDates = pfDashFilterDates();
            var qs = [];
            if (DASH_BRANCH_ID) qs.push('branch_id=' + encodeURIComponent(DASH_BRANCH_ID));
            qs.push('from=' + encodeURIComponent(filterDates.from));
            qs.push('to=' + encodeURIComponent(filterDates.to));
            qs.push('preset=' + encodeURIComponent(filterDates.preset));
            var url = 'api_order_status_chart.php' + (qs.length ? ('?' + qs.join('&')) : '');
            try {
                var resp = await fetch(url, { credentials: 'same-origin', signal: dashCtrl.signal });
                var data = await resp.json();
                if (data.error) {
                    console.warn('Status chart API error:', data.error);
                    return;
                }
                var labels = data.labels || [];
                var counts = data.counts || [];
                var statusEmpty = document.getElementById('dash-status-empty');
                var statusWrap = document.getElementById('dash-status-single-chart');
                var statusLegend = document.getElementById('status-legend');
                if (statusEmpty) statusEmpty.style.display = labels.length ? 'none' : 'block';
                if (statusWrap) statusWrap.style.display = labels.length ? 'flex' : 'none';
                if (statusLegend) statusLegend.style.display = labels.length ? 'flex' : 'none';
                var catColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
                var colors = labels.map(function(_, i) { return catColors[i % catColors.length]; });
                window.__pfDashStatusChart.data.labels = labels;
                window.__pfDashStatusChart.data.datasets[0].data = counts;
                window.__pfDashStatusChart.data.datasets[0].backgroundColor = colors;
                window.__pfDashStatusChart.update();
                
                // Update legend below chart
                updateStatusLegend(labels, counts, colors);
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                console.error('loadStatusChart error:', e);
            }
        }
        
        function updateStatusLegend(labels, counts, colors) {
            var legendContainer = document.getElementById('status-legend');
            if (!legendContainer) return;
            var total = counts.reduce(function(sum, val) { return sum + val; }, 0);
            var html = '';
            labels.forEach(function(label, i) {
                var count = counts[i];
                var color = colors[i];
                html += '<div style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">';
                html += '<span style="width:10px; height:10px; border-radius:50%; background:' + color + ';"></span>';
                html += '<span style="font-weight:600; color:#374151;">' + label + '</span>';
                html += '</div>';
            });
            legendContainer.innerHTML = html;
        }

        (function () {
            if (dashRenderBranchDoughnuts('products', 'dash-product-branch-charts', 'dash-product-single-chart', 'category-legend', '__pfDashBranchProductCharts', 150)) return;
            var cv = document.getElementById('categoryChart');
            var w = cv ? cv.parentElement : null;
            if (!cv || !w) return;
            var catColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
            var catLabels = pfDashParseJsonAttr(w, 'data-category-labels', []);
            var catTotals = pfDashParseJsonAttr(w, 'data-category-totals', []);
            if (!catLabels.length || !catTotals.length) return;
            bindWhenVisible(w, function () {
                window.__pfDashCategoryChart = new Chart(cv.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: catLabels,
                        datasets: [{
                            data: catTotals,
                            backgroundColor: catColors.slice(0, catLabels.length),
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
                
                // Update category legend
                var legendContainer = document.getElementById('category-legend');
                if (legendContainer) {
                    var html = '';
                    catLabels.forEach(function(label, i) {
                        var color = catColors[i % catColors.length];
                        html += '<div style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">';
                        html += '<span style="width:10px; height:10px; border-radius:50%; background:' + color + ';"></span>';
                        html += '<span style="font-weight:600; color:#374151;">' + label + '</span>';
                        html += '</div>';
                    });
                    legendContainer.innerHTML = html;
                }
            });
        })();

        (function () {
            if (dashRenderBranchDoughnuts('services', 'dash-service-category-branch-charts', 'dash-service-category-single-chart', 'service-category-legend', '__pfDashBranchServiceCategoryCharts', 150)) return;
            var cv = document.getElementById('serviceCategoryChart');
            var w = cv ? cv.parentElement : null;
            if (!cv || !w) return;
            var svcColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
            var svcLabels = pfDashParseJsonAttr(w, 'data-service-category-labels', []);
            var svcTotals = pfDashParseJsonAttr(w, 'data-service-category-totals', []);
            svcLabels = svcLabels.filter(function (_, i) { return Number(svcTotals[i] || 0) > 0; });
            svcTotals = svcTotals.filter(function (value) { return Number(value || 0) > 0; });
            if (!svcLabels.length || !svcTotals.length) return;
            bindWhenVisible(w, function () {
                window.__pfDashServiceCategoryChart = new Chart(cv.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: svcLabels,
                        datasets: [{
                            data: svcTotals,
                            backgroundColor: svcColors.slice(0, svcLabels.length),
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
                var legendContainer = document.getElementById('service-category-legend');
                if (legendContainer) {
                    var rows = svcLabels.map(function (label, i) { return { label: label, value: svcTotals[i] }; });
                    legendContainer.innerHTML = dashLegendHtml(rows, svcColors);
                }
            });
        })();
        (function attachDashboardChartLayout() {
            var mainEl = document.querySelector('.main-content');
            function runDashResize() {
                ['__pfDashSalesChart', '__pfDashStatusChart', '__pfDashCategoryChart', '__pfDashServiceCategoryChart'].forEach(function (k) {
                    var c = window[k];
                    if (c && typeof c.resize === 'function') {
                        try { c.resize(); } catch (e) {}
                    }
                });
                ['__pfDashBranchProductCharts', '__pfDashBranchStatusCharts', '__pfDashBranchServiceCategoryCharts'].forEach(function (k) {
                    (window[k] || []).forEach(function (c) {
                        if (c && typeof c.resize === 'function') {
                            try { c.resize(); } catch (e) {}
                        }
                    });
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

        // Best Selling Services (ApexCharts) — matches split category totals when available (same as donut)
        (function () {
            if (dashRenderBranchServiceBars()) return;
            var el = document.getElementById('productsChart');
            var dataWrap = document.getElementById('dash-service-single-chart');
            if (!el || !dataWrap || typeof ApexCharts === 'undefined') return;
            var serviceBarLabels = [];
            var serviceBarValues = [];
            try {
                serviceBarLabels = JSON.parse(dataWrap.getAttribute('data-service-bar-labels') || '[]');
                serviceBarValues = JSON.parse(dataWrap.getAttribute('data-service-bar-values') || '[]');
            } catch (e) {
                serviceBarLabels = [];
                serviceBarValues = [];
            }
            bindWhenVisible(el.parentElement, function () {
                var mobileProducts = isDashMobile();
                var chart = new ApexCharts(el, {
                    chart: { type: 'bar', height: mobileProducts ? 340 : 300, toolbar: { show: false } },
                    series: [{ name: 'Sales (₱)', data: serviceBarValues }],
                    xaxis: {
                        categories: serviceBarLabels,
                        labels: { style: { fontSize: mobileProducts ? '10px' : '11px' } }
                    },
                    yaxis: { labels: { maxWidth: mobileProducts ? 118 : 160, style: { fontSize: mobileProducts ? '10px' : '11px' } } },
                    colors: ['#00232b'],
                    plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: mobileProducts ? '58%' : '64%' } },
                    dataLabels: { enabled: !mobileProducts, style: { fontSize: '11px', fontWeight: 700 } },
                    grid: { borderColor: '#f3f4f6', padding: { left: mobileProducts ? 0 : 8, right: mobileProducts ? 4 : 12 } },
                    tooltip: { theme: 'dark' }
                });
                chart.render();
            });
        })();
        (function pfDashKickDeferredBranchCharts() {
            var secondaryEl = document.getElementById('pf-dashboard-secondary');
            if (secondaryEl && secondaryEl.dataset.deferBranchChartsUrl && typeof window.pfDashLoadDeferredBranchCharts === 'function') {
                window.pfDashLoadDeferredBranchCharts(secondaryEl);
            }
        })();
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.printflowInitDashboardCharts);
    } else {
        window.printflowInitDashboardCharts();
    }
    document.addEventListener('printflow:page-init', window.printflowInitDashboardCharts);
})();
</script>
<script>
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
    if (window.__pfDashFilterBusy) {
        window.__pfDashFilterQueued = true;
        return;
    }
    window.__pfDashLastSubmittedRange = key;
    if (window.pfDashCancelSecondary) window.pfDashCancelSecondary();
    if (window.pfDashCancelBranchCharts) window.pfDashCancelBranchCharts();
    if (window.printflowTeardownDashboardCharts) window.printflowTeardownDashboardCharts();
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
    .then(function(resp) {
        if (!resp.ok || resp.redirected) throw new Error('Dashboard filter unavailable');
        return resp.text();
    })
    .then(function(html) {
        var currentKey = fromEl.value.trim() + '|' + toEl.value.trim() + '|' + (document.getElementById('dash_preset')?.value || '');
        if (currentKey !== key || window.__pfDashFilterQueued) {
            window.__pfDashFilterQueued = true;
            return;
        }
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
        window.__pfDashLastSubmittedRange = null;
        console.error('Dashboard live filter failed:', err);
    })
    .finally(function() {
        window.__pfDashFilterBusy = false;
        if (window.__pfDashFilterQueued) {
            window.__pfDashFilterQueued = false;
            window.__pfDashLastSubmittedRange = null;
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
        selectedPreset: initialPreset || 'today',
        handleDateTyping(delay) {
            this.selectedPreset = '';
            var p = document.getElementById('dash_preset');
            if (p) p.value = '';
            window.debouncedSubmitDashboardFilter(typeof delay === 'number' ? delay : 300);
        },
        resetDateRange() {
            this.setPreset('today');
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

