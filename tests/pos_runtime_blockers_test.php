<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$rules = $read('includes/pos_service_stock_validation.php');
$stockHelpers = $read('includes/pos_service_stock_helpers.php');
$inventoryApi = $read('admin/inventory_items_api.php');
$sw = $read('public/sw.php');
$manifest = $read('public/manifest.php');
$shopConfig = $read('includes/shop_config.php');

if (strpos($rules, "db_table_has_column('service_material_rules', 'qty_multiplier', true)") === false
    || strpos($rules, 'printflow_pos_assert_service_stock_multiplier_schema(') === false
    || strpos($rules, 'r.qty_multiplier') === false) {
    throw new RuntimeException('POS stock validation must verify the multiplier schema before selecting the multiplier column.');
}
foreach ([36 => 'Decals/Stickers (Print/Cut)', 39 => 'Plates', 64 => 'Poster Printing', 67 => 'Brochure'] as $serviceId => $serviceType) {
    if (strpos($stockHelpers, $serviceId . ' => ' . "'" . $serviceType . "'") === false) {
        throw new RuntimeException('A reported POS service is missing its canonical service_type mapping: ' . $serviceId);
    }
}
foreach ([39, 64, 67] as $serviceId) {
    if (strpos($stockHelpers, 'in_array($serviceId, [39, 64, 67], true)') === false
        || strpos($rules, 'printflow_pos_assert_required_service_material_rule(') === false) {
        throw new RuntimeException('An unconfigured service must fail closed for service ID ' . $serviceId);
    }
}
if (strpos($inventoryApi, 'r.qty_multiplier') !== false
    || strpos($inventoryApi, "'Service material mappings could not be loaded.'") === false
    || strpos($inventoryApi, "'material_rules' => " . '$materialRules') === false) {
    throw new RuntimeException('The Set Price inventory API must avoid the absent multiplier column and report mapping-query failures instead of returning an empty mapping list.');
}
if (strpos($sw, '/public/app-icon.php?v=') === false
    || strpos($manifest, '/public/app-icon.php?v=') === false
    || strpos($sw, '/public/assets/images/icon-512.png') !== false
    || strpos($sw, "const CACHE_VERSION = 'v20'") === false) {
    throw new RuntimeException('Service-worker app-shell and PWA manifest must use the available dynamic icon.');
}
if (strpos($shopConfig, 'is_file($shop_logo_path)') === false
    || strpos($shopConfig, '$shop_logo_file = \'\';') === false) {
    throw new RuntimeException('Missing configured logos must fall back without emitting broken image URLs.');
}

echo "POS runtime blocker regression checks passed.\n";
