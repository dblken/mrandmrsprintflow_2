<?php
declare(strict_types=1);
$testDir = sys_get_temp_dir() . '/pf_reports_' . bin2hex(random_bytes(5));
mkdir($testDir);
function printflow_staff_dashboard_cache_dir(): string { return $GLOBALS['testDir']; }
function pf_reports_generation_path(): string { return $GLOBALS['testDir'] . '/generation.token'; }
function printflow_db_errors(): array { return $GLOBALS['errors'] ?? []; }
require_once dirname(__DIR__) . '/includes/admin_reports_cache.php';
function checkReport($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$calls = 0;
$compute = static function () use (&$calls): array { $calls++; return ['total_sales'=>123.45, 'transaction_count'=>2]; };
try {
    $filters = ['2026-10-01', '2026-10-14 23:59:59'];
    $first = pf_admin_reports_cached('sales', 1, $filters, $compute);
    checkReport(pf_admin_reports_cached('sales', 1, $filters, $compute) === $first && $calls === 1, 'Same branch/date must reuse exact values');
    pf_admin_reports_cached('sales', 2, $filters, $compute);
    pf_admin_reports_cached('sales', 'all', $filters, $compute);
    pf_admin_reports_cached('sales', 1, ['', ''], $compute);
    checkReport($calls === 4, 'Branches and All Time must be isolated');
    printflow_staff_dashboard_cache_invalidate_branch(1);
    pf_admin_reports_cached('sales', 2, $filters, $compute);
    checkReport($calls === 4, 'Unrelated branch retained');
    pf_admin_reports_cached('sales', 'all', $filters, $compute);
    checkReport($calls === 5, 'Branch mutation expires All Branches');
    pf_reports_bump_generation();
    pf_admin_reports_cached('sales', 2, $filters, $compute);
    checkReport($calls === 6, 'Mutation generation expires report data');
    $raced = static function () use (&$calls): array { $calls++; pf_reports_bump_generation(); return ['total_sales'=>10]; };
    pf_admin_reports_cached('race', 1, $filters, $raced);
    pf_admin_reports_cached('race', 1, $filters, $compute);
    checkReport($calls === 8, 'Concurrent mutation must not store an old computation');
    $failed = static function () use (&$calls): array { $calls++; $GLOBALS['errors'][] = ['errno'=>1205]; return []; };
    pf_admin_reports_cached('failed', 1, $filters, $failed);
    pf_admin_reports_cached('failed', 1, $filters, $compute);
    checkReport($calls === 10, 'Database errors must not be cached as empty reports');
    $_GET['nocache'] = '1';
    pf_admin_reports_cached('sales', 2, $filters, $compute);
    checkReport($calls === 11, 'Explicit refresh bypasses cache');
    unset($_GET['nocache']);
    $path = printflow_admin_dashboard_cache_key(2, '', '', 'reports-v1-sales-' . hash('sha256', json_encode($filters)));
    touch($testDir . '/' . $path . '.json', time() - 120);
    pf_admin_reports_cached('sales', 2, $filters, $compute);
    checkReport($calls === 12, 'Expired entries are recomputed');
    echo "Reports cache reuse, filters, branch invalidation, mutation race, errors and expiry passed (isolated cache).\n";
} finally {
    foreach (glob($testDir . '/*') ?: [] as $file) unlink($file);
    rmdir($testDir);
}
