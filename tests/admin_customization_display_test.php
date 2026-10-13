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
$assert(str_contains($adminPage, 'jo_ord.order_date'), 'admin list must use store order_date for submitted date');
$assert(!str_contains($adminPage, 'jo.created_at'), 'admin customizations must not reference job created_at for display');
$assert(str_contains($adminPage, 'printflow_job_order_display_service_type'), 'admin list must resolve per-line service names');
$assert(str_contains($adminPage, 'formatBusinessSubmittedAt'), 'admin modal must format business submitted date');
$assert(!str_contains($adminPage, 'System Created At'), 'admin UI must not expose database insertion timestamp');
$assert(!str_contains($adminPage, 'formatSystemCreatedAt'), 'admin UI must not format system created_at for display');
$assert(str_contains($adminPage, "job?.order_business_date || job?.order_date || job?.store_order_date"), 'date submitted must use business order_date only');
$assert(str_contains($adminPage, 'Asia/Manila'), 'admin modal must format dates in Manila timezone');
$assert(str_contains($adminPage, 'max-width:560px'), 'admin modal must match staff medium width');
$assert(str_contains($adminPage, 'pf-customization-order-details'), 'admin modal must use staff order details layout');
$assert(str_contains($adminPage, 'adminItemSpecEntries'), 'admin modal must build spec grid from API display_specifications');
$assert(str_contains($adminPage, 'resolvePriorityValue'), 'admin modal must resolve priority before Not specified fallback');

$assert(str_contains($jobSummary, 'function printflow_job_order_business_date_value'), 'shared helper for business date exists');
$assert(str_contains($jobSummary, 'function printflow_job_order_display_service_type'), 'shared helper for line service exists');
$assert(str_contains($jobService, 'ord.order_date AS store_order_date'), 'getOrder must expose store order_date');
$assert(str_contains($jobService, 'printflow_job_order_display_service_type($order)'), 'getOrder must resolve line-specific service type');
$assert(str_contains($jobService, 'display_service_type'), 'getOrder must expose display_service_type for admin modal');
$assert(str_contains($jobService, 'display_dimensions'), 'getOrder must expose display_dimensions from order item');
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
