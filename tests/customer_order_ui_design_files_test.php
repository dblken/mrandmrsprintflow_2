<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/order_ui_helper.php';
require_once $root . '/includes/service_field_priority_helper.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(pf_order_ui_should_skip_spec_key('uploaded_files'), 'uploaded_files hidden from spec tiles');
$expect(pf_order_ui_should_skip_spec_key('design_upload_files'), 'design_upload_files hidden from spec tiles');
$expect(
    printflow_is_internal_customization_value(['tmp_path' => '/tmp/x.jpg', 'name' => 'a.jpg']),
    'upload row treated as internal value'
);
$expect(
    printflow_is_internal_customization_value([['tmp_path' => '/tmp/x.jpg', 'mime' => 'image/jpeg']]),
    'upload file list treated as internal value'
);

$item = [
    '_cart_key' => 'service_1_test',
    'uploaded_files' => [
        [
            'tmp_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pf_ui_test_' . uniqid('', true) . '.jpg',
            'name' => 'one.jpg',
            'mime' => 'image/jpeg',
            'upload_sort' => 0,
        ],
        [
            'tmp_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pf_ui_test_' . uniqid('', true) . '.jpg',
            'name' => 'two.jpg',
            'mime' => 'image/jpeg',
            'upload_sort' => 1,
        ],
    ],
];
foreach ($item['uploaded_files'] as &$upload) {
    file_put_contents((string)$upload['tmp_path'], 'fake');
}
unset($upload);

$files = pf_order_ui_item_design_files_list($item, true, []);
$expect(count($files) === 2, 'cart review lists every staged design file');
$expect(
    strpos((string)($files[0]['preview_url'] ?? ''), 'index=0') !== false
    && strpos((string)($files[1]['preview_url'] ?? ''), 'index=1') !== false,
    'each staged file gets its own preview URL index'
);

$helper = (string)file_get_contents($root . '/includes/order_ui_helper.php');
$ordersJs = (string)file_get_contents($root . '/customer/orders.php');
$expect(strpos($helper, 'Show all images') !== false, 'review gallery uses Show all images label');
$expect(strpos($ordersJs, 'imRenderLongFormSection') !== false, 'orders modal merges long-form text');
$expect(strpos($ordersJs, 'imDedupeDesignFilesForDisplay') !== false, 'orders modal dedupes design files');

if ($failures !== []) {
    fwrite(STDERR, "customer_order_ui_design_files_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "customer_order_ui_design_files_test passed.\n";
