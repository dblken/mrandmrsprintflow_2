<?php
/**
 * Staff Reports CSV Export
 * Path: staff/api/reports_export.php
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';

require_role('Staff');

$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);

$report = $_GET['report'] ?? 'daily_sales';
$date   = $_GET['date'] ?? date('Y-m-d');
$date   = date('Y-m-d', strtotime($date));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="printflow_staff_' . $report . '_' . $date . '.csv"');

$output = fopen('php://output', 'w');

// Header
fputcsv($output, ['PRINTFLOW - STAFF REPORT']);
fputcsv($output, [strtoupper(str_replace('_', ' ', $report))]);
fputcsv($output, ['Date: ' . date('M d, Y', strtotime($date))]);
fputcsv($output, ['Generated: ' . date('M d, Y h:i A')]);
fputcsv($output, []);

if ($report === 'daily_sales') {
    // Standard Orders
    fputcsv($output, ['STANDARD ORDERS']);
    fputcsv($output, ['Order #', 'Customer', 'Time', 'Amount', 'Status', 'Payment']);
    
    $orders = db_query("
        SELECT o.order_id, CONCAT(c.first_name, ' ', c.last_name) as customer_name,
               o.order_date, o.total_amount, o.status, o.payment_status
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        WHERE DATE(o.order_date) = ? AND o.branch_id = ? AND " . printflow_order_archive_scope_sql('o') . "
        ORDER BY o.order_date ASC
    ", 'si', [$date, $staffBranchId]);

    $total_sales = 0;
    if ($orders) {
        foreach ($orders as $o) {
            fputcsv($output, [
                '#' . $o['order_id'],
                $o['customer_name'] ?? 'Walk-in',
                date('h:i A', strtotime($o['order_date'])),
                number_format((float)$o['total_amount'], 2),
                $o['status'],
                $o['payment_status']
            ]);
            if ($o['payment_status'] === 'Paid') {
                $total_sales += (float)$o['total_amount'];
            }
        }
    } else {
        fputcsv($output, ['No standard orders found.']);
    }
    fputcsv($output, []);

    // Service Orders
    fputcsv($output, ['SERVICE ORDERS']);
    fputcsv($output, ['Order #', 'Service', 'Customer', 'Time', 'Amount', 'Status']);
    
    $s_orders = db_query("
        SELECT so.id, so.service_name, CONCAT(c.first_name, ' ', c.last_name) as customer_name,
               so.created_at, so.total_price, so.status
        FROM service_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        WHERE DATE(so.created_at) = ? AND so.branch_id = ?
        ORDER BY so.created_at ASC
    ", 'si', [$date, $staffBranchId]);

    if ($s_orders) {
        foreach ($s_orders as $so) {
            fputcsv($output, [
                '#' . $so['id'],
                $so['service_name'],
                $so['customer_name'] ?? 'N/A',
                date('h:i A', strtotime($so['created_at'])),
                number_format((float)$so['total_price'], 2),
                $so['status']
            ]);
            if ($so['status'] === 'Completed') {
                $total_sales += (float)$so['total_price'];
            }
        }
    } else {
        fputcsv($output, ['No service orders found.']);
    }
    fputcsv($output, []);
    fputcsv($output, ['', '', 'TOTAL PAID REVENUE:', number_format($total_sales, 2)]);

} elseif ($report === 'inventory') {
    fputcsv($output, ['PRODUCT INVENTORY']);
    fputcsv($output, ['Product', 'SKU', 'Category', 'Stock', 'Price', 'Status']);
    
    $products = db_query("
        SELECT p.name, p.sku, p.category, COALESCE(pbs.stock_quantity, 0) AS stock_quantity, p.price, p.status
        FROM products p
        LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
        WHERE p.status = 'Activated'
        ORDER BY p.category, p.name
    ", 'i', [$staffBranchId]);
    foreach ($products as $p) {
        $stock_status = ($p['stock_quantity'] <= 0) ? 'OUT OF STOCK' : (($p['stock_quantity'] < 20) ? 'LOW STOCK' : 'In Stock');
        fputcsv($output, [
            $p['name'],
            $p['sku'],
            $p['category'],
            $p['stock_quantity'],
            number_format((float)$p['price'], 2),
            $stock_status
        ]);
    }
    fputcsv($output, []);

    fputcsv($output, ['RAW MATERIALS (INV ITEMS)']);
    fputcsv($output, ['Item Name', 'Category', 'Stock', 'UOM', 'Roll-based']);
    
    $inv_items = db_query("
        SELECT i.name, ic.name as category_name, i.unit_of_measure, i.track_by_roll,
               (SELECT SUM(IF(direction='IN', quantity, -quantity)) FROM inventory_transactions WHERE item_id = i.id AND branch_id = ?) as current_stock
        FROM inv_items i
        LEFT JOIN inv_categories ic ON i.category_id = ic.id
        ORDER BY ic.name, i.name
    ", 'i', [$staffBranchId]);
    
    if ($inv_items) {
        foreach ($inv_items as $i) {
            fputcsv($output, [
                $i['name'],
                $i['category_name'],
                number_format((float)$i['current_stock'], 2),
                $i['unit_of_measure'],
                $i['track_by_roll'] ? 'Yes' : 'No'
            ]);
        }
    }
}

fclose($output);
exit;
