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

foreach (['qty_multiplier', 'qty_multiplier_approved', 'qty_multiplier_approved_by', 'qty_multiplier_approved_at'] as $column) {
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
                r.qty_multiplier_approved_by, r.qty_multiplier_approved_at
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
    $groups = printflow_service_material_rule_display_groups($activeServices ?: [], $rows ?: [], $mugsRule);
    pf_service_rule_api_reply(200, ['success' => true, 'groups' => $groups]);
}

if ($method !== 'POST') pf_service_rule_api_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    pf_service_rule_api_reply(419, ['success' => false, 'error' => 'Invalid session token. Refresh the page and try again.']);
}

$ruleId = filter_var($_POST['rule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string)($_POST['action'] ?? '');
if (!$ruleId || !in_array($action, ['update_multiplier', 'approve'], true)) {
    pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A valid rule and action are required.']);
}

try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start approval transaction.');
    $select = $conn->prepare(
        "SELECT r.service_type, r.item_id, r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
                r.qty_multiplier_approved_by, r.qty_multiplier_approved_at, i.name AS material_name,
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
    if ($action === 'approve' && $ruleType !== 'REQUIRED') {
        throw new InvalidArgumentException('Only REQUIRED service material rules can be approved. OPTIONAL rules remain in their existing selection workflow.');
    }
    if ($action === 'update_multiplier') {
        $multiplier = printflow_service_material_parse_multiplier($_POST['qty_multiplier'] ?? null);
        if ($multiplier === null) throw new InvalidArgumentException('Enter a positive multiplier with up to two decimal places.');
        $current = $rule['qty_multiplier'] === null ? null : (string)$rule['qty_multiplier'];
        $state = printflow_service_material_multiplier_edit_state(
            $current,
            $multiplier,
            (int)$rule['qty_multiplier_approved'] === 1,
            $rule['qty_multiplier_approved_by'] === null ? null : (int)$rule['qty_multiplier_approved_by'],
            $rule['qty_multiplier_approved_at'] === null ? null : (string)$rule['qty_multiplier_approved_at']
        );
        if ($state['changed']) {
            $update = $conn->prepare(
                "UPDATE service_material_rules
                 SET qty_multiplier = ?, qty_multiplier_approved = 0,
                     qty_multiplier_approved_by = NULL, qty_multiplier_approved_at = NULL
                 WHERE id = ?"
            );
            if (!$update) throw new RuntimeException('Could not save the multiplier.');
            $update->bind_param('si', $multiplier, $ruleId);
            if (!$update->execute()) throw new RuntimeException('Could not save the multiplier.');
            $update->close();
        }
        if (!$conn->commit()) throw new RuntimeException('Could not commit the multiplier change.');
        pf_service_rule_api_reply(200, ['success' => true, 'changed' => $state['changed'], 'approval_required' => $ruleType === 'REQUIRED' && ($state['changed'] || !$state['approved'])]);
    }

    $multiplier = printflow_service_material_parse_multiplier((string)($rule['qty_multiplier'] ?? ''));
    if ($multiplier === null) throw new InvalidArgumentException('Set a valid positive multiplier before approving this REQUIRED rule.');
    $adminId = (int)get_user_id();
    if ($adminId <= 0) throw new RuntimeException('The authenticated Admin ID is unavailable; approval was not recorded.');
    $approve = $conn->prepare(
        "UPDATE service_material_rules
         SET qty_multiplier_approved = 1, qty_multiplier_approved_by = ?, qty_multiplier_approved_at = NOW()
         WHERE id = ? AND UPPER(TRIM(rule_type)) = 'REQUIRED'"
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
