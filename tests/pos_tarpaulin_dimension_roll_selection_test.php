<?php
declare(strict_types=1);

/**
 * Tarpaulin Width/Height → narrowest mapped roll + Actual Usage (linear_ft_v1).
 * Joining/seam fields stay out of the new POS path.
 */

require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$roll = static function (int $id, string $name, float $available): array {
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'ACTIVE',
        'track_by_roll' => 1,
        'unit_of_measure' => 'ft',
        'category_name' => 'TARPAULIN',
        'available_ft' => $available,
        'branch_id' => 1,
        'open_rolls' => [['id' => $id * 10, 'remaining_length_ft' => $available]],
    ];
};

$candidates = [
    $roll(13, '3FT Tarpaulin', 100),
    $roll(14, '4FT Tarpaulin', 100),
    $roll(15, '5FT Tarpaulin', 100),
    $roll(16, '6FT Tarpaulin', 100),
];

$twoByTwo = printflow_tarpaulin_resolve_candidate($candidates, 2, 2, 1);
$assert(!empty($twoByTwo['ok']), '2 × 2 ft must find a fitting mapped roll.');
$assert((int)$twoByTwo['selection']['item_id'] === 13, '2 × 2 ft selects the narrowest 3 ft roll.');
$assert((float)$twoByTwo['selection']['roll_width_ft'] === 3.0, '2 × 2 ft uses a 3 ft roll width.');
$assert((float)$twoByTwo['selection']['required_linear_ft'] === 2.0, '2 × 2 ft on 3 ft consumes 2 linear ft.');

$sixBySix = printflow_tarpaulin_resolve_candidate($candidates, 6, 6, 1);
$assert(!empty($sixBySix['ok']), '6 × 6 ft must find a fitting mapped roll.');
$assert((int)$sixBySix['selection']['item_id'] === 16, '6 × 6 ft selects the 6 ft roll.');
$assert((float)$sixBySix['selection']['roll_width_ft'] === 6.0, '6 × 6 ft uses a 6 ft roll width.');
$assert((float)$sixBySix['selection']['required_linear_ft'] === 6.0, '6 × 6 ft on 6 ft consumes 6 linear ft.');

[$wIn, $hIn] = printflow_tarpaulin_normalize_dimensions([
    'width' => '24',
    'height' => '24',
    'pos_material_dimension_unit' => 'in',
]);
$assert(abs($wIn - 2.0) < 0.000001 && abs($hIn - 2.0) < 0.000001, '24 × 24 in converts to 2 × 2 ft.');
$fromInches = printflow_tarpaulin_resolve_candidate($candidates, $wIn, $hIn, 1);
$assert((int)$fromInches['selection']['item_id'] === 13, 'Converted inch dimensions still select the 3 ft roll.');

[$wCm, $hCm] = printflow_tarpaulin_normalize_dimensions([
    'width' => '60.96',
    'height' => '60.96',
    'unit' => 'cm',
]);
$assert(abs($wCm - 2.0) < 0.01 && abs($hCm - 2.0) < 0.01, '60.96 × 60.96 cm converts to about 2 × 2 ft.');

$noFit = printflow_tarpaulin_resolve_candidate([
    $roll(13, '3FT Tarpaulin', 100),
    $roll(14, '4FT Tarpaulin', 100),
], 6, 6, 1);
$assert(empty($noFit['ok']) && ($noFit['reason'] ?? '') === 'incompatible', 'No fitting roll is rejected clearly.');

$shortStock = printflow_tarpaulin_resolve_candidate([
    $roll(13, '3FT Tarpaulin', 1),
], 2, 2, 1);
$assert(empty($shortStock['ok']) && ($shortStock['reason'] ?? '') === 'insufficient', 'Insufficient roll stock is rejected clearly.');
$assert((float)($shortStock['shortage']['required_ft'] ?? 0) === 2.0, 'Shortage reports the required Actual Usage feet.');

$mapped = printflow_production_assignment_selection_registry()[40] ?? [];
foreach ([13, 14, 15, 16] as $itemId) {
    $assert(isset($mapped[$itemId]), 'Mapped Tarpaulin roll item ' . $itemId . ' remains in production assignment registry.');
}

$root = dirname(__DIR__);
$pos = (string)file_get_contents($root . '/staff/pos.php');
$stock = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$assert(!str_contains($pos, 'posEnsureTarpaulinDimensionInputs')
    && !str_contains($pos, 'data-pos-tarp-width="1"')
    && !str_contains($pos, 'data-pos-tarp-height="1"')
    && !str_contains($pos, 'pos-tarpaulin-dimension-controls'),
    'POS Tarpaulin Order Details does not inject separate Width/Height inputs.');
$assert(str_contains($pos, 'function posCollectTarpaulinStockCustomization()')
    && str_contains($pos, 'parsePair(dimensionControl')
    && str_contains($pos, 'Select a valid Dimensions option before adding Tarpaulin.'),
    'Tarpaulin size is derived from the Dimensions field for cart/stock collection.');
$assert(!str_contains($pos, 'Joining Direction')
    && !str_contains($pos, 'Joining Allowance')
    && !str_contains($pos, 'pos-tarpaulin-joining-controls'),
    'Joining Direction / Allowance remain absent from POS.');
$assert(str_contains($stock, 'printflow_pos_tarpaulin_selection(')
    && str_contains($stock, 'printflow_tarpaulin_resolve_candidate(')
    && str_contains($stock, 'No active Tarpaulin roll width can fit these dimensions, including rotation.'),
    'POS stock validation restores mapped roll-fit selection without joining seams.');

echo "POS Tarpaulin dimension roll selection tests passed.\n";
