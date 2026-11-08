<?php
/**
 * Early POS capacity checks for Brochure (67) and Poster Printing (64).
 *
 * Preferred mapped substrates (by inventory item ID):
 * - Brochure → C2S Special Paper #211 (pcs)
 * - Poster Printing → C2s Board #212 (sheet)
 *
 * Demand uses the Admin-approved service_material_rules multiplier for that
 * mapped item. Discrete sheet/pcs papers consume inventory units per ordered
 * piece (PER_ITEM / inventory-units-per-item, default 1.0), matching the
 * Poster primary usage ceiling. AREA_SQFT rules still apply when that is the
 * approved basis. This path never writes inventory transactions.
 */

require_once __DIR__ . '/pos_material_selection.php';
require_once __DIR__ . '/pos_service_stock_helpers.php';
require_once __DIR__ . '/service_material_rule_identity.php';

/** Owner-confirmed preferred paper item for early POS capacity checks. */
function printflow_pos_brochure_poster_preferred_item_id(int $serviceId): int
{
    return match ($serviceId) {
        67 => 211, // Brochure → C2S Special Paper
        64 => 212, // Poster Printing → C2s Board
        default => 0,
    };
}

function printflow_pos_brochure_poster_is_early_stock_service(int $serviceId): bool
{
    return printflow_pos_brochure_poster_preferred_item_id($serviceId) > 0;
}

function printflow_pos_brochure_poster_expected_service_name(int $serviceId): string
{
    return match ($serviceId) {
        67 => 'Brochure',
        64 => 'Poster Printing',
        default => '',
    };
}

function printflow_pos_brochure_poster_uom_is_discrete(string $uom): bool
{
    return in_array(strtolower(trim($uom)), [
        'sheet', 'sheets', 'pc', 'pcs', 'piece', 'pieces',
    ], true);
}

/**
 * Fail closed unless the live inventory row matches the registry item ID and name.
 *
 * @param array<string,mixed>|null $item
 */
function printflow_pos_brochure_poster_assert_mapped_item(int $serviceId, ?array $item): array
{
    $itemId = printflow_pos_brochure_poster_preferred_item_id($serviceId);
    $expectedName = printflow_pos_material_selection_item_names($serviceId)[$itemId] ?? '';
    $serviceLabel = printflow_pos_brochure_poster_expected_service_name($serviceId);
    if ($itemId <= 0 || $expectedName === '' || $serviceLabel === '') {
        throw new RuntimeException('This service does not use Brochure/Poster early material stock validation.');
    }
    $policy = printflow_pos_material_selection_registry()[$serviceId][$itemId] ?? null;
    if (!is_array($policy) || ($policy['group'] ?? '') === '' || ($policy['role'] ?? '') !== 'alternative') {
        throw new RuntimeException(
            'The preferred ' . $serviceLabel . ' paper mapping is missing from the active service-to-material registry. Ask an Admin to restore inventory item #' . $itemId . '.'
        );
    }
    if (!$item
        || (int)($item['id'] ?? 0) !== $itemId
        || strcasecmp(trim((string)($item['name'] ?? '')), $expectedName) !== 0
        || strtoupper(trim((string)($item['status'] ?? ''))) !== 'ACTIVE'
        || !empty($item['track_by_roll'])) {
        throw new RuntimeException(
            'The preferred ' . $serviceLabel . ' material (' . $expectedName . ', inventory item #' . $itemId . ') is inactive, renamed, or unavailable. Ask an Admin to restore the mapping before adding this service.'
        );
    }
    return $item;
}

/**
 * Resolve customer width/height/unit from the POS customization payload.
 *
 * @param array<string,mixed> $customization
 * @return array{width:float,height:float,unit:string}|null
 */
function printflow_pos_brochure_poster_dimensions_or_null(array $customization): ?array
{
    $width = $customization['width'] ?? null;
    $height = $customization['height'] ?? null;
    $unit = strtolower(trim((string)($customization['pos_material_dimension_unit']
        ?? $customization['dimensions_unit']
        ?? $customization['unit']
        ?? '')));
    if (!is_numeric($width) || !is_numeric($height)
        || !is_finite((float)$width) || !is_finite((float)$height)
        || (float)$width <= 0 || (float)$height <= 0) {
        return null;
    }
    if (!in_array($unit, ['ft', 'in', 'cm', 'mm'], true)) {
        return null;
    }
    return [
        'width' => (float)$width,
        'height' => (float)$height,
        'unit' => $unit,
    ];
}

/**
 * @param array<string,mixed> $customization
 * @return array{width:float,height:float,unit:string}
 */
function printflow_pos_brochure_poster_dimensions(array $customization): array
{
    $dimensions = printflow_pos_brochure_poster_dimensions_or_null($customization);
    if ($dimensions === null) {
        throw new InvalidArgumentException('Select valid Dimensions before checking Brochure/Poster material stock.');
    }
    return $dimensions;
}

/**
 * Load the Admin-approved multiplier row for the preferred mapped item.
 * Test override: $GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_RULES'][$serviceId][$itemId]
 *   = ['multiplier' => float, 'basis' => 'PER_ITEM'|'AREA_SQFT']
 *
 * @return array{multiplier:float,basis:string}
 */
function printflow_pos_brochure_poster_approved_rule(int $serviceId, int $itemId): array
{
    if (isset($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_RULES'])
        && is_array($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_RULES'])) {
        $override = $GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_RULES'][$serviceId][$itemId] ?? null;
        if (is_array($override)
            && is_numeric($override['multiplier'] ?? null)
            && is_finite((float)$override['multiplier'])
            && (float)$override['multiplier'] > 0) {
            $basis = strtoupper(trim((string)($override['basis'] ?? 'PER_ITEM')));
            if (!in_array($basis, ['PER_ITEM', 'AREA_SQFT', 'INVENTORY_UNITS_PER_ITEM', 'INVENTORY_UNITS_PER_SQFT'], true)) {
                $basis = 'PER_ITEM';
            }
            return ['multiplier' => (float)$override['multiplier'], 'basis' => $basis];
        }
    }

    // Legacy test override that only supplied a positive multiplier.
    if (isset($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'])
        && is_array($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'])) {
        $legacy = $GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'][$serviceId][$itemId] ?? null;
        if (is_numeric($legacy) && is_finite((float)$legacy) && (float)$legacy > 0) {
            return ['multiplier' => (float)$legacy, 'basis' => 'PER_ITEM'];
        }
    }

    $serviceLabel = printflow_pos_brochure_poster_expected_service_name($serviceId);
    $aliases = printflow_pos_service_material_type_aliases($serviceId);
    if ($aliases === []) {
        $aliases = [$serviceLabel];
    }
    $hasServiceIdColumn = function_exists('db_table_has_column')
        && db_table_has_column('service_material_rules', 'service_id', true);
    $scope = printflow_service_material_rule_identity_scope($serviceId, $aliases, 'r', $hasServiceIdColumn);
    $rows = db_query(
        "SELECT r.item_id, r.qty_multiplier, r.qty_multiplier_approved, r.qty_multiplier_approved_by,
                r.qty_multiplier_approved_at, r.qty_multiplier_approved_basis, r.qty_multiplier_basis,
                r.rule_type, r.service_id, r.service_type
         FROM service_material_rules r
         WHERE {$scope['sql']}
           AND r.item_id = ?
         ORDER BY r.id DESC
         LIMIT 8",
        $scope['types'] . 'i',
        array_merge($scope['params'], [$itemId])
    );
    if ($rows === false) {
        throw new RuntimeException(
            'The ' . $serviceLabel . ' material consumption rule could not be loaded. Try again or ask an Admin to review the service-material mapping.'
        );
    }

    foreach ($rows as $candidate) {
        if (!is_array($candidate)) continue;
        try {
            $multiplier = printflow_pos_approved_service_multiplier(
                $candidate['qty_multiplier'] ?? null,
                $candidate['qty_multiplier_approved'] ?? null,
                $candidate['qty_multiplier_approved_by'] ?? null,
                $candidate['qty_multiplier_approved_at'] ?? null
            );
        } catch (RuntimeException $error) {
            continue;
        }
        $approvedBasis = strtoupper(trim((string)($candidate['qty_multiplier_approved_basis'] ?? '')));
        $storedBasis = strtoupper(trim((string)($candidate['qty_multiplier_basis'] ?? '')));
        $basis = $approvedBasis !== '' ? $approvedBasis : $storedBasis;
        if ($basis === '') {
            $basis = 'PER_ITEM';
        }
        return ['multiplier' => $multiplier, 'basis' => $basis];
    }

    // Dedicated early capacity for mapped discrete print papers: 1 inventory
    // unit per ordered piece when Admin has not yet approved a rate row.
    return ['multiplier' => 1.0, 'basis' => 'PER_ITEM'];
}

/**
 * Build early demand for Brochure/Poster before Add to Order.
 *
 * @param array<string,mixed> $line
 * @param array<string,mixed>|null $itemOverride test-only inventory row
 * @return array{item_id:int,name:string,uom:string,required:float,multiplier:float,basis:string,area_sqft:?float,raw_demand:float}
 */
function printflow_pos_brochure_poster_early_demand(array $line, int $branchId, ?array $itemOverride = null): array
{
    if ($branchId <= 0) {
        throw new RuntimeException('The POS branch could not be identified for Brochure/Poster stock validation.');
    }
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    $serviceId = (int)($customization['service_id'] ?? $line['product_id'] ?? $line['id'] ?? 0);
    $itemId = printflow_pos_brochure_poster_preferred_item_id($serviceId);
    if ($itemId <= 0) {
        throw new RuntimeException('This service does not use Brochure/Poster early material stock validation.');
    }

    $item = $itemOverride;
    if ($item === null) {
        if (!class_exists('InventoryManager')) {
            throw new RuntimeException('Inventory lookup is unavailable for Brochure/Poster stock validation.');
        }
        $item = InventoryManager::getItem($itemId);
    }
    $item = printflow_pos_brochure_poster_assert_mapped_item($serviceId, $item);
    $uom = trim((string)($item['unit_of_measure'] ?? ''));
    if ($uom === '') {
        $uom = $serviceId === 67 ? 'pcs' : 'sheet';
    }

    $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
    $rule = printflow_pos_brochure_poster_approved_rule($serviceId, $itemId);
    $multiplier = (float)$rule['multiplier'];
    $basis = strtoupper(trim((string)$rule['basis']));
    $areaSqFt = null;
    $rawDemand = null;
    $required = null;

    $useArea = in_array($basis, ['AREA_SQFT', 'INVENTORY_UNITS_PER_SQFT'], true);
    // Discrete sheet/pcs papers use per-piece demand even when a legacy
    // AREA_SQFT label remains on the rule row — area×rate under-counts A4
    // print media relative to one inventory unit per ordered piece.
    if ($useArea && !printflow_pos_brochure_poster_uom_is_discrete($uom)) {
        $dimensions = printflow_pos_brochure_poster_dimensions($customization);
        $calculation = printflow_pos_material_demand_for_area(
            $dimensions['width'],
            $dimensions['height'],
            $dimensions['unit'],
            $quantity,
            $multiplier
        );
        $areaSqFt = (float)$calculation['area_sqft'];
        $rawDemand = (float)$calculation['raw_demand'];
        $required = (float)$calculation['rounded_demand'];
        $basis = 'AREA_SQFT';
    } else {
        $calculation = printflow_pos_material_demand_for_items($quantity, $multiplier);
        $rawDemand = (float)$calculation['raw_demand'];
        $required = (float)$calculation['rounded_demand'];
        $basis = 'PER_ITEM';
    }

    return [
        'item_id' => $itemId,
        'name' => (string)$item['name'],
        'uom' => $uom,
        'required' => $required,
        'multiplier' => $multiplier,
        'basis' => $basis,
        'area_sqft' => $areaSqFt,
        'raw_demand' => $rawDemand,
        'branch_id' => $branchId,
    ];
}
