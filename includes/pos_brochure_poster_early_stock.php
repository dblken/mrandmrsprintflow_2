<?php
/**
 * Early POS capacity checks for Brochure (67) and Poster Printing (64).
 * Preferred mapped substrates: Brochure → C2S Special Paper #211,
 * Poster Printing → C2s Board #212. Demand uses Admin-approved AREA_SQFT
 * multipliers and never writes inventory transactions.
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
 * @return array{width:float,height:float,unit:string}
 */
function printflow_pos_brochure_poster_dimensions(array $customization): array
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
        throw new InvalidArgumentException('Select valid Dimensions before checking Brochure/Poster material stock.');
    }
    if (!in_array($unit, ['ft', 'in', 'cm', 'mm'], true)) {
        throw new InvalidArgumentException('Select Dimensions with a supported unit before checking Brochure/Poster material stock.');
    }
    return [
        'width' => (float)$width,
        'height' => (float)$height,
        'unit' => $unit,
    ];
}

/**
 * Load the Admin-approved AREA_SQFT multiplier for the preferred mapped item.
 * Test override: $GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'][$serviceId][$itemId].
 */
function printflow_pos_brochure_poster_approved_multiplier(int $serviceId, int $itemId): float
{
    if (isset($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'])
        && is_array($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'])) {
        $override = $GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'][$serviceId][$itemId] ?? null;
        if (is_numeric($override) && is_finite((float)$override) && (float)$override > 0) {
            return (float)$override;
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
         LIMIT 5",
        $scope['types'] . 'i',
        array_merge($scope['params'], [$itemId])
    );
    if ($rows === false) {
        throw new RuntimeException(
            'The ' . $serviceLabel . ' material multiplier could not be loaded. Try again or ask an Admin to review the service-material mapping.'
        );
    }
    if ($rows === []) {
        throw new RuntimeException(
            'No active material rule maps inventory item #' . $itemId . ' to ' . $serviceLabel
            . '. Ask an Admin to configure and approve the AREA_SQFT multiplier before adding this service.'
        );
    }

    $row = null;
    foreach ($rows as $candidate) {
        if (!is_array($candidate)) continue;
        $basis = strtoupper(trim((string)($candidate['qty_multiplier_approved_basis']
            ?? $candidate['qty_multiplier_basis']
            ?? '')));
        if ($basis !== '' && $basis !== 'AREA_SQFT' && $basis !== 'INVENTORY_UNITS_PER_SQFT') {
            continue;
        }
        $row = $candidate;
        break;
    }
    if ($row === null) {
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    }
    if ($row === null) {
        throw new RuntimeException(
            'No usable AREA_SQFT material rule was found for ' . $serviceLabel . ' item #' . $itemId . '.'
        );
    }

    $approvedBasis = strtoupper(trim((string)($row['qty_multiplier_approved_basis'] ?? '')));
    $storedBasis = strtoupper(trim((string)($row['qty_multiplier_basis'] ?? '')));
    if ($approvedBasis !== 'AREA_SQFT'
        && $storedBasis !== 'INVENTORY_UNITS_PER_SQFT'
        && $storedBasis !== 'AREA_SQFT') {
        throw new RuntimeException(
            'The configured ' . $serviceLabel . ' multiplier for inventory item #' . $itemId
            . ' must use the AREA_SQFT / inventory-units-per-sqft basis.'
        );
    }

    try {
        return printflow_pos_approved_service_multiplier(
            $row['qty_multiplier'] ?? null,
            $row['qty_multiplier_approved'] ?? null,
            $row['qty_multiplier_approved_by'] ?? null,
            $row['qty_multiplier_approved_at'] ?? null
        );
    } catch (RuntimeException $error) {
        throw new RuntimeException(
            'REQUIRED material item #' . $itemId . ' for ' . $serviceLabel
            . ' has no valid Admin-approved AREA_SQFT multiplier. An Admin must configure and approve it before this service can be added.',
            0,
            $error
        );
    }
}

/**
 * Build early demand for Brochure/Poster before Add to Order.
 *
 * @param array<string,mixed> $line
 * @param array<string,mixed>|null $itemOverride test-only inventory row
 * @return array{item_id:int,name:string,uom:string,required:float,multiplier:float,area_sqft:float,raw_demand:float}
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

    $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
    $dimensions = printflow_pos_brochure_poster_dimensions($customization);
    $multiplier = printflow_pos_brochure_poster_approved_multiplier($serviceId, $itemId);
    $calculation = printflow_pos_material_demand_for_area(
        $dimensions['width'],
        $dimensions['height'],
        $dimensions['unit'],
        $quantity,
        $multiplier
    );

    return [
        'item_id' => $itemId,
        'name' => (string)$item['name'],
        'uom' => (string)($item['unit_of_measure'] ?? 'sheet'),
        'required' => (float)$calculation['rounded_demand'],
        'multiplier' => (float)$multiplier,
        'area_sqft' => (float)$calculation['area_sqft'],
        'raw_demand' => (float)$calculation['raw_demand'],
        'branch_id' => $branchId,
    ];
}
