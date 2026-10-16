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
require_once __DIR__ . '/../includes/pos_dashboard_kpi.php';
require_once __DIR__ . '/../includes/staff_status_filters.php';
require_once __DIR__ . '/../includes/staff_dashboard_revenue.php';
require_once __DIR__ . '/../includes/staff_dashboard_queries.php';
require_once __DIR__ . '/../includes/staff_dashboard_cache.php';
require_once __DIR__ . '/../includes/staff_dashboard_metrics_version.php';

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
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');

    return match ($timeframe) {
        'week' => (function () {
            $tz = new DateTimeZone('Asia/Manila');
            $now = new DateTimeImmutable('now', $tz);
            $weekStart = $now->modify('monday this week');
            $weekEnd = $now->modify('sunday this week');
            $start = $weekStart->format('Y-m-d');
            $end = $weekEnd->format('Y-m-d');
            $startDay = $weekStart->format('j');
            $endDay = $weekEnd->format('j');
            $startMonth = $weekStart->format('F');
            $endMonth = $weekEnd->format('F');
            $year = $weekEnd->format('Y');
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
        'month' => (function () use ($now) {
            $start = $now->modify('first day of this month')->format('Y-m-d');
            $end = $now->modify('last day of this month')->format('Y-m-d');
            return [
            'key' => 'month',
            'start' => $start,
            'end' => $end,
            'sql' => "DATE(o.order_date) BETWEEN ? AND ?",
            'sql_no_alias' => "DATE(order_date) BETWEEN ? AND ?",
            'types' => 'ss',
            'params' => [$start, $end],
            'display_label' => 'This Month (' . $now->format('F Y') . ')',
            'short_label' => 'This Month',
            'chart_title' => 'Sales Trend (This Month)',
            ];
        })(),
        'year' => [
            'key' => 'year',
            'start' => $now->setDate((int)$now->format('Y'), 1, 1)->format('Y-m-d'),
            'end' => $now->setDate((int)$now->format('Y'), 12, 31)->format('Y-m-d'),
            'sql' => "DATE(o.order_date) BETWEEN ? AND ?",
            'sql_no_alias' => "DATE(order_date) BETWEEN ? AND ?",
            'types' => 'ss',
            'params' => [$now->setDate((int)$now->format('Y'), 1, 1)->format('Y-m-d'), $now->setDate((int)$now->format('Y'), 12, 31)->format('Y-m-d')],
            'display_label' => 'This Year (' . $now->format('Y') . ')',
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
            'display_label' => 'Today (' . $now->format('F j, Y') . ')',
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
$staffOrderScopeSql .= ' AND ' . printflow_order_archive_scope_sql('o');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

printflow_staff_dashboard_ensure_indexes();

$hasOrderType = function_exists('db_table_has_column') ? db_table_has_column('orders', 'order_type') : true;
$hasOrderItemType = function_exists('db_table_has_column') ? db_table_has_column('order_items', 'item_type') : false;
$hasOrderItemServiceId = function_exists('db_table_has_column') ? db_table_has_column('order_items', 'service_id') : false;
$productJoinSql = $hasOrderItemType
    ? "JOIN products p ON oi.product_id = p.product_id AND COALESCE(NULLIF(oi.item_type, ''), 'product') = 'product'"
    : "JOIN products p ON oi.product_id = p.product_id";
$serviceJoinSql = $hasOrderItemServiceId
    ? 'LEFT JOIN services s ON oi.service_id = s.service_id'
    : 'LEFT JOIN services s ON oi.product_id = s.service_id';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = printflow_staff_list_page_size();
$offset = ($page - 1) * $limit;
$search_filter = trim((string)($_GET['search'] ?? ''));
$timeframe = strtolower(trim((string)($_GET['timeframe'] ?? 'today')));
if (!in_array($timeframe, ['today', 'week', 'month'], true)) {
    $timeframe = 'today';
}
$status_filter = printflow_staff_dashboard_normalize_status_filter((string)($_GET['status'] ?? ''), $staffRole);

$timeMeta = pf_dashboard_timeframe_meta($timeframe);
$revenueTimeBounds = printflow_staff_dashboard_time_bounds_meta($timeMeta);
$revenueEligible = printflow_staff_dashboard_should_compute_revenue($status_filter, $staffRole);
$onlineRevenueSql = $revenueEligible ? printflow_staff_online_dashboard_revenue_sql('o') : '0=1';
$chartRevenueSql = ($staffRole === 'pos')
    ? "o.status = 'Completed'"
    : $onlineRevenueSql;
$chartTimeSql = ($staffRole === 'pos') ? $timeMeta['sql'] : $revenueTimeBounds['sql'];
$chartTimeTypes = ($staffRole === 'pos') ? $timeMeta['types'] : $revenueTimeBounds['types'];
$chartTimeParams = ($staffRole === 'pos') ? $timeMeta['params'] : $revenueTimeBounds['params'];
$statusMeta = printflow_staff_dashboard_status_sql('o', $status_filter, $staffRole);
$statusLabels = printflow_staff_dashboard_status_labels($staffRole);
$statusLabel = $status_filter !== '' ? ($statusLabels[$status_filter] ?? $status_filter) : 'All';

$cacheKey = printflow_staff_dashboard_cache_key(
    $staffBranchId,
    $staffRole,
    'bundle',
    $timeframe,
    $status_filter,
    $page,
    (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')
);
if (empty($_GET['nocache']) && empty($search_filter)) {
    $cachedPayload = printflow_staff_dashboard_cache_get($cacheKey, 90);
    if (is_array($cachedPayload)) {
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo json_encode($cachedPayload, $flags);
        exit;
    }
}

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
     {$productJoinSql}
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
     {$serviceJoinSql}
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

$revenue = db_query(
    "SELECT COALESCE(SUM(o.total_amount), 0) AS total
     FROM orders o
     WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$revenueTimeBounds['sql']} AND {$onlineRevenueSql}",
    'i' . $revenueTimeBounds['types'],
    array_merge([$staffBranchId], $revenueTimeBounds['params'])
) ?: [];
$totalRevenue = (float)($revenue[0]['total'] ?? 0);

$chartLabels = [];
$chartValues = [];
if (!$revenueEligible && $staffRole !== 'pos') {
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
         WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$chartTimeSql} AND {$chartRevenueSql}
         GROUP BY HOUR(o.order_date)
         ORDER BY bucket_key ASC",
        'i' . $chartTimeTypes,
        array_merge([$staffBranchId], $chartTimeParams)
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
         WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$chartTimeSql} AND {$chartRevenueSql}
         GROUP BY DATE(o.order_date)
         ORDER BY bucket_key ASC",
        'i' . $chartTimeTypes,
        array_merge([$staffBranchId], $chartTimeParams)
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
         WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$chartTimeSql} AND {$chartRevenueSql}
         GROUP BY MONTH(o.order_date)
         ORDER BY bucket_key ASC",
        'i' . $chartTimeTypes,
        array_merge([$staffBranchId], $chartTimeParams)
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

$orderTimeBounds = printflow_staff_dashboard_time_bounds_meta($timeMeta);

$topServices = printflow_staff_dashboard_top_sales(
    $staffBranchId,
    $staffOrderScopeSql,
    $timeMeta,
    $statusMeta,
    10,
    $staffRole
);

$recentVisibilitySql = printflow_staff_recent_orders_visibility_sql('o', $staffRole);
$sqlCond = " WHERE o.branch_id = ? AND {$staffOrderScopeSql} AND {$orderTimeBounds['sql']} AND {$statusMeta['sql']} AND {$recentVisibilitySql}";
$params = array_merge([$staffBranchId], $orderTimeBounds['params'], $statusMeta['params']);
$types = 'i' . $orderTimeBounds['types'] . $statusMeta['types'];

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
            o.status
     FROM orders o
     LEFT JOIN customers c ON o.customer_id = c.customer_id
     {$sqlCond}
     ORDER BY o.order_date DESC
     LIMIT {$limit} OFFSET {$offset}",
    $types,
    $params
) ?: [];
if ($orders !== [] && function_exists('printflow_dashboard_order_service_labels')) {
    $dashboardServiceLabels = printflow_dashboard_order_service_labels(array_map(static function (array $row): int {
        return (int)($row['order_id'] ?? 0);
    }, $orders));
    foreach ($orders as &$dashboardOrderRow) {
        $oid = (int)($dashboardOrderRow['order_id'] ?? 0);
        $dashboardOrderRow['service_type'] = $dashboardServiceLabels[$oid] ?? 'General';
    }
    unset($dashboardOrderRow);
}

$peso = '₱';
foreach ($orders as &$order) {
    $order['status_html'] = function_exists('status_badge') ? status_badge($order['status'], 'order') : $order['status'];
    $order['formatted_date'] = date('M d, Y', strtotime((string)$order['order_date']));
    $order['formatted_total'] = $peso . number_format((float)$order['total_amount'], 2);
    $preferPendingLink = $staffRole !== 'pos';
    $order['manage_url'] = htmlspecialchars(
        printflow_staff_order_management_url((int)$order['order_id'], $preferPendingLink),
        ENT_QUOTES,
        'UTF-8'
    );
}
unset($order);

$stats = [
    'formatted_revenue' => $peso . number_format($totalRevenue, 2),
    'product_orders' => $productOrdersCount,
    'custom_orders' => $customOrdersCount,
    'reviews' => $reviewsCount,
    'product_label' => $productLabel,
    'custom_label' => $customLabel,
    'review_label' => $reviewLabel,
    'total_orders' => $totalRows,
];

if ($staffRole === 'pos') {
    $posTimeMeta = [
        'key' => $timeMeta['key'],
        'start' => $timeMeta['start'],
        'end' => $timeMeta['end'],
        'sql' => $timeMeta['sql'],
        'types' => $timeMeta['types'],
        'params' => $timeMeta['params'],
    ];
    $posMetrics = printflow_pos_dashboard_kpi_metrics($staffBranchId, $staffOrderScopeSql, $posTimeMeta);
    $posLinks = printflow_pos_dashboard_kpi_links(
        $timeMeta['key'],
        (string)$timeMeta['start'],
        (string)$timeMeta['end']
    );
    $stats['pos_kpi'] = true;
    $stats['pos_revenue'] = $posMetrics['formatted_revenue'];
    $stats['pos_revenue_label'] = $posMetrics['revenue_label'];
    $stats['pos_revenue_subtitle'] = $posMetrics['revenue_subtitle'];
    $stats['pos_walk_in_subtitle'] = $posMetrics['walk_in_subtitle'];
    $stats['pos_completed_subtitle'] = $posMetrics['completed_subtitle'];
    $stats['pos_walk_in_orders'] = $posMetrics['walk_in_orders'];
    $stats['pos_pending_customizations'] = $posMetrics['pending_customizations'];
    $stats['pos_completed_transactions'] = $posMetrics['completed_transactions'];
    $stats['pos_links'] = $posLinks;
}

$payload = [
    'success' => true,
    'metrics_version' => PRINTFLOW_STAFF_DASHBOARD_METRICS_VERSION,
    '_branch_id' => $staffBranchId,
    'stats' => $stats,
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
        'html' => printflow_staff_dashboard_ajax_pagination_html(
            $page,
            (int)ceil($totalRows / $limit)
        ),
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
    if ($staffRole !== 'pos') {
        $payload['debug']['revenue'] = printflow_staff_dashboard_revenue_debug(
            $staffBranchId,
            $staffOrderScopeSql,
            $timeMeta,
            $status_filter,
            $staffRole,
            $totalRevenue
        );
    }
    $payload['debug']['top_sales'] = printflow_staff_dashboard_top_sales_debug(
        $staffBranchId,
        $staffOrderScopeSql,
        $timeMeta,
        $statusMeta,
        $staffRole
    );
    if (function_exists('printflow_db_errors')) {
        $payload['debug']['db_errors'] = printflow_db_errors();
    }
}

if (empty($_GET['nocache']) && empty($search_filter)) {
    printflow_staff_dashboard_cache_set($cacheKey, $payload);
}

$clientPayload = $payload;
unset($clientPayload['_branch_id']);

$flags = JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
echo json_encode($clientPayload, $flags);
exit;
