<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migrate_decals_nextjet_rule_optional_20261102.php');
$rollback = (string)file_get_contents($root . '/database/rollback_decals_nextjet_rule_optional_20261102.php');
$bootstrap = (string)file_get_contents($root . '/migrate_db.php');
$defaultMigration = (string)file_get_contents($root . '/database/migrate_service_material_rule_default_20261102.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($migration, 'FOR UPDATE') && str_contains($migration, 'count($rows) !== 1')
    && str_contains($migration, 'SHOW TABLE STATUS') && str_contains($migration, "!== 'INNODB'"),
    'Migration retains row locking, unique target, and InnoDB transaction safeguards.');
$assert(str_contains($migration, 'pfpm_nextjet_is_target_row($row)')
    && str_contains($migration, 'pfpm_nextjet_create_snapshot')
    && str_contains($migration, 'pfpm_nextjet_validate_snapshot')
    && str_contains($migration, 'fflush($handle)')
    && str_contains($migration, 'pfpm_nextjet_matches_migrated_row($updatedRow, $row)')
    && strpos($migration, 'pfpm_nextjet_matches_migrated_row($updatedRow, $row)') < strrpos($migration, '$conn->commit()'),
    'Migration verifies target identity, durable private snapshot, checksum, and the updated row before commit.');
$assert(str_contains($migration, "SET rule_type = 'OPTIONAL', qty_multiplier_approved = 0")
    && !preg_match('/SET\s+qty_multiplier\s*=/i', $migration),
    'Only the targeted rule type and approval audit fields change; multiplier remains untouched.');
$assert(str_contains($rollback, 'pfpm_nextjet_validate_snapshot($snapshot)')
    && str_contains($rollback, 'pfpm_nextjet_matches_migrated_row($current, $row)')
    && str_contains($rollback, 'pfpm_nextjet_matches_original_row($restoredRow, $row)')
    && strpos($rollback, 'pfpm_nextjet_matches_original_row($restoredRow, $row)') < strpos($rollback, '$conn->commit()'),
    'Rollback validates the checksum, requires the exact migrated state, and verifies restoration before commit.');
$assert(str_contains($bootstrap, "`rule_type` varchar(50) DEFAULT 'REQUIRED'")
    && str_contains($defaultMigration, "ALTER COLUMN `rule_type` SET DEFAULT 'OPTIONAL'"),
    'Global default change remains separate and deferred; fresh-install schema retains its existing default.');

echo "Decals Nextjet migration source contract tests passed.\n";
