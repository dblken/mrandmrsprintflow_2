<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_service_dimensions.php';
require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';
require_once dirname(__DIR__) . '/includes/roll_branch_scope.php';
require_once dirname(__DIR__) . '/includes/pos_sticker_laminate_early_stock.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$options = [
    'Glossy Sticker Printed (1ft X 2ft)',
    'Glossy Sticker Cut Out (1ft X 2ft)',
    'Matte Sticker Printed (1ft X 2ft)',
    'Matte Sticker Cut Out (1ft X 2ft)',
];
$config = [[
    'field_key' => 'dimensions',
    'field_label' => 'Dimensions And Finish',
    'field_type' => 'select',
    'unit' => 'ft',
    'is_visible' => 1,
    '_options' => $options,
]];
$lineFor = static fn(string $value, int $qty = 1): array => [
    'qty' => $qty,
    'customization' => ['dimensions' => $value],
];

$assert(printflow_pos_sticker_laminate_item_for_finish($options[0]) === 20,
    'Glossy Sticker Printed maps only to Gloss Laminate item 20.');
$assert(printflow_pos_sticker_laminate_item_for_finish($options[1]) === 20,
    'Glossy Sticker Cut Out maps only to Gloss Laminate item 20.');
$assert(printflow_pos_sticker_laminate_item_for_finish($options[2]) === 21,
    'Matte Sticker Printed maps only to Matte Laminate item 21.');
$assert(printflow_pos_sticker_laminate_item_for_finish($options[3]) === 21,
    'Matte Sticker Cut Out maps only to Matte Laminate item 21.');

$gloss = printflow_pos_sticker_laminate_selection($lineFor($options[0]), $config);
$matte = printflow_pos_sticker_laminate_selection($lineFor($options[2], 3), $config);
$cutout = printflow_pos_sticker_laminate_selection($lineFor($options[1], 2), $config);
$matteCutout = printflow_pos_sticker_laminate_selection($lineFor($options[3]), $config);
$assert($gloss !== null && $gloss['item_id'] === 20 && $gloss['width_ft'] === 1.0 && $gloss['height_ft'] === 2.0,
    'Gloss finish selection resolves configured physical dimensions into feet.');
$assert($matte !== null && $matte['item_id'] === 21 && $matte['quantity'] === 3,
    'Matte selection preserves the requested order quantity.');
$assert($cutout !== null && $cutout['item_id'] === 20,
    'Glossy cut-out selects only Gloss Laminate.');
$assert($matteCutout !== null && $matteCutout['item_id'] === 21,
    'Matte cut-out selects only Matte Laminate.');
$assert(printflow_pos_sticker_laminate_selection($lineFor(''), $config) === null,
    'An unset sticker option triggers no laminate stock check while the form is incomplete.');

$item = [
    'id' => 20, 'name' => 'Gloss Laminate', 'status' => 'ACTIVE',
    'unit_of_measure' => 'ft', 'track_by_roll' => 1, 'material_format' => 'roll',
    'material_width' => 24, 'material_dimension_unit' => 'in',
];
$rollWidth = printflow_pos_sticker_laminate_roll_width_ft(20, $item);
$assert(abs($rollWidth - 2.0) < 0.000001,
    'Roll width comes from this laminate item’s 24-inch inventory metadata, not Tarpaulin presets.');
$glossPlan = printflow_pos_sticker_laminate_panel_plan($gloss, $rollWidth);
$mattePlan = printflow_pos_sticker_laminate_panel_plan($matte, $rollWidth);
$assert($glossPlan['finished_quantity'] === 1 && $glossPlan['run_length_per_panel_ft'] === 1.0,
    'A 1 × 2 ft piece fits the configured 2 ft roll by rotation and consumes 1 linear ft.');
$assert($mattePlan['finished_quantity'] === 3 && $mattePlan['run_length_per_panel_ft'] === 1.0,
    'Three selected pieces require three separate one-foot panels.');

// Matte is configured as physical roll stock in the supplied inventory view.
$matteRollWidth = printflow_pos_sticker_laminate_roll_width_ft(21, [
    'id' => 21, 'name' => 'Matte Laminate', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft',
    'track_by_roll' => 1, 'material_format' => 'roll', 'material_width' => 12,
    'material_dimension_unit' => 'in',
]);
$matte100 = printflow_pos_sticker_laminate_panel_plan(
    printflow_pos_sticker_laminate_selection($lineFor($options[3], 100), $config),
    $matteRollWidth
);
$assert($matte100['calculation']['required_linear_ft'] === 200.0,
    'A one-foot-wide laminate roll consumes two linear feet per 1 × 2 ft sticker; quantity 100 requires 200 ft.');
$matteSingle = printflow_pos_sticker_laminate_panel_plan(
    printflow_pos_sticker_laminate_selection($lineFor($options[3], 1), $config),
    $matteRollWidth
);
$cabuyao50 = printflow_pos_sticker_laminate_filter_rolls_by_width([[
    'id' => 401, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 1,
    'remaining_length_ft' => 50, 'received_at' => '2026-01-01',
]], $matteRollWidth);
$cabuyao50Allocation = printflow_tarpaulin_allocate_cart_panels(
    ['matte-single' => ['item_id' => 21, 'plan' => $matteSingle]],
    [21 => $cabuyao50]
);
$assert(printflow_pos_sticker_laminate_roll_balance_ft($cabuyao50) === 50.0
    && !empty($cabuyao50Allocation['ok'])
    && $cabuyao50Allocation['required_by_item'][21] === 2.0,
    'Cabuyao Matte Laminate with 50 ft valid roll stock does not report zero for a 1 × 2 ft matte cut-out; it requires 2 ft at configured 1 ft width.');
$matteShort = printflow_tarpaulin_allocate_cart_panels(
    ['matte-100' => ['item_id' => 21, 'plan' => $matte100]],
    [21 => [['id' => 21, 'branch_id' => 1, 'roll_code' => 'M1', 'remaining_length_ft' => 50, 'received_at' => '2026-01-01']]]
);
$matteEnough = printflow_tarpaulin_allocate_cart_panels(
    ['matte-100' => ['item_id' => 21, 'plan' => $matte100]],
    [21 => [['id' => 21, 'branch_id' => 1, 'roll_code' => 'M1', 'remaining_length_ft' => 200, 'received_at' => '2026-01-01']]]
);
$assert(empty($matteShort['ok']) && !empty($matteEnough['ok']),
    'The configured 1 ft width blocks 100 pieces at 50 ft and allows them at 200 ft.');

// Gloss is shown as standard (ledger) FT stock in the supplied current inventory view.
$glossLedgerItem = [
    'id' => 20, 'name' => 'Gloss Laminate', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft',
    'track_by_roll' => 0, 'material_format' => 'sheet', 'material_width' => 24,
    'material_dimension_unit' => 'in',
];
$assert(printflow_pos_sticker_laminate_uses_physical_rolls($item)
    && !printflow_pos_sticker_laminate_uses_physical_rolls($glossLedgerItem),
    'Stock source follows the item track_by_roll flag, not a blanket roll assumption.');
$glossLedgerPlan = printflow_pos_sticker_laminate_panel_plan(
    printflow_pos_sticker_laminate_selection($lineFor($options[0], 100), $config),
    2.0
);
$glossLedgerCapacity = printflow_tarpaulin_allocate_cart_panels(
    ['gloss' => ['item_id' => 20, 'plan' => $glossLedgerPlan]],
    [20 => [['id' => 20, 'branch_id' => 1, 'status' => 'OPEN', 'roll_code' => 'Branch FT stock', 'remaining_length_ft' => 100, 'received_at' => '']]]
);
$glossLedgerShort = printflow_tarpaulin_allocate_cart_panels(
    ['gloss' => ['item_id' => 20, 'plan' => $glossLedgerPlan]],
    [20 => [['id' => 20, 'branch_id' => 1, 'status' => 'OPEN', 'roll_code' => 'Branch FT stock', 'remaining_length_ft' => 50, 'received_at' => '']]]
);
$assert(!empty($glossLedgerCapacity['ok']) && empty($glossLedgerShort['ok']),
    'Standard FT ledger stock is checked as aggregate footage using the same panel-width fit plan.');

$branch1 = [
    ['id' => 1, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 2, 'roll_code' => 'G1', 'remaining_length_ft' => 3, 'received_at' => '2026-01-01'],
    ['id' => 2, 'branch_id' => 2, 'status' => 'OPEN', 'width_ft' => 2, 'roll_code' => 'OTHER', 'remaining_length_ft' => 100, 'received_at' => '2026-01-01'],
];
$branch1Rolls = printflow_pos_sticker_laminate_filter_branch_rolls($branch1, 1);
$assert(count($branch1Rolls) === 1 && $branch1Rolls[0]['branch_id'] === 1,
    'A branch change cannot use rolls owned by another branch.');
$sufficient = printflow_tarpaulin_allocate_cart_panels(
    ['gloss-line' => ['item_id' => 20, 'plan' => $glossPlan]],
    [20 => $branch1Rolls]
);
$assert(!empty($sufficient['ok']), 'Sufficient physical roll length permits the availability check.');
$insufficient = printflow_tarpaulin_allocate_cart_panels(
    ['matte-line' => ['item_id' => 21, 'plan' => $mattePlan]],
    [21 => [['id' => 3, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 2, 'roll_code' => 'M1', 'remaining_length_ft' => 2, 'received_at' => '2026-01-01']]]
);
$assert(empty($insufficient['ok']) && ($insufficient['reason'] ?? '') === 'physical_roll_capacity',
    'Insufficient continuous roll length blocks demand even when nominal dimensions fit.');
$invalidRolls = printflow_pos_sticker_laminate_filter_rolls_by_width([
    ['id' => 4, 'branch_id' => 1, 'status' => 'CLOSED', 'width_ft' => 1, 'remaining_length_ft' => 30],
    ['id' => 5, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 1, 'remaining_length_ft' => 0],
], $matteRollWidth);
$wrongBranchRolls = printflow_pos_sticker_laminate_filter_branch_rolls([
    ['id' => 6, 'branch_id' => 2, 'status' => 'OPEN', 'width_ft' => 1, 'remaining_length_ft' => 50],
], 1);
$wrongWidthRolls = printflow_pos_sticker_laminate_filter_rolls_by_width([
    ['id' => 7, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 2, 'remaining_length_ft' => 50],
], $matteRollWidth);
$assert($invalidRolls === [] && $wrongBranchRolls === [] && $wrongWidthRolls === [],
    'Closed, empty, other-branch, and conflicting-width rolls cannot be counted as available stock.');
$displayRollBalance = 50.0;
$actualOpenRolls = [['id' => 8, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 2, 'remaining_length_ft' => 50]];
$eligibleRolls = printflow_pos_sticker_laminate_filter_rolls_by_width($actualOpenRolls, $matteRollWidth);
$assert(abs($displayRollBalance - printflow_pos_sticker_laminate_roll_balance_ft($actualOpenRolls)) < 0.001
    && abs($displayRollBalance - printflow_pos_sticker_laminate_roll_balance_ft($eligibleRolls)) > 0.001,
    'A 50 ft inventory-list balance that disagrees with width-eligible rolls is detectable and must be reported, not rendered as zero stock.');

$source = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$cart = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$pos = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
$inventoryPage = (string)file_get_contents(dirname(__DIR__) . '/admin/inv_items_management.php');
$assert(str_contains($source, 'if ($serviceId === 36)')
    && str_contains($source, 'printflow_pos_sticker_laminate_selection')
    && str_contains($source, 'printflow_tarpaulin_allocate_cart_panels'),
    'Shared backend service-stock validation uses the mapped laminate and physical roll allocator.');
$assert(str_contains($cart, "case 'validate_service_stock':")
    && str_contains($cart, 'if (in_array($action, [\'add\', \'update\', \'update_service_link\', \'validate_stock\'], true))')
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'The endpoint revalidates availability for modal checks and again before accepting a cart add.');
$assert(str_contains($source, 'printflow_pos_tarpaulin_open_rolls($itemId, $branchId)')
    && str_contains($source, 'printflow_pos_sticker_laminate_filter_branch_rolls')
    && str_contains($source, 'InventoryManager::getStockOnHand($itemId, $branchId)')
    && str_contains($source, 'inventory_discrepancy')
    && str_contains($inventoryPage, 'InventoryManager::getStockOnHand(')
    && str_contains($inventoryPage, '$branchId')
    && str_contains($source, 'printflow_pos_sticker_laminate_uses_physical_rolls'),
    'POS reconciles its branch roll stock against the same on-hand source used by Admin and selects tracking mode from the actual item.');
$assert(str_contains($pos, 'const stickerLaminateReady = Number(serviceId) === 36')
    && str_contains($pos, '&& !stickerLaminateReady')
    && str_contains($pos, 'Matte Sticker (?:Printed|Cut Out)')
    && str_contains($pos, "body.addEventListener('change', (event) => {"),
    'A selected physical sticker finish bypasses unrelated incomplete-field deferral and form changes schedule revalidation.');
$confirmStart = strpos($pos, 'async function confirmServiceModal()');
$confirmEnd = strpos($pos, "\n        function ", $confirmStart + 20);
$confirm = $confirmStart !== false && $confirmEnd !== false ? substr($pos, $confirmStart, $confirmEnd - $confirmStart) : '';
$assert($confirm !== '' && strpos($confirm, 'posValidateServiceStockNow(') !== false,
    'The actual Add to Order confirmation path performs an immediate stock validation before posting the cart add.');
$assert(!str_contains(file_get_contents(dirname(__DIR__) . '/includes/pos_sticker_laminate_early_stock.php'), 'issueStock('),
    'The early check does not deduct or reserve inventory.');

echo "POS sticker laminate early-stock checks passed.\n";

