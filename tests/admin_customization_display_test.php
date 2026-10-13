<?php
/**
 * Regression checks for Admin Customizations business date and per-line service labels.
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$adminPage = (string)file_get_contents($root . '/admin/customizations.php');
$jobSummary = (string)file_get_contents($root . '/includes/job_order_summary.php');
$jobService = (string)file_get_contents($root . '/includes/JobOrderService.php');

$assert(str_contains($adminPage, 'jo_ord.order_date AS store_order_date'), 'admin list must load store order_date');
$assert(str_contains($adminPage, 'COALESCE(jo_ord.order_date, jo.created_at)'), 'admin list must prefer business order_date over job created_at');
$assert(str_contains($adminPage, 'printflow_job_order_display_service_type'), 'admin list must resolve per-line service names');
$assert(str_contains($adminPage, 'formatBusinessSubmittedAt'), 'admin modal must format business submitted date');
$assert(str_contains($adminPage, 'System Created At'), 'admin modal must show audit created timestamp separately');
$assert(str_contains($adminPage, 'Asia/Manila'), 'admin modal must format dates in Manila timezone');

$assert(str_contains($jobSummary, 'function printflow_job_order_business_date_value'), 'shared helper for business date exists');
$assert(str_contains($jobSummary, 'function printflow_job_order_display_service_type'), 'shared helper for line service exists');
$assert(str_contains($jobService, 'ord.order_date AS store_order_date'), 'getOrder must expose store order_date');
$assert(str_contains($jobService, 'printflow_job_order_display_service_type($order)'), 'getOrder must resolve line-specific service type');
$assert(str_contains($jobService, 'order_business_date'), 'getOrder must expose order_business_date for API consumers');
$assert(str_contains($jobService, '$ownItemId = (int)($jo[\'order_item_id\']'), 'store payload enrichment must anchor service to order_item_id');

require_once $root . '/includes/job_order_summary.php';
$assert(
    printflow_job_order_business_date_value(['created_at' => '2026-10-13 14:00:00'], ['order_date' => '2026-09-07 07:30:00']) === '2026-09-07 07:30:00',
    'business date helper must prefer orders.order_date'
);
$assert(
    printflow_format_business_datetime_display('2026-09-07 07:30:00') === 'Sep 7, 2026 7:30 AM',
    'business datetime display must preserve selected time in Manila'
);

if ($failures !== []) {
    fwrite(STDERR, "Admin customization display test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin customization display test passed.\n";
