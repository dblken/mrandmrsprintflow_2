<?php
ob_start(); // Start buffering at top to allow clean AJAX JSON responses
/**
 * Admin Reports & Analytics — PrintFlow
 * Modern BI dashboard with strict branch filtering + predictive analytics.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/InventoryManager.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}
$current_user = get_logged_in_user();
$is_manager = (($current_user['role'] ?? '') === 'Manager');
$is_admin = (($current_user['role'] ?? '') === 'Admin');

$reports_href_base = rtrim(AUTH_REDIRECT_BASE, '/') . '/admin/reports.php';

// ── Branch context ────────────────────────────────────────────────────────────
$branchCtx = init_branch_context(false);
$branchId  = $branchCtx['selected_branch_id'];   // 'all' | int
$branchName = $branchCtx['branch_name'];
if ($is_manager) {
    $forcedBranch = (int)(printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 0));
    if ($forcedBranch > 0) {
        $branchId = $forcedBranch;
        $branchCtx['selected_branch_id'] = $branchId;
        foreach (($branchCtx['branches_list'] ?? []) as $b) {
            if ((int)($b['id'] ?? 0) === $branchId) {
                $branchCtx['branch_name'] = $b['branch_name'];
                $branchName = $b['branch_name'];
                break;
            }
        }
    }
}
[$bSql, $bTypes, $bParams] = branch_where_parts('o', $branchId);

// ── Date range ────────────────────────────────────────────────────────────────
$from_req = $_GET['from'] ?? null;
$to_req   = $_GET['to']   ?? null;

// Default to 30 days ONLY if not set at all. If set to empty string, it means "All Time".
if ($from_req === null) {
    $from = date('Y-m-d', strtotime('-30 days'));
} else {
    $from = $from_req;
}

if ($to_req === null) {
    $to = date('Y-m-d');
} else {
    $to = $to_req;
}

// Ensure from <= to to prevent empty charts from swapped dates
if ($from !== '' && $to !== '' && strtotime($from) > strtotime($to)) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$toEnd = ($to !== '') ? $to . ' 23:59:59' : '';

/** Helper to build date WHERE clause for different table aliases */
$getDateWhere = function(string $alias = 'o', string $col = 'order_date') use ($from, $toEnd) {
    $p = []; $t = ""; $sql = "";
    if ($from !== '' && $toEnd !== '') {
        $sql = " AND {$alias}.{$col} BETWEEN ? AND ?";
        $p = [$from, $toEnd]; $t = "ss";
    } elseif ($from !== '') {
        $sql = " AND {$alias}.{$col} >= ?";
        $p = [$from]; $t = "s";
    } elseif ($toEnd !== '') {
        $sql = " AND {$alias}.{$col} <= ?";
        $p = [$toEnd]; $t = "s";
    }
    return [$sql, $t, $p];
};

/** Context helper for "All Time" (no date range) for independent timelines */
$allTimeFilter = ["", "", []];

// ── Global Filter Definitions (Sales Trend ONLY) ─────────────────────────────
$salesTrendFrom     = $from;
$salesTrendTo       = $to;
$salesTrendToEnd    = $toEnd;
$salesTrendBranchId = $branchId;

/**
 * Analytics filter context:
 * Uses the selected branch from the report filter. When Admin selects "All",
 * branch_where_parts() includes every non-archived branch (archived branches are excluded).
 */
$globalAnalyticsFrom     = '';
$globalAnalyticsTo       = '';
$globalAnalyticsToEnd    = '';
$globalAnalyticsBranchId = $branchId;

// Helpers for the decoupled context
[$gaBSql, $gaBTypes, $gaBParams] = branch_where_parts('o', $globalAnalyticsBranchId);
[$gaDW, $gaDT, $gaDP] = ["", "", []]; // Always All-Time for global context

// ── Chart sort (value_desc|value_asc|month_asc|month_desc) ────────────────────
$chart_sort = $_GET['chart_sort'] ?? 'value_desc';
$valid_sorts = ['value_desc','value_asc','month_asc','month_desc'];
if (!in_array($chart_sort, $valid_sorts)) $chart_sort = 'value_desc';

// ── Sales trend metric ─────────────────────────────────────────────────────────
// Reports chart is fixed to combined mode (Revenue + Orders) to keep one clear view.
$trend_metric = 'both';

$y_cal = (int) date('Y');
$heatmap_available_years = [];
$heatmap_year = $y_cal;

/** Stable reports URL query (explicit keys + full path fixes Turbo / relative ? links). */
function reports_page_query(array $overrides = []): string {
    $keys = ['from', 'to', 'branch_id', 'chart_sort', 'trend_metric', 'txn_pay', 'txn_page', 'heatmap_year'];
    $q = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $overrides)) {
            // Keep the override if it's set (even if empty string)
            if ($overrides[$k] !== null) {
                $q[$k] = $overrides[$k];
            }
        } elseif (isset($_GET[$k])) {
            // Keep the GET param if it's set (even if empty string)
            $q[$k] = $_GET[$k];
        }
    }
    // Remove nulls and empty strings for specific fields where they don't make sense
    // but keep 'from' and 'to' as empty if they were explicitly passed.
    return http_build_query($q);
}

// ── Recent transactions payment filter ───────────────────────────────────────
$txn_payment_filter = $_GET['txn_pay'] ?? 'all';
$txn_pay_valid = ['all','paid','unpaid','pending'];
if (!in_array($txn_payment_filter, $txn_pay_valid)) $txn_payment_filter = 'all';

// ── 1. Branch empty check (orders + customization jobs) ───────────────────────
$branch_empty = !pf_reports_branch_has_activity($branchId);
$gaBranchEmpty = !pf_reports_branch_has_activity($globalAnalyticsBranchId);

// ── Heatmap year list (only years with real data; never future years) ─────────
if (!$gaBranchEmpty) {
    // Heatmap follows globalAnalyticsBranchId (usually All Branches for Admins)
    $heatmap_available_years = pf_reports_heatmap_available_years($globalAnalyticsBranchId);
}
$heatmap_year_req = isset($_GET['heatmap_year']) ? (int) $_GET['heatmap_year'] : 0;
if ($heatmap_available_years === []) {
    $heatmap_year = $y_cal;
} elseif ($heatmap_year_req === 0) {
    $heatmap_year = in_array($y_cal, $heatmap_available_years, true) ? $y_cal : $heatmap_available_years[0];
} elseif (!in_array($heatmap_year_req, $heatmap_available_years, true)) {
    $heatmap_year = in_array($y_cal, $heatmap_available_years, true) ? $y_cal : $heatmap_available_years[0];
} else {
    $heatmap_year = $heatmap_year_req;
}

// ── 2. KPI — current period (FILTERED BY DATE RANGE) ─────────────────────────
$total_orders = $revenue = $paid_orders = $avg_val = 0;
if (!$gaBranchEmpty) {
    try {
        [$bo, $bto, $bpo] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw, $dt, $dp] = $getDateWhere('o', 'order_date');
        $orderRow = db_query(
            "SELECT COUNT(*) as total_orders,
                    SUM(CASE WHEN (o.payment_status='Paid' OR o.status='Completed') THEN o.total_amount ELSE 0 END) as revenue,
                    SUM(CASE WHEN (o.payment_status='Paid' OR o.status='Completed') THEN 1 ELSE 0 END) as paid
             FROM orders o WHERE 1=1 {$dw} {$bo}",
            $dt . $bto,
            array_merge($dp, $bpo)
        )[0] ?? [];

        [$bj, $btj, $bpj] = branch_where_parts('jo', $globalAnalyticsBranchId);
        [$dwj, $dtj, $dpj] = $getDateWhere('jo', 'created_at');
        $jobRow = db_query(
            "SELECT COUNT(*) as total_orders,
                    SUM(CASE WHEN (jo.payment_status='PAID' OR jo.status='COMPLETED')
                             THEN COALESCE(NULLIF(jo.amount_paid,0), jo.estimated_total, 0)
                             ELSE 0 END) as revenue,
                    SUM(CASE WHEN (jo.payment_status='PAID' OR jo.status='COMPLETED') THEN 1 ELSE 0 END) as paid
             FROM job_orders jo WHERE 1=1 {$dwj} {$bj}",
            $dtj . $btj,
            array_merge($dpj, $bpj)
        )[0] ?? [];

        $total_orders = (int)($orderRow['total_orders'] ?? 0) + (int)($jobRow['total_orders'] ?? 0);
        $revenue = (float)($orderRow['revenue'] ?? 0) + (float)($jobRow['revenue'] ?? 0);
        $paid_orders = (int)($orderRow['paid'] ?? 0) + (int)($jobRow['paid'] ?? 0);
        $avg_val = $paid_orders > 0 ? ($revenue / $paid_orders) : 0;
    } catch(Throwable $e){}
}
$period_has_activity = ($total_orders > 0);

// ── 2b. Sales by product category & service category (report date range + branch) ──
// Product chart: real catalog SKUs only (inner join products — same grain as revenue from store lines).
// Paid filter matches KPI/report norms and tolerates casing; no order_type exclusions (they hid valid lines).
// Service chart: service-category + row fallbacks.
$report_product_category_sales = [];
$report_service_category_sales = [];
if (!$gaBranchEmpty) {
    try {
        $report_product_category_sales = pf_reports_sales_by_official_product(
            $from,
            $toEnd,
            $globalAnalyticsBranchId
        );
    } catch (Throwable $e) {
        $report_product_category_sales = [];
    }
    try {
        $report_service_category_sales = pf_reports_sales_by_service_category(
            $globalAnalyticsFrom,
            $globalAnalyticsTo,
            $globalAnalyticsBranchId
        );
        $report_service_category_sales = pf_reports_fold_demo_service_categories(
            $report_service_category_sales,
            ['Eunsoyaaaaa', 'Ink']
        );
    } catch (Throwable $e) {
        $report_service_category_sales = [];
    }
}

// Previous period for trend arrows — only if a specific date range is set
$orders_delta = $revenue_delta = null;
if ($from !== '' && $to !== '' && !$gaBranchEmpty) {
    $days     = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $prevFrom = date('Y-m-d', strtotime($from) - $days * 86400);
    $prevToEnd = date('Y-m-d', strtotime($from) - 86400) . ' 23:59:59';
    $prev_orders = $prev_revenue = $prev_paid = 0;
    try {
        [$bo,$bto,$bpo] = branch_where_parts('o', $globalAnalyticsBranchId);
        $prevOrderRow = db_query(
            "SELECT COUNT(*) as total_orders,
                    SUM(CASE WHEN (o.payment_status='Paid' OR o.status='Completed') THEN o.total_amount ELSE 0 END) as revenue,
                    SUM(CASE WHEN (o.payment_status='Paid' OR o.status='Completed') THEN 1 ELSE 0 END) as paid
             FROM orders o WHERE o.order_date BETWEEN ? AND ?{$bo}",
            'ss' . $bto,
            array_merge([$prevFrom, $prevToEnd], $bpo)
        )[0] ?? [];
        [$bj,$btj,$bpj] = branch_where_parts('jo', $globalAnalyticsBranchId);
        $prevJobRow = db_query(
            "SELECT COUNT(*) as total_orders,
                    SUM(CASE WHEN (jo.payment_status='PAID' OR jo.status='COMPLETED')
                             THEN COALESCE(NULLIF(jo.amount_paid,0), jo.estimated_total, 0)
                             ELSE 0 END) as revenue,
                    SUM(CASE WHEN (jo.payment_status='PAID' OR jo.status='COMPLETED') THEN 1 ELSE 0 END) as paid
             FROM job_orders jo WHERE jo.created_at BETWEEN ? AND ?{$bj}",
            'ss' . $btj,
            array_merge([$prevFrom, $prevToEnd], $bpj)
        )[0] ?? [];
        $prev_orders = (int)($prevOrderRow['total_orders'] ?? 0) + (int)($prevJobRow['total_orders'] ?? 0);
        $prev_revenue = (float)($prevOrderRow['revenue'] ?? 0) + (float)($prevJobRow['revenue'] ?? 0);
        $prev_paid = (int)($prevOrderRow['paid'] ?? 0) + (int)($prevJobRow['paid'] ?? 0);
        
        $orders_delta  = $prev_orders  > 0 ? round((($total_orders - $prev_orders)  / $prev_orders)  * 100, 1) : null;
        $revenue_delta = $prev_revenue > 0 ? round((($revenue      - $prev_revenue) / $prev_revenue) * 100, 1) : null;
    } catch(Exception $e){}
}

// ── 3. Top KPI labels (FILTERED BY DATE RANGE) ─────────────────────────────────────────────────────────
$top_kpi_product = $top_kpi_location = null;
if (!$gaBranchEmpty && $total_orders > 0) {
    try {
        [$b,$bt,$bp] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw,$dt,$dp] = $getDateWhere('o', 'order_date'); // Use filtered date range
        $top_kpi_product = db_query(
            "SELECT p.name, SUM(oi.quantity) as qty FROM order_items oi
             JOIN products p ON oi.product_id=p.product_id
             JOIN orders o ON oi.order_id=o.order_id
             WHERE 1=1 {$dw} {$b}
             GROUP BY p.product_id ORDER BY qty DESC LIMIT 1",
            $dt . $bt, array_merge($dp, $bp)
        )[0] ?? null;
    } catch(Exception $e){}

    try {
        $topRows = pf_reports_customer_locations_merged($from, $toEnd, $globalAnalyticsBranchId, 1, false);
        $top_kpi_location = $topRows[0] ?? null;
        if ($top_kpi_location !== null) {
            $top_kpi_location['cnt'] = (int)($top_kpi_location['orders'] ?? 0);
        }
    } catch(Exception $e){}
}

// ── 4. Overall sales trend (Store vs Customization vs Total Orders - All-Time) ──
$trend12_labels = $trend12_revenue_store = $trend12_revenue_custom = $trend12_revenues = $trend12_orders = [];
if (!$gaBranchEmpty) {
    try {
        [$bo,$bto,$bpo] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$bj,$btj,$bpj] = branch_where_parts('jo', $globalAnalyticsBranchId);

        // Dashboard standard: Last 12 months (11 months prior + current)
        $trend_start_str = date('Y-m-d', strtotime('-11 months'));
        $trend_start_key = date('Y-m', strtotime($trend_start_str));

        $raw_store = db_query(
            "SELECT DATE_FORMAT(o.order_date,'%Y-%m') AS mon,
                    COUNT(*) AS orders_store,
                    SUM(CASE WHEN (o.payment_status='Paid' OR o.status='Completed') THEN o.total_amount ELSE 0 END) AS revenue_store
             FROM orders o
             WHERE 1=1{$bo}
             GROUP BY DATE_FORMAT(o.order_date,'%Y-%m')
             ORDER BY mon",
            $bto,
            $bpo
        ) ?: [];
        $raw_job = db_query(
            "SELECT DATE_FORMAT(COALESCE(jo.payment_verified_at, jo.created_at),'%Y-%m') AS mon,
                    COUNT(*) AS orders_custom,
                    SUM(CASE WHEN (jo.payment_status='PAID' OR jo.status='COMPLETED')
                             THEN COALESCE(NULLIF(jo.amount_paid,0), jo.estimated_total, 0)
                             ELSE 0 END) AS revenue_custom
             FROM job_orders jo
             WHERE 1=1{$bj}
             GROUP BY DATE_FORMAT(COALESCE(jo.payment_verified_at, jo.created_at),'%Y-%m')
             ORDER BY mon",
            $btj,
            $bpj
        ) ?: [];
    } catch (Exception $e) {
        $raw_store = [];
        $raw_job = [];
        $trend_start_key = date('Y-m', strtotime('-11 months'));
    }

    $mapS = [];
    foreach ($raw_store as $r) {
        $mapS[$r['mon']] = $r;
    }
    $mapJ = [];
    foreach ($raw_job as $r) {
        $mapJ[$r['mon']] = $r;
    }

    $curr_m = $trend_start_key;
    $end_m  = date('Y-m');
    while ($curr_m <= $end_m) {
        $trend12_labels[] = date('M Y', strtotime($curr_m . '-01'));
        $s  = $mapS[$curr_m] ?? [];
        $j  = $mapJ[$curr_m] ?? [];
        $rs = (float)($s['revenue_store'] ?? 0);
        $rc = (float)($j['revenue_custom'] ?? 0);
        $trend12_revenue_store[] = $rs;
        $trend12_revenue_custom[] = $rc;
        $trend12_revenues[] = $rs + $rc;
        $trend12_orders[] = (int)($s['orders_store'] ?? 0) + (int)($j['orders_custom'] ?? 0);

        // Advance one month
        $curr_m = date('Y-m', strtotime($curr_m . '-01 +1 month'));
    }
}
$forecast_revenue_store = !empty($trend12_revenue_store) ? pf_linreg($trend12_revenue_store) : 0;
$forecast_revenue_custom = !empty($trend12_revenue_custom) ? pf_linreg($trend12_revenue_custom) : 0;
$forecast_revenue = !empty($trend12_revenues) ? pf_linreg($trend12_revenues) : 0;
$forecast_orders = !empty($trend12_orders) ? (int) pf_linreg($trend12_orders) : 0;
$next_month_label = date('M Y', strtotime('+1 month'));
// Apply month sort to 12-month trend
if ($chart_sort === 'month_desc' && !empty($trend12_labels)) {
    $trend12_labels = array_reverse($trend12_labels);
    $trend12_revenue_store = array_reverse($trend12_revenue_store);
    $trend12_revenue_custom = array_reverse($trend12_revenue_custom);
    $trend12_revenues = array_reverse($trend12_revenues);
    $trend12_orders = array_reverse($trend12_orders);
}

// ── 5. Per-product forecast (last 6 months → next 3 months) ─────────────────
$fc_hist_labels = $fc_fore_labels = [];
for ($i = 5; $i >= 0; $i--) $fc_hist_labels[] = date('M y', strtotime("-$i months"));
for ($i = 1; $i <= 3; $i++)  $fc_fore_labels[] = date('M y', strtotime("+$i months"));
$fc_all_labels = array_merge($fc_hist_labels, $fc_fore_labels); // 9 labels

$fc_series_data   = [];   // [product => [hist=>[], fore=>[]]]
$fc_total_history = 0;
if (!$gaBranchEmpty) {
    try {
        [$b,$bt,$bp] = branch_where_parts('o', $globalAnalyticsBranchId);
        $fcRaw = db_query(
            "SELECT p.name AS product,
                    DATE_FORMAT(o.order_date,'%Y-%m') as mon,
                    COUNT(*) as orders
             FROM order_items oi
             JOIN products p ON oi.product_id=p.product_id
             JOIN orders o ON oi.order_id=o.order_id
             WHERE o.order_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH),'%Y-%m-01')
               AND o.order_date <  DATE_FORMAT(NOW(),'%Y-%m-01') + INTERVAL 1 MONTH$b
             GROUP BY p.product_id, p.name, mon ORDER BY p.name, mon",
            $bt, $bp
        ) ?: [];
    } catch(Exception $e){ $fcRaw = []; }

    // Group by product
    $fcByProd = [];
    foreach ($fcRaw as $r) {
        $fcByProd[$r['product']][$r['mon']] = (int)$r['orders'];
    }

    // Sort by total, take top 6
    $fcTotals = [];
    foreach ($fcByProd as $prod => $data) $fcTotals[$prod] = array_sum($data);
    arsort($fcTotals);
    $topProdFc = array_slice($fcTotals, 0, 6, true);

    foreach ($topProdFc as $prod => $_) {
        $hist = [];
        for ($i = 5; $i >= 0; $i--) {
            $k = date('Y-m', strtotime("-$i months"));
            $v = $fcByProd[$prod][$k] ?? 0;
            $hist[] = $v;
            $fc_total_history += $v;
        }
        $fore = pf_forecast3($hist);
        $lastHist = $hist[5] ?? 0;
        $lastFore = $fore[2] ?? 0; // Last forecast month (3 months ahead)
        $demand = 'moderate';
        $demandLabel = '⚠️ Moderate';
        
        // Compare last forecast to last historical month
        if ($lastHist > 0) {
            $changePercent = (($lastFore - $lastHist) / $lastHist) * 100;
            if ($changePercent > 15) {
                $demand = 'high';
                $demandLabel = '🔥 High Demand';
            } elseif ($changePercent < -15) {
                $demand = 'declining';
                $demandLabel = '⬇️ Declining';
            }
        } elseif ($lastFore > 10) {
            $demand = 'high';
            $demandLabel = '🔥 High Demand';
        }
        
        $fc_series_data[$prod] = [
            'hist' => $hist,
            'fore' => $fore,
            'demand' => $demand,
            'demandLabel' => $demandLabel,
        ];
    }
}
$can_forecast = $fc_total_history >= 20;

// ── 6. Best selling services (products + customization jobs) ────────────────
$top_products = [];
$top_products_prev = [];
if (!$gaBranchEmpty) {
    $top_products = array_map(static function ($row) {
        return [
            'product_id' => null,
            'product_name' => (string)($row['category'] ?? 'Customization'),
            'qty_sold' => (int)($row['qty_sold'] ?? 0),
            'revenue' => (float)($row['total'] ?? 0),
        ];
    }, $report_service_category_sales);
    
    if ($chart_sort === 'value_asc') {
        $top_products = array_reverse($top_products);
    }
}

// ── 7. Revenue distribution (donut) ──────────────────────────────────────────
$rev_donut = array_slice($top_products, 0, 7);
$donut_palette = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#8ED6E6', '#6B7C85', '#F39C12', '#2ECC71'];
$rev_donut_total = 0.0;
foreach ($rev_donut as $rd) {
    $rev_donut_total += round((float)($rd['revenue'] ?? 0), 2);
}

// ── 8. Order status ───────────────────────────────────────────────────────────
$status_data = [];
if (!$gaBranchEmpty) {
    try {
        [$b,$bt,$bp] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw,$dt,$dp] = ["", "", []]; // Status All Time
        $status_data = db_query(
            "SELECT o.status, COUNT(*) as cnt FROM orders o
             WHERE 1=1 {$dw} {$b}
             GROUP BY o.status ORDER BY cnt DESC",
            $dt . $bt, array_merge($dp, $bp)
        ) ?: [];
        if ($chart_sort === 'value_asc') $status_data = array_reverse($status_data);
    } catch(Exception $e){}
}

// ── 9. Seasonal heatmap (year, products + customization jobs) ────────────────
$heatmap_products = [];
if (!$gaBranchEmpty) {
    // Heatmap follows globalAnalyticsBranchId
    $heatmap_products = pf_reports_heatmap_matrix($heatmap_year, $globalAnalyticsBranchId);
    if ($chart_sort === 'value_asc') {
        $heatmap_products = array_reverse($heatmap_products, true);
    }
}

// ── 10. Customer locations (store + job transactions; customer address linked per transaction) ──
$customer_locations = [];
if (!$gaBranchEmpty) {
    try {
        $customer_locations = pf_reports_customer_locations_merged($from, $toEnd, $globalAnalyticsBranchId, 12, false);
        if ($chart_sort === 'value_asc') {
            $customer_locations = array_reverse($customer_locations);
        }
    } catch (Exception $e) {
    }
}

// ── 11. Customization usage ───────────────────────────────────────────────────
$custom_usage = [];
if (!$gaBranchEmpty) {
    try {
        [$b,$bt,$bp] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw,$dt,$dp] = ["", "", []]; // Custom All Time
        $custom_usage = db_query(
            "SELECT p.name AS product,
                    SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 1 THEN oi.quantity ELSE 0 END) AS custom_count,
                    SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 0 THEN oi.quantity ELSE 0 END) AS template_count
             FROM order_items oi
             JOIN products p ON oi.product_id = p.product_id
             JOIN orders o ON oi.order_id = o.order_id
             LEFT JOIN (
                 SELECT order_id, 1 AS has_upload
                 FROM order_designs
                 GROUP BY order_id
             ) dc ON dc.order_id = o.order_id
             WHERE 1=1 {$dw} {$b}
             GROUP BY p.product_id, p.name
             HAVING (
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 1 THEN oi.quantity ELSE 0 END) +
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 0 THEN oi.quantity ELSE 0 END)
             ) > 0
             ORDER BY (
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 1 THEN oi.quantity ELSE 0 END) +
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 0 THEN oi.quantity ELSE 0 END)
             ) DESC LIMIT 8",
            $dt . $bt, array_merge($dp, $bp)
        ) ?: [];
        if ($chart_sort === 'value_asc') $custom_usage = array_reverse($custom_usage);
    } catch(Exception $e){}
}

// ── 12. Branch performance (orders + job_orders, all-time) ─────────────────
$dash_branch_perf = pf_reports_branch_performance_merged($from, $toEnd, $globalAnalyticsBranchId);
$dash_branch_perf = array_values(array_filter($dash_branch_perf, static function ($branch) {
    return (float)($branch['revenue'] ?? 0) > 0;
}));
$dash_branch_total_revenue = array_reduce($dash_branch_perf, static function ($carry, $branch) {
    return $carry + (float)($branch['revenue'] ?? 0);
}, 0.0);
$dash_branch_count = count($dash_branch_perf);
$dash_top_branch = $dash_branch_perf[0] ?? null;
$dash_low_branch = $dash_branch_count > 0 ? $dash_branch_perf[$dash_branch_count - 1] : null;
$dash_avg_branch_revenue = $dash_branch_count > 0 ? ($dash_branch_total_revenue / $dash_branch_count) : 0.0;
$dash_top_branches = array_slice($dash_branch_perf, 0, 5);
$dash_period_label = 'All Time';
if ($from !== '' && $to !== '') {
    if (date('Y-m', strtotime($from)) === date('Y-m', strtotime($to))) {
        $dash_period_label = date('F Y', strtotime($from));
    } else {
        $dash_period_label = date('M j, Y', strtotime($from)) . ' - ' . date('M j, Y', strtotime($to));
    }
} elseif ($from !== '') {
    $dash_period_label = 'From ' . date('M j, Y', strtotime($from));
} elseif ($to !== '') {
    $dash_period_label = 'Until ' . date('M j, Y', strtotime($to));
}

$branch_perf = pf_reports_branch_performance_merged('', '', $is_manager ? $globalAnalyticsBranchId : 'all');
if ($chart_sort === 'value_asc') {
    $branch_perf = array_reverse($branch_perf);
}

// ── 13. Top customers ─────────────────────────────────────────────────────────
$top_customers = [];
if (!$gaBranchEmpty) {
    try {
        [$b,$bt,$bp] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw,$dt,$dp] = ["", "", []]; // Customers All Time
        $top_customers = db_query(
            "SELECT CONCAT(c.first_name,' ',c.last_name) as name, c.email,
                    COUNT(o.order_id) as orders,
                    SUM(CASE WHEN o.payment_status='Paid' THEN o.total_amount ELSE 0 END) as spent
             FROM customers c JOIN orders o ON c.customer_id=o.customer_id
             WHERE 1=1 {$dw} {$b}
             GROUP BY c.customer_id ORDER BY spent DESC LIMIT 8",
            $dt . $bt, array_merge($dp, $bp)
        ) ?: [];
        if ($chart_sort === 'value_asc') $top_customers = array_reverse($top_customers);
    } catch(Exception $e){}
}

// ── 14. Inventory alerts ──────────────────────────────────────────────────────
$low_stock = [];
try {
    InventoryManager::ensureBranchScopedSchema();
    $reportInventoryBranchId = ($branchId === 'all') ? null : (int)$branchId;
    $invItems = db_query(
        "SELECT i.id, i.name, i.unit_of_measure as unit, i.reorder_level, i.track_by_roll
         FROM inv_items i
         WHERE i.status = 'ACTIVE' AND i.reorder_level > 0
         ORDER BY i.name ASC"
    ) ?: [];
    foreach ($invItems as $item) {
        if ($reportInventoryBranchId === null) {
            if (!empty($item['track_by_roll'])) {
                $soh = (float)(db_query(
                    "SELECT COALESCE(SUM(remaining_length_ft), 0) AS soh
                     FROM inv_rolls
                     WHERE item_id = ? AND status = 'OPEN'",
                    'i',
                    [(int)$item['id']]
                )[0]['soh'] ?? 0);
            } else {
                $soh = (float)(db_query(
                    "SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN quantity ELSE -quantity END), 0) AS soh
                     FROM inventory_transactions
                     WHERE item_id = ?",
                    'i',
                    [(int)$item['id']]
                )[0]['soh'] ?? 0);
            }
        } else {
            $soh = (float)InventoryManager::getStockOnHand((int)$item['id'], $reportInventoryBranchId);
        }
        if ($soh <= (float)$item['reorder_level']) {
            $item['soh'] = $soh;
            $low_stock[] = $item;
        }
    }
    usort($low_stock, static fn($a, $b) => ((float)$a['soh'] <=> (float)$b['soh']));
    $low_stock = array_slice($low_stock, 0, 8);
} catch(Exception $e){}

// ── 15. Recent transactions ───────────────────────────────────────────────────
$txn_page = max(1,(int)($_GET['txn_page'] ?? 1));
$txn_per  = 10;
$txn_count = $txn_pages = 0;
$recent_orders = [];
$txn_pay_sql = '';
if ($txn_payment_filter === 'paid')     $txn_pay_sql = " AND o.payment_status = 'Paid'";
elseif ($txn_payment_filter === 'unpaid') $txn_pay_sql = " AND (o.payment_status IS NULL OR (o.payment_status != 'Paid' AND o.payment_status != 'Pending'))";
elseif ($txn_payment_filter === 'pending') $txn_pay_sql = " AND o.payment_status = 'Pending'";
if (!$gaBranchEmpty) {
    try {
        [$b,$bt,$bp] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw,$dt,$dp] = ["", "", []]; // Transactions All Time
        $txn_count = (int)(db_query(
            "SELECT COUNT(*) as cnt FROM orders o WHERE 1=1 {$dw} {$b} {$txn_pay_sql}",
            $dt . $bt, array_merge($dp, $bp)
        )[0]['cnt'] ?? 0);
        $txn_pages  = max(1, ceil($txn_count / $txn_per));
        $txn_page   = min($txn_page, $txn_pages);
        $txn_offset = ($txn_page-1) * $txn_per;
        [$b2,$bt2,$bp2] = branch_where_parts('o', $globalAnalyticsBranchId);
        [$dw2,$dt2,$dp2] = ["", "", []]; // Transactions All Time
        $recent_orders = db_query(
            "SELECT o.order_id, CONCAT(c.first_name,' ',c.last_name) as customer_name,
                    o.order_date, o.total_amount, o.payment_status, o.status
             FROM orders o LEFT JOIN customers c ON o.customer_id=c.customer_id
             WHERE 1=1 {$dw2} {$b2} {$txn_pay_sql}
             ORDER BY o.order_date DESC LIMIT $txn_per OFFSET $txn_offset",
            $dt2 . $bt2, array_merge($dp2, $bp2)
        ) ?: [];
    } catch(Exception $e){}
}

// ── 16. Customer count ────────────────────────────────────────────────────────
$cust_total = 0;
try { $cust_total = (int)(db_query("SELECT COUNT(*) as cnt FROM customers")[0]['cnt'] ?? 0); } catch(Exception $e){}

// ── Variables required by footer.php ──────────────────────────────────────────
$base_url = '/printflow';
$url_products = $base_path . '/public/products.php';
$is_logged_in = true;

// ── 17. Demand signals (data-driven) ─────────────────────────────────────────
$seasonal_events = [
    ['event'=>'Graduation Season',    'services'=>['Tarpaulin Printing','Layouts / Graphic Layout Services']],
    ['event'=>'Election Season',       'services'=>['Tarpaulin Printing','Reflectorized Stickers / Signages']],
    ['event'=>'Holiday Season',        'services'=>['Souvenirs','Stickers on Sintraboard']],
    ['event'=>"Valentine's Season",   'services'=>['Stickers','Transparent Stickers']],
    ['event'=>'School Opening Season', 'services'=>['Layouts / Graphic Layout Services','T-shirt Printing']],
    ['event'=>'Midyear Peak',          'services'=>['Decals / Stickers (Print & Cut)','Sintraboard Standees']],
];
$active_events = [];
if (!$gaBranchEmpty && $from !== '' && $toEnd !== '') {
    $min_units = 20;
    $min_uplift_pct = 25;
    $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $prevFrom = date('Y-m-d', strtotime($from) - $days * 86400);
    $prevToEnd = date('Y-m-d', strtotime($from) - 86400) . ' 23:59:59';

    $getServiceQty = function (array $services, string $rangeFrom, string $rangeToEnd) use ($globalAnalyticsBranchId): int {
        if (empty($services)) return 0;
        $placeholders = implode(',', array_fill(0, count($services), '?'));
        $types = str_repeat('s', count($services));
        $params = $services;

        $oParams = array_merge([$rangeFrom, $rangeToEnd], $params);
        $oTypes = 'ss' . $types;
        [$bO, $bOT, $bOP] = branch_where_parts('o', $globalAnalyticsBranchId);
        $oRows = db_query(
            "SELECT COALESCE(SUM(oi.quantity),0) as qty
             FROM order_items oi
             JOIN products p ON oi.product_id = p.product_id
             JOIN orders o ON oi.order_id = o.order_id
             WHERE o.payment_status = 'Paid'
               AND o.order_date BETWEEN ? AND ?
               AND p.name IN ($placeholders) $bO",
            $oTypes . $bOT,
            array_merge($oParams, $bOP)
        );
        $orderQty = (int)($oRows[0]['qty'] ?? 0);

        $jParams = array_merge([$rangeFrom, $rangeToEnd], $params);
        $jTypes = 'ss' . $types;
        [$bJ, $bJT, $bJP] = branch_where_parts('jo', $globalAnalyticsBranchId);
        $jRows = db_query(
            "SELECT COALESCE(SUM(COALESCE(jo.quantity, 1)),0) as qty
             FROM job_orders jo
             WHERE (jo.payment_status = 'PAID' OR jo.status = 'COMPLETED')
               AND jo.created_at BETWEEN ? AND ?
               AND COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization') IN ($placeholders) $bJ",
            $jTypes . $bJT,
            array_merge($jParams, $bJP)
        );
        $jobQty = (int)($jRows[0]['qty'] ?? 0);

        return $orderQty + $jobQty;
    };

    foreach ($seasonal_events as $ev) {
        $currQty = $getServiceQty($ev['services'], $from, $toEnd);
        $prevQty = $getServiceQty($ev['services'], $prevFrom, $prevToEnd);
        $deltaPct = $prevQty > 0 ? round((($currQty - $prevQty) / $prevQty) * 100, 1) : null;
        if ($currQty >= $min_units && ($deltaPct === null ? $prevQty === 0 : $deltaPct >= $min_uplift_pct)) {
            $active_events[] = $ev + ['curr' => $currQty, 'prev' => $prevQty, 'delta' => $deltaPct];
        }
    }
}

// ── 18. Auto insights ─────────────────────────────────────────────────────────
$insights = [];
if (!$gaBranchEmpty) {
    if (!empty($top_products))
        $insights[] = "<strong>{$top_products[0]['product_name']}</strong> is the top-selling service with <strong>".number_format((int)$top_products[0]['qty_sold'])."</strong> units to date.";
    if (!empty($customer_locations))
        $insights[] = "Most transactions (store + custom jobs) map to <strong>".htmlspecialchars(trim($customer_locations[0]['city']))."</strong> ({$customer_locations[0]['orders']} in period).";
    if ($forecast_revenue > 0)
        $insights[] = "Next month (<strong>$next_month_label</strong>) revenue forecast: <strong>₱".number_format($forecast_revenue,0)."</strong> based on 12-month trend.";
    if (!empty($custom_usage) && (int)$custom_usage[0]['custom_count'] > (int)$custom_usage[0]['template_count'])
        $insights[] = "<strong>".htmlspecialchars($custom_usage[0]['product'])."</strong> shows the highest custom design upload rate.";
    if (!empty($branch_perf) && count($branch_perf) > 1)
        $insights[] = "<strong>".htmlspecialchars($branch_perf[0]['branch_name'])."</strong> leads all branches with ₱".number_format((float)$branch_perf[0]['revenue'],0)." revenue.";
    if ($revenue_delta !== null) {
        if ($revenue_delta > 10)
            $insights[] = "Revenue is up <strong>{$revenue_delta}%</strong> vs. the previous period — strong growth momentum.";
        elseif ($revenue_delta < -10)
            $insights[] = "Revenue dropped <strong>".abs($revenue_delta)."%</strong> vs. the previous period — consider a promotional push.";
    }
}
foreach ($active_events as $ev) {
    $svcs = implode(' and ', array_map(fn($s)=>"<strong>$s</strong>", $ev['services']));
    if ($ev['delta'] !== null) {
        $insights[] = "<strong>{$ev['event']}</strong> demand is up <strong>{$ev['delta']}%</strong> vs previous period ({$ev['curr']} units) — services include {$svcs}.";
    } else {
        $insights[] = "<strong>{$ev['event']}</strong> demand appeared this period ({$ev['curr']} units) — services include {$svcs}.";
    }
}

$page_title = 'Reports & Analytics — Admin';
$last_updated = date('M j, Y g:i A');

// Required variables for footer.php
$base_url = '/printflow';
$url_products = $base_path . '/public/products.php';
$is_logged_in = true;

// ── Period empty (branch has orders but none in date range) ─────────────────
$period_empty = (!$branch_empty && !$period_has_activity);

// ── Top services: prev month qty for trend % (products + jobs) ───────────────
$top_products_prev = [];
if (!$gaBranchEmpty && !empty($top_products)) {
    try {
        $prevMonthStart = date('Y-m-01', strtotime('-1 month'));
        $prevMonthEnd   = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
        $prevRows = pf_reports_sales_by_service_category($prevMonthStart, $prevMonthEnd, $globalAnalyticsBranchId);
        $prevRows = pf_reports_fold_demo_service_categories($prevRows, ['Eunsoyaaaaa', 'Ink']);
        foreach ($prevRows as $r) {
            $top_products_prev['s:' . mb_strtolower((string)($r['category'] ?? 'Customization'))] = (int)($r['qty_sold'] ?? 0);
        }
    } catch (Exception $e) {
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?></title>
<?php require_once __DIR__ . '/../includes/favicon_links.php'; ?>
<link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.0/dist/apexcharts.min.js"></script>
<?php /* Chart.js: loaded once via includes/admin_style.php (pinned v3.9.1 UMD). */ ?>
<script>
function reportsPrintInPlace(url) {
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';
    document.body.appendChild(iframe);
    iframe.onload = function() {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) { console.error(e); }
        setTimeout(function() { iframe.remove(); }, 1000);
    };
    iframe.src = url;
}
</script>
<?php include __DIR__ . '/../includes/admin_style.php'; ?>
<?php render_branch_css(); ?>
<style>
/* ── Layout ─────────────────────────── */
.ana-wrap { display:flex; flex-direction:column; gap:24px; }
.ana-grid  { display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:stretch; }
.ana-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; }
@media(max-width:960px){ .ana-grid,.ana-grid3{ grid-template-columns:1fr; } }

/* ── Card (SaaS-style) ───────────────── */
.ana-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:box-shadow .2s; display:flex; flex-direction:column; }
.ana-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
.ana-hd   { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid #f3f4f6; gap:10px; flex-wrap:wrap; flex-shrink:0; }
.ana-hd h3{ margin:0; font-size:14px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; white-space:nowrap; }
.ana-hd h3 svg{ width:16px; height:16px; color:#53C5E0; flex-shrink:0; }
.ana-bd   { padding:20px; flex:1; display:flex; flex-direction:column; min-height:0; }
.ana-bd-0 { padding:0; flex:1; display:flex; flex-direction:column; min-height:0; }

/* Product Demand Forecast — tight padding; chart gets flex space, side column capped */
.ana-card.pf-forecast-card { overflow: hidden; }
.ana-card.pf-forecast-card .ana-hd { padding: 14px 18px; }
.ana-card.pf-forecast-card .ana-bd { overflow: visible; padding: 20px 24px; }
.pf-forecast-grid {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0;
    align-items: start;
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}
.pf-ch-forecast-col {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
    padding-right: 24px;
    border-right: 1px solid #e5e7eb;
}
.pf-ch-forecast-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 380px;
    overflow: hidden;
    position: relative;
}
#ch-forecast {
    width: 100% !important;
    height: 100% !important;
}
#ch-forecast > div:not(:first-child) {
    display: none !important;
}
.pf-ch-forecast-mount {
    flex: 1 1 auto;
    width: 100%;
    height: 100%;
    position: relative;
    overflow: hidden;
}
.pf-ch-forecast-mount > div:not(:first-child) {
    display: none !important;
}
.pf-forecast-side {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    width: 300px;
    flex-shrink: 0;
    overflow: hidden;
    max-height: 380px;
    padding-left: 24px;
}
.pf-forecast-side .pf-fc-side-hd {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b7280;
    margin: 0 0 12px 0;
    flex-shrink: 0;
}
.pf-forecast-side .pf-fc-side-row {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f3f4f6;
    flex-shrink: 0;
}
.pf-forecast-side .pf-fc-side-row:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}
/* Ellipsis for long names */
.pf-fc-name-truncate {
    display: inline-block;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: bottom;
}
@media (max-width: 1024px) {
    .pf-forecast-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .pf-ch-forecast-col {
        padding-right: 0;
        border-right: none;
        padding-bottom: 20px;
        border-bottom: 1px solid #e5e7eb;
    }
    .pf-forecast-side {
        width: 100%;
        max-height: none;
        padding-left: 0;
        padding-top: 20px;
    }
}
/* Y tick labels: valid SVG anchor only (no CSS translate — that spilled outside the card) */
.pf-ch-forecast-wrap .apexcharts-yaxis .apexcharts-yaxis-texts-g text.apexcharts-yaxis-label {
    text-anchor: end !important;
    dominant-baseline: middle;
    fill: #374151 !important;
    font-size: 11px !important;
    font-weight: 600 !important;
}
/* X-axis labels — enhanced visibility */
.pf-ch-forecast-wrap .apexcharts-xaxis .apexcharts-xaxis-texts-g text {
    fill: #1f2937 !important;
    font-size: 11px !important;
    font-weight: 700 !important;
}
/* Grid lines — subtle but visible */
.pf-ch-forecast-wrap .apexcharts-grid line {
    stroke: #e5e7eb !important;
    stroke-width: 1px !important;
}
/* X-axis baseline — stronger */
.pf-ch-forecast-wrap .apexcharts-xaxis line {
    stroke: #d1d5db !important;
    stroke-width: 1.5px !important;
}
/* Forecast divider line (vertical) — make it stand out */
.pf-ch-forecast-wrap .apexcharts-xcrosshairs-hidden {
    stroke: #94a3b8 !important;
    stroke-width: 2px !important;
    stroke-dasharray: 6 4 !important;
    opacity: 0.6 !important;
}
/*
 * ApexCharts still injects an HTML legend layer (max-height set) beside the SVG when legend.show is false,
 * which reserves horizontal space — collapse that slot so the plot uses full width.
 */
.pf-ch-forecast-wrap .apexcharts-legend {
    display: none !important;
    width: 0 !important;
    min-width: 0 !important;
    max-width: 0 !important;
    height: 0 !important;
    min-height: 0 !important;
    max-height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden !important;
    border: none !important;
}
/* Take legend HTML layer out of layout flow (Apex still reserves a flex column beside the SVG) */
.pf-ch-forecast-wrap .apexcharts-canvas {
    position: relative !important;
}
.pf-ch-forecast-wrap .apexcharts-canvas > div:has(> .apexcharts-legend) {
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
    margin: 0 !important;
    padding: 0 !important;
}
/* Apex 3.x embeds legend HTML in a full-viewBox <foreignObject> — collapses so it doesn't fill the canvas */
.pf-ch-forecast-wrap svg > foreignObject {
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}
/* Hide duplicate ApexCharts renders */
.pf-ch-forecast-wrap .apexcharts-canvas:not(:first-of-type) {
    display: none !important;
}
.pf-ch-forecast-wrap > div > div:not(:first-child) {
    display: none !important;
}

/* ── KPI (modern SaaS) ───────────────── */
.kpi-row  { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; align-items:stretch !important; }
@media(max-width:900px){ .kpi-row{ grid-template-columns:repeat(2,1fr); } }
.kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px 22px; position:relative; overflow:hidden; transition:all .2s; box-shadow:0 1px 3px rgba(0,0,0,.04); cursor:help; height:100% !important; display:flex; flex-direction:column; }
.kpi-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.08); }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.kpi-ind::before  { background:linear-gradient(90deg,#00232b,#53C5E0); }
.kpi-em::before   { background:linear-gradient(90deg,#059669,#34d399); }
.kpi-amb::before  { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
.kpi-vio::before  { background:linear-gradient(90deg,#7c3aed,#a78bfa); }
.kpi-lbl  { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; margin-bottom:6px; }
.kpi-val  { font-size:26px; font-weight:800; color:#111827; line-height:1.15; margin-bottom:6px; letter-spacing:-.02em; }
.kpi-sub  { font-size:12px; color:#6b7280; display:flex; align-items:center; gap:4px; flex-wrap:wrap; line-height:1.4; margin-top:auto; }
.t-up     { color:#059669; font-weight:700; }
.t-dn     { color:#dc2626; font-weight:700; }
.t-fl     { color:#6b7280; font-weight:500; }

/* ── Toolbar (Filter / Sort / Print) ─── */
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
.filter-select { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 10px; color: #1f2937; background: #fff; box-sizing: border-box; cursor: pointer; }
.filter-select:focus { outline: none; border-color: #0d9488; }
.filter-actions { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #f3f4f6; }
.filter-btn-reset { flex: 1; height: 36px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; }
.filter-btn-reset:hover { background: #f9fafb; }
.fp-preset-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 5px;
    margin-top: 8px;
}
.fp-preset-btn {
    display: inline-flex; align-items: center; justify-content: center;
    height: 28px; padding: 0 8px;
    border: 1px solid #e5e7eb; background: #fff; border-radius: 6px;
    font-size: 11px; font-weight: 500; color: #374151; cursor: pointer;
    transition: all 0.15s; white-space: nowrap; box-sizing: border-box;
    width: 100%;
}
.fp-preset-btn:hover { border-color: #9ca3af; background: #f9fafb; color: #111827; }
.fp-preset-btn.active { border-color: #00232b; background: #ecf8fb; color: #00232b; font-weight: 700; }
.filter-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; background: #0d9488; color: #fff; border-radius: 50%; font-size: 10px; font-weight: 700; }
.sort-dropdown { position: absolute; top: calc(100% + 6px); right: 0; min-width: 200px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); z-index: 200; padding: 6px 0; }
.sort-option { display: flex; align-items: center; gap: 8px; padding: 9px 16px; font-size: 13px; color: #374151; cursor: pointer; transition: background 0.1s; }
.sort-option:hover { background: #f9fafb; }
.sort-option.selected { color: #0d9488; font-weight: 600; background: #f0fdfa; }
.sort-option .check { margin-left: auto; color: #0d9488; }
.export-dropdown-wide { min-width: 280px; max-height: min(70vh, 560px); overflow-y: auto; }
.export-dd-label { padding: 10px 16px 4px; font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.06em; }
.export-dd-hr { height: 1px; background: #f3f4f6; margin: 6px 12px; border: 0; }
a.export-dd-link {
    display: block; padding: 9px 16px; font-size: 13px; color: #374151; text-decoration: none;
    transition: background 0.1s; cursor: pointer;
}
a.export-dd-link:hover { background: #f9fafb; }
[x-cloak] { display: none !important; }

/* ── Empty state ────────────────────── */
.empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:56px 24px; text-align:center; }
.empty-icon  { width:56px; height:56px; border-radius:50%; background:#f3f4f6; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; }
.empty-title { font-size:16px; font-weight:700; color:#1f2937; margin-bottom:6px; }
.empty-sub   { font-size:13px; color:#6b7280; max-width:340px; }
.empty-kpi   { font-size:24px; font-weight:800; color:#d1d5db; }

/* ── Chart boxes ────────────────────── */
.ch-box     { width:100%; position:relative; }
.ch-empty   { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:#9ca3af; font-size:13px; padding:40px 16px; }
.ch-empty svg{ opacity:.35; }
#dash-sales-nodata.visible { display:flex !important; }
.hidden     { display:none !important; }

/* Best Selling Services: let chart fill the whole card height. */
.pf-ch-products-card { display: flex; flex-direction: column; }
.pf-ch-products-card .ana-bd { display: flex; flex: 1 1 auto; min-height: 0; }
.pf-ch-products-card .ch-box { display: flex; flex: 1 1 auto; min-height: 300px; }
.pf-ch-products-card #ch-products { flex: 1 1 auto; min-height: 100%; width: 100%; }
.pf-ch-products-card { overflow: visible !important; }
.pf-ch-products-card.ana-card { overflow: visible !important; }
#ch-products .apexcharts-canvas,
#ch-products .apexcharts-svg,
#ch-products .apexcharts-inner { overflow: visible !important; }
#ch-products { overflow: visible !important; }
#ch-products .apexcharts-yaxis text {
    fill: #0f172a !important;
    font-size: 11px !important;
    font-weight: 700 !important;
}

/* ── Revenue donut (layout + custom legend) ───────────────────────── */
.rev-donut-card-hd { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.rev-donut-growth { font-size:12px; font-weight:700; white-space:nowrap; padding:4px 10px; border-radius:8px; background:#E5EEF2; color:#0F4C5C; }
.rev-donut-growth.up { background:#d1fae5; color:#047857; }
.rev-donut-growth.dn { background:#fee2e2; color:#b91c1c; }
.rev-donut-body { padding-top:4px; }
/* Chart on top, legend full-width below */
.rev-donut-row { display:flex; flex-direction:column; align-items:stretch; justify-content:flex-start; gap:18px; width:100%; }
.rev-donut-chart-wrap { flex:0 0 auto; align-self:center; width:min(100%,280px); height:240px; margin:0; }
.rev-donut-legend-wrap { flex:0 1 auto; width:100%; max-width:100%; margin:0; padding-top:4px; border-top:1px dashed #e5e7eb; }
.rev-donut-legend { list-style:none; margin:0; padding:0; column-count:2; column-gap:24px; font-size:12px; }
@media (max-width:640px) {
    .rev-donut-legend { column-count:1; }
}
.rev-donut-legend li { break-inside:avoid; display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #f3f4f6; }
.rev-donut-legend li:last-child { border-bottom:none; }
.rev-donut-swatch { flex:0 0 10px; width:10px; height:10px; border-radius:3px; margin-top:3px; }
.rev-donut-legend-txt { flex:1; min-width:0; line-height:1.35; word-wrap:break-word; overflow-wrap:anywhere; color:#374151; font-weight:600; }
.rev-donut-legend-meta { display:block; font-size:11px; font-weight:500; color:#6B7C85; margin-top:2px; }

/* Heatmap — header, scroll, legend, y-axis readability */
.heatmap-card-hd { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; width:100%; }
.heatmap-card-hd h3 { flex:1 1 auto; min-width:0; margin:0; }
.heatmap-header-tools { display:flex; align-items:center; gap:10px; flex-shrink:0; margin-left:auto; }
.heatmap-year-chip { display:inline-block; margin-left:6px; padding:2px 10px; border-radius:8px; background:#E5EEF2; color:#0F4C5C; font-size:13px; font-weight:800; vertical-align:middle; }
.heatmap-year-label { font-size:12px; font-weight:600; color:#6b7280; white-space:nowrap; }
.heatmap-year-select { min-width:5.5rem; }
.pf-heatmap-legend { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:14px 20px; margin:0 0 14px; padding:0; font-size:11px; font-weight:600; color:#475569; }
.pf-heatmap-legend .pf-hm-legend-item { display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none; transition:all 0.2s ease; padding:4px 8px; border-radius:6px; }
.pf-heatmap-legend .pf-hm-legend-item:hover { background:#f1f5f9; transform:translateY(-1px); }
.pf-heatmap-legend .pf-hm-legend-item.pf-hm-hidden { opacity:0.3; text-decoration:line-through; }
.pf-heatmap-legend .pf-hm-legend-item i { width:14px; height:14px; border-radius:4px; flex-shrink:0; border:1px solid rgba(15,23,42,.08); }
/* HTML heatmap — label column + 12 months, fluid width (no horizontal scroll) */
.pf-hm-outer { width:100%; max-width:100%; min-width:0; overflow:hidden; }
.pf-hm-root { width:100%; max-width:100%; animation:pf-hm-fade .45s ease; }
@keyframes pf-hm-fade { from { opacity:.35; transform:scale(.995); } to { opacity:1; transform:scale(1); } }
.pf-hm-grid {
    display:grid;
    grid-template-columns:minmax(0,auto) minmax(0,1fr);
    column-gap:10px;
    row-gap:6px;
    width:100%;
    max-width:100%;
    align-items:stretch;
}
.pf-hm-corner { min-height:0; min-width:0; }
.pf-hm-months {
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:clamp(2px,0.6vw,6px);
    min-width:0;
}
.pf-hm-month {
    font-size:clamp(9px,1.65vw,11px);
    font-weight:700;
    color:#475569;
    text-align:center;
    line-height:1.2;
    padding:2px 0 6px;
}
.pf-hm-label-col {
    display:flex;
    align-items:center;
    min-width:0;
    max-width:min(200px,32vw);
    position:relative;
    z-index:2;
    padding:2px 0;
}
.pf-hm-label-text {
    display:block;
    width:100%;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    font-size:clamp(10px,1.85vw,12px);
    font-weight:700;
    color:#00232b;
    line-height:1.25;
}
.pf-hm-tiles {
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:clamp(2px,0.6vw,6px);
    min-width:0;
    position:relative;
    z-index:1;
    align-items:stretch;
}
.pf-hm-cell {
    position:relative;
    z-index:1;
    min-height:clamp(26px,7vw,46px);
    padding:3px 2px;
    border-radius:6px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid rgba(15,23,42,.06);
    transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
    cursor:default;
}
.pf-hm-cell:focus { outline:2px solid #53C5E0; outline-offset:1px; }
.pf-hm-cell:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(15,23,42,.12); filter:brightness(1.03); }
.pf-hm-cell--future:hover,
.pf-hm-cell--nodata:hover { transform:none; box-shadow:none; filter:none; }
.pf-hm-cell.pf-hm-hidden { opacity:0.15 !important; pointer-events:none; }
.pf-hm-cell--low { background:#a5e3f2; }
.pf-hm-cell--med { background:#53C5E0; }
.pf-hm-cell--high { background:#00232b; }
.pf-hm-val {
    font-size:clamp(7px,2.2vw,11px);
    font-weight:800;
    font-variant-numeric:tabular-nums;
    line-height:1.1;
    text-align:center;
    padding:0 1px;
    pointer-events:none;
}
.pf-hm-cell--low .pf-hm-val,
.pf-hm-cell--med .pf-hm-val { color:#0f172a; }
.pf-hm-cell--high .pf-hm-val { color:#fff; }
.pf-hm-month--future { color:#94a3b8; font-weight:600; }
.pf-hm-cell--future {
    background:repeating-linear-gradient(-45deg,#f8fafc,#f8fafc 4px,#f1f5f9 4px,#f1f5f9 8px);
    border:1px dashed #cbd5e1;
    cursor:default;
    opacity:.88;
}
.pf-hm-cell--future .pf-hm-val { display:none; }
.pf-hm-cell--nodata {
    background:#f8fafc;
    border:1px dashed #94a3b8;
}
.pf-hm-cell--nodata .pf-hm-val { display:none; }
.pf-heatmap-note { font-size:11px; color:#64748b; margin:-4px 0 12px; line-height:1.45; max-width:52rem; }
@media (max-width:520px) {
    .pf-hm-label-col { max-width:min(160px,38vw); }
}
.pf-heatmap-chbox { min-height:200px; }
.pf-heatmap-chbox .chart-loading { position:absolute; inset:0; background:rgba(255,255,255,.88); display:flex; align-items:center; justify-content:center; z-index:6; border-radius:8px; backdrop-filter:blur(2px); }
.pf-heatmap-chbox .chart-loading.hidden { display:none !important; }
.chart-loading-spinner { width:28px; height:28px; border:3px solid #e5e7eb; border-top-color:#53C5E0; border-radius:50%; animation:pf-chart-spin .75s linear infinite; }
@keyframes pf-chart-spin { to { transform:rotate(360deg); } }
#ch-heatmap-mount { width:100%; max-width:100%; min-width:0; }

/* Chart loading (Apex) — skeleton until render completes */
.ch-box.pf-chart-loading::after {
    content:'';
    position:absolute; inset:0; border-radius:8px;
    background:linear-gradient(90deg,#f1f5f9 0%,#e2e8f0 45%,#f8fafc 55%,#f1f5f9 100%);
    background-size:200% 100%;
    animation:pf-chart-shimmer 1.1s ease-in-out infinite;
    pointer-events:none; z-index:3;
}
@keyframes pf-chart-shimmer {
    0% { background-position:100% 0; opacity:1; }
    100% { background-position:-100% 0; opacity:.85; }
}
.ch-box.pf-chart-loading .apexcharts-canvas { opacity:0; }
.ch-box:not(.pf-chart-loading) .apexcharts-canvas { transition:opacity .35s ease; }
.ch-box.pf-chart-reveal-done .apexcharts-inner { animation:pf-chart-fade-in 1.05s cubic-bezier(0.22, 1, 0.36, 1); }
@keyframes pf-chart-fade-in { from { opacity:.45; } to { opacity:1; } }
/* Promote chart SVG layer so scroll + Apex intro animations stay smoother */
.main-content .ch-box .apexcharts-inner { transform:translateZ(0); }
/* Branch comparison + customization — breathing room, avoid clipped axes */
.ch-branch-box .apexcharts-inner { padding:0 6px; }
.ch-branch-box .apexcharts-yaxis:first-of-type .apexcharts-yaxis-texts-g text { dominant-baseline: middle; }
.ch-custom-box .apexcharts-inner { padding:0 6px 4px; }
.pf-ch-products-wrap.ch-custom-box { padding:0; }
#ch-branches .apexcharts-yaxis .apexcharts-yaxis-texts-g text,
#ch-branches .apexcharts-yaxis-title text { font-weight:600; }
#ch-custom .apexcharts-xaxis .apexcharts-xaxis-texts-g text,
#ch-products .apexcharts-xaxis .apexcharts-xaxis-texts-g text { font-weight:600; }

/* Customer locations — top city */
.top-location-pill { font-size:11px; font-weight:600; color:#0F4C5C; background:#E5EEF2; padding:5px 12px; border-radius:8px; border:1px solid #cfe8ef; }

/* ApexCharts — readable tooltips & crosshair (avoid white-on-white) */
.apexcharts-tooltip { 
    color:#f8fafc !important; 
    background:#1e293b !important; 
    border:1px solid #334155 !important; 
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
    border-radius:8px !important;
    padding:0 !important;
}
.apexcharts-tooltip-title { 
    color:#e2e8f0 !important; 
    border-bottom:1px solid #334155 !important;
    background:#0f172a !important;
    padding:8px 12px !important;
    margin:0 !important;
    font-weight:700 !important;
    font-size:13px !important;
}
.apexcharts-tooltip-series-group { 
    padding:6px 12px !important;
}
.apexcharts-tooltip-y-group { 
    color:#f8fafc !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
}
.apexcharts-tooltip-marker { 
    width:8px !important;
    height:8px !important;
    border-radius:50% !important;
    margin-right:8px !important;
}
.apexcharts-tooltip-text-y-label {
    color:#cbd5e1 !important;
    font-size:12px !important;
}
.apexcharts-tooltip-text-y-value {
    color:#fff !important;
    font-weight:700 !important;
    font-size:12px !important;
}
/* Sales trend — strong crosshair + dark toolbar/zoom glyphs (Apex defaults can be near-white) */
#ch-trend .apexcharts-xcrosshairs,
#ch-trend .apexcharts-xcrosshairs line { stroke:#001018 !important; stroke-width:2px !important; opacity:1 !important; }
#ch-trend .apexcharts-ycrosshairs { stroke:#0F4C5C !important; stroke-width:1px !important; stroke-dasharray:4 3 !important; opacity:0.75 !important; }
#ch-trend .apexcharts-marker,
#ch-trend .apexcharts-marker path { fill:#00232b !important; stroke:#53C5E0 !important; stroke-width:2px !important; }
#ch-trend .apexcharts-toolbar { z-index:12; }
#ch-trend .apexcharts-toolbar svg,
#ch-trend .apexcharts-toolbar svg line,
#ch-trend .apexcharts-toolbar svg path,
#ch-trend .apexcharts-toolbar svg polyline,
#ch-trend .apexcharts-toolbar svg rect { stroke:#00232b !important; fill:#00232b !important; color:#00232b !important; }
#ch-trend .apexcharts-zoom-icon svg,
#ch-trend .apexcharts-pan-icon svg,
#ch-trend .apexcharts-reset-icon svg { stroke:#00232b !important; fill:none !important; }
.apexcharts-xcrosshairs { stroke:#00232b !important; stroke-width:1px !important; opacity:0.85 !important; }
.apexcharts-ycrosshairs { stroke:#6B7C85 !important; stroke-dasharray:4 3 !important; opacity:0.5 !important; }

/* ── Tables ─────────────────────────── */
.rpt-tbl { width:100%; border-collapse:collapse; }
.rpt-tbl th { padding:8px 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; background:#f9fafb; text-align:left; border-bottom:2px solid #e5e7eb; }
.rpt-tbl th.num { text-align:center; }
.rpt-tbl td { padding:9px 14px; font-size:13px; border-bottom:1px solid #f3f4f6; color:#374151; }
.rpt-tbl tr:hover td{ background:#f8fafc; }
.rpt-tbl-clickable tbody tr{ transition:background .15s; }
.rpt-tbl-clickable tbody tr:hover{ background:#f1f5f9 !important; }
.num      { text-align:center; font-variant-numeric:tabular-nums; font-weight:600; }

/* ── Badges ─────────────────────────── */
.badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; }
.b-green  { background:#d1fae5; color:#059669; }
.b-yellow { background:#fef3c7; color:#d97706; }
.b-amber  { background:#fef9c3; color:#854d0e; }
.b-orange { background:#ffedd5; color:#c2410c; }
.b-blue   { background:#dbeafe; color:#2563eb; }
.b-indigo { background:#e0e7ff; color:#4338ca; }
.b-cyan   { background:#cffafe; color:#0e7490; }
.b-teal   { background:#ccfbf1; color:#0f766e; }
.b-red    { background:#fee2e2; color:#dc2626; }
.b-rose   { background:#ffe4e6; color:#b91c1c; }
.b-gray   { background:#f3f4f6; color:#6b7280; }
.b-purple { background:#ede9fe; color:#7c3aed; }
.b-pink   { background:#fce7f3; color:#be185d; }

/* ── Insights ───────────────────────── */
.ins-panel { background:linear-gradient(135deg,#001018 0%,#00232b 38%,#0F4C5C 68%,#3A86A8 100%); border-radius:14px; padding:22px 26px; color:#fff; }
.ins-title { font-size:14px; font-weight:700; margin-bottom:14px; display:flex; align-items:center; gap:7px; }
.ins-list  { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:9px; }
.ins-list li{ font-size:13px; line-height:1.55; opacity:.9; display:flex; gap:9px; align-items:flex-start; }
.ins-list li::before{ content:'→'; color:#53C5E0; font-weight:700; flex-shrink:0; }
.ins-list strong{ color:#b8eaf4; }

/* ── Forecast chips ─────────────────── */
.fc-row  { display:flex; gap:14px; flex-wrap:wrap; margin-top:16px; }
.fc-chip { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15); border-radius:10px; padding:12px 16px; flex:1; min-width:140px; }
.fc-lbl  { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:rgba(255,255,255,.55); margin-bottom:4px; }
.fc-val  { font-size:20px; font-weight:800; color:#e8f8fc; line-height:1.1; }
.fc-sub  { font-size:11px; color:rgba(255,255,255,.45); margin-top:2px; }

/* ── Seasonal event badges ──────────── */
.ev-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:20px; padding:5px 12px; font-size:12px; font-weight:600; margin:4px 4px 0 0; }

/* ── Forecast product bars ──────────── */
.fc-prod-bar { height:6px; background:rgba(83,197,224,.25); border-radius:3px; overflow:hidden; }
.fc-prod-fill{ height:100%; border-radius:3px; }

/* ── Stock bar ──────────────────────── */
.sk-bar      { height:5px; background:#f3f4f6; border-radius:3px; overflow:hidden; }
.sk-fill     { height:100%; border-radius:3px; }
.sk-good     { background:#10b981; }
.sk-warn     { background:#f59e0b; }
.sk-danger   { background:#ef4444; }

/* ── Branch Performance Comparison (Enhanced Unified Chart - No Scroll) ──────────── */
.pf-brc-container { display: flex; align-items: flex-start; gap: 0; position: relative; width: 100%; border: 1px solid #f1f5f9; border-radius: 12px; padding: 16px 12px 24px; background: #fff; }
.pf-brc-y-left { width: 70px; flex-shrink: 0; height: 320px; display: flex; flex-direction: column; justify-content: space-between; font-size: 9px; font-weight: 800; color: #00232b; text-align: right; padding-right: 10px; border-right: 2px solid #e2e8f0; margin-top: 20px; margin-bottom: 65px; }
.pf-brc-y-right { width: 45px; flex-shrink: 0; height: 320px; display: flex; flex-direction: column; justify-content: space-between; font-size: 9px; font-weight: 800; color: #3b82f6; text-align: left; padding-left: 10px; border-left: 2px solid #e2e8f0; margin-top: 20px; margin-bottom: 65px; }
.pf-brc-wrap { flex: 1; overflow: hidden; padding-top: 20px; position: relative; min-width: 0; }
.pf-brc-legend { display: flex; gap: 20px; flex-wrap: wrap; align-items: center; justify-content: center; padding: 0 20px; transition: all 0.2s ease; margin-bottom: 16px; }
.pf-brc-leg { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; transition: all 0.2s ease; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; color: #475569; }
.pf-brc-leg:hover { background: #f1f5f9; transform: translateY(-1px); }
.pf-brc-leg.is-hidden { opacity: 0.3; text-decoration: line-through; }
.pf-brc-leg i { width: 14px; height: 14px; border-radius: 4px; flex-shrink: 0; border: 1px solid rgba(15,23,42,.08); }
.pf-brc-grid { height: 320px; display: flex; align-items: stretch; position: relative; border-bottom: 2px solid #334155; width: 100%; }
.pf-brc-gridline { position: absolute; left: 0; right: 0; border-top: 1px dashed #f1f5f9; pointer-events: none; }
.pf-brc-cluster { flex: 1; min-width: 0; display: flex; align-items: flex-end; gap: 4px; padding: 0 8px; height: 100%; transition: all 0.2s ease; border-right: 1px solid #f8fafc; position: relative; }
.pf-brc-cluster:hover { background: rgba(241, 245, 249, 0.6); z-index: 10; }
.pf-brc-names { display: flex; padding-left: 1px; margin-top: 8px; width: 100%; height: 75px; }
.pf-brc-name { flex: 1; min-width: 0; display: flex; justify-content: center; align-items: flex-start; padding-top: 15px; }
.pf-brc-name-txt { font-size: 9px; font-weight: 800; color: #1e293b; white-space: nowrap; transform: rotate(-40deg); transform-origin: top center; display: inline-block; max-width: 80px; text-align: right; overflow: hidden; text-overflow: ellipsis; }

.pf-brc-cluster--empty { opacity: 0.5; }
.pf-brc-bar { flex: 1; min-width: 8px; max-width: 16px; border-radius: 4px 4px 0 0; min-height: 1px; position: relative; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; transform-origin: bottom; }
.pf-brc-bar.is-hidden { opacity: 0; transform: scaleY(0); pointer-events: none; }
.pf-brc-bar:not(.is-hidden):hover { filter: brightness(1.15); transform: scaleY(1.05); z-index: 5; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.pf-brc-val { position: absolute; top: -18px; left: 50%; transform: translateX(-50%); font-size: 8px; font-weight: 800; white-space: nowrap; color: #475569; line-height: 1; pointer-events: none; }
@media (max-width: 1200px) {
    .pf-brc-cluster { gap: 3px; padding: 0 6px; }
    .pf-brc-bar { min-width: 6px; max-width: 12px; }
    .pf-brc-name-txt { font-size: 8px; max-width: 70px; }
}

/* ── Custom Card Tooltip ──────────────── */
#pf-brc-card-tooltip {
    position: fixed; z-index: 9999; pointer-events: none; visibility: hidden; opacity: 0;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15); transition: opacity 0.15s ease, visibility 0.15s ease;
    min-width: 180px; font-family: inherit;
}
.pf-tt-branch { font-weight: 800; font-size: 14px; color: #0f172a; margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
.pf-tt-row { display: flex; justify-content: space-between; gap: 12px; font-size: 12px; line-height: 1.6; }
.pf-tt-lbl { color: #64748b; font-weight: 600; }
.pf-tt-val { color: #0f172a; font-weight: 700; text-align: right; }
/* ── Customer Locations (Progress Bar Style - Match Customization Usage) ── */
.pf-loc-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px;
}
.pf-loc-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    z-index: 1;
}
.pf-loc-row:hover {
    transform: translateY(-2px);
    z-index: 10;
}
.pf-loc-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.pf-loc-name {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
}
.pf-loc-rank {
    font-size: 11px;
    font-weight: 800;
    color: #9ca3af;
    flex-shrink: 0;
}
.pf-loc-city {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.2s ease;
}
.pf-loc-value {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    flex-shrink: 0;
    transition: color 0.2s ease;
}
.pf-loc-bar-wrap {
    width: 100%;
    height: 28px;
    background: #f1f5f9;
    border-radius: 6px;
    overflow: visible;
    position: relative;
}
.pf-loc-bar {
    height: 100%;
    background: linear-gradient(90deg, #00232b 0%, #0F4C5C 50%, #53C5E0 100%);
    border-radius: 6px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: inset 0 1px 2px rgba(255,255,255,0.1);
}
.pf-loc-row:hover .pf-loc-bar {
    filter: brightness(1.15);
    box-shadow: 0 4px 12px rgba(0,35,43,0.2), inset 0 1px 2px rgba(255,255,255,0.15);
}
.pf-loc-row:hover .pf-loc-city {
    color: #00232b;
}
.pf-loc-row:hover .pf-loc-value {
    color: #00232b;
}

/* Customer Locations Tooltip (Reused from Customization Usage / ApexCharts Style) */
#pf-loc-tooltip {
    position: fixed;
    z-index: 99999;
    pointer-events: none;
    visibility: hidden;
    opacity: 0;
    color: #f8fafc !important;
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    box-shadow: 0 10px 30px rgba(0,0,0,.25) !important;
    border-radius: 8px !important;
    padding: 0 !important;
    transition: opacity 0.2s ease, visibility 0.2s ease, transform 0.2s ease;
    transform: scale(0.95);
    will-change: transform, opacity;
    font-family: inherit;
}
#pf-loc-tooltip .pf-loc-tt-city {
    color: #e2e8f0 !important;
    border-bottom: 1px solid #334155 !important;
    background: #0f172a !important;
    padding: 8px 12px !important;
    margin: 0 !important;
    font-weight: 700 !important;
    font-size: 13px !important;
}
#pf-loc-tooltip .pf-loc-tt-orders {
    padding: 6px 12px !important;
    color: #cbd5e1 !important;
    font-size: 12px !important;
}
#pf-loc-tooltip .pf-loc-tt-orders strong {
    color: #fff !important;
    font-weight: 700 !important;
}

/* ── 12-Month Trend Chart Styling ──────────────────────────────────────────── */
.trend12-chart { 
    height: 300px; 
    position: relative;
    overflow: hidden;
}

.trend12-chart canvas {
    background: linear-gradient(135deg, rgba(0,35,43,0.02) 0%, rgba(83,197,224,0.02) 100%);
    border-radius: 8px;
    position: relative;
    z-index: 2;
}

.trend12-chart::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(0,35,43,0.01) 0%, rgba(83,197,224,0.01) 100%);
    border-radius: 8px;
    pointer-events: none;
    z-index: 1;
}

/* Enhanced chart container with subtle gradient background */
.ana-card .trend12-chart {
    position: relative;
    overflow: hidden;
}

#dash-sales-chart-wrap {
    overflow: hidden;
}

.pf-branch-revenue-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.9fr) minmax(280px, 0.9fr);
    gap: 18px;
    align-items: stretch;
    min-height: 520px;
}

.pf-branch-revenue-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.pf-branch-revenue-sidebar {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 12px;
    border: 1px solid #eef2f7;
    border-radius: 14px;
    background:
        radial-gradient(circle at top right, rgba(83,197,224,0.12), transparent 34%),
        linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
}

.pf-branch-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.pf-branch-meta-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    color: #334155;
    font-size: 12px;
    font-weight: 600;
    padding: 8px 12px;
}

.pf-branch-meta-badge svg {
    width: 14px;
    height: 14px;
    color: #64748b;
}

.pf-branch-summary-title {
    margin: 0;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #2563eb;
}

.pf-branch-summary-grid {
    display: grid;
    gap: 10px;
}

.pf-branch-stat {
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    padding: 12px;
    border-radius: 12px;
    background: rgba(255,255,255,0.88);
    border: 1px solid rgba(226,232,240,0.92);
    box-shadow: 0 10px 25px rgba(15,23,42,0.04);
}

.pf-branch-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.pf-branch-stat-icon svg {
    width: 18px;
    height: 18px;
}

.pf-branch-stat-copy {
    min-width: 0;
}

.pf-branch-stat-label {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 4px;
}

.pf-branch-stat-value {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.25;
}

.pf-branch-stat-sub {
    font-size: 11px;
    font-weight: 700;
    margin-top: 3px;
}

.pf-branch-stat-total .pf-branch-stat-icon {
    background: linear-gradient(180deg, #dbeafe 0%, #eff6ff 100%);
    color: #2563eb;
}

.pf-branch-stat-top .pf-branch-stat-icon {
    background: linear-gradient(180deg, #dcfce7 0%, #f0fdf4 100%);
    color: #16a34a;
}

.pf-branch-stat-low .pf-branch-stat-icon {
    background: linear-gradient(180deg, #f3e8ff 0%, #faf5ff 100%);
    color: #9333ea;
}

.pf-branch-stat-avg .pf-branch-stat-icon {
    background: linear-gradient(180deg, #ffedd5 0%, #fff7ed 100%);
    color: #f97316;
}

.pf-branch-stat-sub.pos { color: #16a34a; }
.pf-branch-stat-sub.neg { color: #dc2626; }
.pf-branch-stat-sub.neu { color: #475569; }

.pf-branch-toplist {
    border-top: 1px solid #e5e7eb;
    padding-top: 14px;
}

.pf-branch-toplist-title {
    margin: 0 0 12px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #2563eb;
}

.pf-branch-toplist-items {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.pf-branch-toprow {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
}

.pf-branch-rank {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: linear-gradient(180deg, #2563eb 0%, #3b82f6 100%);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.pf-branch-toprow-copy {
    min-width: 0;
}

.pf-branch-toprow-name {
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 5px;
}

.pf-branch-toprow-bar {
    height: 6px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}

.pf-branch-toprow-bar > span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #3b82f6 0%, #1d4ed8 100%);
}

.pf-branch-toprow-value {
    font-size: 12px;
    font-weight: 800;
    color: #334155;
    white-space: nowrap;
}

.pf-branch-footnote {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    padding: 10px 12px;
    border-top: 1px solid #e5e7eb;
    color: #64748b;
    font-size: 12px;
}

.pf-branch-footnote svg {
    width: 14px;
    height: 14px;
    color: #94a3b8;
    flex-shrink: 0;
}

.pf-wide-chart-canvas--branch,
.pf-wide-chart-canvas--branch canvas {
    width: 100% !important;
    min-width: 0 !important;
}

@media (max-width: 768px) {
    .pf-branch-revenue-layout {
        grid-template-columns: 1fr;
        min-height: 0;
    }
    .ana-card .trend12-chart {
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch;
    }
    #dash-sales-chart-wrap {
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch;
    }
    .pf-wide-chart-canvas {
        width: 820px !important;
        min-width: 820px !important;
        max-width: none !important;
        height: 100% !important;
    }
    .pf-wide-chart-canvas canvas,
    .ana-card .trend12-chart canvas {
        min-width: 820px !important;
        width: 820px !important;
        max-width: none !important;
    }
    .pf-wide-chart-canvas--branch,
    .pf-wide-chart-canvas--branch canvas,
    #dash-sales-chart-wrap canvas {
        min-width: 760px !important;
        width: 760px !important;
        max-width: none !important;
    }
}

/* ── Print-only elements (hidden on screen) ───────────────────────────────── */
.print-report-header, .print-report-footer { display:none; }

/* ── Print-optimized layout ───────────────────────────────────────────────── */
@media print {
    .sidebar,.mobile-header,header,.no-print,.branch-context-banner{ display:none !important; }
    .main-content{ margin-left:0 !important; padding:0 !important; }
    .ana-wrap{ gap:16px !important; }
    .ana-card{ break-inside:avoid; margin-bottom:16px; box-shadow:none !important; border:1px solid #ddd !important; }
    .print-page-break{ page-break-before:always; }
    .print-page-break:first-of-type{ page-break-before:auto; }
    .ana-card:hover{ box-shadow:none !important; }
    .ana-grid,.ana-grid3{ display:block !important; }
    .print-hide{ display:none !important; }
    .print-report-header{ display:block !important; margin-bottom:20px; padding-bottom:16px; border-bottom:2px solid #333; }
    .print-report-footer{ display:block !important; margin-top:24px; padding-top:12px; border-top:1px solid #999; font-size:11px; color:#666; text-align:center; }
    .kpi-card{ box-shadow:none !important; border:1px solid #ddd !important; }
    .kpi-card::before{ display:none !important; }
    .kpi-lbl{ color:#555 !important; }
    .kpi-val{ color:#111 !important; }
    .kpi-sub{ color:#666 !important; }
    .t-up,.t-dn{ color:#333 !important; }
    .rpt-tbl th,.rpt-tbl td{ padding:10px 12px !important; font-size:12px !important; color:#222 !important; }
    .rpt-tbl th{ background:#f0f0f0 !important; color:#333 !important; }
    .rpt-tbl tr:hover td{ background:#fff !important; }
    .badge{ background:#e5e5e5 !important; color:#333 !important; border:1px solid #ccc; }
    .ins-panel{ background:#f5f5f5 !important; color:#333 !important; border:1px solid #ddd; }
    .ins-panel .fc-chip{ background:#fff !important; border:1px solid #ddd !important; }
    .ins-panel .fc-lbl,.ins-panel .fc-sub{ color:#666 !important; }
    .ins-panel .fc-val{ color:#111 !important; }
    .ins-list li::before{ color:#666 !important; }
    .ins-list strong{ color:#111 !important; }
    @page{ margin:1.5cm; size:A4; }
    body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; background:#fff !important; }
    .rpt-tbl{ width:100% !important; }
    .rpt-tbl th,.rpt-tbl td{ word-wrap:break-word; overflow-wrap:break-word; }
    .ch-box{ min-height:200px !important; }
    .ana-wrap{ max-width:100% !important; }
}
</style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
    <div class="main-content">
        <header class="pf-mobile-branch-inline">
            <h1 class="page-title">Reports <span class="pf-mobile-title-extra">& Analytics</span></h1>
            <?php if (!defined('MANAGER_PANEL') || !MANAGER_PANEL) { render_branch_selector($branchCtx); } ?>
        </header>
        <main>
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>

            <!-- ── Print Report Header (visible only when printing) ── -->
            <div class="print-report-header">
                <h1 style="margin:0 0 8px;font-size:22px;font-weight:800;color:#111;">PrintFlow Sales Report</h1>
                <div style="font-size:13px;color:#444;line-height:1.6;">
                    <strong>Branch:</strong> <?php echo htmlspecialchars($branchName); ?> &nbsp;|&nbsp;
                    <strong>Date Range:</strong> <?php echo date('M j, Y',strtotime($from)); ?> – <?php echo date('M j, Y',strtotime($to)); ?> &nbsp;|&nbsp;
                    <strong>Generated:</strong> <?php echo date('F j, Y g:i A'); ?>
                </div>
            </div>

            <!-- ── Toolbar: Filter, Sort, Print ── -->
            <?php
            // Detect active preset based on current from/to
            $today = date('Y-m-d');
            $active_p = '';
            if ($to === $today) {
                if     ($from === date('Y-m-d', strtotime('-7 days')))       $active_p = 'last_7';
                elseif ($from === date('Y-m-d', strtotime('-30 days')))      $active_p = 'last_30';
                elseif ($from === date('Y-m-01'))                            $active_p = 'this_month';
                elseif ($from === date('Y-m-d', strtotime('-3 months')))     $active_p = 'last_3';
                elseif ($from === date('Y-m-d', strtotime('-6 months')))     $active_p = 'last_6';
                elseif ($from === date('Y-m-d', strtotime('-12 months')))    $active_p = 'last_12';
            }
            ?>
            <div class="no-print" id="pf-reports-toolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;" x-data="reportsFilterPanel('<?php echo $active_p; ?>')">
                <div style="font-size:13px;color:#6b7280;" id="pf-reports-toolbar-summary">
                    <?php echo htmlspecialchars($branchName); ?> &nbsp;·&nbsp;
                    <?php if ($from !== '' && $to !== ''): ?>
                        <?php echo date('M d, Y', strtotime($from)); ?> – <?php echo date('M d, Y', strtotime($to)); ?>
                    <?php elseif ($from !== ''): ?>
                        From <?php echo date('M d, Y', strtotime($from)); ?>
                    <?php elseif ($to !== ''): ?>
                        Until <?php echo date('M d, Y', strtotime($to)); ?>
                    <?php else: ?>
                        All Time
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <!-- Filter -->
                    <div style="position:relative;">
                        <button class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                            </svg>
                            Filter
                            <span x-show="hasActiveFilters"><span class="filter-badge" x-text="filterCount"></span></span>
                        </button>
                        <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                            <div class="filter-panel-header">Filter</div>
                            <form method="GET" id="reportsFilterForm">
                                <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branchId); ?>">
                                <input type="hidden" name="chart_sort" value="<?php echo htmlspecialchars($chart_sort); ?>">
                                <input type="hidden" name="trend_metric" value="<?php echo htmlspecialchars($trend_metric); ?>">
                                <input type="hidden" name="txn_pay" value="<?php echo htmlspecialchars($txn_payment_filter); ?>">
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button type="button" class="filter-reset-link" @click="resetDateRange()">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div>
                                            <div class="filter-date-label">From:</div>
                                            <input type="date" name="from" id="fp_from" class="filter-input" value="<?php echo htmlspecialchars($from); ?>" @change="selectedPreset = ''; window.debouncedUpdateDashboard(300)">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" name="to" id="fp_to" class="filter-input" value="<?php echo htmlspecialchars($to); ?>" @change="selectedPreset = ''; window.debouncedUpdateDashboard(300)">
                                        </div>
                                    </div>
                                    <div style="margin-top:10px;">
                                        <div class="filter-date-label">Quick presets</div>
                                        <div class="fp-preset-grid">
                                            <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'last_7' }" @click="setPreset('last_7')">Last 7 days</button>
                                            <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'last_30' }" @click="setPreset('last_30')">Last 30 days</button>
                                            <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'this_month' }" @click="setPreset('this_month')">This month</button>
                                            <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'last_3' }" @click="setPreset('last_3')">Last 3 months</button>
                                            <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'last_6' }" @click="setPreset('last_6')">Last 6 months</button>
                                            <button type="button" class="fp-preset-btn" :class="{ 'active': selectedPreset === 'last_12' }" @click="setPreset('last_12')">Last 12 months</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" @click="resetFilters()" style="flex:1;">Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Export: print + Excel/CSV (aligned with admin export endpoints & staff-style CSVs) -->
                    <div style="position:relative;" x-data="{exportOpen:false}">
                        <button class="toolbar-btn" @click="exportOpen=!exportOpen" style="height:38px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Export
                        </button>
                        <div class="sort-dropdown export-dropdown-wide" x-show="exportOpen" x-cloak @click.outside="exportOpen=false">
                            <div class="export-dd-label" style="display:flex; justify-content:space-between; align-items:center;">
                                Reporting Period
                                <span style="text-transform:none; font-weight:600; color:#4b5563; font-size:11px;"><?php echo date('M j, Y', strtotime($from)); ?> – <?php echo date('M j, Y', strtotime($to)); ?></span>
                            </div>
                            <hr class="export-dd-hr" style="margin: 4px 12px 8px;">

                            <?php
                            $rptQs = [
                                'from' => $from,
                                'to' => $to,
                                'branch_id' => $branchId === 'all' ? 'all' : (int)$branchId,
                            ];
                            $pfRptUrl = function (string $file, array $extra = []) use ($rptQs, $base_path) {
                                return rtrim($base_path, '/') . '/admin/' . $file . '?' . http_build_query(array_merge($rptQs, $extra));
                            };
                            $printOrdersUrl = $pfRptUrl('reports_print.php', ['report' => 'orders']);
                            $printSalesUrl = $pfRptUrl('reports_print.php', ['report' => 'sales']);
                            $printCustUrl = $pfRptUrl('reports_print.php', ['report' => 'customers']);
                            $xlsxOrdersUrl = $pfRptUrl('reports_export_excel.php', ['report' => 'orders']);
                            $xlsxSalesUrl = $pfRptUrl('reports_export_excel.php', ['report' => 'sales']);
                            $xlsxCustomersUrl = $pfRptUrl('reports_export_excel.php', ['report' => 'customers']);
                            $csvSalesUrl = $pfRptUrl('reports_export.php', ['report' => 'sales']);
                            $csvOrdersUrl = $pfRptUrl('reports_export.php', ['report' => 'orders']);
                            $csvCustomersUrl = $pfRptUrl('reports_export.php', ['report' => 'customers']);
                            $csvDailyUrl = $pfRptUrl('reports_export.php', ['report' => 'daily_sales', 'date' => $to]);
                            $csvShopInvUrl = $pfRptUrl('reports_export.php', ['report' => 'shop_inventory']);
                            $csvMaterialsUrl = $pfRptUrl('reports_export.php', ['report' => 'inventory']);
                            $activityLogsPrintUrl = rtrim($base_path, '/') . '/admin/activity_logs.php?' . http_build_query([
                                'print_all' => '1',
                                'date_from' => $from,
                                'date_to' => $to,
                            ]);
                            $je = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
                            ?>
                            <div class="export-dd-label">Print</div>
                            <button type="button" class="sort-option" style="width:100%;border:none;background:none;cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;text-align:left;padding:9px 16px;color:#111827;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"full"]), $je); ?>); exportOpen = false' title="Full analytical summary">Print Full Report</button>
                            
                            <hr class="export-dd-hr">
                            <button type="button" class="sort-option" style="width:100%;border:none;background:none;cursor:pointer;font-size:13px;font-family:inherit;font-weight:inherit;text-align:left;padding:9px 16px;color:#374151;" onclick='reportsPrintInPlace(<?php echo json_encode($printCustUrl, $je); ?>); exportOpen = false'>Print – Customers Table</button>
                            
                            <hr class="export-dd-hr">
                            <button type="button" class="sort-option" style="width:100%;border:none;background:none;cursor:pointer;font-size:13px;font-family:inherit;font-weight:inherit;text-align:left;padding:9px 16px;color:#374151;" onclick='reportsPrintInPlace(<?php echo json_encode($printCustUrl, $je); ?>); exportOpen = false'>Print – Customers Table</button>
                            <?php if (($current_user['role'] ?? '') === 'Admin'): ?>
                            <button type="button" class="sort-option" style="width:100%;border:none;background:none;cursor:pointer;font-size:13px;font-family:inherit;font-weight:inherit;text-align:left;padding:9px 16px;color:#374151;" onclick='reportsPrintInPlace(<?php echo json_encode($activityLogsPrintUrl, $je); ?>); exportOpen = false' title="Uses report date range">Print – Activity logs</button>
                            <?php endif; ?>

                            <hr class="export-dd-hr">
                            <div class="export-dd-label">Excel</div>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($xlsxSalesUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false" title="Formatted like print: colors, auto column width">Excel – Sales detail</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($xlsxOrdersUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">Excel – Orders status</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($xlsxCustomersUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">Excel – Customers</a>

                            <hr class="export-dd-hr">
                            <div class="export-dd-label">CSV</div>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvSalesUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">CSV – Sales detail</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvOrdersUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">CSV – Orders status</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvCustomersUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">CSV – Customers</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvDailyUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false" title="End date of report range">CSV – Daily sales (end date)</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvShopInvUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">CSV – Products &amp; materials stock</a>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvMaterialsUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen=false">CSV – Legacy materials &amp; movements</a>
                        </div>
                    </div>
                </div>
            </div>

            <?php

// ── 15. Dash Sales Chart (branch revenue = store + customization; filter-aware) ──
// This is the ONLY component affected by the global from/to/branch filter
try {
    $dashSales = pf_reports_period_sales_merged($salesTrendFrom, $salesTrendToEnd, $salesTrendBranchId);
    $dash_labels     = $dashSales['labels'] ?? [];
    $dash_rev_store  = $dashSales['revStore'] ?? [];
    $dash_rev_custom = $dashSales['revCustom'] ?? [];
    $dash_rev_total  = $dashSales['revTotal'] ?? [];
    $dash_orders     = $dashSales['orders'] ?? [];
    
    // Debug logging for sales chart data
    error_log('[PrintFlow] Sales chart data generated: ' . json_encode([
        'from' => $salesTrendFrom,
        'to' => $salesTrendToEnd,
        'branchId' => $salesTrendBranchId,
        'labels_count' => count($dash_labels),
        'sample_label' => $dash_labels[0] ?? null,
        'revStore_count' => count($dash_rev_store),
        'sample_revStore' => $dash_rev_store[0] ?? null,
        'total_revenue' => array_sum($dash_rev_total) ?: (array_sum($dash_rev_store) + array_sum($dash_rev_custom)),
        'total_orders' => array_sum($dash_orders)
    ]));
} catch (Exception $e) {
    error_log('[PrintFlow] Error generating sales chart data: ' . $e->getMessage());
    $dash_labels = [];
    $dash_rev_store = [];
    $dash_rev_custom = [];
    $dash_rev_total = [];
    $dash_orders = [];
}

// ── 16. Consolidate Dashboard Data (Single Source of Truth) ──────────────────
$dashData = [
    'kpis' => [
        'total_orders'  => $total_orders,
        'revenue'       => $revenue,
        'paid_orders'   => $paid_orders,
        'avg_val'       => $avg_val,
        'orders_delta'  => $orders_delta,
        'revenue_delta' => $revenue_delta,
        'top_product'   => $top_kpi_product ? [
            'name' => mb_substr($top_kpi_product['name'], 0, 22),
            'qty'  => (int)$top_kpi_product['qty']
        ] : null,
        'top_location'  => $top_kpi_location ? [
            'city' => mb_substr(trim($top_kpi_location['city']), 0, 20),
            'cnt'  => (int)$top_kpi_location['cnt']
        ] : null,
    ],
    'salesChart' => [
        'labels'    => $dash_labels,
        'revTotal'  => $dash_rev_total,
        'revStore'  => $dash_rev_store,
        'revCustom' => $dash_rev_custom,
        'orders'    => $dash_orders,
    ],
    'trend12' => [
        'labels'     => $trend12_labels,
        'revStore'   => $trend12_revenue_store,
        'revCustom'  => $trend12_revenue_custom,
        'revenues'   => $trend12_revenues,
        'orders'     => $trend12_orders,
        'forecast'   => [
            'revenue'   => $forecast_revenue,
            'revStore'  => $forecast_revenue_store,
            'revCustom' => $forecast_revenue_custom,
            'orders'    => $forecast_orders,
            'label'     => $next_month_label
        ]
    ],
    'topServices' => array_map(function($p) use ($top_products_prev) {
        $k = !empty($p['product_id']) ? 'p:' . (int) $p['product_id'] : 's:' . mb_strtolower((string) ($p['product_name'] ?? ''));
        return [
            'name'     => $p['product_name'],
            'qty'      => (int)$p['qty_sold'],
            'revenue'  => (float)$p['revenue'],
            'prev_qty' => $top_products_prev[$k] ?? null
        ];
    }, $top_products),
    'revenueDonut' => array_map(function($p) {
        return [
            'name'    => $p['product_name'],
            'revenue' => round((float)$p['revenue'], 2)
        ];
    }, $rev_donut),
    'productCategorySales' => array_map(static function ($r) {
        return [
            'category' => trim((string)($r['category'] ?? '')) !== '' ? trim((string)$r['category']) : 'Uncategorized product',
            'revenue'  => round((float)($r['total'] ?? 0), 2),
            'items'    => (int)($r['items_sold'] ?? 0),
        ];
    }, $report_product_category_sales),
    'serviceCategorySales' => array_map(static function ($r) {
        return [
            'category' => trim((string)($r['category'] ?? '')) !== '' ? trim((string)$r['category']) : 'Customization',
            'revenue'  => round((float)($r['total'] ?? 0), 2),
            'jobs'     => (int)($r['qty_sold'] ?? 0),
        ];
    }, $report_service_category_sales),
    'orderStatus' => array_map(function($s) {
        return [
            'status' => $s['status'],
            'cnt' => (int)$s['cnt']
        ];
    }, $status_data),
    'customUsage' => array_map(function($cu) {
        return [
            'product' => $cu['product'],
            'custom_count' => (int)$cu['custom_count'],
            'template_count' => (int)$cu['template_count']
        ];
    }, $custom_usage),
    'customerLocations' => array_map(function($l) {
        return [
            'city' => $l['city'],
            'orders' => (int)$l['orders']
        ];
    }, $customer_locations),
    'branchPerf' => array_map(function($b) {
        return [
            'branch_name' => $b['branch_name'],
            'revenue' => (float)$b['revenue'],
            'orders_store' => (int)($b['orders_store'] ?? 0),
            'orders_jobs' => (int)($b['orders_jobs'] ?? 0)
        ];
    }, $branch_perf),
    'salesByBranch' => array_map(function($b) {
        return [
            'branch_name' => $b['branch_name'],
            'revenue' => round((float)$b['revenue'], 2),
            'orders_store' => (int)($b['orders_store'] ?? 0),
            'orders_jobs' => (int)($b['orders_jobs'] ?? 0)
        ];
    }, $dash_branch_perf),
    'forecastChart' => [
        'can_forecast' => (bool)$can_forecast,
        'all_labels'   => $fc_all_labels,
        'hist_count'   => count($fc_hist_labels ?? []),
        'series'       => array_map(function($prod) use ($fc_series_data) {
            $pd = $fc_series_data[$prod];
            return [
                'name' => $prod,
                'hist' => $pd['hist'],
                'fore' => $pd['fore']
            ];
        }, array_keys($fc_series_data))
    ],
    'customizationRevenue' => [
        'labels' => $trend12_labels,
        'revenue' => $trend12_revenue_custom
    ],
    'lastUpdated' => date('M j, Y g:i A'),
    'periodEmpty' => $period_empty
];
?>

<?php
            if (isset($_GET['ajax'])) {
                ob_clean(); // Clear any boilerplate buffered before line 1612
                ob_start();
            }
            ?>
            <div class="ana-wrap" id="pf-reports-dashboard-container">

            <?php if ($gaBranchEmpty): ?>
            <!-- ══ BRANCH EMPTY STATE ════════════════════════════════════════ -->
            <!-- KPI row shows zeroes -->
            <div class="kpi-row">
                <?php foreach ([
                    ['kpi-em',  'Total Orders',           '0',         'No transactions recorded'],
                    ['kpi-ind', 'Total Revenue',          '₱0',        'No completed transactions'],
                    ['kpi-amb', 'Top Selling Service',    '—',         'No orders yet'],
                    ['kpi-vio', 'Top Customer Location',  '—',         'No location data'],
                ] as [$cls,$lbl,$val,$sub]): ?>
                <div class="kpi-card <?php echo $cls; ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div class="kpi-lbl" style="margin-bottom:0;"><?php echo $lbl; ?></div>
                        <span style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em;">All-Time</span>
                    </div>
                    <div class="kpi-val empty-kpi"><?php echo $val; ?></div>
                    <div class="kpi-sub"><?php echo $sub; ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ana-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="28" height="28" fill="none" stroke="#9ca3af" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="empty-title">No sales data available for this system</div>
                    <div class="empty-sub">Reports and charts will appear once transactions are recorded across any branch.</div>
                </div>
            </div>

            <?php else: ?>
            <!-- ══ KPI ROW ═══════════════════════════════════════════════════ -->
            <div class="kpi-row">
                <!-- Total Orders -->
                <div class="kpi-card kpi-em" title="Orders for the selected date range and branch context">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div class="kpi-lbl" style="margin-bottom:0;">Total Orders</div>
                        <span style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : ($is_admin ? 'All Branches' : 'This Branch'); ?></span>
                    </div>
                    <div class="kpi-val"><?php echo number_format($total_orders); ?></div>
                    <div class="kpi-sub">
                        <?php if ($from !== '' || $to !== ''): ?>
                            Selected period total
                        <?php else: ?>
                            All-time cumulative total
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Revenue -->
                <div class="kpi-card kpi-ind" title="Revenue for the selected date range and branch context">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div class="kpi-lbl" style="margin-bottom:0;">Total Revenue</div>
                        <span style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : 'All-Time'; ?></span>
                    </div>
                    <div class="kpi-val">₱<?php echo number_format($revenue, 0); ?></div>
                    <div class="kpi-sub">
                        <?php echo $paid_orders; ?> completed transactions <?php echo ($from !== '' || $to !== '') ? 'in period' : 'total'; ?>
                    </div>
                </div>
                <!-- Top Product -->
                <div class="kpi-card kpi-amb" title="Top selling service for the selected date range.">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div class="kpi-lbl" style="margin-bottom:0;">Top Selling Service</div>
                        <span style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : 'All-Time'; ?></span>
                    </div>
                    <div class="kpi-val" style="font-size:15px;margin-top:4px;line-height:1.3;">
                        <?php echo $top_kpi_product ? htmlspecialchars(mb_substr($top_kpi_product['name'],0,22)) : '—'; ?>
                    </div>
                    <div class="kpi-sub"><?php echo $top_kpi_product ? number_format((int)$top_kpi_product['qty']).' units' : 'No data for period'; ?></div>
                </div>
                <!-- Top Location -->
                <div class="kpi-card kpi-vio" title="Top customer location for the selected date range.">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div class="kpi-lbl" style="margin-bottom:0;">Top Location</div>
                        <span style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : 'All-Time'; ?></span>
                    </div>
                    <div class="kpi-val" style="font-size:15px;margin-top:4px;line-height:1.3;">
                        <?php echo $top_kpi_location ? htmlspecialchars(mb_substr(trim($top_kpi_location['city']),0,20)) : '—'; ?>
                    </div>
                    <div class="kpi-sub"><?php echo $top_kpi_location ? $top_kpi_location['cnt'].' transactions' : 'No location data for period'; ?></div>
                </div>
            </div>

            <!-- ══ SALES REVENUE (From Dashboard) ═════════════════════════════ -->
            <div class="ana-card">
                <div class="ana-hd">
                    <h3 class="chart-title-nowrap">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        Sales Revenue by Branch
                        <span style="margin-left:8px;padding:3px 8px;background:#EBF8FF;color:#2C5282;border-radius:6px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.04em;">Filter Applied</span>
                    </h3>
                    <div class="pf-branch-meta no-print">
                        <div class="pf-branch-meta-badge" title="Current branch revenue reporting period">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"/></svg>
                            <?php echo htmlspecialchars($dash_period_label); ?>
                        </div>
                        <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"branch_perf"]), $je); ?>)' title="Print Sales Revenue by Branch Report">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="pf-branch-revenue-layout">
                        <div class="pf-branch-revenue-main">
                    <div class="ch-box" id="dash-sales-chart-wrap" style="height:520px;">
                        <?php if ($branch_empty): ?>
                        <div class="empty-state" style="padding:40px 20px;">
                            <div class="empty-icon" style="opacity:0.4; margin-bottom:12px;">
                                <svg width="24" height="24" fill="none" stroke="#9ca3af" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div class="empty-title" style="font-size:13px; color:#6b7280;">No branch revenue yet</div>
                            <div class="empty-sub" style="font-size:11px;">Sales revenue by branch will appear here once transactions are recorded for <strong><?php echo htmlspecialchars($branchName); ?></strong>.</div>
                        </div>
                        <?php else: ?>
                        <div class="chart-loading hidden" id="dash-sales-loading">
                            <div class="chart-loading-spinner"></div>
                        </div>
                        <div id="dash-sales-nodata" style="position:absolute;inset:0;display:none;align-items:center;justify-content:center;color:#9ca3af;font-size:11px;font-weight:600;letter-spacing:0.02em;z-index:1;">
                            <span>No branch revenue data for this period</span>
                        </div>
                        <!-- Debug info (remove in production) -->
                        <?php if (isset($_GET['debug'])): ?>
                        <div style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.8);color:white;padding:8px;font-size:10px;border-radius:4px;z-index:999;">
                            Labels: <?php echo count($dash_labels); ?><br>
                            Branch Rev: <?php echo array_sum($dash_rev_total); ?><br>
                            Store Rev: <?php echo array_sum($dash_rev_store); ?><br>
                            Custom Rev: <?php echo array_sum($dash_rev_custom); ?><br>
                            Orders: <?php echo array_sum($dash_orders); ?><br>
                            Sample: <?php echo $dash_labels[0] ?? 'none'; ?>
                        </div>
                        <?php endif; ?>
                        <div class="pf-wide-chart-canvas pf-wide-chart-canvas--branch"><canvas id="dashSalesChart"></canvas></div>
                        <?php endif; ?>
                    </div>
                        <div class="pf-branch-footnote">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Showing sales revenue for all visible branches for <?php echo htmlspecialchars($dash_period_label); ?>.
                        </div>
                        </div>
                        <aside class="pf-branch-revenue-sidebar">
                            <h4 class="pf-branch-summary-title">Summary</h4>
                            <div class="pf-branch-summary-grid">
                                <div class="pf-branch-stat pf-branch-stat-total">
                                    <div class="pf-branch-stat-icon">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .672-3 1.5S10.343 11 12 11s3 .672 3 1.5S13.657 14 12 14m0-6V6m0 8v2m9-4a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Total Sales Revenue</div>
                                        <div class="pf-branch-stat-value">₱<?php echo number_format($dash_branch_total_revenue, 0); ?></div>
                                        <div class="pf-branch-stat-sub neu"><?php echo (int)$dash_branch_count; ?> active branches in view</div>
                                    </div>
                                </div>
                                <div class="pf-branch-stat pf-branch-stat-top">
                                    <div class="pf-branch-stat-icon">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81H7.03a1 1 0 00.95-.69l1.07-3.292z"/></svg>
                                    </div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Top Performing Branch</div>
                                        <div class="pf-branch-stat-value"><?php echo htmlspecialchars((string)($dash_top_branch['branch_name'] ?? '—')); ?></div>
                                        <div class="pf-branch-stat-sub pos"><?php $topPct = $dash_branch_total_revenue > 0 && $dash_top_branch ? (((float)$dash_top_branch['revenue'] / $dash_branch_total_revenue) * 100) : 0; echo $dash_top_branch ? ('₱' . number_format((float)$dash_top_branch['revenue'], 0) . ' (' . number_format($topPct, 1) . '%)') : 'No data'; ?></div>
                                    </div>
                                </div>
                                <div class="pf-branch-stat pf-branch-stat-low">
                                    <div class="pf-branch-stat-icon">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
                                    </div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Lowest Performing Branch</div>
                                        <div class="pf-branch-stat-value"><?php echo htmlspecialchars((string)($dash_low_branch['branch_name'] ?? '—')); ?></div>
                                        <div class="pf-branch-stat-sub neg"><?php $lowPct = $dash_branch_total_revenue > 0 && $dash_low_branch ? (((float)$dash_low_branch['revenue'] / $dash_branch_total_revenue) * 100) : 0; echo $dash_low_branch ? ('₱' . number_format((float)$dash_low_branch['revenue'], 0) . ' (' . number_format($lowPct, 1) . '%)') : 'No data'; ?></div>
                                    </div>
                                </div>
                                <div class="pf-branch-stat pf-branch-stat-avg">
                                    <div class="pf-branch-stat-icon">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 11V5a1 1 0 012 0v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6z"/></svg>
                                    </div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Average Revenue / Branch</div>
                                        <div class="pf-branch-stat-value">₱<?php echo number_format($dash_avg_branch_revenue, 0); ?></div>
                                        <div class="pf-branch-stat-sub neu">Presentation view for fast branch comparison</div>
                                    </div>
                                </div>
                            </div>
                            <div class="pf-branch-toplist">
                                <h4 class="pf-branch-toplist-title">Top Branches</h4>
                                <div class="pf-branch-toplist-items">
                                    <?php foreach ($dash_top_branches as $index => $branch): ?>
                                        <?php $branchPct = $dash_branch_total_revenue > 0 ? (((float)$branch['revenue'] / $dash_branch_total_revenue) * 100) : 0; ?>
                                        <?php $barPct = $dash_top_branch && (float)$dash_top_branch['revenue'] > 0 ? (((float)$branch['revenue'] / (float)$dash_top_branch['revenue']) * 100) : 0; ?>
                                        <div class="pf-branch-toprow">
                                            <div class="pf-branch-rank"><?php echo $index + 1; ?></div>
                                            <div class="pf-branch-toprow-copy">
                                                <div class="pf-branch-toprow-name"><?php echo htmlspecialchars((string)$branch['branch_name']); ?></div>
                                                <div class="pf-branch-toprow-bar"><span style="width:<?php echo max(6, min(100, $barPct)); ?>%"></span></div>
                                            </div>
                                            <div class="pf-branch-toprow-value">₱<?php echo number_format((float)$branch['revenue'], 0); ?> (<?php echo number_format($branchPct, 1); ?>%)</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>

            <!-- ══ SALES TREND (12-Month) ════════════════════════════════════ -->
            <div class="ana-card">
                <div class="ana-hd">
                    <h3>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        12-Month Sales Trend
                        <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Independent</span>
                    </h3>
                    <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                        <span style="font-size:11px;color:#9ca3af;">· <?php echo $next_month_label; ?> forecast</span>
                        <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"sales_trend"]), $je); ?>)' title="Print 12-Month Sales Trend Report">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="ch-box trend12-chart" style="height:300px;">
                        <div class="pf-wide-chart-canvas"><canvas id="salesChart"></canvas></div>
                    </div>
                </div>
            </div>



            <!-- ══ PRODUCT DEMAND FORECAST ═══════════════════════════════════ -->
            <?php if (!$can_forecast): ?>
            <div class="ana-card print-hide pf-forecast-card" id="pf-forecast-section">
                <div class="ana-hd">
                    <h3>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Product Demand Forecast — Next 6 Months
                    </h3>
                    <div style="display:flex;align-items:center;gap:12px;font-size:11px;color:#6b7280;">
                        <span title="Solid lines = actual historical data. Dashed lines = predicted demand based on 6-month trend.">— Solid = Actual · - - Dashed = Forecast (6mo)</span>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="ch-empty">
                        <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <div style="font-weight:600;color:#6b7280;">Not enough data to generate a forecast</div>
                        <div style="font-size:12px;">Predictions will appear once at least <strong>20 orders</strong> are recorded in the last 6 months.</div>
                    </div>
                </div>
            </div>
            <?php else:
            $fc_colors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8'];
            $fc_max_side = 1;
            foreach ($fc_series_data as $pd) {
                $fc_max_side = max($fc_max_side, max($pd['fore']));
            }
            $demand_badges = ['high' => '🔥 High Demand', 'moderate' => '⚠️ Moderate', 'declining' => '⬇️ Declining'];
            ob_start();
            ?>
                                <div class="pf-fc-side-hd">Top Predicted Demand</div>
                                <?php
            $fc_i = 0;
            foreach ($fc_series_data as $prod => $pd):
                $pct = $fc_max_side > 0 ? round(max($pd['fore']) / $fc_max_side * 100) : 0;
                $col = $fc_colors[$fc_i % count($fc_colors)];
                $badge = $pd['demandLabel'] ?? $demand_badges[$pd['demand'] ?? 'moderate'] ?? '⚠️ Moderate';
                $fc_i++;
                ?>
                                <div class="pf-fc-side-row">
                                    <div style="display:grid;grid-template-columns:1fr auto;align-items:center;margin-bottom:4px;gap:8px;">
                                        <span class="pf-fc-name-truncate" style="font-size:12px;font-weight:600;color:#374151;" title="<?php echo htmlspecialchars($prod); ?>"><?php echo htmlspecialchars($prod); ?></span>
                                        <span style="font-size:10px;font-weight:700;color:#6b7280;white-space:nowrap;background:#f3f4f6;padding:2px 6px;border-radius:4px;flex-shrink:0;" title="Demand trend based on 3-month forecast vs. last historical month: High (+15% or more), Moderate (stable), Declining (-15% or less)"><?php echo $badge; ?></span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div class="fc-prod-bar" style="flex:1;"><div class="fc-prod-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div></div>
                                        <span style="font-size:11px;color:#6b7280;min-width:32px;">~<?php echo number_format(max($pd['fore'])); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
            <?php
            $pf_fc_forecast_side_html = ob_get_clean();
            ?>
            <div class="ana-card print-hide pf-forecast-card" id="pf-forecast-section">
                <div class="ana-hd">
                    <h3>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Product Demand Forecast
                        <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Independent</span>
                    </h3>
                    <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                        <span style="font-size:11px;color:#9ca3af;" title="Solid lines = actual historical data. Dashed lines = predicted demand based on 3-month trend.">· — Solid = Actual · - - Dashed = Forecast (3mo)</span>
                        <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"forecast"]), $je); ?>)' title="Print Product Demand Forecast Report">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="pf-forecast-grid">
                        <div class="pf-ch-forecast-col">
                            <div class="pf-ch-forecast-wrap">
                                <div id="ch-forecast" class="pf-ch-forecast-mount"></div>
                            </div>
                        </div>
                        <div class="pf-forecast-side">
                            <?php echo $pf_fc_forecast_side_html; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══ BEST SELLING SERVICES & REVENUE DONUT ═════════════════════ -->
            <div class="ana-grid print-hide">
                <!-- Best Selling Services -->
                <div class="ana-card pf-ch-products-card">
                    <div class="ana-hd">
                        <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                            <span>Best Selling Services</span>
                            <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">All-Time</span>
                        </h3>
                    <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                        <span style="font-size:11px;color:#6b7280;font-weight:600;white-space:nowrap;">
                            <?php echo "All-Time Cumulative"; ?>
                        </span>
                        <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"best_selling"]), $je); ?>)' title="Print Best Selling Services Report">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                    </div>
                    <div class="ana-bd">
                        <div class="ch-box" id="pf-ch-products-wrapper">
                            <div id="ch-products" style="width:100%;"></div>
                        </div>
                    </div>
                </div>

                <!-- Revenue Donut -->
                <div class="ana-card">
                    <div class="ana-hd rev-donut-card-hd">
                        <h3 style="margin:0;"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>Revenue Distribution</h3>
                        <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                            <?php if (!empty($rev_donut) && $revenue_delta !== null): ?>
                            <span class="rev-donut-growth <?php echo $revenue_delta > 0 ? 'up' : ($revenue_delta < 0 ? 'dn' : ''); ?>">vs prior period: <?php echo $revenue_delta > 0 ? '+' : ''; ?><?php echo htmlspecialchars((string)$revenue_delta); ?>%</span>
                            <?php endif; ?>
                            <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"revenue_dist"]), $je); ?>)' title="Print Revenue Distribution Report">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                        </div>
                    </div>
                    <div class="ana-bd rev-donut-body">
                        <div class="rev-donut-row" id="pf-rev-donut-wrapper">
                            <div class="rev-donut-chart-wrap ch-box"><div id="ch-donut"></div></div>
                            <div class="rev-donut-legend-wrap">
                                <ul class="rev-donut-legend" id="pf-rev-donut-legend" aria-label="Revenue by service">
                                    <!-- Populated by JS -->
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ SALES BY PRODUCT CATEGORY | SALES BY SERVICE CATEGORY ═══ -->
            <div class="ana-grid print-hide">
                <div class="ana-card">
                    <div class="ana-hd">
                        <h3 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            Sales by Product
                            <span style="margin-left:4px;padding:3px 8px;background:#EBF8FF;color:#2C5282;border:1px solid #BEE3F8;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : 'All time'; ?></span>
                        </h3>
                    </div>
                    <div class="ana-bd">
                        <?php if (!empty($report_product_category_sales)): ?>
                        <div class="ch-box" style="min-height:260px;">
                            <div style="position:relative;height:240px;max-width:300px;margin:0 auto;">
                                <canvas id="reportsProductCategoryChart" aria-label="Sales by official product"></canvas>
                            </div>
                        </div>
                        <div id="reports-product-cat-legend" style="font-size:12px;display:flex;flex-wrap:wrap;justify-content:center;gap:12px;padding:8px 10px 4px;"></div>
                        <?php else: ?>
                        <div class="ch-empty" style="min-height:200px;"><svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>No official product sales for this period</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ana-card">
                    <div class="ana-hd">
                        <h3 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                            Sales by Service Category
                            <span style="margin-left:4px;padding:3px 8px;background:#EBF8FF;color:#2C5282;border:1px solid #BEE3F8;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : 'All time'; ?></span>
                        </h3>
                    </div>
                    <div class="ana-bd">
                        <?php if (!empty($report_service_category_sales)): ?>
                        <div class="ch-box" style="min-height:260px;">
                            <div style="position:relative;height:240px;max-width:300px;margin:0 auto;">
                                <canvas id="reportsServiceCategoryChart" aria-label="Sales by service category"></canvas>
                            </div>
                        </div>
                        <div id="reports-service-cat-legend" style="font-size:12px;display:flex;flex-wrap:wrap;justify-content:center;gap:12px;padding:8px 10px 4px;"></div>
                        <?php else: ?>
                        <div class="ch-empty" style="min-height:200px;"><svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>No customization revenue by category for this period</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ══ SEASONAL HEATMAP ══════════════════════════════════════════ -->
            <?php $hm_box_h = !empty($heatmap_products) ? max(200, count($heatmap_products) * 44 + 56) : 200; ?>
            <div class="ana-card print-hide">
                <div class="ana-hd heatmap-card-hd">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Seasonal Demand Heatmap <span class="heatmap-year-chip" id="pf-heatmap-year-display"><?php echo (int)$heatmap_year; ?></span>
                        <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Full Year</span>
                    </h3>
                    <div class="heatmap-header-tools">
                        <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                            <label class="heatmap-year-label" for="pf-heatmap-year">Year</label>
                            <select id="pf-heatmap-year" class="chart-select heatmap-year-select" style="height:32px;font-size:12px;" aria-label="Heatmap year" <?php echo empty($heatmap_available_years) ? 'disabled' : ''; ?>>
                                <?php if (empty($heatmap_available_years)): ?>
                                <option value=""><?php echo (int) $y_cal; ?></option>
                                <?php else: ?>
                                <?php foreach ($heatmap_available_years as $yy): ?>
                                <option value="<?php echo (int) $yy; ?>" <?php echo (int) $yy === (int) $heatmap_year ? 'selected' : ''; ?>><?php echo (int) $yy; ?></option>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"heatmap"]), $je); ?>)' title="Print Seasonal Demand Heatmap Report">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                        </div>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="pf-heatmap-legend" aria-label="Heatmap legend" id="pf-heatmap-legend">
                        <span class="pf-hm-legend-item" data-kind="future" role="button" tabindex="0" title="Click to toggle visibility"><i style="background:repeating-linear-gradient(-45deg,#f8fafc,#f8fafc 3px,#e2e8f0 3px,#e2e8f0 6px);border:1px dashed #cbd5e1;"></i> Not yet</span>
                        <span class="pf-hm-legend-item" data-kind="nodata" role="button" tabindex="0" title="Click to toggle visibility"><i style="background:#f8fafc;border:1px dashed #94a3b8;"></i> No transactions</span>
                        <span class="pf-hm-legend-item" data-kind="low" role="button" tabindex="0" title="Click to toggle visibility"><i style="background:#a5e3f2;"></i> Low</span>
                        <span class="pf-hm-legend-item" data-kind="med" role="button" tabindex="0" title="Click to toggle visibility"><i style="background:#53C5E0;"></i> Medium</span>
                        <span class="pf-hm-legend-item" data-kind="high" role="button" tabindex="0" title="Click to toggle visibility"><i style="background:#00232b;"></i> High</span>
                    </div>
                    <div class="ch-box pf-heatmap-chbox" id="pf-heatmap-chbox" style="min-height:<?php echo (int)$hm_box_h; ?>px;">
                        <div id="pf-heatmap-ajax-loading" class="chart-loading hidden" aria-hidden="true">
                            <div class="chart-loading-spinner" role="status" aria-label="Loading heatmap"></div>
                        </div>
                        <div id="ch-heatmap-mount">
                            <?php if (!empty($heatmap_products)): ?>
                            <div class="pf-hm-outer"><?php echo pf_reports_render_heatmap_html($heatmap_products, (int) $heatmap_year); ?></div>
                            <?php else: ?>
                            <div class="ch-empty pf-heatmap-empty"><svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><?php echo empty($heatmap_available_years) ? 'No historical transaction years for this branch.' : 'No data available for the selected year.'; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>





            <!-- ══ CUSTOMER LOCATIONS | ORDER STATUS BREAKDOWN ════════════════ -->
            <div class="ana-grid print-hide">
                <div class="ana-card">
                    <div class="ana-hd">
                        <h3><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg> Customer Locations
                        <span style="margin-left:8px;padding:3px 8px;background:#EBF8FF;color:#2C5282;border:1px solid #BEE3F8;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;"><?php echo ($from !== '' || $to !== '') ? 'Filtered' : 'All time'; ?></span>
                    </h3>
                        <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                            <?php if (!empty($customer_locations)): ?>
                            <span class="top-location-pill">Top Location: <?php echo htmlspecialchars(trim($customer_locations[0]['city'])); ?></span>
                            <?php endif; ?>
                            <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"locations"]), $je); ?>)' title="Print Customer Locations Report">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                        </div>
                    </div>
                    <div class="ana-bd">
                        <?php if (!empty($customer_locations)): ?>
                            <?php 
                                $max_orders = max(array_column($customer_locations, 'orders'));
                            ?>
                            <div class="pf-loc-list">
                                <?php foreach ($customer_locations as $index => $loc): 
                                    $pct = $max_orders > 0 ? ($loc['orders'] / $max_orders) * 100 : 0;
                                    $rank = $index + 1;
                                ?>
                                <div class="pf-loc-row">
                                    <div class="pf-loc-header">
                                        <div class="pf-loc-name">
                                            <span class="pf-loc-rank">#<?php echo $rank; ?></span>
                                            <span class="pf-loc-city"><?php echo htmlspecialchars(trim($loc['city'])); ?></span>
                                        </div>
                                        <div class="pf-loc-value"><?php echo $loc['orders']; ?></div>
                                    </div>
                                    <div class="pf-loc-bar-wrap">
                                         <div class="pf-loc-bar" style="width: <?php echo $pct . '%'; ?>;"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="ch-empty"><svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>No location data available</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="ana-card">
                    <div class="ana-hd">
                        <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>Order Status Breakdown
                            <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">All-Time</span>
                        </h3>
                        <div class="no-print">
                            <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"order_status"]), $je); ?>)' title="Print Order Status Breakdown Report">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                        </div>
                    </div>
                    <div class="ana-bd">
                        <div class="ch-box" style="min-height:300px;"><div id="ch-status"></div></div>
                    </div>
                </div>
            </div>

            <!-- ══ BRANCH PERFORMANCE COMPARISON (Dual-Axis) ══════════════════ -->
            <?php if (!$is_manager && count($branch_perf) > 0): ?>
            <?php
                $perf_revArr = array_map(fn($b) => (float)$b['revenue'], $branch_perf);
                $maxRev = max(100, ...$perf_revArr);
                $pref_ordArr = [];
                foreach ($branch_perf as $b) {
                    $pref_ordArr[] = (int)($b['orders_store'] ?? 0);
                    $pref_ordArr[] = (int)($b['orders_jobs'] ?? 0);
                }
                $maxOrd = max(5, ...$pref_ordArr);
                $cH = 320; 
                $fmtRev = function(float $v): string {
                    if ($v >= 1e6) return '₱' . number_format($v / 1e6, 1) . 'M';
                    if ($v >= 1e3) return '₱' . number_format($v / 1e3, 0) . 'k';
                    return '₱' . number_format($v, 0);
                };
            ?>
            <div class="ana-card">
                <div class="ana-hd">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg><?php echo $is_manager ? 'Current Branch Performance' : 'Branch Performance Comparison'; ?>
                        <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;"><?php echo $is_manager ? 'This branch' : 'All branches'; ?></span>
                    </h3>
                    <div class="no-print">
                        <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"branch_perf"]), $je); ?>)' title="Print Branch Performance Comparison Report">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="pf-brc-legend" style="margin-bottom:20px;">
                        <span class="pf-brc-leg" data-metric="revenue" role="button" tabindex="0" title="Click to toggle Revenue"><i style="background:#00232b"></i>Revenue (₱)</span>
                        <span class="pf-brc-leg" data-metric="orders" role="button" tabindex="0" title="Click to toggle Store Orders"><i style="background:#3b82f6"></i>Store Orders</span>
                        <span class="pf-brc-leg" data-metric="jobs" role="button" tabindex="0" title="Click to toggle Customization Jobs"><i style="background:#14b8a6"></i>Customization Jobs</span>
                    </div>
                    
                    <div class="pf-brc-container">
                        <!-- Left Y-Axis (Revenue) -->
                        <div class="pf-brc-y-left">
                            <div><?php echo $fmtRev($maxRev); ?></div>
                            <div><?php echo $fmtRev($maxRev*0.75); ?></div>
                            <div><?php echo $fmtRev($maxRev*0.5); ?></div>
                            <div><?php echo $fmtRev($maxRev*0.25); ?></div>
                            <div>₱0</div>
                        </div>

                        <!-- Main Chart Content (Scrollable) -->
                        <div class="pf-brc-wrap">
                            <div class="pf-brc-grid">
                                <div class="pf-brc-gridline" style="bottom:25%"></div>
                                <div class="pf-brc-gridline" style="bottom:50%"></div>
                                <div class="pf-brc-gridline" style="bottom:75%"></div>
                                <?php foreach ($branch_perf as $br):
                                    $rev = (float)$br['revenue'];
                                    $os  = (int)($br['orders_store'] ?? 0);
                                    $oj  = (int)($br['orders_jobs'] ?? 0);
                                    $hR  = $maxRev > 0 ? (int)round(($rev / $maxRev) * $cH) : 0;
                                    $hO  = $maxOrd > 0 ? (int)round(($os / $maxOrd) * $cH) : 0;
                                    $hC  = $maxOrd > 0 ? (int)round(($oj / $maxOrd) * $cH) : 0;
                                    $isEmpty = ($rev == 0 && $os == 0 && $oj == 0);
                                    $revFull = number_format($rev, 0);
                                ?>
                                <div class="pf-brc-cluster <?php echo $isEmpty ? 'pf-brc-cluster--empty' : ''; ?>"
                                     data-branch="<?php echo htmlspecialchars($br['branch_name']); ?>"
                                     data-rev="₱<?php echo $revFull; ?>"
                                     data-os="<?php echo $os; ?>"
                                     data-oj="<?php echo $oj; ?>"
                                     data-empty="<?php echo $isEmpty ? '1' : '0'; ?>">
                                    <!-- Revenue bar -->
                                    <div class="pf-brc-bar pf-brc-bar--revenue" style="height:<?php echo max(1, $hR); ?>px; background:#00232b;">
                                        <?php if ($hR > 25): ?><div class="pf-brc-val"><?php echo $fmtRev($rev); ?></div><?php endif; ?>
                                    </div>
                                    <!-- Store Orders bar -->
                                    <div class="pf-brc-bar pf-brc-bar--orders" style="height:<?php echo max(1, $hO); ?>px; background:#3b82f6;">
                                        <?php if ($hO > 20 && $os > 0): ?><div class="pf-brc-val"><?php echo $os; ?></div><?php endif; ?>
                                    </div>
                                    <!-- Customization bar -->
                                    <div class="pf-brc-bar pf-brc-bar--jobs" style="height:<?php echo max(1, $hC); ?>px; background:#14b8a6;">
                                        <?php if ($hC > 20 && $oj > 0): ?><div class="pf-brc-val"><?php echo $oj; ?></div><?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Branch Names (Horizontal Scrollable) -->
                            <div class="pf-brc-names">
                                <?php foreach ($branch_perf as $br): ?>
                                <div class="pf-brc-name">
                                    <span class="pf-brc-name-txt"><?php echo htmlspecialchars(mb_substr($br['branch_name'], 0, 18)); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Right Y-Axis (Orders) -->
                        <div class="pf-brc-y-right">
                            <div><?php echo number_format($maxOrd); ?></div>
                            <div><?php echo number_format($maxOrd*0.75); ?></div>
                            <div><?php echo number_format($maxOrd*0.5); ?></div>
                            <div><?php echo number_format($maxOrd*0.25); ?></div>
                            <div>0</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Custom Tooltip Div -->
            <div id="pf-brc-card-tooltip">
                <div class="pf-tt-branch" id="pf-tt-branch-name">Branch Name</div>
                <div class="pf-tt-row"><span class="pf-tt-lbl">Revenue</span> <span class="pf-tt-val" id="pf-tt-rev">₱0</span></div>
                <div class="pf-tt-row"><span class="pf-tt-lbl">Store Orders</span> <span class="pf-tt-val" id="pf-tt-os">0</span></div>
                <div class="pf-tt-row"><span class="pf-tt-lbl">Customization</span> <span class="pf-tt-val" id="pf-tt-oj">0</span></div>
                <div class="pf-tt-empty" id="pf-tt-empty-msg" style="display:none;">No data available for this period</div>
            </div>

            <script>
            (function(){
                const tooltip = document.getElementById('pf-brc-card-tooltip');
                if (!tooltip) return;
                const ttName = document.getElementById('pf-tt-branch-name');
                const ttRev  = document.getElementById('pf-tt-rev');
                const ttOs   = document.getElementById('pf-tt-os');
                const ttOj   = document.getElementById('pf-tt-oj');
                const ttEmpty = document.getElementById('pf-tt-empty-msg');

                const clusters = document.querySelectorAll('.pf-brc-cluster');
                clusters.forEach(c => {
                    c.addEventListener('mouseenter', (e) => {
                        ttName.textContent = c.dataset.branch;
                        ttRev.textContent  = c.dataset.rev;
                        ttOs.textContent   = c.dataset.os;
                        ttOj.textContent   = c.dataset.oj;
                        ttEmpty.style.display = c.dataset.empty === '1' ? 'block' : 'none';
                        
                        tooltip.style.visibility = 'visible';
                        tooltip.style.opacity = '1';
                    });
                    c.addEventListener('mousemove', (e) => {
                        let x = e.clientX + 15;
                        let y = e.clientY + 15;
                        const winW = window.innerWidth;
                        const winH = window.innerHeight;
                        const ttW = tooltip.offsetWidth;
                        const ttH = tooltip.offsetHeight;

                        if (x + ttW > winW) x = e.clientX - ttW - 15;
                        if (y + ttH > winH) y = e.clientY - ttH - 15;

                        tooltip.style.left = x + 'px';
                        tooltip.style.top  = y + 'px';
                    });
                    c.addEventListener('mouseleave', () => {
                        tooltip.style.visibility = 'hidden';
                        tooltip.style.opacity = '0';
                    });
                });
            })();
            </script>
            <?php endif; ?>

            <!-- ══ TOP CUSTOMERS ══════════════════════════════════════════ -->
            <div class="ana-card print-hide" style="display:none;">
                <div class="ana-hd">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Top Customers
                        <span style="margin-left:8px;padding:3px 8px;background:#F7FAFC;color:#4A5568;border:1px solid #E2E8F0;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">All-Time</span>
                    </h3>
                    <div class="no-print">
                        <button type="button" class="toolbar-btn" style="height:32px;padding:0 10px;font-size:11px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"top_customers"]), $je); ?>)' title="Print Top Customers Report">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                </div>
                <div class="ana-bd ana-bd-0">
                    <?php if (!empty($top_customers)): ?>
                    <table class="rpt-tbl">
                        <thead><tr><th>#</th><th>Customer</th><th class="num">Orders</th><th class="num">Spent</th></tr></thead>
                        <tbody>
                        <?php foreach ($top_customers as $i => $tc): ?>
                        <tr>
                            <td style="color:#9ca3af;font-weight:700;"><?php echo $i+1; ?></td>
                            <td><div style="font-weight:600;"><?php echo htmlspecialchars($tc['name']); ?></div><div style="font-size:11px;color:#9ca3af;"><?php echo htmlspecialchars($tc['email']); ?></div></td>
                            <td class="num"><?php echo (int)$tc['orders']; ?></td>
                            <td class="num" style="color:#059669;">₱<?php echo number_format((float)$tc['spent'],2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="ch-empty">No customer data for this period</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══ AI INSIGHTS + FORECAST PANEL ════════════════════════════ -->
            <div class="ins-panel print-hide">
                <div class="ins-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <span style="display:flex;align-items:center;gap:8px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                        Business Insights &amp; <?php echo $next_month_label; ?> Forecast
                    </span>
                    <div style="display:flex;align-items:center;gap:12px;" class="no-print">
                        <button type="button" class="toolbar-btn" style="height:36px;background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;padding:0 12px;" onclick='reportsPrintInPlace(<?php echo json_encode($pfRptUrl("reports_print.php", ["report"=>"insights"]), $je); ?>)' title="Print Business Insights Report">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print</button>
                    </div>
                </div>

                <?php if (!empty($active_events)): ?>
                <div style="margin-bottom:14px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:rgba(255,255,255,.5);margin-bottom:8px;">Active Seasonal Events</div>
                    <?php foreach ($active_events as $ev): ?>
                    <span class="ev-badge"><?php echo htmlspecialchars($ev['event']); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="fc-row">
                    <div class="fc-chip">
                        <div class="fc-lbl">Predicted Revenue</div>
                        <div class="fc-val">₱<?php echo number_format($forecast_revenue,0); ?></div>
                        <div class="fc-sub"><?php echo $next_month_label; ?></div>
                    </div>
                    <div class="fc-chip">
                        <div class="fc-lbl">Predicted Orders</div>
                        <div class="fc-val"><?php echo number_format($forecast_orders); ?></div>
                        <div class="fc-sub"><?php echo $next_month_label; ?></div>
                    </div>
                    <?php if ($top_kpi_product): ?>
                    <div class="fc-chip">
                        <div class="fc-lbl">Top Forecast Service</div>
                        <div class="fc-val" style="font-size:14px;line-height:1.3;"><?php echo htmlspecialchars(mb_substr($top_kpi_product['name'],0,20)); ?></div>
                        <div class="fc-sub">Highest historical demand</div>
                    </div>
                    <?php endif; ?>
                    <div class="fc-chip">
                        <div class="fc-lbl">Avg Order Value</div>
                        <div class="fc-val">₱<?php echo number_format($avg_val,0); ?></div>
                        <div class="fc-sub">This period</div>
                    </div>
                </div>

                <?php if (!empty($insights)): ?>
                <ul class="ins-list" style="margin-top:18px;line-height:1.7;">
                    <?php foreach ($insights as $ins): ?>
                    <li><?php echo $ins; ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- ══ INVENTORY ALERTS ════════════════════════════════════════ -->


            <?php endif; /* gaBranchEmpty */ ?>

            <!-- ── Print Footer (visible only when printing) ── -->
            <div class="print-report-footer">
                Generated by PrintFlow System &nbsp;·&nbsp; <?php echo date('F j, Y'); ?>
            </div>

            </div><!-- /.ana-wrap -->

            <?php
            if (isset($_GET['ajax'])) {
                $html = ob_get_clean();
                
                // Extract toolbar summary
                ob_start();
                ?>
                <?php echo htmlspecialchars($branchName); ?> &nbsp;·&nbsp;
                <?php if ($from !== '' && $to !== ''): ?>
                    <?php echo date('M d, Y', strtotime($from)); ?> – <?php echo date('M d, Y', strtotime($to)); ?>
                <?php elseif ($from !== ''): ?>
                    From <?php echo date('M d, Y', strtotime($from)); ?>
                <?php elseif ($to !== ''): ?>
                    Until <?php echo date('M d, Y', strtotime($to)); ?>
                <?php else: ?>
                    All Time
                <?php endif; ?>
                <?php
                $summary = ob_get_clean();

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'html' => $html,
                    'summary' => $summary,
                    'filterCount' => (int)($from !== '' || $to !== ''),
                    'activePreset' => $active_p,
                    'dashData' => $dashData
                ]);
                exit;
            }
            ?>
        </main>
    </div>
</div>

<script>
function printflowInitReportsPage() {
    // ── BRANCH PERFORMANCE LEGEND TOGGLE (Interactive Buttons - Matches Heatmap) ──
    const brcLegs = document.querySelectorAll('.pf-brc-leg');
    
    brcLegs.forEach(leg => {
        if (leg.dataset.pfBound === '1') return;
        leg.dataset.pfBound = '1';
        
        leg.addEventListener('click', function() {
            const metric = this.getAttribute('data-metric');
            if (!metric) return;
            
            // Toggle hidden state on legend item
            this.classList.toggle('is-hidden');
            const isHidden = this.classList.contains('is-hidden');
            
            // Find and toggle bars
            let barSelector = '';
            if (metric === 'revenue') barSelector = '.pf-brc-bar--revenue';
            else if (metric === 'orders') barSelector = '.pf-brc-bar--orders';
            else if (metric === 'jobs') barSelector = '.pf-brc-bar--jobs';
            
            const bars = document.querySelectorAll(barSelector);
            bars.forEach(bar => {
                if (isHidden) {
                    bar.classList.add('is-hidden');
                } else {
                    bar.classList.remove('is-hidden');
                }
            });
        });
        
        // Keyboard support
        leg.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                leg.click();
            }
        });
    });

    // ── CUSTOMER LOCATIONS TOOLTIP (REUSED FROM CUSTOMIZATION USAGE) ──
    let locTt = document.getElementById('pf-loc-tooltip');
    if (!locTt) {
        locTt = document.createElement('div');
        locTt.id = 'pf-loc-tooltip';
        locTt.innerHTML = '<div class="pf-loc-tt-city"></div><div class="pf-loc-tt-orders"></div>';
        document.body.appendChild(locTt);
    }

    const locRows = document.querySelectorAll('.pf-loc-row');
    locRows.forEach(row => {
        if (row.dataset.pfBound === '1') return;
        row.dataset.pfBound = '1';
        
        const cityEl = row.querySelector('.pf-loc-city');
        const valueEl = row.querySelector('.pf-loc-value');
        const cityName = cityEl ? cityEl.textContent.trim() : '';
        const orderCount = valueEl ? valueEl.textContent.trim() : '0';
        
        row.addEventListener('mouseenter', (e) => {
            const ttCity = locTt.querySelector('.pf-loc-tt-city');
            const ttOrders = locTt.querySelector('.pf-loc-tt-orders');
            if (ttCity) ttCity.textContent = cityName;
            if (ttOrders) ttOrders.innerHTML = `Total Orders: <strong>${orderCount}</strong>`;
            
            locTt.style.visibility = 'visible';
            locTt.style.opacity = '1';
            locTt.style.transform = 'scale(1)';
        });
        
        row.addEventListener('mousemove', (e) => {
            let x = e.clientX + 15;
            let y = e.clientY + 15;
            const winW = window.innerWidth;
            const winH = window.innerHeight;
            const ttW = locTt.offsetWidth;
            const ttH = locTt.offsetHeight;

            if (x + ttW > winW) x = e.clientX - ttW - 15;
            if (y + ttH > winH) y = e.clientY - ttH - 15;

            locTt.style.left = x + 'px';
            locTt.style.top = y + 'px';
        });
        
        row.addEventListener('mouseleave', () => {
            locTt.style.visibility = 'hidden';
            locTt.style.opacity = '0';
            locTt.style.transform = 'scale(0.95)';
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitReportsPage);
} else {
    printflowInitReportsPage();
}
document.addEventListener('printflow:page-init', printflowInitReportsPage);

</script>

<?php include __DIR__ . '/../includes/reports_analytics_scripts.php'; ?>
</body>
</html>
<?php
if (!isset($_GET['ajax'])) {
    ob_end_flush();
}
?>
