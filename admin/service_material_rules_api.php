<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/service_material_rule_approval.php';
require_once __DIR__ . '/../includes/pos_service_stock_helpers.php';
require_once __DIR__ . '/../includes/service_material_rule_display.php';
require_once __DIR__ . '/../includes/service_material_rule_bulk.php';

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

/** Return only the names of fields whose exact inserted-row identity differs. */
function pf_service_rule_identity_mismatch_fields(array $stored, int $expectedId, string $expectedType, int $expectedItemId, int $expectedServiceId = 0, bool $hasServiceId = false): array
{
    $mismatches = [];
    if ((int)($stored['id'] ?? 0) !== $expectedId) $mismatches[] = 'id';
    $storedServiceId = (int)($stored['service_id'] ?? 0);
    if ($hasServiceId && $storedServiceId !== $expectedServiceId) $mismatches[] = 'service_id';
    $storedType = (string)($stored['service_type'] ?? '');
    $canonicalStoredType = $expectedServiceId === 40
        ? ($storedType === 'Tarpaulin Printing' ? 'Tarpaulin Printing' : null)
        : printflow_pos_canonical_service_material_type($expectedServiceId, $storedType);
    if (!array_key_exists('service_type', $stored) || $canonicalStoredType !== $expectedType) $mismatches[] = 'service_type';
    if (!array_key_exists('item_id', $stored) || (int)$stored['item_id'] !== $expectedItemId) $mismatches[] = 'item_id';
    return $mismatches;
}

function pf_service_rule_bulk_assess(int $serviceId, int $itemId, $submittedMultiplier, $submittedBasis, bool $forUpdate = false): array
{
    $allowedServiceIds = [36, 39, 64, 67, 71];
    if (!in_array($serviceId, $allowedServiceIds, true)) return ['action' => 'pending', 'message' => 'This service is excluded from bulk processing.'];
    $selection = printflow_pos_material_selection_registry()[$serviceId][$itemId] ?? null;
    $expectedType = printflow_pos_material_selection_service_type($serviceId);
    $expectedName = printflow_pos_material_selection_item_names($serviceId)[$itemId] ?? null;
    $definition = printflow_service_material_rule_display_registry()[$serviceId] ?? null;
    if (!$selection || !$expectedType || !$expectedName || !$definition) return ['action' => 'pending', 'message' => 'Service/material identity is not in the verified active mapping registry.'];

    $active = db_query('SELECT service_id, name, status FROM services WHERE service_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$serviceId]);
    if (!is_array($active) || count($active) !== 1 || strtoupper(trim((string)($active[0]['status'] ?? ''))) !== 'ACTIVATED'
        || strcasecmp(trim((string)($active[0]['name'] ?? '')), (string)$definition['name']) !== 0) {
        return ['action' => 'pending', 'service_name' => (string)$definition['name'], 'material_name' => (string)$expectedName, 'message' => 'The exact service is missing, inactive, or its identity does not match the approved registry.'];
    }
    $itemRows = db_query('SELECT id, name, unit_of_measure, status FROM inv_items WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$itemId]);
    if (!is_array($itemRows) || count($itemRows) !== 1 || (int)($itemRows[0]['id'] ?? 0) !== $itemId
        || strcasecmp(trim((string)($itemRows[0]['name'] ?? '')), (string)$expectedName) !== 0
        || strtoupper(trim((string)($itemRows[0]['status'] ?? ''))) !== 'ACTIVE') {
        return ['action' => 'pending', 'service_name' => (string)$definition['name'], 'material_name' => (string)$expectedName, 'message' => 'The exact inventory item is missing, inactive, or has a different name.'];
    }
    $uom = trim((string)($itemRows[0]['unit_of_measure'] ?? ''));
    if (!printflow_service_material_bulk_uom_valid($uom)) {
        return ['action' => 'pending', 'service_name' => (string)$definition['name'], 'material_name' => (string)$expectedName, 'uom' => $uom, 'message' => 'Inventory UOM is missing or unsupported for area-based consumption.'];
    }
    $acceptedTypes = printflow_pos_service_material_type_aliases($serviceId);
    if ($acceptedTypes === []) $acceptedTypes = [$expectedType];
    $typePlaceholders = implode(',', array_fill(0, count($acceptedTypes), '?'));
    // db_query() deliberately returns [] after prepare/execute failures, the
    // same value it returns for a successful SELECT with no matching rows.
    // Distinguish those cases before deciding that a rule is safe to create.
    $dbErrorsBeforeRuleLookup = count(printflow_db_errors());
    $hasServiceId = (bool)($GLOBALS['pf_service_material_has_service_id'] ?? false);
    $identityScope = printflow_service_material_rule_identity_scope($serviceId, $acceptedTypes, 'r', $hasServiceId);
    $rules = db_query(
        "SELECT id, " . ($hasServiceId ? 'service_id' : 'NULL AS service_id') . ", service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved,
                qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis
         FROM service_material_rules r WHERE {$identityScope['sql']} AND r.item_id = ? ORDER BY id ASC LIMIT 3" . ($forUpdate ? ' FOR UPDATE' : ''),
        $identityScope['types'] . 'i', array_merge($identityScope['params'], [$itemId])
    );
    if (!is_array($rules) || count(printflow_db_errors()) > $dbErrorsBeforeRuleLookup) return ['action' => 'pending', 'service_name' => (string)$definition['name'], 'material_name' => (string)$expectedName, 'uom' => $uom, 'message' => 'Existing service-material rules could not be checked. No create or approval is safe until this query succeeds.'];
    $base = ['service_name' => (string)$definition['name'], 'material_name' => (string)$expectedName, 'uom' => $uom,
        'selection_role' => (string)$selection['role'], 'selection_group' => $selection['group'] ?? null,
        'expected_rule_type' => in_array($selection['role'], ['optional', 'multi_optional'], true) ? 'OPTIONAL' : 'REQUIRED'];
    if (count($rules) > 1) return $base + ['action' => 'pending', 'message' => 'Multiple canonical/alias rules exist for this exact item; manual review is required.'];
    if ($rules === []) return $base + ['action' => 'create', 'rule' => null, 'multiplier' => null, 'basis' => '', 'message' => 'Will create an unapproved rule; multiplier configuration and approval remain pending.'];
    $rule = $rules[0];
    $base['rule'] = $rule;
    $resolvedRuleServiceId = printflow_service_material_rule_row_service_id($rule);
    if ($resolvedRuleServiceId !== $serviceId
        || !printflow_service_material_rule_display_row_is_editable((string)$rule['service_type'], $itemId, [$active[0]], (string)$expectedName, $resolvedRuleServiceId)) {
        return $base + ['action' => 'pending', 'message' => 'A legacy or unmatched service-key rule exists; it will not be duplicated or modified by bulk processing.'];
    }
    $state = printflow_service_material_bulk_rule_state($rule, $submittedMultiplier, $submittedBasis, $base['expected_rule_type']);
    return $base + $state;
}

foreach (['qty_multiplier', 'qty_multiplier_approved', 'qty_multiplier_approved_by', 'qty_multiplier_approved_at', 'qty_multiplier_basis', 'qty_multiplier_approved_basis'] as $column) {
    if (!db_table_has_column('service_material_rules', $column, true)) {
        pf_service_rule_api_reply(503, ['success' => false, 'error' => 'Service material approval schema is incomplete. Apply the reviewed service material multiplier migration before using this page.']);
    }
}
$serviceIdSchemaErrorsBefore = count(printflow_db_errors());
$hasServiceIdColumn = db_table_has_column('service_material_rules', 'service_id', true);
if (count(printflow_db_errors()) > $serviceIdSchemaErrorsBefore) {
    pf_service_rule_api_reply(503, ['success' => false, 'error' => 'Could not verify the stable service identity schema. No rule change was made.']);
}
$GLOBALS['pf_service_material_has_service_id'] = $hasServiceIdColumn;
$postAction = strtolower(trim((string)($_POST['action'] ?? '')));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && !$hasServiceIdColumn
    && in_array($postAction, ['create_rule', 'bulk_process', 'update_multiplier', 'approve'], true)) {
    pf_service_rule_api_reply(503, ['success' => false, 'error' => 'Stable service identity is not installed yet. Apply the reviewed service-material identity migration before changing rules.']);
}

global $conn;
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $activeServices = db_query("SELECT service_id, name, status FROM services WHERE status = 'Activated' ORDER BY service_id");
    if ($activeServices === false) pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not load active services.']);
    $rows = db_query(
        "SELECT r.id, " . ($hasServiceIdColumn ? 'r.service_id' : 'NULL AS service_id') . ", r.service_type,
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
    foreach ($ruleRows as $ruleRow) {
        $mappedServiceId = printflow_service_material_rule_row_service_id($ruleRow);
        if ($mappedServiceId !== null) $ruleKeys[$mappedServiceId . ':' . (int)$ruleRow['item_id']] = true;
    }
    $activeById = [];
    foreach ($activeServices as $activeService) $activeById[(int)$activeService['service_id']] = $activeService;
    foreach (printflow_pos_material_selection_registry() as $serviceId => $policy) {
        $service = $activeById[(int)$serviceId] ?? null;
        if (!$service || strcasecmp((string)$service['name'], (string)(printflow_service_material_rule_display_registry()[$serviceId]['name'] ?? '')) !== 0) continue;
        $serviceType = printflow_pos_material_selection_service_type((int)$serviceId);
        $names = printflow_pos_material_selection_item_names((int)$serviceId);
        foreach ($policy as $itemId => $selection) {
            $key = (int)$serviceId . ':' . (int)$itemId;
            if (isset($ruleKeys[$key])) continue;
            $acceptedTypes = printflow_pos_service_material_type_aliases((int)$serviceId);
            if ($acceptedTypes === []) $acceptedTypes = [$serviceType];
            $identityScope = printflow_service_material_rule_identity_scope((int)$serviceId, $acceptedTypes, 'r', $hasServiceIdColumn);
            $aliases = db_query(
                'SELECT r.id, ' . ($hasServiceIdColumn ? 'r.service_id' : 'NULL AS service_id') . ', r.service_type FROM service_material_rules r WHERE ' . $identityScope['sql'] . ' AND r.item_id = ? ORDER BY r.id ASC LIMIT 3',
                $identityScope['types'] . 'i', array_merge($identityScope['params'], [(int)$itemId])
            );
            $itemRows = db_query('SELECT id, name, unit_of_measure, status FROM inv_items WHERE id = ? LIMIT 1', 'i', [(int)$itemId]);
            $item = $itemRows[0] ?? null;
            $identityValid = $item && strtoupper(trim((string)$item['status'])) === 'ACTIVE'
                && strcasecmp((string)$item['name'], (string)($names[$itemId] ?? '')) === 0
                && printflow_service_material_bulk_uom_valid((string)($item['unit_of_measure'] ?? ''));
            $ruleRows[] = [
                'id' => null, 'service_id' => (int)$serviceId, 'service_type' => $serviceType, 'item_id' => (int)$itemId,
                'material_name' => $item && strcasecmp((string)$item['name'], (string)($names[$itemId] ?? '')) === 0 ? (string)$item['name'] : (string)($names[$itemId] ?? ('Inventory item #' . $itemId)),
                'inventory_uom' => $item ? (string)$item['unit_of_measure'] : '',
                'rule_type' => in_array($selection['role'], ['optional', 'multi_optional'], true) ? 'OPTIONAL' : 'REQUIRED',
                'qty_multiplier' => null, 'qty_multiplier_approved' => 0,
                'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null,
                'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null,
                'configured' => false,
                'configuration_blocked' => !$identityValid || !is_array($aliases) || $aliases !== [],
                'configuration_message' => !is_array($aliases) ? 'Existing alias rules could not be checked.' : ($aliases !== [] ? 'A matching rule exists under a non-canonical service key; manual review is required.' : (!$identityValid ? 'The active inventory item identity or UOM could not be verified.' : '')),
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
if (in_array($action, ['bulk_preview', 'bulk_process'], true)) {
    $rawRows = json_decode((string)($_POST['rows'] ?? ''), true);
    if (!is_array($rawRows) || $rawRows === [] || count($rawRows) > 100) pf_service_rule_api_reply(400, ['success' => false, 'error' => 'Submit 1 to 100 currently displayed active service-material rows.']);
    $seen = [];
    $candidates = [];
    foreach ($rawRows as $rawRow) {
        if (!is_array($rawRow)) pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A bulk row is malformed.']);
        $serviceId = filter_var($rawRow['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $itemId = filter_var($rawRow['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$serviceId || !$itemId || isset($seen[$serviceId . ':' . $itemId])) pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A bulk row has an invalid or duplicate service/material identity.']);
        $seen[$serviceId . ':' . $itemId] = true;
        $multiplier = is_scalar($rawRow['multiplier'] ?? null) ? (string)$rawRow['multiplier'] : '';
        $basis = is_scalar($rawRow['basis'] ?? null) ? (string)$rawRow['basis'] : '';
        $candidates[] = ['service_id' => (int)$serviceId, 'item_id' => (int)$itemId, 'multiplier' => $multiplier, 'basis' => $basis];
    }
    $process = $action === 'bulk_process';
    if ($process && (string)($_POST['confirm_bulk'] ?? '') !== '1') pf_service_rule_api_reply(400, ['success' => false, 'error' => 'Explicit Admin confirmation is required before bulk processing.']);
    $results = [];
    $summary = ['to_create' => 0, 'to_approve' => 0, 'will_remain_pending' => 0, 'already_approved' => 0, 'processed' => 0];
    foreach ($candidates as $candidate) {
        $serviceId = $candidate['service_id']; $itemId = $candidate['item_id'];
        $assessment = pf_service_rule_bulk_assess($serviceId, $itemId, $candidate['multiplier'], $candidate['basis']);
        $rule = $assessment['rule'] ?? null;
        $oldStatus = !$rule ? 'Missing' : ((int)($rule['qty_multiplier_approved'] ?? 0) === 1 ? 'Approved' : 'Not Approved');
        $actionName = (string)($assessment['action'] ?? 'pending');
        if ($actionName === 'create') $summary['to_create']++;
        elseif ($actionName === 'approve') $summary['to_approve']++;
        elseif ($actionName === 'already_approved') $summary['already_approved']++;
        if (in_array($actionName, ['create', 'pending'], true)) $summary['will_remain_pending']++;
        $rowResult = [
            'service' => (string)($assessment['service_name'] ?? ('Service #' . $serviceId)),
            'material' => (string)($assessment['material_name'] ?? ('Inventory item #' . $itemId)),
            'action' => $actionName,
            'old_status' => $oldStatus,
            'new_status' => $actionName === 'create' ? 'Not Approved' : ($actionName === 'approve' ? 'Approved' : ($actionName === 'already_approved' ? 'Approved' : 'Pending')),
            'multiplier' => (string)($assessment['multiplier'] ?? $candidate['multiplier']),
            'basis' => (string)($assessment['basis'] ?? $candidate['basis']),
            'approval' => $actionName === 'approve' ? 'New Admin audit on confirmation' : ($actionName === 'already_approved' ? 'Existing audit retained' : 'Not approved'),
            'message' => (string)($assessment['message'] ?? ''),
        ];
        if (!$process || !in_array($actionName, ['create', 'approve'], true)) { $results[] = $rowResult; continue; }
        $lockName = 'pf_smr_' . $serviceId . '_' . $itemId;
        $lock = db_query('SELECT GET_LOCK(?, 5) AS acquired', 's', [$lockName]);
        if (!is_array($lock) || (int)($lock[0]['acquired'] ?? 0) !== 1) {
            $rowResult['action'] = 'pending'; $rowResult['new_status'] = $oldStatus; $rowResult['approval'] = 'Not approved';
            $rowResult['message'] = 'Could not acquire the per-rule lock; retry this row later.'; $summary['will_remain_pending']++; $results[] = $rowResult; continue;
        }
        $safeFailureReason = '';
        try {
            if (!$conn->begin_transaction()) throw new RuntimeException('Could not start a per-rule transaction.');
            $locked = pf_service_rule_bulk_assess($serviceId, $itemId, $candidate['multiplier'], $candidate['basis'], true);
            $lockedAction = (string)($locked['action'] ?? 'pending');
            if ($lockedAction === 'create') {
                $expectedType = printflow_pos_material_selection_service_type($serviceId);
                $role = printflow_pos_material_selection_registry()[$serviceId][$itemId]['role'];
                $ruleType = in_array($role, ['optional', 'multi_optional'], true) ? 'OPTIONAL' : 'REQUIRED';
                $insert = $conn->prepare("INSERT INTO service_material_rules (service_id, service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis) VALUES (?, ?, ?, ?, NULL, 0, NULL, NULL, NULL, NULL)");
                if (!$insert) throw new RuntimeException('Could not prepare the unapproved rule creation.');
                $insert->bind_param('isis', $serviceId, $expectedType, $itemId, $ruleType);
                if (!$insert->execute()) throw new RuntimeException('Could not create the unapproved rule.');
                $createdRuleId = (int)$insert->insert_id; $insert->close();
                if ($createdRuleId <= 0) throw new RuntimeException('The new unapproved rule ID could not be verified.');
                $verifyFailure = '';
                $verifyStmt = $conn->prepare(
                    'SELECT id, service_id, service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved,
                            qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis
                     FROM service_material_rules WHERE id = ? LIMIT 1 FOR UPDATE'
                );
                if (!$verifyStmt) {
                    $verifyFailure = 'The inserted rule could not be verified because its exact-ID query could not be prepared.';
                } else {
                    $verifyStmt->bind_param('i', $createdRuleId);
                    if (!$verifyStmt->execute()) {
                        $verifyFailure = 'The inserted rule could not be verified because its exact-ID query failed.';
                    } elseif (!$verifyStmt->store_result() || (int)$verifyStmt->num_rows !== 1) {
                        $verifyFailure = 'The inserted rule ID did not resolve to exactly one row.';
                    } else {
                        $verifyId = 0; $verifyServiceId = null; $verifyServiceType = null; $verifyItemId = 0; $verifyRuleType = null;
                        $verifyMultiplier = null; $verifyApproved = null; $verifyApprovedBy = null; $verifyApprovedAt = null;
                        $verifyBasis = null; $verifyApprovedBasis = null;
                        if (!$verifyStmt->bind_result($verifyId, $verifyServiceId, $verifyServiceType, $verifyItemId, $verifyRuleType, $verifyMultiplier, $verifyApproved, $verifyApprovedBy, $verifyApprovedAt, $verifyBasis, $verifyApprovedBasis)
                            || !$verifyStmt->fetch()) {
                            $verifyFailure = 'The inserted rule verification result could not be read.';
                        } elseif ((int)$verifyId !== $createdRuleId) {
                            $verifyFailure = 'The inserted rule ID did not match the row read back.';
                        } elseif (($identityMismatches = pf_service_rule_identity_mismatch_fields([
                            'id' => $verifyId, 'service_id' => $verifyServiceId, 'service_type' => $verifyServiceType, 'item_id' => $verifyItemId,
                        ], $createdRuleId, (string)$expectedType, (int)$itemId, (int)$serviceId, true)) !== []) {
                            $verifyFailure = 'Stored identity mismatch in field(s): ' . implode(', ', $identityMismatches) . '.';
                        } elseif ((string)$verifyRuleType !== $ruleType) {
                            $verifyFailure = 'The inserted rule type did not match the expected classification.';
                        } elseif ($verifyMultiplier !== null || (int)$verifyApproved !== 0) {
                            $verifyFailure = 'The inserted rule did not remain unconfigured and unapproved.';
                        } elseif ($verifyApprovedBy !== null || $verifyApprovedAt !== null || $verifyBasis !== null || $verifyApprovedBasis !== null) {
                            $verifyFailure = 'The inserted rule contains unexpected approval audit or basis values.';
                        }
                    }
                    $verifyStmt->close();
                }
                if ($verifyFailure !== '') {
                    $safeFailureReason = $verifyFailure;
                    throw new RuntimeException('Unapproved rule verification failed.');
                }
                if (!$conn->commit()) throw new RuntimeException('Could not commit the unapproved rule creation.');
                $rowResult['action'] = 'created_unapproved'; $rowResult['new_status'] = 'Not Approved'; $rowResult['approval'] = 'None';
                $rowResult['message'] = 'Created only; configure and explicitly approve later.'; $summary['processed']++;
            } elseif ($lockedAction === 'approve') {
                $lockedRule = $locked['rule'] ?? null;
                if (!$lockedRule || (int)($lockedRule['id'] ?? 0) <= 0) throw new RuntimeException('The existing rule could not be locked and verified.');
                $lockedRuleId = (int)$lockedRule['id']; $multiplier = (string)$locked['multiplier']; $basis = 'AREA_SQFT'; $adminId = (int)get_user_id();
                if ($adminId <= 0) throw new RuntimeException('The Admin identity is unavailable.');
                $update = $conn->prepare('UPDATE service_material_rules SET qty_multiplier = ?, qty_multiplier_approved = 0, qty_multiplier_approved_by = NULL, qty_multiplier_approved_at = NULL, qty_multiplier_basis = ?, qty_multiplier_approved_basis = NULL WHERE id = ?');
                if (!$update) throw new RuntimeException('Could not prepare the explicit multiplier update.');
                $update->bind_param('ssi', $multiplier, $basis, $lockedRuleId);
                if (!$update->execute()) throw new RuntimeException('Could not save the multiplier.'); $update->close();
                $approve = $conn->prepare('UPDATE service_material_rules SET qty_multiplier_approved = 1, qty_multiplier_approved_by = ?, qty_multiplier_approved_at = NOW(), qty_multiplier_approved_basis = qty_multiplier_basis WHERE id = ?');
                if (!$approve) throw new RuntimeException('Could not prepare the approval audit.');
                $approve->bind_param('ii', $adminId, $lockedRuleId);
                if (!$approve->execute() || $approve->affected_rows !== 1) throw new RuntimeException('Could not record the explicit Admin approval.'); $approve->close();
                $verify = db_query('SELECT qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis FROM service_material_rules WHERE id = ? LIMIT 1', 'i', [$lockedRuleId]);
                if (!is_array($verify) || count($verify) !== 1 || number_format((float)$verify[0]['qty_multiplier'], 2, '.', '') !== $multiplier
                    || (int)$verify[0]['qty_multiplier_approved'] !== 1 || (int)$verify[0]['qty_multiplier_approved_by'] !== $adminId
                    || trim((string)$verify[0]['qty_multiplier_approved_at']) === '' || (string)$verify[0]['qty_multiplier_basis'] !== $basis
                    || (string)$verify[0]['qty_multiplier_approved_basis'] !== $basis) throw new RuntimeException('Multiplier or Admin approval audit verification failed.');
                if (!$conn->commit()) throw new RuntimeException('Could not commit this rule update.');
                $rowResult['action'] = 'approved'; $rowResult['new_status'] = 'Approved'; $rowResult['approval'] = 'Admin #' . $adminId . ' at ' . (string)$verify[0]['qty_multiplier_approved_at'];
                $rowResult['message'] = 'Multiplier/basis verified and Admin approval audit recorded.'; $summary['processed']++;
            } elseif ($lockedAction === 'already_approved') {
                if (!$conn->commit()) throw new RuntimeException('Could not close the verified no-op transaction.');
                $rowResult['action'] = 'already_approved'; $rowResult['new_status'] = 'Approved';
                $rowResult['approval'] = 'Existing audit retained'; $rowResult['message'] = (string)($locked['message'] ?? 'Already approved; no changes were made.');
            } else {
                $conn->rollback();
                $rowResult['action'] = 'pending'; $rowResult['new_status'] = $oldStatus; $rowResult['approval'] = 'Not approved';
                $rowResult['message'] = (string)($locked['message'] ?? 'This row no longer qualifies; no changes were made.'); $summary['will_remain_pending']++;
            }
        } catch (Throwable $e) {
            @$conn->rollback();
            $errorReference = printflow_db_error_reference();
            error_log('[service_material_rule_bulk_failure] ' . json_encode([
                'reference' => $errorReference,
                'service_id' => $serviceId,
                'item_id' => $itemId,
                'action' => $lockedAction ?? 'unknown',
                'safe_reason' => $safeFailureReason !== '' ? $safeFailureReason : 'Transaction failed before a verified commit.',
                'error_class' => get_class($e),
                'error_code' => (int)$e->getCode(),
            ], JSON_UNESCAPED_SLASHES));
            $rowResult['action'] = 'failed'; $rowResult['new_status'] = $oldStatus; $rowResult['approval'] = 'Not approved';
            $rowResult['message'] = ($safeFailureReason !== '' ? $safeFailureReason . ' ' : 'This row failed safely and was rolled back. ')
                . 'Reference ' . $errorReference . '; review the matching server log entry before retrying.'; $summary['will_remain_pending']++;
        } finally {
            db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$lockName]);
        }
        $results[] = $rowResult;
    }
    if ($process) {
        $summary['will_remain_pending'] = 0;
        $summary['already_approved'] = 0;
        $summary['processed'] = 0;
        foreach ($results as $resultRow) {
            if (in_array($resultRow['action'], ['created_unapproved', 'pending', 'failed'], true)) $summary['will_remain_pending']++;
            if ($resultRow['action'] === 'already_approved') $summary['already_approved']++;
            if (in_array($resultRow['action'], ['created_unapproved', 'approved'], true)) $summary['processed']++;
        }
    }
    pf_service_rule_api_reply(200, ['success' => true, 'mode' => $process ? 'processed' : 'preview', 'summary' => $summary, 'results' => $results]);
}
if ($action === 'create_rule') {
    $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$serviceId || !$itemId) pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A valid active service and approved material identity are required.']);
    $policy = printflow_pos_material_selection_registry()[(int)$serviceId][$itemId] ?? null;
    $expectedType = printflow_pos_material_selection_service_type((int)$serviceId);
    $expectedName = printflow_pos_material_selection_item_names((int)$serviceId)[$itemId] ?? null;
    $identityLookupErrorCount = count(printflow_db_errors());
    $active = db_query("SELECT service_id, name, status FROM services WHERE service_id = ? LIMIT 1", 'i', [$serviceId]);
    $item = db_query('SELECT id, name, status FROM inv_items WHERE id = ? LIMIT 1', 'i', [$itemId]);
    if (!is_array($active) || !is_array($item) || count(printflow_db_errors()) > $identityLookupErrorCount) {
        $reference = printflow_db_error_reference();
        error_log('[service_material_rule_create_failure] ' . json_encode([
            'reference' => $reference, 'stage' => 'identity_lookup', 'service_id' => (int)$serviceId,
            'item_id' => (int)$itemId, 'db_error_count' => count(printflow_db_errors()) - $identityLookupErrorCount,
        ], JSON_UNESCAPED_SLASHES));
        pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not verify the active service and inventory item before creating the rule. No change was made. Reference ' . $reference . '.']);
    }
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
    $ruleLock = 'pf_smr_' . (int)$serviceId . '_' . (int)$itemId;
    $lockRows = db_query('SELECT GET_LOCK(?, 5) AS acquired', 's', [$ruleLock]);
    if (!is_array($lockRows) || (int)($lockRows[0]['acquired'] ?? 0) !== 1) pf_service_rule_api_reply(409, ['success' => false, 'error' => 'This service-material rule is being updated. Retry after the current operation finishes.']);
    if (!$conn->begin_transaction()) { db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]); pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not start the unapproved rule transaction.']); }
    $lockedLookupErrorCount = count(printflow_db_errors());
    $lockedActive = db_query('SELECT service_id, name, status FROM services WHERE service_id = ? LIMIT 1 FOR UPDATE', 'i', [$serviceId]);
    $lockedItem = db_query('SELECT id, name, status FROM inv_items WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$itemId]);
    if (!is_array($lockedActive) || !is_array($lockedItem) || count(printflow_db_errors()) > $lockedLookupErrorCount) {
        $conn->rollback(); db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]);
        $reference = printflow_db_error_reference();
        error_log('[service_material_rule_create_failure] ' . json_encode([
            'reference' => $reference, 'stage' => 'locked_identity_lookup', 'service_id' => (int)$serviceId,
            'item_id' => (int)$itemId, 'db_error_count' => count(printflow_db_errors()) - $lockedLookupErrorCount,
        ], JSON_UNESCAPED_SLASHES));
        pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not recheck the active service and inventory item inside the transaction. The transaction was rolled back. Reference ' . $reference . '.']);
    }
    if (!is_array($lockedActive) || count($lockedActive) !== 1 || strtoupper(trim((string)$lockedActive[0]['status'])) !== 'ACTIVATED'
        || strcasecmp(trim((string)$lockedActive[0]['name']), (string)(printflow_service_material_rule_display_registry()[$serviceId]['name'] ?? '')) !== 0
        || !is_array($lockedItem) || count($lockedItem) !== 1 || strtoupper(trim((string)$lockedItem[0]['status'])) !== 'ACTIVE'
        || strcasecmp(trim((string)$lockedItem[0]['name']), (string)$expectedName) !== 0) {
        $conn->rollback(); db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]);
        pf_service_rule_api_reply(409, ['success' => false, 'error' => 'The active service or inventory identity changed during the request. Refresh before creating the rule.']);
    }
    $existingLookupErrorCount = count(printflow_db_errors());
    $identityScope = printflow_service_material_rule_identity_scope((int)$serviceId, $acceptedTypes, 'r', $hasServiceIdColumn);
    $existing = db_query(
        "SELECT r.id FROM service_material_rules r WHERE {$identityScope['sql']} AND r.item_id = ? LIMIT 2 FOR UPDATE",
        $identityScope['types'] . 'i', array_merge($identityScope['params'], [$itemId])
    );
    if (!is_array($existing) || count(printflow_db_errors()) > $existingLookupErrorCount) {
        $conn->rollback();
        db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]);
        $reference = printflow_db_error_reference();
        error_log('[service_material_rule_create_failure] ' . json_encode([
            'reference' => $reference, 'stage' => 'duplicate_check', 'service_id' => (int)$serviceId,
            'item_id' => (int)$itemId, 'db_error_count' => count(printflow_db_errors()) - $existingLookupErrorCount,
        ], JSON_UNESCAPED_SLASHES));
        pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not verify whether this exact service-material rule already exists. No rule was created. Reference ' . $reference . '.']);
    }
    if ($existing !== []) {
        $conn->rollback();
        db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]);
        pf_service_rule_api_reply(409, ['success' => false, 'error' => 'A rule already exists or could not be checked. Refresh before editing.']);
    }
    $ruleType = in_array($policy['role'], ['optional', 'multi_optional'], true) ? 'OPTIONAL' : 'REQUIRED';
    $insert = $conn->prepare("INSERT INTO service_material_rules (service_id, service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis) VALUES (?, ?, ?, ?, NULL, 0, NULL, NULL, NULL, NULL)");
    if (!$insert) { $conn->rollback(); db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]); pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not prepare the new unapproved material rule.']); }
    $insert->bind_param('isis', $serviceId, $expectedType, $itemId, $ruleType);
    if (!$insert->execute()) { $insert->close(); $conn->rollback(); db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]); pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not create the unapproved material rule.']); }
    $newId = (int)$insert->insert_id;
    $insert->close();
    $verify = null;
    $verifyFailure = '';
    $verifyStmt = $newId > 0 ? $conn->prepare(
        'SELECT id, service_id, service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved,
                qty_multiplier_approved_by, qty_multiplier_approved_at, qty_multiplier_basis, qty_multiplier_approved_basis
         FROM service_material_rules WHERE id = ? LIMIT 1 FOR UPDATE'
    ) : false;
    if ($newId <= 0) {
        $verifyFailure = 'Insert did not return a valid rule ID.';
    } elseif (!$verifyStmt) {
        $verifyFailure = 'The exact inserted rule could not be read back (verification query could not be prepared).';
    } else {
        $verifyStmt->bind_param('i', $newId);
        if (!$verifyStmt->execute()) {
            $verifyFailure = 'The exact inserted rule could not be read back (verification query failed).';
        } elseif (!$verifyStmt->store_result() || (int)$verifyStmt->num_rows !== 1) {
            $verifyFailure = 'The exact inserted rule ID was not found during verification.';
        } else {
            $verifyId = 0;
            $verifyServiceId = null;
            $verifyServiceType = null;
            $verifyItemId = 0;
            $verifyRuleType = null;
            $verifyMultiplier = null;
            $verifyApproved = null;
            $verifyApprovedBy = null;
            $verifyApprovedAt = null;
            $verifyBasis = null;
            $verifyApprovedBasis = null;
            if (!$verifyStmt->bind_result($verifyId, $verifyServiceId, $verifyServiceType, $verifyItemId, $verifyRuleType, $verifyMultiplier, $verifyApproved, $verifyApprovedBy, $verifyApprovedAt, $verifyBasis, $verifyApprovedBasis)
                || !$verifyStmt->fetch()) {
                $verifyFailure = 'The exact inserted rule could not be read back (verification result was unavailable).';
            } else {
                $verify = [
                    'id' => $verifyId, 'service_id' => $verifyServiceId, 'service_type' => $verifyServiceType, 'item_id' => $verifyItemId,
                    'rule_type' => $verifyRuleType, 'qty_multiplier' => $verifyMultiplier,
                    'qty_multiplier_approved' => $verifyApproved, 'qty_multiplier_approved_by' => $verifyApprovedBy,
                    'qty_multiplier_approved_at' => $verifyApprovedAt, 'qty_multiplier_basis' => $verifyBasis,
                    'qty_multiplier_approved_basis' => $verifyApprovedBasis,
                ];
                $identityMismatches = pf_service_rule_identity_mismatch_fields($verify, $newId, (string)$expectedType, (int)$itemId, (int)$serviceId, true);
                if ($identityMismatches !== []) $verifyFailure = 'Stored identity mismatch in field(s): ' . implode(', ', $identityMismatches) . '.';
                elseif ((string)$verify['rule_type'] !== $ruleType) $verifyFailure = 'The stored rule classification differed from the required unapproved classification.';
                elseif ($verify['qty_multiplier'] !== null) $verifyFailure = 'The new row did not retain a NULL, unconfigured multiplier.';
                elseif ((int)$verify['qty_multiplier_approved'] !== 0) $verifyFailure = 'The new row was not stored with approval disabled.';
                elseif ($verify['qty_multiplier_approved_by'] !== null || $verify['qty_multiplier_approved_at'] !== null) $verifyFailure = 'The new row contains unexpected approval audit metadata.';
                elseif ($verify['qty_multiplier_basis'] !== null || $verify['qty_multiplier_approved_basis'] !== null) $verifyFailure = 'The new row contains an unexpected consumption basis or approval basis.';
            }
        }
        $verifyStmt->close();
    }
    if ($verifyFailure !== '') {
        $conn->rollback(); db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]);
        $reference = printflow_db_error_reference();
        error_log('[service_material_rule_create_failure] ' . json_encode([
            'reference' => $reference, 'stage' => 'insert_verification', 'rule_id' => $newId,
            'service_id' => (int)$serviceId, 'item_id' => (int)$itemId, 'service_type_expected' => $expectedType,
            'rule_type_expected' => $ruleType, 'verification_reason' => $verifyFailure,
            'stored_row' => $verify ? [
                'id' => (int)$verify['id'], 'service_type' => (string)$verify['service_type'], 'item_id' => (int)$verify['item_id'],
                'rule_type' => (string)$verify['rule_type'], 'qty_multiplier_is_null' => $verify['qty_multiplier'] === null,
                'approved' => (int)$verify['qty_multiplier_approved'], 'approved_by_is_null' => $verify['qty_multiplier_approved_by'] === null,
                'approved_at_is_null' => $verify['qty_multiplier_approved_at'] === null,
                'basis_is_null' => $verify['qty_multiplier_basis'] === null,
                'approved_basis_is_null' => $verify['qty_multiplier_approved_basis'] === null,
            ] : null,
        ], JSON_UNESCAPED_SLASHES));
        pf_service_rule_api_reply(500, ['success' => false, 'error' => $verifyFailure . ' The transaction was rolled back; the rule remains uncreated. Reference ' . $reference . '.']);
    }
    if (!$conn->commit()) { db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]); pf_service_rule_api_reply(500, ['success' => false, 'error' => 'Could not commit the unapproved service-material rule.']); }
    db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$ruleLock]);
    pf_service_rule_api_reply(200, ['success' => true, 'rule_id' => $newId, 'approved' => false]);
}
if (!$ruleId || !in_array($action, ['update_multiplier', 'approve'], true)) {
    pf_service_rule_api_reply(400, ['success' => false, 'error' => 'A valid rule and action are required.']);
}
$requestedServiceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestedItemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestedServiceId || !$requestedItemId) pf_service_rule_api_reply(400, ['success' => false, 'error' => 'The displayed service and material IDs are required.']);

try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start approval transaction.');
    $select = $conn->prepare(
        "SELECT " . ($hasServiceIdColumn ? 'r.service_id' : 'NULL AS service_id') . ", r.service_type, r.item_id, r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
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
    $resolvedRuleServiceId = printflow_service_material_rule_row_service_id($rule);
    if ((int)$rule['item_id'] !== (int)$requestedItemId || $resolvedRuleServiceId !== (int)$requestedServiceId) {
        throw new InvalidArgumentException('The rule no longer matches the displayed stable service/material IDs. Refresh before editing.');
    }
    $canonicalRuleType = printflow_pos_service_material_expected_type((int)$requestedServiceId) ?? ((int)$requestedServiceId === 40 ? 'Tarpaulin Printing' : '');
    if ($canonicalRuleType === '') throw new InvalidArgumentException('This service is not eligible for rule changes from this page.');
    if (strtoupper(trim((string)($rule['item_status'] ?? ''))) !== 'ACTIVE') {
        throw new InvalidArgumentException('Inactive inventory materials cannot be configured from this page.');
    }
    $activeServices = db_query("SELECT service_id, name, status FROM services WHERE status = 'Activated'");
    if ($activeServices === false || !printflow_service_material_rule_display_row_is_editable(
        (string)$rule['service_type'],
        (int)$rule['item_id'],
        $activeServices ?: [],
        (string)($rule['material_name'] ?? ''),
        (int)$requestedServiceId
    )) {
        throw new InvalidArgumentException('This rule is not mapped to an active service in the approved Admin display.');
    }
    $ruleType = strtoupper(trim((string)$rule['rule_type']));
    if ($action === 'approve') {
        if ($ruleType !== 'REQUIRED') throw new InvalidArgumentException('Only REQUIRED service material rules can be approved.');
        $conflict = printflow_pos_known_service_material_conflict($canonicalRuleType, (string)($rule['material_name'] ?? ''));
        if ($conflict !== null) throw new InvalidArgumentException($conflict);
    }
    if ($action === 'update_multiplier') {
        $multiplier = printflow_service_material_parse_multiplier($_POST['qty_multiplier'] ?? null);
        if ($multiplier === null) throw new InvalidArgumentException('Enter a positive multiplier with up to two decimal places.');
        // The approval UI exposes the dimension rate only for the new selection-based services.
        $basis = 'AREA_SQFT';
        if (!in_array($canonicalRuleType, ['Decals/Stickers (Print/Cut)', 'Plates', 'Poster Printing', 'Brochure', 'Sintraboard Standees'], true)) $basis = trim((string)($rule['qty_multiplier_basis'] ?? ''));
        if (in_array($canonicalRuleType, ['Decals/Stickers (Print/Cut)', 'Plates', 'Poster Printing', 'Brochure', 'Sintraboard Standees'], true)
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
        $serviceIdChanged = $hasServiceIdColumn && (int)($rule['service_id'] ?? 0) !== (int)$requestedServiceId;
        if ($state['changed'] || $basisChanged || ($state['approved'] && $basisApprovalMismatch) || $serviceIdChanged) {
            $update = $conn->prepare(
                "UPDATE service_material_rules
                 SET qty_multiplier = ?, qty_multiplier_approved = 0,
                     qty_multiplier_approved_by = NULL, qty_multiplier_approved_at = NULL,
                     qty_multiplier_basis = ?, qty_multiplier_approved_basis = NULL, service_id = ?
                 WHERE id = ?"
            );
            if (!$update) throw new RuntimeException('Could not save the multiplier.');
            $update->bind_param('ssii', $multiplier, $basis, $requestedServiceId, $ruleId);
            if (!$update->execute()) throw new RuntimeException('Could not save the multiplier.');
            $update->close();
        }
        if (!$conn->commit()) throw new RuntimeException('Could not commit the multiplier change.');
        pf_service_rule_api_reply(200, ['success' => true, 'changed' => $state['changed'] || $basisChanged || $basisApprovalMismatch || $serviceIdChanged, 'approval_required' => ($state['changed'] || $basisChanged || $basisApprovalMismatch || $serviceIdChanged || !$state['approved'])]);
    }

    $multiplier = printflow_service_material_parse_multiplier((string)($rule['qty_multiplier'] ?? ''));
    if ($multiplier === null) throw new InvalidArgumentException('Set a valid positive multiplier before approving this selected-material rule.');
    if (in_array($canonicalRuleType, ['Decals/Stickers (Print/Cut)', 'Plates', 'Poster Printing', 'Brochure', 'Sintraboard Standees'], true)
        && (strtoupper(trim((string)($rule['qty_multiplier_basis'] ?? ''))) !== 'AREA_SQFT'
            || strtoupper(trim((string)($_POST['qty_multiplier_basis'] ?? 'AREA_SQFT'))) !== 'AREA_SQFT')) {
        throw new InvalidArgumentException('Save the inventory-units-per-square-foot basis before approving this material.');
    }
    $adminId = (int)get_user_id();
    if ($adminId <= 0) throw new RuntimeException('The authenticated Admin ID is unavailable; approval was not recorded.');
    $approve = $conn->prepare(
        "UPDATE service_material_rules
         SET qty_multiplier_approved = 1, qty_multiplier_approved_by = ?, qty_multiplier_approved_at = NOW(),
             qty_multiplier_approved_basis = qty_multiplier_basis, service_id = ?
         WHERE id = ?"
    );
    if (!$approve) throw new RuntimeException('Could not prepare the approval record.');
    $approve->bind_param('iii', $adminId, $requestedServiceId, $ruleId);
    if (!$approve->execute()) throw new RuntimeException('Approval was not recorded; refresh the rule and retry.');
    $approve->close();
    if (!$conn->commit()) throw new RuntimeException('Could not commit the approval record.');
    pf_service_rule_api_reply(200, ['success' => true, 'approved_by' => $adminId]);
} catch (InvalidArgumentException $e) {
    @$conn->rollback();
    pf_service_rule_api_reply(400, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    @$conn->rollback();
    $errorReference = printflow_db_error_reference();
    error_log('[service_material_rule_mutation_failure] ' . json_encode([
        'reference' => $errorReference,
        'rule_id' => (int)$ruleId,
        'action' => $action,
        'error_class' => get_class($e),
        'error_code' => (int)$e->getCode(),
    ], JSON_UNESCAPED_SLASHES));
    pf_service_rule_api_reply(500, ['success' => false, 'error' => 'The service material rule could not be saved. No approval success was recorded. Reference ' . $errorReference . '.']);
}
