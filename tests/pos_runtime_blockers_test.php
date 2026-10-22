<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$migration = $read('database/migrate_service_material_rule_multiplier_20261022.php');
$rules = $read('includes/pos_service_stock_validation.php');
$sw = $read('public/sw.php');
$manifest = $read('public/manifest.php');
$shopConfig = $read('includes/shop_config.php');

if (strpos($migration, "PHP_SAPI !== 'cli'") === false
    || strpos($migration, "ADD COLUMN `qty_multiplier` DECIMAL(10,2) DEFAULT '1.00'") === false
    || strpos($migration, "db_table_has_column('service_material_rules', 'qty_multiplier'") === false) {
    throw new RuntimeException('The targeted, idempotent service-rule schema migration contract is missing.');
}
if (strpos($rules, 'r.qty_multiplier') === false) {
    throw new RuntimeException('POS service stock validation must continue using the configured multiplier.');
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
