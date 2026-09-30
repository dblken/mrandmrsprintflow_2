<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$functions = (string)file_get_contents($root . '/includes/functions.php');
$jobs = (string)file_get_contents($root . '/includes/JobOrderService.php');
$inventory = (string)file_get_contents($root . '/includes/InventoryManager.php');
$rolls = (string)file_get_contents($root . '/includes/RollService.php');

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

$assert(
    str_contains($functions, 'SELECT order_date FROM orders WHERE order_id = ? LIMIT 1')
        && str_contains($functions, 'return $raw;'),
    'deferred deductions use the linked parent orders.order_date as their business timestamp'
);
$assert(
    str_contains($jobs, '$ledgerTransactionDate = printflow_store_order_ledger_date($storeOrderId);')
        && !str_contains($jobs, 'substr($ledgerTransactionDate, 0, 10)'),
    'job deduction keeps the complete parent transaction datetime'
);
$assert(
    substr_count($jobs, '$ledgerTransactionDate') >= 8
        && str_contains($jobs, "'JOB_ORDER'")
        && str_contains($jobs, 'RollService::deductFIFO(')
        && str_contains($jobs, 'InventoryManager::issueStock('),
    'roll, material, lamination, and ink deduction paths receive the same ledger timestamp'
);
$assert(
    str_contains($inventory, '$date = $date ?: date(\'Y-m-d H:i:s\');')
        && !str_contains($inventory, 'substr($date, 0, 10)'),
    'inventory writer preserves the deferred transaction timestamp instead of reverting to today'
);
$assert(
    str_contains($jobs, 'SET deducted_at = NOW()')
        && str_contains($jobs, 'WARNING: job_id=%d has no parent order_date'),
    'deducted_at remains the real processing time while a missing business date is logged in debug mode'
);
$assert(
    str_contains($jobs, '$normalizedTargetStatus === \'COMPLETED\'')
        && str_contains($jobs, "UPPER(TRIM(COALESCE(status, ''))) <> 'CANCELLED'")
        && str_contains($jobs, 'self::processDeductions((int)$orderId, [\'materials\' => true, \'inks\' => false]);'),
    'completed jobs with pending materials still run the idempotent recovery deduction pass'
);
$assert(
    str_contains($jobs, 'PRINTFLOW_MATERIAL_DEDUCTION_DEBUG')
        && str_contains($jobs, "'stock_before' =>")
        && str_contains($jobs, "'stock_after' =>")
        && str_contains($jobs, "'inventory_transaction_id' =>"),
    'debug mode records material IDs, branch, stock before/after, and created ledger evidence'
);
$assert(
    str_contains($rolls, '$transactionDate,')
        && str_contains($rolls, '$branchId'),
    'roll helper keeps transactionDate as its optional final argument before writing the ledger'
);

echo "Deferred job-order transaction datetime: {$passed} assertions passed.\n";
