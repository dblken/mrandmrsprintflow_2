<?php

function revision_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$jobService = file_get_contents($root . '/includes/JobOrderService.php');
$workflow = file_get_contents($root . '/includes/revision_workflow.php');
$orders = file_get_contents($root . '/customer/orders.php');
$orderApi = file_get_contents($root . '/customer/get_order_items.php');
$editOrder = file_get_contents($root . '/customer/edit_order.php');
$adminApi = file_get_contents($root . '/admin/job_orders_api.php');
$v2Api = file_get_contents($root . '/staff/api/customizations_v2.php');
$customizationService = file_get_contents($root . '/includes/CustomizationService.php');
$repairMigration = file_get_contents($root . '/database/repair_revision_permissions_20260805.sql');

$normalize = static function (string $status): string {
    $normalized = strtoupper(trim($status));
    $normalized = str_replace(['–', '-'], '_', $normalized);
    $normalized = preg_replace('/\s+/', '_', $normalized);
    return trim((string)$normalized, '_');
};

revision_test_assert($normalize('For Revision') === 'FOR_REVISION', 'For Revision normalizes to FOR_REVISION');
revision_test_assert(substr_count($jobService, "\$normalizedNewStatus === 'FOR_REVISION'") >= 3, 'normalized revision checks use FOR_REVISION');
revision_test_assert(strpos($jobService, "'FOR_REVISION'  => 'For Revision'") !== false, 'normalized status map contains FOR_REVISION');
revision_test_assert(strpos($orders, 'type="button" data-revision-action="1"') !== false, 'dynamic modal action cannot submit a surrounding form');
revision_test_assert(strpos($orders, "event.preventDefault();") !== false && strpos($orders, "event.stopPropagation();") !== false, 'revision click prevents refresh and propagation');
revision_test_assert(strpos($orders, "closest('[data-revision-action=\"1\"]')") !== false, 'dynamically rendered action uses delegated click handling');
revision_test_assert(strpos($orders, 'Revision Request Unavailable') !== false, 'missing active requests show a visible failure instead of a dead action');
revision_test_assert(strpos($orderApi, "'order_item_ids'") !== false && strpos($orderApi, "'customer_id'") !== false && strpos($orderApi, "'status'") !== false, 'revision API returns ownership, item, and active-status metadata');
revision_test_assert(strpos($workflow, 'An unauthorized specification change was rejected.') !== false, 'server rejects unauthorized specification changes');
revision_test_assert(strpos($workflow, "request_status = 'Resubmitted for Review'") !== false, 'successful submission records Resubmitted for Review');
revision_test_assert(strpos($editOrder, 'Submit Updated Details') !== false || strpos($editOrder, 'Submit Updates') !== false, 'revision form exposes an explicit update submission action');
revision_test_assert(strpos($editOrder, "'date'") !== false && strpos($editOrder, 'needed_date') !== false, 'Needed Date uses a date control');
revision_test_assert(strpos($workflow, "if (empty(\$permissions)) return '';") !== false, 'empty permissions never receive a generic revision action label');
revision_test_assert(strpos($workflow, 'incorrect_details_explicit_needed_date') !== false, 'legacy Needed Date repair is explicitly scoped and audited');
revision_test_assert(strpos($workflow, 'customization detail mirror could not be synchronized') !== false, 'revised specifications synchronize the customization mirror transactionally');
revision_test_assert(strpos($editOrder, 'Revision Request Unavailable') !== false && strpos($editOrder, 'Reference ID:') !== false, 'invalid revision requests show a visible reference instead of redirecting');
revision_test_assert(strpos($adminApi, "?: ['uploaded_design']") === false && strpos($v2Api, "?: ['uploaded_design']") === false && strpos($customizationService, ": ['uploaded_design']") === false, 'staff endpoints do not silently default detail revisions to design upload');
revision_test_assert(strpos($repairMigration, "JSON_ARRAY('needed_date')") !== false && strpos($repairMigration, 'order_revision_permission_repairs') !== false, 'idempotent repair migration preserves before/after authorization audit');

echo "Revision request workflow regression test passed.\n";
