<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$revision = (string)file_get_contents($root . '/includes/revision_workflow.php');
$designCore = (string)file_get_contents($root . '/includes/order_item_design_files.php');
$editOrder = (string)file_get_contents($root . '/customer/edit_order.php');
$staffUi = (string)file_get_contents($root . '/staff/customizations.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($revision, 'printflow_revision_design_files_assert_can_add') !== false, 'revision submit uses per-request file limit');
$expect(strpos($revision, 'printflow_revision_collect_design_uploads') !== false, 'revision submit collects multi-file uploads');
$expect(strpos($revision, 'service_order_helper.php') !== false, 'revision workflow loads upload normalizer');
$expect(strpos($revision, 'revision_replacement') !== false, 'revision files stored with revision_replacement role');
$expect(strpos($revision, 'replacement_design_files') !== false, 'staff API payload includes replacement_design_files');
$expect(strpos($designCore, 'printflow_revision_design_files_list_for_request') !== false, 'revision file list helper exists');
$expect(strpos($designCore, 'revision_request_id') !== false, 'design file rows link to revision_request_id');
$expect(strpos($editOrder, 'name="design_file[]"') !== false, 'customer revision form uses multi file input');
$expect(strpos($editOrder, 'multiple') !== false, 'customer revision input allows multiple selection');
$expect(strpos($staffUi, 'revisionReplacementDesignFiles') !== false, 'staff modal renders unified replacement file gallery');
$expect(is_file($root . '/public/download_revision_design_files_zip.php'), 'revision ZIP download endpoint exists');

if ($failures !== []) {
    fwrite(STDERR, "revision_multi_design_files_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "revision_multi_design_files_test passed.\n";
