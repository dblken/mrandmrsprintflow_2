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
    'Glossy Sticker Cut Out (2ft X 2ft)',
    'Matte Sticker Printed (1ft X 2ft)',
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
$assert(printflow_pos_sticker_laminate_item_for_finish('Matte Sticker Cut Out (1ft X 2ft)') === null,
    'Unspecified matte cut-out behavior does not get an invented laminate mapping.');

$gloss = printflow_pos_sticker_laminate_selection($lineFor($options[0]), $config);
$matte = printflow_pos_sticker_laminate_selection($lineFor($options[2], 3), $config);
$cutout = printflow_pos_sticker_laminate_selection($lineFor($options[1], 2), $config);
$assert($gloss !== null && $gloss['item_id'] === 20 && $gloss['width_ft'] === 1.0 && $gloss['height_ft'] === 2.0,
    'Gloss finish selection resolves configured physical dimensions into feet.');
$assert($matte !== null && $matte['item_id'] === 21 && $matte['quantity'] === 3,
    'Matte selection preserves the requested order quantity.');
$assert($cutout !== null && $cutout['item_id'] === 20,
    'Glossy cut-out selects only Gloss Laminate.');
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

$branch1 = [
    ['id' => 1, 'branch_id' => 1, 'roll_code' => 'G1', 'remaining_length_ft' => 3, 'received_at' => '2026-01-01'],
    ['id' => 2, 'branch_id' => 2, 'roll_code' => 'OTHER', 'remaining_length_ft' => 100, 'received_at' => '2026-01-01'],
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
    [21 => [['id' => 3, 'branch_id' => 1, 'roll_code' => 'M1', 'remaining_length_ft' => 2, 'received_at' => '2026-01-01']]]
);
$assert(empty($insufficient['ok']) && ($insufficient['reason'] ?? '') === 'physical_roll_capacity',
    'Insufficient continuous roll length blocks demand even when nominal dimensions fit.');

$source = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$cart = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$pos = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
$assert(str_contains($source, 'if ($serviceId === 36)')
    && str_contains($source, 'printflow_pos_sticker_laminate_selection')
    && str_contains($source, 'printflow_tarpaulin_allocate_cart_panels'),
    'Shared backend service-stock validation uses the mapped laminate and physical roll allocator.');
$assert(str_contains($cart, "case 'validate_service_stock':")
    && str_contains($cart, 'if (in_array($action, [\'add\', \'update\', \'update_service_link\', \'validate_stock\'], true))')
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'The endpoint revalidates availability for modal checks and again before accepting a cart add.');
$assert(str_contains($source, 'printflow_pos_tarpaulin_open_rolls($itemId, $branchId)')
    && str_contains($source, 'printflow_pos_sticker_laminate_filter_branch_rolls'),
    'Production roll reads are branch-scoped and defensively filtered before allocation.');
$assert(str_contains($pos, 'const stickerLaminateReady = Number(serviceId) === 36')
    && str_contains($pos, '&& !stickerLaminateReady')
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

