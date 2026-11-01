<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/service_material_rule_approval.php';

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
    $rows = db_query(
        "SELECT r.id, r.service_type, COALESCE(s.name, r.service_type) AS service_name,
                r.item_id, i.name AS material_name, i.unit_of_measure AS inventory_uom,
                r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
                r.qty_multiplier_approved_by, r.qty_multiplier_approved_at
         FROM service_material_rules r
         LEFT JOIN inv_items i ON i.id = r.item_id
         LEFT JOIN services s ON s.service_id = (
             SELECT MIN(s2.service_id) FROM services s2
             WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(r.service_type))
         )
         ORDER BY r.service_type, r.id"
    );
    if ($rows === false) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not load service material rules.']);
    pf_service_rule_api_reply(200, ['success' => true, 'data' => $rows ?: []]);
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
        "SELECT rule_type, qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by,
                qty_multiplier_approved_at
         FROM service_material_rules WHERE id = ? FOR UPDATE"
    );
    if (!$select) throw new RuntimeException('Could not inspect the selected service material rule.');
    $select->bind_param('i', $ruleId);
    if (!$select->execute()) throw new RuntimeException('Could not inspect the selected service material rule.');
    $result = $select->get_result();
    $rule = $result ? $result->fetch_assoc() : null;
    $select->close();
    if (!$rule) throw new InvalidArgumentException('The selected service material rule no longer exists.');
    $ruleType = strtoupper(trim((string)$rule['rule_type']));
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
