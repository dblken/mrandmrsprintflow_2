<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/reports_dashboard_indexes.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$source = (string)file_get_contents($root . '/includes/reports_dashboard_indexes.php');
$expect(function_exists('printflow_ensure_reports_dashboard_indexes'), 'Index helper is defined.');
$expect(str_contains($source, 'idx_orders_branch_order_date'), 'Orders branch/date index is declared.');
$expect(str_contains($source, 'ADD INDEX') && str_contains($source, 'printflow_reports_dashboard_index_exists'), 'Indexes are added only when missing.');

$dashboard = (string)file_get_contents($root . '/admin/dashboard.php');
$expect(str_contains($dashboard, 'printflow_ensure_reports_dashboard_indexes()'), 'Admin dashboard ensures dashboard indexes once per request.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Reports dashboard index wiring tests passed.\n";
