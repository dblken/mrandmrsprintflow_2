<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$revision = (string)file_get_contents($root . '/includes/revision_workflow.php');
$functions = (string)file_get_contents($root . '/includes/functions.php');
$jobOrdersApi = (string)file_get_contents($root . '/admin/job_orders_api.php');
$customizations = (string)file_get_contents($root . '/staff/customizations.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($revision, 'printflow_customer_add_details_notification_url') !== false, 'customer add details notification URL helper exists');
$expect(strpos($revision, 'printflow_revision_notify_customer_add_details_request') !== false, 'customer bell notification helper exists');
$expect(strpos($revision, 'printflow_revision_ensure_customer_add_details_notification') !== false, 'notification repair helper exists');
$expect(strpos($revision, 'printflow_revision_resync_active_pending_staff_request') !== false, 'pending request resync helper exists');
$expect(strpos($revision, 'printflow_revision_sync_entities_after_staff_request') !== false, 'post-request entity sync helper exists');
$expect(strpos($revision, 'create_notification') !== false, 'submit path creates customer notifications');
$expect(strpos($functions, 'printflow_notification_is_staff_add_details_request') !== false, 'staff add details notification classifier exists');
$expect(strpos($functions, 'printflow_customer_add_details_notification_url') !== false, 'customer notification routing uses edit_order URL');
$expect(strpos($functions, '/customer/edit_order.php') !== false, 'customer design notifications prefer edit_order');
$expect(strpos($jobOrdersApi, 'printflow_add_details_replace_phrases') !== false, 'API surfaces add-details phrasing on errors');
$expect(strpos($jobOrdersApi, "'repaired'") !== false || strpos($jobOrdersApi, 'repaired') !== false, 'API exposes repaired resync flag');
$expect(strpos($customizations, 'res.repaired') !== false, 'staff UI acknowledges repaired resync');

if ($failures !== []) {
    fwrite(STDERR, "add_details_notification_resync_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "add_details_notification_resync_test passed.\n";
