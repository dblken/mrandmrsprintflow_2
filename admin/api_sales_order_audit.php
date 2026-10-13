<?php
/**
 * Read-only sales audit for one store order (Admin/Manager).
 * Compares orders vs job_orders vs official sales transaction rows.
 */
require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';

if (!is_logged_in() || !in_array(get_user_type(), ['Admin', 'Manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'order_id required']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
$orderSelect = 'order_id, order_type, order_date, status, payment_status, payment_method, total_amount, branch_id';
if (function_exists('db_table_has_column') && db_table_has_column('orders', 'amount_paid')) {
    $orderSelect .= ', amount_paid';
}
if (function_exists('db_table_has_column') && db_table_has_column('orders', 'order_source')) {
    $orderSelect .= ', order_source';
}
$order = db_query(
    "SELECT {$orderSelect} FROM orders WHERE order_id = ? LIMIT 1",
    'i',
    [$orderId]
)[0] ?? null;

$jobs = db_query(
    'SELECT id, order_id, status, payment_status, payment_method, estimated_total, amount_paid, created_at, payment_verified_at
     FROM job_orders WHERE order_id = ?',
    'i',
    [$orderId]
) ?: [];

$provider = db_query(
    'SELECT id, status, amount_centavos, paid_amount_centavos, channel, created_at, provider_paid_at
     FROM provider_payments WHERE order_id = ?',
    'i',
    [$orderId]
) ?: [];

$submissions = [];
try {
    if (function_exists('db_table_has_column') && db_table_has_column('payment_submissions', 'order_id')) {
        $submissions = db_query(
            'SELECT id, verification_status, submitted_amount, created_at FROM payment_submissions WHERE order_id = ?',
            'i',
            [$orderId]
        ) ?: [];
    }
} catch (Throwable $e) {
    $submissions = [];
}

$from = trim((string)($_GET['business_date'] ?? ''));
if ($from === '' && $order) {
    $from = substr((string)$order['order_date'], 0, 10);
}
if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
$toEnd = $from . ' 23:59:59';
$breakdown = pf_reports_official_sales_breakdown($from, $toEnd, 'all', 5000);
$storeDaySum = 0.0;
$storeDayCount = 0;
if ($order && function_exists('pf_reports_store_order_sales_expr') && function_exists('pf_reports_store_order_revenue_expr')) {
    $storeSales = pf_reports_store_order_sales_expr('o');
    $storeRevenue = pf_reports_store_order_revenue_expr('o');
    [$daySql, $dayTypes, $dayParams] = pf_reports_date_expr_where('o.order_date', $from, $toEnd);
    $dayRow = db_query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM({$storeRevenue}), 0) AS rev
         FROM orders o
         WHERE {$storeSales}{$daySql}",
        $dayTypes,
        $dayParams
    )[0] ?? [];
    $storeDayCount = (int)($dayRow['cnt'] ?? 0);
    $storeDaySum = round((float)($dayRow['rev'] ?? 0), 2);
}
$txnForOrder = array_values(array_filter(
    $breakdown['transactions'] ?? [],
    static fn(array $row): bool => (int)($row['store_order_id'] ?? 0) === $orderId
        || ((string)($row['ref_type'] ?? '') === 'order' && (int)($row['id'] ?? 0) === $orderId)
));

$txnTotal = round(array_sum(array_map(static fn(array $r): float => (float)($r['amount'] ?? 0), $txnForOrder)), 2);

echo json_encode([
    'success' => true,
    'order_id' => $orderId,
    'order' => $order,
    'job_orders' => $jobs,
    'provider_payments' => $provider,
    'payment_submissions' => $submissions,
    'official_sales_transactions' => $txnForOrder,
    'official_sales_transaction_count' => count($txnForOrder),
    'official_sales_transaction_total' => $txnTotal,
    'duplicate_risk' => count($txnForOrder) > 1,
    'business_date' => $from,
    'store_orders_on_business_date_count' => $storeDayCount,
    'store_orders_on_business_date_revenue_sum' => $storeDaySum,
    'official_breakdown_summary_total' => round((float)($breakdown['summary']['total_sales'] ?? 0), 2),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
