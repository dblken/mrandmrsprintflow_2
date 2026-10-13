<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$revision = (string)file_get_contents($root . '/includes/revision_workflow.php');
$designCore = (string)file_get_contents($root . '/includes/order_item_design_files.php');
$functions = (string)file_get_contents($root . '/includes/functions.php');
$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$jobOrdersApi = (string)file_get_contents($root . '/admin/job_orders_api.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($designCore, 'revision_request_id IS NULL OR revision_request_id = 0') !== false, 'order item design list excludes revision response rows');
$expect(strpos($revision, 'printflow_staff_add_details_notification_url') !== false, 'staff add details notification URL helper exists');
$expect(strpos($revision, 'selected_design_file_ids') !== false, 'request stores selected design file ids');
$expect(strpos($revision, 'printflow_revision_file_sections_for_request') !== false, 'file section grouping helper exists');
$expect(strpos($functions, 'printflow_staff_add_details_notification_url') !== false, 'staff notification routing uses add details URL');
$expect(strpos($customizations, 'revisionSelectedDesignFileIds') !== false, 'staff modal tracks selected design file ids');
$expect(strpos($customizations, 'focus_add_details') !== false, 'customizations deep link supports focus_add_details');
$expect(strpos($revision, 'printflow_revision_unified_design_files_for_request') !== false, 'unified design file dedupe helper exists');
$expect(strpos($revision, "'unified_design_files'") !== false, 'revision review payload exposes unified_design_files');
$expect(strpos($customizations, 'revisionOriginalDesignFiles') !== false, 'staff modal renders original design comparison column');
$expect(strpos($customizations, 'revisionCurrentResponseDesignFiles') !== false, 'staff modal renders customer new upload comparison column');
$expect(strpos($customizations, 'revisionReviewActiveForItem') !== false, 'staff modal hides item design section during add-details review');
$expect(strpos($revision, 'printflow_revision_submit_staff_request') !== false, 'staff add-details submit helper exists');
$expect(strpos($jobOrdersApi, "case 'request_add_details':") !== false, 'job orders API exposes request_add_details action');
$expect(strpos($customizations, "action', 'request_add_details'") !== false, 'staff modal submits request_add_details directly');
$expect(strpos($customizations, 'revision_idempotency_key') !== false, 'staff modal sends revision idempotency key');

if ($failures !== []) {
    fwrite(STDERR, "add_details_workflow_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "add_details_workflow_test passed.\n";
