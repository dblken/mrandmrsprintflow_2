<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_plates_release_rule.php';

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$expect((printflow_pos_plates_early_stock_item_for_type('Address Plate')['item_id'] ?? 0) === 6, 'Address Plate maps to AC HOME #6');
$expect((printflow_pos_plates_early_stock_item_for_type('Adress Plate')['item_id'] ?? 0) === 6, 'POS spelling Adress Plate maps to AC HOME #6');
$expect((printflow_pos_plates_early_stock_item_for_type('Car And Euro Plate')['item_id'] ?? 0) === 3, 'Car And Euro Plate maps to AC EURO #3');
$expect((printflow_pos_plates_early_stock_item_for_type('New Plate')['item_id'] ?? 0) === 2, 'New Plate maps to AC PH #2');
$expect(printflow_pos_plates_early_stock_item_for_type('Motor Cycle Plate') === null, 'No unprovided Motor Cycle item mapping is invented');

// Canonical service material identity registry and the migration's verified
// service/material ID registry are the local mapping sources.
$root = dirname(__DIR__);
$selectionSource = (string)file_get_contents($root . '/includes/pos_material_selection.php');
$migrationSource = (string)file_get_contents($root . '/database/migrate_service_material_rule_service_id_20261103.php');
$expect(str_contains($selectionSource, "2 => 'AC PH'") && str_contains($selectionSource, "3 => 'AC EURO'")
    && str_contains($selectionSource, "6 => 'AC HOME'"), 'Plate item IDs are present in the canonical service material identity registry');
$expect(str_contains($migrationSource, "39 => [1 => 'AC MC', 2 => 'AC PH', 3 => 'AC EURO'")
    && str_contains($migrationSource, "6 => 'AC HOME'"), 'Plate IDs/names are consistent with the verified material migration registry');

$items = [
    6 => ['id' => 6, 'name' => 'AC HOME', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    3 => ['id' => 3, 'name' => 'AC EURO', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    2 => ['id' => 2, 'name' => 'AC PH', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
];

foreach ([
    ['Address Plate', 6, 'AC HOME'],
    ['Car And Euro Plate', 3, 'AC EURO'],
    ['New Plate', 2, 'AC PH'],
] as [$type, $itemId, $name]) {
    $line = [
        'product_id' => 39,
        'qty' => 2,
        'is_service' => true,
        'customization' => ['service_id' => 39, 'type_of_plates' => $type, 'branch_id' => 4],
    ];
    $demand = printflow_pos_plates_early_demand($line, 4, $items[$itemId]);
    $expect(is_array($demand) && (int)$demand['item_id'] === $itemId, "{$type} demand uses inventory item #{$itemId}");
    $expect(strcasecmp((string)$demand['name'], $name) === 0, "{$type} demand keeps mapped name {$name}");
    $expect((float)$demand['required'] === 2.0, "{$type} quantity 2 requires 2 pcs");
    $expect((int)$demand['branch_id'] === 4, "{$type} demand is tied to the selected branch");
    $expect(printflow_pos_service_stock_exceeds_available(2.0, 1.0), "{$type} qty 2 exceeds stock of 1");
    $expect(!printflow_pos_service_stock_exceeds_available(2.0, 2.0), "{$type} qty 2 fits stock of 2");
}

// Branch changes retain the selected item and order-quantity demand while
// carrying the new branch into the authorized branch-scoped stock lookup.
$branchOne = printflow_pos_plates_early_demand([
    'product_id' => 39,
    'qty' => 1,
    'customization' => ['service_id' => 39, 'type_of_plates' => 'New Plate'],
], 1, $items[2]);
$branchFour = printflow_pos_plates_early_demand([
    'product_id' => 39,
    'qty' => 1,
    'customization' => ['service_id' => 39, 'type_of_plates' => 'New Plate'],
], 4, $items[2]);
$expect((int)$branchOne['branch_id'] === 1 && (int)$branchFour['branch_id'] === 4,
    'Changing the selected branch changes the stock lookup scope');
$expect((int)$branchOne['item_id'] === (int)$branchFour['item_id']
    && (float)$branchOne['required'] === (float)$branchFour['required'],
    'Branch changes keep the exact selected Plate item and quantity demand');

$lineQty1 = [
    'product_id' => 39,
    'qty' => 1,
    'customization' => ['service_id' => 39, 'type_of_plates' => 'New Plate'],
];
$one = printflow_pos_plates_early_demand($lineQty1, 1, $items[2]);
$expect((float)$one['required'] === 1.0, 'Quantity 1 requires 1 pc of AC PH');
$unmappedTypeBlocked = false;
try {
    printflow_pos_plates_early_demand([
        'product_id' => 39,
        'qty' => 1,
        'customization' => ['service_id' => 39, 'type_of_plates' => 'Motor Cycle Plate'],
    ], 1, null);
} catch (RuntimeException $error) {
    $unmappedTypeBlocked = str_contains($error->getMessage(), 'No verified inventory mapping');
}
$expect($unmappedTypeBlocked, 'A selected Plate type without a provided mapping fails closed rather than bypassing the stock check');

$renamed = false;
try {
    printflow_pos_plates_assert_early_stock_item(2, 'AC PH', array_merge($items[2], ['name' => 'Something Else']));
} catch (RuntimeException $error) {
    $renamed = true;
}
$expect($renamed, 'Renamed plate blank fails closed');

$invalidUnitCaught = false;
try {
    printflow_pos_plates_assert_early_stock_item(2, 'AC PH', array_merge($items[2], ['unit_of_measure' => 'sheet']));
} catch (RuntimeException $error) {
    $invalidUnitCaught = str_contains($error->getMessage(), 'not inventoried in pieces');
}
$expect($invalidUnitCaught, 'A mapped Plate item with a non-piece UOM fails closed instead of assuming pcs');

$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$expect(str_contains($validation, 'printflow_pos_plates_early_demand'), 'Validator runs Plates early demand before Add to Order');
$expect(str_contains($validation, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'), 'Branch stock stays a read-only lookup');
$expect(str_contains($validation, 'printflow_pos_service_stock_add_demand')
    && str_contains($validation, "'required' => (float)\$required['quantity']"),
    'Selected Plate demand enters the shared read-only material availability aggregation');
$expect(str_contains($cart, 'pos_cart_service_validation_branch_id')
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Server-side stock validation receives the authorized service branch');
$expect(str_contains($pos, 'plateTypeReady') && str_contains($pos, 'Insufficient ${issue.name || materialName} stock')
    && str_contains($pos, 'materials.find(row => Number(row && row.item_id) === plateTypeItemId)'),
    'POS modal blocks Add to Order with an insufficient-stock message');
$expect(str_contains($cart, "case 'validate_service_stock':") && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Add to cart revalidates through validate_service_stock');
$expect(str_contains($pos, "body.addEventListener('change'")
    && str_contains($pos, 'plateTypeItemIds')
    && str_contains($pos, 'const plateTypeSelected = Number(serviceId) === 39 && plateTypeRaw !==')
    && str_contains($pos, 'const plateTypeItemId = Number(serviceId) === 39'),
    'Plate type and editable branch changes schedule immediate revalidation; unmapped selected types reach fail-closed server validation');

if ($failures !== []) {
    fwrite(STDERR, "pos_plates_early_stock_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "pos_plates_early_stock_validation_test passed.\n";
