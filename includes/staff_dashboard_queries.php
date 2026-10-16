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
            return '';
        }
        $replacements = [' Printing', ' (Print/Cut)', ' Print'];
        foreach ($replacements as $suffix) {
            $name = str_replace($suffix, '', $name);
        }

        return trim($name);
    }
}

if (!function_exists('printflow_staff_dashboard_top_sale_label_from_order_label')) {
    function printflow_staff_dashboard_top_sale_label_from_order_label(string $label): string
    {
        $label = trim($label);
        if ($label === '' || strcasecmp($label, 'Custom Service') === 0) {
            return '';
        }

        return printflow_staff_dashboard_normalize_top_sale_name($label);
    }
}

if (!function_exists('printflow_staff_dashboard_top_sales_eligible_orders')) {
    /**
     * Orders eligible for Top Sales — same branch, date, status, and visibility as Recent Orders.
     *
     * @param array{start?:string,end?:string,sql?:string,types?:string,params?:array} $timeMeta
     * @param array{sql:string,types:string,params:array} $statusMeta
     * @return list<array{order_id:int,total_amount:float}>
     */
    function printflow_staff_dashboard_top_sales_eligible_orders(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta,
        array $statusMeta,
        ?string $staffRole = null
    ): array {
        $timeBounds = printflow_staff_dashboard_time_bounds_meta($timeMeta);
        $visibilitySql = printflow_staff_recent_orders_visibility_sql('o', $staffRole);
        $statusSql = trim((string)($statusMeta['sql'] ?? '1=1'));
        $statusTypes = (string)($statusMeta['types'] ?? '');
        $statusParams = is_array($statusMeta['params'] ?? null) ? $statusMeta['params'] : [];

        return db_query(
            "SELECT o.order_id, COALESCE(o.total_amount, 0) AS total_amount
             FROM orders o
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND {$timeBounds['sql']}
               AND {$visibilitySql}
               AND {$statusSql}",
            'i' . $timeBounds['types'] . $statusTypes,
            array_merge([$branchId], $timeBounds['params'], $statusParams)
        ) ?: [];
    }
}

if (!function_exists('printflow_staff_dashboard_top_sales')) {
    /**
     * @param array{start?:string,end?:string,sql?:string,types?:string,params?:array} $timeMeta
     * @param array{sql:string,types:string,params:array} $statusMeta
     * @return list<array{name:string,item_count:int,revenue:float}>
     */
    function printflow_staff_dashboard_top_sales(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta,
        array $statusMeta,
        int $limit = 5,
        ?string $staffRole = null,
        bool $includeEvidence = false
    ): array {
        $limit = max(1, min(20, $limit));
        // Aggregate the saved order lines directly. An order-level summary can
        // describe only its first item and must never be used to label every
        // line in a multi-item order. Do not join job_orders: it is a workflow
        // mirror and may contain multiple rows for the same order item.
        $hasItemType = function_exists('db_table_has_column') && db_table_has_column('order_items', 'item_type');
        $hasServiceId = function_exists('db_table_has_column') && db_table_has_column('order_items', 'service_id');
        if (!$hasItemType && !$hasServiceId) {
            return [];
        }
        $productPredicate = $hasItemType
            ? "LOWER(TRIM(COALESCE(oi.item_type, ''))) = 'product'"
            : "p.product_id IS NOT NULL";
        if ($hasServiceId) {
            $productPredicate .= ' AND COALESCE(oi.service_id, 0) = 0';
        }
        $servicePredicate = $hasItemType
            ? "LOWER(TRIM(COALESCE(oi.item_type, ''))) = 'service'"
            : "s.service_id IS NOT NULL";
        $productJoin = "LEFT JOIN products p ON p.product_id = oi.product_id AND {$productPredicate}";
        $serviceJoin = $hasServiceId
            ? "LEFT JOIN services s ON s.service_id = oi.service_id AND {$servicePredicate}"
            : "LEFT JOIN services s ON 1=0";
        $catalogLabel = "CASE
               WHEN {$productPredicate} THEN NULLIF(TRIM(p.name), '')
               WHEN {$servicePredicate} THEN NULLIF(TRIM(s.name), '')
               ELSE NULL
           END";
        $itemTypeSelect = $hasItemType ? 'oi.item_type' : "'' AS item_type";
        $serviceIdSelect = $hasServiceId ? 'oi.service_id' : '0 AS service_id';
        $timeBounds = printflow_staff_dashboard_time_bounds_meta($timeMeta);
        $visibilitySql = printflow_staff_recent_orders_visibility_sql('o', $staffRole);
        $statusSql = trim((string)($statusMeta['sql'] ?? '1=1'));
        $rows = db_query(
            "SELECT DISTINCT oi.order_item_id, oi.order_id, oi.product_id,
                    {$itemTypeSelect}, {$serviceIdSelect},
                    {$catalogLabel} AS catalog_name,
                    GREATEST(COALESCE(oi.quantity, 0), 0) AS quantity,
                    GREATEST(COALESCE(oi.unit_price, 0), 0) AS unit_price
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             {$productJoin}
             {$serviceJoin}
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND {$timeBounds['sql']}
               AND {$visibilitySql}
               AND {$statusSql}
               AND {$catalogLabel} IS NOT NULL
               AND oi.quantity > 0
               AND oi.order_item_id > 0",
            'i' . $timeBounds['types'] . (string)($statusMeta['types'] ?? ''),
            array_merge([$branchId], $timeBounds['params'], is_array($statusMeta['params'] ?? null) ? $statusMeta['params'] : [])
        ) ?: [];
        if ($rows === []) {
            return [];
        }

        /** @var array<string, array{item_ids:array<int, true>, order_ids:array<int, true>, lines:array<int,array{order_id:int,order_item_id:int}>, revenue:float}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $orderItemId = (int)($row['order_item_id'] ?? 0);
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderItemId <= 0 || $orderId <= 0) {
                continue;
            }
            $rawLabel = trim((string)($row['catalog_name'] ?? ''));
            if ($rawLabel === '') {
                continue;
            }
            $label = printflow_staff_dashboard_top_sale_label_from_order_label($rawLabel);
            if ($label === '') {
                continue;
            }
            if (!isset($buckets[$label])) {
                $buckets[$label] = ['item_ids' => [], 'order_ids' => [], 'lines' => [], 'revenue' => 0.0];
            }
            $buckets[$label]['item_ids'][$orderItemId] = true;
            $buckets[$label]['order_ids'][$orderId] = true;
            $buckets[$label]['lines'][$orderItemId] = [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'item_type' => (string)($row['item_type'] ?? ''),
                'product_id' => (int)($row['product_id'] ?? 0),
                'service_id' => (int)($row['service_id'] ?? 0),
                'catalog_name' => $rawLabel,
            ];
            $buckets[$label]['revenue'] += (float)($row['quantity'] ?? 0) * (float)($row['unit_price'] ?? 0);
        }

        $result = [];
        foreach ($buckets as $name => $bucket) {
            $result[] = [
                'name' => $name,
                'item_count' => count($bucket['item_ids']),
                'revenue' => round((float)$bucket['revenue'], 2),
            ];
            if ($includeEvidence) {
                $result[array_key_last($result)]['order_ids'] = array_map('intval', array_keys($bucket['order_ids']));
                $result[array_key_last($result)]['order_item_ids'] = array_map('intval', array_keys($bucket['item_ids']));
                $result[array_key_last($result)]['lines'] = array_values($bucket['lines']);
            }
        }

        usort($result, static function (array $a, array $b): int {
            $countCmp = ($b['item_count'] <=> $a['item_count']);
            if ($countCmp !== 0) {
                return $countCmp;
            }

            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return array_slice($result, 0, $limit);
    }
}

if (!function_exists('printflow_staff_dashboard_top_sales_debug')) {
    /**
     * @return array{source:string,branch_id:int,date_range:array<string,string>,status_sql:string,order_ids:list<int>,buckets:list<array<string,mixed>>}
     */
    function printflow_staff_dashboard_top_sales_debug(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta,
        array $statusMeta,
        ?string $staffRole = null
    ): array {
        $top = printflow_staff_dashboard_top_sales(
            $branchId,
            $staffOrderScopeSql,
            $timeMeta,
            $statusMeta,
            20,
            $staffRole,
            true
        );
        $orderIds = [];
        foreach ($top as $bucket) {
            $orderIds = array_merge($orderIds, $bucket['order_ids'] ?? []);
        }

        return [
            'source' => 'order_items joined to orders, products, and services; no job_orders join',
            'branch_id' => $branchId,
            'date_range' => [
                'start' => (string)($timeMeta['start'] ?? ''),
                'end' => (string)($timeMeta['end'] ?? ''),
                'bounds_sql' => printflow_staff_dashboard_time_bounds_meta($timeMeta)['sql'],
            ],
            'status_sql' => (string)($statusMeta['sql'] ?? '1=1'),
            'order_ids' => array_values(array_unique($orderIds)),
            'buckets' => $top,
        ];
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
