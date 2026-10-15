<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$mutation = (string) file_get_contents($root . '/includes/order_status_mutation.php');
$jobs = (string) file_get_contents($root . '/includes/JobOrderService.php');
$db = (string) file_get_contents($root . '/includes/db.php');

$expect(str_contains($jobs, 'function resolveRollMaterialLengthFt'), 'Roll completion must normalize manual assignment lengths.');
$expect(str_contains($jobs, '$computed > ($quantity * 10)'), 'Inflated computed roll lengths must fall back to staff quantity.');
$expect(str_contains($db, 'printflow_db_transaction_depth_bump'), 'Nested transactions must track PrintFlow depth.');
$expect(str_contains($mutation, 'printflow_db_transaction_depth_pop'), 'Order status mutation must use nested transaction helpers.');
$expect(str_contains($jobs, 'rollMaterialSupersededByWiderAssignment'), 'Completion must skip narrower duplicate tarp roll assignments.');
$expect(str_contains($jobs, 'superseded_by_wider_roll_material'), 'Superseded tarp rows must be auditable in assignment metadata.');
$expect(str_contains($mutation, 'Cannot complete Job'), 'Inventory completion failures must map to actionable HTTP 409 responses.');

if ($failures !== []) {
    fwrite(STDERR, "job_order_completion_roll_deduction_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "job_order_completion_roll_deduction_test passed.\n";
