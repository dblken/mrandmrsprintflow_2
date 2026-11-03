<?php
declare(strict_types=1);

/**
 * Explicit POS alternative/optional material policy. Inventory IDs are the
 * confirmed catalog identities; no service/material name inference is used.
 * Multipliers are inventory UOM units per square foot and must be Admin
 * approved before a selected material can be validated or deducted.
 */
function printflow_pos_material_selection_registry(): array
{
    $alternatives = static function (string $group, array $itemIds, bool $required = true): array {
        $rows = [];
        foreach ($itemIds as $itemId) $rows[(int)$itemId] = ['group' => $group, 'role' => 'alternative', 'group_required' => $required];
        return $rows;
    };
    return [
        39 => $alternatives('plate_media', [1,2,3,4,5,6,7,8,9,10,11]),
        67 => $alternatives('brochure_paper', [212,211]),
        64 => $alternatives('poster_paper', [212,211]),
        71 => $alternatives('sintra_thickness', [207,206]) + [23 => ['group' => null, 'role' => 'optional', 'group_required' => false]],
        // Nextjet remains OPTIONAL per the confirmed database correction.
        // At most one selected base may be consumed; no base is forced here.
        36 => [
            18 => ['group' => 'decal_base', 'role' => 'alternative', 'group_required' => false],
            22 => ['group' => 'decal_base', 'role' => 'alternative', 'group_required' => false],
            20 => ['group' => 'decal_finishes', 'role' => 'multi_optional', 'group_required' => false],
            21 => ['group' => 'decal_finishes', 'role' => 'multi_optional', 'group_required' => false],
        ],
    ];
}

function printflow_pos_material_selection_allowed_ids(int $serviceId): array
{
    return array_map('intval', array_keys(printflow_pos_material_selection_registry()[$serviceId] ?? []));
}

function printflow_pos_material_selection_service_type(int $serviceId): ?string
{
    return [39 => 'Plates', 67 => 'Brochure', 64 => 'Poster Printing', 71 => 'Sintraboard Standees', 36 => 'Decals/Stickers (Print/Cut)'][$serviceId] ?? null;
}

/** Exact item identities protect against inventory ID reuse or broad name matching. */
function printflow_pos_material_selection_item_names(int $serviceId): array
{
    return [
        39 => [1 => 'AC MC', 2 => 'AC PH', 3 => 'AC EURO', 4 => 'AC THAI', 5 => 'AC NMC', 6 => 'AC HOME', 7 => 'SP MC', 8 => 'SP PH', 9 => 'SP EURO', 10 => 'SP THAI', 11 => 'SP NMC'],
        67 => [212 => 'C2s Board', 211 => 'C2S Special Paper'],
        64 => [212 => 'C2s Board', 211 => 'C2S Special Paper'],
        71 => [207 => 'Sintra 5mm', 206 => 'Sintra 3mm 32', 23 => 'Pp Stkr Matte 98'],
        36 => [18 => 'Nextjet', 20 => 'Gloss Laminate', 21 => 'Matte Laminate', 22 => 'Hologram'],
    ][$serviceId] ?? [];
}

/** Validate selected IDs and the exact-one rule for required alternative groups. */
function printflow_pos_material_selection_resolve(int $serviceId, array $selectedIds): array
{
    $policy = printflow_pos_material_selection_registry()[$serviceId] ?? null;
    if ($policy === null) throw new InvalidArgumentException('This service does not use selectable POS material rules.');
    if (count($selectedIds) > 32) throw new InvalidArgumentException('Too many POS materials were selected.');
    $selected = [];
    foreach ($selectedIds as $rawId) {
        if (!is_scalar($rawId) || filter_var($rawId, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('A selected material ID is invalid.');
        }
        $id = (int)$rawId;
        if (!isset($policy[$id])) throw new InvalidArgumentException('A material not available for this service was selected. Refresh the service form.');
        if (isset($selected[$id])) throw new InvalidArgumentException('A material was selected more than once.');
        $selected[$id] = $policy[$id] + ['item_id' => $id];
    }
    $groups = [];
    foreach ($policy as $id => $definition) {
        if (($definition['role'] ?? '') !== 'alternative' || empty($definition['group'])) continue;
        $groups[$definition['group']]['required'] = !empty($definition['group_required']);
        $groups[$definition['group']]['selected'] = ($groups[$definition['group']]['selected'] ?? 0) + (isset($selected[$id]) ? 1 : 0);
    }
    foreach ($groups as $group => $state) {
        if (($state['selected'] ?? 0) > 1 || (!empty($state['required']) && ($state['selected'] ?? 0) !== 1)) {
            throw new InvalidArgumentException('Select exactly one material from the ' . str_replace('_', ' ', $group) . ' choices.');
        }
    }
    return array_values($selected);
}

/**
 * Calculate area-based demand; multiplier means inventory UOM units per ft².
 * Every fractional inventory unit is rounded upward as required.
 */
function printflow_pos_material_demand_for_area($width, $height, string $dimensionUnit, $orderQuantity, $multiplier): array
{
    foreach ([$width, $height, $orderQuantity, $multiplier] as $value) {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) {
            throw new InvalidArgumentException('Enter valid positive dimensions, quantity, and an Admin-approved material multiplier.');
        }
    }
    $unit = strtolower(trim($dimensionUnit));
    $toFeet = ['ft' => 1.0, 'feet' => 1.0, 'foot' => 1.0, 'in' => 1 / 12, 'inch' => 1 / 12, 'inches' => 1 / 12, 'cm' => 1 / 30.48, 'mm' => 1 / 304.8][$unit] ?? null;
    if ($toFeet === null) throw new InvalidArgumentException('The selected service dimensions do not have a supported unit.');
    $areaSqFt = (float)$width * $toFeet * (float)$height * $toFeet;
    $raw = $areaSqFt * (float)$multiplier * (float)$orderQuantity;
    if (!is_finite($raw) || $raw <= 0 || $raw > 100000000) throw new InvalidArgumentException('Calculated material consumption is outside the supported range.');
    return ['area_sqft' => $areaSqFt, 'raw_demand' => $raw, 'rounded_demand' => (int)ceil($raw - 1e-10), 'multiplier' => (float)$multiplier];
}

/** Validate the server-authored consumption snapshot before creating job assignments. */
function printflow_pos_material_selection_validate_saved_consumption(int $serviceId, array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['item_id'])) {
            throw new InvalidArgumentException('Saved POS selected-material consumption is malformed.');
        }
        $ids[] = $row['item_id'];
    }
    $selected = printflow_pos_material_selection_resolve($serviceId, $ids);
    if (count($selected) !== count($rows)) {
        throw new InvalidArgumentException('Saved POS selected-material consumption contains duplicate entries.');
    }
    $expectedNames = printflow_pos_material_selection_item_names($serviceId);
    $byId = [];
    foreach ($rows as $row) {
        $itemId = (int)$row['item_id'];
        $policy = null;
        foreach ($selected as $entry) if ((int)$entry['item_id'] === $itemId) { $policy = $entry; break; }
        $dimensions = is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
        $uom = trim((string)($row['uom'] ?? ''));
        $width = $dimensions['width'] ?? null;
        $height = $dimensions['height'] ?? null;
        $dimensionUnit = (string)($dimensions['unit'] ?? '');
        $orderQty = $row['order_quantity'] ?? null;
        $multiplier = $row['multiplier'] ?? null;
        if ($policy === null
            || strcasecmp(trim((string)($row['material_name'] ?? '')), (string)($expectedNames[$itemId] ?? '')) !== 0
            || (string)($row['selection_role'] ?? '') !== (string)$policy['role']
            || ($row['selection_group'] ?? null) !== ($policy['group'] ?? null)
            || $uom === ''
            || (int)($row['rule_id'] ?? 0) <= 0
            || (int)($row['multiplier_approved_by'] ?? 0) <= 0
            || trim((string)($row['multiplier_approved_at'] ?? '')) === ''
            || strtoupper(trim((string)($row['multiplier_basis'] ?? ''))) !== 'INVENTORY_UNITS_PER_SQFT'
            || strtoupper(trim((string)($row['multiplier_approved_basis'] ?? ''))) !== 'AREA_SQFT') {
            throw new InvalidArgumentException('Saved POS material selection is missing its validated identity or Admin approval snapshot.');
        }
        $calculation = printflow_pos_material_demand_for_area($width, $height, $dimensionUnit, $orderQty, $multiplier);
        if (!is_numeric($row['area_sqft'] ?? null) || abs((float)$row['area_sqft'] - $calculation['area_sqft']) > 0.000001
            || !is_numeric($row['calculated_demand'] ?? null) || abs((float)$row['calculated_demand'] - $calculation['raw_demand']) > 0.000001
            || !is_numeric($row['rounded_demand'] ?? null) || (int)$row['rounded_demand'] !== $calculation['rounded_demand']) {
            throw new InvalidArgumentException('Saved POS material consumption does not match the selected dimensions and approved rate.');
        }
        if (isset($byId[$itemId])) throw new InvalidArgumentException('A selected POS material was saved more than once.');
        $byId[$itemId] = $row;
    }
    return array_values($byId);
}
