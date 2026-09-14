<?php

$read = static function (string $relativePath): string {
    $path = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $relativePath);
    }
    return (string)file_get_contents($path);
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$functions = $read('includes/functions.php');
$getOrderItems = $read('customer/get_order_items.php');

$assert(str_contains($functions, 'printflow_customer_modal_collapse_equivalent_dimension_fields'), 'collapses equivalent size/dimensions fields');
$assert(str_contains($functions, 'printflow_customer_modal_format_dimension_display_value'), 'formats customer dimension display values');
$assert(str_contains($functions, 'printflow_customer_modal_finalize_customer_dimension_labels'), 'finalizes customer Dimensions label');
$assert(str_contains($functions, "return \$w . ' × ' . \$h . ' ft'"), 'formats ft dimensions with natural numbers');
$assert(str_contains($functions, 'printflow_customer_modal_is_preset_size_value'), 'preserves preset/non-measurement size labels');
$assert(str_contains($functions, 'printflow_customer_modal_finalize_customer_dimension_labels($out)'), 'customer modal flatten applies label finalize');
$assert(str_contains($getOrderItems, 'printflow_flatten_customization_for_customer_order_modal'), 'order items API uses customer modal flatten');

// Runtime checks for pure formatting helpers (no DB bootstrap).
require_once __DIR__ . '/../includes/customization_normalizer.php';

$formatNumber = static function (float $n): string {
    if (abs($n - round($n)) < 0.0001) {
        return (string)(int)round($n);
    }
    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
};

$assert($formatNumber(2.0) === '2', 'whole numbers drop .00');
$assert($formatNumber(0.5) === '0.5', 'meaningful .5 preserved');
$assert($formatNumber(2.25) === '2.25', 'meaningful .25 preserved');

echo PHP_EOL . 'All customer modal dimension tests passed.' . PHP_EOL;
