<?php

declare(strict_types=1);

/**
 * Regression guard for Counter Staff Top Sales (Oct 2026 Cabuyao phantom T-shirt case).
 *
 * Root cause on production before staff_dashboard_metrics_20261016_v2:
 * - printflow_staff_dashboard_top_sales() joined job_orders and preferred jo.service_type
 *   before product/catalog names, so POS Plates/Mugs orders with stale job rows labeled
 *   "T-shirt Printing" were bucketed as "Tshirt" while Recent Orders used
 *   printflow_dashboard_order_service_labels() (Plates, Stickers, Mugs).
 * - Live order IDs affected (2026-10-16 Cabuyao): 27294, 27292 (Plates), plus one more
 *   in the same eligible set — not valid T-shirt sales for today.
 */

$root = dirname(__DIR__);
$queries = (string)file_get_contents($root . '/includes/staff_dashboard_queries.php');
$api = (string)file_get_contents($root . '/staff/api_dashboard_stats.php');
$cache = (string)file_get_contents($root . '/includes/staff_dashboard_cache.php');
$clear = (string)file_get_contents($root . '/clear_cache.php');
$version = (string)file_get_contents($root . '/includes/staff_dashboard_metrics_version.php');

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$expect(strpos($queries, 'printflow_dashboard_order_service_labels') !== false, 'Top Sales labels match Recent Orders resolver');
$expect(strpos($queries, 'jo.service_type') === false, 'Top Sales must not read job_orders.service_type for labels');
$expect(strpos($queries, 'printflow_staff_recent_orders_visibility_sql') !== false, 'Top Sales uses Recent Orders visibility SQL');
$expect(strpos($queries, 'printflow_staff_dashboard_time_bounds_meta') !== false, 'Top Sales uses Manila time bounds helper');
$expect(strpos($api, 'metrics_version') !== false, 'dashboard API exposes metrics_version for deploy verification');
$expect(strpos($api, 'orderTimeBounds') !== false, 'Recent Orders API shares orderTimeBounds with Top Sales');
$expect(strpos($cache, 'top_sales_order_labels_20261016') !== false, 'cache key version includes top sales fix');
$expect(strpos($clear, 'printflow_staff_dashboard_cache_flush_all') !== false, 'clear_cache flushes staff dashboard JSON cache');
$expect(strpos($version, 'staff_dashboard_metrics_20261016_v2') !== false, 'metrics version constant defined');

if ($failures !== []) {
    fwrite(STDERR, "staff_dashboard_top_sales_regression_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "staff_dashboard_top_sales_regression_test passed.\n";
