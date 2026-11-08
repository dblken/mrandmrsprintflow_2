<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';
require_once dirname(__DIR__) . '/includes/pos_service_dimensions.php';
require_once dirname(__DIR__) . '/includes/pos_sintraboard_early_stock.php';

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

// These IDs are sourced from the live service-fields response and local
// stable material registry. The test fixtures deliberately use different
// approved rates so no production rate is asserted or implied.
$expect(printflow_pos_material_selection_field_map(71) === [
    'field_key' => 'board_thickness',
    'options' => ['Sintra 3mm' => 206, 'Sintra 5mm' => 207],
], 'Board Thickness maps only to the live-configured stable item IDs 206 and 207.');

$items = [
    206 => ['id' => 206, 'name' => 'Sintra 3mm 32', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    207 => ['id' => 207, 'name' => 'Sintra 5mm', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
];
$dimensions = [[
    'field_key' => 'sizes',
    'field_label' => 'Dimensions',
    'field_type' => 'select',
    'unit' => 'ft',
    'allow_others' => 1,
    '_options' => ['1 Ft x 2 Ft', '2 Ft x 3 Ft'],
    'is_visible' => 1,
]];

$makeRule = static function (int $itemId, string $multiplier): array {
    return [[
        'id' => $itemId === 206 ? 35 : 52,
        'service_id' => 71,
        'service_type' => 'Sintraboard Standees',
        'item_id' => $itemId,
        'rule_type' => 'REQUIRED',
        'qty_multiplier' => $multiplier,
        'qty_multiplier_approved' => 1,
        'qty_multiplier_approved_by' => 22,
        'qty_multiplier_approved_at' => '2026-11-03 10:00:00',
        'qty_multiplier_basis' => 'AREA_SQFT',
        'qty_multiplier_approved_basis' => 'AREA_SQFT',
    ]];
};

foreach ([
    ['Sintra 3mm', 206, '1.25', '1 Ft x 2 Ft', 3, 8.0],
    ['Sintra 5mm', 207, '0.50', '2 Ft x 3 Ft', 2, 6.0],
] as [$thickness, $itemId, $testRate, $size, $quantity, $expectedRounded]) {
    $line = [
        'product_id' => 71,
        'qty' => $quantity,
        'is_service' => true,
        'customization' => [
            'service_id' => 71,
            'board_thickness' => $thickness,
            'sizes' => $size,
            'pos_selected_material_ids' => [$itemId],
        ],
    ];
    $demand = printflow_pos_sintraboard_early_demand($line, 1, $items[$itemId], $makeRule($itemId, $testRate), $dimensions);
    $expect((int)$demand['item_id'] === $itemId, "{$thickness} checks only its stable inventory item ID.");
    $expect((float)$demand['required'] === $expectedRounded, "{$thickness} uses approved area basis, configured fixture rate, and quantity with fractional demand rounded up.");
    $expect((int)$demand['branch_id'] === 1, "{$thickness} demand retains the selected branch context.");
    $expect(printflow_pos_service_stock_exceeds_available((float)$demand['required'], $expectedRounded - 1), "{$thickness} demand blocks when selected item stock is insufficient.");
    $expect(!printflow_pos_service_stock_exceeds_available((float)$demand['required'], $expectedRounded), "{$thickness} demand passes when selected item stock is sufficient.");

    $changedBranch = printflow_pos_sintraboard_early_demand($line, 4, $items[$itemId], $makeRule($itemId, $testRate), $dimensions);
    $expect((int)$changedBranch['branch_id'] === 4 && (int)$changedBranch['item_id'] === $itemId,
        "Changing the branch preserves the selected {$thickness} material and recalculates through the new branch scope.");

    $incompleteLine = $line;
    $incompleteLine['customization']['sizes'] = '';
    $probe = printflow_pos_sintraboard_early_demand($incompleteLine, 1, $items[$itemId], $makeRule($itemId, $testRate), $dimensions);
    $expect(!empty($probe['available_only']) && (float)$probe['required'] === 0.0,
        "{$thickness} can perform an availability-only check before dimensions are entered without inventing demand.");
}

$badSizeRejected = false;
try {
    $line = ['product_id' => 71, 'qty' => 1, 'customization' => ['service_id' => 71, 'board_thickness' => 'Sintra 3mm', 'sizes' => 'Medium']];
    printflow_pos_sintraboard_early_demand($line, 1, $items[206], $makeRule(206, '1.25'), $dimensions);
} catch (RuntimeException $error) {
    $badSizeRejected = str_contains($error->getMessage(), 'physical width and height')
        || str_contains($error->getMessage(), 'not present in the active service field configuration');
}
$expect($badSizeRejected, 'Options without physical dimensions fail closed instead of guessing area.');

foreach ([
    array_merge($items[206], ['id' => 2, 'name' => 'Sintra 3mm 32']),
    array_merge($items[206], ['status' => 'INACTIVE']),
    array_merge($items[206], ['unit_of_measure' => 'sheet']),
    array_merge($items[207], ['track_by_roll' => 1]),
] as $invalidItem) {
    $itemRejected = false;
    try {
        printflow_pos_sintraboard_assert_early_item((int)$invalidItem['id'], $invalidItem);
    } catch (RuntimeException) {
        $itemRejected = true;
    }
    $expect($itemRejected, 'Incorrect ID/name, inactive status, invalid UOM, and roll-tracked items are rejected.');
}

$missingRateRejected = false;
try {
    printflow_pos_sintraboard_early_demand([
        'product_id' => 71,
        'qty' => 1,
        'customization' => ['service_id' => 71, 'board_thickness' => 'Sintra 3mm', 'sizes' => '1 Ft x 2 Ft'],
    ], 1, $items[206], [[
        'id' => 35, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 206,
        'rule_type' => 'REQUIRED', 'qty_multiplier' => null, 'qty_multiplier_approved' => 0,
        'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null,
        'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null,
    ]], $dimensions);
} catch (RuntimeException $error) {
    $missingRateRejected = str_contains($error->getMessage(), 'approved') || str_contains($error->getMessage(), 'configured');
}
$expect($missingRateRejected, 'Missing or unapproved material rates block computed demand; no multiplier is invented.');

$root = dirname(__DIR__);
$validationSource = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$stockHelperSource = (string)file_get_contents($root . '/includes/pos_sintraboard_early_stock.php');
$posSource = (string)file_get_contents($root . '/staff/pos.php');
$cartSource = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$expect(str_contains($validationSource, 'printflow_pos_sintraboard_early_demand')
    && str_contains($validationSource, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'),
    'Server validator computes selected Sintraboard demand and checks stock for the selected branch.');
$expect(str_contains($cartSource, "case 'validate_service_stock':")
    && str_contains($cartSource, 'printflow_pos_validate_service_stock(')
    && str_contains($cartSource, "in_array(\$action, ['add', 'update', 'update_service_link', 'validate_stock'], true)"),
    'The server validates service stock both on precheck and Add to Order; no selection-time deduction is introduced.');
$expect(str_contains($stockHelperSource, 'qty_multiplier_approved_basis')
    && str_contains($stockHelperSource, 'printflow_pos_material_demand_for_area(')
    && !str_contains($stockHelperSource, 'InventoryManager::issueStock'),
    'Area demand requires the explicitly approved basis and remains a read-only check.');
$expect(str_contains($posSource, 'sintraSelectedItemId')
    && str_contains($posSource, 'sintraMaterial.availability_only')
    && str_contains($posSource, 'Insufficient ${issue.name || sintraMaterial.name'),
    'POS validates only the selected thickness and shows real stock shortages.');

if ($failures !== []) {
    fwrite(STDERR, "pos_sintraboard_early_stock_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "pos_sintraboard_early_stock_validation_test passed.\n";
