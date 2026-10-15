<?php

declare(strict_types=1);

require_once __DIR__ . '/order_status_mutation.php';

if (!function_exists('printflow_bulk_complete_ensure_schema')) {
    function printflow_bulk_complete_ensure_schema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;
        db_execute(
            'CREATE TABLE IF NOT EXISTS staff_bulk_complete_runs (
                idempotency_key VARCHAR(64) NOT NULL PRIMARY KEY,
                staff_user_id INT NOT NULL,
                action VARCHAR(32) NOT NULL,
                response_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_staff_bulk_complete_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

if (!function_exists('printflow_bulk_parse_staff_datetime')) {
    function printflow_bulk_parse_staff_datetime(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            $normalized .= ':00';
        }
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
        try {
            return new DateTimeImmutable($normalized, $tz);
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('printflow_bulk_pos_is_pending_bucket')) {
    function printflow_bulk_pos_is_pending_bucket(string $status): bool
    {
        $key = printflow_workflow_status_key($status);
        return !in_array($key, ['COMPLETED', 'CANCELLED', 'REJECTED'], true);
    }
}

if (!function_exists('printflow_bulk_pos_row_pending_bucket')) {
    /**
     * Match staff POS tab: pending unless every known status is terminal.
     */
    function printflow_bulk_pos_row_pending_bucket(?string $storeStatus, ?string $jobStatus): bool
    {
        $keys = [];
        foreach ([$storeStatus, $jobStatus] as $status) {
            if ($status === null || trim($status) === '') {
                continue;
            }
            $keys[] = printflow_workflow_status_key($status);
        }
        if ($keys === []) {
            return false;
        }
        foreach ($keys as $key) {
            if (!in_array($key, ['COMPLETED', 'CANCELLED', 'REJECTED'], true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('printflow_bulk_pos_order_branch_id_sql')) {
    function printflow_bulk_pos_order_branch_id_sql(string $orderAlias = 'o'): string
    {
        return "COALESCE(
            (SELECT jo.branch_id FROM job_orders jo WHERE jo.order_id = {$orderAlias}.order_id ORDER BY jo.id ASC LIMIT 1),
            {$orderAlias}.branch_id
        )";
    }
}

if (!function_exists('printflow_bulk_pos_latest_job_status_sql')) {
    function printflow_bulk_pos_latest_job_status_sql(string $orderAlias = 'o'): string
    {
        return "(SELECT jo.status FROM job_orders jo
                 WHERE jo.order_id = {$orderAlias}.order_id
                 ORDER BY jo.updated_at DESC, jo.id DESC
                 LIMIT 1)";
    }
}

if (!function_exists('printflow_bulk_complete_inclusive_calendar_range')) {
    /**
     * Match staff list filtering: whole calendar days in the app timezone (Asia/Manila).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
     */
    function printflow_bulk_complete_inclusive_calendar_range(
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): ?array {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila');
        $startDay = $start->setTimezone($tz)->setTime(0, 0, 0);
        $endDay = $end->setTimezone($tz)->setTime(23, 59, 59);
        if ($endDay < $startDay) {
            return null;
        }

        return [$startDay, $endDay];
    }
}

if (!function_exists('printflow_bulk_pos_business_datetime_sql')) {
    /** Same business datetime as staff customizations list (order_date, then job, then created_at). */
    function printflow_bulk_pos_business_datetime_sql(string $orderAlias = 'o'): string
    {
        return "COALESCE(
            NULLIF(TRIM({$orderAlias}.order_date), ''),
            (SELECT MIN(jo.created_at) FROM job_orders jo WHERE jo.order_id = {$orderAlias}.order_id),
            {$orderAlias}.created_at
        )";
    }
}

if (!function_exists('printflow_bulk_pos_walk_in_source_sql')) {
    /** POS walk-in scope aligned with admin/job_orders_api.php list_orders (source=pos). */
    function printflow_bulk_pos_walk_in_source_sql(string $orderAlias = 'o', string $customerAlias = 'c'): string
    {
        return "(
            LOWER(TRIM(COALESCE({$orderAlias}.order_source, ''))) IN ('pos', 'walk-in')
            OR LOWER(TRIM(COALESCE({$customerAlias}.email, ''))) = 'walkin@pos.local'
            OR EXISTS (
                SELECT 1
                FROM customizations cust_pos
                WHERE cust_pos.order_id = {$orderAlias}.order_id
                  AND (
                      cust_pos.customization_details LIKE '%\"source\":\"POS\"%'
                      OR cust_pos.customization_details LIKE '%\"source\": \"POS\"%'
                      OR cust_pos.customization_details LIKE '%\"_seed_batch_id\"%'
                  )
            )
            OR EXISTS (
                SELECT 1
                FROM order_items oi_pos
                WHERE oi_pos.order_id = {$orderAlias}.order_id
                  AND (
                      oi_pos.customization_data LIKE '%\"source\":\"POS\"%'
                      OR oi_pos.customization_data LIKE '%\"source\": \"POS\"%'
                      OR oi_pos.customization_data LIKE '%\"_seed_batch_id\"%'
                  )
            )
        )";
    }
}

if (!function_exists('printflow_bulk_pos_exclude_unfinalized_draft_sql')) {
    function printflow_bulk_pos_exclude_unfinalized_draft_sql(string $orderAlias = 'o'): string
    {
        return " AND NOT (
            LOWER(TRIM(COALESCE({$orderAlias}.payment_status, ''))) = 'unpaid'
            AND LOWER(TRIM(COALESCE({$orderAlias}.status, ''))) = 'draft'
            AND (
                LOWER(TRIM(COALESCE({$orderAlias}.order_source, ''))) IN ('pos_draft', '')
                OR EXISTS (
                    SELECT 1 FROM customizations cust_draft
                    WHERE cust_draft.order_id = {$orderAlias}.order_id
                      AND (
                          cust_draft.customization_details LIKE '%\"source\":\"POS\"%'
                          OR cust_draft.customization_details LIKE '%\"source\": \"POS\"%'
                      )
                )
            )
        )";
    }
}

if (!function_exists('printflow_bulk_pos_pending_orders_in_range')) {
    /**
     * @return array{orders: list<array<string, mixed>>, total: int}
     */
    function printflow_bulk_pos_pending_orders_in_range(
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $branchId,
        int $previewLimit = 500
    ): array {
        $normalized = printflow_bulk_complete_inclusive_calendar_range(
            $start instanceof DateTimeImmutable ? $start : DateTimeImmutable::createFromInterface($start),
            $end instanceof DateTimeImmutable ? $end : DateTimeImmutable::createFromInterface($end)
        );
        if ($normalized === null) {
            return ['orders' => [], 'total' => 0, 'all_order_ids' => []];
        }
        [$start, $end] = $normalized;
        $startSql = $start->format('Y-m-d H:i:s');
        $endSql = $end->format('Y-m-d H:i:s');
        $posFragment = printflow_bulk_pos_walk_in_source_sql('o', 'c');
        $businessDateSql = printflow_bulk_pos_business_datetime_sql('o');
        $archiveSql = printflow_order_archive_scope_sql('o');
        $draftExcludeSql = printflow_bulk_pos_exclude_unfinalized_draft_sql('o');

        $branchSql = printflow_bulk_pos_order_branch_id_sql('o');
        $jobStatusSql = printflow_bulk_pos_latest_job_status_sql('o');

        $sql = "SELECT
                    o.order_id,
                    o.status,
                    o.payment_status,
                    o.total_amount,
                    o.order_date,
                    o.created_at,
                    {$jobStatusSql} AS job_status,
                    {$businessDateSql} AS order_sort_at
                FROM orders o
                LEFT JOIN customers c ON c.customer_id = o.customer_id
                WHERE {$archiveSql}
                  AND COALESCE(o.order_source, '') NOT IN ('pos_merged', 'pos_draft')
                  AND (o.order_type IS NULL OR o.order_type = 'product' OR o.order_type = 'custom')
                  AND LOWER(TRIM(COALESCE(o.status, ''))) NOT IN ('draft')
                  AND {$posFragment}
                  {$draftExcludeSql}
                  AND {$businessDateSql} >= ?
                  AND {$businessDateSql} <= ?";
        $types = 'ss';
        $params = [$startSql, $endSql];
        if ($branchId !== null && $branchId > 0) {
            $sql .= " AND {$branchSql} = ?";
            $types .= 'i';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY order_sort_at ASC, o.order_id ASC';

        $rows = db_query($sql, $types, $params) ?: [];
        $eligible = [];
        foreach ($rows as $row) {
            if (!printflow_bulk_pos_row_pending_bucket(
                (string)($row['status'] ?? ''),
                isset($row['job_status']) ? (string)$row['job_status'] : null
            )) {
                continue;
            }
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $eligible[] = [
                'order_id' => $orderId,
                'order_code' => printflow_format_order_code($orderId, ''),
                'status' => (string)($row['status'] ?? ''),
                'job_status' => (string)($row['job_status'] ?? ''),
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'order_date' => (string)($row['order_sort_at'] ?? ''),
                'order_business_date' => (string)($row['order_sort_at'] ?? ''),
            ];
        }

        $total = count($eligible);
        $preview = $previewLimit > 0 ? array_slice($eligible, 0, $previewLimit) : $eligible;

        return ['orders' => $preview, 'total' => $total, 'all_order_ids' => array_column($eligible, 'order_id')];
    }
}

if (!function_exists('printflow_bulk_complete_load_idempotent')) {
    function printflow_bulk_complete_load_idempotent(string $key, int $staffUserId): ?array
    {
        printflow_bulk_complete_ensure_schema();
        $key = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $key) ?? '', 0, 64);
        if ($key === '') {
            return null;
        }
        $rows = db_query(
            'SELECT response_json FROM staff_bulk_complete_runs
             WHERE idempotency_key = ? AND staff_user_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 1',
            'si',
            [$key, $staffUserId]
        ) ?: [];
        if ($rows === []) {
            return null;
        }
        $decoded = json_decode((string)($rows[0]['response_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('printflow_bulk_complete_store_idempotent')) {
    function printflow_bulk_complete_store_idempotent(string $key, int $staffUserId, string $action, array $payload): void
    {
        printflow_bulk_complete_ensure_schema();
        $key = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $key) ?? '', 0, 64);
        if ($key === '') {
            return;
        }
        db_execute(
            'INSERT INTO staff_bulk_complete_runs (idempotency_key, staff_user_id, action, response_json)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), created_at = NOW()',
            'siss',
            [$key, $staffUserId, $action, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }
}

if (!function_exists('printflow_bulk_parse_execute_order_ids')) {
    /**
     * @return list<int>
     */
    function printflow_bulk_parse_execute_order_ids(array $post): array
    {
        $raw = $post['order_ids'] ?? null;
        if ($raw === null && isset($post['order_ids_json'])) {
            $decoded = json_decode((string)$post['order_ids_json'], true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}

if (!function_exists('printflow_bulk_filter_execute_order_ids')) {
    /**
     * @param list<int> $orderIds
     * @return list<int>
     */
    function printflow_bulk_filter_execute_order_ids(
        array $orderIds,
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        ?int $branchId
    ): array {
        if ($orderIds === []) {
            return [];
        }
        $range = printflow_bulk_pos_pending_orders_in_range($rangeStart, $rangeEnd, $branchId, 0);
        $allowed = array_flip($range['all_order_ids'] ?? []);

        return array_values(array_filter(
            $orderIds,
            static fn(int $orderId): bool => $orderId > 0 && isset($allowed[$orderId])
        ));
    }
}

if (!function_exists('printflow_bulk_execute_pos_pending_complete')) {
    /**
     * @param list<int> $orderIds
     * @return array<string, mixed>
     */
    function printflow_bulk_execute_pos_pending_complete(array $orderIds): array
    {
        $completed = [];
        $skipped = [];
        $failed = [];

        foreach ($orderIds as $orderId) {
            $orderId = (int)$orderId;
            if ($orderId <= 0) {
                continue;
            }

            try {
                printflow_assert_order_branch_access($orderId);
            } catch (Throwable) {
                $skipped[] = [
                    'order_id' => $orderId,
                    'order_code' => printflow_format_order_code($orderId, ''),
                    'reason' => 'Not accessible in your branch.',
                ];
                continue;
            }

            $statusRows = db_query(
                'SELECT status, payment_status, total_amount FROM orders WHERE order_id = ? LIMIT 1',
                'i',
                [$orderId]
            ) ?: [];
            if ($statusRows === []) {
                $skipped[] = [
                    'order_id' => $orderId,
                    'order_code' => printflow_format_order_code($orderId, ''),
                    'reason' => 'Order not found.',
                ];
                continue;
            }

            $currentStatus = trim((string)($statusRows[0]['status'] ?? ''));
            if (!printflow_bulk_pos_is_pending_bucket($currentStatus)) {
                $skipped[] = [
                    'order_id' => $orderId,
                    'order_code' => printflow_format_order_code($orderId, ''),
                    'reason' => 'Order is no longer pending.',
                ];
                continue;
            }

            $result = printflow_staff_mutate_order_status(
                $orderId,
                'Completed',
                $currentStatus,
                ['manage_transaction' => true]
            );

            unset($result['_http_status']);

            if (!empty($result['success'])) {
                $completed[] = [
                    'order_id' => $orderId,
                    'order_code' => printflow_format_order_code($orderId, ''),
                    'changed' => !empty($result['changed']),
                    'already_completed' => !empty($result['already_completed']),
                    'message' => (string)($result['message'] ?? ''),
                ];
                continue;
            }

            $failed[] = [
                'order_id' => $orderId,
                'order_code' => printflow_format_order_code($orderId, ''),
                'error' => (string)($result['error'] ?? 'Could not complete order.'),
                'payment_status' => $result['payment_status'] ?? null,
            ];
        }

        return [
            'success' => true,
            'completed_count' => count($completed),
            'skipped_count' => count($skipped),
            'failed_count' => count($failed),
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }
}
