<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/material_assignment_removal_policy.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assignment = ['id' => 5, 'item_id' => 23, 'quantity' => 2, 'uom' => 'pcs', 'deducted_at' => null];
$preProductionJobs = [['id' => 10, 'status' => 'APPROVED']];
$assert(
    printflow_material_assignment_removal_safety_error($assignment, $preProductionJobs, []) === '',
    'An assigned but undeducted material on an approved pre-production job can be corrected.'
);
$assert(
    printflow_material_assignment_removal_safety_error($assignment, [['id' => 10, 'status' => 'PENDING']], []) === '',
    'A pending undeducted assignment remains correctable.'
);
$assert(
    str_contains(printflow_material_assignment_removal_safety_error($assignment, [['id' => 10, 'status' => 'IN_PRODUCTION']], []), 'before production starts'),
    'Production-started assignments are protected.'
);
$assert(
    str_contains(printflow_material_assignment_removal_safety_error($assignment, [['id' => 10, 'status' => 'COMPLETED']], []), 'before production starts'),
    'Completed assignments are protected.'
);
$assert(
    str_contains(printflow_material_assignment_removal_safety_error($assignment, $preProductionJobs, [['id' => 88]]), 'stock-out exists'),
    'A linked inventory OUT movement prevents removal.'
);
$assert(
    str_contains(printflow_material_assignment_removal_safety_error(array_merge($assignment, ['deducted_at' => '2026-11-05 12:00:00']), $preProductionJobs, []), 'already been marked as deducted'),
    'A deducted timestamp prevents removal even if a ledger lookup is empty.'
);
$assert(
    str_contains(printflow_material_assignment_removal_safety_error($assignment, [], []), 'not linked to a production job'),
    'Assignments without a verifiable linked job fail closed.'
);

$service = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
$api = (string)file_get_contents(dirname(__DIR__) . '/admin/job_orders_api.php');
$staff = (string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
$removeStart = strpos($api, "case 'remove_material':");
$removeEnd = $removeStart === false ? false : strpos($api, "case 'change_item_create':", $removeStart);
$removeCase = $removeStart !== false && $removeEnd !== false ? substr($api, $removeStart, $removeEnd - $removeStart) : '';
$assert($removeCase !== '' && str_contains($removeCase, 'jo_api_require_staff_mutation()'), 'Removal retains POST, Staff/Admin role, and CSRF checks.');
$assert(str_contains($removeCase, 'jo_api_require_staff_order_branch')
    && str_contains($removeCase, 'printflow_order_in_branch')
    && !str_contains($removeCase, 'jo_api_require_staff_branch('), 'Removal checks branch access without the legacy helper that can backfill a missing job branch.');
$assert(str_contains($removeCase, 'JobOrderService::removeMaterial('), 'The API uses the guarded service correction path.');
$assert(str_contains($service, 'SELECT * FROM job_order_materials WHERE id = ? LIMIT 1 FOR UPDATE'), 'The exact assignment row is locked before correction.');
$assert(str_contains($service, "UPPER(ref_type) = 'JOB_ORDER'") && str_contains($service, "UPPER(direction) = 'OUT'"), 'The matching branch stock-out ledger is checked before removal.');
$assert(str_contains($service, 'INSERT INTO activity_logs') && str_contains($service, "'Production material assignment corrected'"), 'A correction audit snapshot is saved in the same transaction.');
$assert(str_contains($service, 'db_execute_affected_rows(') && str_contains($service, 'printflow_db_transaction_depth_pop($conn, false)'), 'Removal is conditional, transactional, and rolls back when its guard or audit fails.');
$assert(str_contains($staff, 'this.removeMaterial(assignment.id)') && !str_contains($staff, "this.showStaffAlert('Material Locked', 'Assigned materials can no longer be removed"), 'Custom Orders no longer blanket-locks saved assignments in the UI.');

echo "Production material removal safety tests passed.\n";
