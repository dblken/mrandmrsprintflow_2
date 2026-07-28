<?php

if (!function_exists('printflow_job_requires_ink')) {
    function printflow_job_requires_ink(int $jobId): bool {
        if ($jobId <= 0 || !db_table_has_column('services', 'requires_ink')) {
            return true;
        }

        $rows = db_query(
            "SELECT s.requires_ink
             FROM job_orders jo
             JOIN services s
               ON LOWER(TRIM(s.name)) IN (LOWER(TRIM(jo.service_type)), LOWER(TRIM(jo.job_title)))
             WHERE jo.id = ?
             ORDER BY (LOWER(TRIM(s.name)) = LOWER(TRIM(jo.service_type))) DESC
             LIMIT 1",
            'i',
            [$jobId]
        );
        if (!empty($rows)) {
            return (int)$rows[0]['requires_ink'] === 1;
        }

        // Existing services remain ink-required until explicitly configured otherwise.
        return true;
    }
}

if (!function_exists('printflow_job_production_assignment_errors')) {
    function printflow_job_production_assignment_errors(int $jobId): array {
        if ($jobId <= 0) return ['production' => 'A valid production job is required.'];

        $errors = [];
        $materials = db_query(
            'SELECT COUNT(*) AS total FROM job_order_materials WHERE job_order_id = ? AND quantity > 0',
            'i',
            [$jobId]
        );
        if ((int)($materials[0]['total'] ?? 0) <= 0) {
            $errors['material'] = 'Please select and add a material.';
        }

        if (printflow_job_requires_ink($jobId)) {
            $inks = db_query(
                'SELECT COUNT(*) AS total FROM job_order_ink_usage WHERE job_order_id = ? AND quantity_used > 0',
                'i',
                [$jobId]
            );
            if ((int)($inks[0]['total'] ?? 0) <= 0) {
                $errors['ink_consumption'] = 'Please select an ink set and enter the required ink consumption.';
            }
        }
        return $errors;
    }
}
