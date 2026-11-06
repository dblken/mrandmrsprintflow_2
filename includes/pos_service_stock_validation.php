<?php
/**
 * Read-only material-capacity checks for Counter Staff POS service lines.
 * This helper never reserves stock or writes inventory/assignment records.
 */

require_once __DIR__ . '/InventoryManager.php';
require_once __DIR__ . '/roll_branch_scope.php';
require_once __DIR__ . '/JobOrderService.php';
require_once __DIR__ . '/pos_quantity_validation.php';
require_once __DIR__ . '/pos_service_stock_helpers.php';
require_once __DIR__ . '/service_material_rule_identity.php';
require_once __DIR__ . '/pos_material_selection.php';
require_once __DIR__ . '/pos_service_dimensions.php';
require_once __DIR__ . '/pos_mugs_release_rule.php';
require_once __DIR__ . '/tarpaulin_material_calculation.php';

/** Read open rolls without invoking InventoryManager's schema-ensure helper. */
function printflow_pos_tarpaulin_open_rolls(int $itemId, int $branchId): array
{
    if ($itemId <= 0 || $branchId <= 0 || !function_exists('db_table_has_column')
        || !db_table_has_column('inv_rolls', 'branch_id')) {
        throw new RuntimeException('Branch-scoped Tarpaulin roll inventory is unavailable; no schema changes were attempted.');
    }
    [$branchSql] = printflow_roll_branch_sql('branch_id', $branchId);
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

function printflow_pos_service_stock_rules(int $serviceId, array $customization, bool $hasAssignedMaterials = false, int $branchId = 0, float $orderQuantity = 0, array $alreadyDemand = []): array
{
    // Current service material quantities are entered by staff in Production
    // Assignment. Keep this compatibility function calculation-free.
    return [];
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
            $item = InventoryManager::getItem($itemId);
            if ($itemId <= 0 || !$item || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
                throw new RuntimeException('A selected POS material is no longer active. Refresh Set Price and try again.');
            }
            try {
                $quantity = printflow_pos_parse_positive_material_quantity($material['quantity'] ?? $material['qty'] ?? null, $item);
            } catch (InvalidArgumentException $error) {
                $itemName = trim((string)($item['name'] ?? ''));
                $itemUom = trim((string)($item['unit_of_measure'] ?? ''));
                throw new RuntimeException(
                    ($itemName !== '' ? $itemName : 'Material #' . $itemId)
                    . ' quantity must match its inventory unit'
                    . ($itemUom !== '' ? ' (' . $itemUom . ')' : '')
                    . '.'
                );
            }
            $resolved[] = [
                'item_id' => $itemId,
                'quantity' => (float)$quantity,
                'name' => (string)$item['name'],
                'uom' => (string)($item['unit_of_measure'] ?? ''),
                'metadata' => is_array($metadata) ? $metadata : [],
            ];
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
        $quantity = (float)($assignment['quantity'] ?? 0);
        if ($quantity > 0) {
            $resolved[] = ['item_id' => $itemId, 'quantity' => $quantity, 'name' => (string)$item['name'], 'uom' => (string)($item['unit_of_measure'] ?? '')];
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
    $materialSelectionsByLine = [];
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

        if (!array_key_exists('pos_material_assignments', $line)) {
            // POS captures customer specifications only. Exact material and
            // quantity are checked after staff records actual use.
            continue;
        }
        $assignmentRows = is_array($line['pos_material_assignments'] ?? null) ? $line['pos_material_assignments'] : [];
        $assigned = printflow_pos_service_line_job_materials($line);
        $hasAssignedMaterials = $assigned !== [];
        $selectionRegistry = printflow_pos_material_selection_registry();
        if (isset($selectionRegistry[$serviceId])) {
            $selected = printflow_pos_material_selection_resolve_assignments($serviceId, $assignmentRows, $customization);
            $expectedNames = printflow_pos_material_selection_item_names($serviceId);
            foreach ($selected as $entry) {
                $itemId = (int)$entry['item_id'];
                $item = InventoryManager::getItem($itemId);
                if (!$item || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
                    throw new RuntimeException('A selected material is inactive or unavailable; refresh the assignment.');
                }
                if (isset($expectedNames[$itemId]) && strcasecmp(trim((string)$item['name']), $expectedNames[$itemId]) !== 0) {
                    throw new RuntimeException('A selected service material no longer matches its configured inventory item.');
                }
            }
        }
        if ($serviceId === 63) {
            foreach ($assigned as $material) {
                if ((int)$material['item_id'] !== 37) throw new RuntimeException('The dedicated Mug material must be assigned for this service.');
            }
        }
        if ($serviceId === 40) {
            foreach ($assigned as $material) {
                $item = InventoryManager::getItem((int)$material['item_id']);
                if (!$item || empty($item['track_by_roll']) || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
                    throw new RuntimeException('Select an active Tarpaulin roll before saving its actual usage.');
                }
            }
        }
        foreach ($assigned as $material) {
            $itemId = (int)$material['item_id'];
            $item = InventoryManager::getItem($itemId);
            if (!$item) throw new RuntimeException('A selected material is no longer available.');
            $tracked = true;
            printflow_pos_service_stock_add_demand($demand, $itemId, (float)$material['quantity'], (string)$item['name'], (string)($item['unit_of_measure'] ?? ''));
        }
        continue;
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
        if (printflow_pos_service_stock_exceeds_available((float)$required['quantity'], $available)) $issues[] = $entry;
    }
    return [
        'ok' => $issues === [],
        'tracked' => $tracked || $demand !== [],
        'materials' => $materials,
        'issues' => $issues,
        'tarpaulin_selections' => $tarpaulinSelections,
        'material_selections_by_line' => $materialSelectionsByLine,
    ];
}
