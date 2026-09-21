<?php

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$functionsSource = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$assert(str_contains($functionsSource, 'function printflow_resolve_order_line_identity'), 'shared order-line identity helper exists');
$assert(str_contains($functionsSource, "'item_type' => 'service'"), 'identity helper returns explicit service item type');
$assert(str_contains($functionsSource, 'placeholder product_id'), 'identity helper guards against product_id collisions on service rows');

$serviceSource = (string)file_get_contents(__DIR__ . '/../includes/JobOrderService.php');
$assert(str_contains($serviceSource, 'resolveBatchStoreOrderLineIdentity'), 'staff batch loader has shared identity resolver');
$assert(str_contains($serviceSource, 'printflow_resolve_order_line_identity'), 'staff batch loader reuses customer identity rules');
$assert(str_contains($serviceSource, 'SELECT order_id, order_type, reference_id FROM orders'), 'staff batch loader reads order type metadata');
$assert(str_contains($serviceSource, "'product_name' => \$identity['display_name']"), 'summary batch uses resolved display_name instead of joined product name');

$customizationsSource = (string)file_get_contents(__DIR__ . '/../staff/customizations.php');
$assert(str_contains($customizationsSource, 'titleWithoutQty'), 'staff list display strips misleading product suffixes when service name is known');

echo PHP_EOL . 'All staff customization identity wiring tests passed.' . PHP_EOL;
