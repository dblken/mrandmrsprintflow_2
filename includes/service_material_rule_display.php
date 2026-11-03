<?php
declare(strict_types=1);

/**
 * Read-only registry for the Admin Service Material Rules display.
 * service_material_rules has no service_id column, so only explicitly verified
 * stored service_type keys may be associated with an active service ID here.
 */
function printflow_service_material_rule_display_registry(): array
{
    return [
        67 => ['name' => 'Brochure', 'service_types' => []],
        63 => ['name' => 'Mugs', 'service_types' => []], // Dedicated POS rule, never Souvenirs rows.
        39 => ['name' => 'Plates', 'service_types' => []],
        64 => ['name' => 'Poster Printing', 'service_types' => []],
        71 => ['name' => 'Sintraboard Standees', 'service_types' => ['Sintraboard Standees'], 'item_names' => [23 => 'Pp Stkr Matte 98']],
        36 => ['name' => 'Stickers /Decals', 'service_types' => ['Decals/Stickers (Print/Cut)'], 'item_names' => [18 => 'Nextjet', 20 => 'Gloss Laminate', 21 => 'Matte Laminate', 22 => 'Hologram']],
        40 => ['name' => 'Tarpaulin', 'service_types' => ['Tarpaulin Printing']],
    ];
}

/** Build the seven active display groups without changing or inferring stored mappings. */
function printflow_service_material_rule_display_groups(array $activeServices, array $ruleRows, ?array $mugsRule = null): array
{
    $registry = printflow_service_material_rule_display_registry();
    $activeById = [];
    foreach ($activeServices as $service) {
        if (!is_array($service) || strtoupper(trim((string)($service['status'] ?? ''))) !== 'ACTIVATED') continue;
        $id = (int)($service['service_id'] ?? 0);
        $expected = $registry[$id] ?? null;
        if (!$expected || strcasecmp(trim((string)($service['name'] ?? '')), $expected['name']) !== 0) continue;
        $activeById[$id] = $service;
    }

    $groups = [];
    foreach ($registry as $serviceId => $definition) {
        if (!isset($activeById[$serviceId])) continue;
        $service = $activeById[$serviceId];
        $materials = [];
        if ($serviceId === 63) {
            if ($mugsRule !== null
                && (int)($mugsRule['item_id'] ?? 0) === 37
                && strtolower(trim((string)($mugsRule['material_name'] ?? ''))) === 'mug'
                && strtolower(trim((string)($mugsRule['inventory_uom'] ?? ''))) === 'pcs'
                && empty($mugsRule['track_by_roll'])) {
                $materials[] = [
                    'id' => null,
                    'service_type' => 'Mugs',
                    'service_name' => $definition['name'],
                    'service_id' => $serviceId,
                    'item_id' => 37,
                    'material_name' => (string)$mugsRule['material_name'],
                    'inventory_uom' => (string)$mugsRule['inventory_uom'],
                    'rule_type' => 'REQUIRED',
                    'qty_multiplier' => '1.00',
                    'qty_multiplier_approved' => null,
                    'qty_multiplier_approved_by' => null,
                    'qty_multiplier_approved_at' => null,
                    'display_source' => 'dedicated_pos_rule',
                    'read_only' => true,
                ];
            }
        } else {
            foreach ($ruleRows as $row) {
                if (!is_array($row)) continue;
                if (!in_array((string)($row['service_type'] ?? ''), $definition['service_types'], true)) continue;
                if (isset($definition['item_names'])) {
                    $itemId = (int)($row['item_id'] ?? 0);
                    $expectedItemName = $definition['item_names'][$itemId] ?? null;
                    if ($expectedItemName === null || strcasecmp(trim((string)($row['material_name'] ?? '')), $expectedItemName) !== 0) continue;
                }
                $row['service_id'] = $serviceId;
                $row['service_name'] = $definition['name'];
                $row['display_source'] = 'service_material_rules';
                $row['read_only'] = false;
                $materials[] = $row;
            }
        }
        $groups[] = [
            'service_id' => $serviceId,
            'service_name' => $definition['name'],
            'materials' => $materials,
            'empty_message' => $serviceId === 63
                ? 'Dedicated Mugs POS rule is unavailable; no Souvenirs rules are used.'
                : 'No materials mapped yet.',
        ];
    }
    return $groups;
}

/** Return true only for persisted rows the Admin display exposes as editable. */
function printflow_service_material_rule_display_row_is_editable(string $serviceType, int $itemId, array $activeServices, ?string $itemName = null): bool
{
    foreach (printflow_service_material_rule_display_registry() as $serviceId => $definition) {
        if ($definition['service_types'] === [] || !in_array($serviceType, $definition['service_types'], true)) continue;
        if (isset($definition['item_names'])) {
            $expectedName = $definition['item_names'][$itemId] ?? null;
            if ($expectedName === null || $itemName === null || strcasecmp(trim($itemName), $expectedName) !== 0) continue;
        }
        foreach ($activeServices as $service) {
            if (!is_array($service)
                || (int)($service['service_id'] ?? 0) !== $serviceId
                || strtoupper(trim((string)($service['status'] ?? ''))) !== 'ACTIVATED'
                || strcasecmp(trim((string)($service['name'] ?? '')), $definition['name']) !== 0) continue;
            return true;
        }
    }
    return false;
}
