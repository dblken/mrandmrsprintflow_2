<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = (string)file_get_contents($root . '/includes/order_item_design_files.php');
$posCheckout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$diagnostic = (string)file_get_contents($root . '/staff/api/order_item_design_files_diagnostic.php');
$jobApi = (string)file_get_contents($root . '/admin/job_orders_api.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($core, 'printflow_order_item_design_files_migrate_order_item') !== false, 'migrate helper exists');
$expect(strpos($core, 'printflow_order_item_design_files_reconcile_order_item') !== false, 'reconcile helper exists');
$expect(strpos($core, 'printflow_order_item_design_files_diagnostic') !== false, 'diagnostic helper exists');
$expect(strpos($core, 'printflow_order_item_design_files_stats') !== false, 'stats helper exists');
$expect(strpos($core, 'SET order_item_id = ?, order_id = ?, customization_id = ?') !== false, 'register relinks rows on idempotency reuse');
$expect(strpos($posCheckout, 'printflow_order_item_design_files_migrate_order_item') !== false, 'POS checkout migrates pending design files');
$expect(strpos($posCheckout, 'printflow_order_item_design_files_sync_from_customization') !== false, 'pending customization syncs design files');
$expect(strpos($diagnostic, 'printflow_order_item_design_files_diagnostic') !== false, 'diagnostic endpoint calls shared diagnostic helper');
$expect(strpos($jobApi, 'printflow_order_item_design_files_reconcile_order_item') !== false, 'price API reconciles design files from JSON');

if ($failures !== []) {
    fwrite(STDERR, "order_item_design_files_migrate_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "order_item_design_files_migrate_test passed.\n";
