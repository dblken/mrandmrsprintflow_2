<?php
/**
 * Admin Customizations must show customer order-item specifications, not stale mirror columns.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/job_order_summary.php';

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$stickerLine = [
    'order_item_id' => 9001,
    'product_name' => 'Stickers Decals',
    'customization' => [
        'service_id' => 12,
        'Dimensions' => 'Glossy Sticker Cut Out (1ft X 2ft)',
        'Material' => 'Sticker Black',
        'layout' => 'with_layout',
        'notes' => 'Unique regression note XYZ-12158',
    ],
];
$wrongMirror = [
    'service_type' => 'T-Shirt Printing',
    'customization_details' => json_encode(['service_type' => 'T-Shirt Printing']),
];

$assert(
    printflow_job_order_service_label_from_sources($stickerLine, $wrongMirror, 'T-Shirt Printing') === 'Stickers Decals',
    'order line product_name must win over customizations.service_type mirror'
);

$assert(
    printflow_job_order_display_dimensions_label($stickerLine, []) === 'Glossy Sticker Cut Out (1ft X 2ft)',
    'dimensions label must come from customer customization field'
);

$shirtLine = [
    'order_item_id' => 9002,
    'product_name' => 'T-Shirt Printing',
    'customization' => ['Material' => 'Cotton White', 'Size' => 'L'],
];
$stickerMirrorOnly = ['service_type' => 'Stickers Decals'];
$assert(
    printflow_job_order_service_label_from_sources($shirtLine, $stickerMirrorOnly, '') === 'T-Shirt Printing',
    'each order item keeps its own service when lines differ'
);

$adminPage = (string)file_get_contents($root . '/admin/customizations.php');
$jobService = (string)file_get_contents($root . '/includes/JobOrderService.php');

$assert(str_contains($adminPage, 'displayServiceType'), 'admin modal must use display service helper');
$assert(str_contains($adminPage, 'displayDimensions'), 'admin modal must use display dimensions helper');
$assert(str_contains($adminPage, 'display_specifications'), 'admin modal must render API display_specifications');
$assert(str_contains($jobService, 'display_specifications'), 'get_order API must attach display_specifications');

$summary = (string)file_get_contents($root . '/includes/job_order_summary.php');
$assert(str_contains($summary, 'printflow_job_order_service_label_from_sources'), 'shared service label resolver exists');
$assert(
    !preg_match('/if \(\$serviceType !== \'\'\) \{\s*return \$serviceType;/', $summary),
    'display service must not return mirror service_type before order line payload'
);

require_once $root . '/includes/customization_normalizer.php';
$displaySpecs = printflow_customization_display_specs($stickerLine['customization'], [
    'include_service' => false,
    'include_design' => false,
    'include_notes' => true,
    'include_quantity' => false,
]);
$assert(isset($displaySpecs['Material']) && $displaySpecs['Material'] === 'Sticker Black', 'spec grid includes material');
$assert(isset($displaySpecs['Notes']) && str_contains($displaySpecs['Notes'], 'XYZ-12158'), 'spec grid includes customer notes');

if ($failures !== []) {
    fwrite(STDERR, "Admin customization specifications test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin customization specifications test passed.\n";
