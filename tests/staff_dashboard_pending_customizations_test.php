<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        echo "FAIL: {$message}\n";
        $failures++;
        return;
    }
    echo "OK: {$message}\n";
};

$kpi = (string)file_get_contents($root . '/includes/pos_dashboard_kpi.php');
$counts = (string)file_get_contents($root . '/includes/staff_customization_counts.php');
$api = (string)file_get_contents($root . '/admin/job_orders_api.php');
$cache = (string)file_get_contents($root . '/includes/staff_dashboard_cache.php');
$queries = (string)file_get_contents($root . '/includes/staff_dashboard_queries.php');

$assert(strpos($kpi, 'printflow_staff_customization_pending_count') !== false, 'POS KPI uses canonical customization pending count');
$assert(strpos($kpi, "SELECT COUNT(*) AS count\n             FROM orders o\n             WHERE o.branch_id = ?\n               AND {\$staffOrderScopeSql}") === false, 'legacy raw orders COUNT pending removed from POS KPI');
$assert(strpos($counts, 'printflow_staff_customization_count_rows') !== false, 'shared count row builder exists');
$assert(strpos($counts, '_count_key') !== false, 'counts dedupe by order key');
$assert(strpos($counts, 'jo_api_pick_authoritative_list_row') !== false, 'authoritative status merge on dedupe');
$assert(strpos($counts, "listSource !== 'pos'") !== false, 'POS scope excludes standalone job/service rows');
$assert(strpos($api, 'printflow_staff_customization_bucket_counts') !== false, 'job_orders_api customization_counts uses shared bucket logic');
$assert(strpos($cache, 'pending_kpi_customization_counts_20261016') !== false, 'staff dashboard cache key version bumped for pending KPI fix');

$assert(strpos($queries, 'printflow_dashboard_order_service_labels') !== false, 'top sales labels align with Recent Orders service labels');
$assert(strpos($queries, 'printflow_staff_dashboard_top_sales_eligible_orders') !== false, 'top sales uses eligible-order scope helper');
$assert(strpos($queries, 'order_ids') !== false && strpos($queries, 'order_count') !== false, 'top sales buckets unique order IDs per label');

exit($failures > 0 ? 1 : 0);
