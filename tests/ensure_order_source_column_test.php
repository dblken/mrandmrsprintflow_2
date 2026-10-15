<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/includes/ensure_order_source_column.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(
    str_contains($source, 'if ($ordersTable === [] || !is_array($ordersTable))')
        && str_contains($source, 'db_table_has_column(\'orders\', \'order_source\')')
        && !preg_match('/\$result->num_rows/', $source)
        && !preg_match('/\$result->fetch_assoc\(\)/', $source),
    'order_source ensure checks query results and avoids mysqli false fatal usage'
);
$expect(
    str_contains($source, 'static $completed')
        && str_contains($source, 'printflow_db_record_error')
        && str_contains($source, 'catch (Throwable'),
    'Schema ensure is idempotent per request and logs failures without throwing'
);
$expect(
    str_contains((string)file_get_contents($root . '/includes/functions.php'), 'ensure_order_source_column.php'),
    'functions.php still loads the order_source schema helper'
);

if ($failures !== []) {
    fwrite(STDERR, "ensure_order_source_column_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "ensure_order_source_column_test passed.\n";
