<?php

declare(strict_types=1);

if (!function_exists('printflow_staff_dashboard_cache_dir')) {
    function printflow_staff_dashboard_cache_dir(): string
    {
        $dir = dirname(__DIR__) . '/tmp/dashboard_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('printflow_staff_dashboard_cache_key')) {
    function printflow_staff_dashboard_cache_key(
        int $branchId,
        string $role,
        string $section,
        string $timeframe,
        string $statusFilter,
        int $page = 1,
        string $businessDate = ''
    ): string {
        $payload = implode('|', [
            (string)$branchId,
            strtolower(trim($role)),
            strtolower(trim($section)),
            strtolower(trim($timeframe)),
            strtoupper(trim($statusFilter)),
            (string)max(1, $page),
            trim($businessDate),
            'pending_kpi_customization_counts_20261016',
            'top_sales_order_labels_20261016_v3',
        ]);

        return 'staff_dash_' . hash('sha256', $payload);
    }
}

if (!function_exists('printflow_staff_dashboard_cache_get')) {
    /**
     * @return array<string, mixed>|null
     */
    function printflow_staff_dashboard_cache_get(string $key, int $ttlSeconds = 90): ?array
    {
        if ($ttlSeconds <= 0) {
            return null;
        }
        $path = printflow_staff_dashboard_cache_dir() . '/' . $key . '.json';
        if (!is_file($path)) {
            return null;
        }
        $mtime = (int)filemtime($path);
        if ($mtime <= 0 || (time() - $mtime) > $ttlSeconds) {
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

if (!function_exists('printflow_staff_dashboard_cache_set')) {
    /**
     * @param array<string, mixed> $payload
     */
    function printflow_staff_dashboard_cache_set(string $key, array $payload): void
    {
        $path = printflow_staff_dashboard_cache_dir() . '/' . $key . '.json';
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        @file_put_contents($path, json_encode($payload, $flags), LOCK_EX);
    }
}

if (!function_exists('printflow_staff_dashboard_cache_flush_admin_summary')) {
    /**
     * The admin dashboard "All Branches" summary combines every branch, so any
     * branch-level change expires All Branches and the affected branch. Legacy
     * entries without branch metadata are expired conservatively.
     */
    function printflow_staff_dashboard_cache_flush_admin_summary(?int $branchId = null): void
    {
        foreach (glob(printflow_staff_dashboard_cache_dir() . '/admin_dash_*.json') ?: [] as $file) {
            if ($branchId !== null && $branchId > 0) {
                $raw = @file_get_contents($file);
                $entry = $raw === false ? null : json_decode($raw, true);
                // Old/invalid entries have no proven branch ownership: expire them.
                // All Branches always depends on the changed branch.
                $owner = is_array($entry) ? ($entry['_branch_id'] ?? null) : null;
                if (is_numeric($owner) && (int)$owner > 0 && (int)$owner !== $branchId) {
                    continue;
                }
            }
            @unlink($file);
        }
    }
}

if (!function_exists('printflow_staff_dashboard_cache_invalidate_branch')) {
    function printflow_staff_dashboard_cache_invalidate_branch(int $branchId): void
    {
        printflow_staff_dashboard_cache_flush_admin_summary($branchId);
        if ($branchId <= 0) {
            printflow_staff_dashboard_cache_flush_all();
            return;
        }
        $dir = printflow_staff_dashboard_cache_dir();
        $needle = 'staff_dash_';
        foreach (glob($dir . '/' . $needle . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && (int)($decoded['_branch_id'] ?? 0) === $branchId) {
                @unlink($file);
            }
        }
    }
}

if (!function_exists('printflow_staff_dashboard_cache_flush_all')) {
    function printflow_staff_dashboard_cache_flush_all(): void
    {
        foreach (glob(printflow_staff_dashboard_cache_dir() . '/staff_dash_*.json') ?: [] as $file) {
            @unlink($file);
        }
        printflow_staff_dashboard_cache_flush_admin_summary();
    }
}
