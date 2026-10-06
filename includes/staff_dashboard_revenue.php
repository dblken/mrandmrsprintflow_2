<?php

/**
 * Online Operations Staff dashboard revenue rules (staff dashboard KPI + API).
 */

require_once __DIR__ . '/staff_status_filters.php';

function printflow_staff_dashboard_should_compute_revenue(string $statusFilter, ?string $role = null): bool
{
    $role = printflow_staff_status_role($role);
    $code = printflow_staff_normalize_status_filter($statusFilter, $role);

    if ($role === 'pos') {
        return $code === '' || $code === 'COMPLETED';
    }

    return in_array($code, ['ALL', 'COMPLETED'], true);
}

/**
 * Eligible store-order revenue: completed, paid, not cancelled/draft, one row per order.
 */
function printflow_staff_online_dashboard_revenue_sql(string $alias = 'o'): string
{
    $alias = trim($alias) !== '' ? trim($alias) : 'o';

    return "LOWER(TRIM(COALESCE({$alias}.status, ''))) = 'completed'
        AND LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) IN ('paid', 'fully paid')
        AND LOWER(TRIM(COALESCE({$alias}.status, ''))) NOT IN ('cancelled', 'rejected', 'draft')
        AND LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) NOT IN (
            'unpaid', 'awaiting payment', 'failed', 'expired', 'cancelled', 'refunded'
        )
        AND LOWER(TRIM(COALESCE({$alias}.order_source, ''))) NOT IN ('pos_draft')";
}

/**
 * @return array{sql:string,types:string,params:array<int, mixed>}
 */
function printflow_staff_dashboard_time_bounds_meta(array $timeMeta): array
{
    $start = trim((string)($timeMeta['start'] ?? ''));
    $end = trim((string)($timeMeta['end'] ?? ''));
    if ($start === '' || $end === '') {
        return [
            'sql' => (string)($timeMeta['sql'] ?? '1=1'),
            'types' => (string)($timeMeta['types'] ?? ''),
            'params' => is_array($timeMeta['params'] ?? null) ? $timeMeta['params'] : [],
        ];
    }

    try {
        $tz = new DateTimeZone('Asia/Manila');
        $endExclusive = (new DateTimeImmutable($end . ' 23:59:59', $tz))->modify('+1 second');
    } catch (Throwable $e) {
        return [
            'sql' => (string)($timeMeta['sql'] ?? '1=1'),
            'types' => (string)($timeMeta['types'] ?? ''),
            'params' => is_array($timeMeta['params'] ?? null) ? $timeMeta['params'] : [],
        ];
    }

    return [
        'sql' => 'o.order_date >= ? AND o.order_date < ?',
        'types' => 'ss',
        'params' => [$start . ' 00:00:00', $endExclusive->format('Y-m-d H:i:s')],
    ];
}

/**
 * Development diagnostics for revenue KPI troubleshooting.
 *
 * @return array<string, mixed>
 */
function printflow_staff_dashboard_revenue_debug(
    int $branchId,
    string $staffOrderScopeSql,
    array $timeMeta,
    string $statusFilter,
    ?string $role = null,
    float $computedTotal = 0.0
): array {
    $role = printflow_staff_status_role($role);
    $shouldCompute = printflow_staff_dashboard_should_compute_revenue($statusFilter, $role);
    $timeBounds = printflow_staff_dashboard_time_bounds_meta($timeMeta);
    $revenueSql = $shouldCompute ? printflow_staff_online_dashboard_revenue_sql('o') : '0=1';

    $rows = db_query(
        "SELECT o.order_id, o.order_date, o.total_amount, o.status, o.payment_status, o.order_source
         FROM orders o
         WHERE o.branch_id = ?
           AND {$staffOrderScopeSql}
           AND {$timeBounds['sql']}
         ORDER BY o.order_date DESC
         LIMIT 200",
        'i' . $timeBounds['types'],
        array_merge([$branchId], $timeBounds['params'])
    ) ?: [];

    $eligible = [];
    $excluded = [];
    foreach ($rows as $row) {
        $orderId = (int)($row['order_id'] ?? 0);
        $amount = (float)($row['total_amount'] ?? 0);
        $reason = printflow_staff_dashboard_revenue_exclusion_reason($row);
        if ($reason === null && $shouldCompute) {
            $eligible[] = [
                'order_id' => $orderId,
                'order_date' => (string)($row['order_date'] ?? ''),
                'total_amount' => $amount,
            ];
        } else {
            $excluded[] = [
                'order_id' => $orderId,
                'order_date' => (string)($row['order_date'] ?? ''),
                'total_amount' => $amount,
                'status' => (string)($row['status'] ?? ''),
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'order_source' => (string)($row['order_source'] ?? ''),
                'reason' => $reason ?? 'status_filter_blocks_revenue',
            ];
        }
    }

    $subtotal = 0.0;
    foreach ($eligible as $item) {
        $subtotal += (float)($item['total_amount'] ?? 0);
    }

    return [
        'endpoint' => 'staff/api_dashboard_stats.php',
        'dashboard' => 'staff/dashboard.php',
        'role' => $role,
        'status_filter' => printflow_staff_normalize_status_filter($statusFilter, $role),
        'should_compute_revenue' => $shouldCompute,
        'revenue_sql' => $revenueSql,
        'date_range' => [
            'start' => (string)($timeMeta['start'] ?? ''),
            'end' => (string)($timeMeta['end'] ?? ''),
            'bounds_sql' => $timeBounds['sql'],
            'bounds_params' => $timeBounds['params'],
        ],
        'branch_id' => $branchId,
        'orders_in_range' => count($rows),
        'eligible_orders' => count($eligible),
        'excluded_orders' => count($excluded),
        'eligible_sample' => array_slice($eligible, 0, 25),
        'excluded_sample' => array_slice($excluded, 0, 25),
        'revenue_subtotal' => round($subtotal, 2),
        'final_total_revenue' => round($computedTotal, 2),
    ];
}

/** @param array<string, mixed> $order */
function printflow_staff_dashboard_revenue_exclusion_reason(array $order): ?string
{
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $payment = strtolower(trim((string)($order['payment_status'] ?? '')));
    $source = strtolower(trim((string)($order['order_source'] ?? '')));

    if (in_array($status, ['cancelled', 'rejected', 'draft'], true)) {
        return 'status_' . $status;
    }
    if ($source === 'pos_draft') {
        return 'order_source_pos_draft';
    }
    if ($status !== 'completed') {
        return 'status_not_completed';
    }
    if (!in_array($payment, ['paid', 'fully paid'], true)) {
        return 'payment_not_paid';
    }
    if (in_array($payment, ['unpaid', 'awaiting payment', 'failed', 'expired', 'cancelled', 'refunded'], true)) {
        return 'payment_excluded_' . str_replace(' ', '_', $payment);
    }

    return null;
}
