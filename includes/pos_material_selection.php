<?php
declare(strict_types=1);

require_once __DIR__ . '/pos_service_stock_helpers.php';

/**
 * Explicit POS alternative/optional material policy. Inventory IDs are the
 * confirmed catalog identities; no service/material name inference is used.
 * Multipliers are Admin-approved and interpreted using each service's
 * explicit consumption basis.
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

/**
 * Production Assignment selection rules are explicit and keyed by service and
 * inventory IDs. They describe which saved alternatives are mutually
 * exclusive; they do not change the POS service-specification workflow.
 */
function printflow_production_assignment_selection_registry(): array
{
    $registry = printflow_pos_material_selection_registry();
    $registry[40] = [];
    foreach ([13, 14, 15, 16] as $itemId) {
        $registry[40][$itemId] = [
            'group' => 'tarpaulin_roll',
            'role' => 'alternative',
            'group_required' => true,
        ];
    }
    return $registry;
}

/** Exact active service names and verified service-material aliases only. */
function printflow_pos_material_selection_service_id_for_label(string $serviceLabel): int
{
    $label = strtolower(trim(preg_replace('/\\s+/', ' ', $serviceLabel) ?? $serviceLabel));
    $known = [
        36 => ['decals/stickers (print/cut)', 'decals / stickers (print/cut)', 'stickers/decals', 'stickers /decals', 'stickers', 'decal', 'decals'],
        39 => ['plates'],
        40 => ['tarpaulin', 'tarpaulin printing'],
        64 => ['poster printing'],
        67 => ['brochure'],
        71 => ['sintraboard standees'],
    ];
    foreach ($known as $serviceId => $aliases) {
        if (in_array($label, $aliases, true)) return (int)$serviceId;
    }
    return 0;
}

/**
 * Prevent incompatible alternatives from being assigned together in
 * Production Assignment. Empty groups are valid because mapped rows are
 * choices, not usage requirements, regardless of the stored rule_type.
 */
function printflow_production_assignment_selection_errors(int $serviceId, array $itemIds): array
{
    $policy = printflow_production_assignment_selection_registry()[$serviceId] ?? [];
    if ($policy === []) return [];

    $selected = [];
    foreach ($itemIds as $rawId) {
        if (!is_scalar($rawId) || filter_var($rawId, FILTER_VALIDATE_INT) === false) continue;
        $id = (int)$rawId;
        if ($id > 0) $selected[$id] = true;
    }

    $groups = [];
    foreach ($policy as $itemId => $entry) {
        if (($entry['role'] ?? '') !== 'alternative' || empty($entry['group'])) continue;
        $group = (string)$entry['group'];
        if (!isset($groups[$group])) {
            $groups[$group] = [
                'label' => printflow_pos_material_selection_group_label($serviceId, $group),
                'selected_ids' => [],
            ];
        }
        if (isset($selected[(int)$itemId])) $groups[$group]['selected_ids'][] = (int)$itemId;
    }

    $errors = [];
    foreach ($groups as $state) {
        $count = count($state['selected_ids']);
        if ($count > 1) {
            $errors['material_selection'] = 'Choose only one material from the ' . strtolower($state['label']) . ' options.';
            continue;
        }
    }
    return $errors;
}

/** True when a REQUIRED row is a staff choice, rather than an automatic assignment. */
function printflow_pos_material_selection_is_staff_choice(int $serviceId, int $itemId): bool
{
    $entry = printflow_production_assignment_selection_registry()[$serviceId][$itemId] ?? null;
    return is_array($entry) && in_array((string)($entry['role'] ?? ''), ['alternative', 'optional', 'multi_optional'], true);
}

function printflow_pos_material_selection_allowed_ids(int $serviceId): array
{
    return array_map('intval', array_keys(printflow_pos_material_selection_registry()[$serviceId] ?? []));
}

/**
 * Unselected optional Sintraboard layers do not participate in thickness
 * stock validation. This keeps stale/incomplete optional rule metadata from
 * blocking the selected board while leaving selected layers subject to the
 * normal strict rule, approval, and stock checks.
 */
function printflow_pos_material_selection_filter_unselected_optional_rules(int $serviceId, array $rows, array $selection): array
{
    if ($serviceId !== 71) return $rows;

    $selectedIds = [];
    foreach ($selection as $selected) {
        if (is_array($selected) && filter_var($selected['item_id'] ?? null, FILTER_VALIDATE_INT) !== false) {
            $selectedIds[(int)$selected['item_id']] = true;
        }
    }
    $policy = printflow_pos_material_selection_registry()[71] ?? [];
    return array_values(array_filter($rows, static function ($row) use ($selectedIds, $policy): bool {
        if (!is_array($row)) return true;
        $itemId = filter_var($row['item_id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId === false || !isset($policy[(int)$itemId])) return true;
        $definition = $policy[(int)$itemId];
        return ($definition['role'] ?? '') !== 'optional' || isset($selectedIds[(int)$itemId]);
    }));
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

/**
 * Pp Stkr Matte 98 is an optional Sintraboard layer. A staff-confirmed actual
 * quantity is already expressed in the inventory item's UOM, so it can be
 * stock-checked and later deducted directly without inventing an area rate.
 */
function printflow_pos_sintraboard_manual_actual_usage_is_confirmed(int $serviceId, int $itemId, string $selectionRole, array $assignment, float $quantity): bool
{
    // The explicit assignment policy is authoritative for this optional layer;
    // a legacy REQUIRED value in service_material_rules must not turn it into
    // a mandatory estimate or force approval when staff records actual usage.
    if ($serviceId !== 71 || $itemId !== 23 || strtolower(trim($selectionRole)) !== 'optional'
        || !is_finite($quantity) || $quantity <= 0) return false;

    $metadata = $assignment['metadata'] ?? [];
    if (is_string($metadata)) $metadata = json_decode($metadata, true);
    if (!is_array($metadata)) return false;
    $usage = $metadata['material_usage'] ?? null;
    if (!is_array($usage)
        || ($usage['manual_actual_usage_confirmed'] ?? false) !== true
        || ($usage['staff_confirmed'] ?? false) !== true
        || !is_numeric($usage['actual'] ?? null)
        || !is_finite((float)$usage['actual'])) return false;

    return abs((float)$usage['actual'] - $quantity) <= 0.000001;
}

/** Staff-facing selector labels; canonical inventory identity checks use item_names(). */
function printflow_pos_material_selection_display_name(int $serviceId, int $itemId, string $fallback): string
{
    if ($serviceId === 71) return [206 => 'Sintra 3mm', 207 => 'Sintra 5mm'][$itemId] ?? $fallback;
    return $fallback;
}

/**
 * Sintraboard's configured Board Thickness field is the single staff selector.
 * These explicit option-to-inventory identities keep that field connected to
 * the stable material IDs without guessing from dimensions or stock records.
 */
function printflow_pos_material_selection_field_map(int $serviceId): array
{
    if ($serviceId !== 71) return [];
    return [
        'field_key' => 'board_thickness',
        'options' => [
            'Sintra 3mm' => 206,
            'Sintra 5mm' => 207,
        ],
    ];
}

function printflow_pos_sintraboard_selected_item_id($rawValue): ?int
{
    if (!is_scalar($rawValue)) return null;
    $value = strtolower(trim((string)$rawValue));
    $value = preg_replace('/\\s+/', ' ', $value) ?? $value;
    foreach (printflow_pos_material_selection_field_map(71)['options'] as $label => $itemId) {
        $expected = strtolower(trim($label));
        if ($value === $expected) return (int)$itemId;
    }
    return null;
}

/** Resolve submitted selection IDs while enforcing the configured Sintra field. */
function printflow_pos_material_selection_resolve_customization(int $serviceId, array $customization): array
{
    $selectedIds = is_array($customization['pos_selected_material_ids'] ?? null)
        ? $customization['pos_selected_material_ids']
        : [];
    if ($serviceId === 71) {
        $fieldSelectedId = printflow_pos_sintraboard_selected_item_id($customization['board_thickness'] ?? null);
        $submittedThicknessIds = array_values(array_filter($selectedIds, static function ($id): bool {
            return is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT) !== false && in_array((int)$id, [206, 207], true);
        }));
        // Board Thickness is the only POS material identity. Optional print
        // layers are chosen later in Production Assignment, never in this form.
        if ($fieldSelectedId === null || count($selectedIds) !== 1 || count($submittedThicknessIds) !== 1 || (int)$submittedThicknessIds[0] !== $fieldSelectedId) {
            throw new InvalidArgumentException('Please select Sintra 3mm or Sintra 5mm.');
        }
    }
    return printflow_pos_material_selection_resolve($serviceId, $selectedIds);
}

/**
 * Stable service-type aliases used when loading authoritative Production
 * Assignment mappings from service_material_rules.
 */
function printflow_production_assignment_service_type_aliases(int $serviceId): array
{
    if ($serviceId === 40) return ['Tarpaulin Printing', 'Tarpaulin'];
    if ($serviceId === 63) return ['Mugs'];
    return printflow_pos_service_material_type_aliases($serviceId);
}

/**
 * Active inventory item IDs explicitly mapped to the service in
 * service_material_rules. Soft-fails closed to an empty list when the
 * database is unavailable so offline unit tests keep using the registry.
 */
function printflow_production_assignment_db_mapped_item_ids(int $serviceId): array
{
    if ($serviceId <= 0) return [];
    // Test-only override so offline unit tests can prove DB-mapped IDs pass
    // without requiring a live database connection.
    if (isset($GLOBALS['PRINTFLOW_TEST_PRODUCTION_ASSIGNMENT_DB_MAPPED_IDS'])
        && is_array($GLOBALS['PRINTFLOW_TEST_PRODUCTION_ASSIGNMENT_DB_MAPPED_IDS'])) {
        $override = $GLOBALS['PRINTFLOW_TEST_PRODUCTION_ASSIGNMENT_DB_MAPPED_IDS'][$serviceId] ?? [];
        return array_values(array_filter(array_map(
            static function ($value): int {
                $id = filter_var($value, FILTER_VALIDATE_INT);
                return ($id === false || (int)$id <= 0) ? 0 : (int)$id;
            },
            is_array($override) ? $override : []
        )));
    }
    if (!function_exists('db_query')) return [];
    try {
        $aliases = printflow_production_assignment_service_type_aliases($serviceId);
        $hasServiceIdColumn = function_exists('db_table_has_column')
            && db_table_has_column('service_material_rules', 'service_id', true);
        if ($aliases !== []) {
            require_once __DIR__ . '/service_material_rule_identity.php';
            $scope = printflow_service_material_rule_identity_scope(
                $serviceId,
                $aliases,
                'r',
                $hasServiceIdColumn
            );
            $rows = db_query(
                "SELECT DISTINCT r.item_id
                 FROM service_material_rules r
                 INNER JOIN inv_items i ON i.id = r.item_id
                 WHERE {$scope['sql']}
                   AND UPPER(TRIM(COALESCE(i.status, ''))) = 'ACTIVE'",
                $scope['types'],
                $scope['params']
            );
        } elseif ($hasServiceIdColumn) {
            $rows = db_query(
                "SELECT DISTINCT r.item_id
                 FROM service_material_rules r
                 INNER JOIN inv_items i ON i.id = r.item_id
                 WHERE r.service_id = ?
                   AND UPPER(TRIM(COALESCE(i.status, ''))) = 'ACTIVE'",
                'i',
                [$serviceId]
            );
        } else {
            return [];
        }
    } catch (Throwable $error) {
        return [];
    }
    if (!is_array($rows)) return [];
    $ids = [];
    foreach ($rows as $row) {
        $itemId = filter_var($row['item_id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId !== false && (int)$itemId > 0) $ids[(int)$itemId] = true;
    }
    return array_map('intval', array_keys($ids));
}

/**
 * Eligible Production Assignment item IDs for a service: the explicit selection
 * policy plus every active authoritative service_material_rules mapping.
 */
function printflow_production_assignment_allowed_item_ids(int $serviceId): array
{
    $ids = [];
    foreach (array_keys(printflow_production_assignment_selection_registry()[$serviceId] ?? []) as $itemId) {
        $normalized = filter_var($itemId, FILTER_VALIDATE_INT);
        if ($normalized !== false && (int)$normalized > 0) $ids[(int)$normalized] = true;
    }
    foreach (printflow_production_assignment_db_mapped_item_ids($serviceId) as $itemId) {
        $ids[(int)$itemId] = true;
    }
    if ($serviceId === 63) $ids[37] = true;
    return array_map('intval', array_keys($ids));
}

/** Resolve materials explicitly chosen by staff during Production Assignment. */
function printflow_pos_material_selection_resolve_assignments(int $serviceId, array $assignments, array $customization = []): array
{
    $ids = [];
    foreach ($assignments as $assignment) {
        if (!is_array($assignment)) throw new InvalidArgumentException('A production material assignment is invalid.');
        $rawId = $assignment['item_id'] ?? $assignment['id'] ?? null;
        // Normalize string/int IDs the same way the Set Price payload does.
        if (!is_scalar($rawId) || filter_var($rawId, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('A production material identity is invalid.');
        }
        $id = (int)$rawId;
        if ($id <= 0) throw new InvalidArgumentException('A production material identity is invalid.');
        if (!in_array($id, $ids, true)) $ids[] = $id;
    }
    if ($serviceId === 71) {
        $fieldSelectedId = printflow_pos_sintraboard_selected_item_id($customization['board_thickness'] ?? null);
        $assignedBoards = array_values(array_filter($ids, static fn(int $id): bool => in_array($id, [206, 207], true)));
        if ($fieldSelectedId === null || count($assignedBoards) !== 1 || $assignedBoards[0] !== $fieldSelectedId) {
            throw new InvalidArgumentException('The production material must match the selected Sintra board thickness.');
        }
    }

    // Production Assignment must accept every active mapped material for this
    // service. The POS form allowlist remains separate and is not reused here
    // as a closed world, because Admin mappings can include additional items
    // (for example interchangeable sticker colors) that staff legitimately use.
    if (!isset(printflow_pos_material_selection_registry()[$serviceId])
        && !isset(printflow_production_assignment_selection_registry()[$serviceId])
        && $serviceId !== 63) {
        // Services without a selection policy still validate stock on the
        // assigned rows without a closed material-identity allowlist.
        return array_map(static fn(int $id): array => [
            'item_id' => $id,
            'group' => null,
            'role' => 'mapped',
            'group_required' => false,
        ], $ids);
    }

    $allowed = array_fill_keys(printflow_production_assignment_allowed_item_ids($serviceId), true);
    $policy = printflow_production_assignment_selection_registry()[$serviceId] ?? [];
    $selected = [];
    foreach ($ids as $id) {
        if (!isset($allowed[$id])) {
            throw new InvalidArgumentException(
                'Material #' . $id . ' is not mapped to this service. Refresh Production Assignment and choose a currently mapped material.'
            );
        }
        $definition = $policy[$id] ?? ['group' => null, 'role' => 'mapped', 'group_required' => false];
        $selected[$id] = $definition + ['item_id' => $id];
    }

    $groupErrors = printflow_production_assignment_selection_errors($serviceId, $ids);
    if ($groupErrors !== []) {
        throw new InvalidArgumentException((string)reset($groupErrors));
    }

    return array_values($selected);
}

function printflow_pos_material_selection_group_label(int $serviceId, string $group): string
{
    if ($serviceId === 71 && $group === 'sintra_thickness') return 'Board Thickness';
    return ucwords(str_replace('_', ' ', $group));
}

/** Plates use standard-count demand; the other selectable-material services use area demand. */
function printflow_pos_material_selection_consumption_basis(int $serviceId): ?string
{
    if (!isset(printflow_pos_material_selection_registry()[$serviceId])) return null;
    return $serviceId === 39 ? 'PER_ITEM' : 'AREA_SQFT';
}

function printflow_pos_material_selection_requires_dimensions(int $serviceId): bool
{
    return printflow_pos_material_selection_consumption_basis($serviceId) === 'AREA_SQFT';
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

/** Calculate Plate demand; multiplier means inventory UOM units consumed per plate item. */
function printflow_pos_material_demand_for_items($orderQuantity, $multiplier): array
{
    foreach ([$orderQuantity, $multiplier] as $value) {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) {
            throw new InvalidArgumentException('Enter a valid positive order quantity and an Admin-approved inventory-units-per-item multiplier.');
        }
    }
    $raw = (float)$orderQuantity * (float)$multiplier;
    if (!is_finite($raw) || $raw <= 0 || $raw > 100000000) {
        throw new InvalidArgumentException('Calculated Plate material consumption is outside the supported range.');
    }
    return ['raw_demand' => $raw, 'rounded_demand' => (int)ceil($raw - 1e-10), 'multiplier' => (float)$multiplier];
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
        $expectedBasis = printflow_pos_material_selection_consumption_basis($serviceId);
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
            || strtoupper(trim((string)($row['multiplier_approved_basis'] ?? ''))) !== $expectedBasis) {
            throw new InvalidArgumentException('Saved POS material selection is missing its validated identity or Admin approval snapshot.');
        }
        if ($expectedBasis === 'PER_ITEM') {
            if (strtoupper(trim((string)($row['multiplier_basis'] ?? ''))) !== 'INVENTORY_UNITS_PER_ITEM'
                || $dimensions !== [] || ($row['area_sqft'] ?? null) !== null) {
                throw new InvalidArgumentException('Saved Plate material consumption must use per-item demand without dimensions.');
            }
            $calculation = printflow_pos_material_demand_for_items($orderQty, $multiplier);
        } else {
            if (strtoupper(trim((string)($row['multiplier_basis'] ?? ''))) !== 'INVENTORY_UNITS_PER_SQFT') {
                throw new InvalidArgumentException('Saved POS material selection is missing its validated consumption basis.');
            }
            $calculation = printflow_pos_material_demand_for_area($width, $height, $dimensionUnit, $orderQty, $multiplier);
            if (!is_numeric($row['area_sqft'] ?? null) || abs((float)$row['area_sqft'] - $calculation['area_sqft']) > 0.000001) {
                throw new InvalidArgumentException('Saved POS material consumption does not match the selected dimensions and approved rate.');
            }
        }
        if (!is_numeric($row['calculated_demand'] ?? null) || abs((float)$row['calculated_demand'] - $calculation['raw_demand']) > 0.000001
            || !is_numeric($row['rounded_demand'] ?? null) || (int)$row['rounded_demand'] !== $calculation['rounded_demand']) {
            throw new InvalidArgumentException('Saved POS material consumption does not match the selected dimensions and approved rate.');
        }
        if (isset($byId[$itemId])) throw new InvalidArgumentException('A selected POS material was saved more than once.');
        $byId[$itemId] = $row;
    }
    return array_values($byId);
}

/**
 * Return only an existing, server-authored POS material snapshot. POS drafts
 * may intentionally defer production-material choices to Production
 * Assignment; an absent or empty snapshot therefore means no auto-assignment,
 * not a failed required-alternative selection. Non-empty snapshots remain
 * strictly validated before they can become job material assignments.
 */
function printflow_pos_material_selection_saved_consumption_for_customization(int $serviceId, array $customization): array
{
    if (!array_key_exists('pos_material_consumption', $customization)
        || $customization['pos_material_consumption'] === null
        || $customization['pos_material_consumption'] === []) {
        return [];
    }
    if (!is_array($customization['pos_material_consumption'])) {
        throw new InvalidArgumentException('Saved POS selected-material consumption is malformed.');
    }

    return printflow_pos_material_selection_validate_saved_consumption(
        $serviceId,
        $customization['pos_material_consumption']
    );
}
