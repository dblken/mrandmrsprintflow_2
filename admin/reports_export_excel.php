<?php
/**
 * PrintFlow — Formatted Excel Export
 * Orders Status Report & Customers Report
 * Professional layout: alignment, column widths, proper date formatting
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/reports_date_range.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/reports_export_excel_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['Admin', 'Manager']);

$report   = $_GET['report'] ?? 'orders';
$dateRange = pf_reports_export_date_range();
$from     = $dateRange['from'];
$to       = $dateRange['to'];
$fromStart = $dateRange['fromStart'];
$toEnd    = $dateRange['toEnd'];

$branchCtx = init_branch_context(false);
$branchId  = $branchCtx['selected_branch_id'];
$branchName = $branchCtx['branch_name'];

[$dateSql, $dateTypes, $dateParams] = pf_reports_order_date_where('o');

[$bSql, $bTypes, $bParams] = branch_where_parts('o', $branchId);

$storePaidSql = pf_reports_store_order_paid_completed_expr('o');
$storePaidOnlySql = pf_reports_store_order_paid_expr('o');
$serviceCompletedSql = pf_reports_service_order_completed_expr('so');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$excelColCount = 8;

if ($report === 'orders') {
    $sheet->setTitle('Orders Status Report');
    buildOrdersReport($sheet, $from, $to, $branchName, $branchId, $bSql, $bTypes, $bParams, $dateSql, $dateTypes, $dateParams);
    $filename = 'PrintFlow_Orders_Status_' . date('Y-m-d') . '.xlsx';
    $excelColCount = 8;
} elseif ($report === 'customers') {
    $sheet->setTitle('Customers Report');
    buildCustomersReport($sheet, $from, $to, $branchName, $branchId);
    $filename = 'PrintFlow_Customers_' . date('Y-m-d') . '.xlsx';
    $excelColCount = 8;
} elseif ($report === 'sales') {
    $sheet->setTitle('Sales Report');
    buildSalesReport($sheet, $from, $to, $branchName, $branchId, $bSql, $bTypes, $bParams, $dateSql, $dateTypes, $dateParams);
    $filename = 'PrintFlow_Sales_' . date('Y-m-d') . '.xlsx';
    $excelColCount = ($branchId === 'all') ? 8 : 7;
} elseif ($report === 'daily_sales') {
    $day = date('Y-m-d', strtotime($_GET['date'] ?? $to));
    $sheet->setTitle('Daily Sales');
    buildDailySalesReport($sheet, $day, $branchName, $branchId, $storePaidSql, $serviceCompletedSql);
    $filename = 'PrintFlow_Daily_Sales_' . $day . '.xlsx';
    $excelColCount = 6;
} elseif ($report === 'shop_inventory') {
    $sheet->setTitle('Shop Inventory');
    buildShopInventoryReport($sheet, $branchName, $branchId, $dateRange['label']);
    $filename = 'PrintFlow_Shop_Inventory_' . date('Y-m-d') . '.xlsx';
    $excelColCount = 6;
} elseif ($report === 'inventory') {
    $sheet->setTitle('Materials Inventory');
    buildMaterialsInventoryReport($sheet, $branchName, $fromStart, $toEnd, $dateRange['label']);
    $filename = 'PrintFlow_Materials_Inventory_' . date('Y-m-d') . '.xlsx';
    $excelColCount = 7;
} else {
    header('HTTP/1.1 400 Bad Request');
    exit('Unknown report type.');
}

pf_excel_autosize_columns($sheet, 1, $excelColCount);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

/**
 * Sales detail report (same rows as CSV sales export), print-style formatting.
 */
function buildSalesReport($sheet, $from, $to, $branchName, $branchId, $bSql, $bTypes, $bParams, $dateSql, $dateTypes, $dateParams) {
    $params = array_merge($dateParams, $bParams);
    $storePaidSql = pf_reports_store_order_paid_expr('o');
    $showBranchCol = ($branchId === 'all');
    $colCustomer = $showBranchCol ? 'C' : 'B';
    $colEmail = $showBranchCol ? 'D' : 'C';
    $colDate = $showBranchCol ? 'E' : 'D';
    $colAmount = $showBranchCol ? 'F' : 'E';
    $colPayment = $showBranchCol ? 'G' : 'F';
    $colStatus = $showBranchCol ? 'H' : 'G';
    $lastCol = $colStatus;
    $lastColIdx = $showBranchCol ? 8 : 7;
    $dateLabel = pf_reports_export_date_range()['label'];

    $summary = db_query(
        "SELECT COUNT(*) as total_orders,
                SUM(o.total_amount) as total_revenue
         FROM orders o
         WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}",
        $dateTypes . $bTypes,
        $params
    );
    $s = $summary[0] ?? [];
    $totalRev = (float)($s['total_revenue'] ?? 0);
    $totalOrd = (int)($s['total_orders'] ?? 0);

    $orders = db_query(
        "SELECT o.order_id,
                (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-')
                 FROM order_items oi
                 LEFT JOIN products p ON oi.product_id = p.product_id
                 WHERE oi.order_id = o.order_id) AS order_sku,
                COALESCE(b.branch_name, 'Unknown') AS branch_name,
                CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name, COALESCE(c.email,'') as email,
                o.order_date, o.total_amount, o.payment_status, o.status
         FROM orders o
         LEFT JOIN customers c ON o.customer_id = c.customer_id
         LEFT JOIN branches b ON o.branch_id = b.id
         WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}
         ORDER BY o.order_date DESC",
        $dateTypes . $bTypes,
        $params
    ) ?: [];

    $sheet->setCellValue('A1', 'PrintFlow Sales & Analytics Report');
    $sheet->mergeCells('A1:' . $lastCol . '1');
    pf_excel_style_doc_title($sheet, 'A1:' . $lastCol . '1');

    $sheet->setCellValue('A3', 'Report Type');
    $sheet->setCellValue('B3', 'Sales Report');
    $sheet->setCellValue('A4', 'Branch');
    $sheet->setCellValue('B4', $branchName);
    $sheet->setCellValue('A5', 'Date Range');
    $sheet->setCellValue('B5', $dateLabel);
    $sheet->setCellValue('A6', 'Generated On');
    $sheet->setCellValue('B6', date('F j, Y, g:i A'));
    $sheet->getStyle('A3:A6')->getFont()->setBold(true);
    $sheet->getStyle('B3:B6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $row = 8;
    $sheet->setCellValue('A' . $row, 'SUMMARY');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11);
    $row++;
    $sheet->setCellValue('A' . $row, 'Total Orders');
    $sheet->setCellValue('B' . $row, $totalOrd);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $row++;
    $sheet->setCellValue('A' . $row, 'Total Revenue');
    $sheet->setCellValue('B' . $row, $totalRev);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    $row += 2;

    $headerRow = $row;
    $sheet->setCellValue('A' . $row, 'Order #');
    if ($showBranchCol) {
        $sheet->setCellValue('B' . $row, 'Branch');
    }
    $sheet->setCellValue($colCustomer . $row, 'Customer');
    $sheet->setCellValue($colEmail . $row, 'Email');
    $sheet->setCellValue($colDate . $row, 'Order Date');
    $sheet->setCellValue($colAmount . $row, 'Total Amount (₱)');
    $sheet->setCellValue($colPayment . $row, 'Payment Status');
    $sheet->setCellValue($colStatus . $row, 'Order Status');
    pf_excel_style_column_headers($sheet, 'A' . $row . ':' . $lastCol . $row);
    $row++;

    $firstData = $row;
    foreach ($orders as $o) {
        $orderCode = printflow_format_order_code($o['order_id'] ?? 0, $o['order_sku'] ?? '');
        $sheet->setCellValue('A' . $row, $orderCode);
        if ($showBranchCol) {
            $sheet->setCellValue('B' . $row, trim($o['branch_name'] ?? 'Unknown'));
        }
        $sheet->setCellValue($colCustomer . $row, trim($o['customer_name'] ?? ''));
        $sheet->setCellValue($colEmail . $row, trim($o['email'] ?? ''));
        $ts = strtotime($o['order_date'] ?? '');
        if ($ts) {
            $sheet->setCellValue($colDate . $row, SpreadsheetDate::PHPToExcel($ts));
            $sheet->getStyle($colDate . $row)->getNumberFormat()->setFormatCode('mmm d, yyyy h:mm AM/PM');
        } else {
            $sheet->setCellValue($colDate . $row, '');
        }
        $amt = (float)($o['total_amount'] ?? 0);
        $sheet->setCellValue($colAmount . $row, $amt);
        $sheet->getStyle($colAmount . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $sheet->setCellValue($colPayment . $row, (string)($o['payment_status'] ?? ''));
        $sheet->setCellValue($colStatus . $row, (string)($o['status'] ?? ''));

        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        if ($showBranchCol) {
            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }
        $sheet->getStyle($colCustomer . $row . ':' . $colEmail . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);
        $sheet->getStyle($colDate . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($colAmount . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle($colPayment . $row . ':' . $colStatus . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    }
    $lastData = $row - 1;
    if ($lastData >= $firstData) {
        pf_excel_zebra_body($sheet, $firstData, $lastData, 1, $lastColIdx);
        pf_excel_apply_table_autofilter($sheet, $headerRow, $lastColIdx, $lastData);
        $sheet->getStyle('A' . $firstData . ':' . $lastCol . $lastData)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
    }

    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->setCellValue($colAmount . $row, $totalRev);
    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle($colAmount . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle($colAmount . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('111827');
}

/**
 * Orders Status Report layout
 */
function buildOrdersReport($sheet, $from, $to, $branchName, $branchId, $bSql, $bTypes, $bParams, $dateSql, $dateTypes, $dateParams) {
    $params = array_merge($dateParams, $bParams);
    $storePaidSql = pf_reports_store_order_paid_completed_expr('o');
    $dateLabel = pf_reports_export_date_range()['label'];

    $summary = db_query(
        "SELECT COUNT(*) as total_orders, SUM(o.total_amount) as total_revenue,
                AVG(o.total_amount) as avg_order_value
         FROM orders o
         WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}",
        $dateTypes . $bTypes, $params
    );
    $sum = $summary[0] ?? [];
    $grandTotalOrd = (int)($sum['total_orders'] ?? 0);
    $grandTotalRev = (float)($sum['total_revenue'] ?? 0);
    $avgOrderVal = (float)($sum['avg_order_value'] ?? 0);

    $status_counts = db_query(
        "SELECT o.status, COUNT(*) as cnt, SUM(o.total_amount) as total
         FROM orders o
         WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}
         GROUP BY o.status ORDER BY cnt DESC",
        $dateTypes . $bTypes, $params
    ) ?: [];

    $daily = db_query(
        "SELECT DATE(o.order_date) as day, COUNT(*) as cnt, SUM(o.total_amount) as total
         FROM orders o
         WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}
         GROUP BY DATE(o.order_date) ORDER BY day DESC",
        $dateTypes . $bTypes, $params
    ) ?: [];

    $row = 1;

    // ─── 1. TITLE (A1:H1) ───────────────────────────────────
    $sheet->setCellValue('A1', 'PrintFlow Sales & Analytics Report');
    $sheet->mergeCells('A1:H1');
    pf_excel_style_doc_title($sheet, 'A1:H1');

    // ─── 2. METADATA (Rows 3–6) ─────────────────────────────
    $sheet->setCellValue('A3', 'Report Type');
    $sheet->setCellValue('B3', 'Orders Status Report');
    $sheet->setCellValue('A4', 'Branch');
    $sheet->setCellValue('B4', $branchName);
    $sheet->setCellValue('A5', 'Date Range');
    $sheet->setCellValue('B5', $dateLabel);
    $sheet->setCellValue('A6', 'Generated On');
    $sheet->setCellValue('B6', date('F j, Y, g:i A'));
    $sheet->getStyle('A3:A6')->getFont()->setBold(true);
    $sheet->getStyle('B3:B6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    // ─── 3. SUMMARY (Rows 8–11) ──────────────────────────────
    $row = 8;
    $sheet->setCellValue('A' . $row, 'SUMMARY');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11);
    $row++;

    $sheet->setCellValue('A' . $row, 'Total Orders');
    $sheet->setCellValue('B' . $row, $grandTotalOrd);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $row++;

    $sheet->setCellValue('A' . $row, 'Total Revenue');
    $sheet->setCellValue('B' . $row, $grandTotalRev);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    $row++;

    $sheet->setCellValue('A' . $row, 'Average Order');
    $sheet->setCellValue('B' . $row, $avgOrderVal);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    $row += 2;

    // ─── 4. STATUS BREAKDOWN TABLE (from row 13) ───────────────
    $statusStartRow = $row;
    $sheet->setCellValue('A' . $row, 'Status');
    $sheet->setCellValue('B' . $row, 'Total Orders');
    $sheet->setCellValue('C' . $row, 'Total Amount (₱)');
    pf_excel_style_column_headers($sheet, 'A' . $row . ':C' . $row);
    $row++;

    $statusFirstData = $row;
    foreach ($status_counts as $sc) {
        $sheet->setCellValue('A' . $row, (string)($sc['status'] ?? ''));
        $sheet->setCellValue('B' . $row, (int)$sc['cnt']);
        $sheet->setCellValue('C' . $row, (float)$sc['total']);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('B' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $row++;
    }

    $statusLastData = $row - 1;
    if ($statusLastData >= $statusFirstData) {
        pf_excel_zebra_body($sheet, $statusFirstData, $statusLastData, 1, 3);
        pf_excel_apply_table_autofilter($sheet, $statusStartRow, 3, $statusLastData);
    }

    // TOTAL row
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->setCellValue('B' . $row, $grandTotalOrd);
    $sheet->setCellValue('C' . $row, $grandTotalRev);
    $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('B' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
    $sheet->getStyle('A' . $row . ':C' . $row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('111827');
    $statusEndRow = $row;
    $row += 2;

    // Borders for Status table
    $sheet->getStyle('A' . $statusStartRow . ':C' . $statusEndRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // ─── 5. DAILY ORDER SUMMARY TABLE ────────────────────────
    $dailyStartRow = $row;
    $sheet->setCellValue('A' . $row, 'Date');
    $sheet->setCellValue('B' . $row, 'Orders');
    $sheet->setCellValue('C' . $row, 'Revenue (₱)');
    pf_excel_style_column_headers($sheet, 'A' . $row . ':C' . $row);
    $row++;

    $dayCount = count($daily);
    $dayTotalOrd = 0;
    $dayTotalRev = 0;

    $dailyFirstData = $row;
    foreach ($daily as $d) {
        $dayStr = $d['day'];
        $ts = strtotime($dayStr);
        $excelDate = SpreadsheetDate::PHPToExcel($ts);

        $sheet->setCellValue('A' . $row, $excelDate);
        $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode('mmm d, yyyy');
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('B' . $row, (int)$d['cnt']);
        $sheet->setCellValue('C' . $row, (float)$d['total']);
        $sheet->getStyle('B' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');

        $dayTotalOrd += (int)$d['cnt'];
        $dayTotalRev += (float)$d['total'];
        $row++;
    }

    $dailyLastData = $row - 1;
    if ($dayCount > 0 && $dailyLastData >= $dailyFirstData) {
        pf_excel_zebra_body($sheet, $dailyFirstData, $dailyLastData, 1, 3);
        pf_excel_apply_table_autofilter($sheet, $dailyStartRow, 3, $dailyLastData);
    }

    if ($dayCount > 0) {
        $sheet->setCellValue('A' . $row, 'DAILY AVERAGE');
        $sheet->setCellValue('B' . $row, round($dayTotalOrd / $dayCount, 1));
        $sheet->setCellValue('C' . $row, $dayTotalRev / $dayCount);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('B' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $row++;
    }
    $dailyEndRow = $row - 1;
    $dailyEndRow = max($dailyEndRow, $dailyStartRow);
    $sheet->getStyle('A' . $dailyStartRow . ':C' . $dailyEndRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

/**
 * Customers Report layout
 */
function buildCustomersReport($sheet, $from, $to, $branchName, $branchId) {
    $storePaidSql = pf_reports_store_order_paid_completed_expr('o');
    $dateLabel = pf_reports_export_date_range()['label'];
    if ($branchId !== 'all') {
        [$totalCust, $activeCust] = branch_customers_summary_for_branch((int)$branchId);
        $customers = branch_customers_report_list((int)$branchId);
    } else {
        $cust_summary = db_query("SELECT COUNT(*) as total, SUM(CASE WHEN status='Activated' THEN 1 ELSE 0 END) as active FROM customers");
        $cs = $cust_summary[0] ?? [];
        $totalCust = (int)($cs['total'] ?? 0);
        $activeCust = (int)($cs['active'] ?? 0);
        $customers = db_query(
            "SELECT c.customer_id, CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as name,
                    COALESCE(c.email,'') as email, COALESCE(c.contact_number,'') as contact_number, c.status, c.created_at,
                    COUNT(o.order_id) as order_count, COALESCE(SUM(o.total_amount), 0) as total_spent
             FROM customers c
             LEFT JOIN orders o ON c.customer_id = o.customer_id AND {$storePaidSql}
             GROUP BY c.customer_id
             HAVING order_count > 0
             ORDER BY total_spent DESC"
        ) ?: [];
    }

    $row = 1;

    // Title
    $sheet->setCellValue('A1', 'PrintFlow Sales & Analytics Report');
    $sheet->mergeCells('A1:H1');
    pf_excel_style_doc_title($sheet, 'A1:H1');

    // Metadata
    $sheet->setCellValue('A3', 'Report Type');
    $sheet->setCellValue('B3', 'Customers Report');
    $sheet->setCellValue('A4', 'Branch');
    $sheet->setCellValue('B4', $branchName);
    $sheet->setCellValue('A5', 'Date Range');
    $sheet->setCellValue('B5', $dateLabel);
    $sheet->setCellValue('A6', 'Generated On');
    $sheet->setCellValue('B6', date('F j, Y, g:i A'));
    $sheet->getStyle('A3:A6')->getFont()->setBold(true);

    // Summary
    $row = 8;
    $sheet->setCellValue('A' . $row, 'SUMMARY');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11);
    $row++;
    $sheet->setCellValue('A' . $row, 'Total Customers');
    $sheet->setCellValue('B' . $row, $totalCust);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $row++;
    $sheet->setCellValue('A' . $row, 'Active Customers');
    $sheet->setCellValue('B' . $row, $activeCust);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $row += 2;

    // Customer list headers
    $headerRow = $row;
    $headers = ['Customer ID', 'Name', 'Email', 'Contact Number', 'Status', 'Registered Date', 'Total Orders', 'Total Spent (₱)'];
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col . $row, $h);
    }
    pf_excel_style_column_headers($sheet, 'A' . $row . ':H' . $row);
    $row++;

    $custFirstData = $row;
    $totalSpentSum = 0;
    foreach ($customers as $c) {
        $totalSpent = (float)($c['total_spent'] ?? 0);
        $totalSpentSum += $totalSpent;
        $regDate = $c['created_at'] ?? '';
        $excelDate = ($regDate && strtotime($regDate)) ? SpreadsheetDate::PHPToExcel(strtotime($regDate)) : '';

        $sheet->setCellValue('A' . $row, (int)$c['customer_id']);
        $sheet->setCellValue('B' . $row, trim($c['name'] ?? ''));
        $sheet->setCellValue('C' . $row, trim($c['email'] ?? ''));
        $sheet->setCellValue('D' . $row, trim($c['contact_number'] ?? ''));
        $sheet->setCellValue('E' . $row, trim($c['status'] ?? ''));
        $sheet->setCellValue('F' . $row, $excelDate);
        $sheet->setCellValue('G' . $row, (int)($c['order_count'] ?? 0));
        $sheet->setCellValue('H' . $row, $totalSpent);

        $sheet->getStyle('A' . $row . ':E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if ($excelDate) $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('mmm d, yyyy');
        $sheet->getStyle('G' . $row . ':H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $row++;
    }

    $custLastData = $row - 1;
    if ($custLastData >= $custFirstData) {
        pf_excel_zebra_body($sheet, $custFirstData, $custLastData, 1, 8);
        pf_excel_apply_table_autofilter($sheet, $headerRow, 8, $custLastData);
    }

    // TOTAL row
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->setCellValue('H' . $row, $totalSpentSum);
    $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
    $sheet->getStyle('A' . $row . ':H' . $row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('111827');
    $lastRow = $row;

    $sheet->getStyle('A' . $headerRow . ':H' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}
