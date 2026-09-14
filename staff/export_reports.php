<?php
/**
 * Export Reports to Excel
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/staff_access.php';
require_once __DIR__ . '/../includes/staff_status_filters.php';

// Requires Staff, Manager, or Admin role
$userType = $_SESSION['user_type'] ?? '';
if (!in_array($userType, ['Staff', 'Admin', 'Manager'], true)) {
    die("Unauthorized access.");
}

$staffBranchId = null;
$staffRole = 'online';
$staffOrderScopeSql = '1=1';
if (in_array($userType, ['Staff', 'Manager'], true)) {
    $staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
    $staffRole = printflow_get_staff_access_role();
    $staffOrderScopeSql = printflow_staff_order_source_sql('o', $staffRole);
}

// Load PhpSpreadsheet via Composer Autoloader
// Assuming it's in the root /vendor folder
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // Fallback search if vendor isn't in root
    $vendor_path = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendor_path)) {
        die("PhpSpreadsheet library not found. Please run 'composer install'.");
    }
    require_once $vendor_path;
}

// Check if Spreadsheet classes are available before use
if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    die("PhpSpreadsheet classes not found. Make sure vendor is populated.");
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// 1. Determine Filters (Matches reports.php logic)
$range = $_GET['range'] ?? 'week';
$status_filter = printflow_staff_normalize_status_filter((string)($_GET['status'] ?? 'ALL'), $staffRole);
$ordersStatusMeta = printflow_staff_orders_status_clause($status_filter, 'o', $staffRole);
$status_where_sql = ($ordersStatusMeta['sql'] !== '1=1') ? ' AND ' . $ordersStatusMeta['sql'] : '';
$status_params = $ordersStatusMeta['params'];
$status_types = $ordersStatusMeta['types'];

if ($range === 'year') {
    $interval_sql = '11 MONTH';
    $start_date = date('Y-m-d', strtotime('-11 months'));
} elseif ($range === 'month') {
    $interval_sql = '29 DAY';
    $start_date = date('Y-m-d', strtotime('-29 days'));
} else {
    $range = 'week';
    $interval_sql = '6 DAY';
    $start_date = date('Y-m-d', strtotime('-6 days'));
}

$end_date = date('Y-m-d');

// 2. Build Query - Concatenate first_name and last_name
$sql = "
    SELECT 
        o.order_id, 
        CONCAT(c.first_name, ' ', c.last_name) as customer_name,
        (SELECT COALESCE(p.name, 'Custom Service') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id LIMIT 1) as service_type,
        o.order_date, 
        o.total_amount, 
        o.status
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL $interval_sql)
    AND {$staffOrderScopeSql}
";

$params = [];
$types = '';

if ($staffBranchId !== null) {
    $sql .= " AND o.branch_id = ?";
    $params[] = $staffBranchId;
    $types .= 'i';
}

if ($status_where_sql !== '') {
    $sql .= $status_where_sql;
    if ($status_params !== []) {
        $params = array_merge($params, $status_params);
        $types .= $status_types;
    }
}

$sql .= " ORDER BY o.order_date DESC";

$orders = db_query($sql, $types, $params);

// 3. Create Spreadsheet
try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('PrintFlow Report');

    // Header Style
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '06A1A1']] // Staff Primary Color
    ];

    // Content Headers
    $headers = ['Order #', 'Customer Name', 'Service Type', 'Date', 'Total Amount (₱)', 'Status'];
    $col_idx = 0;
    $cols = ['A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($headers as $h) {
        $col_letter = $cols[$col_idx];
        $sheet->setCellValue($col_letter . '1', $h);
        $sheet->getColumnDimension($col_letter)->setAutoSize(true);
        $col_idx++;
    }
    $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

    // 4. Fill Data
    $row = 2;
    $total_sum = 0;
    foreach ($orders as $order) {
        $sheet->setCellValue('A' . $row, '#' . $order['order_id']);
        $sheet->setCellValue('B' . $row, $order['customer_name'] ?: 'Guest');
        $sheet->setCellValue('C' . $row, $order['service_type'] ?: 'General');
        $sheet->setCellValue('D' . $row, date('Y/m/d H:i', strtotime($order['order_date'])));
        $sheet->setCellValue('E' . $row, (float)$order['total_amount']);
        $sheet->setCellValue('F' . $row, $order['status']);
        
        // Format currency column E
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        
        $total_sum += (float)$order['total_amount'];
        $row++;
    }

    // 5. Add Total Row
    $row++; // Add a gap
    $sheet->setCellValue('D' . $row, 'TOTAL SALES:');
    $sheet->setCellValue('E' . $row, $total_sum);

    $sheet->getStyle('D' . $row . ':E' . $row)->getFont()->setBold(true);
    $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');

    // 6. Dynamic Filename
    $clean_status = ($status_filter === 'ALL') ? 'All_Statuses' : str_replace(' ', '_', $status_filter);
    $filename = "printflow_report_{$clean_status}_{$start_date}_to_{$end_date}.xlsx";

    // 7. Output to Browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Exception $e) {
    die("Export Error: " . $e->getMessage());
}
