<?php
/**
 * Generic service identity + specification display (no order-specific hacks).
 */

$root = dirname(__DIR__);
require_once $root . '/includes/job_order_summary.php';
require_once $root . '/includes/customization_normalizer.php';

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$functionsSource = (string) file_get_contents($root . '/includes/functions.php');
$jobServiceSource = (string) file_get_contents($root . '/includes/JobOrderService.php');
$staffPage = (string) file_get_contents($root . '/staff/customizations.php');

$assert(str_contains($functionsSource, 'function printflow_merge_store_line_customization'), 'store line merge helper must exist');
$assert(str_contains($functionsSource, 'function printflow_load_customizations_by_order_item_for_orders'), 'customizations batch loader must exist');
$assert(str_contains($functionsSource, 'function printflow_pick_primary_service_from_summaries'), 'primary service picker must exist');
$assert(str_contains($functionsSource, 'function customer_orders_is_pos_placeholder_product_name'), 'POS placeholder product detector must exist');
$assert(str_contains($jobServiceSource, 'printflow_merge_store_line_customization'), 'batch loaders must enrich from customizations table');
$assert(str_contains($jobServiceSource, 'printflow_pick_primary_service_from_summaries'), 'batch loaders must pick service lines first');

$assert(
    !preg_match('/return \'T-Shirt Printing\';\s*\}\s*\/\/ 3\.5/s', $functionsSource),
    'get_service_name_from_customization must not infer T-shirt from vinyl fields'
);

$runtimeOk = false;
if (is_file($root . '/includes/env.php')) {
    require_once $root . '/includes/env.php';
}
if (getenv('DB_HOST') && getenv('DB_NAME') && getenv('DB_USER')) {
    require_once $root . '/includes/functions.php';
    $runtimeOk = function_exists('printflow_resolve_order_line_identity');
}

if ($runtimeOk) {
$tarpCustom = [
    'service_id' => 7,
    'service_type' => 'Tarpaulin Printing',
    'source' => 'pos',
    'source_page' => 'dynamic_form',
    'Material' => '4ft Tarpaulin',
    'width' => '3',
    'height' => '4',
];
$tarpOrder = ['order_type' => 'product', 'reference_id' => 0];
$tarpItem = [
    'product_name' => 'Tshirt',
    'product_id' => 3,
    'service_id' => 0,
    'item_type' => 'product',
    'customization_data' => json_encode($tarpCustom),
];
$tarpIdentity = printflow_resolve_order_line_identity($tarpOrder, $tarpItem, $tarpCustom);
$assert($tarpIdentity['item_type'] === 'service', 'POS tarpaulin with placeholder SKU must classify as service');
$assert(
    stripos($tarpIdentity['display_name'], 'Tarpaulin') !== false,
    'tarpaulin display name must come from saved service, not Tshirt SKU'
);

$stickerCustom = ['service_id' => 12, 'service_type' => 'Decals/Stickers (Print/Cut)'];
$stickerName = get_service_name_from_customization($stickerCustom, 'Tshirt');
$assert(stripos($stickerName, 'Sticker') !== false, 'sticker service must resolve from service_id/type');
$assert(strcasecmp($stickerName, 'Tshirt') !== 0, 'sticker must not fall back to Tshirt product name');

$filtered = printflow_filter_operations_display_specifications([
    'Material' => '4ft Tarpaulin',
    'Material Name' => '4ft Tarpaulin',
    'Layout' => 'with_layout',
    'Dimensions' => '3 × 4 ft',
]);
$assert(!isset($filtered['Material']) && !isset($filtered['Material Name']), 'Material must not appear in operations spec grids');
$assert(($filtered['Layout'] ?? '') === 'with_layout', 'non-material specs remain visible');

$displaySpecs = printflow_customization_display_specs($tarpCustom, [
    'include_service' => false,
    'include_design' => false,
    'include_notes' => false,
    'include_quantity' => true,
]);
$assert(!isset($displaySpecs['Material']), 'normalizer must hide material from display specs');

$adminFiltered = printflow_filter_admin_customization_display_specifications([
    'Material' => 'Sticker Black',
    'Layout' => 'with_layout',
]);
$assert(!isset($adminFiltered['Material']), 'admin filtered specs must hide material');

$primary = printflow_pick_primary_service_from_summaries([
    ['product_name' => 'Tshirt', 'item_type' => 'product', 'service_id' => 0],
    ['product_name' => 'Tarpaulin Printing', 'item_type' => 'service', 'service_id' => 7],
], $tarpCustom);
$assert(stripos((string)$primary['service_type'], 'Tarpaulin') !== false, 'primary service must prefer service line over placeholder product row');
} else {
    $assert(true, 'runtime identity checks skipped (DB env not configured locally)');
}

$assert(str_contains($staffPage, "delete out['Material']"), 'staff modal must strip Material label from spec grid');

if ($failures !== []) {
    fwrite(STDERR, "Order service identity regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Order service identity regression test passed.\n";
