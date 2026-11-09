<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$candidate = static function (int $id, int $rollWidth, int $branch, array $lengths, ?array $physicalWidths = null): array {
    $rolls = [];
    foreach (array_values($lengths) as $index => $length) {
        $rolls[] = ['id' => ($id * 100) + $index + 1, 'branch_id' => $branch, 'status' => 'OPEN',
            'roll_code' => 'R' . $id . '-' . $index, 'width_ft' => $physicalWidths[$index] ?? $rollWidth,
            'remaining_length_ft' => $length, 'received_at' => sprintf('2026-01-%02d', $index + 1)];
    }
    return ['id' => $id, 'name' => $rollWidth . 'FT Tarpaulin', 'status' => 'ACTIVE', 'track_by_roll' => 1,
        'unit_of_measure' => 'ft', 'category_name' => 'TARPAULIN', 'branch_id' => $branch, 'open_rolls' => $rolls];
};
$resolve = static fn(array $candidates, float $width, float $height, int $qty, array $usedDemand = [], array $usedRolls = [], bool $joinedCustom = false): array =>
    printflow_tarpaulin_resolve_width_fit($candidates, $width, $height, $qty, $usedDemand, $usedRolls, $joinedCustom);
$customTarpaulinDimensions = printflow_tarpaulin_normalize_dimensions([
    'dimensions' => 'Others', 'width' => '10', 'height' => '5', 'dimensions_unit' => 'ft',
]);
$assert($customTarpaulinDimensions === [10.0, 5.0],
    'Server normalization preserves explicit 10×5 ft custom-size input independently of inventory roll widths.');
$assert(printflow_tarpaulin_custom_size_selected(['dimensions' => 'Others', 'width' => '10', 'height' => '5'])
    && !printflow_tarpaulin_custom_size_selected(['dimensions' => '3 x 6 ft', 'width' => '3', 'height' => '6']),
    'The joined-width planner is enabled only by the explicit POS custom-size choice.');
foreach ([3, 4, 5, 6] as $configuredWidth) {
    $configured = $candidate(10 + $configuredWidth, $configuredWidth, 1, [20]);
    $configured['material_width'] = $configuredWidth * 12;
    $configured['material_dimension_unit'] = 'in';
    $assert(printflow_tarpaulin_parse_nominal_width($configured) === (float)$configuredWidth, 'Configured physical width is unit-normalized independently of roll footage.');
    $assert($resolve([$configured], 2, 2, 1)['selection']['required_linear_ft'] === 2.0, '2x2 demand is two linear feet on each eligible configured width.');
}

$empty3ft = $candidate(13, 3, 1, []);
$invalidConfiguration = $candidate(13, 3, 1, [20]);
$invalidConfiguration['material_width'] = 3;
$invalidConfiguration['material_dimension_unit'] = 'unknown';
$invalidConfigRejected = false;
try { $resolve([$invalidConfiguration], 2, 2, 1); } catch (RuntimeException $error) {
    $invalidConfigRejected = str_contains($error->getMessage(), 'reconciliation');
}
$assert($invalidConfigRejected, 'Invalid configured width units fail clearly instead of falling back to a name or physical width.');
$emptyOne = $resolve([$empty3ft], 2, 3, 1);
$emptyHundred = $resolve([$empty3ft], 2, 3, 100);
$assert(!$emptyOne['ok'] && $emptyOne['shortage']['required_ft'] === 2.0 && $emptyOne['shortage']['available_ft'] === 0.0,
    'An empty 3 ft inventory still reports 2 ft required for a 2×3 order at quantity 1.');
$assert(!$emptyHundred['ok'] && $emptyHundred['shortage']['required_ft'] === 200.0 && $emptyHundred['shortage']['available_ft'] === 0.0,
    'An empty 3 ft inventory still reports the independently calculated 200 ft demand for 2×3 at quantity 100.');
$noConfiguredCandidates = $resolve([], 2, 3, 100);
$assert(!$noConfiguredCandidates['ok'] && $noConfiguredCandidates['reason'] === 'configuration'
    && $noConfiguredCandidates['shortage']['required_ft'] === 200.0
    && $noConfiguredCandidates['shortage']['available_ft'] === 0.0
    && str_contains($noConfiguredCandidates['shortage']['message'], 'configuration'),
    'No eligible inventory configuration reports positive geometric demand and a configuration error, never a zero-demand shortage.');
$invalidDimensionsRejected = false;
try { $resolve([], 0, 3, 100); } catch (InvalidArgumentException $error) {
    $invalidDimensionsRejected = str_contains(strtolower($error->getMessage()), 'width');
}
$assert($invalidDimensionsRejected, 'Invalid dimensions are rejected before stock lookup instead of becoming a zero-foot shortage.');

// For 3 × 6, every 3–6 ft roll is wide enough. The selected width controls
// whether the piece runs 6 ft (3–5 ft rolls) or rotates to run 3 ft (6 ft roll).
foreach ([3, 4, 5, 6] as $rollWidth) {
    $result = $resolve([$candidate(10 + $rollWidth, $rollWidth, 1, [30])], 3, 6, 1);
    $expectedRun = $rollWidth === 6 ? 3.0 : 6.0;
    $assert($result['ok'] && $result['selection']['roll_width_ft'] === (float)$rollWidth
        && $result['selection']['required_linear_ft'] === $expectedRun,
        "A 3 ft order uses the {$rollWidth} ft roll and calculates footage for that roll's valid orientation.");
}
$fourRolls = array_map(static fn(int $w): array => $candidate(20 + $w, $w, 1, [40]), [4, 5, 6]);
foreach ([4, 5, 6] as $rollWidth) {
    $result = $resolve([$candidate(20 + $rollWidth, $rollWidth, 1, [40])], 4, 6, 1);
    $assert($result['ok'] && $result['selection']['roll_width_ft'] === (float)$rollWidth
        && $result['selection']['required_linear_ft'] === ($rollWidth === 6 ? 4.0 : 6.0),
        "A 4 ft order uses the eligible {$rollWidth} ft roll without underestimating length.");
}
foreach ([5, 6] as $rollWidth) {
    $result = $resolve([$candidate(30 + $rollWidth, $rollWidth, 1, [40])], 5, 6, 1);
    $assert($result['ok'] && $result['selection']['required_linear_ft'] === ($rollWidth === 6 ? 5.0 : 6.0),
        "A 5 ft order uses the eligible {$rollWidth} ft roll.");
}
$sixFt = $resolve([$candidate(16, 6, 1, [20])], 6, 3, 1);
$assert($sixFt['ok'] && $sixFt['selection']['item_id'] === 16 && $sixFt['selection']['required_linear_ft'] === 3.0,
    'A 6 ft-wide order uses a 6 ft roll and considers rotation only on that sufficiently wide roll.');
$narrowOnly = $resolve([$candidate(13, 3, 1, [100]), $candidate(14, 4, 1, [100]), $candidate(15, 5, 1, [100])], 6, 3, 1);
$assert(!$narrowOnly['ok'] && $narrowOnly['reason'] === 'configuration'
    && $narrowOnly['shortage']['required_ft'] > 0 && $narrowOnly['shortage']['available_ft'] === 0.0,
    'A 6 ft order is blocked when only narrower stock exists and reports positive demand with no eligible stock.');

// Candidate selection prefers the least material footprint, then the narrower
// valid width: 3×6 chooses 3 ft, 4×6 chooses 4 ft, and 5×6 chooses 5 ft.
$allWidths = [$candidate(13, 3, 1, [30]), $candidate(14, 4, 1, [30]), $candidate(15, 5, 1, [30]), $candidate(16, 6, 1, [30])];
$assert($resolve($allWidths, 3, 6, 1)['selection']['item_id'] === 13, 'The smallest equally efficient valid roll is preferred for 3×6.');
$assert($resolve($allWidths, 4, 6, 1)['selection']['item_id'] === 14, 'The smallest equally efficient valid roll is preferred for 4×6.');
$assert($resolve($allWidths, 5, 6, 1)['selection']['item_id'] === 15, 'The smallest equally efficient valid roll is preferred for 5×6.');

// Wide custom prints may be joined side by side. Every strip is allocated to
// a specific roll and consumes the full run length for every finished piece.
$twoFiveCandidates = [$candidate(15, 5, 1, [70, 70], [1, 1])];
$twoFiveCandidates[0]['material_width'] = 5;
$twoFiveCandidates[0]['material_dimension_unit'] = 'ft';
$tenByFive = $resolve($twoFiveCandidates, 10, 5, 14, [], [], true);
$fiveAllocations = $tenByFive['selection']['roll_allocations_by_item'][15] ?? [];
$assert($tenByFive['ok'] && $tenByFive['selection']['required_linear_ft'] === 140.0
    && count($fiveAllocations) === 2
    && array_column($fiveAllocations, 'roll_id') === [1501, 1502]
    && array_sum(array_column($fiveAllocations, 'length_ft')) === 140.0
    && array_reduce($fiveAllocations, static fn($ok, $row) => $ok && $row['roll_width_ft'] === 5.0, true),
    '10×5 at quantity 14 allocates two configured 5 ft rolls, 70 linear ft each, regardless of saved roll width 1.');

$tenByFiveQtyTenCandidates = [$candidate(15, 5, 1, [50.1], [1]), $candidate(16, 6, 1, [50.0], [1])];
$tenByFiveQtyTenCandidates[0]['material_width'] = 5;
$tenByFiveQtyTenCandidates[0]['material_dimension_unit'] = 'ft';
$tenByFiveQtyTenCandidates[1]['material_width'] = 6;
$tenByFiveQtyTenCandidates[1]['material_dimension_unit'] = 'ft';
$tenByFiveQtyTenCandidates[0]['open_rolls'][0]['id'] = 157;
$tenByFiveQtyTenCandidates[1]['open_rolls'][0]['id'] = 156;
$tenByFiveQtyTen = $resolve($tenByFiveQtyTenCandidates, 10, 5, 10, [], [], true);
$qtyTenAllocations = [];
foreach ($tenByFiveQtyTen['selection']['roll_allocations_by_item'] ?? [] as $itemId => $rows) {
    foreach ($rows as $row) $qtyTenAllocations[(int)$itemId][] = $row;
}
$assert($tenByFiveQtyTen['ok'] && $tenByFiveQtyTen['selection']['version'] === PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION
    && $tenByFiveQtyTen['selection']['width_ft'] === 10.0 && $tenByFiveQtyTen['selection']['height_ft'] === 5.0
    && $tenByFiveQtyTen['selection']['finished_quantity'] === 10
    && ($qtyTenAllocations[15][0]['roll_id'] ?? 0) === 157
    && ($qtyTenAllocations[15][0]['length_ft'] ?? 0) === 50.0
    && ($qtyTenAllocations[16][0]['roll_id'] ?? 0) === 156
    && ($qtyTenAllocations[16][0]['length_ft'] ?? 0) === 50.0
    && ($qtyTenAllocations[15][0]['roll_width_ft'] ?? 0) === 5.0
    && ($qtyTenAllocations[16][0]['roll_width_ft'] ?? 0) === 6.0,
    '10×5 at quantity 10 persists a current v2 plan with 50 ft from roll 157 (item 15) and 50 ft from roll 156 (item 16), despite legacy roll widths of 1 ft.');

$threeAndFour = [$candidate(13, 3, 1, [70], [1]), $candidate(14, 4, 1, [70], [1])];
$threeAndFour[0]['material_width'] = 3;
$threeAndFour[0]['material_dimension_unit'] = 'ft';
$threeAndFour[1]['material_width'] = 4;
$threeAndFour[1]['material_dimension_unit'] = 'ft';
$sevenByTen = $resolve($threeAndFour, 7, 10, 1, [], [], true);
$mixedAllocations = [];
foreach ($sevenByTen['selection']['roll_allocations_by_item'] ?? [] as $itemId => $rows) {
    foreach ($rows as $row) $mixedAllocations[$itemId] = $row;
}
$assert($sevenByTen['ok'] && $sevenByTen['selection']['orientation'] === 'original'
    && $sevenByTen['selection']['required_linear_ft'] === 20.0
    && isset($mixedAllocations[13], $mixedAllocations[14])
    && $mixedAllocations[13]['roll_id'] === 1301 && $mixedAllocations[13]['length_ft'] === 10.0
    && $mixedAllocations[14]['roll_id'] === 1401 && $mixedAllocations[14]['length_ft'] === 10.0,
    '7×10 joins configured 3 ft plus 4 ft rolls across the 7 ft dimension, assigning 10 linear ft to each roll.');

$rotatedSevenByTen = $resolve([
    $candidate(14, 4, 1, [70], [1]),
    $candidate(16, 6, 1, [70], [1]),
], 7, 10, 1, [], [], true);
$rotatedRows = [];
foreach ($rotatedSevenByTen['selection']['roll_allocations_by_item'] ?? [] as $rows) foreach ($rows as $row) $rotatedRows[] = $row;
$assert($rotatedSevenByTen['ok'] && $rotatedSevenByTen['selection']['orientation'] === 'rotated'
    && $rotatedSevenByTen['selection']['required_linear_ft'] === 14.0
    && array_sum(array_column($rotatedRows, 'length_ft')) === 14.0
    && count($rotatedRows) === 2 && array_column($rotatedRows, 'length_ft') === [7.0, 7.0],
    '7×10 selects the valid rotated 4 ft plus 6 ft layout when it reduces the linear footage to 14 ft.');

$insufficientJoin = $resolve([$candidate(15, 5, 1, [69], [1])], 10, 5, 14, [], [], true);
$assert(!$insufficientJoin['ok'] && $insufficientJoin['reason'] === 'insufficient'
    && $insufficientJoin['shortage']['required_ft'] === 140.0,
    'Insufficient joined-roll footage remains a blocking shortage with the true demand.');

$standardNoJoin = $resolve([$candidate(13, 3, 1, [100]), $candidate(14, 4, 1, [100]), $candidate(15, 5, 1, [100])], 6, 3, 1);
$assert(!$standardNoJoin['ok'] && $standardNoJoin['reason'] === 'configuration',
    'Standard-size Tarpaulin remains on the existing single-width path and does not silently join narrower stock.');

// Multi-roll assignment is explicit and each physical roll contributes its
// footage at most once. Whole pieces cannot be split across short rolls.
$multiRoll = $resolve([$candidate(13, 3, 1, [6, 6])], 3, 6, 2);
$allocations = $multiRoll['selection']['roll_allocations_by_item'][13] ?? [];
$assert($multiRoll['ok'] && count($allocations) === 2
    && $allocations[0]['roll_id'] !== $allocations[1]['roll_id']
    && array_sum(array_column($allocations, 'length_ft')) === 12.0,
    'Two pieces are allocated to distinct 3 ft physical rolls with exact per-roll footage.');
$used = [13 => [$allocations[0]['roll_id'] => $allocations[0]['length_ft']]];
$notDoubleCounted = $resolve([$candidate(13, 3, 1, [6, 6])], 3, 6, 2, [], $used);
$assert(!$notDoubleCounted['ok'], 'A prior line allocation is subtracted by physical roll ID and cannot be counted again.');
$splitPieceImpossible = $resolve([$candidate(13, 3, 1, [5, 7])], 3, 6, 2);
$assert(!$splitPieceImpossible['ok'] && str_contains((string)$splitPieceImpossible['shortage']['message'], 'continuously'),
    'A 6 ft piece cannot be split between a 5 ft and 7 ft remaining roll, despite 12 aggregate ft.');

$configuredThreeWithLegacyWidth = $candidate(13, 3, 1, [30], [1]);
$configuredThreeWithLegacyWidth['material_width'] = 36;
$configuredThreeWithLegacyWidth['material_dimension_unit'] = 'in';
$configuredWidthResult = $resolve([$configuredThreeWithLegacyWidth], 3, 6, 1);
$assert($configuredWidthResult['ok']
    && $configuredWidthResult['selection']['item_id'] === 13
    && $configuredWidthResult['selection']['roll_width_ft'] === 3.0
    && $configuredWidthResult['selection']['required_linear_ft'] === 6.0
    && ($configuredWidthResult['selection']['roll_allocations_by_item'][13][0]['roll_id'] ?? 0) === 1301,
    'Configured 3 ft width controls fit and allocation despite legacy inv_rolls.width_ft = 1.');

$liveLikeCabuyao = [];
foreach ([13 => 3, 14 => 4, 15 => 5, 16 => 6] as $itemId => $configuredWidth) {
    $row = $candidate($itemId, $configuredWidth, 1, [250], [1]);
    $row['material_width'] = $configuredWidth;
    $row['material_dimension_unit'] = 'ft';
    $liveLikeCabuyao[] = $row;
}
$liveLikeResult = $resolve($liveLikeCabuyao, 2, 2, 100);
$allocatedFootage = 0.0;
foreach ($liveLikeResult['selection']['roll_allocations_by_item'] as $allocations) $allocatedFootage += array_sum(array_column($allocations, 'length_ft'));
$assert($liveLikeResult['ok'] && $liveLikeResult['selection']['required_linear_ft'] === 200.0
    && abs($allocatedFootage - 200.0) < 0.001,
    'Configured widths 3–6 ft allocate quantity demand across exact planned rolls despite legacy width metadata.');
$branchOne = $candidate(13, 3, 1, [5]);
$branchOne['open_rolls'][] = ['id' => 1399, 'branch_id' => 2, 'status' => 'OPEN', 'width_ft' => 3, 'remaining_length_ft' => 100];
$branchTwo = $candidate(13, 3, 2, [100]);
$branchOneResult = $resolve([$branchOne], 3, 6, 1);
$assert(!$branchOneResult['ok'] && ($branchOneResult['shortage']['available_ft'] ?? -1) === 5.0,
    'Rolls supplied from another branch are never combined with branch 1 stock.');
$branchTwoResult = $resolve([$branchTwo], 3, 6, 1);
$assert($branchTwoResult['ok'] && $branchTwoResult['selection']['branch_id'] === 2, 'A branch change uses only the new branch candidate set.');
$closedAndEmptyExcluded = $candidate(13, 3, 1, []);
$closedAndEmptyExcluded['open_rolls'] = [
    ['id' => 1, 'branch_id' => 1, 'status' => 'CLOSED', 'width_ft' => 3, 'remaining_length_ft' => 50],
    ['id' => 2, 'branch_id' => 1, 'status' => 'OPEN', 'width_ft' => 3, 'remaining_length_ft' => 0],
];
$closedResult = $resolve([$closedAndEmptyExcluded], 3, 6, 1);
$assert(!$closedResult['ok'] && in_array($closedResult['reason'], ['physical_roll_capacity', 'insufficient'], true)
    && ($closedResult['shortage']['available_ft'] ?? -1) === 0.0,
    'Closed and empty rolls do not satisfy demand or contribute available footage.');

$root = dirname(__DIR__);
$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cartHandler = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$jobService = (string)file_get_contents($root . '/includes/JobOrderService.php');
$staffCustomizations = (string)file_get_contents($root . '/staff/customizations.php');
$jobApi = (string)file_get_contents($root . '/admin/job_orders_api.php');
$rollService = (string)file_get_contents($root . '/includes/RollService.php');
$assert(str_contains($validation, 'printflow_tarpaulin_resolve_width_fit(')
    && str_contains($validation, 'printflow_tarpaulin_custom_size_selected($customization)')
    && str_contains($validation, 'printflow_pos_tarpaulin_open_rolls($itemId, $branchId)')
    && str_contains($validation, 'roll_width_issues')
    && str_contains($validation, "'required' => (float)(\$shortage['required_ft'] ?? 0)")
    && str_contains($validation, "'available' => (float)(\$shortage['available_ft'] ?? 0)"),
    'Modal stock checks use the width-fit resolver and branch-scoped open physical rolls.');
$assert(str_contains($cartHandler, 'printflow_pos_validate_service_stock(')
    && str_contains($cartHandler, "in_array(\$action, ['add', 'update', 'update_service_link', 'validate_stock'], true)"),
    'Server-side cart add and explicit validation call the same stock validator.');
$assert(str_contains($checkout, 'printflow_pos_validate_service_stock(')
    && str_contains($checkout, "\$customization['pos_tarpaulin_selection'] = \$selection"),
    'Checkout revalidates and persists the server-authored allocation plan.');
$assert(str_contains($pos, "body.addEventListener('input'") && str_contains($pos, "body.addEventListener('change'")
    && str_contains($pos, 'posValidateServiceStockNow(serviceId, quantity ? quantity.value : \'\')'),
    'Dimension, quantity, layout, and branch changes trigger a fresh modal check.');
$assert(str_contains($pos, 'const tarpaulinDimensionsReady = tarpaulinEarlyStock')
    && str_contains($pos, '!(tarpaulinEarlyStock && tarpaulinDimensionsReady)')
    && str_contains($pos, 'Number(serviceCustomization.branch_id || 0) > 0'),
    'Tarpaulin early stock checks run as soon as dimensions, quantity, and branch are known without waiting for unrelated required fields.');
$assert(str_contains($jobService, 'printflow_tarpaulin_saved_roll_fit_selection_for_job(')
    && str_contains($jobService, 'RollService::deductFromValidatedAllocation(')
    && str_contains($jobService, "['roll_allocations_by_item'] ?? [])[\$itemId]")
    && str_contains($jobService, '$plannedItemLength')
    && str_contains($rollService, 'id IN ({$placeholders})')
    && str_contains($rollService, 'No other roll was used'),
    'Completion locks and deducts each mixed-width material from only its own saved roll IDs and per-item footage.');
$assert(str_contains($jobService, 'printflow_tarpaulin_normalize_dimensions($custom)')
    && str_contains($jobService, "if (\$selectionServiceId === 40)")
    && str_contains($jobService, 'The saved Tarpaulin allocation no longer matches the job dimensions, quantity, or branch')
    && str_contains($jobService, 'A Tarpaulin material from the saved multi-roll allocation is missing in Production Assignment'),
    'Job creation keeps the explicit customer dimensions and completion requires the complete saved multi-roll plan for that branch/order.');
$assert(str_contains($staffCustomizations, "selection.version !== 'roll_fit_v2'")
    && str_contains($staffCustomizations, 'roll_allocations_by_item')
    && str_contains($staffCustomizations, 'this.tarpaulinSelectionMatchesSavedPlan(selectedAssignmentIds)')
    && str_contains($staffCustomizations, 'width = parsePositive(materialService.primaryCustom?.width ?? materialService.primaryCustom?.width_ft)'),
    'Production Assignment uses all item allocations from the persisted plan and keeps customer dimensions separate.');
$assert(str_contains($jobApi, 'Tarpaulin assignment quantity must match this material’s saved roll allocation')
    && str_contains($jobApi, 'groupIsExactSavedPlanSubset'),
    'Server assignment accepts only material IDs and quantities contained in the server-authored multi-roll plan.');

$paired = printflow_tarpaulin_pair_cart_lines(
    [['id' => 40, 'is_service' => true, 'qty' => 10, 'pos_cart_line_id' => 'qa-line-1', 'customization' => [
        'service_id' => 40, 'dimensions' => 'Others', 'width' => 10, 'height' => 5,
    ]]],
    [['product_id' => 40, 'is_service' => true, 'qty' => 10, 'pos_cart_line_id' => 'qa-line-1', 'customization' => [
        'service_id' => 40, 'dimensions' => 'Others', 'width' => 10, 'height' => 5,
        'pos_tarpaulin_selection' => $tenByFiveQtyTen['selection'],
    ]]],
    1
);
$assert($paired['ok']
    && $paired['items'][0]['customization']['width'] === 10
    && $paired['items'][0]['customization']['height'] === 5
    && $paired['items'][0]['customization']['pos_tarpaulin_selection']['version'] === PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION
    && $paired['items'][0]['customization']['pos_tarpaulin_selection']['roll_allocations_by_item'][15][0]['roll_id'] === 157
    && $paired['items'][0]['customization']['pos_tarpaulin_selection']['roll_allocations_by_item'][16][0]['roll_id'] === 156,
    'Checkout handoff preserves 10×5 customer dimensions and exact 5 ft/6 ft roll allocation IDs.');

echo "POS Tarpaulin width-fit allocation tests passed.\n";
