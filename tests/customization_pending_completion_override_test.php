<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/order_completion_override.php';
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$expect(printflow_order_completion_override_date_in_scope('2026-09-01 00:00:00'), 'September 1 is inclusive at its start.');
$expect(printflow_order_completion_override_date_in_scope('2026-10-05 23:59:59'), 'October 5 is inclusive through its final second.');
$expect(!printflow_order_completion_override_date_in_scope('2026-08-31 23:59:59'), 'Orders before September 1 are outside scope.');
$expect(!printflow_order_completion_override_date_in_scope('2026-10-06 00:00:00'), 'Orders after October 5 are outside scope.');
$expect(!printflow_order_completion_override_date_in_scope(''), 'Missing business dates fail closed.');
$expect(printflow_order_completion_override_pending_status('Pending'), 'Pending orders are eligible.');
$expect(printflow_order_completion_override_pending_status('Pending Approval'), 'Pending Approval orders are eligible.');
$expect(!printflow_order_completion_override_pending_status('Processing'), 'Processing orders are outside the override.');
$expect(!printflow_order_completion_override_pending_status('Cancelled'), 'Cancelled orders are outside the override.');

$endpoint = (string)file_get_contents($root . '/staff/update_order_status_process.php');
$mutation = (string)file_get_contents($root . '/includes/order_status_mutation.php');
$payment = (string)file_get_contents($root . '/includes/order_completion_payment.php');
$jobs = (string)file_get_contents($root . '/includes/JobOrderService.php');
$page = (string)file_get_contents($root . '/staff/customizations.php');
$expect(str_contains($endpoint, "verify_csrf_token((string)(\$_POST['csrf_token'] ?? ''))"), 'CSRF verification remains mandatory.');
$expect(str_contains($endpoint, 'printflow_assert_order_branch_access($orderId)'), 'Branch permission enforcement remains active.');
$expect(str_contains($mutation, 'LIMIT 1 FOR UPDATE'), 'Completion remains serialized by a locked order row.');
$expect(str_contains($mutation, "'error' => 'Order not found.'") && str_contains($mutation, "'_http_status' => 404"), 'Invalid order IDs return a JSON 404 result.');
$expect(str_contains($mutation, 'requires_unpaid_confirmation'), 'Unpaid orders require a server-confirmed retry.');
$expect(str_contains($mutation, 'printflow_order_staff_completion_payment_gate') && str_contains($payment, "['paid', 'fully paid']"), 'Paid orders continue through the existing verified-payment gate.');
$expect(str_contains($mutation, "!in_array(\$recordedPaymentStatus, ['', 'UNPAID'], true)"), 'Only unpaid payment states can use the override.');
$expect(str_contains($mutation, 'Unpaid Order Completion Override'), 'Unpaid completion writes an audit activity.');
$expect(str_contains($mutation, "'previous_status' => \$oldStatus") && str_contains($mutation, "'payment_status' => (string)(\$order['payment_status'] ?? 'Unpaid')"), 'Audit captures previous and payment statuses.');
$expect(str_contains($mutation, "'actor_user_id' => \$actorId") && str_contains($mutation, 'created_at) VALUES (?,') , 'Audit captures actor identity and database timestamp.');
$expect(str_contains($jobs, 'bool $allowUnpaidCompletionOverride = false') && str_contains($jobs, '!$canComplete && !$allowUnpaidCompletionOverride'), 'Only explicitly scoped override calls bypass the job payment gate.');
$expect(str_contains($page, 'businessDate >= \'2026-09-01\' && businessDate <= \'2026-10-05\''), 'Browser sends override intent only for dates in the selected range.');
$expect(str_contains($page, 'This action will be audited.'), 'Unpaid override requires a clear user confirmation.');

if ($failures !== []) {
    fwrite(STDERR, "Pending completion override test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Pending completion override tests passed.\n";
