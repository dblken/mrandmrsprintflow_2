<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migrate_service_material_rule_service_id_20261103.php');
$freshSchema = (string)file_get_contents($root . '/migrate_db.php');
$identity = (string)file_get_contents($root . '/includes/service_material_rule_identity.php');
$selection = (string)file_get_contents($root . '/includes/pos_material_selection.php');
$api = (string)file_get_contents($root . '/admin/service_material_rules_api.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($migration, "if (PHP_SAPI !== 'cli')")
    && str_contains($migration, '--backup-restore-verified=')
    && str_contains($migration, '--target-db='), 'Migration is CLI-only and refuses to start without explicit target and restore-tested-backup reference.');
$assert(str_contains($migration, 'strtolower((string)$serviceType[\'DATA_TYPE\']) === \'enum\'')
    && str_contains($migration, 'MODIFY service_type VARCHAR(')
    && str_contains($migration, 'ADD COLUMN service_id'), 'Migration widens the legacy ENUM to VARCHAR and adds nullable stable service identity.');
$assert(str_contains($migration, 'SHOW CREATE TABLE service_material_rules')
    && str_contains($migration, 'SELECT * FROM service_material_rules ORDER BY id')
    && str_contains($migration, '0700') && str_contains($migration, '0600')
    && str_contains($migration, 'hash(\'sha256\', $json)'), 'Private snapshot records full table definition and rows with restrictive permissions and SHA-256 verification.');
$assert(str_contains($migration, 'ENGINE') && str_contains($migration, 'INNODB'), 'Migration requires an InnoDB table.');
$assert(str_contains($migration, 'WHERE id IN (31, 32)')
    && str_contains($migration, '(string)$row[\'service_type\'] !== \'\'')
    && str_contains($migration, '(int)$row[\'item_id\'] !== 212')
    && !preg_match('/UPDATE\\s+service_material_rules[^;]*WHERE\\s+id\\s+IN\\s*\\(31\\s*,\\s*32\\)/is', $migration), 'Rows 31/32 are guarded as blank, ambiguous rows and are never targeted by a repair UPDATE.');
$assert(str_contains($migration, '$isVerifiedPair')
    && str_contains($migration, '!in_array((string)($row[\'service_type\'] ?? \'\'), $mapping[$serviceId] ?? [], true)')
    && str_contains($migration, '36 => [18 => \'Nextjet\'')
    && str_contains($migration, '67 => [212 => \'C2s Board\', 211 => \'C2S Special Paper\']')
    && preg_match("/\\['name'\\] !== 'C2s Board'/", $migration) === 1
    && str_contains($selection, "67 => [212 => 'C2s Board'")
    && str_contains($migration, 'service_id = ? WHERE id = ? AND service_id IS NULL AND BINARY service_type = BINARY ? AND item_id = ?'), 'Backfill is limited to exact verified item-ID/name and service alias pairs, one guarded row ID at a time.');
$assert(str_contains($migration, 'uq_smr_service_item')
    && str_contains($migration, 'Duplicate stable service/material mappings remain after backfill.'), 'Migration preflights duplicates and installs the stable service/item uniqueness constraint.');
$assert(str_contains($migration, 'begin_transaction()')
    && str_contains($migration, 'rollback()')
    && str_contains($migration, 'Exact backfill verification failed'), 'Backfill runs transactionally and verifies exact rows before commit.');
$assert(str_contains($migration, 'rows 31/32') || str_contains($migration, 'Ambiguous rows 31/32'), 'Ambiguous historical rows are explicitly preserved.');
$assert(str_contains($freshSchema, '`service_id` int(11) DEFAULT NULL')
    && str_contains($freshSchema, '`qty_multiplier_basis` varchar(24) DEFAULT NULL')
    && str_contains($freshSchema, '`qty_multiplier_approved_basis` varchar(24) DEFAULT NULL')
    && str_contains($freshSchema, 'UNIQUE KEY `uq_smr_service_item`'), 'Fresh-install schema includes stable nullable identity, multiplier bases, and duplicate protection.');
$assert(str_contains($identity, 'service_id = ?') && str_contains($identity, 'service_id IS NULL')
    && str_contains($api, 'Stable service identity is not installed yet'), 'Runtime prefers stable IDs, supports explicit legacy rows, and refuses writes before migration.');
$assert(!preg_match('/(?:DELETE|UPDATE)\\s+service_material_rules[^;]*qty_multiplier|(?:DELETE|UPDATE)\\s+service_material_rules[^;]*qty_multiplier_approved/is', $migration), 'Migration never changes multiplier or approval fields.');

echo "Service material service_id migration contract tests passed.\n";
