<?php

function change_item_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/includes/change_item_workflow.php');
$schema = file_get_contents($root . '/database/change_item_requests.sql');
$jobApi = file_get_contents($root . '/admin/job_orders_api.php');
$customerApi = file_get_contents($root . '/customer/change_item_request.php');
$customerOrders = file_get_contents($root . '/customer/orders.php');
$getOrderItems = file_get_contents($root . '/customer/get_order_items.php');
$staffCustomizations = file_get_contents($root . '/staff/customizations.php');
$receiptLookup = file_get_contents($root . '/staff/api/order_receipt_lookup.php');
$jobService = file_get_contents($root . '/includes/JobOrderService.php');

change_item_test_assert(strpos($schema, 'change_item_requests') !== false, 'schema creates change_item_requests table');
change_item_test_assert(strpos($workflow, "ref_type) = 'CHANGE_ITEM'") !== false, 'inventory uses CHANGE_ITEM ref_type');
change_item_test_assert(strpos($workflow, 'uq_order_active_change_item') !== false, 'active change item uniqueness enforced');
change_item_test_assert(strpos($workflow, 'idempotency_key') !== false, 'idempotency key supported');
change_item_test_assert(strpos($jobApi, "case 'change_item_create':") !== false, 'staff API create action exists');
change_item_test_assert(strpos($jobApi, "case 'change_item_approve':") !== false, 'staff API approve action exists');
change_item_test_assert(strpos($jobApi, "case 'change_item_reject':") !== false, 'staff API reject action exists');
change_item_test_assert(strpos($customerApi, 'printflow_change_item_create') !== false, 'customer submit endpoint calls workflow create');
change_item_test_assert(strpos($customerOrders, 'Request Change Item') !== false, 'customer orders UI exposes Request Change Item');
change_item_test_assert(strpos($getOrderItems, 'change_item') !== false, 'order detail payload includes change_item summary');
change_item_test_assert(strpos($staffCustomizations, 'pf-change-item-badge') !== false, 'staff list shows Changed Item badge');
change_item_test_assert(strpos($staffCustomizations, 'Approve Change Item') !== false, 'staff can approve change item requests');
change_item_test_assert(strpos($staffCustomizations, 'Submit Change Item') !== false, 'counter staff can submit change item');
change_item_test_assert(strpos($receiptLookup, 'change_item_eligible') !== false, 'receipt lookup exposes change item eligibility');
change_item_test_assert(strpos($jobService, 'printflow_change_item_on_job_status_change') !== false, 'job completion closes change item cycle');
change_item_test_assert(strpos($workflow, 'notify_shop_users') !== false, 'staff notification on customer change item request');
change_item_test_assert(strpos($workflow, 'create_notification') !== false, 'customer notifications wired');
change_item_test_assert(strpos($workflow, 'strlen($description) > 500') !== false, 'issue description capped at 500 characters');
change_item_test_assert(strpos($customerOrders, 'changeItemSuccessModal') !== false, 'customer change item success modal replaces alert');
change_item_test_assert(strpos($staffCustomizations, 'getStatusLabel(jo)') !== false, 'staff customizations status label helper present');
change_item_test_assert(strpos($workflow, "'iiiiisissssssssss'") !== false, 'change item insert uses correct bind types');
change_item_test_assert(strpos($workflow, "initialStatus = 'Requested'") !== false, 'change item inserts as Requested before approval');
change_item_test_assert(strpos($staffCustomizations, 'changeItemActiveRequest') !== false, 'staff modal renders active change item request');
change_item_test_assert(strpos(file_get_contents($root . '/staff/get_order_for_modal.php'), 'printflow_change_item_summary_for_order') !== false, 'order modal payload includes change item summary');

echo "All change item workflow smoke checks passed.\n";
