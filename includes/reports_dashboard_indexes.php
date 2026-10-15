<?php

declare(strict_types=1);

/**
 * Idempotent read-only index ensures for dashboard/report date filters.
 * Adds indexes only when missing; never drops or alters existing indexes.
 */

if (!function_exists('printflow_reports_dashboard_index_exists')) {
    function printflow_reports_dashboard_index_exists(string $table, string $indexName): bool
    {
        if (!function_exists('db_query')) {
            return false;
        }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $indexName = preg_replace('/[^a-zA-Z0-9_]/', '', $indexName);
        if ($table === '' || $indexName === '') {
            return false;
        }
        $rows = db_query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", 's', [$indexName]) ?: [];
        return $rows !== [];
    }
}

if (!function_exists('printflow_ensure_reports_dashboard_indexes')) {
    function printflow_ensure_reports_dashboard_indexes(): void
    {
        static $completed = false;
        if ($completed) {
            return;
        }
        $completed = true;

        global $conn;
        if (!($conn instanceof mysqli) || !function_exists('db_query')) {
            return;
        }

        $definitions = [
            ['orders', 'idx_orders_branch_order_date', 'ALTER TABLE `orders` ADD INDEX `idx_orders_branch_order_date` (`branch_id`, `order_date`)'],
            ['orders', 'idx_orders_order_date', 'ALTER TABLE `orders` ADD INDEX `idx_orders_order_date` (`order_date`)'],
            ['job_orders', 'idx_job_orders_branch_created', 'ALTER TABLE `job_orders` ADD INDEX `idx_job_orders_branch_created` (`branch_id`, `created_at`)'],
        ];

        foreach ($definitions as [$table, $indexName, $alterSql]) {
            if (printflow_reports_dashboard_index_exists($table, $indexName)) {
                continue;
            }
            $tableRows = db_query("SHOW TABLES LIKE '{$table}'") ?: [];
            if ($tableRows === []) {
                continue;
            }
            if (@$conn->query($alterSql)) {
                error_log('[reports_dashboard_indexes] added ' . $indexName);
                continue;
            }
            $message = (string)$conn->error;
            if (function_exists('printflow_db_record_error')) {
                printflow_db_record_error([
                    'stage' => 'schema_index',
                    'error' => $message,
                    'errno' => $conn->errno,
                    'sql' => $alterSql,
                ]);
            }
            error_log('[reports_dashboard_indexes] skipped ' . $indexName . ': ' . $message);
        }
    }
}
