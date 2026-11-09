<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/inventory_roll_width_audit.php';
require_once dirname(__DIR__) . '/includes/roll_width.php';
require_once dirname(__DIR__) . '/includes/pos_sticker_laminate_early_stock.php';
require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sixFtItem = [
    'id' => 21,
    'name' => 'Matte Laminate',
    'track_by_roll' => 1,
    'material_width' => 6,
    'material_dimension_unit' => 'ft',
];
$assert(abs(printflow_inventory_item_configured_width_ft($sixFtItem) - 6.0) < 0.000001,
    'Configured 6 ft material_width persists as 6 ft for matching logic.');

$openRolls = [[
    'id' => 158,
    'branch_id' => 1,
    'status' => 'OPEN',
    'width_ft' => 2,
    'remaining_length_ft' => 50,
], [
    'id' => 159,
    'branch_id' => 1,
    'status' => 'OPEN',
    'width_ft' => 6,
    'remaining_length_ft' => 40,
]];
$mismatches = printflow_pos_sticker_laminate_roll_width_mismatches($openRolls, 6.0);
$assert(count($mismatches) === 1 && $mismatches[0]['id'] === 158,
    'Only the 2 ft open roll is flagged; the 6 ft roll is not rewritten automatically.');
$eligible = printflow_pos_sticker_laminate_filter_rolls_by_width($openRolls, 6.0);
$assert(count($eligible) === 1 && $eligible[0]['id'] === 159,
    'POS eligibility uses per-roll width matching the configured 6 ft item.');
$correctedRolls = [[
    'id' => 158,
    'branch_id' => 1,
    'status' => 'OPEN',
    'width_ft' => 6,
    'remaining_length_ft' => 50,
]];
$selection = [
    'width_ft' => 5.0,
    'height_ft' => 5.0,
    'quantity' => 1,
];
$plan = printflow_pos_sticker_laminate_panel_plan($selection, 6.0);
$allocation = printflow_tarpaulin_allocate_cart_panels(
    ['matte-5x5' => ['item_id' => 21, 'plan' => $plan]],
    [21 => $correctedRolls]
);
$assert(!empty($allocation['ok']) && ($allocation['required_by_item'][21] ?? 0) === 5.0,
    'After verified width correction to 6 ft, POS allocates 5 ft for one 5 × 5 sticker.');

$note = printflow_inventory_roll_width_mismatch_admin_note('Matte Laminate', 6.0, $mismatches);
$assert(str_contains($note, 'roll #158') && str_contains($note, 'CORRECT WIDTH'),
    'Post-save Admin note explains that material settings alone do not rewrite open rolls.');

$default = printflow_roll_width_default($sixFtItem);
$assert($default === ['value' => '6', 'unit' => 'ft'],
    'New Receive IN defaults use the saved 6 ft configured width.');

$itemsApi = (string)file_get_contents(dirname(__DIR__) . '/admin/inventory_items_api.php');
$itemsPage = (string)file_get_contents(dirname(__DIR__) . '/admin/inv_items_management.php');
$assert(str_contains($itemsApi, 'roll_width_mismatches')
    && str_contains($itemsApi, 'inventory_roll_width_audit.php')
    && str_contains($itemsPage, 'roll_width_note'),
    'Item save returns mismatch metadata and the Admin UI prompts Roll Tracking correction.');

echo "Inventory material roll-width mismatch tests passed.\n";
