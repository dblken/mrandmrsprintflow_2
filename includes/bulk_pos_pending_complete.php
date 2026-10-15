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

if (!function_exists('printflow_bulk_pos_pending_sql_fragment')) {
    function printflow_bulk_pos_pending_sql_fragment(): string
    {
        return "(
            LOWER(TRIM(COALESCE(o.order_source, ''))) IN ('pos', 'walk-in')
            OR LOWER(TRIM(COALESCE(c.email, ''))) = 'walkin@pos.local'
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
        $startSql = $start->format('Y-m-d H:i:s');
        $endSql = $end->format('Y-m-d H:i:s');
        $posFragment = printflow_bulk_pos_pending_sql_fragment();
        $archiveSql = printflow_order_archive_scope_sql('o');

        $sql = "SELECT
                    o.order_id,
                    o.status,
                    o.payment_status,
                    o.total_amount,
                    o.order_date,
                    o.created_at,
                    COALESCE(o.order_date, o.created_at) AS order_sort_at
                FROM orders o
                LEFT JOIN customers c ON c.customer_id = o.customer_id
                WHERE {$archiveSql}
                  AND COALESCE(o.order_source, '') NOT IN ('pos_merged', 'pos_draft')
                  AND (o.order_type IS NULL OR o.order_type IN ('product', 'custom'))
                  AND {$posFragment}
                  AND o.status NOT IN ('Completed', 'Cancelled', 'Rejected')
                  AND COALESCE(o.order_date, o.created_at) >= ?
                  AND COALESCE(o.order_date, o.created_at) <= ?";
        $types = 'ss';
        $params = [$startSql, $endSql];
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND o.branch_id = ?';
            $types .= 'i';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY order_sort_at ASC, o.order_id ASC';

        $rows = db_query($sql, $types, $params) ?: [];
        $eligible = [];
        foreach ($rows as $row) {
            if (!printflow_bulk_pos_is_pending_bucket((string)($row['status'] ?? ''))) {
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
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'order_date' => (string)($row['order_sort_at'] ?? ''),
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
