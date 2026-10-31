<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$rules = $read('includes/pos_service_stock_validation.php');
$inventoryApi = $read('admin/inventory_items_api.php');
$sw = $read('public/sw.php');
$manifest = $read('public/manifest.php');
$shopConfig = $read('includes/shop_config.php');

if (strpos($rules, 'r.qty_multiplier') !== false
    || strpos($rules, 'cannot be calculated from the configured rules') === false
    || strpos($rules, 'A required material mapping for this service could not be resolved.') === false) {
    throw new RuntimeException('POS stock validation must avoid the absent multiplier column and fail closed when authoritative requirements are missing.');
}
foreach ([36, 39, 40, 63, 64, 67, 71] as $serviceId) {
    if (strpos($rules, (string)$serviceId) === false) {
        throw new RuntimeException('A physical customizable POS service is missing its fail-closed mapping guard: ' . $serviceId);
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
