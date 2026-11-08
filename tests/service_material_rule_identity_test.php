<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/service_material_rule_identity.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$canonical = printflow_service_material_rule_identity_scope(67, ['Brochure'], 'r', true);
$assert($canonical['sql'] === '(r.service_id = ? OR (r.service_id IS NULL AND r.service_type IN (?)))', 'Stable ID scope gives Brochure its numeric identity and explicit legacy fallback.');
$assert($canonical['types'] === 'is' && $canonical['params'] === [67, 'Brochure'], 'Prepared parameters are typed and ordered safely.');
$legacy = printflow_service_material_rule_identity_scope(40, ['Tarpaulin Printing'], 'r', true);
$assert($legacy['params'] === [40, 'Tarpaulin Printing'] && str_contains($legacy['sql'], 'r.service_id IS NULL'), 'Tarpaulin preserves its explicit legacy alias until a verified stable mapping is present.');
$preMigration = printflow_service_material_rule_identity_scope(67, ['Brochure'], 'r', false);
$assert($preMigration['sql'] === 'r.service_type IN (?)' && $preMigration['params'] === ['Brochure'], 'Pre-migration reads remain compatible without pretending service_id exists.');

$assert(printflow_service_material_rule_legacy_service_id('Brochure') === 67, 'Canonical Brochure resolves only through the explicit registry.');
$assert(printflow_service_material_rule_legacy_service_id('') === null, 'Blank legacy rows 31/32 remain unassigned.');
$assert(printflow_service_material_rule_legacy_service_id('Souvenirs') === null, 'Legacy Souvenirs rows are not mapped to active Mugs.');
$assert(printflow_service_material_rule_row_service_id(['service_id' => 67, 'service_type' => 'Brochure']) === 67, 'A stable ID with its matching canonical legacy label resolves consistently.');
$assert(printflow_service_material_rule_row_service_id(['service_id' => null, 'service_type' => 'Brochure']) === 67, 'A verified exact legacy label is supported during migration compatibility.');
$assert(printflow_service_material_rule_row_service_id(['service_id' => 36, 'service_type' => 'Poster Printing']) === null, 'A stable ID paired with another service canonical label fails closed.');

echo "Service material stable identity tests passed.\n";
