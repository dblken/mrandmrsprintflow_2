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

if (!function_exists('printflow_staff_dashboard_top_sale_label_from_order_label')) {
    function printflow_staff_dashboard_top_sale_label_from_order_label(string $label): string
    {
        $label = trim($label);
        if ($label === '' || strcasecmp($label, 'Custom Service') === 0) {
            return 'General';
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
     * @return list<array{name:string,order_count:int,revenue:float}>
     */
    function printflow_staff_dashboard_top_sales(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta,
        array $statusMeta,
        int $limit = 5,
        ?string $staffRole = null
    ): array {
        $limit = max(1, min(20, $limit));
        $orderRows = printflow_staff_dashboard_top_sales_eligible_orders(
            $branchId,
            $staffOrderScopeSql,
            $timeMeta,
            $statusMeta,
            $staffRole
        );
        if ($orderRows === []) {
            return [];
        }

        $orderIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int)($row['order_id'] ?? 0);
        }, $orderRows))));
        $labelByOrder = function_exists('printflow_dashboard_order_service_labels')
            ? printflow_dashboard_order_service_labels($orderIds)
            : [];

        /** @var array<string, array{order_ids:array<int, true>, revenue:float}> $buckets */
        $buckets = [];
        foreach ($orderRows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $rawLabel = (string)($labelByOrder[$orderId] ?? 'General');
            $label = printflow_staff_dashboard_top_sale_label_from_order_label($rawLabel);
            if (!isset($buckets[$label])) {
                $buckets[$label] = ['order_ids' => [], 'revenue' => 0.0];
            }
            $buckets[$label]['order_ids'][$orderId] = true;
            $buckets[$label]['revenue'] += (float)($row['total_amount'] ?? 0);
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

if (!function_exists('printflow_staff_dashboard_top_sales_debug')) {
    /**
     * @return array{order_ids:list<int>,labels:array<int,string>,buckets:list<array{name:string,order_ids:list<int>}>}
     */
    function printflow_staff_dashboard_top_sales_debug(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta,
        array $statusMeta,
        ?string $staffRole = null
    ): array {
        $orderRows = printflow_staff_dashboard_top_sales_eligible_orders(
            $branchId,
            $staffOrderScopeSql,
            $timeMeta,
            $statusMeta,
            $staffRole
        );
        $orderIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int)($row['order_id'] ?? 0);
        }, $orderRows))));
        $labels = function_exists('printflow_dashboard_order_service_labels')
            ? printflow_dashboard_order_service_labels($orderIds)
            : [];
        $top = printflow_staff_dashboard_top_sales(
            $branchId,
            $staffOrderScopeSql,
            $timeMeta,
            $statusMeta,
            20,
            $staffRole
        );
        $buckets = [];
        foreach ($top as $row) {
            $name = (string)($row['name'] ?? '');
            $matching = [];
            foreach ($orderIds as $orderId) {
                $label = printflow_staff_dashboard_top_sale_label_from_order_label((string)($labels[$orderId] ?? ''));
                if ($label === $name) {
                    $matching[] = $orderId;
                }
            }
            $buckets[] = ['name' => $name, 'order_ids' => $matching];
        }

        return [
            'order_ids' => $orderIds,
            'labels' => $labels,
            'buckets' => $buckets,
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
