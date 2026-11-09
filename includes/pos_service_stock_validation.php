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
require_once __DIR__ . '/pos_brochure_poster_early_stock.php';
require_once __DIR__ . '/pos_plates_release_rule.php';
require_once __DIR__ . '/pos_sintraboard_early_stock.php';
require_once __DIR__ . '/pos_tarpaulin_release_rule.php';
require_once __DIR__ . '/tarpaulin_material_calculation.php';
require_once __DIR__ . '/pos_sticker_laminate_early_stock.php';

/** Read open rolls without invoking InventoryManager's schema-ensure helper. */
function printflow_pos_tarpaulin_open_rolls(int $itemId, int $branchId): array
{
    if ($itemId <= 0 || $branchId <= 0 || !function_exists('db_table_has_column')
        || !db_table_has_column('inv_rolls', 'branch_id')) {
        throw new RuntimeException('Branch-scoped Tarpaulin roll inventory is unavailable; no schema changes were attempted.');
    }
    [$branchSql] = printflow_roll_branch_sql('branch_id', $branchId);
    $rows = db_query(
        "SELECT id, item_id, branch_id, roll_code, width_ft, total_length_ft, remaining_length_ft, received_at, status FROM inv_rolls WHERE item_id = ? AND status = 'OPEN' AND remaining_length_ft > 0{$branchSql} ORDER BY received_at ASC, id ASC",
        'ii',
        [$itemId, $branchId]
    );
    if (!is_array($rows)) throw new RuntimeException('Branch-scoped Tarpaulin rolls could not be read.');
    return $rows;
}

function printflow_pos_tarpaulin_mapped_item_ids(): array
{
    $registry = printflow_production_assignment_selection_registry()[40] ?? [];
    $ids = [];
    foreach ($registry as $itemId => $policy) {
        if (!is_array($policy)) continue;
        $id = (int)$itemId;
        if ($id > 0) $ids[$id] = $id;
    }
    return array_values($ids);
}

function printflow_pos_tarpaulin_candidates(int $branchId): array
{
    static $cache = [];
    if (isset($cache[$branchId])) return $cache[$branchId];
    $mappedIds = printflow_pos_tarpaulin_mapped_item_ids();
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
        $itemId = (int)($row['id'] ?? 0);
        if ($mappedIds !== [] && !in_array($itemId, $mappedIds, true)) continue;
        if (printflow_tarpaulin_parse_nominal_width($row) <= 0) continue;
        $nominalWidth = printflow_tarpaulin_parse_nominal_width($row);
        $row['open_rolls'] = [];
        $row['roll_width_issues'] = [];
        foreach (printflow_pos_tarpaulin_open_rolls($itemId, $branchId) as $roll) {
            $rawWidth = $roll['width_ft'] ?? null;
            $physicalWidth = is_numeric($rawWidth) ? (float)$rawWidth : 0.0;
            if (!is_finite($physicalWidth) || $physicalWidth <= 0 || abs($physicalWidth - $nominalWidth) > 0.01) {
                $row['roll_width_issues'][] = [
                    'roll_id' => (int)($roll['id'] ?? 0),
                    'width_ft' => $physicalWidth > 0 ? $physicalWidth : 'missing/invalid',
                    'expected_width_ft' => $nominalWidth,
                    'total_length_ft' => max(0.0, (float)($roll['total_length_ft'] ?? 0)),
                    'remaining_length_ft' => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)),
                    'branch_id' => (int)($roll['branch_id'] ?? 0),
                    'item_name' => (string)($row['name'] ?? 'Tarpaulin roll'),
                ];
                continue;
            }
            $row['open_rolls'][] = $roll;
        }
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

/**
 * Resolve the least-waste eligible Tarpaulin width against physical branch
 * rolls. Legacy linear_ft_v1, panel_joining_v2, and roll_fit_v1 selections
 * remain readable; new validations create roll_fit_v2 with an exact roll plan.
 */
function printflow_pos_tarpaulin_selection(array $line, int $branchId, array $usedByItemDemand = []): array
{
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    try {
        [$width, $height] = printflow_pos_tarpaulin_dimensions($customization);
        $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException($error->getMessage());
    }
    $requestedBranch = (int)($customization['branch_id'] ?? 0);
    if ($requestedBranch > 0 && $requestedBranch !== $branchId) {
        throw new RuntimeException('Tarpaulin stock must be validated for the selected POS branch. Reopen the service and try again.');
    }
    $candidates = printflow_pos_tarpaulin_candidates($branchId);
    $stored = $customization['pos_tarpaulin_selection'] ?? null;
    if (is_array($stored) && ($stored['version'] ?? '') === PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION) {
        if ((int)($stored['branch_id'] ?? 0) !== $branchId
            || abs((float)($stored['width_ft'] ?? 0) - $width) > 0.000001
            || abs((float)($stored['height_ft'] ?? 0) - $height) > 0.000001
            || (int)($stored['finished_quantity'] ?? 0) !== $quantity) {
            throw new RuntimeException('Tarpaulin dimensions, quantity, or branch changed after stock validation. Revalidate before continuing.');
        }
    }
    if (is_array($stored) && ($stored['version'] ?? '') === PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION && !empty($stored['item_id'])) {
        // Historical joining-based selections stay readable; new orders do not create them.
        $itemId = (int)$stored['item_id'];
        $candidate = null;
        foreach ($candidates as $row) if ((int)$row['id'] === $itemId) { $candidate = $row; break; }
        if ($candidate === null) throw new RuntimeException('The selected Tarpaulin roll is no longer active or available for this branch. Reopen the service and validate again.');
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        $direction = strtolower(trim((string)($stored['requested_direction'] ?? $customization['joining_direction'] ?? 'automatic')));
        $allowanceMode = strtolower(trim((string)($stored['allowance_mode'] ?? $customization['joining_allowance_mode'] ?? 'included')));
        $allowance = $stored['allowance_ft_per_piece'] ?? $customization['joining_allowance_ft'] ?? 0;
        if ($allowanceMode === 'included') $allowance = 0;
        $plan = printflow_tarpaulin_panel_plan($width, $height, $quantity, $rollWidth, $direction, $allowanceMode, $allowance);
        if ((int)($stored['branch_id'] ?? 0) !== $branchId
            || abs((float)($stored['width_ft'] ?? 0) - $width) > 0.000001
            || abs((float)($stored['height_ft'] ?? 0) - $height) > 0.000001
            || (int)($stored['finished_quantity'] ?? 0) !== $quantity) {
            throw new RuntimeException('Tarpaulin dimensions changed after stock validation. Revalidate before continuing.');
        }
        $demand = (array)$usedByItemDemand;
        $demandFt = (float)($demand[$itemId] ?? 0);
        $available = max(0.0, (float)$candidate['available_ft'] - $demandFt);
        if ($available + 1.0e-9 < (float)$plan['required_linear_ft']) {
            return ['ok' => false, 'reason' => 'insufficient', 'selection' => $stored, 'shortage' => [
                'id' => $itemId, 'name' => $candidate['name'], 'uom' => 'Ft', 'available_ft' => $available,
                'required_ft' => (float)$plan['required_linear_ft'], 'calculation' => $plan, 'physical' => true,
            ]];
        }
        return ['ok' => true, 'selection' => array_merge($plan, [
            'item_id' => $itemId,
            'item_name' => $candidate['name'],
            'branch_id' => $branchId,
            'version' => PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION,
        ])];
    }
    if (is_array($stored) && ($stored['version'] ?? '') === PRINTFLOW_TARPAULIN_CALCULATION_VERSION && !empty($stored['item_id'])) {
        $itemId = (int)$stored['item_id'];
        $candidate = null;
        foreach ($candidates as $row) if ((int)$row['id'] === $itemId) { $candidate = $row; break; }
        if ($candidate === null) throw new RuntimeException('The selected Tarpaulin roll is no longer active or available for this branch. Reopen the service and validate again.');
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        try {
            $calc = printflow_tarpaulin_calculate_linear_length($width, $height, $quantity, $rollWidth);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('No active Tarpaulin roll width can fit these dimensions, including rotation.');
        }
        if ((int)($stored['branch_id'] ?? 0) !== $branchId
            || abs((float)($stored['width_ft'] ?? 0) - $width) > 0.000001
            || abs((float)($stored['height_ft'] ?? 0) - $height) > 0.000001
            || (int)($stored['finished_quantity'] ?? 0) !== $quantity
            || abs((float)($stored['required_linear_ft'] ?? 0) - (float)$calc['required_linear_ft']) > 0.000001) {
            throw new RuntimeException('Tarpaulin dimensions changed after stock validation. Revalidate before continuing.');
        }
        $demandFt = (float)(($usedByItemDemand[$itemId] ?? 0));
        $available = max(0.0, (float)$candidate['available_ft'] - $demandFt);
        if ($available + 1.0e-9 < (float)$calc['required_linear_ft']) {
            return ['ok' => false, 'reason' => 'insufficient', 'selection' => $stored, 'shortage' => [
                'id' => $itemId, 'name' => $candidate['name'], 'uom' => 'Ft', 'available_ft' => $available,
                'required_ft' => (float)$calc['required_linear_ft'], 'calculation' => $calc, 'physical' => true,
            ]];
        }
        return ['ok' => true, 'selection' => array_merge($calc, [
            'item_id' => $itemId,
            'item_name' => (string)$candidate['name'],
            'branch_id' => $branchId,
        ])];
    }

    $usedByItemRoll = [];
    // Preserve the exact physical rolls consumed by earlier Tarpaulin cart
    // lines so each new candidate is checked against the same branch capacity.
    foreach ($customization['_pos_tarpaulin_prior_selections'] ?? [] as $prior) {
        if (!is_array($prior)) continue;
        foreach ((array)($prior['roll_allocations_by_item'] ?? []) as $itemId => $allocations) {
            foreach ((array)$allocations as $allocation) {
                $rollId = (int)($allocation['roll_id'] ?? 0);
                if ($rollId <= 0) continue;
                $usedByItemRoll[(int)$itemId][$rollId] = ($usedByItemRoll[(int)$itemId][$rollId] ?? 0.0) + (float)($allocation['length_ft'] ?? 0);
            }
        }
    }
    $demandPlan = printflow_tarpaulin_unallocated_demand_plan($width, $height, $quantity, $candidates);
    $resolved = printflow_tarpaulin_resolve_width_fit($candidates, $width, $height, $quantity, $usedByItemDemand, $usedByItemRoll);
    if (($resolved['reason'] ?? '') === 'incompatible') {
        return [
            'ok' => false,
            'reason' => 'incompatible',
            'selection' => null,
            'shortage' => [
                'message' => 'No active configured Tarpaulin roll width can cover the selected order width. Calculated demand: '
                    . rtrim(rtrim(number_format((float)$demandPlan['required_linear_ft'], 3, '.', ''), '0'), '.')
                    . ' ft; available eligible stock: 0 ft.',
                'name' => 'Tarpaulin',
                'uom' => 'Ft',
                'required' => (float)$demandPlan['required_linear_ft'],
                'available' => 0,
                'required_ft' => (float)$demandPlan['required_linear_ft'],
                'available_ft' => 0.0,
                'calculation' => $demandPlan,
            ],
        ];
    }
    if (($resolved['reason'] ?? '') === 'ambiguous') {
        return [
            'ok' => false,
            'reason' => 'ambiguous',
            'selection' => null,
            'shortage' => [
                'message' => 'Multiple Tarpaulin rolls fit these dimensions equally. Ask an administrator to review mapped roll widths.',
                'name' => 'Tarpaulin',
                'uom' => 'Ft',
                'required' => (float)$demandPlan['required_linear_ft'],
                'available' => 0,
                'required_ft' => (float)$demandPlan['required_linear_ft'],
                'available_ft' => 0.0,
                'calculation' => $demandPlan,
            ],
        ];
    }
    if (($resolved['reason'] ?? '') === 'search_limit') {
        return ['ok' => false, 'reason' => 'search_limit', 'selection' => null, 'shortage' => [
            'message' => 'The Tarpaulin roll-fit search reached its safe calculation limit. Reduce the quantity or ask an administrator to review the roll stock.',
            'name' => 'Tarpaulin', 'uom' => 'Ft', 'required' => (float)$demandPlan['required_linear_ft'],
            'available' => 0, 'required_ft' => (float)$demandPlan['required_linear_ft'], 'available_ft' => 0.0,
            'calculation' => $demandPlan,
        ]];
    }
    if (empty($resolved['ok'])) {
        $shortage = is_array($resolved['shortage'] ?? null) ? $resolved['shortage'] : [];
        return [
            'ok' => false,
            'reason' => (string)($resolved['reason'] ?? 'insufficient'),
            'selection' => null,
            'shortage' => [
                'id' => (int)($shortage['id'] ?? 0),
                'name' => (string)($shortage['name'] ?? 'Tarpaulin roll'),
                'uom' => 'Ft',
                'required' => (float)($shortage['required_ft'] ?? 0),
                'available' => (float)($shortage['available_ft'] ?? 0),
                'required_ft' => (float)($shortage['required_ft'] ?? 0),
                'available_ft' => (float)($shortage['available_ft'] ?? 0),
                'calculation' => $shortage['calculation'] ?? null,
                'physical' => true,
            ],
        ];
    }
    return $resolved;
}

function printflow_pos_service_stock_rules(int $serviceId, array $customization, bool $hasAssignedMaterials = false, int $branchId = 0, float $orderQuantity = 0, array $alreadyDemand = []): array
{
    // Early mapped demand is Mugs-only (service 63 → inventory item 37 at 1:1).
    // Other services keep calculation-free POS capture; exact usage is staff-entered later.
    if ($serviceId !== 63) {
        return [];
    }

    $serviceRows = db_query(
        'SELECT name FROM services WHERE service_id = ? LIMIT 1',
        'i',
        [$serviceId]
    );
    if ($serviceRows === false) {
        throw new RuntimeException('The POS service material rules could not be loaded.');
    }
    $serviceName = trim((string)($serviceRows[0]['name'] ?? ''));
    if ($serviceName === '') {
        $serviceName = trim((string)($customization['service_type'] ?? 'Mugs'));
    }

    return printflow_pos_mugs_release_material_rule(
        $serviceId,
        $serviceName,
        InventoryManager::getItem(37)
    ) ?? [];
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
    $stickerLaminatePlans = [];
    $stickerLaminateItemIds = [];
    $stickerLaminateRollsByItem = [];
    $stickerLaminateReconciliationByItem = [];
    $stickerLaminatePhysicalIssues = [];
    $materialSelectionsByLine = [];
    $availabilityProbes = [];
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

        if ($serviceId === 40 && !array_key_exists('pos_material_assignments', $line)) {
            // Tarpaulin POS checks the smallest feasible full-width panel
            // combination against branch-scoped physical rolls. Production
            // Assignment still records actual material usage; no stock is
            // reserved or deducted here.
            $demandByItem = [];
            $priorSelections = [];
            foreach ($tarpaulinSelections as $priorSelection) {
                if (!is_array($priorSelection)) continue;
                $priorSelections[] = $priorSelection;
                $priorDemand = (array)($priorSelection['required_by_item'] ?? []);
                if ($priorDemand === [] && (int)($priorSelection['item_id'] ?? 0) > 0) {
                    $priorDemand[(int)$priorSelection['item_id']] = (float)($priorSelection['required_linear_ft'] ?? 0);
                }
                foreach ($priorDemand as $priorId => $requiredFt) {
                    $priorId = (int)$priorId;
                    if ($priorId > 0) $demandByItem[$priorId] = ($demandByItem[$priorId] ?? 0.0) + (float)$requiredFt;
                }
            }
            $line['customization']['_pos_tarpaulin_prior_selections'] = $priorSelections;
            $resolved = printflow_pos_tarpaulin_selection($line, $branchId, $demandByItem);
            if (empty($resolved['ok'])) {
                $shortage = is_array($resolved['shortage'] ?? null) ? $resolved['shortage'] : [];
                if (trim((string)($shortage['message'] ?? '')) !== '') {
                    $tarpaulinPhysicalIssues[] = $shortage;
                } else {
                    $tarpaulinPhysicalIssues[] = [
                        'item_id' => (int)($shortage['id'] ?? 0),
                        'name' => (string)($shortage['name'] ?? 'Tarpaulin roll'),
                        'uom' => 'Ft',
                        'required' => (float)($shortage['required'] ?? $shortage['required_ft'] ?? 0),
                        'available' => (float)($shortage['available'] ?? $shortage['available_ft'] ?? 0),
                        'message' => trim((string)($shortage['message'] ?? '')),
                    ];
                }
                $tracked = true;
                continue;
            }
            $selection = is_array($resolved['selection'] ?? null) ? $resolved['selection'] : [];
            $tarpaulinSelections[$lineIndex] = $selection;
            $requiredByItem = (array)($selection['required_by_item'] ?? []);
            if ($requiredByItem === [] && (int)($selection['item_id'] ?? 0) > 0) {
                $requiredByItem[(int)$selection['item_id']] = (float)($selection['required_linear_ft'] ?? 0);
            }
            $itemNames = [];
            foreach ((array)($selection['material_items'] ?? []) as $selectedItemId => $selectedItem) {
                if (is_array($selectedItem)) $itemNames[(int)$selectedItemId] = (string)($selectedItem['name'] ?? 'Tarpaulin roll');
            }
            foreach ($requiredByItem as $itemId => $requiredFt) {
                $itemId = (int)$itemId;
                if ($itemId <= 0) continue;
                $tarpaulinItemIds[$itemId] = true;
                $tracked = true;
                printflow_pos_service_stock_add_demand($demand, $itemId, (float)$requiredFt, $itemNames[$itemId] ?? (string)($selection['item_name'] ?? 'Tarpaulin roll'), 'Ft');
            }
            continue;
        }

        if (!array_key_exists('pos_material_assignments', $line)) {
            // Mugs keeps an owner-confirmed early capacity check before Add to Order.
            if ($serviceId === 63) {
                $rules = printflow_pos_service_stock_rules($serviceId, $customization);
                foreach ($rules as $rule) {
                    try {
                        $required = printflow_pos_service_required_material_quantity($quantity, $rule['multiplier']);
                    } catch (InvalidArgumentException $error) {
                        throw new RuntimeException('A required Mug material quantity could not be calculated for this POS service.');
                    }
                    $tracked = true;
                    printflow_pos_service_stock_add_demand(
                        $demand,
                        (int)$rule['item_id'],
                        $required,
                        (string)$rule['name'],
                        (string)$rule['uom']
                    );
                }
                continue;
            }
            // Brochure / Poster Printing: early capacity against the preferred
            // mapped paper using Admin-approved AREA_SQFT demand. Exact Actual
            // Usage is still recorded later in Production Assignment.
            if (printflow_pos_brochure_poster_is_early_stock_service($serviceId)) {
                try {
                    $early = printflow_pos_brochure_poster_early_demand($line, $branchId);
                } catch (InvalidArgumentException $error) {
                    throw new RuntimeException($error->getMessage());
                }
                $tracked = true;
                printflow_pos_service_stock_add_demand(
                    $demand,
                    (int)$early['item_id'],
                    (float)$early['required'],
                    (string)$early['name'],
                    (string)$early['uom']
                );
                continue;
            }
            // Plates: early capacity for New Plate / Car And Euro / Address Plate
            // against the mapped blank (1 pcs per ordered quantity).
            if ($serviceId === 39) {
                try {
                    $plateDemand = printflow_pos_plates_early_demand($line, $branchId);
                } catch (InvalidArgumentException $error) {
                    throw new RuntimeException($error->getMessage());
                }
                if ($plateDemand !== null) {
                    $tracked = true;
                    printflow_pos_service_stock_add_demand(
                        $demand,
                        (int)$plateDemand['item_id'],
                        (float)$plateDemand['required'],
                        (string)$plateDemand['name'],
                        (string)$plateDemand['uom']
                    );
                }
                continue;
            }
            // Sintraboard: Board Thickness is the sole material choice. Resolve
            // only its stable item/rule; dimensions determine demand, and an
            // incomplete dimension form gets an availability-only probe.
            if ($serviceId === 71) {
                $sintraDemand = printflow_pos_sintraboard_early_demand($line, $branchId);
                if ($sintraDemand !== null) {
                    $tracked = true;
                    $itemId = (int)$sintraDemand['item_id'];
                    if (!empty($sintraDemand['available_only'])) {
                        $availabilityProbes[$itemId] = [
                            'name' => (string)$sintraDemand['name'],
                            'uom' => (string)$sintraDemand['uom'],
                        ];
                    } else {
                        printflow_pos_service_stock_add_demand(
                            $demand,
                            $itemId,
                            (float)$sintraDemand['required'],
                            (string)$sintraDemand['name'],
                            (string)$sintraDemand['uom']
                        );
                    }
                }
                continue;
            }
            // Stickers/Decals: validate only the laminate implied by the
            // configured finish/size choice. Colored/base sticker materials
            // remain a Production Assignment concern. This is a read-only
            // physical-roll fit check and does not reserve or deduct stock.
            if ($serviceId === 36) {
                $selection = printflow_pos_sticker_laminate_selection(
                    $line,
                    printflow_pos_service_dimension_configs($serviceId) ?: []
                );
                if ($selection !== null) {
                    $requestedBranch = (int)($customization['branch_id'] ?? 0);
                    if ($requestedBranch > 0 && $requestedBranch !== $branchId) {
                        throw new RuntimeException('Laminate stock must be validated for the selected POS branch. Reopen the service and try again.');
                    }
                    $itemId = (int)$selection['item_id'];
                    $item = InventoryManager::getItem($itemId);
                    if (!$item) throw new RuntimeException('The selected Sticker/Decals laminate is unavailable. Contact an administrator.');
                    $configuredRollWidthFt = printflow_pos_sticker_laminate_roll_width_ft($itemId, $item);
                    $physicalRollValidation = printflow_pos_sticker_laminate_uses_physical_rolls($item);
                    if ($physicalRollValidation) {
                        $branchRolls = printflow_pos_sticker_laminate_filter_branch_rolls(
                            printflow_pos_tarpaulin_open_rolls($itemId, $branchId),
                            $branchId
                        );
                        // Both item metadata and inv_rolls.width_ft are in feet.
                        // Only rolls matching the verified configured physical width
                        // are eligible; a conflicting roll width is a reconciliation
                        // issue, never a reason to silently reinterpret the roll.
                        $rollWidthFt = $configuredRollWidthFt;
                        $rolls = printflow_pos_sticker_laminate_filter_rolls_by_width($branchRolls, $rollWidthFt);
                        $displayBalance = max(0.0, (float)InventoryManager::getStockOnHand($itemId, $branchId));
                        $rollBalance = printflow_pos_sticker_laminate_roll_balance_ft($branchRolls);
                        $eligibleBalance = printflow_pos_sticker_laminate_roll_balance_ft($rolls);
                        $widthMismatches = printflow_pos_sticker_laminate_roll_width_mismatches(
                            $branchRolls,
                            $configuredRollWidthFt
                        );
                        if ($widthMismatches !== []
                            || abs($displayBalance - $rollBalance) > 0.001
                            || abs($displayBalance - $eligibleBalance) > 0.001) {
                            $stickerLaminateReconciliationByItem[$itemId] = [
                                'display_balance' => $displayBalance,
                                'branch_roll_balance' => $rollBalance,
                                'eligible_roll_balance' => $eligibleBalance,
                                'configured_width_ft' => $configuredRollWidthFt,
                                'width_mismatches' => $widthMismatches,
                            ];
                        }
                    } else {
                        $rollWidthFt = $configuredRollWidthFt;
                        // Standard FT inventory is ledger footage, not physical roll
                        // rows. Model that branch's aggregate footage as one capacity
                        // bin, while retaining the configured laminate width so every
                        // sticker panel must still physically fit the material.
                        $availableFt = max(0.0, (float)InventoryManager::getStockOnHand($itemId, $branchId));
                        $rolls = $availableFt > 0 ? [[
                            'id' => $itemId,
                            'branch_id' => $branchId,
                            'roll_code' => 'Branch FT stock',
                            'status' => 'OPEN',
                            'width_ft' => $rollWidthFt,
                            'remaining_length_ft' => $availableFt,
                            'received_at' => '',
                        ]] : [];
                    }
                    $plan = printflow_pos_sticker_laminate_panel_plan($selection, $rollWidthFt);
                    $stickerLaminateItemIds[$itemId] = true;
                    $stickerLaminateRollsByItem[$itemId] = $rolls;
                    $stickerLaminatePlans[$lineIndex] = [
                        'item_id' => $itemId,
                        'plan' => $plan,
                        'physical_roll_validation' => $physicalRollValidation,
                    ];
                    $tracked = true;
                }
                continue;
            }
            // Other non-Tarpaulin POS captures customer specifications only. Exact
            // material and quantity are checked after staff records actual use.
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
            // Require dedicated Mug #37 among assignments; allow other mapped
            // materials (Subli Paper, Box Mug, etc.) alongside it.
            printflow_pos_mugs_assert_dedicated_mug_assigned($assigned);
        }
        if ($serviceId === 40) {
            // Require at least one ACTIVE track_by_roll material among the posted
            // assignments. Optional non-roll extras (e.g. eyelets) may accompany it,
            // but a name-lookalike without roll tracking must not satisfy the check.
            $resolvedTarpaulinItems = [];
            foreach ($assigned as $material) {
                $item = InventoryManager::getItem((int)$material['item_id']);
                if (!$item) {
                    throw new RuntimeException('Select an active Tarpaulin roll before saving its actual usage.');
                }
                $resolvedTarpaulinItems[] = $item;
            }
            printflow_pos_tarpaulin_assert_resolved_active_roll($resolvedTarpaulinItems);
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

    $issues = array_values(array_merge($tarpaulinPhysicalIssues, $stickerLaminatePhysicalIssues));
    $materials = [];
    if ($stickerLaminatePlans !== []) {
        foreach (array_keys($stickerLaminateItemIds) as $itemId) {
            $itemId = (int)$itemId;
            $plansForItem = array_filter(
                $stickerLaminatePlans,
                static fn(array $entry): bool => (int)($entry['item_id'] ?? 0) === $itemId
            );
            $allocation = printflow_tarpaulin_allocate_cart_panels(
                $plansForItem,
                [$itemId => ($stickerLaminateRollsByItem[$itemId] ?? [])]
            );
            $requiredByItem = (array)($allocation['required_by_item'] ?? []);
            $item = InventoryManager::getItem($itemId);
            if (!$item || strtoupper((string)($item['status'] ?? '')) !== 'ACTIVE') {
                throw new RuntimeException('A selected Sticker/Decals laminate is no longer active. Contact an administrator.');
            }
            $rolls = $stickerLaminateRollsByItem[$itemId] ?? [];
            $available = isset($stickerLaminateReconciliationByItem[$itemId])
                ? (float)$stickerLaminateReconciliationByItem[$itemId]['display_balance']
                : printflow_pos_sticker_laminate_roll_balance_ft($rolls);
            $required = (float)($requiredByItem[$itemId] ?? 0.0);
            $entry = [
                'item_id' => $itemId,
                'name' => (string)$item['name'],
                'uom' => (string)($item['unit_of_measure'] ?? 'ft'),
                'required' => $required,
                'available' => $available,
                'physical_roll_validation' => !empty($plansForItem) && (bool)reset($plansForItem)['physical_roll_validation'],
            ];
            $materials[] = $entry;
            if (isset($stickerLaminateReconciliationByItem[$itemId])) {
                $reconciliation = $stickerLaminateReconciliationByItem[$itemId];
                $entry['inventory_discrepancy'] = true;
                $entry['message'] = printflow_pos_sticker_laminate_reconciliation_message(
                    $entry['name'],
                    $reconciliation
                );
                $issues[] = $entry;
            } elseif (empty($allocation['ok'])) {
                $entry['out_of_stock'] = $available <= 0;
                $entry['message'] = $available <= 0
                    ? $entry['name'] . ' is out of stock at this branch.'
                    : 'Insufficient ' . $entry['name'] . ' stock for the selected dimensions and quantity. Required: '
                        . rtrim(rtrim(number_format($required, 3, '.', ''), '0'), '.')
                        . ' ft; available: '
                        . rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.')
                        . ($entry['physical_roll_validation']
                            ? ' ft across rolls, but the required panels do not fit the remaining roll lengths.'
                            : ' ft in this branch.');
                $issues[] = $entry;
            }
        }
    }
    foreach (array_unique(array_merge(array_keys($demand), array_keys($availabilityProbes))) as $itemId) {
        $required = $demand[$itemId] ?? ['quantity' => 0.0, 'name' => $availabilityProbes[$itemId]['name'] ?? '', 'uom' => $availabilityProbes[$itemId]['uom'] ?? ''];
        $availableOnly = !isset($demand[$itemId]) && isset($availabilityProbes[$itemId]);
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
            'availability_only' => $availableOnly,
        ];
        $materials[] = $entry;
        if ($availableOnly ? $available <= 0 : printflow_pos_service_stock_exceeds_available((float)$required['quantity'], $available)) {
            $entry['out_of_stock'] = $available <= 0;
            $issues[] = $entry;
        }
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
