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
$resolve = static fn(array $candidates, float $width, float $height, int $qty, array $usedDemand = [], array $usedRolls = []): array =>
    printflow_tarpaulin_resolve_width_fit($candidates, $width, $height, $qty, $usedDemand, $usedRolls);

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
$assert(!$narrowOnly['ok'] && $narrowOnly['reason'] === 'incompatible',
    'A 6 ft order is blocked when only narrower stock exists, regardless of aggregate footage.');

// Candidate selection prefers the least material footprint, then the narrower
// valid width: 3×6 chooses 3 ft, 4×6 chooses 4 ft, and 5×6 chooses 5 ft.
$allWidths = [$candidate(13, 3, 1, [30]), $candidate(14, 4, 1, [30]), $candidate(15, 5, 1, [30]), $candidate(16, 6, 1, [30])];
$assert($resolve($allWidths, 3, 6, 1)['selection']['item_id'] === 13, 'The smallest equally efficient valid roll is preferred for 3×6.');
$assert($resolve($allWidths, 4, 6, 1)['selection']['item_id'] === 14, 'The smallest equally efficient valid roll is preferred for 4×6.');
$assert($resolve($allWidths, 5, 6, 1)['selection']['item_id'] === 15, 'The smallest equally efficient valid roll is preferred for 5×6.');

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

$wrongWidth = $resolve([$candidate(13, 3, 1, [30], [1])], 3, 6, 1);
$assert(!$wrongWidth['ok'] && $wrongWidth['reason'] === 'reconciliation'
    && str_contains((string)$wrongWidth['shortage']['message'], 'roll #1301'),
    'Invalid/conflicting physical width reports the exact roll for reconciliation, not a false zero-stock shortage.');
$missingWidth = $candidate(13, 3, 1, [30], [0]);
$missingWidth['roll_width_issues'] = [['roll_id' => 1301, 'width_ft' => 'missing/invalid', 'item_name' => $missingWidth['name']]];
$missingWidthResult = $resolve([$missingWidth], 3, 6, 1);
$assert(!$missingWidthResult['ok'] && $missingWidthResult['reason'] === 'reconciliation', 'Missing roll width fails closed with a reconciliation result.');

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
$rollService = (string)file_get_contents($root . '/includes/RollService.php');
$assert(str_contains($validation, 'printflow_tarpaulin_resolve_width_fit(')
    && str_contains($validation, 'printflow_pos_tarpaulin_open_rolls($itemId, $branchId)')
    && str_contains($validation, 'roll_width_issues'),
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
$assert(str_contains($jobService, 'printflow_tarpaulin_saved_roll_fit_selection_for_job(')
    && str_contains($jobService, 'RollService::deductFromValidatedAllocation(')
    && str_contains($rollService, 'id IN ({$placeholders})')
    && str_contains($rollService, 'No other roll was used'),
    'Completion locks and deducts only the roll IDs carried by the saved POS allocation plan.');

echo "POS Tarpaulin width-fit allocation tests passed.\n";
