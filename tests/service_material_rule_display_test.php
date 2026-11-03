<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/service_material_rule_display.php';
$apiSource = (string)file_get_contents(dirname(__DIR__) . '/admin/service_material_rules_api.php');
$uiSource = (string)file_get_contents(dirname(__DIR__) . '/admin/inv_items_management.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$active = [
    ['service_id' => 67, 'name' => 'Brochure', 'status' => 'Activated'],
    ['service_id' => 63, 'name' => 'Mugs', 'status' => 'Activated'],
    ['service_id' => 39, 'name' => 'Plates', 'status' => 'Activated'],
    ['service_id' => 64, 'name' => 'Poster Printing', 'status' => 'Activated'],
    ['service_id' => 71, 'name' => 'Sintraboard Standees', 'status' => 'Activated'],
    ['service_id' => 36, 'name' => 'Stickers /Decals', 'status' => 'Activated'],
    ['service_id' => 40, 'name' => 'Tarpaulin', 'status' => 'Activated'],
    ['service_id' => 88, 'name' => 'Souvenirs', 'status' => 'Deactivated'],
    ['service_id' => 89, 'name' => 'Stickers On Sintraboard', 'status' => 'Activated'],
    ['service_id' => 36, 'name' => 'Poster Printing', 'status' => 'Activated'],
];
$rules = [
    ['id' => 1, 'service_type' => 'Tarpaulin Printing', 'item_id' => 13, 'material_name' => '3ft Tarpaulin', 'inventory_uom' => 'ft', 'rule_type' => 'REQUIRED'],
    ['id' => 2, 'service_type' => 'Souvenirs', 'item_id' => 37, 'material_name' => 'Mug', 'inventory_uom' => 'pcs', 'rule_type' => 'REQUIRED'],
    ['id' => 3, 'service_type' => 'Sintraboard Standees', 'item_id' => 23, 'material_name' => 'Pp Stkr Matte 98', 'inventory_uom' => 'pcs', 'rule_type' => 'REQUIRED'],
    ['id' => 4, 'service_type' => 'Sintraboard Standees', 'item_id' => 1, 'material_name' => 'AC MC', 'inventory_uom' => 'pcs', 'rule_type' => 'OPTIONAL'],
    ['id' => 5, 'service_type' => 'Sintraboard Standees', 'item_id' => 7, 'material_name' => 'SP MC', 'inventory_uom' => 'pcs', 'rule_type' => 'OPTIONAL'],
    ['id' => 6, 'service_type' => 'Stickers On Sintraboard', 'item_id' => 18, 'material_name' => 'Nextjet', 'inventory_uom' => 'pcs', 'rule_type' => 'OPTIONAL'],
    ['id' => 7, 'service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 18, 'material_name' => 'Nextjet', 'inventory_uom' => 'pcs', 'rule_type' => 'OPTIONAL'],
    ['id' => 8, 'service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 20, 'material_name' => 'Gloss Laminate', 'inventory_uom' => 'ft', 'rule_type' => 'OPTIONAL'],
    ['id' => 9, 'service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 21, 'material_name' => 'Matte Laminate', 'inventory_uom' => 'ft', 'rule_type' => 'OPTIONAL'],
    ['id' => 10, 'service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 22, 'material_name' => 'Hologram', 'inventory_uom' => 'pcs', 'rule_type' => 'OPTIONAL'],
    ['id' => 11, 'service_type' => 'Decals', 'item_id' => 18, 'material_name' => 'Nextjet', 'inventory_uom' => 'pcs', 'rule_type' => 'OPTIONAL'],
];
$mugs = [
    'item_id' => 37,
    'material_name' => 'Mug',
    'inventory_uom' => 'pcs',
    'track_by_roll' => false,
];
$groups = printflow_service_material_rule_display_groups($active, $rules, $mugs);
$assert(count($groups) === 7, 'Only seven allowlisted, correctly identified activated services appear once each.');
$byId = [];
foreach ($groups as $group) $byId[(int)$group['service_id']] = $group;
$assert(array_keys($byId) === [67, 63, 39, 64, 71, 36, 40], 'Service group order and IDs match the approved active list.');
foreach ([67, 39, 64] as $id) {
    $assert($byId[$id]['materials'] === [], 'Unmapped service ' . $id . ' shows no guessed materials.');
    $assert($byId[$id]['empty_message'] === 'No materials mapped yet.', 'Unmapped service ' . $id . ' shows the empty configuration message.');
}
$assert(count($byId[63]['materials']) === 1
    && $byId[63]['materials'][0]['item_id'] === 37
    && $byId[63]['materials'][0]['read_only'] === true
    && $byId[63]['materials'][0]['qty_multiplier'] === '1.00', 'Mugs uses only its dedicated, read-only 1:1 pcs POS rule.');
$assert(count($byId[71]['materials']) === 1
    && $byId[71]['materials'][0]['material_name'] === 'Pp Stkr Matte 98'
    && $byId[71]['materials'][0]['rule_type'] === 'OPTIONAL'
    && $byId[71]['materials'][0]['stored_rule_type'] === 'REQUIRED', 'Sintraboard exposes its mapped sticker layer as optional while preserving the stored rule for audit.');
$assert(count($byId[36]['materials']) === 4, 'Decals shows its four verified optional material records.');
$assert(count($byId[40]['materials']) === 1
    && $byId[40]['materials'][0]['service_type'] === 'Tarpaulin Printing', 'Tarpaulin uses only the explicit Tarpaulin Printing alias.');
$configuredNames = [
    39 => ['AC MC', 'AC PH', 'AC EURO', 'AC THAI', 'AC NMC', 'AC HOME', 'SP MC', 'SP PH', 'SP EURO', 'SP THAI', 'SP NMC'],
    67 => ['C2S Board', 'C2S Special Paper'],
    64 => ['C2S Board', 'C2S Special Paper'],
    71 => ['Sintra 5mm', 'Sintra 3mm 32', 'Pp Stkr Matte 98'],
];
$additionalRows = [];
foreach ($configuredNames as $serviceId => $names) {
    $serviceType = [39 => 'Plates', 67 => 'Brochure', 64 => 'Poster Printing', 71 => 'Sintraboard Standees'][$serviceId];
    $itemIdsByName = array_flip(printflow_pos_material_selection_item_names((int)$serviceId));
    foreach ($names as $name) {
        $itemId = (int)$itemIdsByName[$name];
        $alreadyMapped = false;
        foreach ($rules as $existingRule) {
            if (($existingRule['service_type'] ?? '') === $serviceType && (int)($existingRule['item_id'] ?? 0) === $itemId) {
                $alreadyMapped = true;
                break;
            }
        }
        if (!$alreadyMapped) $additionalRows[] = ['service_type' => $serviceType, 'item_id' => $itemId, 'material_name' => $name, 'rule_type' => 'REQUIRED'];
    }
}
$additionalRows[] = ['service_type' => 'Sintraboard Standees', 'material_name' => 'AC MC', 'rule_type' => 'OPTIONAL'];
$expanded = printflow_service_material_rule_display_groups($active, array_merge($rules, $additionalRows), $mugs);
$expandedById = [];
foreach ($expanded as $group) $expandedById[(int)$group['service_id']] = $group;
foreach ($configuredNames as $serviceId => $expectedNames) {
    $actualNames = array_map(static fn(array $row): string => (string)$row['material_name'], $expandedById[$serviceId]['materials']);
    sort($actualNames);
    sort($expectedNames);
    $assert($actualNames === $expectedNames, 'Service ' . $serviceId . ' displays only its exact configured mapping names.');
}
$sintraNames = array_map(static fn(array $row): string => (string)$row['material_name'], $expandedById[71]['materials']);
$assert(!in_array('AC MC', $sintraNames, true) && !in_array('SP MC', $sintraNames, true), 'Plate materials remain excluded from Sintraboard.');
$assert(count($expandedById[63]['materials']) === 1 && $expandedById[63]['materials'][0]['item_id'] === 37, 'Expanding other mappings leaves the dedicated Mugs rule unchanged.');
$assert(!printflow_service_material_rule_display_row_is_editable('Souvenirs', 37, $active), 'Legacy Souvenirs rules cannot be mutated from this page.');
$assert(!printflow_service_material_rule_display_row_is_editable('Stickers On Sintraboard', 18, $active), 'Unverified legacy service aliases cannot be mutated.');
$assert(!printflow_service_material_rule_display_row_is_editable('Sintraboard Standees', 1, $active)
    && !printflow_service_material_rule_display_row_is_editable('Sintraboard Standees', 7, $active), 'Plate materials cannot be mutated through the Sintraboard display.');
$assert(printflow_service_material_rule_display_row_is_editable('Sintraboard Standees', 23, $active, 'Pp Stkr Matte 98'), 'The verified Sintraboard rule remains configurable.');
$assert(!printflow_service_material_rule_display_row_is_editable('Sintraboard Standees', 23, $active, 'Changed inventory name'), 'Changed item identity cannot be mutated through the display API.');
$assert(!printflow_service_material_rule_display_row_is_editable('Tarpaulin Printing', 13, array_filter($active, static fn($row) => (int)$row['service_id'] !== 40)), 'Inactive/missing service cannot receive rule changes.');
$assert(str_contains($apiSource, "WHERE status = 'Activated'")
    && str_contains($apiSource, 'printflow_service_material_rule_display_groups($activeServices ?: [], $ruleRows, $mugsRule)'), 'Admin API derives display groups from the activated service catalog and explicit registry.');
$assert(!str_contains($apiSource, 'SELECT MIN(s2.service_id)')
    && str_contains($apiSource, 'INNER JOIN inv_items i ON i.id = r.item_id AND UPPER(TRIM(i.status)) = \'ACTIVE\''), 'Fuzzy service-name joins are removed and inactive materials are not offered.');
$assert(str_contains($apiSource, 'printflow_pos_service_material_type_aliases((int)$serviceId)')
    && str_contains($apiSource, 'service_type IN ({$typePlaceholders})'), 'Admin rule creation checks explicit accepted service aliases to prevent duplicate canonical rules.');
$assert(str_contains($uiSource, 'Array.isArray(data.groups)')
    && str_contains($uiSource, 'group.materials.map')
    && str_contains($uiSource, 'No materials mapped yet.')
    && str_contains($uiSource, 'Dedicated active Mugs POS rule'), 'Admin UI renders explicit groups, empty states, and the read-only Mugs POS rule.');

echo "Service material rule display tests passed.\n";
