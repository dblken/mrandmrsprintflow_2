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
$expect(strpos($queries, 'printflow_staff_dashboard_top_sales_eligible_orders') !== false, 'top sales eligible-order query exists');
$expect(strpos($queries, 'printflow_dashboard_order_service_labels') !== false, 'top sales uses same order labels as Recent Orders');
$expect(strpos($queries, 'LEFT JOIN job_orders jo ON jo.order_item_id') === false, 'top sales no longer mislabels via job_orders.service_type join');
$expect(strpos($queries, 'printflow_staff_recent_orders_visibility_sql(\'o\', $staffRole)') !== false, 'top sales shares Recent Orders visibility rules');
$expect(strpos($queries, 'printflow_staff_dashboard_top_sales') !== false, 'top sales aggregate helper exists');
$expect(strpos($api, 'printflow_staff_dashboard_top_sales') !== false, 'dashboard API uses shared top sales helper');
$expect(strpos($api, '$staffRole') !== false && strpos($api, 'printflow_staff_dashboard_top_sales') !== false, 'dashboard API passes staff role into top sales');
$expect(strpos($api, 'printflow_staff_dashboard_time_bounds_meta($timeMeta)') !== false, 'recent orders and top sales share Manila time bounds');
$expect(strpos($api, 'printflow_staff_dashboard_cache_get') !== false, 'dashboard API reads summary cache');
$expect(strpos($dashboard, 'dashboard_deferred_sections') !== false, 'POS dashboard defers heavy sections to API');
$expect(strpos($cache, 'top_sales_order_labels_20261016') !== false, 'staff dashboard cache version bumped for top sales fix');
$expect(strpos($cache, 'printflow_staff_dashboard_cache_invalidate_branch') !== false, 'branch cache invalidation exists');
$expect(strpos($queries, 'printflow_staff_recent_orders_visibility_sql') !== false, 'recent orders visibility helper exists');
$expect(strpos($api, 'printflow_staff_recent_orders_visibility_sql') !== false, 'dashboard API filters recent orders');
$expect(strpos($api, 'printflow_staff_dashboard_top_sales_debug') !== false, 'dashboard API exposes top sales debug mapping');

if ($failures !== []) {
    fwrite(STDERR, "staff_dashboard_top_sales_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "staff_dashboard_top_sales_test passed.\n";
