<?php
declare(strict_types=1);

/**
 * Fail-closed policy for correcting an assigned material before production.
 * The caller must separately lock and verify the assignment, linked jobs, and
 * inventory ledger rows inside the same transaction as the audit and removal.
 */
function printflow_material_assignment_removal_safety_error(array $assignment, array $linkedJobs, array $ledgerRows): string
{
    $deductedAt = trim((string)($assignment['deducted_at'] ?? ''));
    if ($deductedAt !== '' && $deductedAt !== '0000-00-00 00:00:00') {
        return 'This material has already been marked as deducted. Use the authorized inventory correction workflow.';
    }
    if ($linkedJobs === []) {
        return 'This assignment is not linked to a production job, so it cannot be safely corrected here.';
    }

    $editableStatuses = ['PENDING', 'APPROVED', 'TO_PAY', 'PAYMENT_CONFIRMED'];
    foreach ($linkedJobs as $job) {
        $status = strtoupper(trim((string)($job['status'] ?? '')));
        $status = str_replace(['–', '-'], '_', $status);
        $status = trim((string)preg_replace('/\s+/', '_', $status), '_');
        if (!in_array($status, $editableStatuses, true)) {
            $label = $status !== '' ? str_replace('_', ' ', strtolower($status)) : 'in an unknown state';
            return 'Materials can only be corrected before production starts. This job is ' . $label . '.';
        }
    }
    if ($ledgerRows !== []) {
        return 'A matching inventory stock-out exists for this job and material. Use the authorized inventory correction workflow.';
    }
    return '';
}
