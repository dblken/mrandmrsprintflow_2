<?php
/**
 * Record-specific order archive helpers.
 * The archive mapping is intentionally separate from transactional records.
 */

if (!function_exists('printflow_order_archive_manifest')) {
    /** Fixed, previously previewed order IDs. No date predicate is used. */
    function printflow_order_archive_manifest(): array
    {
        static $ids = null;
        if ($ids !== null) return $ids;

        $ranges = [
            [11332, 11493], [11495, 11496], [11503, 11506],
            [11511, 11512], [11515, 11516], [11519, 11527],
            [12094, 12136],
        ];
        $ids = [];
        foreach ($ranges as [$from, $to]) {
            for ($id = $from; $id <= $to; $id++) $ids[] = $id;
        }
        return $ids;
    }
}

if (!function_exists('printflow_order_archive_tables_ready')) {
    function printflow_order_archive_tables_ready(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        $rows = db_query(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            's',
            ['printflow_order_archive_map']
        );
        $events = db_query(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            's',
            ['printflow_order_archive_events']
        );
        return $ready = (int)($rows[0]['n'] ?? 0) === 1 && (int)($events[0]['n'] ?? 0) === 1;
    }
}

if (!function_exists('printflow_order_archive_exclusion_sql')) {
    /** SQL predicate excluding an archived parent order; alias must be trusted code. */
    function printflow_order_archive_exclusion_sql(string $orderIdExpression): string
    {
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $orderIdExpression)) {
            throw new InvalidArgumentException('Invalid order ID expression.');
        }
        if (!printflow_order_archive_tables_ready()) return '1=1';
        return "NOT EXISTS (SELECT 1 FROM printflow_order_archive_map pf_oam
            WHERE pf_oam.order_id = {$orderIdExpression} AND pf_oam.status = 'archived')";
    }
}

if (!function_exists('printflow_order_archive_scope_sql')) {
    function printflow_order_archive_scope_sql(string $ordersAlias = 'o'): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $ordersAlias)) {
            throw new InvalidArgumentException('Invalid orders alias.');
        }
        return printflow_order_archive_exclusion_sql($ordersAlias . '.order_id');
    }
}

if (!function_exists('printflow_order_archive_notification_exclusion_sql')) {
    function printflow_order_archive_notification_exclusion_sql(string $alias = 'n'): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid notification alias.');
        }
        if (!printflow_order_archive_tables_ready()) return '1=1';
        return "(COALESCE({$alias}.type, '') <> 'Order'
            OR CAST(COALESCE({$alias}.data_id, '0') AS UNSIGNED) = 0
            OR NOT EXISTS (SELECT 1 FROM printflow_order_archive_map pf_oam_n
                WHERE pf_oam_n.order_id = CAST({$alias}.data_id AS UNSIGNED)
                  AND pf_oam_n.status = 'archived'))";
    }
}

if (!function_exists('printflow_order_archive_prepare')) {
    /** Bind a fixed order manifest using mysqli; returns [placeholders, types, ids]. */
    function printflow_order_archive_prepare(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if ($ids === []) throw new InvalidArgumentException('At least one order ID is required.');
        return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids)), $ids];
    }
}
