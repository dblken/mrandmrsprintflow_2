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
require_once __DIR__ . '/pos_tarpaulin_release_rule.php';
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
        $row['open_rolls'] = printflow_pos_tarpaulin_open_rolls($itemId, $branchId);
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
 * Resolve the narrowest mapped Tarpaulin roll that fits customer width/height.
 * New POS orders use linear_ft_v1 (no joining/seam fields). Historical
 * panel_joining_v2 cart selections remain readable for backward compatibility.
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

    $resolved = printflow_tarpaulin_resolve_candidate($candidates, $width, $height, $quantity, $usedByItemDemand);
    if (($resolved['reason'] ?? '') === 'incompatible') {
        return [
            'ok' => false,
            'reason' => 'incompatible',
            'selection' => null,
            'shortage' => [
                'message' => 'No active Tarpaulin roll width can fit these dimensions, including rotation.',
                'name' => 'Tarpaulin',
                'uom' => 'Ft',
                'required' => 0,
                'available' => 0,
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
                'required' => 0,
                'available' => 0,
            ],
        ];
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

        if ($serviceId === 40 && !array_key_exists('pos_material_assignments', $line)) {
            // Tarpaulin POS still resolves the narrowest mapped roll from
            // customer Width/Height before Add to Order. Exact saved quantity
            // remains staff Actual Usage in Production Assignment.
            $demandByItem = [];
            foreach ($tarpaulinSelections as $priorSelection) {
                if (!is_array($priorSelection)) continue;
                $priorId = (int)($priorSelection['item_id'] ?? 0);
                if ($priorId <= 0) continue;
                $demandByItem[$priorId] = ($demandByItem[$priorId] ?? 0.0)
                    + (float)($priorSelection['required_linear_ft'] ?? 0);
            }
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
            $itemId = (int)($selection['item_id'] ?? 0);
            if ($itemId > 0) {
                $tarpaulinItemIds[$itemId] = true;
                $tracked = true;
                printflow_pos_service_stock_add_demand(
                    $demand,
                    $itemId,
                    (float)($selection['required_linear_ft'] ?? 0),
                    (string)($selection['item_name'] ?? 'Tarpaulin roll'),
                    'Ft'
                );
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
