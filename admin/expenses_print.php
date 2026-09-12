<?php
/**
 * Expense printable report — respects all filters from Expense Management page.
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
$autoprint = (bool)($_GET['autoprint'] ?? 1);

$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalAmount += (float)($row['amount'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PrintFlow Expense Report</title>
<style>
body { font-family: Arial, Helvetica, sans-serif; color: #111827; margin: 24px; }
h1 { margin: 0 0 8px; font-size: 22px; }
.meta { margin: 0 0 18px; font-size: 13px; color: #4b5563; }
.meta table { border-collapse: collapse; }
.meta td { padding: 3px 16px 3px 0; vertical-align: top; }
.meta td:first-child { font-weight: 700; color: #374151; white-space: nowrap; }
.summary { margin: 0 0 18px; font-size: 13px; }
table.report { width: 100%; border-collapse: collapse; font-size: 12px; }
table.report th, table.report td { border: 1px solid #d1d5db; padding: 8px 10px; text-align: left; vertical-align: top; }
table.report th { background: #f3f4f6; font-weight: 700; }
table.report td.num, table.report th.num { text-align: right; white-space: nowrap; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.badge-paid { background: #dcfce7; color: #166534; }
.badge-pending { background: #fef3c7; color: #92400e; }
@media print {
    body { margin: 12mm; }
    .no-print { display: none !important; }
}
</style>
<?php if ($autoprint): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</head>
<body>
<h1>PrintFlow Expense Report</h1>
<div class="meta">
    <table>
        <tr><td>Report Type</td><td>Expense Detail</td></tr>
        <?php foreach ($meta as $label => $value): ?>
            <tr><td><?php echo htmlspecialchars($label); ?></td><td><?php echo htmlspecialchars($value); ?></td></tr>
        <?php endforeach; ?>
        <tr><td>Generated On</td><td><?php echo htmlspecialchars(date('F j, Y, g:i A')); ?></td></tr>
    </table>
</div>
<div class="summary">
    <strong>Total Records:</strong> <?php echo number_format(count($rows)); ?>
    &nbsp;&nbsp;|&nbsp;&nbsp;
    <strong>Total Amount:</strong> <?php echo htmlspecialchars(format_currency($totalAmount)); ?>
</div>
<table class="report">
    <thead>
        <tr>
            <th>ID</th>
            <th>Expense</th>
            <th>Category</th>
            <th>Branch</th>
            <th class="num">Amount</th>
            <th>Date</th>
            <th>Status</th>
            <th>Payment Method</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;color:#6b7280;">No expense records match the current filters.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $status = (string)($row['status'] ?? '');
                $badgeClass = $status === 'Paid' ? 'badge-paid' : 'badge-pending';
                ?>
                <tr>
                    <td><?php echo (int)($row['expense_id'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['expense_name'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['category'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['branch_name'] ?? '')); ?></td>
                    <td class="num"><?php echo htmlspecialchars(format_currency((float)($row['amount'] ?? 0))); ?></td>
                    <td><?php echo !empty($row['expense_date']) ? htmlspecialchars(date('M j, Y', strtotime((string)$row['expense_date']))) : '—'; ?></td>
                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                    <td><?php echo htmlspecialchars((string)($row['payment_method'] ?? '—')); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['notes'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</body>
</html>
