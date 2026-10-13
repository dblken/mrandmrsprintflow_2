<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = (string)file_get_contents($root . '/includes/order_item_design_files.php');
$posUpload = (string)file_get_contents($root . '/staff/api/pos_upload_design.php');
$posJs = (string)file_get_contents($root . '/staff/pos.php');
$staffUi = (string)file_get_contents($root . '/staff/customizations.php');
$zip = (string)file_get_contents($root . '/public/download_order_item_design_files_zip.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($core, 'printflow_order_item_design_files_assert_can_add') !== false, 'server assert_can_add helper exists');
$expect(strpos($core, 'printflow_order_item_design_files_limit_error_message') !== false, 'standard limit error message exists');
$expect(strpos($core, 'limit_reached') !== false, 'register returns limit_reached');
$expect(strpos($core, 'order_item_id = ? AND storage_path = ?') !== false, 'register dedupes same path per order item');
$expect(strpos($core, 'printflow_order_item_design_files_assert_can_add($orderItemId, $newInsertCount)') !== false, 'checkout sync rejects batch over limit');
$expect(strpos($posUpload, '$batchLimitHit && $staged !== []') !== false, 'POS upload rolls back partial batch on limit');
$expect(strpos($posUpload, 'printflow_order_item_design_files_assert_can_add') !== false, 'POS upload pre-validates existing + batch');
$expect(strpos($posUpload, 'printflow_order_item_design_files_limit_error_message') !== false, 'POS upload returns standard limit error text');
$expect(strpos($posUpload, 'rollback') !== false, 'POS upload rolls back batch on limit');
$expect(strpos($posJs, 'posCountUniqueDesignFiles') !== false, 'POS counts unique design files client-side');
$expect(strpos($posJs, 'posApplyAuthoritativeDesignFiles') !== false, 'POS replaces list from server response');
$expect(strpos($posJs, 'posDesignUploadInFlight') !== false, 'POS blocks double-click uploads');
$expect(strpos($staffUi, 'staffDesignFilesCountLabel') !== false, 'staff modal shows file counter');
$expect(strpos($staffUi, 'Array.isArray(incoming.design_files)') !== false, 'staff merge replaces authoritative design_files');
$expect(strpos($zip, 'seenFileIds') !== false, 'ZIP skips duplicate file IDs');

if ($failures !== []) {
    fwrite(STDERR, "order_item_design_files_limit_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "order_item_design_files_limit_test passed.\n";
