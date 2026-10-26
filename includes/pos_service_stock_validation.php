<?php
/**
 * Read-only material-capacity checks for Counter Staff POS service lines.
 * This helper never reserves stock or writes inventory/assignment records.
 */

require_once __DIR__ . '/InventoryManager.php';
require_once __DIR__ . '/JobOrderService.php';
require_once __DIR__ . '/pos_quantity_validation.php';
require_once __DIR__ . '/pos_mugs_release_rule.php';
require_once __DIR__ . '/tarpaulin_material_calculation.php';

/** Aggregate demand without allowing a malformed Tarpaulin length to pass. */
function printflow_pos_service_stock_add_demand(array &$demand, int $itemId, float $quantity, string $name, string $uom): void
{
    if ($itemId <= 0 || !is_finite($quantity) || $quantity <= 0) {
        throw new RuntimeException('The required POS service material quantity could not be accumulated.');
    }
    if (!isset($demand[$itemId])) $demand[$itemId] = ['quantity' => 0.0, 'name' => $name, 'uom' => $uom];
    $demand[$itemId]['quantity'] += $quantity;
}

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

/** Read open rolls without invoking InventoryManager's schema-ensure helper. */
function printflow_pos_tarpaulin_open_rolls(int $itemId, int $branchId): array
{
    if ($itemId <= 0 || $branchId <= 0 || !function_exists('db_table_has_column')
        || !db_table_has_column('inv_rolls', 'branch_id')) {
        throw new RuntimeException('Branch-scoped Tarpaulin roll inventory is unavailable; no schema changes were attempted.');
    }
    if (InventoryManager::isMainBranch($branchId)) {
        $branchSql = ' AND (branch_id = ? OR branch_id IS NULL OR branch_id <= 0)';
    } else {
        $branchSql = ' AND branch_id = ?';
    }
    $rows = db_query(
        "SELECT id, roll_code, remaining_length_ft, received_at, status FROM inv_rolls WHERE item_id = ? AND status = 'OPEN' AND remaining_length_ft > 0{$branchSql} ORDER BY received_at ASC, id ASC",
        'ii',
        [$itemId, $branchId]
    );
    if (!is_array($rows)) throw new RuntimeException('Branch-scoped Tarpaulin rolls could not be read.');
    return $rows;
}

function printflow_pos_tarpaulin_candidates(int $branchId): array
{
    static $cache = [];
    if (isset($cache[$branchId])) return $cache[$branchId];
    $rows = db_query(
        "SELECT i.id, i.name, i.status, i.track_by_roll, i.unit_of_measure,
                c.name AS category_name
         FROM inv_items i
         LEFT JOIN inv_categories c ON c.id = i.category_id
         WHERE UPPER(TRIM(COALESCE(i.status, ''))) = 'ACTIVE'
           AND COALESCE(i.track_by_roll, 0) = 1
         ORDER BY i.name ASC, i.id ASC"
    );
    if ($rows === false) throw new RuntimeException('Tarpaulin roll materials could not be loaded.');
    $candidates = [];
    foreach ($rows as $row) {
        if (printflow_tarpaulin_parse_nominal_width($row) <= 0) continue;
        $row['open_rolls'] = printflow_pos_tarpaulin_open_rolls((int)$row['id'], $branchId);
        $row['available_ft'] = array_sum(array_map(static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)), $row['open_rolls']));
        $row['branch_id'] = $branchId;
        $candidates[] = $row;
    }
    $cache[$branchId] = $candidates;
    return $candidates;
}

function printflow_pos_tarpaulin_dimensions(array $customization): array
{
    return printflow_tarpaulin_normalize_dimensions($customization);
}

/** Resolve a first POS roll or verify a previously server-resolved cart selection. */
function printflow_pos_tarpaulin_selection(array $line, int $branchId, array $usedByItemRoll = []): array
{
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    [$width, $height] = printflow_pos_tarpaulin_dimensions($customization);
    $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
    $direction = strtolower(trim((string)($customization['joining_direction'] ?? 'automatic')));
    $allowanceMode = strtolower(trim((string)($customization['joining_allowance_mode'] ?? 'included')));
    $allowance = $customization['joining_allowance_ft'] ?? 0;
    if ($allowanceMode === 'included') $allowance = 0;
    $requestedBranch = (int)($customization['branch_id'] ?? 0);
    if ($requestedBranch > 0 && $requestedBranch !== $branchId) {
        throw new RuntimeException('Tarpaulin stock must be validated for the selected POS branch. Reopen the service and try again.');
    }
    $candidates = printflow_pos_tarpaulin_candidates($branchId);
    $stored = $customization['pos_tarpaulin_selection'] ?? null;
    if (is_array($stored) && ($stored['version'] ?? '') === PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION && !empty($stored['item_id'])) {
        $itemId = (int)$stored['item_id'];
        $candidate = null;
        foreach ($candidates as $row) if ((int)$row['id'] === $itemId) { $candidate = $row; break; }
        if ($candidate === null) throw new RuntimeException('The selected Tarpaulin roll is no longer active or available for this branch. Reopen the service and validate again.');
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        $plan = printflow_tarpaulin_panel_plan($width, $height, $quantity, $rollWidth, $direction, $allowanceMode, $allowance);
        if ((int)($stored['branch_id'] ?? 0) !== $branchId
            || abs((float)($stored['width_ft'] ?? 0) - $width) > 0.000001
            || abs((float)($stored['height_ft'] ?? 0) - $height) > 0.000001
            || (int)($stored['finished_quantity'] ?? 0) !== $quantity
            || strtolower((string)($stored['requested_direction'] ?? 'automatic')) !== $direction
            || strtolower((string)($stored['allowance_mode'] ?? 'included')) !== $allowanceMode
            || abs((float)($stored['allowance_ft_per_piece'] ?? 0) - (float)$allowance) > 0.000001) {
            throw new RuntimeException('Tarpaulin dimensions or joining options changed after stock validation. Revalidate before continuing.');
        }
        $allocation = printflow_tarpaulin_allocate_whole_panels((array)$candidate['open_rolls'], $plan, (array)($usedByItemRoll[$itemId] ?? []));
        if (empty($allocation['ok'])) {
            if (($allocation['reason'] ?? '') === 'search_limit') return ['ok' => false, 'reason' => 'search_limit', 'selection' => $stored];
            return ['ok' => false, 'reason' => 'insufficient', 'selection' => $stored, 'shortage' => [
                'id' => $itemId, 'name' => $candidate['name'], 'uom' => 'Ft', 'available_ft' => (float)$candidate['available_ft'],
                'required_ft' => (float)$plan['required_linear_ft'], 'calculation' => $plan, 'physical' => true,
            ]];
        }
        return ['ok' => true, 'selection' => array_merge($plan, ['item_id' => $itemId, 'item_name' => $candidate['name'], 'branch_id' => $branchId, 'requested_direction' => $direction, 'roll_allocations' => $allocation['allocations']])];
    }
    $resolved = printflow_tarpaulin_resolve_panel_candidate($candidates, $width, $height, $quantity, $direction, $allowanceMode, $allowance, $usedByItemRoll);
    if (!empty($resolved['selection'])) $resolved['selection']['requested_direction'] = $direction;
    if (empty($resolved['ok'])) return $resolved;
    return $resolved;
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
    if ($serviceId === 40) return [];

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
    $lineCustomization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    $lineServiceId = (int)($lineCustomization['service_id'] ?? $line['product_id'] ?? $line['id'] ?? 0);
    if (array_key_exists('pos_material_assignments', $line)) {
        $candidate = $line['pos_material_assignments'];
        if (!is_array($candidate) || count($candidate) > 100) {
            throw new RuntimeException('The Set Price material list is invalid.');
        }
        $resolved = [];
        foreach ($candidate as $material) {
            if (!is_array($material)) throw new RuntimeException('A Set Price material entry is invalid.');
            $itemId = (int)($material['item_id'] ?? 0);
            $metadata = $material['metadata'] ?? [];
            if (is_string($metadata)) $metadata = json_decode($metadata, true);
            $isVersionedTarpaulin = is_array($metadata)
                && printflow_tarpaulin_is_versioned_metadata($metadata);
            if ($isVersionedTarpaulin) {
                $pendingOrderId = (int)($line['pending_order_id'] ?? 0);
                $pendingItemId = (int)($line['pending_order_item_id'] ?? 0);
                if ($lineServiceId !== 40 || $pendingOrderId <= 0) {
                    throw new RuntimeException('A versioned Tarpaulin material assignment is valid only for its linked Tarpaulin POS job.');
                }
                $jobSql = 'SELECT id, order_id, order_item_id FROM job_orders WHERE order_id = ?';
                $jobTypes = 'i';
                $jobParams = [$pendingOrderId];
                if ($pendingItemId > 0 && db_table_has_column('job_orders', 'order_item_id')) {
                    $jobSql .= ' AND order_item_id = ?';
                    $jobTypes .= 'i';
                    $jobParams[] = $pendingItemId;
                }
                $linkedJobs = db_query($jobSql . ' ORDER BY id ASC LIMIT 2', $jobTypes, $jobParams);
                if (!is_array($linkedJobs) || count($linkedJobs) !== 1
                    || !printflow_tarpaulin_job_is_authoritative_service((int)$linkedJobs[0]['id'], $linkedJobs[0])) {
                    throw new RuntimeException('The versioned Tarpaulin material assignment has missing or ambiguous service identity.');
                }
            }
            if ($isVersionedTarpaulin) {
                try {
                    $quantity = printflow_tarpaulin_positive_number($material['quantity'] ?? $material['qty'] ?? null, 'Material length');
                } catch (InvalidArgumentException $error) {
                    throw new RuntimeException('Tarpaulin roll length must be a positive number of feet.');
                }
            } else {
                try {
                    $quantity = printflow_pos_parse_positive_quantity($material['quantity'] ?? $material['qty'] ?? null);
                } catch (InvalidArgumentException $error) {
                    throw new RuntimeException('Material quantities must be positive whole numbers.');
                }
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
    if ($lineServiceId === 40 && !printflow_tarpaulin_job_is_authoritative_service((int)$job['id'], $job)) {
        throw new RuntimeException('The Tarpaulin material assignment has missing or ambiguous service identity.');
    }
    $assignments = JobOrderService::getScopedMaterialsPublic((int)$job['id'], false, false);
    $resolved = [];
    foreach ($assignments as $assignment) {
        $itemId = (int)($assignment['item_id'] ?? 0);
        if ($itemId <= 0 || trim((string)($assignment['deducted_at'] ?? '')) !== '') continue;
        $metadata = $assignment['metadata'] ?? null;
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        if (is_array($metadata) && ($metadata['inventory_skip_reason'] ?? '') === 'superseded_by_wider_roll_material') continue;
        if (is_array($metadata) && printflow_tarpaulin_is_versioned_metadata($metadata)
            && ($lineServiceId !== 40 || !printflow_tarpaulin_job_is_authoritative_service((int)$job['id'], $job))) {
            throw new RuntimeException('A versioned Tarpaulin calculation is valid only for a uniquely linked Tarpaulin service job.');
        }
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
    $tarpaulinSelections = [];
    $tarpaulinItemIds = [];
    $tarpaulinPhysicalIssues = [];
    $tarpaulinPlans = [];
    foreach (array_values($lines) as $lineIndex => $line) {
        if (!is_array($line)) continue;
        $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
        $isService = !empty($line['is_service'])
            || !empty($customization['service_id'])
            || !empty($customization['service_type']);
        if (!$isService) continue;
        $lineProductId = (int)($line['product_id'] ?? $line['id'] ?? 0);
        if ($lineProductId === 40 && !empty($customization['service_id']) && (int)$customization['service_id'] !== 40) {
            throw new RuntimeException('Tarpaulin service identity changed after stock validation. Reopen the service and try again.');
        }
        $serviceId = $lineProductId === 40 ? 40 : (int)($customization['service_id'] ?? $lineProductId);
        $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
        $assigned = [];
        $hasAssignedMaterials = false;
        if ($serviceId === 40) {
            $assigned = printflow_pos_service_line_job_materials($line);
            $hasAssignedMaterials = $assigned !== [];
        }
        if ($serviceId === 40) {
            $resolved = printflow_pos_tarpaulin_selection($line, $branchId);
            if (empty($resolved['ok'])) {
                if (($resolved['reason'] ?? '') === 'insufficient') {
                    $tracked = true;
                    $shortage = $resolved['shortage'] ?? [];
                    $itemId = (int)($shortage['id'] ?? 0);
                    if ($itemId > 0) $tarpaulinItemIds[$itemId] = true;
                    $required = (float)($shortage['required_ft'] ?? $shortage['calculation']['required_linear_ft'] ?? 0);
                    $available = (float)($shortage['available_ft'] ?? $shortage['remaining_ft'] ?? 0);
                    $name = (string)($shortage['name'] ?? 'Tarpaulin roll');
                    $entry = ['item_id' => $itemId, 'name' => $name, 'uom' => 'Ft', 'required' => $required, 'available' => $available];
                    $tarpaulinPhysicalIssues[$itemId] = $entry;
                    $demand[$itemId] ??= ['quantity' => 0.0, 'name' => $name, 'uom' => 'Ft'];
                    $demand[$itemId]['quantity'] += $required;
                    $tarpaulinSelections[$lineIndex] = $resolved['selection'] ?? null;
                    continue;
                }
                if (($resolved['reason'] ?? '') === 'ambiguous') {
                    throw new RuntimeException('More than one Tarpaulin roll is equally suitable. Ask an administrator to clarify the material selection.');
                }
                if (($resolved['reason'] ?? '') === 'incompatible') {
                    throw new RuntimeException('No active Tarpaulin roll width can fit these dimensions, including rotation.');
                }
                if (($resolved['reason'] ?? '') === 'search_limit') {
                    throw new RuntimeException('Tarpaulin stock validation reached its safe calculation limit. Reduce or split the requested quantity and retry; stock was not changed.');
                }
                throw new RuntimeException('Tarpaulin material could not be safely resolved for the selected branch.');
            }
            $selection = $resolved['selection'];
            $tarpaulinSelections[$lineIndex] = $selection;
            $itemId = (int)$selection['item_id'];
            $tarpaulinItemIds[$itemId] = true;
            $assignedRollQuantity = 0.0;
            $selectedRollAssignmentFound = false;
            foreach ($assigned as $material) {
                $assignedItemId = (int)($material['item_id'] ?? 0);
                $assignedItem = $assignedItemId > 0 ? InventoryManager::getItem($assignedItemId) : null;
                if ($assignedItem && !empty($assignedItem['track_by_roll']) && $assignedItemId !== $itemId) {
                    throw new RuntimeException('Set Price changed the validated Tarpaulin roll. Reopen Set Price and use the server-selected roll.');
                }
                if ($assignedItemId === $itemId) {
                    $selectedRollAssignmentFound = true;
                    $assignedRollQuantity += (float)($material['quantity'] ?? 0);
                } elseif ($assignedItem && empty($assignedItem['track_by_roll'])) {
                    printflow_pos_service_stock_add_demand($demand, $assignedItemId, (float)($material['quantity'] ?? 0), (string)($material['name'] ?? $assignedItem['name']), (string)($material['uom'] ?? $assignedItem['unit_of_measure'] ?? ''));
                }
            }
            if ($hasAssignedMaterials && (!$selectedRollAssignmentFound
                || abs($assignedRollQuantity - (float)$selection['required_linear_ft']) > 0.001)) {
                throw new RuntimeException('The validated Tarpaulin roll is missing from the Set Price material assignment.');
            }
            $tarpaulinPlans[$lineIndex] = ['item_id' => $itemId, 'plan' => $selection];
            $item = InventoryManager::getItem($itemId);
            if (!$item || strtoupper((string)($item['status'] ?? '')) !== 'ACTIVE' || empty($item['track_by_roll'])) {
                throw new RuntimeException('The selected Tarpaulin roll is no longer active. Reopen the service and try again.');
            }
            $tracked = true;
            printflow_pos_service_stock_add_demand($demand, $itemId, (float)$selection['required_linear_ft'], (string)$item['name'], 'Ft');
            continue;
        }
        $rules = printflow_pos_service_stock_rules($serviceId, $customization);
        if ($rules !== []) $tracked = true;
        if ($serviceId !== 40) $assigned = printflow_pos_service_line_job_materials($line);

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

    if ($tarpaulinPlans !== []) {
        $rollsByItem = [];
        foreach (array_keys($tarpaulinItemIds) as $itemId) {
            $rollsByItem[(int)$itemId] = printflow_pos_tarpaulin_open_rolls((int)$itemId, $branchId);
        }
        $cartAllocation = printflow_tarpaulin_allocate_cart_panels($tarpaulinPlans, $rollsByItem);
        if (($cartAllocation['reason'] ?? '') === 'search_limit') {
            throw new RuntimeException('Combined Tarpaulin stock validation reached its safe calculation limit. Reduce or split the Tarpaulin cart and retry; stock was not changed.');
        }
        if (empty($cartAllocation['ok'])) {
            foreach ($cartAllocation['required_by_item'] ?? [] as $itemId => $requiredFt) {
                $item = InventoryManager::getItem((int)$itemId);
                $availableFt = array_sum(array_map(static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)), $rollsByItem[(int)$itemId] ?? []));
                $tarpaulinPhysicalIssues[(int)$itemId] = [
                    'item_id' => (int)$itemId, 'name' => (string)($item['name'] ?? 'Tarpaulin roll'),
                    'uom' => 'Ft', 'required' => (float)$requiredFt, 'available' => $availableFt,
                    'message' => 'The combined Tarpaulin panels cannot be cut as continuous pieces from the available open rolls in this branch.',
                ];
            }
        } else {
            foreach ($tarpaulinPlans as $lineIndex => $entry) {
                $key = (string)$lineIndex;
                $selection = $tarpaulinSelections[$lineIndex] ?? null;
                if (!is_array($selection)) continue;
                $selection['roll_allocations'] = $cartAllocation['allocations_by_line'][$key] ?? [];
                $tarpaulinSelections[$lineIndex] = $selection;
            }
        }
    }
    $issues = array_values($tarpaulinPhysicalIssues);
    $materials = [];
    foreach ($demand as $itemId => $required) {
        $item = InventoryManager::getItem((int)$itemId);
        if (!$item || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
            throw new RuntimeException('A required POS service material is no longer active. Contact an administrator before adding this service.');
        }
        if (isset($tarpaulinItemIds[(int)$itemId])) {
            $available = array_sum(array_map(
                static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)),
                printflow_pos_tarpaulin_open_rolls((int)$itemId, $branchId)
            ));
        } else {
            $available = (float)InventoryManager::getStockOnHand((int)$itemId, $branchId);
        }
        $entry = [
            'item_id' => (int)$itemId,
            'name' => (string)$item['name'],
            'uom' => (string)($item['unit_of_measure'] ?? ''),
            'required' => (float)$required['quantity'],
            'available' => $available,
        ];
        $materials[] = $entry;
        if (((float)$required['quantity'] > $available)) $issues[] = $entry;
    }
    return [
        'ok' => $issues === [],
        'tracked' => $tracked || $demand !== [],
        'materials' => $materials,
        'issues' => $issues,
        'tarpaulin_selections' => $tarpaulinSelections,
    ];
}
