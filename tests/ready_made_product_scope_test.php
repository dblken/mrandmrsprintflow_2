<?php
declare(strict_types=1);

if (!function_exists('db_table_has_column')) {
    function db_table_has_column(string $table, string $column): bool
    {
        return $table === 'order_items' && in_array($column, ['service_id', 'item_type'], true);
    }
}

require_once dirname(__DIR__) . '/includes/ready_made_product_order_scope.php';

$scope = printflow_ready_made_product_order_scope_sql('o');
if (strpos($scope, 'product_id') === false) {
    fwrite(STDERR, "Ready-made scope must key off catalog product_id lines.\n");
    exit(1);
}
$source = file_get_contents(dirname(__DIR__) . '/includes/ready_made_product_order_scope.php');
if (is_string($source) && strpos($source, 'custom_service') !== false) {
    fwrite(STDERR, "Scope must not exclude sticker/catalog lines because of legacy item_type.\n");
    exit(1);
}
if (strpos($scope, 'order_type') === false) {
    fwrite(STDERR, "Custom/service workflows must stay on other order_type values.\n");
    exit(1);
}

echo "OK ready_made_product_scope_test\n";
