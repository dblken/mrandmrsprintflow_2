<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/roll_branch_scope.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

// The two active branches from the operator-confirmed Branch Management view.
$archivedBranchFixtureId = 99; // Isolated test fixture; no production branch ID is inferred.
foreach ([1, 2] as $activeBranchId) {
    $assert(!printflow_roll_belongs_to_branch(0, $activeBranchId), 'Unassigned branch_id=0 rolls must not belong to an active branch.');
    $assert(!printflow_roll_belongs_to_branch(null, $activeBranchId), 'NULL branch rolls must not belong to an active branch.');
    $assert(!printflow_roll_belongs_to_branch(-1, $activeBranchId), 'Nonpositive branch IDs must not belong to an active branch.');
    $assert(printflow_roll_belongs_to_branch($activeBranchId, $activeBranchId), 'A roll belongs only to its exact positive branch.');
    $otherBranchId = $activeBranchId === 1 ? 2 : 1;
    $assert(!printflow_roll_belongs_to_branch($otherBranchId, $activeBranchId), 'A roll from the other active branch must stay isolated.');
    $assert(!printflow_roll_belongs_to_branch($archivedBranchFixtureId, $activeBranchId), 'A roll tagged to an archived-branch fixture must not appear in an active branch.');
}

$assert(printflow_roll_branch_sql('branch_id', 1) === [' AND branch_id = ?', 'i', [1]], 'Branch 1 query uses exact equality.');
$assert(printflow_roll_branch_sql('r.branch_id', 2) === [' AND r.branch_id = ?', 'i', [2]], 'Aliased roll queries use exact branch equality.');
try {
    printflow_roll_branch_sql('branch_id', 0);
    throw new RuntimeException('Branch zero unexpectedly produced a stock query.');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'positive authorized branch'), 'Branch zero fails closed.');
}
try {
    printflow_roll_branch_sql('branch_id) OR 1=1 --', 1);
    throw new RuntimeException('An unsafe column name unexpectedly passed.');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'safe roll branch column'), 'The SQL column fragment is validated.');
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$validator = $read('includes/pos_service_stock_validation.php');
$manager = $read('includes/InventoryManager.php');
$rollService = $read('includes/RollService.php');
$legacyRollService = $read('includes/TarpaulinService.php');
$listApi = $read('admin/api_tarp_rolls.php');
$saveApi = $read('admin/api_save_tarp_specs.php');
$ordersPage = $read('admin/orders_management.php');
$rollsApi = $read('admin/inventory_rolls_api.php');

$assert(str_contains($validator, "printflow_roll_branch_sql('branch_id', \$branchId)"), 'POS Tarpaulin stock reads use exact roll ownership.');
$assert(str_contains($manager, "printflow_roll_branch_sql('branch_id', (int)\$branchId)"), 'Branch stock totals exclude unassigned rolls.');
$assert(str_contains($rollService, "printflow_roll_branch_sql('branch_id', \$branchId)"), 'Roll listing and panel deduction use exact branch filters.');
$assert(str_contains($rollService, 'printflow_roll_belongs_to_branch($roll[\'branch_id\'] ?? null, (int)$branchId)'), 'Single-roll deduction verifies the order branch before changing length.');
$assert(str_contains($legacyRollService, 'getAvailableRolls($width, int $branchId)'), 'Legacy Tarpaulin roll choices require an explicit branch.');
$assert(str_contains($listApi, 'order_id') && str_contains($listApi, 'TarpaulinService::getAvailableRolls($width, $orderBranchId)'), 'Order roll choices are based on the saved order branch.');
$assert(str_contains($saveApi, 'branch_id = ? AND status = \'OPEN\' AND remaining_length_ft > 0'), 'Saving roll specs rejects unassigned, closed, or empty rolls.');
$assert(str_contains($ordersPage, "'&order_id=' + encodeURIComponent(this.order?.order_id || '')"), 'The order page identifies the order for branch-scoped roll choices.');
$assert(str_contains($rollsApi, 'Your assigned branch could not be verified; no unscoped roll list was returned.') && str_contains($rollsApi, "'r.branch_id = ?'"), 'Staff roll inventory list refuses missing branch context and uses exact branch ownership.');

echo "Tarpaulin strict branch isolation tests passed.\n";
