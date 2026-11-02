<?php
// Give this contract test a fake, supported current-mode provider schema so
// generated SQL can be checked without opening a database connection.
function db_table_has_column(string $table, string $column): bool
{
    return $table === 'provider_payments'
        && in_array($column, ['subject_type', 'subject_id', 'status', 'mode'], true);
}
function printflow_paymongo_mode(): string { return 'live'; }

require_once __DIR__ . '/../includes/admin_transaction_visibility.php';

$successOrderStatuses = ['Paid', 'Processing', 'Ready for Pickup', 'TO_RECEIVE', 'Completed', 'Delivered', 'Released', 'In Production', 'Payment Confirmed', 'Paid – In Process'];
foreach ($successOrderStatuses as $status) {
    if (!printflow_admin_transaction_row_is_visible('', $status)) {
        fwrite(STDERR, "Expected successful status to be visible: {$status}\n");
        exit(1);
    }
}
$successPaymentStatuses = ['paid', 'Fully Paid', 'Payment Confirmed', 'success', 'successful', 'succeeded'];
foreach ($successPaymentStatuses as $status) {
    if (!printflow_admin_transaction_row_is_visible($status, 'Awaiting Payment')) {
        fwrite(STDERR, "Expected verified payment state to be visible: {$status}\n");
        exit(1);
    }
}

if (printflow_admin_transaction_row_is_visible('processing', 'Awaiting Payment')) {
    fwrite(STDERR, "A payment-processing state alone must not count as verified success.\n");
    exit(1);
}

$failure = ['Awaiting Payment', 'Pending Payment', 'Expired', 'Cancelled', 'Failed', 'Rejected', 'Declined', 'Abandoned', 'Unpaid', 'No transaction', '', null, 'mystery'];
foreach ($failure as $status) {
    if (printflow_admin_transaction_row_is_visible('', $status)) {
        fwrite(STDERR, 'Expected unsuccessful status to be excluded: ' . var_export($status, true) . "\n");
        exit(1);
    }
}

if (!printflow_admin_transaction_row_is_visible('paid', 'Expired')) {
    fwrite(STDERR, "Verified payment should protect an otherwise expired order.\n");
    exit(1);
}
if (!printflow_admin_transaction_row_is_visible('unpaid', 'Processing')) {
    fwrite(STDERR, "A valid active operational status should remain visible.\n");
    exit(1);
}

$orderSql = printflow_admin_order_transaction_visible_sql('o');
$jobSql = printflow_admin_job_transaction_visible_sql('jo');
$providerSql = printflow_admin_provider_payment_visible_sql('pp', 'o');
$inventorySql = printflow_admin_inventory_transaction_visible_sql('t');
if (!str_contains($orderSql, "o.payment_status") || !str_contains($jobSql, "o_admin_visibility") || !str_contains($providerSql, "pp.status")) {
    fwrite(STDERR, "Generated visibility predicates are missing a required status source.\n");
    exit(1);
}
if (!str_contains($orderSql, "pp_admin.mode = 'live'")
    || !str_contains($jobSql, "pp_admin.mode = 'live'")
    || !str_contains($providerSql, "pp.mode = 'live'")
    || !str_contains($providerSql, "= 'paid'")) {
    fwrite(STDERR, "Provider ledger evidence must be current-mode and paid-only.\n");
    exit(1);
}
if (!str_contains($inventorySql, "'JOB_ORDER'") || !str_contains($inventorySql, "'CHANGE_ITEM'") || !str_contains($inventorySql, "'SERVICE_ORDER'")) {
    fwrite(STDERR, "Mapped Admin inventory ledger references are missing the parent transaction guard.\n");
    exit(1);
}
if (stripos($orderSql . $jobSql . $providerSql, 'payment_submissions') !== false) {
    fwrite(STDERR, "Payment submissions must not independently qualify a transaction.\n");
    exit(1);
}
$notificationSql = printflow_admin_notification_transaction_visible_sql('n');
if (!str_contains($notificationSql, "'payment issue'")
    || !str_contains($notificationSql, 'o_admin_payment_issue.payment_status')
    || !str_contains($notificationSql, 'jo_admin_payment_issue.payment_status')) {
    fwrite(STDERR, "Payment Issue notifications must resolve to a visible order or job transaction.\n");
    exit(1);
}

$requiredWiring = [
    'admin/orders_management.php' => 'printflow_admin_order_transaction_visible_sql',
    'admin/payment.php' => 'printflow_admin_provider_payment_visible_sql',
    'admin/inv_transactions_ledger.php' => 'printflow_admin_inventory_transaction_visible_sql',
    'admin/inventory_transactions_api.php' => 'printflow_admin_inventory_transaction_visible_sql',
    'admin/job_orders.php' => 'printflow_admin_job_transaction_visible_sql',
    'admin/job_orders_api.php' => 'printflow_admin_job_transaction_visible_sql',
    'admin/customizations.php' => 'printflow_admin_job_transaction_visible_sql',
    'admin/dashboard.php' => 'printflow_admin_job_transaction_visible_sql',
    'admin/api_order_details.php' => 'printflow_admin_order_transaction_visible_sql',
    'admin/api_sales_order_audit.php' => 'printflow_admin_order_transaction_visible_sql',
    'admin/notifications.php' => 'printflow_admin_notification_transaction_visible_sql',
    'includes/admin_sidebar.php' => 'printflow_admin_notification_transaction_visible_sql',
    'admin/api_order_status_chart.php' => 'printflow_admin_order_transaction_visible_sql',
    'admin/api_customization_usage_chart.php' => 'printflow_admin_job_transaction_visible_sql',
    'admin/api_revenue_chart.php' => 'printflow_admin_job_transaction_visible_sql',
    'admin/reports_data.php' => 'printflow_admin_order_transaction_visible_sql',
    'admin/sales.php' => 'pf_reports_official_sales_breakdown',
    'admin/reports_export.php' => 'pf_reports_store_order_paid_completed_expr',
    'admin/reports_export_excel.php' => 'pf_reports_store_order_paid_completed_expr',
];
foreach ($requiredWiring as $relativePath => $needle) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    if (!is_string($source) || !str_contains($source, $needle)) {
        fwrite(STDERR, "Missing shared visibility wiring in {$relativePath}.\n");
        exit(1);
    }
}

echo "PASS: Admin transaction visibility allowlist, fail-closed statuses, verified payment, and active status\n";
