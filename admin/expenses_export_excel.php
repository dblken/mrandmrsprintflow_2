<?php
/**
 * Expense Excel export — respects all filters from Expense Management page.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/expense_management.php';
require_once __DIR__ . '/../includes/reports_export_excel_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['Admin', 'Manager']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

[$branchCtx, $branchId] = pf_expense_resolve_branch_context();
$branchName = (string)($branchCtx['branch_name'] ?? 'All Branches');

$periodInfo = pf_expense_resolve_period($_GET);
$filters = pf_expense_filters_from_request($_GET);
$rows = pf_expense_list_for_export($filters, $branchId);
$meta = pf_expense_export_filter_meta($filters, $branchName, $periodInfo);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Expense Report');

$sheet->setCellValue('A1', 'PrintFlow Expense Report');
$sheet->mergeCells('A1:I1');
pf_excel_style_doc_title($sheet, 'A1:I1');

$rowNum = 3;
$sheet->setCellValue('A' . $rowNum, 'Report Type');
$sheet->setCellValue('B' . $rowNum, 'Expense Detail');
$rowNum++;

foreach ($meta as $label => $value) {
    $sheet->setCellValue('A' . $rowNum, $label);
    $sheet->setCellValue('B' . $rowNum, $value);
    $rowNum++;
}

$sheet->setCellValue('A' . $rowNum, 'Generated On');
$sheet->setCellValue('B' . $rowNum, date('F j, Y, g:i A'));
$rowNum += 2;

$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalAmount += (float)($row['amount'] ?? 0);
}

$sheet->setCellValue('A' . $rowNum, 'Summary');
$sheet->getStyle('A' . $rowNum)->getFont()->setBold(true);
$rowNum++;
$sheet->setCellValue('A' . $rowNum, 'Total Records');
$sheet->setCellValue('B' . $rowNum, count($rows));
$rowNum++;
$sheet->setCellValue('A' . $rowNum, 'Total Amount');
$sheet->setCellValue('B' . $rowNum, $totalAmount);
$sheet->getStyle('B' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
$rowNum += 2;

$headerRow = $rowNum;
$headers = ['ID', 'Expense', 'Category', 'Branch', 'Amount', 'Date', 'Status', 'Payment Method', 'Notes'];
foreach ($headers as $idx => $header) {
    $col = chr(ord('A') + $idx);
    $sheet->setCellValue($col . $headerRow, $header);
}
pf_excel_style_column_headers($sheet, 'A' . $headerRow . ':I' . $headerRow);

$dataRow = $headerRow + 1;
foreach ($rows as $row) {
    $sheet->setCellValue('A' . $dataRow, (int)($row['expense_id'] ?? 0));
    $sheet->setCellValue('B' . $dataRow, (string)($row['expense_name'] ?? ''));
    $sheet->setCellValue('C' . $dataRow, (string)($row['category'] ?? ''));
    $sheet->setCellValue('D' . $dataRow, (string)($row['branch_name'] ?? ''));
    $sheet->setCellValue('E' . $dataRow, (float)($row['amount'] ?? 0));
    $sheet->getStyle('E' . $dataRow)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->setCellValue('F' . $dataRow, !empty($row['expense_date']) ? date('Y-m-d', strtotime((string)$row['expense_date'])) : '');
    $sheet->setCellValue('G' . $dataRow, (string)($row['status'] ?? ''));
    $sheet->setCellValue('H' . $dataRow, (string)($row['payment_method'] ?? ''));
    $sheet->setCellValue('I' . $dataRow, (string)($row['notes'] ?? ''));
    $dataRow++;
}

if ($dataRow > $headerRow + 1) {
    pf_excel_zebra_body($sheet, $headerRow + 1, $dataRow - 1, 1, 9);
}

pf_excel_autosize_columns($sheet, 1, 9);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="PrintFlow_Expenses_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
