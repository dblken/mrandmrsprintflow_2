<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';

// Execute the real request adapter without bootstrapping a production DB.
function printflow_pos_tarpaulin_candidates(int $branchId): array {
    return array_map(static fn(int $id, int $width, int $rollId, float $remaining): array => [
        'id' => $id, 'name' => $width . 'ft Tarpaulin', 'status' => 'ACTIVE',
        'track_by_roll' => 1, 'unit_of_measure' => 'ft', 'material_width' => $width,
        'material_dimension_unit' => 'ft', 'branch_id' => $branchId, 'open_rolls' => [],
        'roll_width_issues' => [['roll_id' => $rollId, 'width_ft' => 1,
            'expected_width_ft' => $width, 'remaining_length_ft' => $remaining]],
    ], [13,14,15,16], [3,4,5,6], [155,154,157,156], [53.6,45.1,50.1,50.0]);
}
function printflow_pos_tarpaulin_dimensions(array $customization): array {
    return printflow_tarpaulin_normalize_dimensions($customization);
}
$source = file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$start = strpos($source, 'function printflow_pos_tarpaulin_selection(');
$end = strpos($source, 'function printflow_pos_service_stock_rules(', $start);
eval(substr($source, $start, $end - $start));
foreach ([1,10,100] as $quantity) {
    $result = printflow_pos_tarpaulin_selection(['qty'=>$quantity, 'customization'=>[
        'width'=>2, 'height'=>2, 'pos_material_dimension_unit'=>'ft', 'branch_id'=>1,
    ]], 1);
    $issue = $result['shortage'];
    if ($result['ok'] || $result['reason'] !== 'reconciliation'
        || $issue['required'] !== 2.0 * $quantity || $issue['available'] !== 0.0
        || abs($issue['recorded_open_ft'] - 198.8) > 0.000001
        || count($issue['roll_width_issues']) !== 4
        || !str_contains($issue['message'], 'reconciliation')
        || !str_contains($issue['message'], 'Calculated demand: ' . (2 * $quantity) . ' ft.')
        || !str_contains($issue['message'], 'roll #155')) {
        throw new RuntimeException('Request adapter lost allocator reconciliation diagnostics.');
    }
}
echo "Tarpaulin allocator-to-POS reconciliation handoff passed.\n";
