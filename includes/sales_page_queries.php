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
