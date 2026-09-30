<?php
declare(strict_types=1);

$trace = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_transaction_trace.php');

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

$assert(
    str_contains($trace, 'printflow_store_order_ledger_date($orderId)')
        && str_contains($trace, "substr((string)\$expectedLedgerDateRaw, 0, 10)"),
    'trace audit compares ledger business date to order date portion'
);
$assert(
    str_contains($trace, "'expected_ledger_date'")
        && str_contains($trace, 'Marked deducted without matching'),
    'trace audit exposes expected ledger date and inconsistent-state findings'
);
$assert(
    str_contains($trace, 'do not clear deducted_at or automatically reissue'),
    'trace warns against blind double deduction'
);

echo "POS transaction trace material audit: {$passed} assertions passed.\n";
