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
$assert(
    printflow_job_order_display_dimensions_label($shirtLine, []) === '',
    'shirt Size L must not be duplicated as a Dimensions row'
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
$assert(!isset($displaySpecs['Material']), 'raw display builder hides material from customer-facing grids');
$assert(isset($displaySpecs['Notes']) && str_contains($displaySpecs['Notes'], 'XYZ-12158'), 'spec grid includes customer notes');

$filtered = printflow_filter_operations_display_specifications([
    'Material' => 'Sticker Black',
    'Material Name' => 'Sticker Black',
    'Design Input Mode' => 'file',
    'Order Priority' => 'Regular Order',
    'Needed Date' => 'Sep 7, 2026',
    'Layout' => 'with_layout',
    'Calculated Unit Price' => '250.00',
]);
$assert(!isset($filtered['Material']) && !isset($filtered['Material Name']), 'Material is hidden from operations display');
$assert(!isset($filtered['Design Input Mode']), 'Design Input Mode is hidden from operations display');
$assert(!isset($filtered['Calculated Unit Price']), 'calculated price metadata is hidden');
$assert(isset($filtered['Layout']), 'valid customer layout remains visible');
$assert(printflow_format_customer_priority_display('Regular Order') === 'Regular', 'priority displays Regular for regular order selection');
$assert(printflow_format_customer_priority_display('') === '', 'empty priority stays empty for Not specified handling');

require_once $root . '/includes/service_field_priority_helper.php';
$regularPriority = printflow_resolve_customer_priority_from_customization([
    'Order Priority' => 'Regular Order',
]);
$assert(
    ($regularPriority['selected_value'] ?? '') === 'Regular Order',
    'priority resolves Regular Order from submitted label without service_id'
);
$assert(
    printflow_format_customer_priority_display((string)($regularPriority['selected_value'] ?? '')) === 'Regular',
    'admin/staff priority label matches Regular'
);
$emptyPriority = printflow_resolve_customer_priority_from_customization([]);
$assert(trim((string)($emptyPriority['selected_value'] ?? '')) === '', 'empty customization yields no priority value');

$adminFiltered = printflow_filter_admin_customization_display_specifications([
    'Material' => 'Sticker Black',
    'Layout' => 'with_layout',
]);
$assert(!isset($adminFiltered['Material']), 'admin summary must not show material tile');
$assert(isset($adminFiltered['Layout']), 'admin summary keeps other valid specs');

$needed = printflow_job_order_line_needed_date_payload([
    'Needed Date' => '2026-09-07',
    'service_id' => 12,
]);
$assert($needed['needed_date'] === '2026-09-07', 'needed date resolves from customer field only');
$assert($needed['needed_date_display'] === 'Sep 7, 2026', 'needed date display format preserved');

$adminPage = (string)file_get_contents($root . '/admin/customizations.php');
$assert(str_contains($adminPage, 'Needed Date'), 'admin modal uses Needed Date label');
$assert(!str_contains($adminPage, 'Due Date'), 'admin modal must not show Due Date label');
$assert(str_contains($adminPage, 'displayCustomerPriority'), 'admin modal uses customer priority display');
$assert(str_contains($adminPage, 'Not specified'), 'admin modal shows Not specified when priority missing');

if ($failures !== []) {
    fwrite(STDERR, "Admin customization specifications test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin customization specifications test passed.\n";
