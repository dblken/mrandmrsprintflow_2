<?php
/**
 * POS service cart quantity changes: preserve confirmed Set Price state,
 * scale material demand with quantity, and keep layout fees one-time.
 */

require_once __DIR__ . '/service_design_fee.php';
require_once __DIR__ . '/pos_quantity_validation.php';
require_once __DIR__ . '/pos_mugs_release_rule.php';

if (!function_exists('printflow_pos_scale_amount_by_quantity_ratio')) {
    /** Scale a positive amount by toQty/fromQty using cent-scale integer math for money-safe callers. */
    function printflow_pos_scale_amount_by_quantity_ratio(float $amount, int $fromQty, int $toQty): float
    {
        if ($fromQty < 1 || $toQty < 1 || $toQty > 1000000 || !is_finite($amount) || $amount < 0) {
            throw new InvalidArgumentException('Quantity scale inputs are invalid.');
        }
        if ($fromQty === $toQty) {
            return $amount;
        }
        // Use rational scaling with high precision then round to 6 decimals so
        // measured materials (ft/m/l) and money callers can re-canonicalize.
        return round($amount * ($toQty / $fromQty), 6);
    }
}

if (!function_exists('printflow_pos_inventory_uom_is_whole_unit')) {
    function printflow_pos_inventory_uom_is_whole_unit(string $uom): bool
    {
        return in_array(strtolower(trim($uom)), [
            'pc', 'pcs', 'piece', 'pieces', 'sheet', 'sheets', 'roll', 'rolls',
            'unit', 'units', 'set', 'sets', 'bottle', 'bottles', 'pack', 'packs',
        ], true);
    }
}

if (!function_exists('printflow_pos_resolve_scaled_material_quantity')) {
    /**
     * Scale one assigned material for a cart/job quantity change.
     * Mugs (#63) Mug + Subli Paper follow the 1-per-order-qty rule; other
     * materials keep strict proportional scaling (whole units must stay whole).
     */
    function printflow_pos_resolve_scaled_material_quantity(
        float $currentQty,
        int $fromQty,
        int $toQty,
        array $item,
        int $serviceId = 0,
        int $itemId = 0
    ): float {
        $name = (string)($item['name'] ?? '');
        if ($serviceId === 63 && printflow_pos_mugs_is_quantity_linked_material($itemId, $name)) {
            $scaledQty = printflow_pos_mugs_scale_quantity_linked_usage($currentQty, $fromQty, $toQty);
            return printflow_pos_parse_positive_material_quantity($scaledQty, $item);
        }

        $scaledQty = printflow_pos_scale_amount_by_quantity_ratio($currentQty, $fromQty, $toQty);
        if (printflow_pos_inventory_uom_is_whole_unit((string)($item['unit_of_measure'] ?? ''))) {
            $nearest = (float)round($scaledQty);
            if ($nearest >= 1.0 && abs($scaledQty - $nearest) < 1.0e-6) {
                $scaledQty = $nearest;
            }
        }
        return printflow_pos_parse_positive_material_quantity($scaledQty, $item);
    }
}

if (!function_exists('printflow_pos_scale_material_assignment_rows')) {
    /**
     * Proportionally scale saved material quantities for a cart-line quantity change.
     * Keeps the same item_id / metadata identity; never substitutes materials.
     * When $serviceId is Mugs (63), Mug + Subli Paper use the 1:1 order-qty rule.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    function printflow_pos_scale_material_assignment_rows(array $rows, int $fromQty, int $toQty, int $serviceId = 0): array
    {
        if ($fromQty < 1 || $toQty < 1) {
            throw new InvalidArgumentException('Material scale quantities must be positive whole numbers.');
        }
        if ($fromQty === $toQty) {
            return array_values($rows);
        }
        $scaled = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('A material assignment entry is invalid.');
            }
            $itemId = (int)($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                throw new InvalidArgumentException('A material assignment is missing its inventory item.');
            }
            $rawQty = $row['quantity'] ?? $row['qty'] ?? null;
            if (!is_numeric($rawQty) || !is_finite((float)$rawQty) || (float)$rawQty <= 0) {
                throw new InvalidArgumentException('A material assignment quantity could not be scaled.');
            }
            $itemName = trim((string)($row['name'] ?? $row['item_name'] ?? ''));
            if ($serviceId === 63 && printflow_pos_mugs_is_quantity_linked_material($itemId, $itemName)) {
                $nextQty = printflow_pos_mugs_scale_quantity_linked_usage((float)$rawQty, $fromQty, $toQty);
            } else {
                $nextQty = printflow_pos_scale_amount_by_quantity_ratio((float)$rawQty, $fromQty, $toQty);
            }
            $copy = $row;
            $copy['quantity'] = $nextQty;
            $copy['qty'] = $nextQty;
            $metadata = $copy['metadata'] ?? [];
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            if (is_array($metadata)) {
                if (isset($metadata['material_usage']) && is_array($metadata['material_usage'])) {
                    $usage = $metadata['material_usage'];
                    if (isset($usage['actual']) && is_numeric($usage['actual'])) {
                        if ($serviceId === 63 && printflow_pos_mugs_is_quantity_linked_material($itemId, $itemName)) {
                            $usage['actual'] = printflow_pos_mugs_scale_quantity_linked_usage((float)$usage['actual'], $fromQty, $toQty);
                        } else {
                            $usage['actual'] = printflow_pos_scale_amount_by_quantity_ratio((float)$usage['actual'], $fromQty, $toQty);
                        }
                    }
                    $metadata['material_usage'] = $usage;
                }
                $copy['metadata'] = $metadata;
            }
            $scaled[] = $copy;
        }
        return $scaled;
    }
}

if (!function_exists('printflow_pos_confirmed_unit_cents_from_line_total')) {
    /** Recover the confirmed per-unit cents from a staff Set Price line total. */
    function printflow_pos_confirmed_unit_cents_from_line_total($lineTotal, $designFee, int $quantity): int
    {
        if ($quantity < 1 || $quantity > 1000000) {
            throw new InvalidArgumentException('Confirmed unit quantity is invalid.');
        }
        $totalCents = printflow_money_to_cents($lineTotal);
        $feeCents = printflow_money_to_cents($designFee);
        if ($feeCents < 0 || $totalCents < 0 || $totalCents < $feeCents) {
            throw new InvalidArgumentException('Confirmed line total is below the one-time design fee.');
        }
        $variableCents = $totalCents - $feeCents;
        if ($variableCents % $quantity !== 0) {
            // Allow non-divisible historical totals by distributing with banker's
            // rounding through integer division of the variable portion.
            return intdiv($variableCents, $quantity);
        }
        return intdiv($variableCents, $quantity);
    }
}

if (!function_exists('printflow_pos_apply_quantity_change_to_confirmed_service_pricing')) {
    /**
     * After authoritative unit/fee reprice, restore Set Price completion and
     * rebuild the confirmed line total for the new quantity.
     *
     * Without Layout fee stays once per line; per-unit charges follow new qty.
     */
    function printflow_pos_apply_quantity_change_to_confirmed_service_pricing(
        array &$item,
        int $fromQty,
        int $toQty,
        bool $wasPriceSet,
        $previousOverride,
        float $previousDesignFee
    ): void {
        if (!$wasPriceSet) {
            return;
        }
        $designFee = (float)($item['design_fee'] ?? 0);
        $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];

        if ($previousOverride !== null && $previousOverride !== '' && is_numeric($previousOverride) && $fromQty > 0) {
            $unitCents = printflow_pos_confirmed_unit_cents_from_line_total(
                (float)$previousOverride,
                $previousDesignFee,
                $fromQty
            );
        } else {
            $unitCents = printflow_money_to_cents($item['price'] ?? $custom['calculated_unit_price'] ?? 0);
        }
        $feeCents = printflow_money_to_cents($designFee);
        $totals = printflow_service_line_totals_from_cents($unitCents, $feeCents, $toQty);
        $lineTotal = printflow_money_from_cents((int)$totals['line_total_cents']);
        $unitPrice = printflow_money_from_cents($unitCents);

        $item['price_set'] = true;
        $item['line_total_override'] = round($lineTotal, 2);
        // Set Price services store the confirmed final line total in price.
        $item['price'] = round($lineTotal, 2);
        $item['estimated_price'] = round($lineTotal, 2);
        $item['design_fee'] = printflow_money_from_cents($feeCents);
        $custom['design_fee'] = number_format((float)$item['design_fee'], 2, '.', '');
        $custom['calculated_design_fee'] = $custom['design_fee'];
        $custom['calculated_unit_price'] = number_format($unitPrice, 2, '.', '');
        $custom['calculated_estimated_price'] = number_format($lineTotal, 2, '.', '');
        $item['customization'] = $custom;
    }
}

if (!function_exists('printflow_pos_resolve_linked_service_job_id')) {
    function printflow_pos_resolve_linked_service_job_id(array $item): int
    {
        $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        $productId = (int)($item['product_id'] ?? 0);
        $orderId = (int)($item['pending_order_id'] ?? 0);
        if ($orderId <= 0 && $productId > 0 && isset($_SESSION['pos_pending_orders'][$productId])) {
            $orderId = (int)$_SESSION['pos_pending_orders'][$productId];
        }
        $orderItemId = (int)($item['pending_order_item_id'] ?? ($customization['pending_order_item_id'] ?? 0));
        if ($orderId <= 0) {
            return 0;
        }
        $jobSql = 'SELECT id FROM job_orders WHERE order_id = ?';
        $jobTypes = 'i';
        $jobParams = [$orderId];
        if ($orderItemId > 0 && function_exists('db_table_has_column') && db_table_has_column('job_orders', 'order_item_id')) {
            $jobSql .= ' AND order_item_id = ?';
            $jobTypes .= 'i';
            $jobParams[] = $orderItemId;
        }
        $jobs = db_query($jobSql . ' ORDER BY id ASC LIMIT 1', $jobTypes, $jobParams) ?: [];
        return (int)($jobs[0]['id'] ?? 0);
    }
}

if (!function_exists('printflow_pos_snapshot_job_quantity_materials')) {
    /** @return array{quantity:int,order_item_id:int,order_item_quantity:?int,materials:list<array<string,mixed>>} */
    function printflow_pos_snapshot_job_quantity_materials(int $jobId): array
    {
        $jobs = db_query(
            'SELECT id, quantity, order_item_id FROM job_orders WHERE id = ? LIMIT 1',
            'i',
            [$jobId]
        ) ?: [];
        if ($jobs === []) {
            throw new RuntimeException('The linked service job could not be found for the quantity update.');
        }
        $job = $jobs[0];
        $orderItemId = (int)($job['order_item_id'] ?? 0);
        $orderItemQuantity = null;
        if ($orderItemId > 0) {
            $items = db_query(
                'SELECT quantity FROM order_items WHERE order_item_id = ? LIMIT 1',
                'i',
                [$orderItemId]
            ) ?: [];
            if ($items !== []) {
                $orderItemQuantity = (int)($items[0]['quantity'] ?? 0);
            }
        }
        $materials = db_query(
            'SELECT id, item_id, quantity, uom, computed_required_length_ft, metadata, deducted_at
             FROM job_order_materials
             WHERE job_order_id = ?
             ORDER BY id ASC',
            'i',
            [$jobId]
        ) ?: [];
        return [
            'quantity' => (int)($job['quantity'] ?? 0),
            'order_item_id' => $orderItemId,
            'order_item_quantity' => $orderItemQuantity,
            'materials' => array_values($materials),
        ];
    }
}

if (!function_exists('printflow_pos_restore_job_quantity_materials')) {
    function printflow_pos_restore_job_quantity_materials(int $jobId, array $snapshot): void
    {
        $quantity = (int)($snapshot['quantity'] ?? 0);
        if ($jobId <= 0 || $quantity < 1) {
            return;
        }
        db_execute('UPDATE job_orders SET quantity = ? WHERE id = ?', 'ii', [$quantity, $jobId]);
        $orderItemId = (int)($snapshot['order_item_id'] ?? 0);
        $orderItemQuantity = $snapshot['order_item_quantity'] ?? null;
        if ($orderItemId > 0 && $orderItemQuantity !== null && (int)$orderItemQuantity > 0) {
            db_execute(
                'UPDATE order_items SET quantity = ? WHERE order_item_id = ?',
                'ii',
                [(int)$orderItemQuantity, $orderItemId]
            );
        }
        foreach ((array)($snapshot['materials'] ?? []) as $material) {
            if (!is_array($material)) {
                continue;
            }
            $materialId = (int)($material['id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }
            $metadata = $material['metadata'] ?? null;
            if (is_array($metadata)) {
                $metadata = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            db_execute(
                'UPDATE job_order_materials
                 SET quantity = ?, computed_required_length_ft = ?, metadata = ?
                 WHERE id = ? AND job_order_id = ?',
                'ddsii',
                [
                    (float)($material['quantity'] ?? 0),
                    (float)($material['computed_required_length_ft'] ?? 0),
                    $metadata,
                    $materialId,
                    $jobId,
                ]
            );
        }
    }
}

if (!function_exists('printflow_pos_sync_job_quantity_and_scale_materials')) {
    /**
     * Update the linked draft job/order-item quantity and proportionally scale
     * undeducted material assignments. Returns cart-shaped assignment rows.
     * Pass $serviceId so Mugs (63) can apply Mug + Subli Paper 1:1 scaling.
     *
     * @return list<array{item_id:int,quantity:float,metadata:array}>
     */
    function printflow_pos_sync_job_quantity_and_scale_materials(int $jobId, int $fromQty, int $toQty, int $serviceId = 0): array
    {
        if ($jobId <= 0) {
            return [];
        }
        if ($fromQty < 1 || $toQty < 1) {
            throw new InvalidArgumentException('Job quantity scale inputs are invalid.');
        }
        $jobs = db_query(
            'SELECT id, quantity, order_item_id FROM job_orders WHERE id = ? LIMIT 1',
            'i',
            [$jobId]
        ) ?: [];
        if ($jobs === []) {
            throw new RuntimeException('The linked service job could not be found for the quantity update.');
        }
        $job = $jobs[0];
        $jobQty = (int)($job['quantity'] ?? 0);
        // Prefer the live job quantity as the material baseline when it already
        // matches the previous cart quantity (normal Set Price path).
        $baselineQty = ($jobQty > 0) ? $jobQty : $fromQty;
        if ($baselineQty !== $fromQty && $jobQty > 0) {
            // Cart and job drifted somehow; refuse rather than invent a ratio.
            throw new RuntimeException(
                'Service quantity changed after materials were assigned. Return to Set Price and update the material quantities.'
            );
        }

        $materials = db_query(
            'SELECT id, item_id, quantity, uom, computed_required_length_ft, metadata, deducted_at
             FROM job_order_materials
             WHERE job_order_id = ?
             ORDER BY id ASC',
            'i',
            [$jobId]
        ) ?: [];

        foreach ($materials as $material) {
            $stamp = trim((string)($material['deducted_at'] ?? ''));
            if ($stamp !== '' && $stamp !== '0000-00-00 00:00:00') {
                throw new RuntimeException(
                    'Materials for this service were already deducted. Quantity can no longer be changed from the cart.'
                );
            }
        }

        if (!db_execute('UPDATE job_orders SET quantity = ? WHERE id = ?', 'ii', [$toQty, $jobId])) {
            throw new RuntimeException('The service job quantity could not be updated.');
        }
        $orderItemId = (int)($job['order_item_id'] ?? 0);
        if ($orderItemId > 0) {
            db_execute(
                'UPDATE order_items SET quantity = ? WHERE order_item_id = ?',
                'ii',
                [$toQty, $orderItemId]
            );
        }

        $cartAssignments = [];
        foreach ($materials as $material) {
            $materialId = (int)($material['id'] ?? 0);
            $itemId = (int)($material['item_id'] ?? 0);
            if ($materialId <= 0 || $itemId <= 0) {
                continue;
            }
            $item = class_exists('InventoryManager') ? InventoryManager::getItem($itemId) : null;
            if (!$item) {
                throw new RuntimeException('An assigned inventory material no longer exists.');
            }
            try {
                $scaledQty = printflow_pos_resolve_scaled_material_quantity(
                    (float)($material['quantity'] ?? 0),
                    $baselineQty,
                    $toQty,
                    $item,
                    $serviceId,
                    $itemId
                );
            } catch (InvalidArgumentException $error) {
                $name = trim((string)($item['name'] ?? ('Material #' . $itemId)));
                throw new RuntimeException(
                    $name . ' cannot be scaled to the new quantity while staying in its inventory unit. Open Set Price and enter the actual usage for the new quantity.'
                );
            }
            $scaledComputed = printflow_pos_scale_amount_by_quantity_ratio(
                (float)($material['computed_required_length_ft'] ?? 0),
                $baselineQty,
                $toQty
            );
            $metadata = $material['metadata'] ?? null;
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($metadata)) {
                $metadata = [];
            }
            if (isset($metadata['material_usage']) && is_array($metadata['material_usage'])) {
                $usage = $metadata['material_usage'];
                if (isset($usage['actual']) && is_numeric($usage['actual'])) {
                    $usage['actual'] = $scaledQty;
                }
                $metadata['material_usage'] = $usage;
            }
            $metaJson = $metadata !== []
                ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;
            if (db_execute(
                'UPDATE job_order_materials
                 SET quantity = ?, computed_required_length_ft = ?, metadata = ?
                 WHERE id = ? AND job_order_id = ?',
                'ddsii',
                [$scaledQty, $scaledComputed, $metaJson, $materialId, $jobId]
            ) === false) {
                throw new RuntimeException('Assigned material quantities could not be updated for the new service quantity.');
            }
            $cartAssignments[] = [
                'item_id' => $itemId,
                'quantity' => $scaledQty,
                'metadata' => $metadata,
            ];
        }
        return $cartAssignments;
    }
}
