<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$fails = static function (callable $fn, string $message): void { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException($message); };
$assert(!in_array(12, printflow_pos_material_selection_allowed_ids(39), true), 'SPHONE is excluded from Plates.');
$fails(static fn() => printflow_pos_material_selection_resolve(39, []), 'A Plate must select one alternative.');
$fails(static fn() => printflow_pos_material_selection_resolve(39, [1, 2]), 'Two Plate alternatives cannot be selected together.');
$assert(count(printflow_pos_material_selection_resolve(39, [1])) === 1, 'One Plate alternative is accepted.');
$assert(count(printflow_pos_material_selection_resolve(67, [212])) === 1, 'One Brochure paper is accepted.');
$assert(count(printflow_pos_material_selection_resolve(64, [211])) === 1, 'One Poster paper is accepted.');
$assert(count(printflow_pos_material_selection_resolve(71, [207])) === 1, 'One Sintra thickness is accepted.');
$assert(count(printflow_pos_material_selection_resolve(71, [206, 23])) === 2, 'Optional printed sticker material can accompany one Sintra thickness.');
$assert(count(printflow_pos_material_selection_resolve(71, [206])) === 1, 'Optional printed sticker material is not forced.');
$fails(static fn() => printflow_pos_material_selection_resolve(71, [206, 207]), 'Both Sintra thicknesses cannot be selected.');
$assert(count(printflow_pos_material_selection_resolve(36, [22, 20, 21])) === 3, 'Hologram base with multiple selected finishes is accepted.');
$assert(count(printflow_pos_material_selection_resolve(36, [18])) === 1, 'Nextjet can be selected without a finish.');
$assert(count(printflow_pos_material_selection_resolve(36, [])) === 0, 'Nextjet remains optional and is not forced.');
$fails(static fn() => printflow_pos_material_selection_resolve(36, [18, 22]), 'Only one decal base may be selected.');
$fails(static fn() => printflow_pos_material_selection_resolve(36, [999]), 'A material outside the explicit service mapping is rejected.');
$demand = printflow_pos_material_demand_for_area(2, 3, 'ft', 2, 0.2);
$assert(abs($demand['area_sqft'] - 6.0) < 1e-9 && abs($demand['raw_demand'] - 2.4) < 1e-9 && $demand['rounded_demand'] === 3, 'Fractional area demand is rounded up to whole inventory units.');
$inches = printflow_pos_material_demand_for_area(24, 36, 'in', 1, 1);
$assert(abs($inches['area_sqft'] - 6.0) < 1e-9 && $inches['rounded_demand'] === 6, 'Inch dimensions normalize to square feet before demand calculation.');
$fails(static fn() => printflow_pos_material_demand_for_area(0, 2, 'ft', 1, 1), 'Zero dimensions must fail.');
$fails(static fn() => printflow_pos_material_demand_for_area(2, 2, 'unsupported', 1, 1), 'Unsupported dimension units must fail.');
$selectedDemand = printflow_pos_material_demand_for_area(2, 3, 'ft', 2, 0.2);
$saved = [[
    'rule_id' => 16, 'item_id' => 18, 'material_name' => 'Nextjet', 'selection_role' => 'alternative', 'selection_group' => 'decal_base',
    'uom' => 'pcs', 'dimensions' => ['width' => 2, 'height' => 3, 'unit' => 'ft'],
    'selection_context' => ['size' => 'Custom', 'layout' => 'without_layout', 'thickness' => '3mm'], 'order_quantity' => 2,
    'multiplier' => 0.2, 'multiplier_basis' => 'inventory_units_per_sqft', 'area_sqft' => $selectedDemand['area_sqft'],
    'calculated_demand' => $selectedDemand['raw_demand'], 'rounded_demand' => $selectedDemand['rounded_demand'],
    'multiplier_approved_by' => 22, 'multiplier_approved_at' => '2026-11-03 12:00:00', 'multiplier_approved_basis' => 'AREA_SQFT',
]];
$verifiedSaved = printflow_pos_material_selection_validate_saved_consumption(36, $saved);
$assert(count($verifiedSaved) === 1, 'A server-authored approved selected-material calculation validates for deferred job assignment.');
$assert($verifiedSaved[0]['selection_context'] === ['size' => 'Custom', 'layout' => 'without_layout', 'thickness' => '3mm'], 'Selected size, layout, and thickness context remains attached to the saved consumption snapshot.');
$fails(static function () use ($saved): void {
    $tampered = $saved;
    $tampered[0]['rounded_demand'] = 1;
    printflow_pos_material_selection_validate_saved_consumption(36, $tampered);
}, 'A saved rounded demand that differs from the dimension/rate calculation is rejected.');
$fails(static function () use ($saved): void {
    $tampered = $saved;
    $tampered[0]['multiplier_approved_by'] = 0;
    printflow_pos_material_selection_validate_saved_consumption(36, $tampered);
}, 'A saved selected-material assignment without Admin audit is rejected.');
$fails(static function () use ($saved): void {
    $tampered = $saved;
    $tampered[0]['item_id'] = 19;
    printflow_pos_material_selection_validate_saved_consumption(36, $tampered);
}, 'A selected material outside the exact service identity map is rejected.');
$cartSource = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$checkoutSource = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
$jobServiceSource = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
$assert(str_contains($cartSource, 'if (isset(printflow_pos_material_selection_registry()[$selectionServiceId])) return;'), 'Legacy pending-job stock checks cannot reintroduce unselected materials for selection-policy services.');
$assert(str_contains($checkoutSource, 'unset($customization[\'pos_material_consumption\'])')
    && str_contains($checkoutSource, "'pos_material_consumption'] = array_values((array)\$consumptionRows)"), 'Checkout discards client calculation data and persists the server-validated consumption snapshot.');
$assert(str_contains($jobServiceSource, 'self::syncPosSelectedMaterialsForStoreOrder($orderId)')
    && str_contains($jobServiceSource, "'source' => 'pos_explicit_material_selection'"), 'Pre-existing linked jobs receive idempotent assignment rows without changing their deduction timing.');
echo "POS material selection contract tests passed.\n";
