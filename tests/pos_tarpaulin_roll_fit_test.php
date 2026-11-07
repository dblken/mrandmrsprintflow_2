<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$candidate = static function (int $id, int $width, int $branch, array $lengths): array {
    $rolls = [];
    foreach (array_values($lengths) as $index => $length) {
        $rolls[] = ['id' => ($id * 100) + $index + 1, 'roll_code' => 'R' . $id . '-' . $index,
            'width_ft' => $width, 'remaining_length_ft' => $length, 'received_at' => sprintf('2026-01-%02d', $index + 1)];
    }
    return ['id' => $id, 'name' => $width . 'FT Tarpaulin', 'status' => 'ACTIVE', 'track_by_roll' => 1,
        'unit_of_measure' => 'ft', 'category_name' => 'TARPAULIN', 'branch_id' => $branch, 'open_rolls' => $rolls];
};

$rolls = [
    $candidate(13, 3, 1, [20]), $candidate(14, 4, 1, [20]),
    $candidate(15, 5, 1, [20]), $candidate(16, 6, 1, [20]),
];
$threeBySix = printflow_tarpaulin_resolve_roll_fit($rolls, 3, 6, 1);
$assert($threeBySix['ok'] && ($threeBySix['selection']['required_by_item'][13] ?? 0) === 6.0,
    '3 × 6 ft uses the exact 3 ft width roll and requires 6 linear ft.');
$fourBySix = printflow_tarpaulin_resolve_roll_fit($rolls, 4, 6, 1);
$assert($fourBySix['ok'] && ($fourBySix['selection']['required_by_item'][14] ?? 0) === 6.0,
    '4 × 6 ft prefers the exact 4 ft roll when it fits.');

// No 6 ft roll is available: 7 × 6 fits as 3 ft + 4 ft joined panels.
$threeFtSix = $candidate(13, 3, 1, [6]);
$fourFtSix = $candidate(14, 4, 1, [6]);
$mixed = printflow_tarpaulin_resolve_roll_fit([$threeFtSix, $fourFtSix], 7, 6, 1);
$assert($mixed['ok'] && array_keys($mixed['selection']['required_by_item']) === [13, 14],
    '7 × 6 ft can use two different roll widths when the wider single roll is unavailable.');
$assert($mixed['selection']['required_by_item'][13] === 6.0 && $mixed['selection']['required_by_item'][14] === 6.0,
    'The 3 ft + 4 ft plan checks a full 6 ft continuous panel on each physical roll.');
$assert(count($mixed['selection']['roll_allocations_by_item'][13]) === 1
    && count($mixed['selection']['roll_allocations_by_item'][14]) === 1,
    'The selected width combination records physical FIFO roll allocations for each item.');

$alternativeOrientation = printflow_tarpaulin_resolve_roll_fit([$rolls[0], $rolls[1], $rolls[3]], 7, 6, 1);
$assert($alternativeOrientation['ok'] && ($alternativeOrientation['selection']['required_by_item'][16] ?? 0) === 7.0,
    'A 6 ft roll may cover the 6 ft dimension by rotating the 7 × 6 order when its footage is available.');

$shortPanels = printflow_tarpaulin_resolve_roll_fit([
    $candidate(13, 3, 1, [5]), $candidate(14, 4, 1, [7]),
], 7, 6, 1);
$assert(!$shortPanels['ok'] && ($shortPanels['reason'] ?? '') === 'insufficient'
    && str_contains((string)($shortPanels['shortage']['message'] ?? ''), 'continuous panel'),
    'Aggregate footage is not treated as sufficient when no individual roll can hold its continuous panel.');
$wrongPhysicalWidth = $candidate(13, 3, 1, [20]);
$wrongPhysicalWidth['open_rolls'][0]['width_ft'] = 4;
$wrongWidthResult = printflow_tarpaulin_resolve_roll_fit([$wrongPhysicalWidth], 3, 6, 1);
$assert(!$wrongWidthResult['ok'] && ($wrongWidthResult['reason'] ?? '') === 'insufficient',
    'A physical roll whose recorded width conflicts with its active inventory-item width is excluded.');

$quantityChange = printflow_tarpaulin_resolve_roll_fit([$threeFtSix, $fourFtSix], 7, 6, 2);
$assert(!$quantityChange['ok'] && ($quantityChange['reason'] ?? '') === 'insufficient',
    'Changing quantity rechecks the required length on every selected width.');
$branchTwo = printflow_tarpaulin_resolve_roll_fit([
    $candidate(13, 3, 2, [20]), $candidate(14, 4, 2, [20]),
], 7, 6, 1);
$assert($branchTwo['ok'] && $branchTwo['selection']['branch_id'] === 2,
    'The chosen roll-fit snapshot retains the selected branch scope.');
$branchOneOnly = printflow_tarpaulin_resolve_roll_fit([$threeFtSix, $fourFtSix], 7, 6, 1);
$assert($branchOneOnly['ok'] && $branchOneOnly['selection']['branch_id'] === 1,
    'A branch change evaluates only the newly supplied branch roll rows.');

$root = dirname(__DIR__);
$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cartHandler = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$assert(str_contains($validation, 'printflow_tarpaulin_resolve_roll_fit(')
    && str_contains($validation, 'printflow_pos_tarpaulin_open_rolls($itemId, $branchId)')
    && str_contains($validation, 'width_ft, remaining_length_ft'),
    'The server roll-fit resolver reads physical width/remaining length under the selected branch.');
$assert(str_contains($cartHandler, "'validate_service_stock'")
    && str_contains($cartHandler, 'printflow_pos_validate_service_stock(')
    && str_contains($cartHandler, "in_array(\$action, ['add', 'update', 'update_service_link', 'validate_stock'], true)"),
    'The cart API reuses server-side Tarpaulin validation on Add/validate actions.');
$assert(str_contains($pos, "body.addEventListener('input'")
    && str_contains($pos, "body.addEventListener('change'")
    && str_contains($pos, 'posValidateServiceStockNow(serviceId, quantity ? quantity.value : \'\')'),
    'Dimension, layout, and editable branch field changes schedule a fresh modal stock validation.');

echo "POS Tarpaulin roll-fit tests passed.\n";
