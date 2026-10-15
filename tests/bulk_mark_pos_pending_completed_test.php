<?php

function bulk_complete_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$staffPage = file_get_contents($root . '/staff/customizations.php');
$api = file_get_contents($root . '/staff/api/bulk_mark_pos_pending_completed.php');
$bulkInclude = file_get_contents($root . '/includes/bulk_pos_pending_complete.php');
$mutation = file_get_contents($root . '/includes/order_status_mutation.php');
$singleEndpoint = file_get_contents($root . '/staff/update_order_status_process.php');

bulk_complete_test_assert(strpos($staffPage, 'Mark All Completed') !== false, 'POS customizations toolbar exposes Mark All Completed');
bulk_complete_test_assert(strpos($staffPage, 'previewBulkComplete') !== false && strpos($staffPage, 'confirmBulkComplete') !== false, 'bulk completion modal wires preview and confirm handlers');
bulk_complete_test_assert(strpos($staffPage, 'x-teleport="body"') !== false && strpos($staffPage, 'showBulkCompleteModal') !== false, 'bulk completion modal teleports to body like other staff dialogs');
bulk_complete_test_assert(strpos($staffPage, 'validateBulkCompleteDateRange') !== false, 'bulk completion validates date range before preview or execute');
bulk_complete_test_assert(strpos($staffPage, 'bulkCompleteIdempotencyKey') !== false, 'bulk completion UI tracks idempotency key');
bulk_complete_test_assert(strpos($staffPage, 'api/bulk_mark_pos_pending_completed.php') !== false, 'bulk completion calls dedicated staff API');

bulk_complete_test_assert(strpos($api, "printflow_require_staff_module('customizations')") !== false, 'bulk API requires customizations module');
bulk_complete_test_assert(strpos($api, "staffRole === 'online'") !== false || strpos($api, "'online'") !== false, 'online-only staff cannot run bulk completion');
bulk_complete_test_assert(strpos($api, 'verify_csrf_token') !== false, 'bulk API validates CSRF');
bulk_complete_test_assert(strpos($api, 'printflow_bulk_complete_load_idempotent') !== false, 'bulk execute supports idempotent replay');

bulk_complete_test_assert(strpos($bulkInclude, 'printflow_staff_mutate_order_status') !== false, 'bulk execute reuses single-order completion mutation');
bulk_complete_test_assert(strpos($bulkInclude, 'staff_bulk_complete_runs') !== false, 'bulk idempotency table is defined');
bulk_complete_test_assert(strpos($bulkInclude, 'printflow_bulk_pos_business_datetime_sql') !== false, 'bulk preview uses shared business order datetime');
bulk_complete_test_assert(strpos($bulkInclude, 'cust_pos.customization_details') !== false, 'bulk preview includes POS customization source detection');
bulk_complete_test_assert(strpos($bulkInclude, 'printflow_bulk_complete_inclusive_calendar_range') !== false, 'bulk preview expands to full start/end calendar days');

bulk_complete_test_assert(strpos($mutation, 'function printflow_staff_mutate_order_status') !== false, 'shared order status mutation helper exists');
bulk_complete_test_assert(strpos($singleEndpoint, 'printflow_staff_mutate_order_status') !== false, 'single-order endpoint delegates to shared mutation');

echo "Bulk mark POS pending completed regression test passed.\n";
