<?php
/**
 * API: Customization Usage Chart Data
 * Returns data for customization usage analytics
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager']);

header('Content-Type: application/json');

try {
    $currentUser = get_logged_in_user();
    $isManager = (($currentUser['role'] ?? '') === 'Manager');
    $branchId = $isManager
        ? (printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 'all'))
        : ($_GET['branch_id'] ?? 'all');
    $period = $_GET['period'] ?? 'monthly';
    
    // Build branch filter
    $branchSql = ' AND ' . printflow_order_archive_exclusion_sql('jo.order_id');
    $branchTypes = '';
    $branchParams = [];
    
    if ($branchId !== 'all' && is_numeric($branchId)) {
        $branchSql .= ' AND COALESCE(jo.branch_id, (SELECT ord.branch_id FROM orders ord WHERE ord.order_id = jo.order_id LIMIT 1)) = ?';
        $branchTypes = 'i';
        $branchParams = [(int)$branchId];
    }
    
    $labels = [];
    $data = [];
    
    switch ($period) {
        case 'daily':
            // Last 30 days
            $sql = "SELECT DATE(jo.created_at) as period_key,
                           COUNT(*) as total_orders,
                           COUNT(CASE WHEN jo.status = 'COMPLETED' THEN 1 END) as completed_orders,
                           SUM(CASE WHEN jo.payment_status = 'PAID' THEN COALESCE(jo.amount_paid, jo.estimated_total, 0) ELSE 0 END) as revenue
                    FROM job_orders jo 
                    WHERE jo.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    {$branchSql}
                    GROUP BY DATE(jo.created_at) 
                    ORDER BY period_key";
            
            $results = db_query($sql, $branchTypes ?: null, $branchParams ?: null) ?: [];
            
            // Fill in missing dates
            for ($i = 29; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $labels[] = date('M j', strtotime($date));
                
                $found = false;
                foreach ($results as $row) {
                    if ($row['period_key'] === $date) {
                        $data[] = [
                            'total' => (int)$row['total_orders'],
                            'completed' => (int)$row['completed_orders'],
                            'revenue' => (float)$row['revenue']
                        ];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $data[] = ['total' => 0, 'completed' => 0, 'revenue' => 0];
                }
            }
            break;
            
        case 'weekly':
            // Last 12 weeks
            $sql = "SELECT YEARWEEK(jo.created_at, 1) as period_key,
                           COUNT(*) as total_orders,
                           COUNT(CASE WHEN jo.status = 'COMPLETED' THEN 1 END) as completed_orders,
                           SUM(CASE WHEN jo.payment_status = 'PAID' THEN COALESCE(jo.amount_paid, jo.estimated_total, 0) ELSE 0 END) as revenue
                    FROM job_orders jo 
                    WHERE jo.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
                    {$branchSql}
                    GROUP BY YEARWEEK(jo.created_at, 1) 
                    ORDER BY period_key";
            
            $results = db_query($sql, $branchTypes ?: null, $branchParams ?: null) ?: [];
            
            // Fill in missing weeks
            for ($i = 11; $i >= 0; $i--) {
                $weekStart = date('Y-m-d', strtotime("-$i weeks", strtotime('monday this week')));
                $yearWeek = date('oW', strtotime($weekStart));
                $labels[] = 'Week ' . date('W', strtotime($weekStart));
                
                $found = false;
                foreach ($results as $row) {
                    if ($row['period_key'] == $yearWeek) {
                        $data[] = [
                            'total' => (int)$row['total_orders'],
                            'completed' => (int)$row['completed_orders'],
                            'revenue' => (float)$row['revenue']
                        ];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $data[] = ['total' => 0, 'completed' => 0, 'revenue' => 0];
                }
            }
            break;
            
        case 'monthly':
        default:
            // Last 12 months
            $sql = "SELECT DATE_FORMAT(jo.created_at, '%Y-%m') as period_key,
                           COUNT(*) as total_orders,
                           COUNT(CASE WHEN jo.status = 'COMPLETED' THEN 1 END) as completed_orders,
                           SUM(CASE WHEN jo.payment_status = 'PAID' THEN COALESCE(jo.amount_paid, jo.estimated_total, 0) ELSE 0 END) as revenue
                    FROM job_orders jo 
                    WHERE jo.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    {$branchSql}
                    GROUP BY DATE_FORMAT(jo.created_at, '%Y-%m') 
                    ORDER BY period_key";
            
            $results = db_query($sql, $branchTypes ?: null, $branchParams ?: null) ?: [];
            
            // Fill in missing months
            for ($i = 11; $i >= 0; $i--) {
                $date = date('Y-m', strtotime("-$i months"));
                $labels[] = date('M Y', strtotime($date . '-01'));
                
                $found = false;
                foreach ($results as $row) {
                    if ($row['period_key'] === $date) {
                        $data[] = [
                            'total' => (int)$row['total_orders'],
                            'completed' => (int)$row['completed_orders'],
                            'revenue' => (float)$row['revenue']
                        ];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $data[] = ['total' => 0, 'completed' => 0, 'revenue' => 0];
                }
            }
            break;
    }
    
    // Get service type breakdown for current period
    $serviceTypes = [];
    $serviceTypeSql = "SELECT jo.service_type, COUNT(*) as count
                       FROM job_orders jo 
                       WHERE jo.created_at >= " . 
                       ($period === 'daily' ? "DATE_SUB(CURDATE(), INTERVAL 30 DAY)" : 
                        ($period === 'weekly' ? "DATE_SUB(CURDATE(), INTERVAL 12 WEEK)" : 
                         "DATE_SUB(CURDATE(), INTERVAL 12 MONTH)")) . "
                       {$branchSql}
                       GROUP BY jo.service_type 
                       ORDER BY count DESC 
                       LIMIT 8";
    
    $serviceResults = db_query($serviceTypeSql, $branchTypes ?: null, $branchParams ?: null) ?: [];
    foreach ($serviceResults as $row) {
        $serviceTypes[] = [
            'name' => $row['service_type'],
            'count' => (int)$row['count']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'data' => $data,
        'serviceTypes' => $serviceTypes,
        'period' => $period
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load customization usage data',
        'message' => $e->getMessage()
    ]);
}
