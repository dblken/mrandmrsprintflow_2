<?php
/**
 * Read-only material-capacity checks for Counter Staff POS service lines.
 * This helper never reserves stock or writes inventory/assignment records.
 */

require_once __DIR__ . '/InventoryManager.php';
require_once __DIR__ . '/JobOrderService.php';
require_once __DIR__ . '/pos_quantity_validation.php';
require_once __DIR__ . '/pos_mugs_release_rule.php';

function printflow_pos_service_required_material_quantity($serviceQuantity, $multiplier): float
{
    $qty = printflow_pos_parse_positive_quantity($serviceQuantity);
    if (!is_numeric($multiplier) || !is_finite((float)$multiplier) || (float)$multiplier <= 0) {
        throw new InvalidArgumentException('A required material consumption ratio is not configured.');
    }
    $required = $qty * (float)$multiplier;
    if (!is_finite($required) || $required <= 0) {
        throw new InvalidArgumentException('The required material quantity could not be calculated.');
    }
    return $required;
}

function printflow_pos_service_stock_rules(int $serviceId, array $customization): array
{
    if ($serviceId <= 0) {
        throw new RuntimeException('The POS service could not be identified for stock validation.');
    }

    $serviceRows = db_query(
        'SELECT name, category FROM services WHERE service_id = ? LIMIT 1',
        'i',
        [$serviceId]
    );
    if ($serviceRows === false) {
        throw new RuntimeException('The POS service material rules could not be loaded.');
    }
    $service = $serviceRows[0] ?? null;
    if ($service === null) {
        $productRows = db_query(
            'SELECT name, category FROM products WHERE product_id = ? LIMIT 1',
            'i',
            [$serviceId]
        );
        if ($productRows === false) {
            throw new RuntimeException('The POS service material rules could not be loaded.');
        }
        $service = $productRows[0] ?? [];
    }
    // The owner confirmed this active service/material pair and 1:1 rule.
    // Resolve the live catalog item, then use the existing generic stock path.
    if ($serviceId === 63) {
        return printflow_pos_mugs_release_material_rule(
            $serviceId,
            (string)($service['name'] ?? ''),
            InventoryManager::getItem(37)
        ) ?? [];
    }


    $serviceType = JobOrderService::inferServiceTypeFromProduct(
        (string)($service['category'] ?? ''),
        (string)($service['name'] ?? '')
    );
    if ($serviceType === '') {
        $requestedType = trim((string)($customization['service_type'] ?? ''));
        if ($requestedType !== '') {
            $knownTypes = db_query(
                'SELECT DISTINCT service_type FROM service_material_rules WHERE service_type = ? LIMIT 1',
                's',
                [$requestedType]
            );
            if ($knownTypes === false) {
                throw new RuntimeException('The POS service material rules could not be loaded.');
            }
            if ($knownTypes !== []) $serviceType = $requestedType;
        }
    }
    if ($serviceType === '') {
        return [];
    }

    $errorCount = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
    $rules = db_query(
        "SELECT r.item_id, r.rule_type, r.qty_multiplier,
                i.name AS item_name, i.unit_of_measure, i.track_by_roll, i.status AS item_status
         FROM service_material_rules r
         LEFT JOIN inv_items i ON i.id = r.item_id
         WHERE r.service_type = ?
         ORDER BY r.item_id ASC",
        's',
        [$serviceType]
    );
    if ($rules === false || (function_exists('printflow_db_errors') && count(printflow_db_errors()) > $errorCount)) {
        throw new RuntimeException('The POS service material rules could not be loaded.');
    }

    $required = [];
    foreach ($rules ?: [] as $rule) {
        if (strtoupper(trim((string)($rule['rule_type'] ?? 'REQUIRED'))) !== 'REQUIRED') continue;
        $itemId = (int)($rule['item_id'] ?? 0);
        $multiplier = $rule['qty_multiplier'] ?? null;
        if ($itemId <= 0 || trim((string)($rule['item_status'] ?? '')) !== 'ACTIVE'
            || $multiplier === null || !is_numeric($multiplier) || (float)$multiplier <= 0) {
            throw new RuntimeException('A required material mapping for ' . $serviceType . ' is incomplete. Contact an administrator before adding this service.');
        }
        $required[] = [
            'item_id' => $itemId,
            'name' => (string)($rule['item_name'] ?? ('Material #' . $itemId)),
            'uom' => (string)($rule['unit_of_measure'] ?? 'pcs'),
            'track_by_roll' => !empty($rule['track_by_roll']),
            'multiplier' => (float)$multiplier,
            'service_type' => $serviceType,
        ];
    }

    // Existing service availability semantics treat a service without rules as
    // not inventory-tracked. Optional rules alone do not make a service tracked.
    return $required;
}

function printflow_pos_service_line_job_materials(array $line): array
{
    if (array_key_exists('pos_material_assignments', $line)) {
        $candidate = $line['pos_material_assignments'];
        if (!is_array($candidate) || count($candidate) > 100) {
            throw new RuntimeException('The Set Price material list is invalid.');
        }
        $resolved = [];
        foreach ($candidate as $material) {
            if (!is_array($material)) throw new RuntimeException('A Set Price material entry is invalid.');
            $itemId = (int)($material['item_id'] ?? 0);
            try {
                $quantity = printflow_pos_parse_positive_quantity($material['quantity'] ?? $material['qty'] ?? null);
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException('Material quantities must be positive whole numbers.');
            }
            $item = InventoryManager::getItem($itemId);
            if ($itemId <= 0 || !$item || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
                throw new RuntimeException('A selected POS material is no longer active. Refresh Set Price and try again.');
            }
            $resolved[] = [
                'item_id' => $itemId,
                'quantity' => (float)$quantity,
                'name' => (string)$item['name'],
                'uom' => (string)($item['unit_of_measure'] ?? ''),
            ];
            $metadata = $material['metadata'] ?? [];
            if (is_string($metadata)) $metadata = json_decode($metadata, true);
            if (is_array($metadata)) {
                $laminationId = (int)($metadata['lamination_item_id'] ?? 0);
                if ($laminationId > 0) {
                    try {
                        $laminationQuantity = printflow_pos_parse_positive_quantity($metadata['lamination_length_ft'] ?? null);
                    } catch (InvalidArgumentException $error) {
                        throw new RuntimeException('Lamination material quantity must be a positive whole number.');
                    }
                    $lamination = InventoryManager::getItem($laminationId);
                    if (!$lamination || strtoupper((string)($lamination['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
                        throw new RuntimeException('The selected lamination material is no longer active.');
                    }
                    $resolved[] = [
                        'item_id' => $laminationId,
                        'quantity' => (float)$laminationQuantity,
                        'name' => (string)$lamination['name'],
                        'uom' => (string)($lamination['unit_of_measure'] ?? ''),
                    ];
                }
            }
        }
        return $resolved;
    }

    $productId = (int)($line['product_id'] ?? $line['id'] ?? 0);
    $pendingOrderId = (int)($line['pending_order_id'] ?? 0);
    if ($pendingOrderId <= 0 && $productId > 0) {
        $pendingOrderId = (int)($_SESSION['pos_pending_orders'][$productId] ?? 0);
    }
    if ($pendingOrderId <= 0) return [];

    $pendingItemId = (int)($line['pending_order_item_id'] ?? 0);
    $sql = 'SELECT id, quantity, width_ft, height_ft FROM job_orders WHERE order_id = ?';
    $types = 'i';
    $params = [$pendingOrderId];
    if ($pendingItemId > 0 && db_table_has_column('job_orders', 'order_item_id')) {
        $sql .= ' AND order_item_id = ?';
        $types .= 'i';
        $params[] = $pendingItemId;
    }
    $jobs = db_query($sql . ' ORDER BY id ASC LIMIT 1', $types, $params);
    if ($jobs === false || $jobs === []) return [];
    $job = $jobs[0];
    $assignments = JobOrderService::getScopedMaterialsPublic((int)$job['id'], false, false);
    $resolved = [];
    foreach ($assignments as $assignment) {
        $itemId = (int)($assignment['item_id'] ?? 0);
        if ($itemId <= 0 || trim((string)($assignment['deducted_at'] ?? '')) !== '') continue;
        $metadata = $assignment['metadata'] ?? null;
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        if (is_array($metadata) && ($metadata['inventory_skip_reason'] ?? '') === 'superseded_by_wider_roll_material') continue;
        $item = InventoryManager::getItem($itemId);
        if (!$item || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
            throw new RuntimeException('An assigned POS material is no longer available. Reopen Set Price and review the assignment.');
        }
        $quantity = !empty($item['track_by_roll'])
            ? JobOrderService::resolveRollMaterialLengthFt($assignment, $job)
            : (float)($assignment['quantity'] ?? 0);
        if ($quantity > 0) {
            $resolved[] = ['item_id' => $itemId, 'quantity' => $quantity, 'name' => (string)$item['name'], 'uom' => (string)($item['unit_of_measure'] ?? '')];
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $laminationId = (int)($metadata['lamination_item_id'] ?? 0);
        $laminationQty = (float)($metadata['lamination_length_ft'] ?? 0);
        if ($laminationId > 0 && $laminationQty > 0) {
            $lamination = InventoryManager::getItem($laminationId);
            if (!$lamination) throw new RuntimeException('An assigned POS lamination material is no longer available.');
            $resolved[] = ['item_id' => $laminationId, 'quantity' => $laminationQty, 'name' => (string)$lamination['name'], 'uom' => (string)($lamination['unit_of_measure'] ?? '')];
        }
    }
    return $resolved;
}

/**
 * Validate combined configured minimum material demand across POS service lines.
 * Assigned materials replace their mapped minimum for the same item and optional
 * assigned materials are included. Returns a structured read-only result.
 */
function printflow_pos_validate_service_stock(array $lines, int $branchId): array
{
    if ($branchId <= 0) throw new RuntimeException('The POS branch could not be identified for stock validation.');
    $demand = [];
    $tracked = false;
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
        $isService = !empty($line['is_service'])
            || !empty($customization['service_id'])
            || !empty($customization['service_type']);
        if (!$isService) continue;
        $serviceId = (int)($customization['service_id'] ?? $line['product_id'] ?? $line['id'] ?? 0);
        $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
        $rules = printflow_pos_service_stock_rules($serviceId, $customization);
        if ($rules !== []) $tracked = true;

        $lineMinimums = [];
        foreach ($rules as $rule) {
            try {
                $required = printflow_pos_service_required_material_quantity($quantity, $rule['multiplier']);
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException('A required material quantity could not be calculated for this POS service.');
            }
            $itemId = (int)$rule['item_id'];
            $lineMinimums[$itemId] = ['quantity' => $required, 'name' => $rule['name'], 'uom' => $rule['uom']];
        }

        $assigned = printflow_pos_service_line_job_materials($line);
        $assignedById = [];
        foreach ($assigned as $material) {
            $itemId = (int)$material['item_id'];
            $assignedById[$itemId] = ($assignedById[$itemId] ?? 0.0) + (float)$material['quantity'];
            if (!isset($lineMinimums[$itemId])) {
                $lineMinimums[$itemId] = ['quantity' => 0.0, 'name' => $material['name'], 'uom' => $material['uom']];
            }
        }

        if (array_key_exists('pos_material_assignments', $line)) {
            foreach ($rules as $rule) {
                $itemId = (int)$rule['item_id'];
                $minimum = (float)$lineMinimums[$itemId]['quantity'];
                if (($assignedById[$itemId] ?? 0) + 0.000001 < $minimum) {
                    throw new RuntimeException(
                        $rule['name'] . ' material quantity is below the required minimum of '
                        . rtrim(rtrim(number_format($minimum, 3, '.', ''), '0'), '.') . ' '
                        . ($rule['uom'] !== '' ? $rule['uom'] : 'units') . '.'
                    );
                }
            }
        }

        foreach ($lineMinimums as $itemId => $minimum) {
            $lineQty = max((float)$minimum['quantity'], (float)($assignedById[$itemId] ?? 0));
            if ($lineQty <= 0) continue;
            if (!isset($demand[$itemId])) {
                $demand[$itemId] = ['quantity' => 0.0, 'name' => $minimum['name'], 'uom' => $minimum['uom']];
            }
            $demand[$itemId]['quantity'] += $lineQty;
        }
    }

    $issues = [];
    $materials = [];
    foreach ($demand as $itemId => $required) {
        $item = InventoryManager::getItem((int)$itemId);
        if (!$item || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
            throw new RuntimeException('A required POS service material is no longer active. Contact an administrator before adding this service.');
        }
        $available = (float)InventoryManager::getStockOnHand((int)$itemId, $branchId);
        $entry = [
            'item_id' => (int)$itemId,
            'name' => (string)$item['name'],
            'uom' => (string)($item['unit_of_measure'] ?? ''),
            'required' => (float)$required['quantity'],
            'available' => $available,
        ];
        $materials[] = $entry;
        if ((float)$required['quantity'] > $available) $issues[] = $entry;
    }
    return ['ok' => $issues === [], 'tracked' => $tracked || $demand !== [], 'materials' => $materials, 'issues' => $issues];
}

