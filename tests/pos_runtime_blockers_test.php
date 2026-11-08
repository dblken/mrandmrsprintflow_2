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

if (strpos($rules, 'Non-Tarpaulin POS captures customer specifications only. Exact') === false
    || strpos($rules, 'material and quantity are checked after staff records actual use.') === false
    || strpos($rules, 'printflow_pos_material_demand_for_area(') !== false) {
    throw new RuntimeException('Active POS material demand must not be calculated from service multipliers or dimensions.');
}
if (strpos($rules, "if (!array_key_exists('pos_material_assignments', \$line))") === false
    || strpos($rules, 'printflow_pos_service_line_job_materials($line)') === false
    || strpos($rules, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)') === false
    || strpos($rules, 'printflow_pos_service_stock_exceeds_available') === false) {
    throw new RuntimeException('POS must defer unspecified material usage, then validate explicit assigned quantities against branch stock.');
}
if (strpos($stockHelpers, 'printflow_pos_select_service_material_rule_set') === false
    || strpos($inventoryApi, "db_table_has_column('service_material_rules', 'qty_multiplier', true)") === false
    || strpos($inventoryApi, "db_table_has_column('service_material_rules', 'qty_multiplier_basis', true)") === false
    || strpos($inventoryApi, "'material_rules' => " . '$materialRules') === false) {
    throw new RuntimeException('Admin must retain schema-safe access to existing material-rule configuration without using it to calculate staff-entered quantities.');
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
