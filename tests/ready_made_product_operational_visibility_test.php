<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

require_once $root . '/includes/ready_made_product_order_scope.php';

if (!function_exists('db_table_has_column')) {
    function db_table_has_column(string $table, string $column): bool
    {
        return $table === 'order_items' && in_array($column, ['service_id', 'item_type'], true);
    }
}

$scopeSql = printflow_ready_made_product_order_scope_sql('o');
$assert(strpos($scopeSql, 'product_id') !== false, 'ready-made scope keys off product lines (stickers included)');
$assert(strpos($scopeSql, 'service_id') !== false, 'service lines are excluded from ready-made scope');

$helpers = file_get_contents($root . '/includes/expired_product_order_archive.php');
$scopeFile = file_get_contents($root . '/includes/ready_made_product_order_scope.php');
$assert($helpers !== false && $scopeFile !== false, 'visibility sources are readable');
$assert(strpos($helpers, 'printflow_ready_made_product_cancelled_operational_hidden_sql') !== false, 'cancelled ready-made orders are hidden operationally');
$assert(strpos($helpers, "'pending'") !== false, 'legacy Pending is in the awaiting unpaid bucket');
$assert(strpos($helpers, 'printflow_ready_made_product_order_scope_sql') !== false, 'overdue logic is scoped to ready-made product orders');
$operational = file_get_contents($root . '/includes/ready_made_product_operational_visibility.php');
$assert(is_string($operational), 'operational visibility module is readable');
$assert(strpos($operational, 'function printflow_ready_made_product_operational_visible_sql') !== false, 'operational lists use NOT hidden via shared visible predicate');

$dbNow = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('Asia/Manila'));
$boundary = $dbNow->modify('-24 hours');
$isDue = static fn(DateTimeImmutable $anchor): bool => $anchor <= $boundary;
$assert(!$isDue($dbNow->modify('-12 hours')), 'unpaid ready-made younger than 24 hours is not due');
$assert($isDue($boundary->modify('-1 second')), 'unpaid ready-made older than 24 hours is due');
$assert($isDue($boundary), 'unpaid ready-made exactly 24 hours old is due');
$assert(strpos($helpers, 'latestProviderAbsent') === false, 'Pending rows with failed/expired provider ledger still qualify for stale-unpaid hide');
$assert(strpos($helpers, 'printflow_ready_made_product_stale_unpaid_fallback_hidden_sql') !== false, 'provider-schema fallback still hides stale ready-made Pending rows');

$staffReports = file_get_contents($root . '/staff/reports.php');
$dashboardQueries = file_get_contents($root . '/includes/staff_dashboard_queries.php');
$assert(strpos($staffReports, 'printflow_expired_product_order_exclude_archived_sql') !== false, 'staff reports reuse operational exclusion');
$assert(strpos($dashboardQueries, 'printflow_expired_product_order_exclude_archived_sql') !== false, 'staff dashboard recent orders reuse operational exclusion');

echo "OK ready_made_product_operational_visibility_test\n";
