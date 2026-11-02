<?php
/**
 * Adds an unapproved multiplier slot for POS customizable-service stock checks.
 * Existing rules remain unapproved until an authorized operator sets a multiplier
 * and explicitly marks it approved. This migration never assigns consumption.
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

$table = @$conn->query('SHOW TABLES');
if (!$table) {
    fwrite(STDERR, "Could not inspect the service_material_rules table. No schema change was made.\n");
    exit(1);
}
$tableFound = false;
while ($row = $table->fetch_row()) {
    if ((string)($row[0] ?? '') === 'service_material_rules') {
        $tableFound = true;
        break;
    }
}
$table->free();
if (!$tableFound) {
    fwrite(STDERR, "service_material_rules table is missing. No schema change was made.\n");
    exit(1);
}

$readMultiplierColumn = static function () use ($conn): ?array {
    $result = @$conn->query('SHOW COLUMNS FROM `service_material_rules`');
    if (!$result) {
        fwrite(STDERR, "Could not inspect service_material_rules.qty_multiplier. No schema change was made.\n");
        exit(1);
    }
    $column = null;
    while ($row = $result->fetch_assoc()) {
        if ((string)($row['Field'] ?? '') === 'qty_multiplier') {
            $column = $row;
            break;
        }
    }
    $result->free();
    return $column;
};
$matchesMultiplierTypeAndNullability = static function (?array $column): bool {
    return $column !== null
        && strtolower((string)($column['Type'] ?? '')) === 'decimal(10,2)'
        && strtoupper((string)($column['Null'] ?? '')) === 'YES';
};
$matchesExpectedMultiplierDefinition = static function (?array $column) use ($matchesMultiplierTypeAndNullability): bool {
    return $matchesMultiplierTypeAndNullability($column)
        && array_key_exists('Default', $column)
        && $column['Default'] === null;
};
$matchesApprovalDefinition = static function (?array $column): bool {
    return $column !== null
        && strtolower((string)($column['Type'] ?? '')) === 'tinyint(1)'
        && strtoupper((string)($column['Null'] ?? '')) === 'NO'
        && (string)($column['Default'] ?? '') === '0';
};

$currentColumn = $readMultiplierColumn();
if ($currentColumn === null) {
    if (!@$conn->query(
        "ALTER TABLE `service_material_rules` ADD COLUMN `qty_multiplier` DECIMAL(10,2) NULL DEFAULT NULL AFTER `rule_type`"
    )) {
        fwrite(STDERR, "Could not add service_material_rules.qty_multiplier.\n");
        exit(1);
    }
} elseif (!$matchesMultiplierTypeAndNullability($currentColumn)) {
    fwrite(STDERR, "Existing service_material_rules.qty_multiplier definition is incompatible; no schema change was made.\n");
    exit(1);
} elseif ((string)($currentColumn['Default'] ?? '') !== '') {
    // Earlier versions used DEFAULT 1.00. Change only the default; preserve every
    // stored value because a numeric value may already have been explicitly approved.
    if (!@$conn->query(
        "ALTER TABLE `service_material_rules` MODIFY COLUMN `qty_multiplier` DECIMAL(10,2) NULL DEFAULT NULL"
    )) {
        fwrite(STDERR, "Could not remove the implicit qty_multiplier default. No rule values were changed.\n");
        exit(1);
    }
}

$readApprovalColumn = static function () use ($conn): ?array {
    $result = @$conn->query('SHOW COLUMNS FROM `service_material_rules`');
    if (!$result) {
        fwrite(STDERR, "Could not inspect service_material_rules.qty_multiplier_approved. No schema change was made.\n");
        exit(1);
    }
    $column = null;
    while ($row = $result->fetch_assoc()) {
        if ((string)($row['Field'] ?? '') === 'qty_multiplier_approved') {
            $column = $row;
            break;
        }
    }
    $result->free();
    return $column;
};

$approvalColumn = $readApprovalColumn();
if ($approvalColumn === null) {
    if (!@$conn->query(
        "ALTER TABLE `service_material_rules` ADD COLUMN `qty_multiplier_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `qty_multiplier`"
    )) {
        fwrite(STDERR, "Could not add service_material_rules.qty_multiplier_approved.\n");
        exit(1);
    }
} elseif (!$matchesApprovalDefinition($approvalColumn)) {
    fwrite(STDERR, "Existing service_material_rules.qty_multiplier_approved definition is incompatible; no schema change was made.\n");
    exit(1);
}

// Approval audit is additive. Legacy approvals cannot be attributed retroactively,
// so invalidate only approvals that lack complete audit evidence. No rule is
// automatically approved and no multiplier value is modified.
$readColumn = static function (string $name) use ($conn): ?array {
    $safeName = str_replace('`', '', $name);
    $result = @$conn->query('SHOW COLUMNS FROM `service_material_rules`');
    if (!$result) {
        fwrite(STDERR, "Could not inspect service material approval audit schema. No schema change was made.\n");
        exit(1);
    }
    $column = null;
    while ($row = $result->fetch_assoc()) {
        if ((string)($row['Field'] ?? '') === $safeName) { $column = $row; break; }
    }
    $result->free();
    return $column;
};
$ensureAuditColumn = static function (string $name, string $definition, string $after) use ($readColumn, $conn): bool {
    $column = $readColumn($name);
    if ($column === null) {
        return (bool)@$conn->query("ALTER TABLE `service_material_rules` ADD COLUMN `{$name}` {$definition} AFTER `{$after}`");
    }
    return true;
};
if (!$ensureAuditColumn('qty_multiplier_approved_by', 'INT(11) NULL DEFAULT NULL', 'qty_multiplier_approved')
    || !$ensureAuditColumn('qty_multiplier_approved_at', 'DATETIME NULL DEFAULT NULL', 'qty_multiplier_approved_by')) {
    fwrite(STDERR, "Could not add service material approval audit columns.\n");
    exit(1);
}
$approvedByColumn = $readColumn('qty_multiplier_approved_by');
$approvedAtColumn = $readColumn('qty_multiplier_approved_at');
if ($approvedByColumn === null || strtolower((string)($approvedByColumn['Type'] ?? '')) !== 'int(11)'
    || strtoupper((string)($approvedByColumn['Null'] ?? '')) !== 'YES' || $approvedByColumn['Default'] !== null
    || $approvedAtColumn === null || strtolower((string)($approvedAtColumn['Type'] ?? '')) !== 'datetime'
    || strtoupper((string)($approvedAtColumn['Null'] ?? '')) !== 'YES' || $approvedAtColumn['Default'] !== null) {
    fwrite(STDERR, "Approval audit columns have an incompatible definition.\n");
    exit(1);
}
if (!@$conn->query(
    "UPDATE `service_material_rules` SET `qty_multiplier_approved` = 0, `qty_multiplier_approved_by` = NULL, `qty_multiplier_approved_at` = NULL
     WHERE `qty_multiplier_approved` = 1 AND (`qty_multiplier_approved_by` IS NULL OR `qty_multiplier_approved_at` IS NULL)"
)) {
    fwrite(STDERR, "Could not invalidate legacy approvals without audit evidence.\n");
    exit(1);
}

if (!$matchesExpectedMultiplierDefinition($readMultiplierColumn())
    || !$matchesApprovalDefinition($readApprovalColumn())
    || $readColumn('qty_multiplier_approved_by') === null
    || $readColumn('qty_multiplier_approved_at') === null) {
    fwrite(STDERR, "Migration verification failed: multiplier, approval, and audit schema is incomplete.\n");
    exit(1);
}

fwrite(STDOUT, "service_material_rules multiplier and approval audit schema is present; legacy approvals without audit evidence are unapproved.\n");
