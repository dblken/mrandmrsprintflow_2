<?php
/**
 * Staff Dashboard API
 * Returns real-time statistics and filtered data (JSON only).
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../tmp/php_staff_api_errors.log');
error_reporting(E_ALL);

ob_start();

$__pf_debug_requested = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
$__pf_debug_allowed = false;
$__pf_captured_errors = [];

set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$__pf_captured_errors) {
    $__pf_captured_errors[] = [
        'type' => (int)$errno,
        'message' => (string)$errstr,
        'file' => (string)$errfile,
        'line' => (int)$errline,
    ];
    return true;
});

set_exception_handler(function ($e) use (&$__pf_debug_allowed) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['success' => false, 'error' => 'Server error'];
    if ($__pf_debug_allowed) {
        $payload['debug'] = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
});

register_shutdown_function(function () use (&$__pf_debug_allowed, &$__pf_debug_requested, &$__pf_captured_errors) {
    $output = '';
    if (ob_get_level()) {
        $output = (string)ob_get_clean();
    }

    $err = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if ($err && in_array((int)$err['type'], $fatalTypes, true)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => false, 'error' => 'Server error'];
        if ($__pf_debug_allowed || $__pf_debug_requested) {
            $payload['debug'] = ['fatal' => $err];
        }
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo json_encode($payload, $flags);
        return;
    }

    if ($output !== '') {
        $decoded = json_decode($output, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            $payload = ['success' => false, 'error' => 'Invalid API response format'];
            if ($__pf_debug_allowed || $__pf_debug_requested) {
                $payload['debug'] = [
                    'raw_output' => $output,
                    'captured' => $__pf_captured_errors,
                ];
            }
            $flags = JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            echo json_encode($payload, $flags);
            return;
        }

        if (($__pf_debug_allowed || $__pf_debug_requested) && is_array($decoded) && $__pf_captured_errors) {
            $decoded['debug'] = $decoded['debug'] ?? [];
            $decoded['debug']['captured'] = $__pf_captured_errors;
            $flags = JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $output = json_encode($decoded, $flags);
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo $output;
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/staff_access.php';

if ($__pf_debug_requested && defined('PRINTFLOW_DEBUG_SESSION_LOG') && PRINTFLOW_DEBUG_SESSION_LOG) {
    $sessionCookieName = session_name();
    $debugSnapshot = [
        'endpoint' => 'staff/api_dashboard_stats.php',
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'https' => $_SERVER['HTTPS'] ?? null,
        'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
        'session_status' => session_status(),
        'session_name' => $sessionCookieName,
        'has_session_cookie' => isset($_COOKIE[$sessionCookieName]),
        'session_keys' => array_values(array_slice(array_keys($_SESSION ?? []), 0, 50)),
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_type' => $_SESSION['user_type'] ?? null,
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'selected_branch_id' => $_SESSION['selected_branch_id'] ?? null,
    ];

    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    error_log('[PrintFlow] Session debug: ' . json_encode($debugSnapshot, $flags));
}

if (!has_role(['Admin', 'Manager', 'Staff'])) {
    http_response_code(403);
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $payload = ['success' => false, 'error' => 'Unauthorized'];
    if ($__pf_debug_requested) {
        $sessionCookieName = session_name();
        $payload['debug'] = [
            'debug_note' => 'Debug is limited until authorized.',
            'session_status' => session_status(),
            'session_name' => $sessionCookieName,
            'has_session_cookie' => isset($_COOKIE[$sessionCookieName]),
            'cookie_names' => array_values(array_slice(array_keys($_COOKIE ?? []), 0, 20)),
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'https' => $_SERVER['HTTPS'] ?? null,
            'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
            'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'user_type' => $_SESSION['user_type'] ?? null,
            'user_id' => $_SESSION['user_id'] ?? null,
        ];
    }
    echo json_encode($payload, $flags);
    exit;
}

$__pf_debug_allowed = $__pf_debug_requested;
printflow_require_staff_module('dashboard');

function pf_dashboard_timeframe_meta(string $timeframe): array
{
    $today = date('Y-m-d');

    return match ($timeframe) {
        'week' => (function () {
            $start = date('Y-m-d', strtotime('monday this week'));
            $end = date('Y-m-d', strtotime('sunday this week'));
            $startDay = date('j', strtotime($start));
            $endDay = date('j', strtotime($end));
            $startMonth = date('F', strtotime($start));
            $endMonth = date('F', strtotime($end));
            $year = date('Y', strtotime($end));
            return [
                'key' => 'week',
                'start' => $start,
                'end' => $end,
                'sql' => "DATE(o.order_date) BETWEEN ? AND ?",
                'sql_no_alias' => "DATE(order_date) BETWEEN ? AND ?",
                'types' => 'ss',
                'params' => [$start, $end],
                'display_label' => ($startMonth === $endMonth)
                    ? "This Week ($startMonth $startDay-$endDay, $year)"
                    : "This Week ($startMonth $startDay - $endMonth $endDay, $year)",
                'short_label' => 'This Week',
                'chart_title' => 'Sales Trend (7 Days)',
            ];
        })(),
        'month' => [
            'key' => 'month',
            'start' => date('Y-m-01'),
            'end' => date('Y-m-t'),
            'sql' => "DATE(o.order_date) BETWEEN ? AND ?",
            'sql_no_alias' => "DATE(order_date) BETWEEN ? AND ?",
            'types' => 'ss',
            'params' => [date('Y-m-01'), date('Y-m-t')],
            'display_label' => 'This Month (' . date('F Y') . ')',
            'short_label' => 'This Month',
            'chart_title' => 'Sales Trend (This Month)',
        ],
        'year' => [
            'key' => 'year',
            'start' => date('Y-01-01'),
            'end' => date('Y-12-31'),
            'sql' => "DATE(o.order_date) BETWEEN ? AND ?",
            'sql_no_alias' => "DATE(order_date) BETWEEN ? AND ?",
            'types' => 'ss',
            'params' => [date('Y-01-01'), date('Y-12-31')],
            'display_label' => 'This Year (' . date('Y') . ')',
            'short_label' => 'This Year',
            'chart_title' => 'Sales Trend (This Year)',
        ],
        default => [
            'key' => 'today',
            'start' => $today,
            'end' => $today,
            'sql' => "DATE(o.order_date) = ?",
            'sql_no_alias' => "DATE(order_date) = ?",
            'types' => 's',
            'params' => [$today],
            'display_label' => 'Today (' . date('F j, Y') . ')',
            'short_label' => 'Today',
            'chart_title' => 'Sales Trend (Today)',
        ],
    };
}

function pf_dashboard_status_groups(): array
{
    return [
        'PENDING' => ['Pending', 'Pending Review', 'Pending Approval', 'For Revision', 'To Verify', 'Pending Verification', 'Verify Pay', 'Downpayment Submitted'],
        'APPROVED' => ['Approved', 'Design Approved', 'Processing', 'In Production', 'Printing', 'Approved Design'],
        'TO_PAY' => ['To Pay'],
        'READY' => ['Ready for Pickup', 'To Pickup', 'To Pick Up'],
        'COMPLETED' => ['Completed'],
        'CANCELLED' => ['Cancelled', 'Rejected'],
    ];
}

function pf_dashboard_status_labels(): array
{
    return [
        'PENDING' => 'Pending',
        'APPROVED' => 'Approved',
        'TO_PAY' => 'To Pay',
        'READY' => 'Ready',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled / Rejected',
    ];
}

function pf_dashboard_normalize_status_filter(string $statusFilter): string
{
    $statusFilter = strtoupper(trim($statusFilter));
    if ($statusFilter === '') {
        return '';
    }

    $groups = pf_dashboard_status_groups();
    if (isset($groups[$statusFilter])) {
        return $statusFilter;
    }

    foreach ($groups as $code => $statuses) {
        if (in_array($statusFilter, array_map('strtoupper', $statuses), true)) {
            return $code;
        }
    }

    return '';
}

function pf_dashboard_status_sql(string $alias, string $statusFilter): array
{
    $groups = pf_dashboard_status_groups();
    $statusFilter = pf_dashboard_normalize_status_filter($statusFilter);
    if ($statusFilter === '' || !isset($groups[$statusFilter])) {
        return ['sql' => '1=1', 'types' => '', 'params' => []];
    }

    $statuses = $groups[$statusFilter];
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    return [
        'sql' => "{$alias}.status IN ($placeholders)",
        'types' => str_repeat('s', count($statuses)),
        'params' => $statuses,
    ];
}

$staffCtx = init_branch_context();
$staffBranchId = $staffCtx['selected_branch_id'] === 'all'
    ? (int)($_SESSION['branch_id'] ?? 1)
    : (int)$staffCtx['selected_branch_id'];
$staffAccessMeta = printflow_get_staff_access_meta();
$staffRole = (string)($staffAccessMeta['key'] ?? 'online');
$staffOrderScopeSql = printflow_staff_order_source_sql('o', $staffRole);

$hasOrderType = function_exists('db_table_has_column') ? db_table_has_column('orders', 'order_type') : true;

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;
$search_filter = trim((string)($_GET['search'] ?? ''));
$timeframe = strtolower(trim((string)($_GET['timeframe'] ?? 'today')));
if (!in_array($timeframe, ['today', 'week', 'month', 'year'], true)) {
    $timeframe = 'today';
}
$status_filter = pf_dashboard_normalize_status_filter((string)($_GET['status'] ?? ''));

$timeMeta = pf_dashboard_timeframe_meta($timeframe);
$statusMeta = pf_dashboard_status_sql('o', $status_filter);
$statusLabels = pf_dashboard_status_labels();
$statusLabel = $status_filter !== '' ? ($statusLabels[$status_filter] ?? $status_filter) : 'All';

$productLabel = (($status_filter !== '' && $status_filter !== 'COMPLETED') ? $statusLabel : 'Completed') . ' Product Orders';
$customLabel = (($status_filter !== '' && $status_filter !== 'COMPLETED') ? $statusLabel : 'Completed') . ' Customized Orders';
$reviewLabel = ($status_filter !== '' ? $statusLabel . ' ' : '') . 'Reviews';

$productTypeSql = $hasOrderType ? " AND o.order_type = 'product'" : '';
$customTypeSql = $hasOrderType
    ? " AND (s.service_id IS NOT NULL OR jo.id IS NOT NULL OR o.order_type = 'custom')"
    : " AND (s.service_id IS NOT NULL OR jo.id IS NOT NULL)";

$productOrders = db_query(
    "SELECT COUNT(DISTINCT o.order_id) AS count
     FROM orders o
     JOIN order_items oi ON o.order_id = oi.order_id
     JOIN products p ON oi.product_id = p.product_id
     WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND {$statusMeta['sql']}{$productTypeSql}",
    'i' . $timeMeta['types'] . $statusMeta['types'],
    array_merge([$staffBranchId], $timeMeta['params'], $statusMeta['params'])
) ?: [];
$productOrdersCount = (int)($productOrders[0]['count'] ?? 0);

$customOrders = db_query(
    "SELECT COUNT(DISTINCT o.order_id) AS count
     FROM orders o
     JOIN order_items oi ON o.order_id = oi.order_id
     LEFT JOIN job_orders jo ON oi.order_item_id = jo.order_item_id
     LEFT JOIN services s ON oi.product_id = s.service_id
     WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND {$statusMeta['sql']}{$customTypeSql}",
    'i' . $timeMeta['types'] . $statusMeta['types'],
    array_merge([$staffBranchId], $timeMeta['params'], $statusMeta['params'])
) ?: [];
$customOrdersCount = (int)($customOrders[0]['count'] ?? 0);

$reviews = db_query(
    "SELECT COUNT(DISTINCT r.id) AS count
     FROM reviews r
     JOIN orders o ON o.order_id = r.order_id
     WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND {$statusMeta['sql']}",
    'i' . $timeMeta['types'] . $statusMeta['types'],
    array_merge([$staffBranchId], $timeMeta['params'], $statusMeta['params'])
) ?: [];
$reviewsCount = (int)($reviews[0]['count'] ?? 0);

$revenueSql = ($status_filter === '' || $status_filter === 'COMPLETED') ? "o.status = 'Completed'" : '0=1';
$revenue = db_query(
    "SELECT COALESCE(SUM(o.total_amount), 0) AS total
     FROM orders o
     WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND {$revenueSql}",
    'i' . $timeMeta['types'],
    array_merge([$staffBranchId], $timeMeta['params'])
) ?: [];
$totalRevenue = (float)($revenue[0]['total'] ?? 0);

$chartLabels = [];
$chartValues = [];
if ($revenueSql === '0=1') {
    if ($timeframe === 'today') {
        for ($hour = 0; $hour < 24; $hour++) {
            $chartLabels[] = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
            $chartValues[] = 0.0;
        }
    } elseif ($timeframe === 'week') {
        $baseTs = strtotime((string)$timeMeta['start']);
        for ($i = 0; $i < 7; $i++) {
            $chartLabels[] = date('D', strtotime("+$i day", $baseTs));
            $chartValues[] = 0.0;
        }
    } elseif ($timeframe === 'month') {
        $daysInMonth = (int)date('t', strtotime((string)$timeMeta['start']));
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $chartLabels[] = $day;
            $chartValues[] = 0.0;
        }
    } else {
        for ($month = 1; $month <= 12; $month++) {
            $chartLabels[] = date('M', mktime(0, 0, 0, $month, 1));
            $chartValues[] = 0.0;
        }
    }
} elseif ($timeframe === 'today') {
    $rows = db_query(
        "SELECT HOUR(o.order_date) AS bucket_key, COALESCE(SUM(o.total_amount), 0) AS total
         FROM orders o
         WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND o.status = 'Completed'
         GROUP BY HOUR(o.order_date)
         ORDER BY bucket_key ASC",
        'i' . $timeMeta['types'],
        array_merge([$staffBranchId], $timeMeta['params'])
    ) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['bucket_key']] = (float)($row['total'] ?? 0);
    }
    for ($hour = 0; $hour < 24; $hour++) {
        $chartLabels[] = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
        $chartValues[] = (float)($map[$hour] ?? 0);
    }
} elseif ($timeframe === 'week' || $timeframe === 'month') {
    $rows = db_query(
        "SELECT DATE(o.order_date) AS bucket_key, COALESCE(SUM(o.total_amount), 0) AS total
         FROM orders o
         WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND o.status = 'Completed'
         GROUP BY DATE(o.order_date)
         ORDER BY bucket_key ASC",
        'i' . $timeMeta['types'],
        array_merge([$staffBranchId], $timeMeta['params'])
    ) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['bucket_key']] = (float)($row['total'] ?? 0);
    }
    if ($timeframe === 'week') {
        $baseTs = strtotime((string)$timeMeta['start']);
        for ($i = 0; $i < 7; $i++) {
            $dateKey = date('Y-m-d', strtotime("+$i day", $baseTs));
            $chartLabels[] = date('D', strtotime($dateKey));
            $chartValues[] = (float)($map[$dateKey] ?? 0);
        }
    } else {
        $daysInMonth = (int)date('t', strtotime((string)$timeMeta['start']));
        $monthPrefix = date('Y-m-', strtotime((string)$timeMeta['start']));
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateKey = $monthPrefix . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            $chartLabels[] = $day;
            $chartValues[] = (float)($map[$dateKey] ?? 0);
        }
    }
} else {
    $rows = db_query(
        "SELECT MONTH(o.order_date) AS bucket_key, COALESCE(SUM(o.total_amount), 0) AS total
         FROM orders o
         WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND o.status = 'Completed'
         GROUP BY MONTH(o.order_date)
         ORDER BY bucket_key ASC",
        'i' . $timeMeta['types'],
        array_merge([$staffBranchId], $timeMeta['params'])
    ) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['bucket_key']] = (float)($row['total'] ?? 0);
    }
    for ($month = 1; $month <= 12; $month++) {
        $chartLabels[] = date('M', mktime(0, 0, 0, $month, 1));
        $chartValues[] = (float)($map[$month] ?? 0);
    }
}

$topServices = db_query(
    "SELECT
        TRIM(REPLACE(REPLACE(REPLACE(COALESCE(jo.service_type, s.name, p.name, 'General'), ' Printing', ''), ' (Print/Cut)', ''), ' Print', '')) AS name,
        COUNT(DISTINCT oi.order_item_id) AS order_count
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.order_id
     LEFT JOIN job_orders jo ON oi.order_item_id = jo.order_item_id
     LEFT JOIN products p ON oi.product_id = p.product_id
     LEFT JOIN services s ON oi.product_id = s.service_id
     WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND {$statusMeta['sql']}
     GROUP BY name
     ORDER BY order_count DESC, name ASC
     LIMIT 10",
    'i' . $timeMeta['types'] . $statusMeta['types'],
    array_merge([$staffBranchId], $timeMeta['params'], $statusMeta['params'])
) ?: [];

$sqlCond = " WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$timeMeta['sql']} AND {$statusMeta['sql']}";
$params = array_merge([$staffBranchId], $timeMeta['params'], $statusMeta['params']);
$types = 'i' . $timeMeta['types'] . $statusMeta['types'];

if ($search_filter !== '') {
    $sqlCond .= " AND (CAST(o.order_id AS CHAR) LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)";
    $like = '%' . $search_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$rowCount = db_query(
    "SELECT COUNT(*) AS count
     FROM orders o
     LEFT JOIN customers c ON o.customer_id = c.customer_id" . $sqlCond,
    $types,
    $params
) ?: [];
$totalRows = (int)($rowCount[0]['count'] ?? 0);

$orders = db_query(
    "SELECT o.order_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            o.order_date,
            o.total_amount,
            o.status,
            (SELECT COALESCE(p.name, s.name, 'General')
             FROM order_items oi
             LEFT JOIN products p ON oi.product_id = p.product_id
             LEFT JOIN services s ON oi.product_id = s.service_id
             WHERE oi.order_id = o.order_id
             LIMIT 1) AS service_type
     FROM orders o
     LEFT JOIN customers c ON o.customer_id = c.customer_id
     {$sqlCond}
     ORDER BY o.order_date DESC
     LIMIT {$limit} OFFSET {$offset}",
    $types,
    $params
) ?: [];

$peso = '₱';
foreach ($orders as &$order) {
    $order['status_html'] = function_exists('status_badge') ? status_badge($order['status'], 'order') : $order['status'];
    $order['formatted_date'] = date('M d, Y', strtotime((string)$order['order_date']));
    $order['formatted_total'] = $peso . number_format((float)$order['total_amount'], 2);
    $order['manage_url'] = htmlspecialchars(printflow_staff_order_management_url((int)$order['order_id'], true), ENT_QUOTES, 'UTF-8');
}
unset($order);

$payload = [
    'success' => true,
    'stats' => [
        'formatted_revenue' => $peso . number_format($totalRevenue, 2),
        'product_orders' => $productOrdersCount,
        'custom_orders' => $customOrdersCount,
        'reviews' => $reviewsCount,
        'product_label' => $productLabel,
        'custom_label' => $customLabel,
        'review_label' => $reviewLabel,
        'total_orders' => $totalRows,
    ],
    'chart' => [
        'labels' => $chartLabels,
        'values' => $chartValues,
        'title' => $timeMeta['chart_title'],
    ],
    'top_services' => $topServices,
    'orders' => $orders,
    'timeframe_label' => $timeMeta['display_label'],
    'short_label' => $timeMeta['short_label'],
    'status_label' => $statusLabel,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => (int)ceil($totalRows / $limit),
        'total_rows' => $totalRows,
    ],
];

if ($__pf_debug_allowed) {
    $payload['debug'] = $payload['debug'] ?? [];
    $payload['debug']['schema'] = [
        'orders_has_order_type' => $hasOrderType,
        'orders_has_order_date' => function_exists('db_table_has_column') ? db_table_has_column('orders', 'order_date') : null,
        'orders_has_branch_id' => function_exists('db_table_has_column') ? db_table_has_column('orders', 'branch_id') : null,
        'staff_role' => $staffRole,
        'status_filter' => $status_filter,
        'timeframe' => $timeframe,
    ];
    if (function_exists('printflow_db_errors')) {
        $payload['debug']['db_errors'] = printflow_db_errors();
    }
}

$flags = JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
echo json_encode($payload, $flags);
exit;
