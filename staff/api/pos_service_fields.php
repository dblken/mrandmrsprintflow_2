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

// Needed Date is always bounded to the Manila business calendar day.
// Custom transaction datetime (sale stamp) is unrelated and must not reopen past Needed Dates.
$GLOBALS['printflow_allow_past_needed_date'] = false;

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
        $needed_date_min = function_exists('printflow_pos_business_today')
            ? printflow_pos_business_today()
            : date('Y-m-d');
        $needed_date_min_attr = ' min="' . htmlspecialchars($needed_date_min, ENT_QUOTES, 'UTF-8') . '"';
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
                <button type="button" class="qty-btn-minus" style="background:none;border:none;color:#6b7280;font-size:1.125rem;font-weight:600;cursor:pointer;" onclick="window.pfChangeQty(this, -1)">&minus;</button>
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="5" autocomplete="off" id="quantity-input" name="quantity" class="qty-input-field pf-service-quantity-input" data-pos-quantity-max="10000" style="border:none;text-align:center;width:60px;font-size:.875rem;font-weight:500;color:#374151;background:transparent;outline:none;position:relative;z-index:1;pointer-events:auto;user-select:text;cursor:text;" value="1" aria-label="Quantity" onfocus="this.select()">
                <button type="button" class="qty-btn-plus" style="background:none;border:none;color:#6b7280;font-size:1.125rem;font-weight:600;cursor:pointer;" onclick="window.pfChangeQty(this, 1)">+</button>
            </div>
            <div class="pf-service-quantity-limit-hint" style="display:none;color:#dc2626;font-size:12px;font-weight:700;margin-top:4px;">Maximum quantity reached (10,000)</div>
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

$selectionPolicy = printflow_pos_material_selection_registry()[$service_id] ?? null;

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

require_once __DIR__ . '/../../includes/mugs_base_pricing.php';
$basePrice = (float)($service['base_price'] ?? 0);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'       => true,
    'service_id'    => $service_id,
    'name'          => $service['name'],
    'base_price'    => $basePrice,
    // Mugs unit selling price comes only from services.price (never inventory avg cost).
    'price_configured' => !printflow_is_mugs_service($service_id) || $basePrice >= 0.01,
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
    'material_selection_field_map' => printflow_pos_material_selection_field_map($service_id),
]);
