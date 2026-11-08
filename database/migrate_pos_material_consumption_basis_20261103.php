<?php
declare(strict_types=1);
/** Add an explicit consumption basis to service material approvals. CLI only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
global $conn;
if (!$conn instanceof mysqli) { fwrite(STDERR, "Database connection unavailable. No schema changed.\n"); exit(1); }
$engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_material_rules'");
$engineRow = $engine ? $engine->fetch_assoc() : null;
if (!$engineRow || strtoupper((string)$engineRow['ENGINE']) !== 'INNODB') { fwrite(STDERR, "service_material_rules must be InnoDB. No schema changed.\n"); exit(1); }
$requiredColumns = ['qty_multiplier', 'qty_multiplier_approved_at'];
foreach ($requiredColumns as $name) {
    $result = $conn->query("SHOW COLUMNS FROM `service_material_rules` LIKE '" . $conn->real_escape_string($name) . "'");
    if (!$result || !$result->fetch_assoc()) {
        fwrite(STDERR, "Required anchor column {$name} is missing or could not be inspected. No schema change attempted.\n");
        exit(1);
    }
}
$expected = [
    'qty_multiplier_basis' => ['varchar(24)', 'ALTER TABLE `service_material_rules` ADD COLUMN `qty_multiplier_basis` VARCHAR(24) NULL DEFAULT NULL AFTER `qty_multiplier`'],
    'qty_multiplier_approved_basis' => ['varchar(24)', 'ALTER TABLE `service_material_rules` ADD COLUMN `qty_multiplier_approved_basis` VARCHAR(24) NULL DEFAULT NULL AFTER `qty_multiplier_approved_at`'],
];
$existing = [];
foreach ($expected as $name => [$type, $sql]) {
    $result = $conn->query("SHOW COLUMNS FROM `service_material_rules` LIKE '" . $conn->real_escape_string($name) . "'");
    if (!$result) { fwrite(STDERR, "Could not inspect {$name}; no schema change attempted.\n"); exit(1); }
    $column = $result->fetch_assoc();
    if ($column && (strtolower((string)$column['Type']) !== $type || strtoupper((string)$column['Null']) !== 'YES' || $column['Default'] !== null)) {
        fwrite(STDERR, "Existing {$name} definition is incompatible; no schema change attempted.\n"); exit(1);
    }
    $existing[$name] = (bool)$column;
}
foreach ($expected as $name => [$type, $sql]) {
    if (!$existing[$name]) {
        if (!$conn->query($sql)) { fwrite(STDERR, "Could not add {$name}; a prior additive column may already have been added. Inspect schema before rerunning.\n"); exit(1); }
    }
    $verify = $conn->query("SHOW COLUMNS FROM `service_material_rules` LIKE '" . $conn->real_escape_string($name) . "'");
    $verified = $verify ? $verify->fetch_assoc() : null;
    if (!$verified || strtolower((string)$verified['Type']) !== $type || strtoupper((string)$verified['Null']) !== 'YES' || $verified['Default'] !== null) {
        fwrite(STDERR, "Schema verification failed for {$name}.\n"); exit(1);
    }
}
fwrite(STDOUT, "Consumption basis columns are present. Existing approvals have no approved basis and remain invalid for dimension-based POS demand until Admin re-saves and re-approves them.\n");
