<?php
/** Reports-only derived-cache generation. No database or authentication changes.
 * Mutation requests (including payment webhooks) invalidate at start and at
 * shutdown, after their transaction has completed. Conservatively includes
 * failed/non-mutating POSTs rather than risk keeping a stale sales aggregate.
 */
if (!function_exists('pf_reports_generation_path')) {
    function pf_reports_generation_path(): string
    {
        return dirname(__DIR__) . '/tmp/dashboard_cache/reports-generation.token';
    }
}

function pf_reports_bump_generation(): void
{
    $path = pf_reports_generation_path();
    if (!is_file($path)) return; // Reports caching has not been used yet.
    try {
        if (@file_put_contents($path, bin2hex(random_bytes(16)), LOCK_EX) === false) @unlink($path);
    } catch (Throwable $e) {
        @unlink($path);
    }
}

// GET/HEAD report reads never invalidate their own cached aggregates.
if (PHP_SAPI === 'cli' || !in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    pf_reports_bump_generation();
    register_shutdown_function('pf_reports_bump_generation');
}

function pf_reports_read_generation(): string
{
    $path = pf_reports_generation_path();
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle) {
            try {
                fwrite($handle, bin2hex(random_bytes(16)));
            } catch (Throwable $e) {
                return ''; // Cache is optional; never fail a report read.
            } finally {
                fclose($handle);
            }
        }
    }
    $value = @file_get_contents($path);
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) ? $value : '';
}
