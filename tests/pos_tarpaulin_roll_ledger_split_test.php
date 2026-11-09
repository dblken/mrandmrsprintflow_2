<?php
declare(strict_types=1);

/**
 * Documents roll-level Tarpaulin deduction and ledger granularity (no live DB).
 */
$root = dirname(__DIR__);
$roll = (string)file_get_contents($root . '/includes/RollService.php');
$job = (string)file_get_contents($root . '/includes/JobOrderService.php');
$calc = (string)file_get_contents($root . '/includes/tarpaulin_material_calculation.php');

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$expect(str_contains($roll, 'Deduct actual Tarpaulin usage only from the physical rolls in its validated POS plan'),
    'Completion uses the saved POS allocation, not FIFO substitution.');
$expect(str_contains($roll, 'Record ledger entry for this roll deduction')
    || preg_match('/InventoryManager::recordTransaction\([^;]+rollId/s', $roll) === 1,
    'FIFO roll path records one ledger row per physical roll.');
$expect(str_contains($roll, 'foreach ($entries as $entry)')
    && str_contains($roll, 'InventoryManager::recordTransaction($itemId, \'OUT\', $deduct'),
    'Validated allocation emits one ledger OUT per planned physical roll.');
$expect(str_contains($roll, 'isset($plannedByRoll[$rollId])')
    && str_contains($roll, 'invalid or duplicated'),
    'Duplicate roll IDs in one plan are rejected.');

$expect(str_contains($job, 'deductFromValidatedAllocation(')
    && str_contains($job, 'tarpaulinRollFitDeducted[$itemId] = true'),
    'Each Tarpaulin inventory item is deducted once per completion.');
$expect(str_contains($job, 'deducted_at = NOW()')
    && str_contains($job, 'deducted_at IS NULL'),
    'Completion marks assignments deducted_at for idempotent retries.');

$expect(str_contains($calc, 'printflow_tarpaulin_allocate_cart_panels')
    && str_contains($calc, 'allocations_by_line'),
    'POS allocation can split one item across multiple open rolls when footage requires it.');

$twoRollFiveFt = [
    ['id' => 15, 'name' => '5ft Tarpaulin', 'status' => 'ACTIVE', 'track_by_roll' => 1,
        'unit_of_measure' => 'ft', 'category_name' => 'TARPAULIN', 'branch_id' => 1,
        'material_width' => 5, 'material_dimension_unit' => 'ft',
        'open_rolls' => [
            ['id' => 201, 'branch_id' => 1, 'status' => 'OPEN', 'remaining_length_ft' => 40, 'received_at' => '2026-01-01'],
            ['id' => 202, 'branch_id' => 1, 'status' => 'OPEN', 'remaining_length_ft' => 15, 'received_at' => '2026-01-02'],
        ]],
    ['id' => 16, 'name' => '6ft Tarpaulin', 'status' => 'ACTIVE', 'track_by_roll' => 1,
        'unit_of_measure' => 'ft', 'category_name' => 'TARPAULIN', 'branch_id' => 1,
        'material_width' => 6, 'material_dimension_unit' => 'ft',
        'open_rolls' => [
            ['id' => 301, 'branch_id' => 1, 'status' => 'OPEN', 'remaining_length_ft' => 50, 'received_at' => '2026-01-01'],
        ]],
];
require_once $root . '/includes/tarpaulin_material_calculation.php';
$resolved = printflow_tarpaulin_resolve_width_fit($twoRollFiveFt, 10, 5, 10, [], [], true);
$byItem = $resolved['selection']['roll_allocations_by_item'] ?? [];
$fiveRows = $byItem[15] ?? [];
$sixRows = $byItem[16] ?? [];
$fiveTotal = array_sum(array_map(static fn(array $r): float => (float)($r['length_ft'] ?? 0), $fiveRows));
$sixTotal = array_sum(array_map(static fn(array $r): float => (float)($r['length_ft'] ?? 0), $sixRows));
$expect(($resolved['ok'] ?? false) === true && $fiveTotal === 50.0 && $sixTotal === 50.0,
    '10×5 qty 10 allocates 50 ft on 5ft item and 50 ft on 6ft item.');
$expect(count($fiveRows) >= 2 && count($sixRows) === 1,
    'When the first 5ft roll holds only 40 ft, the plan splits the item across two physical rolls while 6ft stays on one roll.');

if ($failures !== []) {
    fwrite(STDERR, "pos_tarpaulin_roll_ledger_split_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_tarpaulin_roll_ledger_split_test passed.\n";
