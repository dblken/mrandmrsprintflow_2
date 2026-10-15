<?php

declare(strict_types=1);

require_once __DIR__ . '/staff_dashboard_revenue.php';
require_once __DIR__ . '/staff_status_filters.php';

if (!function_exists('printflow_staff_list_page_size')) {
    function printflow_staff_list_page_size(): int
    {
        return 10;
    }
}

if (!function_exists('printflow_staff_product_order_visible_sql')) {
    /** POS/online product rows: completed and paid only. */
    function printflow_staff_product_order_visible_sql(string $alias = 'o'): string
    {
        $alias = trim($alias) !== '' ? trim($alias) : 'o';

        return "(
            LOWER(TRIM(COALESCE({$alias}.order_type, 'product'))) = 'product'
            AND LOWER(TRIM(COALESCE({$alias}.status, ''))) = 'completed'
            AND LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) IN ('paid', 'fully paid')
            AND LOWER(TRIM(COALESCE({$alias}.order_source, ''))) NOT IN ('pos_draft', 'pos_merged')
        )";
    }
}

if (!function_exists('printflow_staff_recent_orders_visibility_sql')) {
    function printflow_staff_recent_orders_visibility_sql(string $alias = 'o', ?string $role = null): string
    {
        $alias = trim($alias) !== '' ? trim($alias) : 'o';
        $roleKey = printflow_staff_status_role($role);
        $productSql = printflow_staff_product_order_visible_sql($alias);

        if ($roleKey === 'pos') {
            $customSql = "(
                LOWER(TRIM(COALESCE({$alias}.order_type, ''))) = 'custom'
                AND LOWER(TRIM(COALESCE({$alias}.status, ''))) NOT IN ('cancelled', 'rejected', 'draft')
                AND LOWER(TRIM(COALESCE({$alias}.order_source, ''))) NOT IN ('pos_draft', 'pos_merged')
                AND LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) IN ('paid', 'fully paid')
            )";
        } else {
            $customSql = "(
                LOWER(TRIM(COALESCE({$alias}.order_type, ''))) = 'custom'
                AND LOWER(TRIM(COALESCE({$alias}.status, ''))) NOT IN ('cancelled', 'rejected', 'draft')
                AND LOWER(TRIM(COALESCE({$alias}.order_source, ''))) NOT IN ('pos_draft', 'pos_merged')
            )";
        }

        return "({$productSql} OR {$customSql})";
    }
}

if (!function_exists('printflow_staff_dashboard_ajax_pagination_html')) {
    function printflow_staff_dashboard_ajax_pagination_html(int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '';
        }
        $currentPage = max(1, min($currentPage, $totalPages));
        $window = 2;
        $pages = [1];
        $rangeStart = max(2, $currentPage - $window);
        $rangeEnd = min($totalPages - 1, $currentPage + $window);
        for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
            $pages[] = $i;
        }
        if ($totalPages > 1) {
            $pages[] = $totalPages;
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $html = '<div class="pagination-container" style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding:16px 0;border-top:1px solid #f1f5f9;width:100%;">';
        $baseBtn = 'all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:13px;font-weight:500;cursor:pointer;';
        $activeBtn = 'all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:8px;border:1px solid var(--staff-pagination-active-border,#06A1A1);background:var(--staff-pagination-active-bg,#06A1A1);color:#fff;font-size:13px;font-weight:600;cursor:pointer;';

        if ($currentPage > 1) {
            $prev = $currentPage - 1;
            $html .= '<button type="button" class="pagination-link pagination-prev" onclick="refreshDashboard(' . $prev . ')" style="' . $baseBtn . '">&lsaquo;</button>';
        }
        $prevPage = null;
        foreach ($pages as $page) {
            if ($prevPage !== null && $page - $prevPage > 1) {
                $html .= '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;font-size:13px;color:#9ca3af;">···</span>';
            }
            if ((int)$page === $currentPage) {
                $html .= '<button type="button" class="pagination-link is-active" aria-current="page" style="' . $activeBtn . '">' . (int)$page . '</button>';
            } else {
                $html .= '<button type="button" class="pagination-link" onclick="refreshDashboard(' . (int)$page . ')" style="' . $baseBtn . '">' . (int)$page . '</button>';
            }
            $prevPage = $page;
        }
        if ($currentPage < $totalPages) {
            $next = $currentPage + 1;
            $html .= '<button type="button" class="pagination-link pagination-next" onclick="refreshDashboard(' . $next . ')" style="' . $baseBtn . '">&rsaquo;</button>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('printflow_staff_dashboard_confirmed_sale_sql')) {
    /**
     * Completed or confirmed-paid store orders (excludes unpaid / pending payment / shells).
     */
    function printflow_staff_dashboard_confirmed_sale_sql(string $alias = 'o'): string
    {
        $alias = trim($alias) !== '' ? trim($alias) : 'o';

        return "LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) IN ('paid', 'fully paid')
            AND LOWER(TRIM(COALESCE({$alias}.status, ''))) NOT IN ('cancelled', 'rejected', 'draft')
            AND LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) NOT IN (
                'unpaid', 'awaiting payment', 'failed', 'expired', 'cancelled', 'refunded'
            )
            AND LOWER(TRIM(COALESCE({$alias}.order_source, ''))) NOT IN ('pos_draft', 'pos_merged')";
    }
}

if (!function_exists('printflow_staff_dashboard_normalize_top_sale_name')) {
    function printflow_staff_dashboard_normalize_top_sale_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'General';
        }
        $replacements = [' Printing', ' (Print/Cut)', ' Print'];
        foreach ($replacements as $suffix) {
            $name = str_replace($suffix, '', $name);
        }

        return trim($name) !== '' ? trim($name) : 'General';
    }
}

if (!function_exists('printflow_staff_dashboard_top_sale_label_from_row')) {
    /**
     * @param array<string, mixed> $row
     */
    function printflow_staff_dashboard_top_sale_label_from_row(array $row): string
    {
        foreach (['service_type', 'service_name', 'product_name'] as $key) {
            $candidate = trim((string)($row[$key] ?? ''));
            if ($candidate !== '') {
                return printflow_staff_dashboard_normalize_top_sale_name($candidate);
            }
        }

        $custom = (string)($row['customization_data'] ?? '');
        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded)) {
                foreach (['service_type', 'product_name', 'name'] as $key) {
                    $candidate = trim((string)($decoded[$key] ?? ''));
                    if ($candidate !== '') {
                        return printflow_staff_dashboard_normalize_top_sale_name($candidate);
                    }
                }
            }
        }

        return 'General';
    }
}

if (!function_exists('printflow_staff_dashboard_top_sales')) {
    /**
     * @param array{start?:string,end?:string,sql?:string,types?:string,params?:array} $timeMeta
     * @param array{sql:string,types:string,params:array} $statusMeta
     * @return list<array{name:string,order_count:int,revenue:float}>
     */
    function printflow_staff_dashboard_top_sales(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta,
        array $statusMeta,
        int $limit = 5
    ): array {
        $limit = max(1, min(20, $limit));
        $timeBounds = printflow_staff_dashboard_time_bounds_meta($timeMeta);
        $confirmedSql = printflow_staff_dashboard_confirmed_sale_sql('o');
        $hasOrderItemType = function_exists('db_table_has_column') && db_table_has_column('order_items', 'item_type');
        $hasOrderItemServiceId = function_exists('db_table_has_column') && db_table_has_column('order_items', 'service_id');
        $productJoin = $hasOrderItemType
            ? 'LEFT JOIN products p ON oi.product_id = p.product_id AND COALESCE(NULLIF(oi.item_type, \'\'), \'product\') = \'product\''
            : 'LEFT JOIN products p ON oi.product_id = p.product_id';
        $serviceJoin = $hasOrderItemServiceId
            ? 'LEFT JOIN services s ON oi.service_id = s.service_id'
            : 'LEFT JOIN services s ON oi.product_id = s.service_id';

        $rows = db_query(
            "SELECT o.order_id,
                    oi.order_item_id,
                    oi.customization_data,
                    jo.service_type,
                    s.name AS service_name,
                    p.name AS product_name,
                    COALESCE(oi.quantity, 1) AS quantity,
                    COALESCE(oi.unit_price, 0) AS unit_price
             FROM orders o
             INNER JOIN order_items oi ON oi.order_id = o.order_id
             LEFT JOIN job_orders jo ON jo.order_item_id = oi.order_item_id AND jo.order_id = o.order_id
             {$productJoin}
             {$serviceJoin}
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND {$timeBounds['sql']}
               AND {$statusMeta['sql']}
               AND {$confirmedSql}",
            'i' . $timeBounds['types'] . $statusMeta['types'],
            array_merge([$branchId], $timeBounds['params'], $statusMeta['params'])
        ) ?: [];

        /** @var array<string, array{order_ids:array<int, true>, revenue:float}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $label = printflow_staff_dashboard_top_sale_label_from_row($row);
            if (!isset($buckets[$label])) {
                $buckets[$label] = ['order_ids' => [], 'revenue' => 0.0];
            }
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId > 0) {
                $buckets[$label]['order_ids'][$orderId] = true;
            }
            $qty = max(0, (int)($row['quantity'] ?? 1));
            $unit = (float)($row['unit_price'] ?? 0);
            $buckets[$label]['revenue'] += $qty * $unit;
        }

        $result = [];
        foreach ($buckets as $name => $bucket) {
            $result[] = [
                'name' => $name,
                'order_count' => count($bucket['order_ids']),
                'revenue' => round((float)$bucket['revenue'], 2),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $countCmp = ($b['order_count'] <=> $a['order_count']);
            if ($countCmp !== 0) {
                return $countCmp;
            }

            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return array_slice($result, 0, $limit);
    }
}

if (!function_exists('printflow_staff_dashboard_ensure_indexes')) {
    function printflow_staff_dashboard_ensure_indexes(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $statements = [
            'CREATE INDEX idx_orders_branch_order_date ON orders (branch_id, order_date)',
            'CREATE INDEX idx_orders_branch_status ON orders (branch_id, status)',
            'CREATE INDEX idx_order_items_order_id ON order_items (order_id)',
            'CREATE INDEX idx_job_orders_order_item ON job_orders (order_item_id, order_id)',
        ];
        foreach ($statements as $sql) {
            try {
                db_execute($sql);
            } catch (Throwable $e) {
                // Index may already exist under another name; ignore duplicate errors.
            }
        }
    }
}
