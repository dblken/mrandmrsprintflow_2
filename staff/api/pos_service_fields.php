<?php
/**
 * Staff POS API: Render service form fields HTML for a given service_id
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/service_field_renderer.php';

if (!is_logged_in() || (!is_staff() && !is_admin())) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$service_id = (int)($_GET['service_id'] ?? 0);
if ($service_id < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid service']);
    exit;
}

$service = db_query("SELECT service_id, name, category FROM services WHERE service_id = ? AND status = 'Activated'", 'i', [$service_id]);
if (empty($service)) {
    echo json_encode(['success' => false, 'error' => 'Service not found']);
    exit;
}
$service = $service[0];

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name ASC") ?: [];

require_once __DIR__ . '/../../includes/branch_context.php';
$staff_branch_id = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 0);

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
            <input type="date" name="needed_date" class="shopee-opt-btn" required min="' . date('Y-m-d') . '" style="width:175px;cursor:pointer;">
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

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'       => true,
    'service_id'    => $service_id,
    'name'          => $service['name'],
    'fields_html'   => $fields_html,
    'csrf_token'    => generate_csrf_token(),
    'staff_branch_id' => $staff_branch_id,
]);
