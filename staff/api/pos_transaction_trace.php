<?php
/**
 * Read-only, staff-only diagnostic for a completed POS transaction.
 * Remove or restrict further once the custom-datetime incident is closed.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');
if (!has_role(['Admin', 'Staff', 'Manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid order_id is required.']);
    exit;
}

$branchFilter = function_exists('printflow_branch_filter_for_user')
    ? printflow_branch_filter_for_user()
    : null;
$orderSql = 'SELECT order_id, branch_id, order_source, order_type, order_date, created_at, updated_at, status, payment_status, total_amount
             FROM orders WHERE order_id = ?';
$orderTypes = 'i';
$orderParams = [$orderId];
if ($branchFilter !== null && (int)$branchFilter > 0) {
    $orderSql .= ' AND branch_id = ?';
    $orderTypes .= 'i';
    $orderParams[] = (int)$branchFilter;
}
$orders = db_query($orderSql, $orderTypes, $orderParams) ?: [];
if ($orders === []) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found in your branch.']);
    exit;
}
$order = $orders[0];
$items = db_query(
    'SELECT order_item_id, product_id, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY order_item_id',
    'i',
    [$orderId]
) ?: [];
$customizations = db_query(
    'SELECT customization_id, order_item_id, status, created_at, updated_at FROM customizations WHERE order_id = ? ORDER BY customization_id',
    'i',
    [$orderId]
) ?: [];
$jobs = db_query(
    'SELECT id, order_item_id, branch_id, status, created_at, updated_at FROM job_orders WHERE order_id = ? ORDER BY id',
    'i',
    [$orderId]
) ?: [];
$jobIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $jobs)));

$materials = [];
$ledger = [];
if ($jobIds !== []) {
    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $types = str_repeat('i', count($jobIds));
    $materials = db_query(
        "SELECT id, job_order_id, std_order_id, item_id, quantity, uom, deducted_at
         FROM job_order_materials
         WHERE job_order_id IN ({$placeholders}) OR std_order_id = ?
         ORDER BY id",
        $types . 'i',
        array_merge($jobIds, [$orderId])
    ) ?: [];
    $ledger = db_query(
        "SELECT id, transaction_id, item_id, product_id, branch_id, direction, quantity, uom, ref_type, ref_id, transaction_date, created_at, notes
         FROM inventory_transactions
         WHERE (UPPER(ref_type) = 'JOB_ORDER' AND ref_id IN ({$placeholders}))
            OR (UPPER(ref_type) IN ('ORDER', 'ORDER_PRODUCT') AND ref_id = ?)
         ORDER BY id DESC",
        $types . 'i',
        array_merge($jobIds, [$orderId])
    ) ?: [];
} else {
    $ledger = db_query(
        "SELECT id, transaction_id, item_id, product_id, branch_id, direction, quantity, uom, ref_type, ref_id, transaction_date, created_at, notes
         FROM inventory_transactions
         WHERE UPPER(ref_type) IN ('ORDER', 'ORDER_PRODUCT') AND ref_id = ?
         ORDER BY id DESC",
        'i',
        [$orderId]
    ) ?: [];
}

echo json_encode([
    'success' => true,
    'trace_generated_at' => date('Y-m-d H:i:s'),
    'order' => $order,
    'order_items' => $items,
    'customizations' => $customizations,
    'job_orders' => $jobs,
    'material_assignments' => $materials,
    'inventory_transactions' => $ledger,
], JSON_UNESCAPED_SLASHES);
