<?php
/**
 * Add the durable one-time design fee and exact staff-final line total to
 * order line items. The override is NULL for normal calculated lines.
 *
 * Run only after an owner-approved database backup and schema-first rollout:
 *   php database/migrate_order_items_design_fee_20261024.php
 *
 * This migration is additive and idempotent. It does not update existing rows;
 * the DEFAULT 0.00 preserves historical totals and old application inserts.
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

$tableResult = $conn->query("SHOW TABLES LIKE 'order_items'");
if (!$tableResult || $tableResult->num_rows !== 1) {
    if ($tableResult instanceof mysqli_result) $tableResult->free();
    fwrite(STDERR, "Required order_items table is unavailable. No schema was changed.\n");
    exit(1);
}
$tableResult->free();

foreach ([
    'design_fee' => "ALTER TABLE `order_items` ADD COLUMN `design_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`",
    'line_total_override' => "ALTER TABLE `order_items` ADD COLUMN `line_total_override` DECIMAL(10,2) NULL DEFAULT NULL AFTER `design_fee`",
] as $column => $sql) {
    $columnResult = $conn->query("SHOW COLUMNS FROM `order_items` LIKE '" . $conn->real_escape_string($column) . "'");
    if ($columnResult instanceof mysqli_result && $columnResult->num_rows > 0) {
        $columnResult->free();
        continue;
    }
    if ($columnResult instanceof mysqli_result) $columnResult->free();
    if (!$conn->query($sql)) {
        fwrite(STDERR, "Could not add order_items.{$column}. Review the database error; already-added additive columns are safe to leave in place.\n");
        exit(1);
    }
}

foreach (['design_fee', 'line_total_override'] as $column) {
    $verify = $conn->query("SHOW COLUMNS FROM `order_items` LIKE '" . $conn->real_escape_string($column) . "'");
    if (!$verify || $verify->num_rows !== 1) {
        if ($verify instanceof mysqli_result) $verify->free();
        fwrite(STDERR, "Column verification failed for order_items.{$column}.\n");
        exit(1);
    }
    $verify->free();
}
fwrite(STDOUT, "Design fee columns are present; historical rows were not backfilled.\n");
