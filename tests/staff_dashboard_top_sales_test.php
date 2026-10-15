<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$queries = (string)file_get_contents($root . '/includes/staff_dashboard_queries.php');
$api = (string)file_get_contents($root . '/staff/api_dashboard_stats.php');
$dashboard = (string)file_get_contents($root . '/staff/dashboard.php');
$cache = (string)file_get_contents($root . '/includes/staff_dashboard_cache.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($queries, 'function printflow_staff_dashboard_confirmed_sale_sql') !== false, 'confirmed sale SQL helper exists');
$expect(strpos($queries, "'pos_draft', 'pos_merged'") !== false, 'top sales excludes POS draft and merged shells');
$expect(strpos($queries, 'COUNT(DISTINCT') === false, 'top sales counts distinct orders in PHP buckets');
$expect(strpos($queries, 'printflow_staff_dashboard_top_sales') !== false, 'top sales aggregate helper exists');
$expect(strpos($api, 'printflow_staff_dashboard_top_sales') !== false, 'dashboard API uses shared top sales helper');
$expect(strpos($api, 'printflow_staff_dashboard_cache_get') !== false, 'dashboard API reads summary cache');
$expect(strpos($dashboard, 'dashboard_deferred_sections') !== false, 'POS dashboard defers heavy sections to API');
$expect(strpos($cache, 'printflow_staff_dashboard_cache_invalidate_branch') !== false, 'branch cache invalidation exists');
$expect(strpos($queries, 'printflow_staff_recent_orders_visibility_sql') !== false, 'recent orders visibility helper exists');
$expect(strpos($queries, 'printflow_staff_list_page_size') !== false, 'shared staff list page size exists');
$expect(strpos($api, 'printflow_staff_recent_orders_visibility_sql') !== false, 'dashboard API filters recent orders');

if ($failures !== []) {
    fwrite(STDERR, "staff_dashboard_top_sales_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "staff_dashboard_top_sales_test passed.\n";
