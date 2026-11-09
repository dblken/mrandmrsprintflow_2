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
$matteSingleSelection = printflow_pos_sticker_laminate_selection($lineFor($options[3], 1), $config);
$matteSingle = printflow_pos_sticker_laminate_panel_plan($matteSingleSelection, $matteRollWidth);
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
$matte24InConfig = [
    'id' => 21, 'name' => 'Matte Laminate', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft',
    'track_by_roll' => 1, 'material_format' => 'roll', 'size_variant' => '24 in',
    'material_width' => 24, 'material_dimension_unit' => 'in',
];
$convertedMatteWidth = printflow_pos_sticker_laminate_roll_width_ft(21, $matte24InConfig);
$convertedMatch = printflow_pos_sticker_laminate_filter_rolls_by_width($actualOpenRolls, $convertedMatteWidth);
$assert($convertedMatteWidth === 2.0 && count($convertedMatch) === 1,
    'A configured physical width of 24 in converts to 2 ft and matches the stored 2 ft roll width.');
$mismatchedAdminConfig = [
    'id' => 21, 'name' => 'Matte Laminate', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft',
    'track_by_roll' => 1, 'material_format' => 'roll', 'size_variant' => '24 in',
    'material_width' => 24, 'material_dimension_unit' => 'ft',
];
$badConfiguredWidthFt = printflow_pos_sticker_laminate_roll_width_ft(21, $mismatchedAdminConfig);
$assert($badConfiguredWidthFt === 24.0,
    'An Admin width explicitly stored in feet remains 24 ft; the Size/Variant label is not used to silently reinterpret it.');
$matte24InPlan = printflow_pos_sticker_laminate_panel_plan($matteSingleSelection, $convertedMatteWidth);
$matchingTwoFtRolls = printflow_pos_sticker_laminate_filter_rolls_by_width($actualOpenRolls, $convertedMatteWidth);
$matchingTwoFtAllocation = printflow_tarpaulin_allocate_cart_panels(
    ['matte-24in' => ['item_id' => 21, 'plan' => $matte24InPlan]],
    [21 => $matchingTwoFtRolls]
);
$assert(count($matchingTwoFtRolls) === 1 && !empty($matchingTwoFtAllocation['ok'])
    && $matchingTwoFtAllocation['required_by_item'][21] === 1.0,
    'A real open 2 ft roll matches 24 in item metadata and has enough footage for a 1 × 2 ft piece.');
$liveMismatchRolls = [[
    'id' => 158, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 1,
    'remaining_length_ft' => 50, 'received_at' => '2026-11-07 22:05:12',
]];
$liveMismatchEligible = printflow_pos_sticker_laminate_filter_rolls_by_width($liveMismatchRolls, $convertedMatteWidth);
$liveMismatchDetails = printflow_pos_sticker_laminate_roll_width_mismatches($liveMismatchRolls, $convertedMatteWidth);
$assert($liveMismatchEligible === [] && count($liveMismatchDetails) === 1
    && $liveMismatchDetails[0]['id'] === 158 && $liveMismatchDetails[0]['width_ft'] === 1.0
    && $liveMismatchDetails[0]['remaining_length_ft'] === 50.0,
    'A 50 ft branch roll recorded at 1 ft does not masquerade as the configured 2 ft roll and reports its exact conflicting record.');
$correctedRoll158 = [[
    'id' => 158, 'item_id' => 21, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 2,
    'total_length_ft' => 50, 'remaining_length_ft' => 50, 'received_at' => '2026-11-07 22:05:12',
]];
$correctedRoll158Eligible = printflow_pos_sticker_laminate_filter_rolls_by_width($correctedRoll158, $convertedMatteWidth);
$correctedRoll158Plan = printflow_pos_sticker_laminate_panel_plan($matteSingleSelection, $convertedMatteWidth);
$correctedRoll158Allocation = printflow_tarpaulin_allocate_cart_panels(
    ['matte-roll-158' => ['item_id' => 21, 'plan' => $correctedRoll158Plan]],
    [21 => $correctedRoll158Eligible]
);
$assert(count($correctedRoll158Eligible) === 1 && $correctedRoll158Eligible[0]['id'] === 158
    && $correctedRoll158[0]['remaining_length_ft'] === 50
    && !empty($correctedRoll158Allocation['ok']),
    'After a verified width-only correction to 2 ft, roll 158 remains at 50 ft and is recognized by POS.');
$reconciliationMessage = printflow_pos_sticker_laminate_reconciliation_message('Matte Laminate', [
    'display_balance' => 50, 'branch_roll_balance' => 50, 'eligible_roll_balance' => 0,
    'configured_width_ft' => 2, 'width_mismatches' => $liveMismatchDetails,
]);
$assert(str_contains($reconciliationMessage, 'Inventory Items shows 50 ft')
    && str_contains($reconciliationMessage, 'configured width is 2 ft')
    && str_contains($reconciliationMessage, 'roll #158 is recorded as 1 ft wide with 50 ft remaining')
    && str_contains($reconciliationMessage, 'Verify the physical width'),
    'The staff-facing reconciliation explains the exact configured/stored roll-width mismatch without changing stock availability.');

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
$assert(str_contains($source, 'printflow_pos_sticker_laminate_roll_width_mismatches')
    && str_contains($source, 'printflow_pos_sticker_laminate_reconciliation_message'),
    'The POS reconciliation message identifies configured width and the exact stored roll width without treating mismatched footage as eligible.');
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

$customConfig = $config;
$customConfig[0]['_options'][] = 'Others';
$customLine = static function (string $finish, string $process, float $width, float $height, int $qty): array {
    return [
        'qty' => $qty,
        'customization' => [
            'dimensions' => 'Others',
            'width' => $width,
            'height' => $height,
            'pos_material_dimension_unit' => 'ft',
            'sticker_custom_finish' => $finish,
            'sticker_custom_process' => $process,
        ],
    ];
};
$customMatte = printflow_pos_sticker_laminate_selection($customLine('Matte', 'Printed', 2, 3, 4), $customConfig);
$customGloss = printflow_pos_sticker_laminate_selection($customLine('Gloss', 'Cut Out', 2, 3, 4), $customConfig);
$assert($customMatte !== null && $customMatte['item_id'] === 21 && $customMatte['width_ft'] === 2.0 && $customMatte['height_ft'] === 3.0 && $customMatte['quantity'] === 4,
    'Custom Matte size uses Matte Laminate item 21 and the entered width, height, and quantity.');
$assert($customGloss !== null && $customGloss['item_id'] === 20,
    'Custom Gloss size uses Gloss Laminate item 20.');
$assert(printflow_pos_sticker_laminate_selection([
    'qty' => 1,
    'customization' => ['dimensions' => 'Others', 'width' => 2, 'height' => 3, 'pos_material_dimension_unit' => 'ft'],
], $customConfig) === null, 'A custom size without a laminate finish stays pending.');
$onePiece = printflow_pos_sticker_laminate_panel_plan(
    printflow_pos_sticker_laminate_selection($customLine('Matte', 'Printed', 2, 3, 1), $customConfig),
    2.0
);
$fourPieces = printflow_pos_sticker_laminate_panel_plan($customMatte, 2.0);
$assert($onePiece['calculation']['required_linear_ft'] === 3.0
    && $fourPieces['calculation']['required_linear_ft'] === 12.0
    && $fourPieces['finished_quantity'] === 4,
    'A 2 × 3 ft sticker on a 2 ft roll uses the 3 ft side as the run; quantity 4 requires 12 ft.');
$enough = printflow_tarpaulin_allocate_cart_panels(
    ['custom' => ['item_id' => 21, 'plan' => $fourPieces]],
    [21 => [['id' => 501, 'branch_id' => 1, 'roll_code' => 'M-A', 'status' => 'OPEN', 'remaining_length_ft' => 12, 'received_at' => '2026-01-01']]]
);
$short = printflow_tarpaulin_allocate_cart_panels(
    ['custom' => ['item_id' => 21, 'plan' => $fourPieces]],
    [21 => [['id' => 501, 'branch_id' => 1, 'roll_code' => 'M-A', 'status' => 'OPEN', 'remaining_length_ft' => 9, 'received_at' => '2026-01-01']]]
);
$split = printflow_tarpaulin_allocate_cart_panels(
    ['custom' => ['item_id' => 21, 'plan' => $fourPieces]],
    [21 => [
        ['id' => 601, 'branch_id' => 1, 'roll_code' => 'M-1', 'status' => 'OPEN', 'remaining_length_ft' => 6, 'received_at' => '2026-01-01'],
        ['id' => 602, 'branch_id' => 1, 'roll_code' => 'M-2', 'status' => 'OPEN', 'remaining_length_ft' => 6, 'received_at' => '2026-01-02'],
    ]]
);
$splitRows = array_values((array)($split['allocations_by_line']['custom'] ?? []));
$splitByRoll = [];
foreach ($splitRows as $row) $splitByRoll[(int)$row['roll_id']] = (float)$row['length_ft'];
$assert(!empty($enough['ok']) && empty($short['ok']),
    'Custom sticker stock passes when the open roll covers 12 ft and fails when it only has 9 ft.');
$assert(!empty($split['ok']) && ($splitByRoll[601] ?? 0) === 6.0 && ($splitByRoll[602] ?? 0) === 6.0,
    'Custom sticker panels are allocated across two eligible open rolls when one roll cannot hold every panel.');

$stickerField = [
    'type' => 'select',
    'label' => 'Dimensions And Finish',
    'required' => true,
    'visible' => true,
    'unit' => 'ft',
    'options' => [
        ['value' => 'Matte Sticker Printed (1ft X 2ft)', 'price' => 0],
        ['value' => 'Others', 'price' => 0],
    ],
];
$assert(printflow_service_field_uses_custom_size_panel('dimensions', $stickerField),
    'Dimensions And Finish uses the structured custom-size panel.');
$assert(!printflow_service_field_uses_custom_size_panel('finish', [
    'type' => 'select',
    'label' => 'Finish',
    'options' => [['value' => 'Others', 'price' => 0]],
]), 'A finish-only field keeps its own input and does not become a size panel.');
$assert(printflow_service_custom_size_option_label('Others', 'dimensions', $stickerField) === 'Customize Size',
    'Staff see Customize Size while the option value stays Others.');
$assert(printflow_service_field_custom_size_option_value($stickerField) === 'Others',
    'The sticker custom option keeps the Others value for existing stock handling.');
require_once dirname(__DIR__) . '/includes/service_field_renderer.php';
$stickerHtml = render_service_field('dimensions', $stickerField, [], []);
$assert(str_contains($stickerHtml, '>Customize Size</option>')
    && str_contains($stickerHtml, 'value="Others"')
    && !str_contains($stickerHtml, 'Enter custom dimensions and finish')
    && str_contains($stickerHtml, 'Width (ft)')
    && str_contains($stickerHtml, 'Height (ft)')
    && str_contains($stickerHtml, 'name="sticker_custom_finish"')
    && str_contains($stickerHtml, 'Matte Laminate')
    && str_contains($stickerHtml, 'Gloss Laminate'),
    'The sticker selector shows Customize Size and separate width, height, and laminate fields.');
$tarpaulinField = [
    'type' => 'select',
    'label' => 'Dimensions',
    'visible' => true,
    'unit' => 'ft',
    'options' => [['value' => 'Custom Size', 'price' => 0]],
];
$tarpaulinHtml = render_service_field('dimensions', $tarpaulinField, [], []);
$assert(str_contains($tarpaulinHtml, '>Custom Size</option>')
    && !str_contains($tarpaulinHtml, 'Customize Size')
    && !str_contains($tarpaulinHtml, 'sticker_custom_finish')
    && str_contains($tarpaulinHtml, 'Width (ft)')
    && str_contains($tarpaulinHtml, 'Height (ft)'),
    'Tarpaulin still shows Custom Size with width and height and no sticker finish controls.');

$stickerUi = printflow_render_field_custom_size_others_wrap('dimensions', [
    'label' => 'Dimensions And Finish',
    'unit' => 'ft',
    'field_options' => ['Others'],
], true, '', 'wrap', 'select-others-dimensions');
$tarpaulinUi = printflow_render_field_custom_size_others_wrap('dimensions', [
    'label' => 'Dimensions',
    'unit' => 'ft',
    'field_options' => ['Others'],
], true, '', 'wrap', 'select-others-dimensions');
$assert(str_contains($stickerUi, 'name="sticker_custom_finish"')
    && str_contains($stickerUi, 'Matte Laminate')
    && str_contains($stickerUi, 'Gloss Laminate')
    && str_contains($stickerUi, 'name="sticker_custom_process"')
    && str_contains($stickerUi, 'Width')
    && str_contains($stickerUi, 'Height'),
    'The sticker custom-size panel has laminate finish, sticker type, width, and height.');
$assert(!str_contains($tarpaulinUi, 'sticker_custom_finish'),
    'The shared custom-size panel does not add sticker finish controls to Tarpaulin dimensions.');

$assignment = (string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
$jobs = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
$jobApi = (string)file_get_contents(dirname(__DIR__) . '/admin/job_orders_api.php');
$assert(str_contains($source, "'sticker_laminate_selections'")
    && str_contains($cart, 'pos_sticker_laminate_selection')
    && str_contains($pos, 'sticker_laminate_v1')
    && str_contains($pos, 'stickerCustomReady'),
    'POS blocks Add to Order unless the server returns a concrete sticker laminate allocation.');
$assert(str_contains($assignment, 'persistedPosStickerLaminateSelection')
    && str_contains($assignment, 'maybeAutoSelectStickerLaminate')
    && str_contains($assignment, 'manual_quantity_confirmed: quantityConfirmed'),
    'Production Assignment auto-fills the saved laminate footage and treats it as confirmed.');
$rollService = (string)file_get_contents(dirname(__DIR__) . '/includes/RollService.php');
$assert(str_contains($jobApi, 'printflow_pos_sticker_saved_laminate_selection_for_job')
    && str_contains($jobs, 'deductFromValidatedAllocation')
    && str_contains($jobs, '$stickerLaminateDeducted[$itemId] = true')
    && str_contains($rollService, 'printflow_pos_sticker_laminate_roll_width_ft($itemId, $item)'),
    'Completion deducts the saved sticker roll rows once using sticker width resolution.');

$liveFields = [[
    'field_key' => 'dimensions',
    'field_label' => 'Dimensions',
    'field_type' => 'select',
    'unit' => 'ft',
    'is_visible' => 1,
    '_options' => ['Matte Sticker Printed (1ft X 2ft)', 'Others'],
], [
    'field_key' => 'finish',
    'field_label' => 'Finish',
    'field_type' => 'radio',
    'is_visible' => 1,
    '_options' => ['Matte', 'Glossy'],
], [
    'field_key' => 'sticker_type',
    'field_label' => 'Sticker Type',
    'field_type' => 'radio',
    'is_visible' => 1,
    '_options' => ['Cut Out', 'Printed'],
]];
$liveLine = static function (string $finish, string $type, int $qty = 1): array {
    return [
        'qty' => $qty,
        'customization' => [
            'dimensions' => 'Others',
            'width' => '5',
            'height' => '5',
            'dimensions_width' => '5',
            'dimensions_height' => '5',
            'dimensions_unit' => 'ft',
            'finish' => $finish,
            'sticker_type' => $type,
            'branch_id' => '1',
            'quantity' => (string)$qty,
        ],
    ];
};
$screenshot = printflow_pos_sticker_laminate_selection($liveLine('Matte', 'Cut Out'), $liveFields);
$assert($screenshot !== null
    && $screenshot['item_id'] === 21
    && $screenshot['width_ft'] === 5.0
    && $screenshot['height_ft'] === 5.0
    && $screenshot['quantity'] === 1
    && $screenshot['finish_value'] === 'Matte'
    && $screenshot['process_value'] === 'Cut Out',
    'A 5 × 5 ft Matte Cut Out reaches laminate validation with width, height, finish, type, and quantity separate.');
$glossPrinted = printflow_pos_sticker_laminate_selection($liveLine('Glossy', 'Printed', 2), $liveFields);
$customSizeSentinel = $liveLine('Glossy', 'Printed', 1);
$customSizeSentinel['customization']['dimensions'] = 'Custom Size';
$glossFromCustomSizeValue = printflow_pos_sticker_laminate_selection($customSizeSentinel, $liveFields);
$assert($glossFromCustomSizeValue !== null
    && $glossFromCustomSizeValue['item_id'] === 20
    && $glossFromCustomSizeValue['width_ft'] === 5.0
    && $glossFromCustomSizeValue['height_ft'] === 5.0
    && $glossFromCustomSizeValue['finish_value'] === 'Glossy'
    && $glossFromCustomSizeValue['process_value'] === 'Printed',
    'Custom Size as the dimensions sentinel reaches laminate validation instead of the unconfigured-option error.');
$stalePresetLine = [
    'qty' => 1,
    'customization' => ['dimensions' => 'Retired Glossy Sticker Printed (99ft X 99ft)', 'branch_id' => '1'],
];
$staleThrown = false;
try {
    printflow_pos_sticker_laminate_selection($stalePresetLine, [$liveFields[0]]);
} catch (RuntimeException $error) {
    $staleThrown = str_contains($error->getMessage(), 'no longer configured');
}
$assert($staleThrown, 'A genuinely stale fixed-size preset still fails configuration validation.');
$fixedPresetLine = [
    'qty' => 1,
    'customization' => ['dimensions' => 'Matte Sticker Printed (1ft X 2ft)', 'branch_id' => '1'],
];
$fixedPreset = printflow_pos_sticker_laminate_selection($fixedPresetLine, [$liveFields[0]]);
$assert($fixedPreset !== null && $fixedPreset['item_id'] === 21 && $fixedPreset['width_ft'] === 1.0 && $fixedPreset['height_ft'] === 2.0,
    'Configured fixed-size sticker options still resolve for laminate validation.');
$assert($glossPrinted !== null && $glossPrinted['item_id'] === 20 && $glossPrinted['quantity'] === 2
    && $glossPrinted['finish_value'] === 'Glossy' && $glossPrinted['process_value'] === 'Printed',
    'Glossy Printed uses Gloss Laminate and keeps the sticker type separate.');
$missingFinish = $liveLine('Matte', 'Cut Out');
unset($missingFinish['customization']['finish']);
$assert(printflow_pos_sticker_laminate_selection($missingFinish, $liveFields) === null,
    'Custom size without a laminate finish stays incomplete.');
$missingType = $liveLine('Matte', 'Cut Out');
unset($missingType['customization']['sticker_type']);
$assert(printflow_pos_sticker_laminate_selection($missingType, $liveFields) === null,
    'Custom size without a sticker type stays incomplete.');
$missingSize = $liveLine('Matte', 'Cut Out');
$missingSize['customization']['width'] = '';
$missingSize['customization']['dimensions_width'] = '';
$assert(printflow_pos_sticker_laminate_selection($missingSize, $liveFields) === null,
    'Custom size without a width stays incomplete.');
$posSource = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
$assert(str_contains($posSource, "stickerSpec(['sticker_custom_finish', 'finish', 'Finish'])")
    && str_contains($posSource, "stickerSpec(['sticker_custom_process', 'sticker_type', 'Sticker Type'])")
    && str_contains($posSource, 'serviceCustomization.dimensions_width')
    && str_contains($posSource, "'dimensions_unit'"),
    'POS stock validation reads the live finish, sticker type, width, height, and unit fields.');
$overwrittenPayload = [
    'qty' => 1,
    'customization' => [
        'dimensions' => '5x5',
        'dimensions_width' => '5',
        'dimensions_height' => '5',
        'dimensions_unit' => 'ft',
        'finish' => 'Matte',
        'sticker_type' => 'Printed',
        'branch_id' => '1',
        'quantity' => '1',
    ],
];
$assert(printflow_pos_sticker_custom_size_active($overwrittenPayload['customization'], $liveFields),
    'Separated finish/type with WxH dimensions value is treated as custom size, not a stale preset.');
$assert(printflow_pos_sticker_laminate_selection($overwrittenPayload, $liveFields)['item_id'] === 21,
    'Add-to-order payload with dimensions=5x5 still maps Matte Printed to item 21.');
$separatedOnly = [
    'qty' => 2,
    'customization' => [
        'dimensions_width' => '3',
        'dimensions_height' => '4',
        'dimensions_unit' => 'ft',
        'finish' => 'Glossy',
        'sticker_type' => 'Cut Out',
        'quantity' => '2',
    ],
];
$glossSeparated = printflow_pos_sticker_laminate_selection($separatedOnly, $liveFields);
$assert($glossSeparated !== null && $glossSeparated['item_id'] === 20 && $glossSeparated['quantity'] === 2,
    'Width/height without a dimensions sentinel still validates Glossy Cut Out.');
$assert(str_contains($posSource, 'preserveCustomSizeChoice') && str_contains($posSource, 'Number(serviceId) === 36'),
    'Add to Order keeps Others/Customize Size on dimensions instead of overwriting with WxH.');
$assert(str_contains($posSource, 'Number(serviceId) === 36 && issue.message')
    && !str_contains($posSource, 'issue.inventory_discrepancy && issue.message'),
    'Sticker laminate stock warnings show the server message, not a generic 0 ft shortage.');

$qtyFromCustomization = $liveLine('Matte', 'Printed', 1);
unset($qtyFromCustomization['qty']);
$assert(printflow_pos_sticker_laminate_selection($qtyFromCustomization, $liveFields)['quantity'] === 1,
    'Custom sticker quantity is read from customization when the cart line qty is absent.');
$fiveByFiveMatte = printflow_pos_sticker_laminate_selection($liveLine('Matte', 'Printed'), $liveFields);
$wideMatteRoll = printflow_pos_sticker_laminate_roll_width_ft(21, [
    'id' => 21, 'name' => 'Matte Laminate', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft',
    'track_by_roll' => 1, 'material_width' => 60, 'material_dimension_unit' => 'in',
]);
$fiveByFivePlan = printflow_pos_sticker_laminate_panel_plan($fiveByFiveMatte, $wideMatteRoll);
$assert($fiveByFivePlan['calculation']['required_linear_ft'] === 5.0
    && $fiveByFivePlan['finished_quantity'] === 1,
    'Custom 5 × 5 ft, quantity 1 requires 5 linear ft on a wide enough matte roll.');
$narrowMattePlanFailed = false;
try {
    printflow_pos_sticker_laminate_panel_plan($fiveByFiveMatte, 1.0);
} catch (InvalidArgumentException) {
    $narrowMattePlanFailed = true;
}
$assert($narrowMattePlanFailed,
    'A 5 × 5 ft sticker is rejected before allocation when neither dimension fits a 1 ft roll.');
$fitMessage = printflow_pos_sticker_laminate_size_does_not_fit_message($fiveByFiveMatte, 2.0, 'Matte Laminate');
$assert(str_contains($fitMessage, '5 × 5 ft') && str_contains($fitMessage, 'at least 5 ft wide'),
    'Roll-width fit errors explain the sticker size and minimum roll width instead of 0 ft required.');
$matteFivePlan = printflow_pos_sticker_laminate_panel_plan($fiveByFiveMatte, $wideMatteRoll);
$matteFiftyRolls = printflow_pos_sticker_laminate_filter_rolls_by_width([[
    'id' => 900, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 5,
    'remaining_length_ft' => 50, 'received_at' => '2026-01-01',
]], $wideMatteRoll);
$matteFiveAllocation = printflow_tarpaulin_allocate_cart_panels(
    ['matte-5x5' => ['item_id' => 21, 'plan' => $matteFivePlan]],
    [21 => $matteFiftyRolls]
);
$assert(!empty($matteFiveAllocation['ok'])
    && ($matteFiveAllocation['required_by_item'][21] ?? 0) === 5.0
    && printflow_pos_sticker_laminate_roll_balance_ft($matteFiftyRolls) === 50.0,
    'Fifty feet of eligible matte rolls allocate a 5 ft run for one 5 × 5 ft piece.');
$glossFive = printflow_pos_sticker_laminate_selection($liveLine('Glossy', 'Printed'), $liveFields);
$glossWide = printflow_pos_sticker_laminate_roll_width_ft(20, [
    'id' => 20, 'name' => 'Gloss Laminate', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft',
    'track_by_roll' => 1, 'material_width' => 60, 'material_dimension_unit' => 'in',
]);
$glossFivePlan = printflow_pos_sticker_laminate_panel_plan($glossFive, $glossWide);
$glossFiftyAllocation = printflow_tarpaulin_allocate_cart_panels(
    ['gloss-5x5' => ['item_id' => 20, 'plan' => $glossFivePlan]],
    [20 => [[
        'id' => 901, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 5,
        'remaining_length_ft' => 50, 'received_at' => '2026-01-01',
    ]]]
);
$assert(!empty($glossFiftyAllocation['ok'])
    && ($glossFiftyAllocation['required_by_item'][20] ?? 0) === 5.0,
    'Gloss laminate uses the same positive footage demand and 50 ft roll eligibility.');

$separatedPresetFields = [[
    'field_key' => 'dimensions',
    'field_label' => 'Dimensions',
    'field_type' => 'select',
    'unit' => 'ft',
    'is_visible' => 1,
    '_options' => ['2ft X 2ft', '1ft X 1ft', 'Others'],
], $liveFields[1], $liveFields[2]];
$twoByTwoMattePrinted = [
    'qty' => 1,
    'customization' => [
        'dimensions' => '2ft X 2ft',
        'finish' => 'Matte',
        'sticker_type' => 'Printed',
        'branch_id' => '1',
    ],
];
$twoByTwo = printflow_pos_sticker_laminate_selection($twoByTwoMattePrinted, $separatedPresetFields);
$assert($twoByTwo !== null
    && $twoByTwo['item_id'] === 21
    && $twoByTwo['width_ft'] === 2.0
    && $twoByTwo['height_ft'] === 2.0
    && ($twoByTwo['configured_preset'] ?? false) === true
    && $twoByTwo['finish_value'] === 'Matte'
    && $twoByTwo['process_value'] === 'Printed',
    'A configured 2ft X 2ft preset with separate Matte Printed maps to Matte Laminate item 21.');
$glossCutTwoByTwo = printflow_pos_sticker_laminate_selection([
    'qty' => 2,
    'customization' => [
        'dimensions' => '2ft X 2ft',
        'finish' => 'Glossy',
        'sticker_type' => 'Cut Out',
        'branch_id' => '1',
    ],
], $separatedPresetFields);
$assert($glossCutTwoByTwo !== null && $glossCutTwoByTwo['item_id'] === 20 && $glossCutTwoByTwo['quantity'] === 2,
    'Glossy Cut Out on the same preset size uses Gloss Laminate at the requested quantity.');
$twoByTwoPlan = printflow_pos_sticker_laminate_panel_plan($twoByTwo, $wideMatteRoll);
$twoByTwoRolls = printflow_pos_sticker_laminate_filter_rolls_by_width([[
    'id' => 910, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 5,
    'remaining_length_ft' => 50, 'received_at' => '2026-01-01',
]], $wideMatteRoll);
$twoByTwoAlloc = printflow_tarpaulin_allocate_cart_panels(
    ['two-by-two' => ['item_id' => 21, 'plan' => $twoByTwoPlan]],
    [21 => $twoByTwoRolls]
);
$assert(!empty($twoByTwoAlloc['ok']) && ($twoByTwoAlloc['required_by_item'][21] ?? 0) === 2.0,
    'One 2 × 2 ft matte sticker requires 2 linear ft and allocates on eligible rolls.');
$twoByTwoShortAlloc = printflow_tarpaulin_allocate_cart_panels(
    ['two-by-two' => ['item_id' => 21, 'plan' => $twoByTwoPlan]],
    [21 => [[
        'id' => 911, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 5,
        'remaining_length_ft' => 1, 'received_at' => '2026-01-01',
    ]]]
);
$assert(empty($twoByTwoShortAlloc['ok']),
    'A true laminate shortage still blocks allocation for preset dimensions.');
$narrowTwoByTwoFailed = false;
try {
    printflow_pos_sticker_laminate_panel_plan($twoByTwo, 1.0);
} catch (InvalidArgumentException) {
    $narrowTwoByTwoFailed = true;
}
$assert($narrowTwoByTwoFailed,
    '2 × 2 ft on a 1 ft roll width is rejected before allocation.');
$assert(printflow_pos_sticker_laminate_selection([
    'qty' => 1,
    'customization' => ['dimensions' => '2ft X 2ft', 'branch_id' => '1'],
], $separatedPresetFields) === null,
    'Preset size without finish and sticker type stays incomplete.');
$posSource = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
$assert(str_contains($posSource, 'stickerSeparatedPresetReady'),
    'POS stock pre-check runs for configured dimension presets with separate finish/type.');

echo "POS sticker laminate early-stock checks passed.\n";

