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

$order = db_query(
    'SELECT order_id, order_type, order_date, status, payment_status, payment_method, total_amount, amount_paid, branch_id
     FROM orders WHERE order_id = ? LIMIT 1',
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

$from = $order ? substr((string)$order['order_date'], 0, 10) : date('Y-m-d', strtotime('-30 days'));
$toEnd = date('Y-m-d 23:59:59', strtotime($from . ' +1 day'));
$breakdown = pf_reports_official_sales_breakdown($from, $toEnd, 'all', 5000);
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
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
