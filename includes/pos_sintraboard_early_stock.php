<?php
/** Read-only early capacity check for the selected Sintraboard thickness. */

require_once __DIR__ . '/pos_material_selection.php';
require_once __DIR__ . '/pos_service_stock_helpers.php';
require_once __DIR__ . '/pos_service_dimensions.php';
require_once __DIR__ . '/service_material_rule_identity.php';

/** Fail closed unless the selected stable material still matches inventory. */
function printflow_pos_sintraboard_assert_early_item(int $itemId, ?array $item): array
{
    $expectedName = printflow_pos_material_selection_item_names(71)[$itemId] ?? '';
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? '')));
    $pieceUoms = ['pc', 'pcs', 'piece', 'pieces'];
    if ($expectedName === '' || !$item || (int)($item['id'] ?? 0) !== $itemId
        || strcasecmp(trim((string)($item['name'] ?? '')), $expectedName) !== 0
        || strtoupper(trim((string)($item['status'] ?? ''))) !== 'ACTIVE'
        || !in_array($uom, $pieceUoms, true) || !empty($item['track_by_roll'])) {
        throw new RuntimeException('The selected Sintraboard material is inactive, unavailable, or no longer matches its configured inventory item. Ask an Admin to verify the material mapping.');
    }
    return $item;
}

/**
 * Load and strictly resolve one selected board rule, ignoring unrelated
 * optional materials (for example, unselected Pp Stkr Matte 98).
 *
 * @param array<int,array<string,mixed>>|null $rowsOverride test-only rows
 * @return array<string,mixed>
 */
function printflow_pos_sintraboard_early_rule(int $itemId, ?array $rowsOverride = null): array
{
    if (!in_array($itemId, [206, 207], true)) {
        throw new RuntimeException('The selected Sintraboard thickness has no verified inventory mapping.');
    }
    if ($rowsOverride !== null) {
        $rows = array_values(array_filter($rowsOverride, static fn($row): bool => is_array($row) && (int)($row['item_id'] ?? 0) === $itemId));
    } else {
        if (!function_exists('db_table_has_column') || !function_exists('db_query')) {
            throw new RuntimeException('Sintraboard material rules could not be verified.');
        }
        foreach (['id', 'item_id', 'rule_type', 'qty_multiplier', 'qty_multiplier_approved',
            'qty_multiplier_approved_by', 'qty_multiplier_approved_at', 'qty_multiplier_basis',
            'qty_multiplier_approved_basis'] as $column) {
            if (!db_table_has_column('service_material_rules', $column, true)) {
                throw new RuntimeException('The Sintraboard approved consumption-rule schema is incomplete. Ask an Admin to review it.');
            }
        }
        $hasServiceId = db_table_has_column('service_material_rules', 'service_id', true);
        $scope = printflow_service_material_rule_identity_scope(
            71,
            printflow_pos_service_material_type_aliases(71),
            'r',
            $hasServiceId
        );
        $serviceIdSelect = $hasServiceId ? 'r.service_id' : 'NULL AS service_id';
        $rows = db_query(
            "SELECT r.id, {$serviceIdSelect}, r.service_type, r.item_id, r.rule_type,
                    r.qty_multiplier, r.qty_multiplier_approved, r.qty_multiplier_approved_by,
                    r.qty_multiplier_approved_at, r.qty_multiplier_basis, r.qty_multiplier_approved_basis
             FROM service_material_rules r
             WHERE {$scope['sql']} AND r.item_id = ?
             ORDER BY r.id DESC LIMIT 16",
            $scope['types'] . 'i',
            array_merge($scope['params'], [$itemId])
        );
        if ($rows === false) throw new RuntimeException('The selected Sintraboard consumption rule could not be read.');
    }

    if ($rows === []) throw new RuntimeException('The selected Sintraboard thickness has no configured material rule. Ask an Admin to configure and approve its usage rate.');
    try {
        $resolved = printflow_pos_select_service_material_rule_set(71, $rows);
    } catch (Throwable $error) {
        throw new RuntimeException('The selected Sintraboard material rule is ambiguous or invalid. Ask an Admin to review that rule.');
    }
    $matches = array_values(array_filter($resolved, static fn($row): bool => is_array($row) && (int)($row['item_id'] ?? 0) === $itemId));
    if (count($matches) !== 1) throw new RuntimeException('The selected Sintraboard material rule is missing or ambiguous. Ask an Admin to review that rule.');
    $rule = $matches[0];
    if (strtoupper(trim((string)($rule['rule_type'] ?? ''))) !== 'REQUIRED') {
        throw new RuntimeException('The selected Sintraboard board thickness does not have a REQUIRED approved usage rule. Ask an Admin to review the rule type.');
    }
    $multiplier = printflow_pos_approved_service_multiplier(
        $rule['qty_multiplier'] ?? null,
        $rule['qty_multiplier_approved'] ?? null,
        $rule['qty_multiplier_approved_by'] ?? null,
        $rule['qty_multiplier_approved_at'] ?? null
    );
    $basis = strtoupper(trim((string)($rule['qty_multiplier_basis'] ?? '')));
    $approvedBasis = strtoupper(trim((string)($rule['qty_multiplier_approved_basis'] ?? '')));
    if (!in_array($basis, ['AREA_SQFT', 'INVENTORY_UNITS_PER_SQFT'], true) || $approvedBasis !== $basis) {
        throw new RuntimeException('The selected Sintraboard material requires a matching Admin-approved area basis before stock can be checked.');
    }
    return ['rule_id' => (int)$rule['id'], 'multiplier' => $multiplier, 'basis' => $basis];
}

/**
 * Resolve board thickness to its configured inventory demand. If dimensions
 * are not entered yet, return an availability-only probe (required=0); the
 * full approved area calculation runs as soon as valid dimensions are present.
 *
 * @param array<string,mixed> $line
 * @param array<string,mixed>|null $itemOverride test-only inventory row
 * @param array<int,array<string,mixed>>|null $ruleRowsOverride test-only rows
 * @param array<int,array<string,mixed>>|null $dimensionRowsOverride test-only field config
 * @return array<string,mixed>|null
 */
function printflow_pos_sintraboard_early_demand(
    array $line,
    int $branchId,
    ?array $itemOverride = null,
    ?array $ruleRowsOverride = null,
    ?array $dimensionRowsOverride = null
): ?array {
    if ($branchId <= 0) throw new RuntimeException('The POS branch could not be identified for Sintraboard stock validation.');
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    $serviceId = (int)($customization['service_id'] ?? $line['product_id'] ?? $line['id'] ?? 0);
    if ($serviceId !== 71) return null;
    $selectedId = printflow_pos_sintraboard_selected_item_id($customization['board_thickness'] ?? null);
    if ($selectedId === null) throw new RuntimeException('Please select Sintra 3mm or Sintra 5mm.');

    $item = $itemOverride ?? InventoryManager::getItem($selectedId);
    $item = printflow_pos_sintraboard_assert_early_item($selectedId, $item);
    $rule = printflow_pos_sintraboard_early_rule($selectedId, $ruleRowsOverride);
    $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? $customization['quantity'] ?? null);

    $dimensionRows = $dimensionRowsOverride ?? printflow_pos_service_dimension_configs(71);
    if (!is_array($dimensionRows)) throw new RuntimeException('Sintraboard dimensions could not be verified from the active service configuration.');
    $descriptor = printflow_pos_service_dimension_descriptor($dimensionRows);
    if ($descriptor === null) throw new RuntimeException('Configure one visible Sintraboard dimension field with physical options and a valid unit before checking material demand.');
    $fieldKey = (string)$descriptor['field_key'];
    $selectedDimension = trim((string)($customization[$fieldKey] ?? ''));
    $isCustomDimension = in_array(strtolower($selectedDimension), ['others', 'custom size'], true);
    $width = $customization['width'] ?? $customization[$fieldKey . '_width'] ?? null;
    $height = $customization['height'] ?? $customization[$fieldKey . '_height'] ?? null;
    if (($selectedDimension === '' || $isCustomDimension)
        && (!is_numeric($width) || !is_numeric($height) || (float)$width <= 0 || (float)$height <= 0)) {
        return [
            'item_id' => $selectedId,
            'name' => (string)$item['name'],
            'uom' => (string)$item['unit_of_measure'],
            'required' => 0.0,
            'available_only' => true,
            'rule_id' => $rule['rule_id'],
            'branch_id' => $branchId,
        ];
    }

    $dimensions = printflow_pos_resolve_service_dimension_selection($dimensionRows, $customization);
    $calculation = printflow_pos_material_demand_for_area(
        $dimensions['width'],
        $dimensions['height'],
        (string)$dimensions['unit'],
        $quantity,
        $rule['multiplier']
    );
    return [
        'item_id' => $selectedId,
        'name' => (string)$item['name'],
        'uom' => (string)$item['unit_of_measure'],
        'required' => (float)$calculation['rounded_demand'],
        'available_only' => false,
        'multiplier' => (float)$rule['multiplier'],
        'basis' => $rule['basis'],
        'rule_id' => $rule['rule_id'],
        'area_sqft' => (float)$calculation['area_sqft'],
        'raw_demand' => (float)$calculation['raw_demand'],
        'branch_id' => $branchId,
    ];
}
