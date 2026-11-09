<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/pos_sticker_laminate_early_stock.php';
require_once $root . '/includes/tarpaulin_material_calculation.php';

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        $failures++;
    }
};

$matteItem = [
    'id' => 21,
    'name' => 'Matte Laminate',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'ft',
    'material_width' => 6,
    'material_dimension_unit' => 'ft',
    'track_by_roll' => 1,
    'category_name' => 'PRINTED STKR',
];
$tarpaulinParsedWidth = printflow_tarpaulin_parse_nominal_width($matteItem);
$stickerConfiguredWidth = printflow_pos_sticker_laminate_roll_width_ft(21, $matteItem);
$assert($tarpaulinParsedWidth <= 0.000001 && abs($stickerConfiguredWidth - 6.0) < 0.000001,
    'Matte Laminate resolves configured width through the sticker helper, not the Tarpaulin parser.');

$rollService = (string)file_get_contents($root . '/includes/RollService.php');
$jobService = (string)file_get_contents($root . '/includes/JobOrderService.php');
$assert(str_contains($rollService, 'printflow_pos_sticker_laminate_is_mapped_inventory_item($itemId)')
    && str_contains($rollService, 'if ($isStickerLaminate) {')
    && str_contains($rollService, 'printflow_pos_sticker_laminate_roll_width_ft($itemId, $item)')
    && str_contains($rollService, 'printflow_pos_sticker_laminate_roll_width_mismatches($rolls, $configuredWidthFt)')
    && str_contains($rollService, 'printflow_tarpaulin_parse_nominal_width($item)'),
    'Completion deduction branches sticker laminate width off the Tarpaulin parser and checks physical roll width.');
$assert(str_contains($jobService, 'savedStickerLaminate')
    && str_contains($jobService, '$stickerLaminateDeducted[$itemId] = true')
    && str_contains($jobService, 'printflow_tarpaulin_saved_roll_fit_selection_for_job'),
    'Sticker completion stays on the saved laminate plan while Tarpaulin keeps its own saved plan path.');

$plannedRolls = [[
    'id' => 158,
    'width_ft' => 2.0,
    'remaining_length_ft' => 50.0,
    'status' => 'OPEN',
]];
$mismatches = printflow_pos_sticker_laminate_roll_width_mismatches($plannedRolls, 6.0);
$assert(count($mismatches) === 1 && (int)($mismatches[0]['id'] ?? 0) === 158,
    'A 2 ft open roll conflicts with a 6 ft configured laminate without implying Tarpaulin stock logic.');

$validRolls = [[
    'id' => 158,
    'width_ft' => 6.0,
    'remaining_length_ft' => 50.0,
    'status' => 'OPEN',
]];
$assert(printflow_pos_sticker_laminate_roll_width_mismatches($validRolls, 6.0) === [],
    'A roll width that matches the item profile is eligible for sticker completion deduction.');

$tarpItem = [
    'id' => 99,
    'name' => 'Tarpaulin 10 FT',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'ft',
    'material_width' => '',
    'category_name' => 'TARPAULIN',
    'track_by_roll' => 1,
];
$assert(!printflow_pos_sticker_laminate_is_mapped_inventory_item(99)
    && printflow_tarpaulin_parse_nominal_width($tarpItem) > 0,
    'Tarpaulin inventory items are not treated as sticker laminate SKUs.');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "sticker_laminate_completion_deduct_test: OK\n");
