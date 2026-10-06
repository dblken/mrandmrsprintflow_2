<?php
/**
 * Shared Sales Management page filters — used by admin/sales.php and sales exports.
 */
if (defined('PF_SALES_PAGE_QUERIES_LOADED')) {
    return;
}
define('PF_SALES_PAGE_QUERIES_LOADED', true);

require_once __DIR__ . '/reports_dashboard_queries.php';

function pf_sales_method_filter_key(?string $value): string
{
    $value = strtolower(trim((string)$value));
    if (in_array($value, ['qr ph', 'qrph', 'qr_ph', 'qr-ph'], true)) {
        return 'qrph';
    }
    if ($value === 'cash') {
        return 'cash';
    }
    return $value;
}

function pf_sales_is_paid_transaction(array $row): bool
{
    $status = strtolower(trim((string)($row['payment_status'] ?? '')));
    return in_array($status, ['paid', 'fully paid'], true);
}

function pf_sales_format_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $value)));
}

function pf_sales_method_display(?string $value): string
{
    $key = pf_sales_method_filter_key($value);
    if ($key === 'cash') {
        return 'Cash';
    }
    if ($key === 'qrph') {
        return 'QR Ph';
    }
    return pf_sales_format_label($value);
}

/** @return list<string> */
function pf_sales_export_transaction_headers(): array
{
    return [
        'Date',
        'Type',
        'Item / Product or Service',
        'Order #',
        'Customer',
        'Branch',
        'Payment Status',
        'Payment Method',
        'Order Status',
        'Amount',
    ];
}

/**
 * Format one transaction row for CSV/Excel/Print exports (matches admin/sales.php table).
 *
 * @return list<string|float>
 */
function pf_sales_format_export_transaction_row(array $row): array
{
    $date = !empty($row['sales_date'])
        ? date('M j, Y g:i A', strtotime((string)$row['sales_date']))
        : '';

    return [
        $date,
        (string)($row['type'] ?? ''),
        (string)($row['item_name'] ?? '-'),
        '#' . (int)($row['id'] ?? 0),
        (string)($row['customer_name'] ?? ''),
        (string)($row['branch_name'] ?? ''),
        pf_sales_format_label($row['payment_status'] ?? ''),
        pf_sales_method_display($row['payment_method'] ?? ''),
        pf_sales_format_label($row['status'] ?? ''),
        round((float)($row['amount'] ?? 0), 2),
    ];
}

/**
 * @return array{period:string,from:string,to:string,to_end:string,label:string,range_label:string}
 */
function pf_sales_resolve_period(array $input): array
{
    $period = trim((string)($input['sales_period'] ?? 'today'));
    if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
        $period = 'today';
    }

    $todayYmd = date('Y-m-d');
    $requestedFrom = trim((string)($input['from'] ?? ''));
    $requestedTo = trim((string)($input['to'] ?? ''));
    $validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedFrom) ? $requestedFrom : '';
    $validTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedTo) ? $requestedTo : '';

    if ($period === 'custom' && $validFrom !== '' && $validTo !== '') {
        $from = $validFrom;
        $to = $validTo;
        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }
        $label = 'Filtered';
    } elseif ($period === 'week') {
        $from = date('Y-m-d', strtotime('monday this week'));
        $to = $todayYmd;
        $label = 'This Week';
    } elseif ($period === 'month') {
        $from = date('Y-m-01');
        $to = $todayYmd;
        $label = 'This Month';
    } else {
        $period = 'today';
        $from = $todayYmd;
        $to = $todayYmd;
        $label = 'Today';
    }

    $rangeLabel = date('M d, Y', strtotime($from));
    if ($from !== $to) {
        $rangeLabel = date('M d, Y', strtotime($from)) . ' – ' . date('M d, Y', strtotime($to));
    }

    return [
        'period' => $period,
        'from' => $from,
        'to' => $to,
        'to_end' => $to . ' 23:59:59',
        'label' => $label,
        'range_label' => $rangeLabel,
    ];
}

/**
 * @return array{type:string,method:string,item:string}
 */
function pf_sales_filters_from_request(array $input): array
{
    $type = strtolower(trim((string)($input['type'] ?? 'all')));
    if (!in_array($type, ['all', 'product', 'service'], true)) {
        $type = 'all';
    }

    $method = strtolower(trim((string)($input['method'] ?? 'all')));
    if (!in_array($method, ['all', 'cash', 'qrph'], true)) {
        $method = 'all';
    }

    return [
        'type' => $type,
        'method' => $method,
        'item' => trim((string)($input['item'] ?? '')),
    ];
}

/**
 * @param list<array<string,mixed>> $transactions
 * @return list<array<string,mixed>>
 */
function pf_sales_filter_transactions(array $transactions, array $filters): array
{
    return array_values(array_filter($transactions, static function ($row) use ($filters): bool {
        if (!pf_sales_is_paid_transaction($row)) {
            return false;
        }

        $type = strtolower(trim((string)($row['type'] ?? '')));
        $method = pf_sales_method_filter_key($row['payment_method'] ?? '');

        if (($filters['type'] ?? 'all') !== 'all' && $type !== $filters['type']) {
            return false;
        }
        if (($filters['method'] ?? 'all') !== 'all' && $method !== $filters['method']) {
            return false;
        }
        if (($filters['item'] ?? '') !== '') {
            $itemKey = $type . '|' . trim((string)($row['item_name'] ?? ''));
            if ($itemKey !== $filters['item']) {
                return false;
            }
        }

        return true;
    }));
}

/**
 * @param list<array<string,mixed>> $transactions
 * @return array{total_sales:float,transaction_count:int,product_sales:float,service_sales:float}
 */
function pf_sales_summary_from_transactions(array $transactions): array
{
    $summary = [
        'total_sales' => 0.0,
        'transaction_count' => 0,
        'product_sales' => 0.0,
        'service_sales' => 0.0,
    ];

    foreach ($transactions as $row) {
        $amount = (float)($row['amount'] ?? 0);
        $type = strtolower(trim((string)($row['type'] ?? '')));
        $summary['total_sales'] += $amount;
        $summary['transaction_count']++;
        if ($type === 'service') {
            $summary['service_sales'] += $amount;
        } else {
            $summary['product_sales'] += $amount;
        }
    }

    $summary['total_sales'] = round($summary['total_sales'], 2);
    $summary['product_sales'] = round($summary['product_sales'], 2);
    $summary['service_sales'] = round($summary['service_sales'], 2);

    return $summary;
}

/**
 * @return array<string,string>
 */
function pf_sales_export_filter_meta(array $filters, array $periodInfo): array
{
    $typeLabels = ['all' => 'All types', 'product' => 'Product', 'service' => 'Service'];
    $methodLabels = ['all' => 'All methods', 'cash' => 'Cash', 'qrph' => 'QR Ph'];

    $itemLabel = 'All products/services';
    if (($filters['item'] ?? '') !== '') {
        $parts = explode('|', (string)$filters['item'], 2);
        $itemLabel = ucfirst($parts[0] ?? '') . ' - ' . ($parts[1] ?? '');
    }

    return [
        'Period' => (string)($periodInfo['label'] ?? ''),
        'Date Range' => (string)($periodInfo['range_label'] ?? ''),
        'Sales Type' => $typeLabels[$filters['type'] ?? 'all'] ?? 'All types',
        'Payment Method' => $methodLabels[$filters['method'] ?? 'all'] ?? 'All methods',
        'Product / Service' => $itemLabel,
        'Payment Status' => 'Paid only',
    ];
}

/**
 * Sales breakdown with the same paid-only and UI filters as admin/sales.php.
 *
 * @return array{
 *   salesData:array{summary:array<string,mixed>,by_branch:list<array<string,mixed>>,by_item:list<array<string,mixed>>,transactions:list<array<string,mixed>>},
 *   periodInfo:array<string,mixed>,
 *   filters:array<string,string>,
 *   filter_meta:array<string,string>
 * }
 */
/** Complete paid-only aggregated trend data without the transaction-list limit. */
function pf_sales_trend_breakdown(array $periodInfo, $branchId, array $filters): array
{
    $from = (string)($periodInfo['from'] ?? '');
    $toEnd = (string)($periodInfo['to_end'] ?? '');
    $period = (string)($periodInfo['period'] ?? 'today');
    $fromTs = $from !== '' ? strtotime($from) : time();
    $toTs = $toEnd !== '' ? strtotime($toEnd) : $fromTs;
    $days = max(1, (int)floor(($toTs - $fromTs) / 86400) + 1);
    if ($period === 'today' && $days <= 1) {
        $group = static fn(string $e): array => ["DATE_FORMAT({$e}, '%Y-%m-%d %H:00:00')", "DATE_FORMAT({$e}, '%l %p')"];
    } elseif ($days <= 31) {
        $group = static fn(string $e): array => ["DATE({$e})", "DATE_FORMAT({$e}, '%b %e')"];
    } elseif ($days <= 180) {
        $group = static fn(string $e): array => ["DATE_SUB(DATE({$e}), INTERVAL WEEKDAY({$e}) DAY)", "DATE_FORMAT(DATE_SUB(DATE({$e}), INTERVAL WEEKDAY({$e}) DAY), '%b %e')"];
    } else {
        $group = static fn(string $e): array => ["DATE_FORMAT({$e}, '%Y-%m-01')", "DATE_FORMAT({$e}, '%b %Y')"];
    }
    $typeFilter = strtolower(trim((string)($filters['type'] ?? 'all')));
    $methodFilter = strtolower(trim((string)($filters['method'] ?? 'all')));
    $parts = explode('|', trim((string)($filters['item'] ?? '')), 2);
    $itemType = strtolower(trim((string)($parts[0] ?? '')));
    $itemName = trim((string)($parts[1] ?? ''));
    $expectedItemType = $itemType === 'product' ? 'product' : 'service';

    $store = static function (string $scope, string $type, string $revenue, bool $hasMethod) use ($branchId, $from, $toEnd, $group, $methodFilter, $itemType, $itemName, $expectedItemType): array {
        [$key, $label] = $group('o.order_date');
        [$dateSql, $dateTypes, $dateParams] = pf_reports_date_expr_where('o.order_date', $from, $toEnd);
        [$branchSql, $branchTypes, $branchParams] = branch_where_parts('o', $branchId);
        $extraSql = '';
        $extraTypes = '';
        $extraParams = [];
        if ($methodFilter === 'cash') {
            if (!$hasMethod) return [];
            $extraSql .= " AND LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'cash'";
        } elseif ($methodFilter === 'qrph' && $hasMethod) {
            $extraSql .= " AND LOWER(TRIM(COALESCE(o.payment_method, ''))) <> 'cash'";
        }
        if ($itemName !== '' && $itemType === $expectedItemType && (($type === 'Product' && $expectedItemType === 'product') || ($type === 'Custom' && $expectedItemType === 'service'))) {
            $fallback = strtolower($type === 'Product' ? 'Product Order' : 'Customization');
            $extraSql .= " AND LOWER(COALESCE((SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(p_group.name), '') ORDER BY p_group.name SEPARATOR ', ') FROM order_items oi_group LEFT JOIN products p_group ON p_group.product_id = oi_group.product_id WHERE oi_group.order_id = o.order_id), '{$fallback}')) = LOWER(?)";
            $extraTypes = 's';
            $extraParams[] = $itemName;
        }
        $sql = "SELECT {$key} AS bucket_key, {$label} AS bucket_label, COALESCE(SUM({$revenue}), 0) AS revenue FROM orders o WHERE LOWER(TRIM(COALESCE(o.payment_status, ''))) IN ('paid', 'fully paid') AND {$scope}{$extraSql}{$dateSql}{$branchSql} GROUP BY bucket_key, bucket_label ORDER BY bucket_key";
        $result = db_query($sql, $extraTypes . $dateTypes . $branchTypes, array_merge($extraParams, $dateParams, $branchParams)) ?: [];
        return array_map(static fn(array $r): array => ['bucket_key' => (string)($r['bucket_key'] ?? ''), 'bucket_label' => (string)($r['bucket_label'] ?? ''), 'type' => $type, 'revenue' => round((float)($r['revenue'] ?? 0), 2)], $result);
    };

    $jobs = static function (string $revenue, bool $hasMethod) use ($branchId, $from, $toEnd, $group, $methodFilter, $itemType, $itemName, $expectedItemType): array {
        $dateExpr = pf_reports_job_order_sales_date_expr('jo');
        [$key, $label] = $group($dateExpr);
        [$dateSql, $dateTypes, $dateParams] = pf_reports_date_expr_where($dateExpr, $from, $toEnd);
        [$branchSql, $branchTypes, $branchParams] = branch_where_parts('jo', $branchId);
        $extraSql = '';
        $extraTypes = '';
        $extraParams = [];
        if ($methodFilter === 'cash') {
            if (!$hasMethod) return [];
            $extraSql .= " AND LOWER(TRIM(COALESCE(jo.payment_method, ''))) = 'cash'";
        } elseif ($methodFilter === 'qrph' && $hasMethod) {
            $extraSql .= " AND LOWER(TRIM(COALESCE(jo.payment_method, ''))) <> 'cash'";
        }
        if ($itemName !== '' && $itemType === $expectedItemType && $expectedItemType === 'service') {
            $extraSql .= " AND LOWER(COALESCE(NULLIF(TRIM(jo.service_type), ''), NULLIF(TRIM(jo.job_title), ''), 'customization')) = LOWER(?)";
            $extraTypes = 's';
            $extraParams[] = $itemName;
        }
        $exclude = pf_reports_job_exclude_linked_custom_store_sale_sql('jo');
        $sql = "SELECT {$key} AS bucket_key, {$label} AS bucket_label, COALESCE(SUM({$revenue}), 0) AS revenue FROM job_orders jo WHERE LOWER(TRIM(COALESCE(jo.payment_status, ''))) IN ('paid', 'fully paid'){$exclude}{$extraSql}{$dateSql}{$branchSql} GROUP BY bucket_key, bucket_label ORDER BY bucket_key";
        $result = db_query($sql, $extraTypes . $dateTypes . $branchTypes, array_merge($extraParams, $dateParams, $branchParams)) ?: [];
        return array_map(static fn(array $r): array => ['bucket_key' => (string)($r['bucket_key'] ?? ''), 'bucket_label' => (string)($r['bucket_label'] ?? ''), 'type' => 'Custom', 'revenue' => round((float)($r['revenue'] ?? 0), 2)], $result);
    };

    $hasStoreMethod = function_exists('db_table_has_column') ? db_table_has_column('orders', 'payment_method') : pf_reports_table_has_column('orders', 'payment_method');
    $hasJobMethod = function_exists('db_table_has_column') ? db_table_has_column('job_orders', 'payment_method') : pf_reports_table_has_column('job_orders', 'payment_method');
    $rows = [];
    if ($typeFilter !== 'service') $rows = array_merge($rows, $store(pf_reports_store_product_order_scope_sql('o'), 'Product', pf_reports_store_order_revenue_expr('o'), $hasStoreMethod));
    if ($typeFilter !== 'product') {
        $rows = array_merge($rows, $store(pf_reports_store_custom_order_scope_sql('o'), 'Custom', pf_reports_store_order_revenue_expr('o'), $hasStoreMethod));
        $rows = array_merge($rows, $jobs(pf_reports_job_order_revenue_expr('jo'), $hasJobMethod));
    }
    $buckets = [];
    foreach ($rows as $row) {
        $key = (string)$row['bucket_key'];
        if ($key === '') continue;
        if (!isset($buckets[$key])) $buckets[$key] = ['bucket_key' => $key, 'bucket_label' => (string)$row['bucket_label'], 'product_sales' => 0.0, 'custom_sales' => 0.0];
        if ($row['type'] === 'Product') $buckets[$key]['product_sales'] += (float)$row['revenue'];
        else $buckets[$key]['custom_sales'] += (float)$row['revenue'];
    }
    ksort($buckets);
    foreach ($buckets as &$bucket) {
        $bucket['product_sales'] = round($bucket['product_sales'], 2);
        $bucket['custom_sales'] = round($bucket['custom_sales'], 2);
    }
    unset($bucket);
    return array_values($buckets);
}
/** Complete paid-only trend data grouped by active branch for the All Branches view. */
function pf_sales_trend_branch_breakdown(array $periodInfo, array $filters): array
{
    $from = (string)($periodInfo['from'] ?? '');
    $toEnd = (string)($periodInfo['to_end'] ?? '');
    $period = (string)($periodInfo['period'] ?? 'today');
    $fromTs = $from !== '' ? strtotime($from) : time();
    $toTs = $toEnd !== '' ? strtotime($toEnd) : $fromTs;
    $days = max(1, (int)floor(($toTs - $fromTs) / 86400) + 1);
    if ($period === 'today' && $days <= 1) {
        $group = static fn(string $e): array => ["DATE_FORMAT({$e}, '%Y-%m-%d %H:00:00')", "DATE_FORMAT({$e}, '%l %p')"];
    } elseif ($days <= 31) {
        $group = static fn(string $e): array => ["DATE({$e})", "DATE_FORMAT({$e}, '%b %e')"];
    } elseif ($days <= 180) {
        $group = static fn(string $e): array => ["DATE_SUB(DATE({$e}), INTERVAL WEEKDAY({$e}) DAY)", "DATE_FORMAT(DATE_SUB(DATE({$e}), INTERVAL WEEKDAY({$e}) DAY), '%b %e')"];
    } else {
        $group = static fn(string $e): array => ["DATE_FORMAT({$e}, '%Y-%m-01')", "DATE_FORMAT({$e}, '%b %Y')"];
    }
    $typeFilter = strtolower(trim((string)($filters['type'] ?? 'all')));
    $methodFilter = strtolower(trim((string)($filters['method'] ?? 'all')));
    $parts = explode('|', trim((string)($filters['item'] ?? '')), 2);
    $itemType = strtolower(trim((string)($parts[0] ?? '')));
    $itemName = trim((string)($parts[1] ?? ''));
    $expectedItemType = $itemType === 'product' ? 'product' : 'service';
    $series = [];
    foreach (get_all_branches() as $branch) {
        $name = trim((string)($branch['branch_name'] ?? ''));
        if ($name !== '') $series[] = $name;
    }
    $series = array_values(array_unique($series));
    $rows = [];

    $store = static function (string $scope, string $type, string $revenue, bool $hasMethod) use ($from, $toEnd, $group, $methodFilter, $itemType, $itemName, $expectedItemType): array {
        [$key, $label] = $group('o.order_date');
        $branchName = "COALESCE(NULLIF(TRIM(b.branch_name), ''), CONCAT('Branch #', o.branch_id))";
        [$dateSql, $dateTypes, $dateParams] = pf_reports_date_expr_where('o.order_date', $from, $toEnd);
        [$branchSql, $branchTypes, $branchParams] = branch_where_parts('o', 'all');
        $extraSql = '';
        $extraTypes = '';
        $extraParams = [];
        if ($methodFilter === 'cash') {
            if (!$hasMethod) return [];
            $extraSql .= " AND LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'cash'";
        } elseif ($methodFilter === 'qrph' && $hasMethod) {
            $extraSql .= " AND LOWER(TRIM(COALESCE(o.payment_method, ''))) <> 'cash'";
        }
        if ($itemName !== '' && $itemType === $expectedItemType && (($type === 'Product' && $expectedItemType === 'product') || ($type === 'Custom' && $expectedItemType === 'service'))) {
            $fallback = strtolower($type === 'Product' ? 'Product Order' : 'Customization');
            $extraSql .= " AND LOWER(COALESCE((SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(p_group.name), '') ORDER BY p_group.name SEPARATOR ', ') FROM order_items oi_group LEFT JOIN products p_group ON p_group.product_id = oi_group.product_id WHERE oi_group.order_id = o.order_id), '{$fallback}')) = LOWER(?)";
            $extraTypes = 's';
            $extraParams[] = $itemName;
        }
        $sql = "SELECT {$key} AS bucket_key, {$label} AS bucket_label, {$branchName} AS branch_name, COALESCE(SUM({$revenue}), 0) AS revenue FROM orders o LEFT JOIN branches b ON b.id = o.branch_id WHERE LOWER(TRIM(COALESCE(o.payment_status, ''))) IN ('paid', 'fully paid') AND {$scope}{$extraSql}{$dateSql}{$branchSql} GROUP BY bucket_key, bucket_label, branch_name ORDER BY bucket_key, branch_name";
        $result = db_query($sql, $extraTypes . $dateTypes . $branchTypes, array_merge($extraParams, $dateParams, $branchParams)) ?: [];
        return array_map(static fn(array $r): array => ['bucket_key' => (string)($r['bucket_key'] ?? ''), 'bucket_label' => (string)($r['bucket_label'] ?? ''), 'branch_name' => (string)($r['branch_name'] ?? ''), 'type' => $type, 'revenue' => round((float)($r['revenue'] ?? 0), 2)], $result);
    };

    $jobs = static function (string $revenue, bool $hasMethod) use ($from, $toEnd, $group, $methodFilter, $itemType, $itemName, $expectedItemType): array {
        $dateExpr = pf_reports_job_order_sales_date_expr('jo');
        [$key, $label] = $group($dateExpr);
        $branchName = "COALESCE(NULLIF(TRIM(b.branch_name), ''), CONCAT('Branch #', jo.branch_id))";
        [$dateSql, $dateTypes, $dateParams] = pf_reports_date_expr_where($dateExpr, $from, $toEnd);
        [$branchSql, $branchTypes, $branchParams] = branch_where_parts('jo', 'all');
        $extraSql = '';
        $extraTypes = '';
        $extraParams = [];
        if ($methodFilter === 'cash') {
            if (!$hasMethod) return [];
            $extraSql .= " AND LOWER(TRIM(COALESCE(jo.payment_method, ''))) = 'cash'";
        } elseif ($methodFilter === 'qrph' && $hasMethod) {
            $extraSql .= " AND LOWER(TRIM(COALESCE(jo.payment_method, ''))) <> 'cash'";
        }
        if ($itemName !== '' && $itemType === $expectedItemType && $expectedItemType === 'service') {
            $extraSql .= " AND LOWER(COALESCE(NULLIF(TRIM(jo.service_type), ''), NULLIF(TRIM(jo.job_title), ''), 'customization')) = LOWER(?)";
            $extraTypes = 's';
            $extraParams[] = $itemName;
        }
        $exclude = pf_reports_job_exclude_linked_custom_store_sale_sql('jo');
        $sql = "SELECT {$key} AS bucket_key, {$label} AS bucket_label, {$branchName} AS branch_name, COALESCE(SUM({$revenue}), 0) AS revenue FROM job_orders jo LEFT JOIN branches b ON b.id = jo.branch_id WHERE LOWER(TRIM(COALESCE(jo.payment_status, ''))) IN ('paid', 'fully paid'){$exclude}{$extraSql}{$dateSql}{$branchSql} GROUP BY bucket_key, bucket_label, branch_name ORDER BY bucket_key, branch_name";
        $result = db_query($sql, $extraTypes . $dateTypes . $branchTypes, array_merge($extraParams, $dateParams, $branchParams)) ?: [];
        return array_map(static fn(array $r): array => ['bucket_key' => (string)($r['bucket_key'] ?? ''), 'bucket_label' => (string)($r['bucket_label'] ?? ''), 'branch_name' => (string)($r['branch_name'] ?? ''), 'type' => 'Custom', 'revenue' => round((float)($r['revenue'] ?? 0), 2)], $result);
    };

    $hasStoreMethod = function_exists('db_table_has_column') ? db_table_has_column('orders', 'payment_method') : pf_reports_table_has_column('orders', 'payment_method');
    $hasJobMethod = function_exists('db_table_has_column') ? db_table_has_column('job_orders', 'payment_method') : pf_reports_table_has_column('job_orders', 'payment_method');
    if ($typeFilter !== 'service') $rows = array_merge($rows, $store(pf_reports_store_product_order_scope_sql('o'), 'Product', pf_reports_store_order_revenue_expr('o'), $hasStoreMethod));
    if ($typeFilter !== 'product') {
        $rows = array_merge($rows, $store(pf_reports_store_custom_order_scope_sql('o'), 'Custom', pf_reports_store_order_revenue_expr('o'), $hasStoreMethod));
        $rows = array_merge($rows, $jobs(pf_reports_job_order_revenue_expr('jo'), $hasJobMethod));
    }
    $buckets = [];
    $sourceTotals = [];
    foreach ($rows as $row) {
        $key = (string)$row['bucket_key'];
        $branchName = trim((string)$row['branch_name']);
        if ($key === '' || $branchName === '') continue;
        if (!isset($buckets[$key])) $buckets[$key] = ['bucket_key' => $key, 'bucket_label' => (string)$row['bucket_label'], 'branch_sales' => []];
        $buckets[$key]['branch_sales'][$branchName] = ($buckets[$key]['branch_sales'][$branchName] ?? 0) + (float)$row['revenue'];
        if (!isset($sourceTotals[$branchName])) $sourceTotals[$branchName] = ['product_sales' => 0.0, 'custom_sales' => 0.0];
        $sourceKey = ($row['type'] ?? '') === 'Product' ? 'product_sales' : 'custom_sales';
        $sourceTotals[$branchName][$sourceKey] += (float)$row['revenue'];
    }
    ksort($buckets);
    foreach ($buckets as &$bucket) {
        foreach ($series as $branchName) $bucket['branch_sales'][$branchName] = round((float)($bucket['branch_sales'][$branchName] ?? 0), 2);
        ksort($bucket['branch_sales']);
    }
    unset($bucket);
    $sourceBreakdown = [];
    foreach ($series as $branchName) {
        $sourceBreakdown[] = [
            'branch_name' => $branchName,
            'product_sales' => round((float)($sourceTotals[$branchName]['product_sales'] ?? 0), 2),
            'custom_sales' => round((float)($sourceTotals[$branchName]['custom_sales'] ?? 0), 2),
        ];
    }
    return ['mode' => 'branches', 'series' => $series, 'rows' => array_values($buckets), 'source_breakdown' => $sourceBreakdown];
}
function pf_sales_page_filtered_breakdown(array $input, $branchId, int $limit = 5000): array
{
    $periodInfo = pf_sales_resolve_period($input);
    $filters = pf_sales_filters_from_request($input);

    $branchEmpty = !pf_reports_branch_has_activity($branchId);
    $salesData = !$branchEmpty
        ? pf_reports_official_sales_breakdown($periodInfo['from'], $periodInfo['to_end'], $branchId, $limit)
        : ['summary' => [], 'by_branch' => [], 'by_item' => [], 'transactions' => []];

    $salesData['transactions'] = pf_sales_filter_transactions($salesData['transactions'] ?? [], $filters);
    $salesData['summary'] = pf_sales_summary_from_transactions($salesData['transactions']);

    return [
        'salesData' => $salesData,
        'periodInfo' => $periodInfo,
        'filters' => $filters,
        'filter_meta' => pf_sales_export_filter_meta($filters, $periodInfo),
    ];
}
