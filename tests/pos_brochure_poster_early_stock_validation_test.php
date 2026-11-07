<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';
require_once dirname(__DIR__) . '/includes/pos_brochure_poster_early_stock.php';

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$expect(printflow_pos_brochure_poster_preferred_item_id(67) === 211, 'Brochure prefers C2S Special Paper #211');
$expect(printflow_pos_brochure_poster_preferred_item_id(64) === 212, 'Poster Printing prefers C2s Board #212');
$expect(printflow_pos_brochure_poster_preferred_item_id(63) === 0, 'Mugs is not routed through Brochure/Poster early stock');
$expect(printflow_pos_brochure_poster_is_early_stock_service(67)
    && printflow_pos_brochure_poster_is_early_stock_service(64)
    && !printflow_pos_brochure_poster_is_early_stock_service(40),
    'Only Brochure and Poster Printing enable early paper capacity checks');

$specialPaper = [
    'id' => 211,
    'name' => 'C2S Special Paper',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'sheet',
    'track_by_roll' => 0,
];
$c2sBoard = [
    'id' => 212,
    'name' => 'C2s Board',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'sheet',
    'track_by_roll' => 0,
];

$brochureItem = printflow_pos_brochure_poster_assert_mapped_item(67, $specialPaper);
$expect((int)$brochureItem['id'] === 211, 'Brochure mapping resolves inventory item #211 by ID');
$posterItem = printflow_pos_brochure_poster_assert_mapped_item(64, $c2sBoard);
$expect((int)$posterItem['id'] === 212, 'Poster mapping resolves inventory item #212 by ID');

$inactiveCaught = false;
try {
    printflow_pos_brochure_poster_assert_mapped_item(67, array_merge($specialPaper, ['status' => 'INACTIVE']));
} catch (RuntimeException $error) {
    $inactiveCaught = str_contains($error->getMessage(), 'inactive') || str_contains($error->getMessage(), 'unavailable');
}
$expect($inactiveCaught, 'Inactive preferred Brochure paper fails closed');

$nameCaught = false;
try {
    printflow_pos_brochure_poster_assert_mapped_item(64, array_merge($c2sBoard, ['name' => 'Some Other Board']));
} catch (RuntimeException $error) {
    $nameCaught = str_contains($error->getMessage(), 'renamed') || str_contains($error->getMessage(), 'unavailable');
}
$expect($nameCaught, 'Renamed preferred Poster paper fails closed (ID+name identity)');

$dims = printflow_pos_brochure_poster_dimensions([
    'width' => '8',
    'height' => '11',
    'pos_material_dimension_unit' => 'in',
]);
$expect($dims['width'] === 8.0 && $dims['height'] === 11.0 && $dims['unit'] === 'in',
    'Brochure/Poster dimensions parse from POS customization');

$missingDimCaught = false;
try {
    printflow_pos_brochure_poster_dimensions(['width' => '', 'height' => '11', 'pos_material_dimension_unit' => 'in']);
} catch (InvalidArgumentException $error) {
    $missingDimCaught = true;
}
$expect($missingDimCaught, 'Blank dimensions fail before stock math');

$GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS'] = [
    67 => [211 => 1.0],
    64 => [212 => 1.0],
];

$brochureLine = [
    'product_id' => 67,
    'qty' => 100,
    'is_service' => true,
    'customization' => [
        'service_id' => 67,
        'width' => '8',
        'height' => '11',
        'pos_material_dimension_unit' => 'in',
        'layout' => 'without_layout',
    ],
];
$brochureDemand = printflow_pos_brochure_poster_early_demand($brochureLine, 1, $specialPaper);
$expect((int)$brochureDemand['item_id'] === 211, 'Brochure early demand uses Special Paper #211');
$expect((float)$brochureDemand['required'] > 0, 'Brochure area demand produces a positive required quantity');
$brochureRequiredAt100 = (float)$brochureDemand['required'];

$brochureLine['qty'] = 50;
$brochureHalf = printflow_pos_brochure_poster_early_demand($brochureLine, 1, $specialPaper);
$expect((float)$brochureHalf['required'] < $brochureRequiredAt100,
    'Lower Brochure quantity reduces required material');

$brochureLine['qty'] = 100;
$brochureLine['customization']['width'] = '16';
$brochureLine['customization']['height'] = '22';
$brochureLarger = printflow_pos_brochure_poster_early_demand($brochureLine, 1, $specialPaper);
$expect((float)$brochureLarger['required'] > $brochureRequiredAt100,
    'Larger Brochure dimensions increase required material');

$posterLine = [
    'product_id' => 64,
    'qty' => 10,
    'is_service' => true,
    'customization' => [
        'service_id' => 64,
        'width' => '18',
        'height' => '24',
        'pos_material_dimension_unit' => 'in',
    ],
];
$posterDemand = printflow_pos_brochure_poster_early_demand($posterLine, 1, $c2sBoard);
$expect((int)$posterDemand['item_id'] === 212, 'Poster early demand uses C2s Board #212');
$posterRequired = (float)$posterDemand['required'];
$expect($posterRequired > 0, 'Poster area demand produces a positive required quantity');
$expect(printflow_pos_service_stock_exceeds_available($posterRequired, $posterRequired - 1),
    'Insufficient Poster stock is detected against required demand');
$expect(!printflow_pos_service_stock_exceeds_available($posterRequired, $posterRequired),
    'Exact available Poster stock is sufficient');

$combined = [];
printflow_pos_service_stock_add_demand($combined, 211, 20.0, 'C2S Special Paper', 'sheet');
printflow_pos_service_stock_add_demand($combined, 211, 15.0, 'C2S Special Paper', 'sheet');
$expect($combined[211]['quantity'] === 35.0, 'Cart lines accumulate Brochure paper demand for the same branch item');
$expect(printflow_pos_service_stock_exceeds_available($combined[211]['quantity'], 34.0),
    'Stock that changed after the form opened fails once cart demand exceeds availability');

$root = dirname(__DIR__);
$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$early = (string)file_get_contents($root . '/includes/pos_brochure_poster_early_stock.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');

$expect(str_contains($validation, 'printflow_pos_brochure_poster_is_early_stock_service'),
    'Validator includes Brochure/Poster early capacity path');
$expect(str_contains($validation, 'printflow_pos_brochure_poster_early_demand'),
    'Validator computes Brochure/Poster demand before Add to Order');
$expect(str_contains($early, 'InventoryManager::getItem') || str_contains($early, 'getItem($itemId)'),
    'Preferred paper is resolved by inventory item ID');
$expect(str_contains($early, 'printflow_pos_material_demand_for_area'),
    'Configured AREA_SQFT consumption drives required quantity');
$expect(str_contains($early, 'printflow_pos_approved_service_multiplier'),
    'Missing/unapproved multipliers fail closed');
$expect(str_contains($cart, "case 'validate_service_stock':"),
    'Form open/qty/dimension edits call validate_service_stock');
$expect(
    str_contains($cart, "in_array(\$action, ['add', 'update', 'update_service_link', 'validate_stock'], true)")
        && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Server revalidates stock on add without using a separate deduct path'
);
$expect(str_contains($pos, 'preferredPaperId')
    && str_contains($pos, 'Number(serviceId) === 67 ? 211')
    && str_contains($pos, 'Number(serviceId) === 64 ? 212'),
    'POS modal matches Brochure #211 and Poster #212 by inventory ID');
$expect(str_contains($pos, 'Required ${materialName}')
    && str_contains($pos, 'Available:'),
    'POS modal shows required amount and available stock');
$expect(str_contains($validation, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'),
    'Branch stock comes from InventoryManager::getStockOnHand (read-only)');

unset($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS']);

if ($failures !== []) {
    fwrite(STDERR, "pos_brochure_poster_early_stock_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_brochure_poster_early_stock_validation_test passed.\n";
