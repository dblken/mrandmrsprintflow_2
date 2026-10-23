<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (InvalidArgumentException $error) { return; }
    $assert(false, $message);
};
$presetPair = printflow_tarpaulin_normalize_dimensions(['dimensions' => '2x3 Ft']);
$customPair = printflow_tarpaulin_normalize_dimensions(['width' => '2', 'height' => '2']);
$assert($presetPair === [2.0, 3.0] && $customPair === [2.0, 2.0], 'Preset WxH strings and custom width/height fields normalize to the same validated feet values.');

// Dimension and quantity boundaries are rejected without changing user values.
$assert(printflow_tarpaulin_panel_plan(2, 3, 1, 3)['required_linear_ft'] === 2.0, 'The 2x3 preset uses a single rotated piece when it fits.');
$assert(printflow_tarpaulin_panel_plan(2, 2, 1, 3)['required_linear_ft'] === 2.0, 'The custom 2x2 size is valid.');
$singleWithAllowance = printflow_tarpaulin_panel_plan(2, 3, 1, 5, 'automatic', 'additional', 0.5);
$assert($singleWithAllowance['allowance_mode'] === 'additional' && $singleWithAllowance['allowance_ft_per_piece'] === 0.5
    && $singleWithAllowance['applied_allowance_ft_per_piece'] === 0.0 && $singleWithAllowance['required_linear_ft'] === 2.0,
    'A single-piece plan preserves the chosen allowance mode but applies no joining allowance.');
$assert(printflow_tarpaulin_panel_plan(100, 100, 1, 5)['required_linear_ft'] === 2000.0, 'The 100x100 boundary uses 20 continuous 100 ft panels.');
foreach ([[0, 2, 1], [101, 2, 1], [2, 101, 1], ['NaN', 2, 1], [2, 2, 0], [2, 2, 1.5]] as [$width, $height, $quantity]) {
    $throws(static fn() => printflow_tarpaulin_panel_plan($width, $height, $quantity, 5), 'Invalid dimensions or quantity must be rejected.');
}

$vertical = printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'vertical');
$horizontal = printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'horizontal');
$automatic = printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'automatic');
$assert($vertical['direction'] === 'vertical' && $vertical['panel_count_per_piece'] === 4 && $vertical['required_linear_ft'] === 40.0, 'Vertical seams create four 10 ft panels.');
$assert($horizontal['direction'] === 'horizontal' && $horizontal['panel_count_per_piece'] === 2 && $horizontal['required_linear_ft'] === 40.0, 'Horizontal seams create two 20 ft panels.');
$assert($automatic['required_linear_ft'] === 40.0 && $automatic['direction'] === 'vertical', 'Automatic mode chooses least material with deterministic vertical tie-breaking.');
$assert(printflow_tarpaulin_panel_plan(20, 10, 2, 5, 'vertical')['required_linear_ft'] === 80.0, 'Finished quantity multiplies the full per-tarp plan once.');
$included = printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'vertical', 'included', 0.5);
$additional = printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'vertical', 'additional', 0.5);
$assert($included['required_linear_ft'] === 40.0 && $additional['required_linear_ft'] === 50.0, 'Allowance changes panel count when necessary, without adding a separate seam surcharge.');
$assert($additional['coverage_target_ft'] === 20.5 && $additional['allowance_ft_per_piece'] === 0.5, 'Additional allowance is applied once to the joined coverage target per finished tarp.');
$additionalHorizontal = printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'horizontal', 'additional', 0.5);
$assert($additionalHorizontal['coverage_target_ft'] === 10.5 && $additionalHorizontal['required_linear_ft'] === 60.0, 'Horizontal allowance applies to height coverage and creates three 20 ft panels.');
$throws(static fn() => printflow_tarpaulin_panel_plan(20, 10, 1, 5, 'automatic', 'additional', -0.1), 'Negative allowance is rejected.');

$roll = static fn(int $id, float $ft): array => ['id' => $id, 'roll_code' => 'R' . $id, 'remaining_length_ft' => $ft, 'received_at' => sprintf('2026-01-%02d', $id)];
$shortRolls = printflow_tarpaulin_allocate_whole_panels([$roll(1, 20), $roll(2, 20)], $vertical);
$assert($shortRolls['ok'], 'Four 10 ft panels can be allocated as two continuous panels on each 20 ft roll.');
$oneRoll = printflow_tarpaulin_allocate_whole_panels([$roll(1, 40)], $vertical);
$assert($oneRoll['ok'] && $oneRoll['allocations'][0]['panels'] === 4 && $oneRoll['allocations'][0]['length_ft'] === 40.0, 'Multiple whole panels can be cut from one sufficiently long roll.');
$twoRolls = printflow_tarpaulin_allocate_whole_panels([$roll(1, 20), $roll(2, 20)], $horizontal);
$assert($twoRolls['ok'] && count($twoRolls['allocations']) === 2, 'Whole panels may be allocated across same-width physical rolls.');
$tooShort = printflow_tarpaulin_allocate_whole_panels([$roll(1, 19), $roll(2, 21)], $horizontal);
$assert(!$tooShort['ok'], 'A 20 ft panel cannot be split across 19 ft and 21 ft rolls even when aggregate stock is enough.');
$panelItem = ['id' => 90, 'name' => '5FT Tarpaulin', 'status' => 'ACTIVE', 'track_by_roll' => 1,
    'unit_of_measure' => 'ft', 'category_name' => 'Tarpaulin', 'branch_id' => 1,
    'open_rolls' => [$roll(1, 9), $roll(2, 9), $roll(3, 22)]];
$physicalCandidate = printflow_tarpaulin_resolve_panel_candidate([$panelItem], 20, 10, 1, 'vertical', 'included', 0);
$assert(!$physicalCandidate['ok'] && ($physicalCandidate['shortage']['available_ft'] ?? 0) === 40.0,
    'Aggregate stock equals demand but two short rolls cannot be combined to make the remaining continuous panels.');
$cartRolls = [$roll(10, 10), $roll(5, 5)];
$fiveFtLine = ['item_id' => 90, 'plan' => ['panel_count_per_piece' => 1, 'finished_quantity' => 1, 'run_length_per_panel_ft' => 5]];
$tenFtLine = ['item_id' => 90, 'plan' => ['panel_count_per_piece' => 1, 'finished_quantity' => 1, 'run_length_per_panel_ft' => 10]];
$smallFirst = printflow_tarpaulin_allocate_cart_panels(['small' => $fiveFtLine, 'large' => $tenFtLine], [90 => $cartRolls]);
$largeFirst = printflow_tarpaulin_allocate_cart_panels(['large' => $tenFtLine, 'small' => $fiveFtLine], [90 => $cartRolls]);
$assert($smallFirst['ok'] && $largeFirst['ok'], 'A feasible 5 ft plus 10 ft cart passes regardless of panel or line order.');
$assert($smallFirst['allocations_by_line']['small'][0]['roll_id'] === 5 && $smallFirst['allocations_by_line']['large'][0]['roll_id'] === 10,
    'Backtracking keeps the 10 ft roll for the 10 ft panel and assigns the 5 ft panel to the 5 ft roll.');
$assert($smallFirst['allocations_by_line'] === $largeFirst['allocations_by_line'], 'Allocation is deterministic when cart-line order is reversed.');
$twoFivePanels = ['item_id' => 90, 'plan' => ['panel_count_per_piece' => 1, 'finished_quantity' => 2, 'run_length_per_panel_ft' => 5]];
$multiPanel = printflow_tarpaulin_allocate_cart_panels(['fiveA' => $twoFivePanels, 'fiveB' => $fiveFtLine], [90 => [$roll(20, 15)]]);
$assert($multiPanel['ok'] && $multiPanel['allocations_by_line']['fiveA'][0]['panels'] === 2
    && $multiPanel['allocations_by_line']['fiveB'][0]['panels'] === 1, 'Multiple cart lines and quantities can cut multiple whole panels from one roll.');
$impossibleCart = printflow_tarpaulin_allocate_cart_panels(['large' => $tenFtLine, 'small' => $fiveFtLine], [90 => [$roll(21, 9), $roll(22, 6)]]);
$assert(!$impossibleCart['ok'] && $impossibleCart['reason'] === 'physical_roll_capacity', 'Aggregate linear feet do not falsely approve physically incompatible continuous panels.');
$multiRollSameMaterial = printflow_tarpaulin_allocate_cart_panels(['small' => $fiveFtLine, 'large' => $tenFtLine], [90 => [$roll(10, 10), $roll(5, 5)]]);
$assert($multiRollSameMaterial['ok'], 'Rolls of the same selected inventory item remain distinct physical capacities.');
$limited = printflow_tarpaulin_allocate_cart_panels(['large' => ['item_id' => 90, 'plan' => ['panel_count_per_piece' => 501, 'finished_quantity' => 1, 'run_length_per_panel_ft' => 1]]], [90 => []]);
$assert(!$limited['ok'] && $limited['reason'] === 'search_limit', 'Panel-count limit returns a safe calculation-limit result, not an inventory shortage.');

$metadata = ['tarpaulin_calculation_version' => PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION];
$assert(printflow_tarpaulin_is_versioned_metadata($metadata), 'The v2 material marker is recognized for assignment and completion.');
$candidatePlan = $vertical + ['item_id' => 90, 'branch_id' => 1];
$assert(printflow_tarpaulin_panel_plans_match($candidatePlan, $candidatePlan), 'Set Price and completion accept a byte-for-byte matching saved plan.');
$changedPlan = $candidatePlan;
$changedPlan['direction'] = 'horizontal';
$assert(!printflow_tarpaulin_panel_plans_match($changedPlan, $candidatePlan), 'A changed seam direction cannot silently replace the saved plan.');

echo "POS Tarpaulin panel joining tests passed.\n";
