<?php
/**
 * Adds the multiplier column used by POS customizable-service stock checks.
 * Existing rules receive the schema's established 1.00 default.
 *
 * CLI only: php database/migrate_service_material_rule_multiplier_20261022.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable. No schema change was made.\n");
    exit(1);
}

$table = @$conn->query("SHOW TABLES LIKE 'service_material_rules'");
if (!$table || $table->num_rows === 0) {
    if ($table) $table->free();
    fwrite(STDERR, "service_material_rules table is missing. No schema change was made.\n");
    exit(1);
}
$table->free();

if (!db_table_has_column('service_material_rules', 'qty_multiplier', true)) {
    if (!@$conn->query(
        "ALTER TABLE `service_material_rules` ADD COLUMN `qty_multiplier` DECIMAL(10,2) DEFAULT '1.00' AFTER `rule_type`"
    )) {
        fwrite(STDERR, "Could not add service_material_rules.qty_multiplier.\n");
        exit(1);
    }
}

if (!db_table_has_column('service_material_rules', 'qty_multiplier', true)) {
    fwrite(STDERR, "Migration verification failed.\n");
    exit(1);
}

fwrite(STDOUT, "service_material_rules.qty_multiplier is present.\n");
