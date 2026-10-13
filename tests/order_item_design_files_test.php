<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$schema = (string)file_get_contents($root . '/includes/ensure_order_item_design_files_schema.php');
$core = (string)file_get_contents($root . '/includes/order_item_design_files.php');
$posUpload = (string)file_get_contents($root . '/staff/api/pos_upload_design.php');
$posCheckout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$orderReview = (string)file_get_contents($root . '/customer/order_review.php');
$serve = (string)file_get_contents($root . '/public/serve_design.php');
$staffUi = (string)file_get_contents($root . '/staff/customizations.php');
$posJs = (string)file_get_contents($root . '/staff/pos.php');
$service = (string)file_get_contents($root . '/includes/CustomizationService.php');

$expect(strpos($schema, 'order_item_design_files') !== false, 'schema creates order_item_design_files table');
$expect(strpos($schema, 'uq_order_item_design_file_idempotency') !== false, 'schema enforces idempotency unique key');
$expect(strpos($core, 'printflow_order_item_design_files_list_for_items') !== false, 'core supports batched file loads');
$expect(strpos($core, 'printflow_order_item_design_files_to_api') !== false, 'core normalizes API file rows');
$expect(strpos($core, 'printflow_order_item_design_files_sync_from_customization') !== false, 'checkout sync helper exists');
$expect(strpos($core, 'design_upload_files') !== false, 'sync reads design_upload_files payload');
$expect(strpos($posUpload, "'files'") !== false && strpos($posUpload, 'idempotency_key') !== false, 'POS upload returns authoritative files list');
$expect(strpos($posCheckout, 'printflow_order_item_design_files_sync_from_customization') !== false, 'POS checkout registers design files');
$expect(strpos($orderReview, 'printflow_order_item_design_files_sync_from_customization') !== false, 'customer checkout registers design files');
$expect(strpos($serve, "order_item_file") !== false, 'serve_design supports per-file downloads');
$expect(strpos($staffUi, 'staffItemDesignFiles') !== false, 'staff modal renders deduped design file list');
$expect(strpos($posJs, 'design_upload_files') !== false && strpos($posJs, 'posApplyStagedUploadMany') !== false, 'POS stages multiple design uploads');
$expect(strpos($service, 'attachDesignFilesToItemViews') !== false, 'CustomizationService attaches batched design files');

if ($failures !== []) {
    fwrite(STDERR, "order_item_design_files_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "order_item_design_files_test passed.\n";
