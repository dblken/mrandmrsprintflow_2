<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_dashboard_cache.php';
require_once __DIR__ . '/admin_reports_cache_generation.php';

/** Cache only derived report aggregates, after the caller resolves permissions.
 * Uses the existing branch/All Branches invalidation namespace, never HTML or
 * user/session data. Profit and expenses remain fresh, not cached here.
 */
function pf_admin_reports_cached(string $section, $branchId, array $filters, callable $compute): array
{
    $started = microtime(true);
    printflow_staff_dashboard_cache_dir();
    $generation = pf_reports_read_generation();
    $key = printflow_admin_dashboard_cache_key($branchId, '', '', 'reports-v1-' . $section . '-' . hash('sha256', json_encode($filters)));
    $cached = $generation !== '' ? printflow_admin_dashboard_cache_get($key) : null;
    if (is_array($cached) && ($cached['_report_generation'] ?? null) === $generation && isset($cached['report_data']) && is_array($cached['report_data'])) {
        $GLOBALS['pf_reports_timings'][$section] = ['cache' => 'hit', 'ms' => round((microtime(true) - $started) * 1000, 1)];
        return $cached['report_data'];
    }
    $errorsBefore = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
    $data = $compute();
    $errorsAfter = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
    if ($errorsAfter === $errorsBefore && $generation !== '' && hash_equals($generation, pf_reports_read_generation())) {
        printflow_admin_dashboard_cache_set($key, ['_branch_id' => $branchId, '_report_generation' => $generation, 'report_data' => $data]);
    }
    $GLOBALS['pf_reports_timings'][$section] = ['cache' => $errorsAfter === $errorsBefore ? 'miss' : 'error-uncached', 'ms' => round((microtime(true) - $started) * 1000, 1)];
    return $data;
}
