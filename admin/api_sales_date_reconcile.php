<?php
/**
 * Read-only reconciliation for one business date (orders.order_date).
 */
require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/branch_context.php';

if (!is_logged_in() || !in_array(get_user_type(), ['Admin', 'Manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$businessDate = trim((string)($_GET['business_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'business_date required (YYYY-MM-DD)']);
    exit;
}

$branchId = $_GET['branch_id'] ?? init_branch_context(false)['selected_branch_id'] ?? 'all';
$toEnd = $businessDate . ' 23:59:59';
$storeSales = pf_reports_store_order_sales_expr('o');
$storeRevenue = pf_reports_store_order_revenue_expr('o');
$jobSales = pf_reports_job_order_sales_expr('jo');
$jobRevenue = pf_reports_job_order_revenue_expr('jo');
$jobDate = pf_reports_job_order_sales_date_expr('jo');
$jobExclude = pf_reports_job_exclude_linked_custom_store_sale_sql('jo');

[$branchSql, $branchTypes, $branchParams] = branch_where_parts('o', $branchId);
$orders = db_query(
    "SELECT o.order_id, o.order_date, o.order_type, o.status, o.payment_status, o.total_amount, o.amount_paid, o.branch_id
     FROM orders o
     WHERE DATE(o.order_date) = ? AND {$storeSales}{$branchSql}
     ORDER BY o.order_id ASC",
    's' . $branchTypes,
    array_merge([$businessDate], $branchParams)
) ?: [];

[$jobBranchSql, $jobBranchTypes, $jobBranchParams] = branch_where_parts('jo', $branchId);
$jobs = db_query(
    "SELECT jo.id, jo.order_id, jo.status, jo.payment_status, jo.estimated_total, jo.amount_paid,
            {$jobDate} AS sales_date_expr
     FROM job_orders jo
     WHERE {$jobSales}{$jobExclude}{$jobBranchSql}
       AND DATE({$jobDate}) = ?
     ORDER BY jo.id ASC",
    $jobBranchTypes . 's',
    array_merge($jobBranchParams, [$businessDate])
) ?: [];

$canonical = pf_reports_canonical_official_sales_totals($businessDate, $toEnd, $branchId);
$breakdown = pf_reports_official_sales_breakdown($businessDate, $toEnd, $branchId, 5000);

$storeSum = round(array_sum(array_map(static fn(array $r): float => (float)($r['total_amount'] ?? 0), $orders)), 2);

echo json_encode([
    'success' => true,
    'business_date' => $businessDate,
    'branch_id' => $branchId,
    'store_orders' => $orders,
    'store_order_count' => count($orders),
    'store_order_total_amount_sum' => $storeSum,
    'orphan_job_orders' => $jobs,
    'orphan_job_count' => count($jobs),
    'canonical_totals' => $canonical,
    'official_breakdown_summary' => $breakdown['summary'] ?? [],
    'official_transaction_rows' => count($breakdown['transactions'] ?? []),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
