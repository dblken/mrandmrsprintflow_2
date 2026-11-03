<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/service_material_rule_approval.php';
require_once __DIR__ . '/../includes/pos_service_stock_helpers.php';
require_once __DIR__ . '/../includes/service_material_rule_display.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_auth();
if (!printflow_service_material_rule_admin_allowed(get_user_type())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only Admin accounts can manage service material multiplier approvals.']);
    exit;
}

function pf_service_rule_api_reply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

foreach (['qty_multiplier', 'qty_multiplier_approved', 'qty_multiplier_approved_by', 'qty_multiplier_approved_at', 'qty_multiplier_basis', 'qty_multiplier_approved_basis'] as $column) {
    if (!db_table_has_column('service_material_rules', $column, true)) {
        pf_service_rule_api_reply(503, ['success' => false, 'error' => 'Service material approval schema is incomplete. Apply the reviewed service material multiplier migration before using this page.']);
    }
}

global $conn;
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $activeServices = db_query("SELECT service_id, name, status FROM services WHERE status = 'Activated' ORDER BY service_id");
    if ($activeServices === false) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not load active services.']);
    $rows = db_query(
        "SELECT r.id, r.service_type,
                r.item_id, i.name AS material_name, i.unit_of_measure AS inventory_uom,
                r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
                r.qty_multiplier_approved_by, r.qty_multiplier_approved_at,
                r.qty_multiplier_basis, r.qty_multiplier_approved_basis
         FROM service_material_rules r
         INNER JOIN inv_items i ON i.id = r.item_id AND UPPER(TRIM(i.status)) = 'ACTIVE'
         ORDER BY r.id"
    );
    if ($rows === false) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not load service material rules.']);
    $mugsRule = null;
    foreach ($activeServices as $service) {
        if ((int)($service['service_id'] ?? 0) !== 63 || (string)($service['name'] ?? '') !== 'Mugs') continue;
        $mugRows = db_query("SELECT id, name, unit_of_measure, status, track_by_roll FROM inv_items WHERE id = 37 LIMIT 1");
        if ($mugRows === false) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not verify the dedicated Mugs POS rule.']);
        try {
            $mugsRule = printflow_pos_mugs_release_material_rule(63, 'Mugs', $mugRows[0] ?? null)[0] ?? null;
        } catch (Throwable $e) {
            error_log('Mugs display rule verification failed: ' . get_class($e));
            $mugsRule = null;
        }
        if ($mugsRule !== null) {
            $mugsRule['material_name'] = $mugsRule['name'];
            $mugsRule['inventory_uom'] = $mugsRule['uom'];
        }
        break;
    }
    $ruleRows = $rows ?: [];
    $ruleKeys = [];
    foreach ($ruleRows as $ruleRow) $ruleKeys[(string)$ruleRow['service_type'] . ':' . (int)$ruleRow['item_id']] = true;
    $activeById = [];
    foreach ($activeServices as $activeService) $activeById[(int)$activeService['service_id']] = $activeService;
    foreach (printflow_pos_material_selection_registry() as $serviceId => $policy) {
        $service = $activeById[(int)$serviceId] ?? null;
        if (!$service || strcasecmp((string)$service['name'], (string)(printflow_service_material_rule_display_registry()[$serviceId]['name'] ?? '')) !== 0) continue;
        $serviceType = printflow_pos_material_selection_service_type((int)$serviceId);
        $names = printflow_pos_material_selection_item_names((int)$serviceId);
        foreach ($policy as $itemId => $selection) {
            $key = $serviceType . ':' . (int)$itemId;
            if (isset($ruleKeys[$key])) continue;
            $itemRows = db_query('SELECT id, name, unit_of_measure, status FROM inv_items WHERE id = ? LIMIT 1', 'i', [(int)$itemId]);
            $item = $itemRows[0] ?? null;
            if (!$item || strtoupper(trim((string)$item['status'])) !== 'ACTIVE' || strcasecmp((string)$item['name'], (string)($names[$itemId] ?? '')) !== 0) continue;
            $ruleRows[] = [
                'id' => null, 'service_type' => $serviceType, 'item_id' => (int)$itemId,
                'material_name' => (string)$item['name'], 'inventory_uom' => (string)$item['unit_of_measure'],
                'rule_type' => in_array($selection['role'], ['optional', 'multi_optional'], true) ? 'OPTIONAL' : 'REQUIRED',
                'qty_multiplier' => null, 'qty_multiplier_approved' => 0,
                'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null,
                'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null,
                'configured' => false,
            ];
        }
    }
    $groups = printflow_service_material_rule_display_groups($activeServices ?: [], $ruleRows, $mugsRule);
    pf_service_rule_api_reply(200, ['success' => true, 'groups' => $groups]);
}

if ($method !== 'POST') pf_service_rule_api_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    pf_service_rule_api_reply(419, ['success' => false, 'error' => 'Invalid session token. Refresh the page and try again.']);
}

$ruleId = filter_var($_POST['rule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string)($_POST['action'] ?? '');
if ($action === 'create_rule') {
    $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$serviceId || !$itemId) pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A valid active service and approved material identity are required.']);
    $policy = printflow_pos_material_selection_registry()[(int)$serviceId][$itemId] ?? null;
    $expectedType = printflow_pos_material_selection_service_type((int)$serviceId);
    $expectedName = printflow_pos_material_selection_item_names((int)$serviceId)[$itemId] ?? null;
    $active = db_query("SELECT service_id, name, status FROM services WHERE service_id = ? LIMIT 1", 'i', [$serviceId]);
    $item = db_query('SELECT id, name, status FROM inv_items WHERE id = ? LIMIT 1', 'i', [$itemId]);
    if (!$policy || !$expectedType || !$expectedName || empty($active) || strtoupper((string)$active[0]['status']) !== 'ACTIVATED'
        || strcasecmp((string)$active[0]['name'], (string)(printflow_service_material_rule_display_registry()[$serviceId]['name'] ?? '')) !== 0
        || empty($item) || strtoupper((string)$item[0]['status']) !== 'ACTIVE' || strcasecmp((string)$item[0]['name'], $expectedName) !== 0) {
        pf_service_rule_api_reply(400, ['success' => false, 'error' => 'This service/material pair is not in the verified selection registry.']);
    }
    // Check every explicitly accepted service key, not just the canonical key.
    // This prevents a canonical rule from being created beside a legacy alias
    // row that the POS runtime would also resolve for this service.
    $acceptedTypes = printflow_pos_service_material_type_aliases((int)$serviceId);
    if ($acceptedTypes === []) $acceptedTypes = [$expectedType];
    $typePlaceholders = implode(',', array_fill(0, count($acceptedTypes), '?'));
    $existing = db_query(
        "SELECT id FROM service_material_rules WHERE service_type IN ({$typePlaceholders}) AND item_id = ? LIMIT 2",
        str_repeat('s', count($acceptedTypes)) . 'i',
        array_merge($acceptedTypes, [$itemId])
    );
    if ($existing === false || $existing !== []) pf_service_rule_api_reply(409, ['success' => false, 'error' => 'A rule already exists or could not be checked. Refresh before editing.']);
    $ruleType = in_array($policy['role'], ['optional', 'multi_optional'], true) ? 'OPTIONAL' : 'REQUIRED';
    $insert = $conn->prepare("INSERT INTO service_material_rules (service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis) VALUES (?, ?, ?, NULL, 0, NULL, NULL, NULL, NULL)");
    if (!$insert) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not prepare the new unapproved material rule.']);
    $insert->bind_param('sis', $expectedType, $itemId, $ruleType);
    if (!$insert->execute()) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not create the unapproved material rule.']);
    $newId = (int)$insert->insert_id;
    $insert->close();
    pf_service_rule_api_reply(200, ['success' => true, 'rule_id' => $newId, 'approved' => false]);
}
if (!$ruleId || !in_array($action, ['update_multiplier', 'approve'], true)) {
    pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A valid rule and action are required.']);
}

try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start approval transaction.');
    $select = $conn->prepare(
        "SELECT r.service_type, r.item_id, r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
                r.qty_multiplier_approved_by, r.qty_multiplier_approved_at, r.qty_multiplier_basis,
                r.qty_multiplier_approved_basis, i.name AS material_name,
                i.status AS item_status
         FROM service_material_rules r LEFT JOIN inv_items i ON i.id = r.item_id WHERE r.id = ? FOR UPDATE"
    );
    if (!$select) throw new RuntimeException('Could not inspect the selected service material rule.');
    $select->bind_param('i', $ruleId);
    if (!$select->execute()) throw new RuntimeException('Could not inspect the selected service material rule.');
    $result = $select->get_result();
    $rule = $result ? $result->fetch_assoc() : null;
    $select->close();
    if (!$rule) throw new InvalidArgumentException('The selected service material rule no longer exists.');
    if (strtoupper(trim((string)($rule['item_status'] ?? ''))) !== 'ACTIVE') {
        throw new InvalidArgumentException('Inactive inventory materials cannot be configured from this page.');
    }
    $activeServices = db_query("SELECT service_id, name, status FROM services WHERE status = 'Activated'");
    if ($activeServices === false || !printflow_service_material_rule_display_row_is_editable(
        (string)$rule['service_type'],
        (int)$rule['item_id'],
        $activeServices ?: [],
        (string)($rule['material_name'] ?? '')
    )) {
        throw new InvalidArgumentException('This rule is not mapped to an active service in the approved Admin display.');
    }
    $ruleType = strtoupper(trim((string)$rule['rule_type']));
    if ($action === 'approve') {
        $conflict = printflow_pos_known_service_material_conflict((string)$rule['service_type'], (string)($rule['material_name'] ?? ''));
        if ($conflict !== null) throw new InvalidArgumentException($conflict);
    }
    if ($action === 'update_multiplier') {
        $multiplier = printflow_service_material_parse_multiplier($_POST['qty_multiplier'] ?? null);
        if ($multiplier === null) throw new InvalidArgumentException('Enter a positive multiplier with up to two decimal places.');
        // The approval UI exposes the dimension rate only for the new selection-based services.
        $basis = 'AREA_SQFT';
        if (!in_array($rule['service_type'], ['Decals/Stickers (Print/Cut)', 'Plates', 'Poster Printing', 'Brochure', 'Sintraboard Standees'], true)) $basis = trim((string)($rule['qty_multiplier_basis'] ?? ''));
        if (in_array($rule['service_type'], ['Decals/Stickers (Print/Cut)', 'Plates', 'Poster Printing', 'Brochure', 'Sintraboard Standees'], true)
            && strtoupper(trim((string)($_POST['qty_multiplier_basis'] ?? ''))) !== 'AREA_SQFT') {
            throw new InvalidArgumentException('Set the consumption basis to inventory units per square foot before saving this multiplier.');
        }
        $current = $rule['qty_multiplier'] === null ? null : (string)$rule['qty_multiplier'];
        $state = printflow_service_material_multiplier_edit_state(
            $current,
            $multiplier,
            (int)$rule['qty_multiplier_approved'] === 1,
            $rule['qty_multiplier_approved_by'] === null ? null : (int)$rule['qty_multiplier_approved_by'],
            $rule['qty_multiplier_approved_at'] === null ? null : (string)$rule['qty_multiplier_approved_at']
        );
        $basisChanged = strtoupper((string)($rule['qty_multiplier_basis'] ?? '')) !== strtoupper($basis);
        $basisApprovalMismatch = strtoupper((string)($rule['qty_multiplier_approved_basis'] ?? '')) !== strtoupper($basis);
        if ($state['changed'] || $basisChanged || ($state['approved'] && $basisApprovalMismatch)) {
            $update = $conn->prepare(
                "UPDATE service_material_rules
                 SET qty_multiplier = ?, qty_multiplier_approved = 0,
                     qty_multiplier_approved_by = NULL, qty_multiplier_approved_at = NULL,
                     qty_multiplier_basis = ?, qty_multiplier_approved_basis = NULL
                 WHERE id = ?"
            );
            if (!$update) throw new RuntimeException('Could not save the multiplier.');
            $update->bind_param('ssi', $multiplier, $basis, $ruleId);
            if (!$update->execute()) throw new RuntimeException('Could not save the multiplier.');
            $update->close();
        }
        if (!$conn->commit()) throw new RuntimeException('Could not commit the multiplier change.');
        pf_service_rule_api_reply(200, ['success' => true, 'changed' => $state['changed'] || $basisChanged || $basisApprovalMismatch, 'approval_required' => ($state['changed'] || $basisChanged || $basisApprovalMismatch || !$state['approved'])]);
    }

    $multiplier = printflow_service_material_parse_multiplier((string)($rule['qty_multiplier'] ?? ''));
    if ($multiplier === null) throw new InvalidArgumentException('Set a valid positive multiplier before approving this selected-material rule.');
    if (in_array((string)$rule['service_type'], ['Decals/Stickers (Print/Cut)', 'Plates', 'Poster Printing', 'Brochure', 'Sintraboard Standees'], true)
        && (strtoupper(trim((string)($rule['qty_multiplier_basis'] ?? ''))) !== 'AREA_SQFT'
            || strtoupper(trim((string)($_POST['qty_multiplier_basis'] ?? 'AREA_SQFT'))) !== 'AREA_SQFT')) {
        throw new InvalidArgumentException('Save the inventory-units-per-square-foot basis before approving this material.');
    }
    $adminId = (int)get_user_id();
    if ($adminId <= 0) throw new RuntimeException('The authenticated Admin ID is unavailable; approval was not recorded.');
    $approve = $conn->prepare(
        "UPDATE service_material_rules
         SET qty_multiplier_approved = 1, qty_multiplier_approved_by = ?, qty_multiplier_approved_at = NOW(),
             qty_multiplier_approved_basis = qty_multiplier_basis
         WHERE id = ?"
    );
    if (!$approve) throw new RuntimeException('Could not prepare the approval record.');
    $approve->bind_param('ii', $adminId, $ruleId);
    if (!$approve->execute()) throw new RuntimeException('Approval was not recorded; refresh the rule and retry.');
    $approve->close();
    if (!$conn->commit()) throw new RuntimeException('Could not commit the approval record.');
    pf_service_rule_api_reply(200, ['success' => true, 'approved_by' => $adminId]);
} catch (InvalidArgumentException $e) {
    @$conn->rollback();
    pf_service_rule_api_reply(400, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    @$conn->rollback();
    error_log('Service material rule approval failed: ' . get_class($e));
    pf_service_rule_api_reply(500, ['success' => false, 'error' => 'The service material rule could not be saved. No approval success was recorded.']);
}
