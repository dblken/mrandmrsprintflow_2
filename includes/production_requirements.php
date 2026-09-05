<?php

if (!function_exists('printflow_material_ink_requirement_from_names')) {
    /**
     * Resolve ink guidance from manually assigned core materials.
     * null preserves the configured legacy service behavior for unknown materials.
     */
    function printflow_material_ink_requirement_from_names(array $materialNames): ?bool {
        $hasVerifiedNoInkCore = false;
        foreach ($materialNames as $rawName) {
            $name = strtoupper(trim((string)$rawName));
            $name = preg_replace('/[^A-Z0-9]+/', ' ', $name);
            $name = trim((string)preg_replace('/\s+/', ' ', $name));

            if (
                $name === 'MUG'
                || in_array($name, [
                    'NEXJET', 'PP STKR MATTE 98', 'HOLOGRAM', 'TRANSPARENT', 'STICKER PAPER',
                    'C2S BOARD', 'C2S SPECIAL PAPER', 'SUBLI PAPER', 'PHOTO PAPER',
                ], true)
                || preg_match('/^(?:\d+(?:\.\d+)? ?FT )?TARPAULIN(?: ROLL)?$/', $name)
            ) {
                return true;
            }

            if (
                preg_match('/^VINYL (?:BLACK|BLUE|GREEN|ORANGE|PINK|RED|WHITE|YELLOW)$/', $name)
                || preg_match('/^STICKER (?:BLACK|BLUE|GOLD|GREEN|RED|SILVER|WHITE|YELLOW)$/', $name)
                || preg_match('/^(?:AC|SP) (?:EURO|HOME|MC|NMC|PH|THAI)$/', $name)
                || $name === '3M REFLECTIVE'
                || in_array($name, ['SINTRA 3MM 32', 'SINTRA 5MM'], true)
                || in_array($name, [
                    'HOLOGRAPHIC', 'MATTE BLACK', 'MAROON', 'CREAM', 'BROWN', 'DARK BROWN',
                    'GOLD CHROME', 'GOLD', 'SILVER CHROME', 'SILVER', 'GRAY', 'RASPBERRY',
                    'LIGHT VIOLET', 'VIOLET', 'LIGHT PINK', 'YELLOW GREEN', 'VIVID GREEN',
                    'MINT GREEN', 'GOLDEN YELLOW', 'LIGHT YELLOW', 'LIGHT BLUE', 'ROYAL BLUE',
                    'RED', 'PINK', 'BLACK', 'WHITE', 'ORANGE', 'GREEN', 'YELLOW', 'BLUE',
                ], true)
            ) {
                $hasVerifiedNoInkCore = true;
            }
        }
        return $hasVerifiedNoInkCore ? false : null;
    }
}

if (!function_exists('printflow_job_requires_ink')) {
    function printflow_job_requires_ink(int $jobId): bool {
        if ($jobId > 0) {
            $assignedMaterials = db_query(
                "SELECT i.name
                 FROM job_order_materials jom
                 JOIN inv_items i ON i.id = jom.item_id
                 WHERE jom.job_order_id = ? AND jom.quantity > 0",
                'i',
                [$jobId]
            ) ?: [];
            $materialDecision = printflow_material_ink_requirement_from_names(
                array_column($assignedMaterials, 'name')
            );
            if ($materialDecision !== null) return $materialDecision;
        }

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
