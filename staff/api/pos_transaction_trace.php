<?php
/**
 * Read-only, staff-only diagnostic for a completed POS transaction.
 * Remove or restrict further once the custom-datetime incident is closed.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/InventoryManager.php';

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
// Stock helpers detect this transaction and skip their legacy schema setup.
// Keep every diagnostic query read-only and in one consistent snapshot.
global $conn;
if (!$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to start read-only trace.']);
    exit;
}
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
    'SELECT customization_id, order_id, order_item_id, status, created_at, updated_at FROM customizations WHERE order_id = ? ORDER BY customization_id',
    'i',
    [$orderId]
) ?: [];
$jobs = db_query(
    'SELECT id, order_id, order_item_id, branch_id, status, created_at, updated_at FROM job_orders WHERE order_id = ? ORDER BY id',
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
    $materials = db_query(
        'SELECT id, job_order_id, std_order_id, item_id, quantity, uom, deducted_at FROM job_order_materials WHERE std_order_id = ? ORDER BY id',
        'i', [$orderId]
    ) ?: [];
    $ledger = db_query(
        "SELECT id, transaction_id, item_id, product_id, branch_id, direction, quantity, uom, ref_type, ref_id, transaction_date, created_at, notes
         FROM inventory_transactions
         WHERE UPPER(ref_type) IN ('ORDER', 'ORDER_PRODUCT') AND ref_id = ?
         ORDER BY id DESC",
        'i',
        [$orderId]
    ) ?: [];
}

$stock = [];
$deductionAudit = [];
$expectedLedgerDateRaw = function_exists('printflow_store_order_ledger_date')
    ? printflow_store_order_ledger_date($orderId)
    : trim((string)($order['order_date'] ?? ''));
$expectedLedgerDate = $expectedLedgerDateRaw !== '' && $expectedLedgerDateRaw !== null
    ? substr((string)$expectedLedgerDateRaw, 0, 10)
    : '';
foreach ($materials as $material) {
    $itemId = (int)$material['item_id'];
    $branchId = (int)$order['branch_id'];
    foreach ($jobs as $job) {
        if ((int)$job['id'] === (int)$material['job_order_id'] && (int)$job['branch_id'] > 0) {
            $branchId = (int)$job['branch_id'];
            break;
        }
    }
    $key = $itemId . ':' . $branchId;
    $matchingLedgerIds = [];
    $wrongDateLedgerIds = [];
    foreach ($ledger as $movement) {
        $jobMatches = (int)$material['job_order_id'] > 0
            ? (int)$movement['ref_id'] === (int)$material['job_order_id']
            : in_array((int)$movement['ref_id'], $jobIds, true);
        if (strtoupper((string)$movement['ref_type']) === 'JOB_ORDER'
            && strtoupper((string)$movement['direction']) === 'OUT'
            && $jobMatches && (int)$movement['item_id'] === $itemId
            && (int)$movement['branch_id'] === $branchId) {
            $matchingLedgerIds[] = (int)$movement['id'];
            $movementBusinessDate = substr(trim((string)$movement['transaction_date']), 0, 10);
            if ($expectedLedgerDate !== '' && $movementBusinessDate !== $expectedLedgerDate) {
                $wrongDateLedgerIds[] = (int)$movement['id'];
            }
        }
    }
    $stamp = trim((string)($material['deducted_at'] ?? ''));
    $markedDeducted = $stamp !== '' && $stamp !== '0000-00-00 00:00:00';
    if ($markedDeducted && $matchingLedgerIds === []) {
        $finding = 'Marked deducted without matching job/item/branch ledger evidence. Manual reconciliation required; do not clear deducted_at or automatically reissue.';
    } elseif ($wrongDateLedgerIds !== []) {
        $finding = 'Matching ledger OUT row(s) exist but transaction_date does not match the order business date.';
    } elseif ($markedDeducted) {
        $finding = 'Marked deducted with matching JOB_ORDER ledger evidence.';
    } else {
        $finding = 'Assignment not yet marked deducted; stock should deduct on completion.';
    }
    $deductionAudit[] = [
        'assignment_id' => (int)$material['id'],
        'job_order_id' => (int)($material['job_order_id'] ?? 0),
        'item_id' => $itemId,
        'branch_id' => $branchId,
        'deducted_at' => $markedDeducted ? $stamp : null,
        'expected_ledger_date' => $expectedLedgerDate !== '' ? $expectedLedgerDate : null,
        'marked_deducted' => $markedDeducted,
        'candidate_ledger_ids' => $matchingLedgerIds,
        'wrong_business_date_ledger_ids' => $wrongDateLedgerIds,
        'requires_review' => ($markedDeducted && $matchingLedgerIds === []) || $wrongDateLedgerIds !== [],
        'finding' => $finding,
    ];
    if (!isset($stock[$key])) {
        $stock[$key] = [
            'item_id' => $itemId,
            'branch_id' => $branchId,
            'current_stock' => InventoryManager::getStockOnHand($itemId, $branchId),
        ];
    }
}
$queryErrors = function_exists('printflow_db_errors') ? printflow_db_errors() : [];
// Never report an empty result as proof that no rows exist if a query failed.
if ($queryErrors !== []) {
    error_log('[PrintFlow POS trace] order=' . $orderId . ' query_errors=' . json_encode($queryErrors));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Trace query failed; inspect PHP error log for this order ID.']);
    exit;
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
    'order_business_date' => $order['order_date'],
    'expected_ledger_business_date' => $expectedLedgerDate !== '' ? $expectedLedgerDate : null,
    'material_stock_snapshot' => array_values($stock),
    'material_deduction_audit' => $deductionAudit,
    'stock_snapshot_note' => 'Current stock only. Capture before and after completion to prove the change.',
], JSON_UNESCAPED_SLASHES);
$conn->rollback();
