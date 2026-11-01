<?php
declare(strict_types=1);

$migration = (string)file_get_contents(dirname(__DIR__) . '/database/migrate_service_material_rule_multiplier_20261022.php');
$bootstrap = (string)file_get_contents(dirname(__DIR__) . '/migrate_db.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($migration, 'ADD COLUMN `qty_multiplier` DECIMAL(10,2) NULL DEFAULT NULL AFTER `rule_type`'),
    'New multiplier columns leave existing rules unapproved with NULL values.');
$assert(str_contains($bootstrap, '`qty_multiplier` decimal(10,2) DEFAULT NULL')
    && !str_contains($bootstrap, '`qty_multiplier` decimal(10,2) DEFAULT \'1.00\''),
    'Fresh database bootstrap does not seed implicit 1:1 consumption.');
$assert(str_contains($bootstrap, '`qty_multiplier_approved` tinyint(1) NOT NULL DEFAULT 0'),
    'Fresh database bootstrap requires explicit multiplier approval.');
$assert(str_contains($bootstrap, '`qty_multiplier_approved_by` int(11) DEFAULT NULL')
    && str_contains($bootstrap, '`qty_multiplier_approved_at` datetime DEFAULT NULL'),
    'Fresh schemas include nullable approval audit fields.');
$assert(str_contains($migration, "'INT(11) NULL DEFAULT NULL'")
    && str_contains($migration, "'DATETIME NULL DEFAULT NULL'")
    && str_contains($migration, "strtolower((string)(\$approvedByColumn['Type'] ?? '')) !== 'int(11)'")
    && str_contains($migration, "strtoupper((string)(\$approvedByColumn['Null'] ?? '')) !== 'YES'")
    && str_contains($migration, "\$approvedByColumn['Default'] !== null")
    && str_contains($migration, "strtolower((string)(\$approvedAtColumn['Type'] ?? '')) !== 'datetime'")
    && str_contains($migration, "strtoupper((string)(\$approvedAtColumn['Null'] ?? '')) !== 'YES'")
    && str_contains($migration, "\$approvedAtColumn['Default'] !== null"),
    'The migration adds and verifies the exact production audit column types, nullability, and NULL defaults.');
$assert(str_contains($migration, 'ADD COLUMN `qty_multiplier_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `qty_multiplier`'),
    'Approval is explicit and defaults off for every existing and newly inserted rule.');
$assert(str_contains($migration, 'MODIFY COLUMN `qty_multiplier` DECIMAL(10,2) NULL DEFAULT NULL'),
    'Legacy implicit numeric defaults are removed without rewriting stored multiplier values.');
$assert(str_contains($migration, <<<'SOURCE'
strtolower((string)($column['Type'] ?? '')) === 'decimal(10,2)'
SOURCE
),
    'An existing multiplier column must have the intended numeric type.');
$assert(str_contains($migration, <<<'SOURCE'
strtoupper((string)($column['Null'] ?? '')) === 'YES'
SOURCE
),
    'An existing multiplier column must match the migration nullability.');
$assert(str_contains($migration, <<<'SOURCE'
$column['Default'] === null
SOURCE
), 'The verified multiplier column must have a SQL NULL default.');
$assert(str_contains($migration, <<<'SOURCE'
(string)($currentColumn['Default'] ?? '') !== ''
SOURCE
),
    'A legacy non-NULL default is corrected without changing existing values.');
$assert(str_contains($migration, '$matchesExpectedMultiplierDefinition($readMultiplierColumn())'),
    'The final multiplier schema must have a NULL default.');
$assert(str_contains($migration, '(string)($column[\'Default\'] ?? \'\') === \'0\''),
    'The approval column must default to unapproved.');
$assert(str_contains($migration, <<<'SOURCE'
strtolower((string)($column['Type'] ?? '')) === 'tinyint(1)'
SOURCE
)
    && str_contains($migration, <<<'SOURCE'
strtoupper((string)($column['Null'] ?? '')) === 'NO'
SOURCE
), 'The approval marker must be NOT NULL TINYINT(1).');
$assert(str_contains($migration, "SET `qty_multiplier_approved` = 0, `qty_multiplier_approved_by` = NULL, `qty_multiplier_approved_at` = NULL")
    && str_contains($migration, "WHERE `qty_multiplier_approved` = 1 AND (`qty_multiplier_approved_by` IS NULL OR `qty_multiplier_approved_at` IS NULL)"),
    'Only legacy approvals missing audit evidence are invalidated; multiplier values remain unchanged.');
$assert(!preg_match('/UPDATE\s+`?service_material_rules`?\s+SET\s+[^\n]*(?:`?id`?|`?service_type`?|`?item_id`?|`?rule_type`?|`?qty_multiplier`?)\s*=/i', $migration)
    && !preg_match('/\b(?:INSERT\s+INTO|DELETE\s+FROM)\s+`?service_material_rules\b/i', $migration),
    'Migration data correction cannot rewrite rule IDs, mappings, types, or multiplier values, and cannot insert/delete rule rows.');
$assert(str_contains($migration, 'elseif (!$matchesMultiplierTypeAndNullability($currentColumn))'),
    'An incompatible existing definition stops the migration without alteration.');
$assert(str_contains($migration, "DEFAULT 0 AFTER `qty_multiplier`")
    && str_contains($migration, '`qty_multiplier_approved` = 1 AND (`qty_multiplier_approved_by` IS NULL OR `qty_multiplier_approved_at` IS NULL)'),
    'New production rows default to unapproved, and only audit-incomplete legacy approvals are reset.');

echo "POS service material migration contract tests passed.\n";
