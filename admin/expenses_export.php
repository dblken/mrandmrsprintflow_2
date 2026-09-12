<?php
/**
 * Expense CSV export — respects all filters from Expense Management page.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/expense_management.php';

require_role(['Admin', 'Manager']);

[$branchCtx, $branchId] = pf_expense_resolve_branch_context();
$branchName = (string)($branchCtx['branch_name'] ?? 'All Branches');

$periodInfo = pf_expense_resolve_period($_GET);
$filters = pf_expense_filters_from_request($_GET);
$rows = pf_expense_list_for_export($filters, $branchId);
$meta = pf_expense_export_filter_meta($filters, $branchName, $periodInfo);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="PrintFlow_Expenses_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

function pf_expense_csv_val($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $v = trim((string)$v);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
}

fputcsv($output, ['PrintFlow Expense Report']);
fputcsv($output, ['Report Type', 'Expense Detail']);
foreach ($meta as $label => $value) {
    fputcsv($output, [$label, pf_expense_csv_val($value)]);
}
fputcsv($output, ['Generated On', date('F j, Y, g:i A')]);
fputcsv($output, []);

$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalAmount += (float)($row['amount'] ?? 0);
}

fputcsv($output, ['Summary']);
fputcsv($output, ['Total Records', count($rows)]);
fputcsv($output, ['Total Amount', number_format($totalAmount, 2, '.', '')]);
fputcsv($output, []);

fputcsv($output, ['ID', 'Expense', 'Category', 'Branch', 'Amount', 'Date', 'Status', 'Payment Method', 'Notes']);
foreach ($rows as $row) {
    fputcsv($output, [
        (int)($row['expense_id'] ?? 0),
        pf_expense_csv_val($row['expense_name'] ?? ''),
        pf_expense_csv_val($row['category'] ?? ''),
        pf_expense_csv_val($row['branch_name'] ?? ''),
        number_format((float)($row['amount'] ?? 0), 2, '.', ''),
        !empty($row['expense_date']) ? date('Y-m-d', strtotime((string)$row['expense_date'])) : '',
        pf_expense_csv_val($row['status'] ?? ''),
        pf_expense_csv_val($row['payment_method'] ?? ''),
        pf_expense_csv_val($row['notes'] ?? ''),
    ]);
}

fclose($output);
exit;
