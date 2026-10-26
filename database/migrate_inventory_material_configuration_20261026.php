<?php
/**
 * Add optional inventory-material specification and usage configuration.
 * Run before deploying code that reads/writes these fields:
 *   php database/migrate_inventory_material_configuration_20261026.php
 *
 * Additive only: no stock, material ID, order, or ledger values are changed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
global $conn;
if (!$conn instanceof mysqli) {
    fwrite(STDERR, "Database connection is unavailable. No schema was changed.\n");
    exit(1);
}

$table = $conn->query("SHOW TABLES LIKE 'inv_items'");
if (!$table || $table->num_rows !== 1) {
    if ($table instanceof mysqli_result) $table->free();
    fwrite(STDERR, "Required inv_items table is unavailable. No schema was changed.\n");
    exit(1);
}
$table->free();

$columns = [
    'size_variant' => ['VARCHAR(100)', "ALTER TABLE `inv_items` ADD COLUMN `size_variant` VARCHAR(100) NULL DEFAULT NULL AFTER `name`"],
    'material_format' => ['VARCHAR(24)', "ALTER TABLE `inv_items` ADD COLUMN `material_format` VARCHAR(24) NULL DEFAULT NULL AFTER `size_variant`"],
    'material_width' => ['DECIMAL(10,3)', "ALTER TABLE `inv_items` ADD COLUMN `material_width` DECIMAL(10,3) NULL DEFAULT NULL AFTER `material_format`"],
    'material_height' => ['DECIMAL(10,3)', "ALTER TABLE `inv_items` ADD COLUMN `material_height` DECIMAL(10,3) NULL DEFAULT NULL AFTER `material_width`"],
    'material_dimension_unit' => ['VARCHAR(16)', "ALTER TABLE `inv_items` ADD COLUMN `material_dimension_unit` VARCHAR(16) NULL DEFAULT NULL AFTER `material_height`"],
    'consumption_method' => ['VARCHAR(32)', "ALTER TABLE `inv_items` ADD COLUMN `consumption_method` VARCHAR(32) NULL DEFAULT NULL AFTER `material_dimension_unit`"],
    'designs_per_unit' => ['INT UNSIGNED', "ALTER TABLE `inv_items` ADD COLUMN `designs_per_unit` INT UNSIGNED NULL DEFAULT NULL AFTER `consumption_method`"],
    'usable_margin_width' => ['DECIMAL(10,3)', "ALTER TABLE `inv_items` ADD COLUMN `usable_margin_width` DECIMAL(10,3) NULL DEFAULT NULL AFTER `designs_per_unit`"],
    'usable_margin_height' => ['DECIMAL(10,3)', "ALTER TABLE `inv_items` ADD COLUMN `usable_margin_height` DECIMAL(10,3) NULL DEFAULT NULL AFTER `usable_margin_width`"],
    'wastage_percent' => ['DECIMAL(6,3)', "ALTER TABLE `inv_items` ADD COLUMN `wastage_percent` DECIMAL(6,3) NULL DEFAULT NULL AFTER `usable_margin_height`"],
];

foreach ($columns as $column => [$expectedType, $sql]) {
    $check = $conn->query("SHOW COLUMNS FROM `inv_items` LIKE '" . $conn->real_escape_string($column) . "'");
    if ($check instanceof mysqli_result && $check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $check->free();
        if (strtolower((string)($row['Type'] ?? '')) !== strtolower($expectedType)
            || strtoupper((string)($row['Null'] ?? '')) !== 'YES'
            || !array_key_exists('Default', $row) || $row['Default'] !== null) {
            fwrite(STDERR, "Existing inv_items.{$column} definition is incompatible; no change was made to that column.\n");
            exit(1);
        }
        continue;
    }
    if ($check instanceof mysqli_result) $check->free();
    if (!$conn->query($sql)) {
        fwrite(STDERR, "Could not add inv_items.{$column}; inspect schema before rerunning.\n");
        exit(1);
    }
}

foreach ($columns as $column => [$expectedType]) {
    $verify = $conn->query("SHOW COLUMNS FROM `inv_items` LIKE '" . $conn->real_escape_string($column) . "'");
    $row = ($verify instanceof mysqli_result && $verify->num_rows === 1) ? $verify->fetch_assoc() : null;
    if ($verify instanceof mysqli_result) $verify->free();
    if (!$row || strtolower((string)($row['Type'] ?? '')) !== strtolower($expectedType)
        || strtoupper((string)($row['Null'] ?? '')) !== 'YES'
        || !array_key_exists('Default', $row) || $row['Default'] !== null) {
        fwrite(STDERR, "Column verification failed for inv_items.{$column}.\n");
        exit(1);
    }
}
fwrite(STDOUT, "Optional material configuration columns are present; existing stock and records were not updated.\n");
