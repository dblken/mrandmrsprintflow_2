<?php
/**
 * PrintFlow — Professional Analytical Report
 * Optimized for Executive Decision Making & Professional Print
 */

// Production Error Handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

set_time_limit(120);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/reports_date_range.php';

require_role(['Admin', 'Manager']);

// ── INPUTS ────────────────────────────────────────────────────────────────────
$report    = $_GET['report'] ?? 'orders';
$dateRange = pf_reports_export_date_range();
$from      = $dateRange['from'];
$to        = $dateRange['to'];
$fromStart = $dateRange['fromStart'];
$toEnd     = $dateRange['toEnd'];
$autoprint = (bool)($_GET['autoprint'] ?? 0);

$branchCtx  = init_branch_context(false);
$branchId   = $branchCtx['selected_branch_id'];
$branchName = $branchCtx['branch_name'];



// REQ: Update Report Title
$reportTitles = [
    'customers' => 'Customers Report',
    'sales' => 'Sales Report',
    'sales_revenue' => 'Daily Sales Revenue Report',
    'sales_trend' => '12-Month Sales Trend Report',
    'forecast' => 'Product Demand Forecast Report',
    'best_selling' => 'Best Selling Services Report',
    'revenue_dist' => 'Revenue Distribution Report',
    'heatmap' => 'Seasonal Demand Heatmap Report',
    'locations' => 'Customer Locations Report',
    'customization' => 'Customization Usage Report',
    'branch_perf' => 'Branch Performance Report',
    'order_status' => 'Order Status Breakdown Report',
    'top_customers' => 'Top Customers Report',
    'insights' => 'Business Insights Report',
    'orders' => 'PrintFlow Business Performance Report',
    'full' => 'PrintFlow Business Performance Report'
];
$reportTitle = $reportTitles[$report] ?? 'PrintFlow Business Performance Report';
$isCustomers = ($report === 'customers');

// ── HELPERS ───────────────────────────────────────────────────────────────────
function pf_fmt_curr($val) { return '₱' . number_format((float)$val, 2); }
function pf_fmt_qty($val) { return number_format((int)$val); }
function pf_fmt_pct($val, $nd = 'N/A') { 
    if ($val === null || $val === false) return $nd;
    return round((float)$val, 1) . '%';
}
function pf_status_badge($val, $avg) {
    if (!$avg || $avg <= 0) return '<span class="status-neutral">N/A</span>';
    $threshold = $avg;
    if ($val > ($threshold * 1.3)) return '<span class="status-high">High Demand</span>';
    if ($val < ($threshold * 0.7)) return '<span class="status-low">Low / Declining</span>';
    return '<span class="status-med">Moderate</span>';
}

// ── SECTION FLAGS ─────────────────────────────────────────────────────────────
$show_all = ($report === 'orders' || $report === 'sales' || $report === 'full' || $report === '');
$show_sales_revenue = $show_all || ($report === 'sales_revenue');
$show_sales_trend   = $show_all || ($report === 'sales_trend');
$show_forecast      = $show_all || ($report === 'forecast');
$show_best_selling  = $show_all || ($report === 'best_selling');
$show_revenue_dist  = $show_all || ($report === 'revenue_dist');
$show_heatmap       = $show_all || ($report === 'heatmap');
$show_locations     = $show_all || ($report === 'locations');
$show_customization = $show_all || ($report === 'customization');
$show_branch_perf   = $show_all || ($report === 'branch_perf');
$show_order_status  = $show_all || ($report === 'order_status');
$show_top_customers = $show_all || ($report === 'top_customers');
$show_insights      = $show_all || ($report === 'insights');

// Debug info (only show in development)
if (false && isset($_GET['debug'])) {
    echo "<pre>Debug Info:\n";
    echo "Report: $report\n";
    echo "From: $from\n";
    echo "To: $to\n";
    echo "Branch ID: $branchId\n";
    echo "Branch Name: $branchName\n";
    echo "Show Sales Revenue: " . ($show_sales_revenue ? 'YES' : 'NO') . "\n";
    echo "</pre>";
}

// ── DATA INITIALIZATION ───────────────────────────────────────────────────────
$grandTotalOrd = 0; $grandTotalRev = 0; $paidOrders = 0; $avgOrderVal = 0;
$status_counts = []; $daily_sales = []; $trend_labels = []; $trend_rev = []; $trend_ord = [];
$forecast_data = []; $best_selling = []; $rev_dist = []; $heatmap_matrix = []; $heatmap_months = [];
$locations = []; $custom_usage = []; $branch_perf = []; $top_customers = []; $insights = [];
$totalCust = 0; $activeCust = 0; $customers = []; $totalSpentSum = 0;
$forecast_revenue = 0; $forecast_orders = 0; $next_month_label = date('M Y', strtotime('+1 month'));
$top_kpi_product = null; $customer_locations = []; $insight_branch_perf = [];
$top_products_insight = []; $insight_custom_usage = []; $revenue_delta = null; $active_events = [];

try {
    [$bSql, $bTypes, $bParams] = branch_where_parts('o', $branchId);

    // ── 0. CUSTOMERS (IF REQUESTED) ───────────────────────────────────────────
    if ($isCustomers) {
        $stats = db_query("SELECT COUNT(*) as tot, SUM(CASE WHEN status='Activated' THEN 1 ELSE 0 END) as act FROM customers") ?: [];
        $totalCust = (int)($stats[0]['tot'] ?? 0);
        $activeCust = (int)($stats[0]['act'] ?? 0);
        
        $cList = db_query(
            "SELECT c.customer_id, c.first_name, c.last_name, c.email, c.contact_number, c.status, c.created_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id) as order_count,
                    (SELECT SUM(total_amount) FROM orders o WHERE o.customer_id = c.customer_id AND payment_status='Paid') as total_spent
             FROM customers c ORDER BY total_spent DESC"
        ) ?: [];
        foreach($cList as $row) {
            $name = trim(($row['first_name']??'') . ' ' . ($row['last_name']??''));
            $row['name'] = $name ?: 'Unknown Customer';
            $customers[] = $row;
            $totalSpentSum += (float)($row['total_spent'] ?? 0);
        }
    }

    // ── 1. GLOBAL SUMMARY & STATUS ────────────────────────────────────────────
    $sumRes = db_query("SELECT COUNT(*) as total_orders, SUM(CASE WHEN o.payment_status='Paid' THEN o.total_amount ELSE 0 END) as total_revenue, AVG(CASE WHEN o.payment_status='Paid' THEN o.total_amount ELSE NULL END) as avg_order_value FROM orders o WHERE o.order_date BETWEEN ? AND ?$bSql", 'ss'.$bTypes, array_merge([$from, $toEnd], $bParams)) ?: [];
    $summaryAttrs = $sumRes[0] ?? [];
    $grandTotalOrd = (int)($summaryAttrs['total_orders'] ?? 0);
    $grandTotalRev = (float)($summaryAttrs['total_revenue'] ?? 0);
    $avgOrderVal   = (float)($summaryAttrs['avg_order_value'] ?? 0);

    if ($show_order_status) {
        $status_counts = db_query("SELECT o.status, COUNT(*) as cnt, SUM(o.total_amount) as total FROM orders o WHERE o.order_date BETWEEN ? AND ?$bSql GROUP BY o.status ORDER BY cnt DESC", 'ss'.$bTypes, array_merge([$from, $toEnd], $bParams)) ?: [];
    }
    if ($show_sales_revenue) {
        // Safe query with better error handling and debug logging
        try {
            $daily_sales = db_query(
                "SELECT DATE(o.order_date) as day, 
                        COUNT(*) as cnt, 
                        SUM(CASE WHEN o.payment_status='Paid' THEN o.total_amount ELSE 0 END) as revenue 
                 FROM orders o 
                 WHERE o.order_date BETWEEN ? AND ?{$bSql} 
                 GROUP BY DATE(o.order_date) 
                 ORDER BY day ASC", 
                'ss'.$bTypes, 
                array_merge([$from, $toEnd], $bParams)
            ) ?: [];
            
            // Debug logging if requested
            if (false && isset($_GET['debug'])) {
                echo "<pre>Daily Sales Query Debug:\n";
                echo "Query returned: " . count($daily_sales) . " rows\n";
                echo "Sample data: " . print_r(array_slice($daily_sales, 0, 3), true) . "\n";
                echo "</pre>";
            }
            
            // Also get paid orders count for the period
            $paidOrdersResult = db_query(
                "SELECT COUNT(*) as paid_count 
                 FROM orders o 
                 WHERE o.order_date BETWEEN ? AND ?{$bSql} 
                   AND o.payment_status = 'Paid'",
                'ss'.$bTypes,
                array_merge([$from, $toEnd], $bParams)
            ) ?: [];
            $paidOrders = (int)($paidOrdersResult[0]['paid_count'] ?? 0);
        } catch (Exception $e) {
            if (false && isset($_GET['debug'])) {
                echo "<pre>Daily Sales Query Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
            }
            $daily_sales = [];
            $paidOrders = 0;
        }
    }
    if ($show_sales_trend || $show_insights) {
        [$bo, $bto, $bpo] = branch_where_parts('o', $branchId);
        [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);
        $trend_start_key = date('Y-m', strtotime('-11 months'));

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

        $storeByMonth = [];
        foreach ($raw_store as $r) $storeByMonth[$r['mon']] = $r;
        $jobByMonth = [];
        foreach ($raw_job as $r) $jobByMonth[$r['mon']] = $r;

        $trend12_revenues = [];
        $trend12_orders = [];
        $curr_m = $trend_start_key;
        $end_m = date('Y-m');
        while ($curr_m <= $end_m) {
            $s = $storeByMonth[$curr_m] ?? [];
            $j = $jobByMonth[$curr_m] ?? [];
            $orders = (int)($s['orders_store'] ?? 0) + (int)($j['orders_custom'] ?? 0);
            $revenue = (float)($s['revenue_store'] ?? 0) + (float)($j['revenue_custom'] ?? 0);

            $trend_labels[$curr_m] = date('M Y', strtotime($curr_m . '-01'));
            $trend_rev[$curr_m] = $revenue;
            $trend_ord[$curr_m] = $orders;
            $trend12_revenues[] = $revenue;
            $trend12_orders[] = $orders;

            $curr_m = date('Y-m', strtotime($curr_m . '-01 +1 month'));
        }
        $forecast_revenue = !empty($trend12_revenues) ? pf_linreg($trend12_revenues) : 0;
        $forecast_orders = !empty($trend12_orders) ? (int) pf_linreg($trend12_orders) : 0;
    }
    if ($show_forecast) {
        $top_p = pf_reports_top_products_merged($from, $toEnd, $branchId, 5);
        foreach($top_p as $p) {
            $pid = $p['product_id'];
            $hist = [];
            if ($pid) {
                $hRows = db_query("SELECT DATE_FORMAT(o.order_date,'%Y-%m') as mon, SUM(oi.quantity) as qty FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE oi.product_id=? AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)$bSql GROUP BY mon ORDER BY mon", 'i'.$bTypes, array_merge([$pid], $bParams)) ?: [];
                foreach($hRows as $hr) $hist[] = (int)$hr['qty'];
            }
            $f3 = function_exists('pf_forecast3') ? pf_forecast3($hist) : [0,0,0];
            $forecast_data[] = [
                'name' => (string)($p['product_name'] ?? 'Unknown'),
                'current' => (int)($p['qty_sold'] ?? 0),
                'forecast' => (int)array_sum($f3)
            ];
        }
    }
    if ($show_best_selling || $show_revenue_dist || $show_insights) {
        $best_selling = pf_reports_top_products_merged($from, $toEnd, $branchId, 10);
        if ($show_revenue_dist) {
            foreach($best_selling as $b) {
                 $rev_dist[] = [
                     'label' => (string)($b['product_name'] ?? 'Unknown'),
                     'value' => (float)($b['revenue'] ?? 0),
                     'pct'   => $grandTotalRev > 0 ? round(($b['revenue'] / $grandTotalRev) * 100, 1) : 0
                 ];
            }
        }
    }
    if ($show_heatmap) {
        $yNow = (int)date('Y');
        $heatmap_matrix = pf_reports_heatmap_matrix($yNow, $branchId);
        $heatmap_months = pf_reports_heatmap_month_short_labels();
    }
    if ($show_locations) {
        $locRows = pf_reports_customer_locations_merged($from, $toEnd, $branchId, 10, true);
        $locations = [];
        foreach ($locRows as $r) {
            $locations[] = [
                'city' => $r['city'],
                'cnt' => $r['orders'],
                'spent' => (float)($r['revenue'] ?? 0),
            ];
        }
    }
    if ($show_customization) {
        $custom_usage = db_query("SELECT COALESCE(NULLIF(TRIM(p.name), ''), 'Customization') as product, SUM(CASE WHEN (COALESCE(CHAR_LENGTH(oi.design_image),0) > 0 OR COALESCE(CHAR_LENGTH(oi.design_file),0) > 0) THEN 1 ELSE 0 END) as custom_count, SUM(CASE WHEN (COALESCE(CHAR_LENGTH(oi.design_image),0) = 0 AND COALESCE(CHAR_LENGTH(oi.design_file),0) = 0) THEN 1 ELSE 0 END) as template_count FROM orders o JOIN order_items oi ON o.order_id=oi.order_id JOIN products p ON oi.product_id=p.product_id WHERE o.order_date BETWEEN ? AND ?$bSql GROUP BY p.product_id LIMIT 10", 'ss'.$bTypes, array_merge([$from, $toEnd], $bParams)) ?: [];
    }
    if ($show_branch_perf || $show_insights) {
        $branch_perf = pf_reports_branch_performance_merged($from, $toEnd, is_manager() ? $branchId : 'all') ?: [];
    }
    if ($show_top_customers) {
        $top_customers = db_query("SELECT c.customer_id, CONCAT(c.first_name,' ',c.last_name) as name, COUNT(o.order_id) as orders, SUM(o.total_amount) as spent FROM orders o JOIN customers c ON o.customer_id=c.customer_id WHERE o.order_date BETWEEN ? AND ?$bSql GROUP BY c.customer_id ORDER BY spent DESC LIMIT 10", 'ss'.$bTypes, array_merge([$from, $toEnd], $bParams)) ?: [];
    }
    if ($show_insights) {
        $top_products_insight = pf_reports_top_products_merged('', '', $branchId, 10);
        $insight_branch_perf = pf_reports_branch_performance_merged('', '', is_manager() ? $branchId : 'all') ?: [];

        $top_kpi_product = db_query(
            "SELECT p.name, SUM(oi.quantity) as qty
             FROM order_items oi
             JOIN products p ON oi.product_id=p.product_id
             JOIN orders o ON oi.order_id=o.order_id
             WHERE o.order_date BETWEEN ? AND ?{$bSql}
             GROUP BY p.product_id, p.name
             ORDER BY qty DESC LIMIT 1",
            'ss' . $bTypes,
            array_merge([$from, $toEnd], $bParams)
        )[0] ?? null;

        $customer_locations = pf_reports_customer_locations_merged($from, $toEnd, $branchId, 12, false);

        $insight_custom_usage = db_query(
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
             WHERE 1=1 {$bSql}
             GROUP BY p.product_id, p.name
             HAVING (
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 1 THEN oi.quantity ELSE 0 END) +
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 0 THEN oi.quantity ELSE 0 END)
             ) > 0
             ORDER BY (
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 1 THEN oi.quantity ELSE 0 END) +
                 SUM(CASE WHEN COALESCE(dc.has_upload, 0) = 0 THEN oi.quantity ELSE 0 END)
             ) DESC LIMIT 8",
            $bTypes,
            $bParams
        ) ?: [];

        $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevFrom = date('Y-m-d', strtotime($from) - $days * 86400);
        $prevToEnd = date('Y-m-d', strtotime($from) - 86400) . ' 23:59:59';
        $prev = db_query(
            "SELECT SUM(CASE WHEN o.payment_status='Paid' THEN o.total_amount ELSE 0 END) as revenue
             FROM orders o WHERE o.order_date BETWEEN ? AND ?{$bSql}",
            'ss' . $bTypes,
            array_merge([$prevFrom, $prevToEnd], $bParams)
        )[0] ?? [];
        $prev_revenue = (float)($prev['revenue'] ?? 0);
        $revenue_delta = $prev_revenue > 0 ? round((($grandTotalRev - $prev_revenue) / $prev_revenue) * 100, 1) : null;

        if (!empty($top_products_insight)) {
            $insights[] = "<strong>" . htmlspecialchars((string)$top_products_insight[0]['product_name']) . "</strong> is the top-selling service with <strong>" . number_format((int)$top_products_insight[0]['qty_sold']) . "</strong> units to date.";
        }
        if (!empty($customer_locations)) {
            $insights[] = "Most transactions (store + custom jobs) map to <strong>" . htmlspecialchars(trim((string)$customer_locations[0]['city'])) . "</strong> (" . number_format((int)$customer_locations[0]['orders']) . " in period).";
        }
        if ($forecast_revenue > 0) {
            $insights[] = "Next month (<strong>" . htmlspecialchars($next_month_label) . "</strong>) revenue forecast: <strong>&#8369;" . number_format($forecast_revenue, 0) . "</strong> based on 12-month trend.";
        }
        if (!empty($insight_custom_usage) && (int)$insight_custom_usage[0]['custom_count'] > (int)$insight_custom_usage[0]['template_count']) {
            $insights[] = "<strong>" . htmlspecialchars((string)$insight_custom_usage[0]['product']) . "</strong> shows the highest custom design upload rate.";
        }
        if (!empty($insight_branch_perf) && count($insight_branch_perf) > 1) {
            $insights[] = "<strong>" . htmlspecialchars((string)$insight_branch_perf[0]['branch_name']) . "</strong> leads all branches with &#8369;" . number_format((float)$insight_branch_perf[0]['revenue'], 0) . " revenue.";
        }
        if ($revenue_delta !== null) {
            if ($revenue_delta > 10) {
                $insights[] = "Revenue is up <strong>{$revenue_delta}%</strong> vs. the previous period &mdash; strong growth momentum.";
            } elseif ($revenue_delta < -10) {
                $insights[] = "Revenue dropped <strong>" . abs($revenue_delta) . "%</strong> vs. the previous period &mdash; consider a promotional push.";
            }
        }
    }
} catch (Throwable $e) {
    error_log('[PrintFlow] Reports print failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>Report Error</h1><p>Unable to generate this report right now.</p>';
    die();
}

$month_now = (int)date('n');
$seasonal_events = [
    ['months'=>[3,4,5],  'icon'=>'', 'event'=>'Graduation Season',    'services'=>['Tarpaulin Printing','Layouts / Graphic Layout Services']],
    ['months'=>[4,5],    'icon'=>'', 'event'=>'Election Season',       'services'=>['Tarpaulin Printing','Reflectorized Stickers / Signages']],
    ['months'=>[11,12],  'icon'=>'', 'event'=>'Holiday Season',        'services'=>['Souvenirs','Stickers on Sintraboard']],
    ['months'=>[2],      'icon'=>'', 'event'=>"Valentine's Season",    'services'=>['Stickers','Transparent Stickers']],
    ['months'=>[6,10],   'icon'=>'', 'event'=>'School Opening Season', 'services'=>['Layouts / Graphic Layout Services','T-shirt Printing']],
    ['months'=>[7,8,9],  'icon'=>'', 'event'=>'Midyear Peak',          'services'=>['Decals / Stickers (Print & Cut)','Sintraboard Standees']],
];
foreach ($seasonal_events as $ev) {
    if (in_array($month_now, $ev['months'], true)) {
        $active_events[] = $ev;
        $svcs = implode(' and ', array_map(fn($s) => "<strong>" . htmlspecialchars($s) . "</strong>", $ev['services']));
        $insights[] = "<strong>" . htmlspecialchars($ev['event']) . "</strong> is active &mdash; expect increased demand for {$svcs}.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PrintFlow <?php echo htmlspecialchars($reportTitle); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #1f2937; line-height: 1.5; margin: 0; padding: 24px 32px; background: #fff; }
        
        /* REQ: Professional Header with Subheading */
        .header-wrap { border-bottom: 2px solid #111; padding-bottom: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
        .title-group h1 { font-size: 28px; font-weight: 800; margin: 0; color: #000; letter-spacing: -0.02em; }
        .title-group .subheading { font-size: 14px; font-weight: 600; color: #4b5563; margin: 4px 0 12px; }
        
        .meta-grid { display: grid; grid-template-columns: auto auto; gap: 4px 32px; font-size: 11px; color: #6b7280; }
        .meta-grid strong { color: #111; }
        
        /* REQ: Improved Section Title Styling */
        .section { margin-bottom: 24px; page-break-inside: auto; display: block; visibility: visible; }
        .section-title { font-size: 16px; font-weight: 800; color: #000; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid #000; text-transform: uppercase; letter-spacing: 0.02em; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; border: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 700; font-size: 11px; text-transform: uppercase; color: #374151; padding: 10px 14px; border-bottom: 2px solid #e5e7eb; text-align: left; }
        td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; color: #1f2937; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.zebra:nth-child(even) { background: #fafafa; }
        tr.total td { background: #f9fafb; font-weight: 700; border-top: 2px solid #e5e7eb; }

        .status-high { color: #065f46; background: #ecfdf5; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; }
        .status-med { color: #854d0e; background: #fefce8; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; }
        .status-low { color: #9f1239; background: #fff1f2; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; }
        .status-neutral { color: #4b5563; background: #f3f4f6; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; }
        
        .insight-box { background: #fdfdfd; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
        .footer { margin-top: 64px; border-top: 1px solid #e5e7eb; padding-top: 24px; text-align: center; font-size: 11px; color: #9ca3af; }
        
        @media print { 
            * { box-sizing: border-box; }
            html, body { margin: 0; padding: 0; width: 100%; height: 100%; }
            body { padding: 16px 24px; background: #fff !important; }
            .no-print { display: none !important; }
            .section { display: block !important; visibility: visible !important; page-break-inside: auto; margin-bottom: 20px; }
            table { display: table !important; visibility: visible !important; width: 100%; page-break-inside: auto; }
            .header-wrap { display: flex !important; margin-bottom: 16px; padding-bottom: 12px; }
            .meta-grid { display: grid !important; }
            @page { margin: 1.2cm; size: A4; }
        }
    </style>
</head>
<body>
    <div class="header-wrap">
        <div class="title-group">
            <h1><?php echo htmlspecialchars($reportTitle); ?></h1>
            <p class="subheading">Report Overview</p>
            <div class="meta-grid">
                <span><strong>Branch:</strong> <?php echo htmlspecialchars($branchName); ?></span>
                <span><strong>Period:</strong> <?php echo date('M j, Y', strtotime($from)).' – '.date('M j, Y', strtotime($to)); ?></span>
                <span><strong>Ref ID:</strong> PF-<?php echo strtoupper(substr(md5($from.$to),0,6)); ?></span>
                <span><strong>Generated:</strong> <?php echo date('M j, Y g:i A'); ?></span>
            </div>
        </div>
        <button class="no-print" onclick="window.print()" style="padding:10px 20px; background:#0d9488; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600;">Print Document</button>
    </div>

    <?php if (false && isset($_GET['debug'])): ?>
    <div class="no-print" style="background:#fef3c7;padding:20px;margin-bottom:20px;border:2px solid #f59e0b;border-radius:8px;">
        <h3 style="margin:0 0 10px;color:#92400e;">Debug Information</h3>
        <table style="font-size:11px;border:none;">
            <tr><td style="border:none;padding:4px;"><strong>Report Type:</strong></td><td style="border:none;padding:4px;"><?php echo htmlspecialchars($report); ?></td></tr>
            <tr><td style="border:none;padding:4px;"><strong>Is Customers:</strong></td><td style="border:none;padding:4px;"><?php echo $isCustomers ? 'YES' : 'NO'; ?></td></tr>
            <tr><td style="border:none;padding:4px;"><strong>Show Sales Revenue:</strong></td><td style="border:none;padding:4px;"><?php echo $show_sales_revenue ? 'YES' : 'NO'; ?></td></tr>
            <tr><td style="border:none;padding:4px;"><strong>Daily Sales Count:</strong></td><td style="border:none;padding:4px;"><?php echo count($daily_sales); ?></td></tr>
            <tr><td style="border:none;padding:4px;"><strong>From Date:</strong></td><td style="border:none;padding:4px;"><?php echo $from; ?></td></tr>
            <tr><td style="border:none;padding:4px;"><strong>To Date:</strong></td><td style="border:none;padding:4px;"><?php echo $to; ?></td></tr>
            <tr><td style="border:none;padding:4px;"><strong>Branch ID:</strong></td><td style="border:none;padding:4px;"><?php echo $branchId; ?></td></tr>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($isCustomers): ?>
        <div class="section">
            <h2 class="section-title">Customer Performance Summary</h2>
            <table style="max-width: 400px;">
                <tr><td>Database Total</td><td class="num"><?php echo pf_fmt_qty($totalCust); ?></td></tr>
                <tr><td>Activated Accounts</td><td class="num"><?php echo pf_fmt_qty($activeCust); ?></td></tr>
            </table>
        </div>
        <div class="section">
            <h2 class="section-title">Customer Ledger (By Engagement)</h2>
            <table>
                <thead><tr><th>ID</th><th>Client Name</th><th>Email</th><th class="center">Status</th><th class="num">Orders</th><th class="num">Revenue Contribution</th></tr></thead>
                <tbody>
                    <?php if (empty($customers)): ?><tr><td colspan="6" class="center">No customer data matched criteria.</td></tr><?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr class="zebra">
                        <td><?php echo (int)($c['customer_id']??0); ?></td>
                        <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($c['email']??'—'); ?></td>
                        <td class="center"><span class="status-med"><?php echo htmlspecialchars($c['status']??'Inactive'); ?></span></td>
                        <td class="num"><?php echo pf_fmt_qty($c['order_count']); ?></td>
                        <td class="num"><?php echo pf_fmt_curr($c['total_spent']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total"><td colspan="5">COMBINED CONTRIBUTION</td><td class="num"><?php echo pf_fmt_curr($totalSpentSum); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?php if ($show_sales_revenue): ?>
        <div class="section">
            <h2 class="section-title">Daily Sales Performance</h2>
            <?php 
            // Debug: Show query parameters
            if (false && isset($_GET['debug'])) {
                echo "<div style='background:#fef3c7;padding:10px;margin-bottom:10px;border:1px solid #f59e0b;border-radius:4px;'><strong>Debug Info:</strong><br>";
                echo "From: $from<br>To: $to<br>Branch ID: $branchId<br>";
                echo "Daily sales count: " . count($daily_sales) . "<br>";
                echo "Paid orders: $paidOrders</div>";
            }
            
            if (empty($daily_sales)): 
            ?>
                <div style="padding: 20px; text-align: center; color: #6b7280; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <p><strong>No transactions recorded for this period.</strong></p>
                    <p style="font-size: 12px; margin: 8px 0 0;">Period: <?php echo date('M j, Y', strtotime($from)).' – '.date('M j, Y', strtotime($to)); ?></p>
                    <p style="font-size: 12px; margin: 4px 0 0;">Branch: <?php echo htmlspecialchars($branchName); ?></p>
                    <?php if (false && isset($_GET['debug'])): ?>
                    <p style="font-size: 11px; margin: 8px 0 0; color: #dc2626;">Debug mode: Check if orders exist in database for this date range and branch.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Date</th><th class="num">Transactions</th><th class="num">Revenue</th><th class="num">Avg Order</th></tr></thead>
                    <tbody>
                        <?php 
                        $totalDays = count($daily_sales);
                        $totalTransactions = 0;
                        $totalRevenue = 0;
                        foreach ($daily_sales as $d): 
                            $dayTransactions = (int)($d['cnt'] ?? 0);
                            $dayRevenue = (float)($d['revenue'] ?? 0);
                            $avgOrder = $dayTransactions > 0 ? $dayRevenue / $dayTransactions : 0;
                            $totalTransactions += $dayTransactions;
                            $totalRevenue += $dayRevenue;
                        ?>
                        <tr class="zebra">
                            <td><?php echo date('M j, Y (D)', strtotime($d['day'])); ?></td>
                            <td class="num"><?php echo pf_fmt_qty($dayTransactions); ?></td>
                            <td class="num"><?php echo pf_fmt_curr($dayRevenue); ?></td>
                            <td class="num"><?php echo pf_fmt_curr($avgOrder); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total">
                            <td><strong>PERIOD TOTALS (<?php echo $totalDays; ?> days)</strong></td>
                            <td class="num"><strong><?php echo pf_fmt_qty($totalTransactions); ?></strong></td>
                            <td class="num"><strong><?php echo pf_fmt_curr($totalRevenue); ?></strong></td>
                            <td class="num"><strong><?php echo $totalTransactions > 0 ? pf_fmt_curr($totalRevenue / $totalTransactions) : pf_fmt_curr(0); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top: 12px; font-size: 11px; color: #6b7280;">
                    <p><strong>Summary:</strong> <?php echo $paidOrders; ?> paid orders out of <?php echo $totalTransactions; ?> total transactions (<?php echo $totalTransactions > 0 ? round(($paidOrders / $totalTransactions) * 100, 1) : 0; ?>% payment rate)</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($show_sales_trend): ?>
        <div class="section">
            <h2 class="section-title">12-Month Historical Trend</h2>
            <table>
                <thead><tr><th>Billing Month</th><th class="num">Orders</th><th class="num">Net Revenue</th></tr></thead>
                <tbody>
                    <?php if (empty($trend_labels)): ?><tr><td colspan="3" class="center">Historical data unavailable.</td></tr><?php else: ?>
                    <?php foreach ($trend_labels as $key => $lbl): ?>
                    <tr class="zebra"><td><?php echo htmlspecialchars($lbl); ?></td><td class="num"><?php echo pf_fmt_qty($trend_ord[$key]??0); ?></td><td class="num"><?php echo pf_fmt_curr($trend_rev[$key]??0); ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_forecast): ?>
        <div class="section">
            <h2 class="section-title">Forward Demand Projections (3-Mo)</h2>
            <table>
                <thead><tr><th>Service Category</th><th class="num">Current Period</th><th class="num">Projected Total</th><th class="num">Status</th></tr></thead>
                <tbody>
                    <?php if (empty($forecast_data)): ?><tr><td colspan="4" class="center">Insufficient volume for projection.</td></tr><?php else: ?>
                    <?php 
                        $all_f = array_column($forecast_data, 'forecast'); 
                        $avg_f = !empty($all_f) ? array_sum($all_f) / count($all_f) : 0;
                        foreach ($forecast_data as $f): 
                    ?>
                    <tr class="zebra">
                        <td><strong><?php echo htmlspecialchars($f['name']); ?></strong></td>
                        <td class="num"><?php echo pf_fmt_qty($f['current']); ?> nodes</td>
                        <td class="num"><?php echo pf_fmt_qty($f['forecast']); ?> nodes</td>
                        <td class="num"><?php echo pf_status_badge($f['forecast'], $avg_f); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_best_selling): ?>
        <div class="section">
            <h2 class="section-title">Top Ranking Services</h2>
            <table>
                <thead><tr><th>Rank</th><th>Service Identifier</th><th class="num">Volume</th><th class="num">Revenue</th></tr></thead>
                <tbody>
                    <?php if (empty($best_selling)): ?><tr><td colspan="4" class="center">No sales data recorded.</td></tr><?php else: ?>
                    <?php foreach ($best_selling as $idx => $p): ?>
                    <tr class="zebra"><td class="num">#<?php echo $idx + 1; ?></td><td><?php echo htmlspecialchars((string)($p['product_name']??'Unknown')); ?></td><td class="num"><?php echo pf_fmt_qty($p['qty_sold']??0); ?></td><td class="num"><?php echo pf_fmt_curr($p['revenue']??0); ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_revenue_dist): ?>
        <div class="section">
            <h2 class="section-title">Revenue Allocation</h2>
            <table style="max-width: 500px;">
                <thead><tr><th>Service Category</th><th class="num">Value</th><th class="num">Contribution</th></tr></thead>
                <tbody>
                    <?php if (empty($rev_dist)): ?><tr><td colspan="3" class="center">No data.</td></tr><?php else: ?>
                    <?php foreach ($rev_dist as $rd): ?>
                    <tr class="zebra"><td><?php echo htmlspecialchars($rd['label']); ?></td><td class="num"><?php echo pf_fmt_curr($rd['value']); ?></td><td class="num"><?php echo $rd['pct']; ?>%</td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_heatmap): ?>
        <div class="section">
            <h2 class="section-title">Seasonal Velocity Map (Unit Volume)</h2>
            <table style="font-size: 10px;">
                <thead><tr><th style="min-width: 130px;">Line Item</th><?php foreach ($heatmap_months as $m) echo "<th class='num'>$m</th>"; ?></tr></thead>
                <tbody>
                    <?php if (empty($heatmap_matrix)): ?><tr><td colspan="13" class="center">No seasonal data found.</td></tr><?php else: ?>
                    <?php foreach ($heatmap_matrix as $prod => $mo): ?>
                    <tr class="zebra"><td><strong><?php echo htmlspecialchars((string)$prod); ?></strong></td><?php for ($i=1; $i<=12; $i++) { $q = (int)($mo[$i]['qty']??0); echo "<td class='num'>".($q>0?pf_fmt_qty($q):'—')."</td>"; } ?></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_locations): ?>
        <div class="section">
            <h2 class="section-title">Regional Performance</h2>
            <table>
                <thead><tr><th>City / Region</th><th class="num">Transactions</th><th class="num">Net Contribution</th><th class="num">Status</th></tr></thead>
                <tbody>
                    <?php if (empty($locations)): ?><tr><td colspan="4" class="center">No location records.</td></tr><?php else: ?>
                    <?php 
                        $all_l = array_column($locations, 'cnt');
                        $avg_l = !empty($all_l) ? array_sum($all_l) / count($all_l) : 0;
                        foreach ($locations as $loc): 
                    ?>
                    <tr class="zebra"><td><?php echo htmlspecialchars((string)($loc['city']??'Unknown')); ?></td><td class="num"><?php echo pf_fmt_qty($loc['cnt']); ?></td><td class="num"><?php echo pf_fmt_curr($loc['spent']); ?></td><td class="num"><?php echo pf_status_badge($loc['cnt'], $avg_l); ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_customization): ?>
        <div class="section">
            <h2 class="section-title">Product Adaptability Index</h2>
            <table>
                <thead><tr><th>Product Line</th><th class="num">Custom Uploads</th><th class="num">Template Fixed</th><th class="num">Adaptability %</th></tr></thead>
                <tbody>
                    <?php if (empty($custom_usage)): ?><tr><td colspan="4" class="center">No data.</td></tr><?php else: ?>
                    <?php foreach ($custom_usage as $c): ?>
                    <tr class="zebra">
                        <td><?php echo htmlspecialchars((string)($c['product']??'Unknown')); ?></td>
                        <td class="num"><?php echo pf_fmt_qty($c['custom_count']); ?></td>
                        <td class="num"><?php echo pf_fmt_qty($c['template_count']); ?></td>
                        <td class="num"><?php $tot = (int)($c['custom_count']??0)+(int)($c['template_count']??0); echo $tot > 0 ? round(($c['custom_count']/$tot)*100,1) : '0'; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_branch_perf): ?>
        <div class="section">
            <h2 class="section-title">Operational Intelligence (Branch Comparison)</h2>
            <table>
                <thead><tr><th>Business Unit</th><th class="num">POS Orders</th><th class="num">POS Revenue</th><th class="num">Project Orders</th><th class="num">Project Revenue</th></tr></thead>
                <tbody>
                    <?php if (empty($branch_perf)): ?><tr><td colspan="5" class="center">No branch data available.</td></tr><?php else: ?>
                    <?php foreach ($branch_perf as $bp): ?>
                    <tr class="zebra"><td><strong><?php echo htmlspecialchars((string)($bp['branch_name']??'Unknown')); ?></strong></td><td class="num"><?php echo pf_fmt_qty($bp['orders_store']); ?></td><td class="num"><?php echo pf_fmt_curr($bp['revenue_store']); ?></td><td class="num"><?php echo pf_fmt_qty($bp['orders_jobs']); ?></td><td class="num"><?php echo pf_fmt_curr($bp['revenue_jobs']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_order_status): ?>
        <div class="section">
            <h2 class="section-title">Pipeline Health Breakdown</h2>
            <table>
                <thead><tr><th>Current State</th><th class="num">Volume</th><th class="num">Locked Value</th></tr></thead>
                <tbody>
                    <?php if (empty($status_counts)): ?><tr><td colspan="3" class="center">Queue empty.</td></tr><?php else: ?>
                    <?php foreach ($status_counts as $sc): ?>
                    <tr class="zebra"><td><?php echo htmlspecialchars((string)($sc['status']??'Unknown')); ?></td><td class="num"><?php echo pf_fmt_qty($sc['cnt']); ?></td><td class="num"><?php echo pf_fmt_curr($sc['total']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_top_customers): ?>
        <div class="section">
            <h2 class="section-title">High-Value Client Ranking</h2>
            <table>
                <thead><tr><th>Customer Identity</th><th class="num">Frequency</th><th class="num">LT Revenue</th><th class="num">Status</th></tr></thead>
                <tbody>
                    <?php if (empty($top_customers)): ?><tr><td colspan="4" class="center">No client data.</td></tr><?php else: ?>
                    <?php 
                        $all_c = array_column($top_customers, 'spent');
                        $avg_c = !empty($all_c) ? array_sum($all_c) / count($all_c) : 0;
                        foreach ($top_customers as $tc): 
                    ?>
                    <tr class="zebra"><td><strong><?php echo htmlspecialchars((string)($tc['name']??'Valued Client')); ?></strong></td><td class="num"><?php echo pf_fmt_qty($tc['orders']); ?> orders</td><td class="num"><?php echo pf_fmt_curr($tc['spent']); ?></td><td class="num"><?php echo pf_status_badge($tc['spent'], $avg_c); ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($show_insights): ?>
        <div class="section">
            <h2 class="section-title">Strategic Insights & Projections</h2>
            <div class="insight-box">
                <table>
                    <thead>
                        <tr>
                            <th>Predicted Revenue</th>
                            <th class="num">Predicted Orders</th>
                            <th>Top Forecast Service</th>
                            <th class="num">Avg Order Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="zebra">
                            <td><strong>&#8369;<?php echo number_format($forecast_revenue, 0); ?></strong><br><span style="font-size:11px;color:#6b7280;"><?php echo htmlspecialchars($next_month_label); ?></span></td>
                            <td class="num"><strong><?php echo number_format($forecast_orders); ?></strong><br><span style="font-size:11px;color:#6b7280;"><?php echo htmlspecialchars($next_month_label); ?></span></td>
                            <td><strong><?php echo htmlspecialchars((string)($top_kpi_product['name'] ?? ($top_products_insight[0]['product_name'] ?? 'No data for period'))); ?></strong><br><span style="font-size:11px;color:#6b7280;">Highest historical demand</span></td>
                            <td class="num"><strong>&#8369;<?php echo number_format($avgOrderVal, 0); ?></strong><br><span style="font-size:11px;color:#6b7280;">This period</span></td>
                        </tr>
                    </tbody>
                </table>
                <ul style="margin:0; padding-left:20px; color:#374151;">
                    <?php foreach ($insights as $insight) echo "<li style='margin-bottom:10px;'>".$insight."</li>"; ?>
                </ul>
                <p style="font-size:11px; color:#6b7280; border-top:1px solid #f3f4f6; margin-top:20px; padding-top:10px;">* Projections are based on the rolling 12-month revenue and order trend. Seasonal notes are listed separately when active.</p>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="footer">
        Confidential Report &nbsp;·&nbsp; Generated by PrintFlow Analytics &nbsp;·&nbsp; <?php echo date('F j, Y g:i A'); ?>
    </div>
    <script>window.onload = function() { if (window.location.search.includes('autoprint=1')) setTimeout(()=>window.print(),800); };</script>
</body>
</html>
