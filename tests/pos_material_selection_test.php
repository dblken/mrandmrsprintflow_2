<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$fails = static function (callable $fn, string $message): void { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException($message); };
$assert(!in_array(12, printflow_pos_material_selection_allowed_ids(39), true), 'SPHONE is excluded from Plates.');
$fails(static fn() => printflow_pos_material_selection_resolve(39, []), 'A Plate must select one alternative.');
$fails(static fn() => printflow_pos_material_selection_resolve(39, [1, 2]), 'Two Plate alternatives cannot be selected together.');
$assert(count(printflow_pos_material_selection_resolve(39, [1])) === 1, 'One Plate alternative is accepted.');
$assert(printflow_pos_material_selection_consumption_basis(39) === 'PER_ITEM', 'Plates use the dedicated per-item consumption basis.');
$assert(!printflow_pos_material_selection_requires_dimensions(39), 'standard Plates do not require physical dimensions in POS.');
$assert(printflow_pos_material_selection_consumption_basis(67) === 'AREA_SQFT'
    && printflow_pos_material_selection_requires_dimensions(67), 'Brochure remains area-based and still requires dimensions.');
$assert(printflow_pos_material_selection_consumption_basis(71) === 'AREA_SQFT'
    && printflow_pos_material_selection_consumption_basis(36) === 'AREA_SQFT', 'Sintraboard and Stickers/Decals retain their area basis.');
$consumptionMigration = (string)file_get_contents(dirname(__DIR__) . '/database/migrate_pos_material_consumption_basis_20261103.php');
$assert(strlen('INVENTORY_UNITS_PER_ITEM') === 24 && str_contains($consumptionMigration, "'qty_multiplier_basis' => ['varchar(24)'")
    && str_contains($consumptionMigration, "'qty_multiplier_approved_basis' => ['varchar(24)'"),
    'The existing 24-character basis columns safely retain the complete Plates per-item rate label and approved basis.');
$plateDemand = printflow_pos_material_demand_for_items(3, 1.25);
$assert(abs($plateDemand['raw_demand'] - 3.75) < 1e-9 && $plateDemand['rounded_demand'] === 4,
    'Plate demand uses only quantity times the configured per-item multiplier and rounds fractional inventory demand up.');
$fails(static fn() => printflow_pos_material_demand_for_items(0, 1), 'A zero Plate order quantity fails closed.');
$fails(static fn() => printflow_pos_material_demand_for_items(3, 0), 'An unconfigured or zero Plate multiplier fails closed.');
$fails(static fn() => printflow_pos_material_demand_for_items(3, 'NAN'), 'A non-finite Plate multiplier fails closed.');
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
$plateSnapshotDemand = printflow_pos_material_demand_for_items(3, 1.25);
$plateSnapshot = [[
    'rule_id' => 42, 'item_id' => 1, 'material_name' => 'AC MC', 'selection_role' => 'alternative', 'selection_group' => 'plate_media',
    'uom' => 'pcs', 'dimensions' => [], 'selection_context' => ['type_of_plates' => 'Motor Cycle Plate'],
    'order_quantity' => 3, 'multiplier' => 1.25, 'multiplier_basis' => 'INVENTORY_UNITS_PER_ITEM', 'area_sqft' => null,
    'calculated_demand' => $plateSnapshotDemand['raw_demand'], 'rounded_demand' => $plateSnapshotDemand['rounded_demand'],
    'multiplier_approved_by' => 22, 'multiplier_approved_at' => '2026-11-03 12:00:00', 'multiplier_approved_basis' => 'PER_ITEM',
]];
$validatedPlateSnapshot = printflow_pos_material_selection_validate_saved_consumption(39, $plateSnapshot);
$assert(count($validatedPlateSnapshot) === 1 && $validatedPlateSnapshot[0]['rounded_demand'] === 4,
    'A selected standard Plate saves a validated quantity-based consumption snapshot without dimensions.');
$fails(static function () use ($plateSnapshot): void {
    $bad = $plateSnapshot;
    $bad[0]['dimensions'] = ['width' => 1, 'height' => 1, 'unit' => 'ft'];
    printflow_pos_material_selection_validate_saved_consumption(39, $bad);
}, 'Plate snapshots cannot silently include guessed area dimensions.');
$fails(static function () use ($plateSnapshot): void {
    $bad = $plateSnapshot;
    $bad[0]['multiplier_approved_basis'] = 'AREA_SQFT';
    printflow_pos_material_selection_validate_saved_consumption(39, $bad);
}, 'A Plate snapshot approved under an area basis is rejected.');
$cartSource = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$checkoutSource = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
$jobServiceSource = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
$assert(str_contains($cartSource, 'if (isset(printflow_pos_material_selection_registry()[$selectionServiceId])) return;'), 'Legacy pending-job stock checks cannot reintroduce unselected materials for selection-policy services.');
$assert(str_contains($checkoutSource, 'unset($customization[\'pos_material_consumption\'])')
    && str_contains($checkoutSource, "'pos_material_consumption'] = array_values((array)\$consumptionRows)"), 'Checkout discards client calculation data and persists the server-validated consumption snapshot.');
$assert(str_contains($jobServiceSource, 'self::syncPosSelectedMaterialsForStoreOrder($orderId)')
    && str_contains($jobServiceSource, "'source' => 'pos_explicit_material_selection'"), 'Pre-existing linked jobs receive idempotent assignment rows without changing their deduction timing.');
echo "POS material selection contract tests passed.\n";
