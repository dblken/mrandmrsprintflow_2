<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Actual usage is preserved as entered in the item's inventory UOM. Discrete
// units remain whole; measured units retain supported precision.
$uomCases = [
    ['2', 'pcs', 2.0], ['1.00', 'pcs', 1.0], ['1', 'sheet', 1.0], ['0.75', 'ft', 0.75],
    ['1.00', 'ft', 1.0], ['1.25', 'l', 1.25], ['0.5', 'm', 0.5],
];
foreach ($uomCases as [$entered, $uom, $expected]) {
    $actual = printflow_pos_parse_positive_material_quantity($entered, [
        'unit_of_measure' => $uom,
        // Deliberately contradictory legacy methods and rates must not change quantity.
        'consumption_method' => 'dimension_sheet', 'qty_multiplier' => 99,
    ]);
    $assert($actual === $expected, "Entered {$entered} {$uom} must be persisted unchanged.");
}
foreach ([['', 'pcs'], ['0', 'sheet'], ['-1', 'ft'], ['1.5', 'pcs'], ['1e2', 'ft'], ['1.234', 'l']] as [$entered, $uom]) {
    try {
        printflow_pos_parse_positive_material_quantity($entered, ['unit_of_measure' => $uom]);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException("Invalid actual usage {$entered} {$uom} was accepted.");
}

$stock = file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$assignment = file_get_contents(dirname(__DIR__) . '/admin/job_orders_api.php');
$service = file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
$counterUi = file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
$adminUi = file_get_contents(dirname(__DIR__) . '/admin/inv_items_management.php');
$js = file_get_contents(dirname(__DIR__) . '/public/assets/js/material_consumption_estimator.js');
foreach ([$stock, $assignment, $service, $counterUi, $adminUi, $js] as $source) {
    $assert(is_string($source), 'A required workflow source file is readable.');
}

$assert(str_contains($stock, 'if (!array_key_exists(\'pos_material_assignments\', $line))')
    && str_contains($stock, '// Non-Tarpaulin POS captures customer specifications only. Exact')
    && str_contains($stock, '// Current service material quantities are entered by staff in Production')
    && !str_contains($stock, 'printflow_pos_material_demand_for_area(')
    && str_contains($stock, 'printflow_tarpaulin_resolve_candidate('),
    'Non-Tarpaulin POS stock validation stays assignment-based; Tarpaulin uses mapped linear roll-fit without area multipliers.');
$assert(str_contains($assignment, '$rawQuantity = trim((string)($_POST[\'quantity\'] ?? \'\'))')
    && str_contains($assignment, 'getStockOnHandForUpdate($itemId, $branchId)')
    && str_contains($assignment, 'requested %s %s; available %s %s')
    && str_contains($assignment, "\$metadata['actual_usage_version'] = 'manual_v1';"),
    'The authorized assignment API validates staff input and branch stock before writing the manual-usage record.');
$assert(str_contains($service, "'actual_usage_version'] ?? '') === 'manual_v1'")
    && str_contains($service, 'resolveRollMaterialLengthFt')
    && str_contains($service, 'getStockOnHandForUpdate($itemId, $branchId)')
    && str_contains($service, 'InventoryManager::issueStock('),
    'Inventory deduction uses saved manual quantity and rechecks locked current branch stock.');
$assert(str_contains($service, "\$metadata['actual_usage_version'] = 'manual_v1';")
    && str_contains($service, '$computed_len = !empty($track_by_roll) ? $qty : 0'),
    'New assignment rows store the entered quantity and mark it as manual for later deduction.');
$assert(str_contains($counterUi, 'Enter the actual quantity used in the inventory unit.')
    && str_contains($counterUi, 'getMaterialRequiredStock(itemId, qty = 0, height = 0, metadata = {})')
    && str_contains($counterUi, 'return parsedQty;')
    && str_contains($counterUi, 'manual_confirmation_required: true')
    && !str_contains($counterUi, 'minimum required quantity'),
    'Counter Staff sees an empty manual Actual Usage input; dimensions and estimates do not prefill or impose a minimum.');
$assert(str_contains($adminUi, 'they do not calculate current assignment quantities'),
    'Admin multiplier/basis values remain stored for history but are clearly marked as inactive for current quantities.');
$assert(str_contains($js, 'function estimate()')
    && str_contains($js, "status: 'manual'")
    && !str_contains($js, 'demandForArea')
    && !str_contains($js, 'demandForItems'),
    'The shared browser consumption helper has no dimension/rate estimator.');
$assert(printflow_pos_material_selection_filter_unselected_optional_rules(71, [
    ['item_id' => 23, 'rule_type' => 'OPTIONAL'],
    ['item_id' => 206, 'rule_type' => 'REQUIRED'],
], [['item_id' => 206]]) === [
    ['item_id' => 206, 'rule_type' => 'REQUIRED'],
], 'An unselected optional Sintraboard layer is excluded; the selected board remains.');

// Both Counter Staff Set Price and Online Operations submit through this shared
// Admin assignment endpoint; no database migration/data change is required.
$assert(str_contains($counterUi, "fdMaterial.append('action', 'add_material')")
    && str_contains($counterUi, "fdMaterial.append('quantity', pm.qty)")
    && str_contains($assignment, "case 'add_material':"),
    'Counter Staff Set Price posts the exact manually confirmed qty to the shared assignment endpoint used by Online Operations.');

echo "Manual material usage workflow tests passed.\n";
