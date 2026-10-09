<?php
/**
 * AJAX: Revenue Chart Data
 * Returns JSON: { labels, revenue_store, revenue_custom, revenue, orders }
 *   revenue = store + customization; orders = store + job rows (total count)
 * ?period=today|weekly|monthly|6months|yearly
 * ?year=YYYY  (optional, for monthly/6months/yearly)
 * ?month=M    (optional, 1-12, for monthly)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/branch_context.php';

header('Content-Type: application/json');

$emptyPayload = [
    'labels' => [],
    'revenue_store' => [],
    'revenue_custom' => [],
    'revenue' => [],
    'orders' => [],
];

// Allow Admin and Manager. Managers are hard-locked to their assigned branch.
$user_type = (string)($_SESSION['user_type'] ?? '');
if (!in_array($user_type, ['Admin', 'Manager'], true)) {
    echo json_encode(['error' => 'Unauthorized'] + $emptyPayload);
    exit;
}

$period = $_GET['period'] ?? 'monthly';
$year   = max(2020, min(2030, (int)($_GET['year'] ?? date('Y'))));
$month  = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$from_raw = trim($_GET['from'] ?? '');
$to_raw   = trim($_GET['to']   ?? '');
$use_range = ($from_raw !== '' || $to_raw !== '');

// Branch filter:
// - Admin can request "all" or a specific branch_id.
// - Manager is always forced to their assigned branch.
if ($user_type === 'Manager') {
    $branch_int = (int)(printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 0));
    if ($branch_int <= 0) {
        echo json_encode(['error' => 'Unauthorized branch'] + $emptyPayload);
        exit;
    }
} else {
    $branch_raw = $_GET['branch_id'] ?? 'all';
    $branch_int = ($branch_raw !== 'all' && ctype_digit((string)$branch_raw)) ? (int)$branch_raw : null;
}
$oFilter = ' AND ' . printflow_order_archive_scope_sql('orders') . ($branch_int ? " AND branch_id = $branch_int" : '');
$jFilter = ' AND ' . printflow_order_archive_exclusion_sql('job_orders.order_id') . ($branch_int ? " AND branch_id = $branch_int" : '');

try {
    // ── Date-range mode (global filter) ──────────────────────────────────────
    if ($use_range) {
        $from_dt = $from_raw !== '' ? $from_raw : date('Y-m-d', strtotime('-30 days'));
        $to_dt   = $to_raw   !== '' ? $to_raw   : date('Y-m-d');
        $to_end  = $to_dt . ' 23:59:59';
        $days_diff = (int)((strtotime($to_dt) - strtotime($from_dt)) / 86400) + 1;
        $group_monthly = $days_diff > 60;

        if ($group_monthly) {
            $sR = db_query(
                "SELECT DATE_FORMAT(order_date,'%Y-%m') AS gkey,
                        DATE_FORMAT(order_date,'%b %Y') AS label,
                        COALESCE(SUM(total_amount),0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status='Paid' OR status='Completed')
                   AND order_date BETWEEN ? AND ? $oFilter
                 GROUP BY DATE_FORMAT(order_date,'%Y-%m') ORDER BY gkey",
                'ss', [$from_dt, $to_end]
            ) ?: [];
            $jR = db_query(
                "SELECT DATE_FORMAT(ts,'%Y-%m') AS gkey,
                        DATE_FORMAT(ts,'%b %Y') AS label,
                        COALESCE(SUM(amt),0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (SELECT COALESCE(payment_verified_at,created_at) AS ts,
                              COALESCE(NULLIF(amount_paid,0),estimated_total,0) AS amt
                       FROM job_orders
                       WHERE (payment_status='PAID' OR status='COMPLETED')
                         AND COALESCE(payment_verified_at,created_at) BETWEEN ? AND ? $jFilter) t
                 GROUP BY DATE_FORMAT(ts,'%Y-%m') ORDER BY gkey",
                'ss', [$from_dt, $to_end]
            ) ?: [];
            $sMap = []; foreach ($sR as $r) $sMap[$r['gkey']] = $r;
            $jMap = []; foreach ($jR as $r) $jMap[$r['gkey']] = $r;
            $rows = [];
            $cur = strtotime(date('Y-m-01', strtotime($from_dt)));
            $end = strtotime(date('Y-m-01', strtotime($to_dt)));
            while ($cur <= $end) {
                $k = date('Y-m', $cur);
                $rows[] = _pf_merge_store_job_row(date('M Y', $cur), $sMap[$k] ?? null, $jMap[$k] ?? null);
                $cur = strtotime('+1 month', $cur);
            }
        } else {
            $sR = db_query(
                "SELECT DATE(order_date) AS dkey,
                        DATE_FORMAT(order_date,'%b %e') AS label,
                        COALESCE(SUM(total_amount),0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status='Paid' OR status='Completed')
                   AND order_date BETWEEN ? AND ? $oFilter
                 GROUP BY DATE(order_date) ORDER BY dkey",
                'ss', [$from_dt, $to_end]
            ) ?: [];
            $jR = db_query(
                "SELECT DATE(ts) AS dkey,
                        DATE_FORMAT(ts,'%b %e') AS label,
                        COALESCE(SUM(amt),0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (SELECT COALESCE(payment_verified_at,created_at) AS ts,
                              COALESCE(NULLIF(amount_paid,0),estimated_total,0) AS amt
                       FROM job_orders
                       WHERE (payment_status='PAID' OR status='COMPLETED')
                         AND COALESCE(payment_verified_at,created_at) BETWEEN ? AND ? $jFilter) t
                 GROUP BY DATE(ts) ORDER BY dkey",
                'ss', [$from_dt, $to_end]
            ) ?: [];
            $sMap = []; foreach ($sR as $r) $sMap[$r['dkey']] = $r;
            $jMap = []; foreach ($jR as $r) $jMap[$r['dkey']] = $r;
            $rows = [];
            $cur = strtotime($from_dt);
            $end = strtotime($to_dt);
            while ($cur <= $end) {
                $dkey = date('Y-m-d', $cur);
                $rows[] = _pf_merge_store_job_row(date('M j', $cur), $sMap[$dkey] ?? null, $jMap[$dkey] ?? null);
                $cur = strtotime('+1 day', $cur);
            }
        }
        echo json_encode([
            'labels'         => array_map(fn($r) => $r['label'], $rows),
            'revenue_store'  => array_map(fn($r) => (float)$r['revenue_store'], $rows),
            'revenue_custom' => array_map(fn($r) => (float)$r['revenue_custom'], $rows),
            'revenue'        => array_map(fn($r) => (float)$r['revenue'], $rows),
            'orders'         => array_map(fn($r) => (int)$r['orders'], $rows),
        ]);
        exit;
    }

    switch ($period) {
        case 'today':
            $storeRows = db_query(
                "SELECT DATE_FORMAT(order_date, '%H:00') AS label,
                        COALESCE(SUM(total_amount), 0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status = 'Paid' OR status = 'Completed')
                   AND DATE(order_date) = CURDATE()
                   $oFilter
                 GROUP BY HOUR(order_date), DATE_FORMAT(order_date, '%H:00')
                 ORDER BY HOUR(order_date)"
            ) ?: [];
            $jobRows = db_query(
                "SELECT DATE_FORMAT(ts, '%H:00') AS label,
                        COALESCE(SUM(amt), 0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (
                     SELECT COALESCE(payment_verified_at, created_at) AS ts,
                            COALESCE(NULLIF(amount_paid, 0), estimated_total, 0) AS amt
                     FROM job_orders
                     WHERE (payment_status = 'PAID' OR status = 'COMPLETED')
                       AND DATE(COALESCE(payment_verified_at, created_at)) = CURDATE()
                       $jFilter
                 ) t
                 GROUP BY HOUR(ts), DATE_FORMAT(ts, '%H:00')
                 ORDER BY HOUR(ts)"
            ) ?: [];
            $rows = _pf_build_hourly_today($storeRows, $jobRows);
            break;

        case 'weekly':
            $storeRows = db_query(
                "SELECT DATE(order_date) AS dkey,
                        COALESCE(SUM(total_amount), 0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status = 'Paid' OR status = 'Completed')
                   AND DATE(order_date) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                   AND DATE(order_date) <= CURDATE()
                   $oFilter
                 GROUP BY DATE(order_date)
                 ORDER BY dkey"
            ) ?: [];
            $jobRows = db_query(
                "SELECT DATE(ts) AS dkey,
                        COALESCE(SUM(amt), 0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (
                     SELECT COALESCE(payment_verified_at, created_at) AS ts,
                            COALESCE(NULLIF(amount_paid, 0), estimated_total, 0) AS amt
                     FROM job_orders
                     WHERE (payment_status = 'PAID' OR status = 'COMPLETED')
                       AND DATE(COALESCE(payment_verified_at, created_at)) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                       AND DATE(COALESCE(payment_verified_at, created_at)) <= CURDATE()
                       $jFilter
                 ) t
                 GROUP BY DATE(ts)
                 ORDER BY dkey"
            ) ?: [];
            $rows = _pf_build_weekly($storeRows, $jobRows);
            break;

        case 'monthly':
            $storeRows = db_query(
                "SELECT DAY(order_date) AS dom,
                        COALESCE(SUM(total_amount), 0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status = 'Paid' OR status = 'Completed')
                   AND MONTH(order_date) = $month AND YEAR(order_date) = $year
                   $oFilter
                 GROUP BY DAY(order_date)
                 ORDER BY dom"
            ) ?: [];
            $jobRows = db_query(
                "SELECT DAY(ts) AS dom,
                        COALESCE(SUM(amt), 0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (
                     SELECT COALESCE(payment_verified_at, created_at) AS ts,
                            COALESCE(NULLIF(amount_paid, 0), estimated_total, 0) AS amt
                     FROM job_orders
                     WHERE (payment_status = 'PAID' OR status = 'COMPLETED')
                       AND MONTH(COALESCE(payment_verified_at, created_at)) = $month
                       AND YEAR(COALESCE(payment_verified_at, created_at)) = $year
                       $jFilter
                 ) t
                 GROUP BY DAY(ts)
                 ORDER BY dom"
            ) ?: [];
            $rows = _pf_build_monthly($storeRows, $jobRows, $year, $month);
            break;

        case '6months':
            $storeRows = db_query(
                "SELECT YEAR(order_date) AS y, MONTH(order_date) AS m,
                        DATE_FORMAT(order_date, '%b %Y') AS label,
                        COALESCE(SUM(total_amount), 0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status = 'Paid' OR status = 'Completed')
                   AND order_date >= DATE_SUB(CONCAT($year,'-',$month,'-01'), INTERVAL 5 MONTH)
                   AND order_date <= LAST_DAY(CONCAT($year,'-',$month,'-01'))
                   $oFilter
                 GROUP BY YEAR(order_date), MONTH(order_date), DATE_FORMAT(order_date, '%b %Y')
                 ORDER BY y, m"
            ) ?: [];
            $jobRows = db_query(
                "SELECT YEAR(ts) AS y, MONTH(ts) AS m,
                        DATE_FORMAT(ts, '%b %Y') AS label,
                        COALESCE(SUM(amt), 0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (
                     SELECT COALESCE(payment_verified_at, created_at) AS ts,
                            COALESCE(NULLIF(amount_paid, 0), estimated_total, 0) AS amt
                     FROM job_orders
                     WHERE (payment_status = 'PAID' OR status = 'COMPLETED')
                       AND COALESCE(payment_verified_at, created_at) >= DATE_SUB(CONCAT($year,'-',$month,'-01'), INTERVAL 5 MONTH)
                       AND COALESCE(payment_verified_at, created_at) <= LAST_DAY(CONCAT($year,'-',$month,'-01'))
                       $jFilter
                 ) t
                 GROUP BY YEAR(ts), MONTH(ts), DATE_FORMAT(ts, '%b %Y')
                 ORDER BY y, m"
            ) ?: [];
            $rows = _pf_build_6months($storeRows, $jobRows, $year, $month);
            break;

        case 'yearly':
            $storeRows = db_query(
                "SELECT MONTH(order_date) AS m, DATE_FORMAT(order_date, '%b') AS label,
                        COALESCE(SUM(total_amount), 0) AS revenue_store,
                        COUNT(*) AS orders_store
                 FROM orders
                 WHERE (payment_status = 'Paid' OR status = 'Completed')
                   AND YEAR(order_date) = $year
                   $oFilter
                 GROUP BY MONTH(order_date), DATE_FORMAT(order_date, '%b')
                 ORDER BY m"
            ) ?: [];
            $jobRows = db_query(
                "SELECT MONTH(ts) AS m, DATE_FORMAT(ts, '%b') AS label,
                        COALESCE(SUM(amt), 0) AS revenue_custom,
                        COUNT(*) AS orders_custom
                 FROM (
                     SELECT COALESCE(payment_verified_at, created_at) AS ts,
                            COALESCE(NULLIF(amount_paid, 0), estimated_total, 0) AS amt
                     FROM job_orders
                     WHERE (payment_status = 'PAID' OR status = 'COMPLETED')
                       AND YEAR(COALESCE(payment_verified_at, created_at)) = $year
                       $jFilter
                 ) t
                 GROUP BY MONTH(ts), DATE_FORMAT(ts, '%b')
                 ORDER BY m"
            ) ?: [];
            $rows = _pf_build_yearly($storeRows, $jobRows, $year);
            break;

        default:
            echo json_encode(['error' => 'Invalid period'] + $emptyPayload);
            exit;
    }

    echo json_encode([
        'labels' => array_map(fn($r) => $r['label'], $rows),
        'revenue_store' => array_map(fn($r) => (float)$r['revenue_store'], $rows),
        'revenue_custom' => array_map(fn($r) => (float)$r['revenue_custom'], $rows),
        'revenue' => array_map(fn($r) => (float)$r['revenue'], $rows),
        'orders' => array_map(fn($r) => (int)$r['orders'], $rows),
    ]);
} catch (Throwable $e) {
    error_log('api_revenue_chart error: ' . $e->getMessage());
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
    ] + $emptyPayload);
}

/**
 * @param array<int, array<string, mixed>> $storeRows
 * @param array<int, array<string, mixed>> $jobRows
 * @return list<array{label:string,revenue_store:float,revenue_custom:float,revenue:float,orders:int}>
 */
function _pf_build_hourly_today(array $storeRows, array $jobRows): array
{
    $sMap = [];
    foreach ($storeRows as $r) {
        $sMap[$r['label']] = $r;
    }
    $jMap = [];
    foreach ($jobRows as $r) {
        $jMap[$r['label']] = $r;
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $lbl = sprintf('%02d:00', $h);
        $s = $sMap[$lbl] ?? null;
        $j = $jMap[$lbl] ?? null;
        $out[] = _pf_merge_store_job_row($lbl, $s, $j);
    }
    return $out;
}

/**
 * @param array<int, array<string, mixed>> $storeRows  rows with dkey (Y-m-d)
 * @param array<int, array<string, mixed>> $jobRows
 */
function _pf_build_weekly(array $storeRows, array $jobRows): array
{
    $sMap = [];
    foreach ($storeRows as $r) {
        $sMap[$r['dkey']] = $r;
    }
    $jMap = [];
    foreach ($jobRows as $r) {
        $jMap[$r['dkey']] = $r;
    }
    $out = [];
    for ($i = 0; $i < 7; $i++) {
        $ts = strtotime('today - ' . (6 - $i) . ' days');
        $dkey = date('Y-m-d', $ts);
        $lbl = date('D', $ts) . ' ' . date('d', $ts);
        $out[] = _pf_merge_store_job_row($lbl, $sMap[$dkey] ?? null, $jMap[$dkey] ?? null);
    }
    return $out;
}

/**
 * @param array<int, array<string, mixed>> $storeRows  rows with dom (1-31)
 * @param array<int, array<string, mixed>> $jobRows
 */
function _pf_build_monthly(array $storeRows, array $jobRows, int $year, int $month): array
{
    $sMap = [];
    foreach ($storeRows as $r) {
        $sMap[(int)$r['dom']] = $r;
    }
    $jMap = [];
    foreach ($jobRows as $r) {
        $jMap[(int)$r['dom']] = $r;
    }
    $days = (int)date('t', strtotime("$year-$month-01"));
    $out = [];
    for ($d = 1; $d <= $days; $d++) {
        $lbl = date('M j', strtotime("$year-$month-$d"));
        $out[] = _pf_merge_store_job_row($lbl, $sMap[$d] ?? null, $jMap[$d] ?? null);
    }
    return $out;
}

/**
 * @param array<int, array<string, mixed>> $storeRows  y, m, label
 */
function _pf_build_6months(array $storeRows, array $jobRows, int $year, int $month): array
{
    $sMap = [];
    foreach ($storeRows as $r) {
        $sMap[(int)$r['y'] . '-' . (int)$r['m']] = $r;
    }
    $jMap = [];
    foreach ($jobRows as $r) {
        $jMap[(int)$r['y'] . '-' . (int)$r['m']] = $r;
    }
    $out = [];
    $dt = strtotime("$year-$month-01 -5 months");
    for ($i = 0; $i < 6; $i++) {
        $m = (int)date('n', $dt);
        $y = (int)date('Y', $dt);
        $key = "$y-$m";
        $lbl = date('M Y', $dt);
        $out[] = _pf_merge_store_job_row($lbl, $sMap[$key] ?? null, $jMap[$key] ?? null);
        $dt = strtotime('+1 month', $dt);
    }
    return $out;
}

/**
 * @param array<int, array<string, mixed>> $storeRows  m, label
 */
function _pf_build_yearly(array $storeRows, array $jobRows, int $year): array
{
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $sMap = [];
    foreach ($storeRows as $r) {
        $sMap[(int)$r['m']] = $r;
    }
    $jMap = [];
    foreach ($jobRows as $r) {
        $jMap[(int)$r['m']] = $r;
    }
    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $lbl = $months[$m - 1];
        $out[] = _pf_merge_store_job_row($lbl, $sMap[$m] ?? null, $jMap[$m] ?? null);
    }
    return $out;
}

/**
 * @param array<string, mixed>|null $s
 * @param array<string, mixed>|null $j
 * @return array{label:string,revenue_store:float,revenue_custom:float,revenue:float,orders:int}
 */
function _pf_merge_store_job_row(string $label, ?array $s, ?array $j): array
{
    $rs = (float)($s['revenue_store'] ?? 0);
    $os = (int)($s['orders_store'] ?? 0);
    $rc = (float)($j['revenue_custom'] ?? 0);
    $oc = (int)($j['orders_custom'] ?? 0);
    return [
        'label' => $label,
        'revenue_store' => $rs,
        'revenue_custom' => $rc,
        'revenue' => $rs + $rc,
        'orders' => $os + $oc,
    ];
}
