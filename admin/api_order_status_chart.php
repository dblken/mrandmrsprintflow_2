<?php
/**
 * API: Order Status Chart Data (Branch-Aware)
 * Returns order status breakdown for dashboard chart
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager']);

header('Content-Type: application/json');

try {
    $currentUser = get_logged_in_user();
    $isManager = (($currentUser['role'] ?? '') === 'Manager');

    // Managers are always locked to their assigned branch.
    if ($isManager) {
        $branchId = printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 'all');
    } else {
        $branchId = $_GET['branch_id'] ?? 'all';
        if ($branchId !== 'all') {
            $branchId = (int)$branchId;
        }
    }

    $fromInput = trim((string)($_GET['from'] ?? ''));
    $toInput = trim((string)($_GET['to'] ?? ''));
    $isValidDate = static function (string $date): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    };
    $fromDate = $isValidDate($fromInput) ? $fromInput : date('Y-m-d');
    $toDate = $isValidDate($toInput) ? $toInput : date('Y-m-d');
    if (strtotime($fromDate) > strtotime($toDate)) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    $fromStart = $fromDate . ' 00:00:00';
    $toEnd = $toDate . ' 23:59:59';

    // Build branch SQL filter
    [$bSqlFrag, $bTypes, $bParams] = branch_where_parts('o', $branchId);

    // Query order status breakdown
    $order_status = db_query(
        "SELECT o.status, COUNT(*) as cnt 
         FROM orders o 
         WHERE o.order_date BETWEEN ? AND ? {$bSqlFrag} 
         GROUP BY o.status 
         ORDER BY cnt DESC",
        'ss' . ($bTypes ?: ''),
        array_merge([$fromStart, $toEnd], $bParams ?: [])
    ) ?: [];

    // Status color mapping
    $statusColors = [
        'Pending' => '#F39C12',
        'Processing' => '#3498DB',
        'Ready for Pickup' => '#53C5E0',
        'Completed' => '#2ECC71',
        'Cancelled' => '#E74C3C',
        'Design Approved' => '#6C5CE7',
    ];

    // Prepare chart data
    $labels = [];
    $counts = [];
    $colors = [];

    foreach ($order_status as $status) {
        $labels[] = $status['status'];
        $counts[] = (int)$status['cnt'];
        $colors[] = $statusColors[$status['status']] ?? '#6B7C85';
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'counts' => $counts,
        'colors' => $colors
    ]);

} catch (Exception $e) {
    error_log("Order Status Chart API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load order status data',
        'message' => $e->getMessage()
    ]);
}
