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
$expect(printflow_pos_brochure_poster_is_early_stock_service(67)
    && printflow_pos_brochure_poster_is_early_stock_service(64),
    'Brochure and Poster Printing enable early paper capacity checks');

$specialPaper = [
    'id' => 211,
    'name' => 'C2S Special Paper',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'pcs',
    'track_by_roll' => 0,
];
$c2sBoard = [
    'id' => 212,
    'name' => 'C2s Board',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'sheet',
    'track_by_roll' => 0,
];

$expect((int)printflow_pos_brochure_poster_assert_mapped_item(67, $specialPaper)['id'] === 211,
    'Brochure mapping resolves inventory item #211 by ID');
$expect((int)printflow_pos_brochure_poster_assert_mapped_item(64, $c2sBoard)['id'] === 212,
    'Poster mapping resolves inventory item #212 by ID');

$inactiveCaught = false;
try {
    printflow_pos_brochure_poster_assert_mapped_item(67, array_merge($specialPaper, ['status' => 'INACTIVE']));
} catch (RuntimeException $error) {
    $inactiveCaught = true;
}
$expect($inactiveCaught, 'Inactive preferred Brochure paper fails closed');

$GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_RULES'] = [
    67 => [211 => ['multiplier' => 1.0, 'basis' => 'PER_ITEM']],
    64 => [212 => ['multiplier' => 1.0, 'basis' => 'PER_ITEM']],
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
        'branch_id' => 1,
    ],
];
$brochureDemand = printflow_pos_brochure_poster_early_demand($brochureLine, 1, $specialPaper);
$expect((int)$brochureDemand['item_id'] === 211, 'Brochure early demand uses Special Paper #211');
$expect(strtolower((string)$brochureDemand['uom']) === 'pcs', 'Brochure Special Paper UOM is pcs');
$expect((float)$brochureDemand['required'] === 100.0, 'Brochure qty 100 requires 100 pcs at 1:1 configured rate');
$expect(printflow_pos_service_stock_exceeds_available((float)$brochureDemand['required'], 63.0),
    'Reported case: Brochure qty 100 exceeds C2s Special Paper stock 63 pcs');
$expect(!printflow_pos_service_stock_exceeds_available(50.0, 63.0),
    'Brochure quantity within Special Paper stock can proceed');

$brochureLine['qty'] = 50;
$brochureHalf = printflow_pos_brochure_poster_early_demand($brochureLine, 1, $specialPaper);
$expect((float)$brochureHalf['required'] === 50.0, 'Lower Brochure quantity reduces required pcs');

$brochureLine['qty'] = 100;
$brochureLine['customization']['width'] = '16';
$brochureLine['customization']['height'] = '22';
$brochureLargerDims = printflow_pos_brochure_poster_early_demand($brochureLine, 1, $specialPaper);
$expect((float)$brochureLargerDims['required'] === 100.0,
    'Discrete pcs paper keeps per-piece demand when dimensions change; qty still drives required units');

$posterLine = [
    'product_id' => 64,
    'qty' => 100,
    'is_service' => true,
    'customization' => [
        'service_id' => 64,
        'width' => '8.27',
        'height' => '11.69',
        'pos_material_dimension_unit' => 'in',
        'branch_id' => 1,
    ],
];
$posterDemand = printflow_pos_brochure_poster_early_demand($posterLine, 1, $c2sBoard);
$expect((int)$posterDemand['item_id'] === 212, 'Poster early demand uses C2s Board #212');
$expect(strtolower((string)$posterDemand['uom']) === 'sheet', 'Poster C2s Board UOM is sheet');
$expect((float)$posterDemand['required'] === 100.0, 'Poster qty 100 requires 100 sheets at configured rate');
$expect(printflow_pos_service_stock_exceeds_available((float)$posterDemand['required'], 98.0),
    'Reported case: Poster qty 100 exceeds C2s Board stock 98 sheets');
$expect(!printflow_pos_service_stock_exceeds_available(90.0, 98.0),
    'Poster quantity within C2s Board stock can proceed');

$combined = [];
printflow_pos_service_stock_add_demand($combined, 211, 40.0, 'C2S Special Paper', 'pcs');
printflow_pos_service_stock_add_demand($combined, 211, 30.0, 'C2S Special Paper', 'pcs');
$expect($combined[211]['quantity'] === 70.0, 'Cart lines accumulate Brochure paper demand');
$expect(printflow_pos_service_stock_exceeds_available($combined[211]['quantity'], 63.0),
    'Server revalidation fails when combined cart demand exceeds branch stock');

$root = dirname(__DIR__);
$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$early = (string)file_get_contents($root . '/includes/pos_brochure_poster_early_stock.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');

$expect(str_contains($validation, 'printflow_pos_brochure_poster_early_demand'),
    'Validator computes Brochure/Poster demand before Add to Order');
$expect(str_contains($early, 'printflow_pos_material_demand_for_items'),
    'Configured per-piece consumption drives discrete sheet/pcs demand');
$expect(str_contains($early, "67 => 211") && str_contains($early, "64 => 212"),
    'Preferred papers are matched by inventory item ID');
$expect(str_contains($cart, "case 'validate_service_stock':")
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Form edits and Add to Order revalidate through validate_service_stock');
$expect(str_contains($cart, "empty(\$customization['service_id'])"),
    'validate_service_stock stamps service_id for Brochure/Poster demand routing');
$expect(str_contains($pos, 'paperEarlyStock')
    && str_contains($pos, 'paperDimsReady'),
    'POS modal revalidates Brochure/Poster once Dimensions + Qty are ready');
$expect(str_contains($pos, '[67, 64].includes(Number(serviceId))')
    && str_contains($pos, 'preferred .+ material|inventory item #|material stock'),
    'Brochure/Poster mapping and stock failures stay visible in the modal');
$expect(str_contains($pos, 'Required ${materialName}')
    && str_contains($pos, 'Available:'),
    'POS modal shows required usage and available stock');
$expect(str_contains($validation, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'),
    'Branch stock comes from InventoryManager::getStockOnHand (read-only)');

unset($GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_RULES'], $GLOBALS['PRINTFLOW_TEST_BROCHURE_POSTER_MULTIPLIERS']);

if ($failures !== []) {
    fwrite(STDERR, "pos_brochure_poster_early_stock_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_brochure_poster_early_stock_validation_test passed.\n";
