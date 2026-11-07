<?php
declare(strict_types=1);
require_once __DIR__ . '/pos_material_selection.php';
require_once __DIR__ . '/service_material_rule_identity.php';

/**
 * Read-only registry for the Admin Service Material Rules display.
 * Stable service_id is authoritative after migration. Before migration, only
 * explicitly verified stored service_type keys associate rows with a service.
 */
function printflow_service_material_rule_display_registry(): array
{
    return [
        67 => ['name' => 'Brochure', 'service_types' => ['Brochure'], 'material_names' => ['C2s Board', 'C2S Special Paper'], 'item_names' => [212 => 'C2s Board', 211 => 'C2S Special Paper']],
        63 => ['name' => 'Mugs', 'service_types' => []], // Dedicated POS rule, never Souvenirs rows.
        39 => ['name' => 'Plates', 'service_types' => ['Plates'], 'material_names' => [
            'AC MC', 'AC PH', 'AC EURO', 'AC THAI', 'AC NMC', 'AC HOME',
            'SP MC', 'SP PH', 'SP EURO', 'SP THAI', 'SP NMC',
        ], 'item_names' => [1 => 'AC MC', 2 => 'AC PH', 3 => 'AC EURO', 4 => 'AC THAI', 5 => 'AC NMC', 6 => 'AC HOME', 7 => 'SP MC', 8 => 'SP PH', 9 => 'SP EURO', 10 => 'SP THAI', 11 => 'SP NMC']],
        64 => ['name' => 'Poster Printing', 'service_types' => ['Poster Printing'], 'material_names' => ['C2s Board', 'C2S Special Paper'], 'item_names' => [212 => 'C2s Board', 211 => 'C2S Special Paper']],
        71 => ['name' => 'Sintraboard Standees', 'service_types' => ['Sintraboard Standees'], 'material_names' => ['Sintra 5mm', 'Sintra 3mm 32', 'Pp Stkr Matte 98'], 'item_names' => [207 => 'Sintra 5mm', 206 => 'Sintra 3mm 32', 23 => 'Pp Stkr Matte 98']],
        36 => [
            'name' => 'Stickers /Decals',
            'service_types' => ['Decals/Stickers (Print/Cut)'],
            'material_names' => array_values(array_unique(array_merge(
                ['Nextjet', 'Gloss Laminate', 'Matte Laminate', 'Hologram'],
                array_values(printflow_stickers_decals_colored_sticker_item_names()),
                array_map(static fn(string $name): string => ucwords(strtolower($name)), printflow_stickers_decals_colored_sticker_item_names())
            ))),
            'item_names' => [
                18 => 'Nextjet',
                20 => 'Gloss Laminate',
                21 => 'Matte Laminate',
                22 => 'Hologram',
            ] + printflow_stickers_decals_colored_sticker_item_names(),
        ],
        40 => [
            'name' => 'Tarpaulin',
            'service_types' => ['Tarpaulin Printing'],
            // Rolls plus optional Eyelet extras (4 standard eyelets come with the roll).
            'material_names' => ['3ft Tarpaulin', '4ft Tarpaulin', '5ft Tarpaulin', '6ft Tarpaulin', 'Eyelet'],
        ],
    ];
}

/** The display accepts only registry-approved legacy keys; a saved numeric ID takes precedence. */
function printflow_service_material_rule_display_service_id(array $row): ?int
{
    $registry = printflow_service_material_rule_display_registry();
    $serviceType = (string)($row['service_type'] ?? '');
    $stableId = (int)($row['service_id'] ?? 0);
    if ($stableId > 0) {
        $definition = $registry[$stableId] ?? null;
        return $definition && in_array($serviceType, $definition['service_types'], true) ? $stableId : null;
    }
    foreach ($registry as $serviceId => $definition) {
        if (in_array($serviceType, $definition['service_types'], true)) return (int)$serviceId;
    }
    return null;
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
                if (printflow_service_material_rule_display_service_id($row) !== (int)$serviceId) continue;
                if (isset($definition['material_names'])) {
                    $allowedName = false;
                    foreach ($definition['material_names'] as $materialName) {
                        if (strcasecmp(trim((string)($row['material_name'] ?? '')), $materialName) === 0) {
                            $allowedName = true;
                            break;
                        }
                    }
                    if (!$allowedName) continue;
                }
                if (isset($definition['item_names'])) {
                    $itemId = (int)($row['item_id'] ?? 0);
                    $expectedItemName = $definition['item_names'][$itemId] ?? null;
                    $rowMaterialName = trim((string)($row['material_name'] ?? ''));
                    if ($expectedItemName !== null) {
                        if (strcasecmp($rowMaterialName, $expectedItemName) !== 0) continue;
                    } elseif (in_array($rowMaterialName, $definition['item_names'], true)) {
                        // Known ID-bound material names must not be reassigned under another item ID.
                        continue;
                    }
                }
                $row['service_id'] = $serviceId;
                $row['service_name'] = $definition['name'];
                $selectionPolicy = printflow_production_assignment_selection_registry()[$serviceId][(int)($row['item_id'] ?? 0)] ?? null;
                $row['selection_group'] = is_array($selectionPolicy) ? ($selectionPolicy['group'] ?? null) : null;
                $row['selection_role'] = is_array($selectionPolicy) ? (string)($selectionPolicy['role'] ?? '') : '';
                $row['group_required'] = is_array($selectionPolicy) && !empty($selectionPolicy['group_required']);
                $row['stored_rule_type'] = strtoupper(trim((string)($row['rule_type'] ?? '')));
                if (is_array($selectionPolicy) && in_array($selectionPolicy['role'] ?? '', ['optional', 'multi_optional'], true)) {
                    $row['rule_type'] = 'OPTIONAL';
                }
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
function printflow_service_material_rule_display_row_is_editable(string $serviceType, int $itemId, array $activeServices, ?string $itemName = null, ?int $ruleServiceId = null): bool
{
    foreach (printflow_service_material_rule_display_registry() as $serviceId => $definition) {
        if ($ruleServiceId !== null && $ruleServiceId > 0) {
            if ((int)$ruleServiceId !== (int)$serviceId) continue;
        } elseif ($definition['service_types'] === [] || !in_array($serviceType, $definition['service_types'], true)) continue;
        if (isset($definition['item_names'])) {
            $expectedName = $definition['item_names'][$itemId] ?? null;
            if ($expectedName !== null) {
                if ($itemName === null || strcasecmp(trim($itemName), $expectedName) !== 0) continue;
            } elseif ($itemName !== null && in_array($itemName, $definition['item_names'], true)) {
                continue;
            }
        }
        if (isset($definition['material_names'])) {
            $allowedName = false;
            foreach ($definition['material_names'] as $materialName) {
                if ($itemName !== null && strcasecmp(trim($itemName), $materialName) === 0) {
                    $allowedName = true;
                    break;
                }
            }
            if (!$allowedName) continue;
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
