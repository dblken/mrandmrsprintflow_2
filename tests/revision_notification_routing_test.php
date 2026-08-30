<?php

function revision_notification_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/includes/functions.php');
$editOrder = file_get_contents($root . '/customer/edit_order.php');
$client = file_get_contents($root . '/public/assets/js/notifications.js');
$staffCustomizations = file_get_contents($root . '/staff/customizations.php');
$staffModalApi = file_get_contents($root . '/staff/get_order_for_modal.php');
$workflow = file_get_contents($root . '/includes/revision_workflow.php');
$serveDesign = file_get_contents($root . '/public/serve_design.php');
$adminApi = file_get_contents($root . '/admin/job_orders_api.php');
$migration = file_get_contents($root . '/database/repair_revision_notification_targets_20260806.sql');

$revisionCheck = strpos($functions, 'if (printflow_notification_is_revision_submission($n))');
$ratingCheck = strpos($functions, '$is_rating = (', $revisionCheck === false ? 0 : $revisionCheck);

revision_notification_assert($revisionCheck !== false, 'staff routing recognizes revision submissions');
revision_notification_assert($ratingCheck !== false && $revisionCheck < $ratingCheck, 'revision routing runs before the broad review/rating classifier');
revision_notification_assert(substr_count($editOrder, "'Design'") >= 2, 'direct and fallback staff notifications store the Design type');
revision_notification_assert(strpos($editOrder, '$order_id') !== false, 'notification creation retains the internal order ID as data_id');
revision_notification_assert(strpos($client, "staff/customizations.php?order_id=' + did + '&job_type=ORDER&status=PENDING'") !== false, 'client fallback deep-links the exact internal order ID as an order');
revision_notification_assert(strpos($staffCustomizations, "Customer's Revised Design — Awaiting Staff Review") !== false, 'staff detail clearly labels the replacement design');
revision_notification_assert(strpos($migration, "SET type = 'Design'") !== false && strpos($migration, 'submitted revised details') !== false, 'existing revision notifications have an idempotent data repair');
revision_notification_assert(strpos($staffModalApi, 'printflow_revision_review_payload($order_id, true)') !== false, 'direct Customizations view loads the shared revision state');
revision_notification_assert(strpos($workflow, "'replacement_design'") !== false && strpos($workflow, 'type=revision_submission&id=') !== false, 'replacement preview uses the exact submitted-revision endpoint');
revision_notification_assert(strpos($staffCustomizations, 'if (this.modalCache && this.modalCache[cacheKey])') === false, 'staff detail does not return stale cached revision state');
revision_notification_assert(strpos($serveDesign, "\$type === 'revision_submission'") !== false && strpos($serveDesign, "in_array('uploaded_design', \$permittedFields, true)") !== false, 'replacement endpoint enforces submitted design authorization');
revision_notification_assert(strpos($serveDesign, "db_table_has_column('order_items', 'revision_design_path')") !== false && strpos($adminApi, "db_table_has_column('order_items', 'revision_design_path')") !== false, 'legacy revision columns are schema-gated');

echo "Revision notification routing regression test passed.\n";
