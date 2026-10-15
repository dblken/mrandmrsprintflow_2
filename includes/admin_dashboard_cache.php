<?php

declare(strict_types=1);

/**
 * Short-lived file cache for the computed Admin Dashboard summary data.
 *
 * Only derived, read-only summary arrays are cached (KPI totals, chart rows,
 * recent orders, low-stock lists). Nothing is written back to the database.
 * Entries expire after a short TTL and are flushed by the same hooks that
 * invalidate the staff dashboard cache (POS checkout, order status changes).
 *
 * Disable with PRINTFLOW_ADMIN_DASHBOARD_CACHE_TTL=0 or bypass a single
 * request with ?refresh=1 / ?nocache=1.
 */

require_once __DIR__ . '/staff_dashboard_cache.php';

if (!function_exists('printflow_admin_dashboard_cache_ttl')) {
    function printflow_admin_dashboard_cache_ttl(): int
    {
        $raw = getenv('PRINTFLOW_ADMIN_DASHBOARD_CACHE_TTL');
        if ($raw === false || trim((string)$raw) === '') {
            return 60;
        }
        return max(0, (int)$raw);
    }
}

if (!function_exists('printflow_admin_dashboard_cache_bypass_requested')) {
    function printflow_admin_dashboard_cache_bypass_requested(): bool
    {
        foreach (['refresh', 'nocache'] as $flag) {
            $value = strtolower(trim((string)($_GET[$flag] ?? '')));
            if (in_array($value, ['1', 'true', 'yes'], true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('printflow_admin_dashboard_cache_key')) {
    /**
     * @param int|string $branchId 'all' or a branch id
     */
    function printflow_admin_dashboard_cache_key($branchId, string $fromDate, string $toDate, string $version = 'v1'): string
    {
        $payload = implode('|', [
            'admin',
            $branchId === 'all' ? 'all' : (string)(int)$branchId,
            $fromDate,
            $toDate,
            $version,
        ]);
        return 'admin_dash_' . hash('sha256', $payload);
    }
}

if (!function_exists('printflow_admin_dashboard_cache_get')) {
    /**
     * @return array<string, mixed>|null
     */
    function printflow_admin_dashboard_cache_get(string $key, ?int $ttlSeconds = null): ?array
    {
        $ttl = $ttlSeconds ?? printflow_admin_dashboard_cache_ttl();
        if ($ttl <= 0 || printflow_admin_dashboard_cache_bypass_requested()) {
            return null;
        }
        $path = printflow_staff_dashboard_cache_dir() . '/' . $key . '.json';
        if (!is_file($path)) {
            return null;
        }
        $mtime = (int)@filemtime($path);
        if ($mtime <= 0 || (time() - $mtime) > $ttl) {
            @unlink($path);
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('printflow_admin_dashboard_cache_set')) {
    /**
     * @param array<string, mixed> $payload
     */
    function printflow_admin_dashboard_cache_set(string $key, array $payload): void
    {
        if (printflow_admin_dashboard_cache_ttl() <= 0) {
            return;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = json_encode($payload, $flags);
        if ($encoded === false) {
            return;
        }
        $path = printflow_staff_dashboard_cache_dir() . '/' . $key . '.json';
        @file_put_contents($path, $encoded, LOCK_EX);
    }
}

if (!function_exists('printflow_admin_dashboard_cache_flush_all')) {
    function printflow_admin_dashboard_cache_flush_all(): void
    {
        foreach (glob(printflow_staff_dashboard_cache_dir() . '/admin_dash_*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
