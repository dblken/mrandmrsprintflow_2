<?php

require_once __DIR__ . '/../includes/service_order_helper.php';

function pos_design_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$fieldKey = 'dimensions';
$label = 'Dimensions';

$filePayload = [
    'layout' => 'with_layout',
    'design_input_mode' => 'file',
    'dimensions_design_input_mode' => 'file',
    'design_upload_path' => '/uploads/temp/test-design.jpg',
    'design_upload_name' => 'test-design.jpg',
    'dimensions_link' => 'partial-stale-value',
];

$mode = service_order_design_input_mode_from_customization($filePayload, $fieldKey);
$hasUpload = service_order_customization_has_design_file($filePayload, $fieldKey, $label);
$linkValue = service_order_extract_design_link_from_customization($filePayload, $label, $fieldKey);
if ($mode === 'file') {
    $linkValue = '';
}

pos_design_test_assert($mode === 'file', 'design_input_mode resolves file mode');
pos_design_test_assert($hasUpload === true, 'upload detected in customization');
pos_design_test_assert($linkValue === '', 'file mode clears stale link for validation');

$linkPayload = [
    'design_input_mode' => 'link',
    'dimensions_link' => 'https://example.com/design.png',
];
$linkCheck = service_order_validate_design_link(
    service_order_extract_design_link_from_customization($linkPayload, $label, $fieldKey)
);
pos_design_test_assert($linkCheck['ok'] === true, 'valid HTTPS link passes validation');

$badLinkCheck = service_order_validate_design_link('not-a-url');
pos_design_test_assert($badLinkCheck['ok'] === false, 'invalid link rejected');

$posSource = (string) file_get_contents(__DIR__ . '/../staff/pos.php');
pos_design_test_assert(strpos($posSource, 'design_input_mode') !== false, 'POS payload includes design_input_mode');
pos_design_test_assert(strpos($posSource, "designMode === 'file'") !== false, 'POS client validates upload mode separately from link');

$handlerSource = (string) file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
pos_design_test_assert(
    strpos($handlerSource, 'service_order_design_input_mode_from_customization') !== false,
    'cart handler uses design_input_mode for file fields'
);

echo "POS service design mode validation tests passed.\n";
