<?php
/**
 * Staff POS API: Render service form fields HTML for a given service_id
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/service_field_renderer.php';
require_once __DIR__ . '/../../includes/pos_custom_transaction_datetime.php';
require_once __DIR__ . '/../../includes/pos_material_selection.php';
require_once __DIR__ . '/../../includes/pos_service_stock_helpers.php';
require_once __DIR__ . '/../../includes/pos_service_dimensions.php';
require_once __DIR__ . '/../../includes/service_material_rule_identity.php';
require_once __DIR__ . '/../../includes/InventoryManager.php';

if (!is_logged_in() || (!is_staff() && !is_admin())) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$service_id = (int)($_GET['service_id'] ?? 0);
if ($service_id < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid service']);
    exit;
}

$service = db_query("SELECT service_id, name, category, COALESCE(price, 0) AS base_price FROM services WHERE service_id = ? AND status = 'Activated'", 'i', [$service_id]);
if (empty($service)) {
    $exists = db_query("SELECT service_id, status FROM services WHERE service_id = ? LIMIT 1", 'i', [$service_id]);
    if (empty($exists)) {
        echo json_encode(['success' => false, 'error' => 'Service not found']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Service is not available for POS ordering.']);
    }
    exit;
}
$service = $service[0];

if (printflow_pos_can_custom_transaction_datetime()) {
    $GLOBALS['printflow_allow_past_needed_date'] = true;
}

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name ASC") ?: [];

require_once __DIR__ . '/../../includes/branch_context.php';
$staff_branch_id = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 0);
$staff_branch_name = '';
foreach ($branches as $branchRow) {
    if ((int)($branchRow['id'] ?? 0) === (int)$staff_branch_id) {
        $staff_branch_name = (string)($branchRow['branch_name'] ?? '');
        break;
    }
}

$fields_html = '';
try {
    ob_start();
    if (service_has_field_config($service_id)) {
        echo render_service_fields($service_id, $branches, ['branch_id' => $staff_branch_id]);
    } else {
        // Fallback: simple form for unconfigured services
        $branch_options = '';
        foreach ($branches as $b) {
            $sel = ($staff_branch_id == $b['id']) ? ' selected' : '';
            $branch_options .= '<option value="' . (int)$b['id'] . '"' . $sel . '>' . htmlspecialchars($b['branch_name']) . '</option>';
        }
        $needed_date_min = !empty($GLOBALS['printflow_allow_past_needed_date']) ? '' : date('Y-m-d');
        $needed_date_min_attr = $needed_date_min !== '' ? ' min="' . htmlspecialchars($needed_date_min, ENT_QUOTES, 'UTF-8') . '"' : '';
        echo '
    <div class="shopee-form-row">
        <div class="shopee-form-label">Branch *</div>
        <div class="shopee-form-field">
            <select name="branch_id" class="shopee-opt-btn" required style="width:175px;cursor:pointer;">
                ' . $branch_options . '
            </select>
        </div>
    </div>
    <div class="shopee-form-row">
        <div class="shopee-form-label">Needed Date *</div>
        <div class="shopee-form-field">
            <input type="date" name="needed_date" class="shopee-opt-btn" required' . $needed_date_min_attr . ' style="width:175px;cursor:pointer;">
        </div>
    </div>
    <div class="shopee-form-row">
        <div class="shopee-form-label">Quantity *</div>
        <div class="shopee-form-field">
            <div class="quantity-container shopee-opt-btn" style="display:inline-flex;justify-content:space-between;gap:1rem;width:175px;cursor:default;">
                <button type="button" style="background:none;border:none;color:#6b7280;font-size:1.125rem;font-weight:600;cursor:pointer;" onclick="decreaseQty()">&minus;</button>
                <input type="number" id="quantity-input" name="quantity" style="border:none;text-align:center;width:60px;font-size:.875rem;font-weight:500;color:#374151;background:transparent;outline:none;" min="1" max="100" value="1">
                <button type="button" style="background:none;border:none;color:#6b7280;font-size:1.125rem;font-weight:600;cursor:pointer;" onclick="increaseQty()">+</button>
            </div>
        </div>
    </div>
    <div class="shopee-form-row">
        <div class="shopee-form-label">Notes</div>
        <div class="shopee-form-field">
            <textarea name="notes" rows="3" class="shopee-opt-btn" style="max-width:400px;resize:none;text-align:left;padding:.75rem;"></textarea>
        </div>
    </div>';
    }
    $fields_html = ob_get_clean();
} catch (Throwable $renderError) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('[pos_service_fields] service_id=' . $service_id . ' ' . $renderError->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load service order fields. Please contact support if this continues.',
        'service_id' => $service_id,
    ]);
    exit;
}

if ($fields_html === '') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'This service has no order fields configured for POS.',
        'service_id' => $service_id,
    ]);
    exit;
}

// These services use explicit selectable material groups. Choices are always
// resolved by the confirmed inventory IDs; absent or unapproved rule rows stay
// visible but disabled so staff cannot create unvalidated consumption.
$materialSelectionHtml = '';
$selectionPolicy = printflow_pos_material_selection_registry()[$service_id] ?? null;
if (is_array($selectionPolicy)) {
    foreach (['qty_multiplier_basis', 'qty_multiplier_approved_basis'] as $basisColumn) {
        if (!db_table_has_column('service_material_rules', $basisColumn, true)) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Selected-material consumption is not configured until the reviewed material-basis migration is applied.']);
            exit;
        }
    }
    $serviceType = printflow_pos_material_selection_service_type($service_id);
    $itemIds = array_map('intval', array_keys($selectionPolicy));
    $idSql = implode(',', $itemIds);
    $items = db_query("SELECT id, name, unit_of_measure, status FROM inv_items WHERE id IN ({$idSql}) ORDER BY id");
    if ($items === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Verified service materials could not be loaded.']);
        exit;
    }
    $aliases = printflow_pos_service_material_type_aliases($service_id);
    if ($aliases === []) $aliases = [$serviceType];
    $schemaErrorsBefore = count(printflow_db_errors());
    $hasServiceIdColumn = db_table_has_column('service_material_rules', 'service_id', true);
    if (count(printflow_db_errors()) > $schemaErrorsBefore) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Stable service-material identity could not be verified.']);
        exit;
    }
    $identityScope = printflow_service_material_rule_identity_scope($service_id, $aliases, 'r', $hasServiceIdColumn);
    $ruleRows = db_query(
        "SELECT id, " . ($hasServiceIdColumn ? 'service_id' : 'NULL AS service_id') . ", service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by, qty_multiplier_approved_at,
                qty_multiplier_basis, qty_multiplier_approved_basis
         FROM service_material_rules r WHERE {$identityScope['sql']} AND r.item_id IN ({$idSql}) ORDER BY id",
        $identityScope['types'],
        $identityScope['params']
    );
    if ($ruleRows === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Service material rules could not be loaded.']);
        exit;
    }
    try { $ruleRows = printflow_pos_select_service_material_rule_set($service_id, $ruleRows ?: []); }
    catch (Throwable $identityError) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Stable and legacy material mappings conflict. An Admin must resolve the duplicate configuration.']);
        exit;
    }
    $rulesByItem = [];
    foreach ($ruleRows as $ruleRow) {
        $ruleItemId = (int)$ruleRow['item_id'];
        if (isset($rulesByItem[$ruleItemId])) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Multiple rules exist for one service/material ID. An Admin must resolve the duplicate configuration.']);
            exit;
        }
        $rulesByItem[$ruleItemId] = $ruleRow;
    }
    $expectedNames = printflow_pos_material_selection_item_names($service_id);
    $labels = [];
    foreach ($items as $item) {
        $id = (int)$item['id'];
        if (strtoupper(trim((string)$item['status'])) !== 'ACTIVE'
            || !isset($expectedNames[$id])
            || strcasecmp(trim((string)$item['name']), $expectedNames[$id]) !== 0) continue;
        $policy = $selectionPolicy[$id];
        $group = (string)($policy['group'] ?? '');
        $rule = $rulesByItem[$id] ?? null;
        $approved = false;
        $setupMessage = 'Admin configuration required';
        if ($rule) {
            try {
                printflow_pos_approved_service_multiplier($rule['qty_multiplier'] ?? null, $rule['qty_multiplier_approved'] ?? null, $rule['qty_multiplier_approved_by'] ?? null, $rule['qty_multiplier_approved_at'] ?? null);
                $storedRuleType = strtoupper(trim((string)($rule['rule_type'] ?? '')));
                $optionalBySelectionPolicy = in_array($policy['role'], ['optional', 'multi_optional'], true);
                if ($optionalBySelectionPolicy
                    ? !in_array($storedRuleType, ['OPTIONAL', 'REQUIRED'], true)
                    : $storedRuleType !== 'REQUIRED') {
                    throw new RuntimeException('The saved rule type does not match the verified selection policy.');
                }
                if (strtoupper(trim((string)($rule['qty_multiplier_basis'] ?? ''))) !== 'AREA_SQFT'
                    || strtoupper(trim((string)($rule['qty_multiplier_approved_basis'] ?? ''))) !== 'AREA_SQFT') {
                    throw new RuntimeException('The Admin-approved rate must be saved as inventory units per square foot.');
                }
                $approved = true;
                $setupMessage = 'Available';
            } catch (Throwable $ignored) {
                $setupMessage = $ignored->getMessage() === 'The saved rule type does not match the verified selection policy.'
                    ? 'Admin must correct the rule type'
                    : 'Admin multiplier and area-rate approval required';
            }
        }
        $role = (string)$policy['role'];
        $labels[$group !== '' ? $group : 'optional'][] = [
            'id' => $id, 'name' => $expectedNames[$id], 'uom' => (string)($item['unit_of_measure'] ?? ''),
            'role' => $role, 'required_group' => !empty($policy['group_required']), 'enabled' => $approved,
            'setup' => $setupMessage,
        ];
    }
    $demandHelpText = $service_id === 39
        ? 'Select the Plate material used. Demand is calculated from the order quantity and the Admin-approved inventory-units-per-item multiplier; fractional inventory units round up.'
        : 'Select only the material(s) used. Demand is calculated from the selected dimensions, approved multiplier, and order quantity; fractional units round up.';
    $materialSelectionHtml = '<section class="pos-material-selection" data-pos-material-picker="1" style="margin:12px 0;padding:14px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc">'
        . '<h3 style="margin:0 0 6px;font-size:15px">Materials for this order</h3><p style="margin:0 0 10px;color:#64748b;font-size:12px">' . htmlspecialchars($demandHelpText, ENT_QUOTES, 'UTF-8') . '</p>';
    foreach ($labels as $groupKey => $choices) {
        $requiredGroup = $groupKey !== 'optional' && !empty($choices[0]['required_group']);
        $mode = $groupKey === 'decal_finishes' ? 'checkbox' : 'radio';
        $materialSelectionHtml .= '<fieldset data-material-group="' . htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') . '" data-group-required="' . ($requiredGroup ? '1' : '0') . '" style="margin:8px 0;padding:8px;border:1px solid #e2e8f0;border-radius:8px"><legend style="padding:0 4px;font-size:12px;font-weight:700">'
            . htmlspecialchars(str_replace('_', ' ', $groupKey), ENT_QUOTES, 'UTF-8') . ($requiredGroup ? ' · choose one' : ' · optional') . '</legend>';
        foreach ($choices as $choice) {
            $disabled = $choice['enabled'] ? '' : ' disabled';
            $checked = '';
            $materialSelectionHtml .= '<label style="display:flex;gap:8px;align-items:center;padding:5px 2px;font-size:13px"><input type="' . $mode . '" name="pos_material_choice_' . htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') . '" value="' . (int)$choice['id'] . '" data-pos-material-id="' . (int)$choice['id'] . '" data-pos-material-group="' . htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') . '" data-pos-material-uom="' . htmlspecialchars($choice['uom'], ENT_QUOTES, 'UTF-8') . '"' . $disabled . $checked . '><span>' . htmlspecialchars($choice['name'], ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($choice['uom'], ENT_QUOTES, 'UTF-8') . '</span><small style="margin-left:auto;color:' . ($choice['enabled'] ? '#047857' : '#b45309') . '">' . htmlspecialchars($choice['setup'], ENT_QUOTES, 'UTF-8') . '</small></label>';
        }
        $materialSelectionHtml .= '</fieldset>';
    }
    $materialSelectionHtml .= '</section>';
    $fields_html .= $materialSelectionHtml;
}

$needsMaterialDimensions = is_array($selectionPolicy)
    && printflow_pos_material_selection_requires_dimensions($service_id);
$dimensionConfigRows = $needsMaterialDimensions
    ? printflow_pos_service_dimension_configs($service_id)
    : null;
$dimensionDescriptor = $needsMaterialDimensions && is_array($dimensionConfigRows)
    ? printflow_pos_service_dimension_descriptor($dimensionConfigRows)
    : null;
$materialDimensionUnit = '';
if ($dimensionDescriptor !== null) {
    $materialDimensionUnit = (string)$dimensionDescriptor['unit'];
    if ($materialDimensionUnit === '' && $dimensionDescriptor['field_type'] === 'dimension') {
        $dimensionConfig = $dimensionDescriptor['row'];
        $dimensionConfig['type'] = $dimensionConfig['field_type'];
        $dimensionConfig['label'] = $dimensionConfig['field_label'] ?? '';
        $dimensionConfig['options'] = $dimensionConfig['_options'] ?? [];
        $context = printflow_service_field_custom_size_unit_context((string)$dimensionDescriptor['field_key'], $dimensionConfig);
        $materialDimensionUnit = (string)($context['unit'] ?? '');
    }
}
if ($needsMaterialDimensions && ($dimensionConfigRows === false || $dimensionDescriptor === null
    || ($dimensionDescriptor['field_type'] === 'dimension' && $materialDimensionUnit === ''))) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'This service must have one explicit dimension unit before area-based material consumption can be used.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'       => true,
    'service_id'    => $service_id,
    'name'          => $service['name'],
    'base_price'    => (float)($service['base_price'] ?? 0),
    'fields_html'   => $fields_html,
    'csrf_token'    => generate_csrf_token(),
    'staff_branch_id' => $staff_branch_id,
    'staff_branch_name' => $staff_branch_name,
    'material_dimension_unit' => $materialDimensionUnit,
    'material_dimension_field_key' => $dimensionDescriptor['field_key'] ?? '',
    'material_dimension_unit_per_option' => $dimensionDescriptor !== null
        && in_array($dimensionDescriptor['field_type'], ['select', 'radio'], true)
        && $materialDimensionUnit === '',
    'material_consumption_basis' => printflow_pos_material_selection_consumption_basis($service_id),
]);
