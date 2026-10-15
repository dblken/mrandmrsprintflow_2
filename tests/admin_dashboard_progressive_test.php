<?php
// Executes the dashboard computation block with an isolated cache and query spies.
declare(strict_types=1);
$testCacheDir = sys_get_temp_dir() . '/pf_admin_progressive_' . bin2hex(random_bytes(6));
mkdir($testCacheDir);
function printflow_staff_dashboard_cache_dir(): string { return $GLOBALS['testCacheDir']; }
require_once dirname(__DIR__) . '/includes/admin_dashboard_cache.php';
function printflow_order_archive_exclusion_sql($v) { return '1=1'; }
function printflow_customer_exclusion_customer_scope_sql($v) { return '1=1'; }
function printflow_pos_sql_exclude_placeholder_customers($v) { return ''; }
function branch_customers_belong_where_sql($id, $alias) { return ['', '', []]; }
function db_query($sql, $types = null, $params = null) {
    $GLOBALS['queries'][] = $sql;
    return [['cnt' => 7]];
}
function pf_reports_official_sales_breakdown(...$args) {
    $GLOBALS['queries'][] = 'official revenue';
    return ['summary' => ['total_sales' => 123.45]];
}
function expect_progressive($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$source = file_get_contents(dirname(__DIR__) . '/admin/dashboard.php');
$start = strpos($source, '$dashCacheVars = [');
$end = strpos($source, '// Expose phase timings', $start);
$computation = substr($source, $start, $end - $start);
$dashFromDate = $dashToDate = '2026-10-15';
$dashFromStart = $kpiDateFrom = '2026-10-15 00:00:00';
$dashToEnd = $kpiDateToEnd = '2026-10-15 23:59:59';
$_GET = [];
try {
    foreach ([1, 'all'] as $branchId) {
        $queries = [];
        eval($computation);
        expect_progressive(count($queries) === 4, 'Cold summary executes only its four KPI operations.');
        expect_progressive(!isset($low_stock) && !isset($category_sales), 'Secondary data is not fetched on initial load.');
        expect_progressive($total_revenue === 123.45 && $total_orders === 7, 'KPI results are preserved.');
        $queries = [];
        eval($computation);
        expect_progressive($queries === [], 'Warm summary reuses cache without querying.');
        $secondaryKey = printflow_admin_dashboard_cache_key($branchId, $dashFromDate, $dashToDate, 'v2-secondary');
        $summaryKey = printflow_admin_dashboard_cache_key($branchId, $dashFromDate, $dashToDate, 'v2-summary');
        expect_progressive($secondaryKey !== $summaryKey, 'Summary and secondary entries are distinct.');
        printflow_admin_dashboard_cache_set($secondaryKey, ['test' => true, '_branch_id' => $branchId]);
        $otherBranchKey = printflow_admin_dashboard_cache_key(2, $dashFromDate, $dashToDate, 'v2-summary');
        printflow_admin_dashboard_cache_set($otherBranchKey, ['_branch_id' => 2]);
        printflow_staff_dashboard_cache_invalidate_branch(1);
        expect_progressive(printflow_admin_dashboard_cache_get($summaryKey) === null && printflow_admin_dashboard_cache_get($secondaryKey) === null, 'Existing mutation hook invalidates both new cache sections.');
        expect_progressive(printflow_admin_dashboard_cache_get($otherBranchKey) !== null, 'Updating branch 1 preserves branch 2 summary.');
        printflow_staff_dashboard_cache_flush_all();
        expect_progressive(printflow_admin_dashboard_cache_get($otherBranchKey) === null, 'Global invalidation still clears every branch.');
    }
    echo "Admin dashboard progressive computation tests passed (isolated cache, stubbed DB).\n";
} finally {
    foreach (glob($testCacheDir . '/*.json') ?: [] as $testFile) unlink($testFile);
    rmdir($testCacheDir);
}
