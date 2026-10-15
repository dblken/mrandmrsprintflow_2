<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$jobs = (string) file_get_contents($root . '/includes/JobOrderService.php');
$mutation = (string) file_get_contents($root . '/includes/order_status_mutation.php');
$customizations = (string) file_get_contents($root . '/staff/customizations.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(
    str_contains($jobs, "UPDATE job_orders SET status = ? WHERE id = ?")
        && str_contains($jobs, '$resolvedMachineId')
        && !preg_match('/bind_param\([^)]+\$newStatus,\s*\$machineId\s*\?:/', $jobs),
    'Job status updates omit machine_id when none is assigned (avoids null bind_param failures)'
);
$expect(
    str_contains($jobs, 'status notification failed for job #'),
    'Completion must not fail when optional notifications throw'
);
$expect(
    str_contains($mutation, 'Failed to update linked customization status')
        && str_contains($mutation, 'Failed to persist'),
    'Completion errors surface actionable client messages instead of opaque 500s'
);
$expect(
    str_contains($customizations, 'storeFromJob[workflow]')
        && str_contains($customizations, "PENDING: 'Pending'"),
    'Staff completion sends store-order expected_status for optimistic locking'
);

if ($failures !== []) {
    fwrite(STDERR, "job_order_completion_status_persist_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "job_order_completion_status_persist_test passed.\n";
