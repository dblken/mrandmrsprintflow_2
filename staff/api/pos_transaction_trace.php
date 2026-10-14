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
// Ordinary SELECTs. A read-only snapshot on this host aborts the connection
// and makes the following lookup look like a missing order.
global $conn;
$readOnlySnapshot = false;
$orderSql = 'SELECT order_id, branch_id, order_source, order_date, status, payment_status, total_amount
             FROM orders WHERE order_id = ?';
$orderTypes = 'i';
$orderParams = [$orderId];
if ($branchFilter !== null && (int)$branchFilter > 0) {
    $orderSql .= ' AND branch_id = ?';
    $orderTypes .= 'i';
    $orderParams[] = (int)$branchFilter;
}
$errorsBeforeLookup = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
$orders = db_query($orderSql, $orderTypes, $orderParams) ?: [];
$orderLookupErrors = function_exists('printflow_db_errors')
    ? array_slice(printflow_db_errors(), $errorsBeforeLookup)
    : [];
if ($orders === [] && $orderLookupErrors !== []) {
    error_log('[PrintFlow POS trace] order=' . $orderId . ' lookup_errors=' . json_encode(array_map(static function ($error) {
        return [
            'stage' => (string)($error['stage'] ?? ''),
            'errno' => (int)($error['errno'] ?? 0),
        ];
    }, $orderLookupErrors)));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Trace query failed before the order lookup.']);
    exit;
}
if ($orders === []) {
    if ($readOnlySnapshot && function_exists('printflow_db_in_transaction') && printflow_db_in_transaction($conn)) {
        $conn->rollback();
    }
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
    'SELECT customization_id, order_id, order_item_id, status FROM customizations WHERE order_id = ? ORDER BY customization_id',
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
        "SELECT id, job_order_id, item_id, quantity, deducted_at
         FROM job_order_materials
         WHERE job_order_id IN ({$placeholders})
         ORDER BY id",
        $types,
        $jobIds
    ) ?: [];
    $ledger = db_query(
        "SELECT id, item_id, branch_id, direction, quantity, ref_type, ref_id, transaction_date
         FROM inventory_transactions
         WHERE (UPPER(ref_type) = 'JOB_ORDER' AND ref_id IN ({$placeholders}))
            OR (UPPER(ref_type) IN ('ORDER', 'ORDER_PRODUCT') AND ref_id = ?)
         ORDER BY id DESC",
        $types . 'i',
        array_merge($jobIds, [$orderId])
    ) ?: [];
} else {
    $materials = db_query(
        'SELECT id, job_order_id, item_id, quantity, deducted_at FROM job_order_materials WHERE job_order_id = ? ORDER BY id',
        'i', [$orderId]
    ) ?: [];
    $ledger = db_query(
        "SELECT id, item_id, branch_id, direction, quantity, ref_type, ref_id, transaction_date
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
$expectedLedgerDate = function_exists('printflow_normalize_business_datetime')
    ? (printflow_normalize_business_datetime((string)$expectedLedgerDateRaw) ?? '')
    : trim((string)$expectedLedgerDateRaw);
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
            $movementBusinessDate = function_exists('printflow_normalize_business_datetime')
                ? (printflow_normalize_business_datetime((string)$movement['transaction_date']) ?? '')
                : trim((string)$movement['transaction_date']);
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
}
$queryErrors = function_exists('printflow_db_errors')
    ? array_slice(printflow_db_errors(), $errorsBeforeLookup)
    : [];
// A stock helper can record schema noise. Keep the ledger rows and report only
// the errno so a failed read is not presented as an empty deduction.
$readErrorNos = array_values(array_unique(array_map(static function ($error): int {
    return (int)($error['errno'] ?? 0);
}, $queryErrors)));

echo json_encode([
    'success' => true,
    'trace_generated_at' => date('Y-m-d H:i:s'),
    'read_only_snapshot' => $readOnlySnapshot,
    'order' => $order,
    'order_items' => $items,
    'customizations' => $customizations,
    'job_orders' => $jobs,
    'material_assignments' => $materials,
    'inventory_transactions' => $ledger,
    'read_error_numbers' => $readErrorNos,
    'order_business_date' => $order['order_date'],
    'expected_ledger_business_date' => $expectedLedgerDate !== '' ? $expectedLedgerDate : null,
    'material_stock_snapshot' => array_values($stock),
    'material_deduction_audit' => $deductionAudit,
    'stock_snapshot_note' => 'Current stock only. Capture before and after completion to prove the change.',
], JSON_UNESCAPED_SLASHES);
if ($readOnlySnapshot && function_exists('printflow_db_in_transaction') && printflow_db_in_transaction($conn)) {
    $conn->rollback();
}
