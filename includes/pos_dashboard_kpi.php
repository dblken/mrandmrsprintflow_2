<?php

/**
 * POS / Walk-in dashboard KPI metrics for staff dashboard + API refresh.
 */

if (!function_exists('printflow_pos_dashboard_exclude_draft_sql')) {
    function printflow_pos_dashboard_exclude_draft_sql(string $orderAlias = 'o'): string
    {
        return " AND NOT (
            LOWER(TRIM(COALESCE({$orderAlias}.payment_status, ''))) = 'unpaid'
            AND LOWER(TRIM(COALESCE({$orderAlias}.status, ''))) = 'draft'
            AND LOWER(TRIM(COALESCE({$orderAlias}.order_source, ''))) IN ('pos_draft', '')
        )";
    }
}

if (!function_exists('printflow_pos_dashboard_revenue_label')) {
    function printflow_pos_dashboard_revenue_label(string $timeframe): string
    {
        return match ($timeframe) {
            'week' => "This Week's Revenue",
            'month' => "This Month's Revenue",
            default => "Today's Revenue",
        };
    }
}

if (!function_exists('printflow_pos_dashboard_revenue_subtitle')) {
    function printflow_pos_dashboard_revenue_subtitle(string $timeframe): string
    {
        return match ($timeframe) {
            'week' => 'POS income this week',
            'month' => 'POS income this month',
            default => 'POS income today',
        };
    }
}

if (!function_exists('printflow_pos_dashboard_walkin_subtitle')) {
    function printflow_pos_dashboard_walkin_subtitle(string $timeframe): string
    {
        return match ($timeframe) {
            'week' => "This week's counter transactions",
            'month' => "This month's counter transactions",
            default => "Today's counter transactions",
        };
    }
}

if (!function_exists('printflow_pos_dashboard_completed_subtitle')) {
    function printflow_pos_dashboard_completed_subtitle(string $timeframe): string
    {
        return match ($timeframe) {
            'week' => 'Finished POS orders this week',
            'month' => 'Finished POS orders this month',
            default => 'Finished POS orders',
        };
    }
}

if (!function_exists('printflow_pos_dashboard_kpi_links')) {
    /**
     * @return array{reports:string,orders:string,customizations_pending:string,orders_completed:string}
     */
    function printflow_pos_dashboard_kpi_links(string $timeframe, string $rangeStart, string $rangeEnd): array
    {
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        $reportsRange = in_array($timeframe, ['today', 'week', 'month'], true) ? $timeframe : 'today';
        $dateQuery = http_build_query([
            'date_from' => $rangeStart,
            'date_to' => $rangeEnd,
        ]);

        return [
            'reports' => $base . '/staff/reports.php?range=' . rawurlencode($reportsRange),
            'orders' => $base . '/staff/orders.php?' . $dateQuery,
            'customizations_pending' => $base . '/staff/customizations.php?status=PENDING',
            'orders_completed' => $base . '/staff/orders.php?' . $dateQuery,
        ];
    }
}

if (!function_exists('printflow_pos_dashboard_kpi_metrics')) {
    /**
     * @param array{key:string,start:string,end:string,sql:string,sql_no_alias:string,types:string,params:array} $timeMeta
     * @return array{
     *   revenue:float,
     *   walk_in_orders:int,
     *   pending_customizations:int,
     *   completed_transactions:int,
     *   formatted_revenue:string,
     *   revenue_label:string
     * }
     */
    function printflow_pos_dashboard_kpi_metrics(
        int $branchId,
        string $staffOrderScopeSql,
        array $timeMeta
    ): array {
        $draftSql = printflow_pos_dashboard_exclude_draft_sql('o');
        $timeSql = (string)($timeMeta['sql'] ?? '1=1');
        $timeTypes = (string)($timeMeta['types'] ?? '');
        $timeParams = is_array($timeMeta['params'] ?? null) ? $timeMeta['params'] : [];
        $timeframeKey = (string)($timeMeta['key'] ?? 'today');

        $baseTypes = 'i' . $timeTypes;
        $baseParams = array_merge([$branchId], $timeParams);

        $revenueRow = db_query(
            "SELECT COALESCE(SUM(total_amount), 0) AS total
             FROM orders o
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND o.status = 'Completed'
               {$draftSql}
               AND {$timeSql}",
            $baseTypes,
            $baseParams
        ) ?: [];
        $revenue = (float)($revenueRow[0]['total'] ?? 0);

        $walkInRow = db_query(
            "SELECT COUNT(*) AS count
             FROM orders o
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND o.status NOT IN ('Cancelled', 'Rejected')
               {$draftSql}
               AND {$timeSql}",
            $baseTypes,
            $baseParams
        ) ?: [];
        $walkInOrders = (int)($walkInRow[0]['count'] ?? 0);

        $completedRow = db_query(
            "SELECT COUNT(*) AS count
             FROM orders o
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND o.status = 'Completed'
               {$draftSql}
               AND {$timeSql}",
            $baseTypes,
            $baseParams
        ) ?: [];
        $completedTransactions = (int)($completedRow[0]['count'] ?? 0);

        $pendingRow = db_query(
            "SELECT COUNT(*) AS count
             FROM orders o
             WHERE o.branch_id = ?
               AND {$staffOrderScopeSql}
               AND o.order_type = 'custom'
               AND o.status NOT IN ('Completed', 'Cancelled', 'Rejected')
               {$draftSql}",
            'i',
            [$branchId]
        ) ?: [];
        $pendingCustomizations = (int)($pendingRow[0]['count'] ?? 0);

        return [
            'revenue' => $revenue,
            'walk_in_orders' => $walkInOrders,
            'pending_customizations' => $pendingCustomizations,
            'completed_transactions' => $completedTransactions,
            'formatted_revenue' => '₱' . number_format($revenue, 2),
            'revenue_label' => printflow_pos_dashboard_revenue_label($timeframeKey),
            'revenue_subtitle' => printflow_pos_dashboard_revenue_subtitle($timeframeKey),
            'walk_in_subtitle' => printflow_pos_dashboard_walkin_subtitle($timeframeKey),
            'completed_subtitle' => printflow_pos_dashboard_completed_subtitle($timeframeKey),
        ];
    }
}
