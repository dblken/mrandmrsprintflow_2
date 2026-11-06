<?php
declare(strict_types=1);

/**
 * Regression: Mark as Completed for POS custom orders with assigned sheet
 * materials must use the project DB connection (not an undefined $conn).
 *
 * Covers:
 * - undefined-connection source contract on the deduction writer
 * - successful once-only deduction (via processDeductions behavior harness)
 * - idempotent retry
 * - rollback on insufficient / invalid assignment
 */

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$inventory = (string)file_get_contents($root . '/includes/InventoryManager.php');
$productStock = (string)file_get_contents($root . '/includes/product_branch_stock.php');
$mutation = (string)file_get_contents($root . '/includes/order_status_mutation.php');
$jobs = (string)file_get_contents($root . '/includes/JobOrderService.php');
$endpoint = (string)file_get_contents($root . '/staff/update_order_status_process.php');

$issueStart = strpos($inventory, 'public static function issueStock(');
$issueEnd = $issueStart === false ? false : strpos($inventory, 'public static function getStockOnHand(', $issueStart);
$issueStock = ($issueStart !== false && $issueEnd !== false)
    ? substr($inventory, $issueStart, $issueEnd - $issueStart)
    : '';

$expect($issueStock !== '', 'issueStock method must be present for sheet-material completion deductions');
$globalPos = strpos($issueStock, 'global $conn');
$txnPos = strpos($issueStock, 'printflow_db_in_transaction($conn)');
$expect(
    $globalPos !== false && $txnPos !== false && $globalPos < $txnPos,
    'issueStock must declare global $conn before checking transaction state'
);

$recordStart = strpos($productStock, 'function printflow_record_product_inventory_transaction(');
$recordEnd = $recordStart === false ? false : strpos($productStock, "\nfunction ", $recordStart + 1);
$recordFn = ($recordStart !== false && $recordEnd !== false)
    ? substr($productStock, $recordStart, $recordEnd - $recordStart)
    : '';
$expect($recordFn !== '', 'product ledger helper must exist');
$recordGlobalPos = strpos($recordFn, 'global $conn');
$recordTxnPos = strpos($recordFn, 'printflow_db_in_transaction($conn)');
$expect(
    $recordGlobalPos !== false && $recordTxnPos !== false && $recordGlobalPos < $recordTxnPos,
    'printflow_record_product_inventory_transaction must declare global $conn before use'
);

$expect(str_contains($endpoint, 'printflow_staff_mutate_order_status('), 'customizations Mark as Completed posts to the shared status endpoint');
$expect(
    str_contains($mutation, "JobOrderService::syncStoreOrderToStatus(\$orderId, 'COMPLETED'")
        && str_contains($mutation, 'printflow_db_transaction_depth_pop($conn, false)'),
    'service completion must sync jobs and roll back the outer transaction on deduction failure'
);
$expect(
    str_contains($jobs, 'private static function processDeductions(')
        && str_contains($jobs, 'InventoryManager::issueStock(')
        && str_contains($jobs, "['materials' => true, 'inks' => false]"),
    'job completion deducts materials once via InventoryManager::issueStock'
);

// Behavioral undefined-$conn probe: same local-scope mistake that broke Mark as Completed.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$undefinedCaught = false;
try {
    (static function (): void {
        // Intentionally no global — mirrors the pre-fix issueStock bug.
        $active = isset($conn) ? $conn : $conn;
        unset($active);
    })();
} catch (Throwable $e) {
    $undefinedCaught = str_contains($e->getMessage(), 'Undefined variable')
        && str_contains($e->getMessage(), '$conn');
}
$expect($undefinedCaught, 'undefined local $conn must surface as an error (not be silently null)');

$fixedOk = false;
try {
    $GLOBALS['conn'] = (object)['active' => true];
    $fixedOk = (static function (): bool {
        global $conn;
        return is_object($conn) && !empty($conn->active);
    })();
} catch (Throwable $e) {
    $fixedOk = false;
    $failures[] = 'global $conn probe failed: ' . $e->getMessage();
}
$expect($fixedOk, 'declaring global $conn must bind the shared connection for transaction checks');
restore_error_handler();

$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$deductionTest = $root . '/tests/customization_deduction_behavior_test.php';
$deductionCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($deductionTest);
$deductionOutput = [];
$deductionCode = 1;
exec($deductionCmd . ' 2>&1', $deductionOutput, $deductionCode);
$deductionText = implode("\n", $deductionOutput);
$expect($deductionCode === 0, 'customization_deduction_behavior_test must exit 0');
$expect(
    str_contains($deductionText, 'stock 10 -> 8 and one ledger entry')
        && str_contains($deductionText, 'repeat deduction makes no second movement')
        && str_contains($deductionText, 'invalid/insufficient quantity rolls back'),
    'material deduction harness must cover success, idempotent retry, and rollback'
);

if ($failures !== []) {
    fwrite(STDERR, "custom_order_completion_conn_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "custom_order_completion_conn_test passed.\n";
