<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/service_design_fee.php';

$source = (string)file_get_contents(__DIR__ . '/../includes/JobOrderService.php');
if (!preg_match('/public static function ensureJobsForStoreOrder\(int \$orderId\): \?int\s*\{([\s\S]*?)\n    \}/', $source, $match)) {
    fwrite(STDERR, "FAIL: could not locate the store-order job creation lifecycle.\n");
    exit(1);
}
if (!str_contains($match[1], 'printflow_order_item_calculated_line_total($item)')) {
    fwrite(STDERR, "FAIL: lazily created jobs do not use the authoritative persisted order-item line total.\n");
    exit(1);
}
if (!str_contains($match[1], "self::tableHasColumn('products', 'product_type')")
    || !str_contains($match[1], 'AS product_type')) {
    fwrite(STDERR, "FAIL: lazy job creation assumes the optional products.product_type column exists.\n");
    exit(1);
}

$checks = [
    'legacy line total remains unit price times quantity' => [
        ['quantity' => 3, 'unit_price' => 100.00], 300.00,
    ],
    'layout fee is included once' => [
        ['quantity' => 3, 'unit_price' => 100.00, 'design_fee' => 200.00, 'line_total_override' => null], 500.00,
    ],
    'exact Set Price override is authoritative' => [
        ['quantity' => 3, 'unit_price' => 333.33, 'design_fee' => 200.00, 'line_total_override' => 1000.00], 1000.00,
    ],
];
foreach ($checks as $label => [$item, $expected]) {
    $actual = printflow_order_item_calculated_line_total($item);
    if (abs($actual - $expected) > 0.001) {
        fwrite(STDERR, "FAIL: {$label}; expected {$expected}, got {$actual}.\n");
        exit(1);
    }
}

echo "Job-order design-fee estimates passed: legacy, fee-inclusive, and exact override cases.\n";
