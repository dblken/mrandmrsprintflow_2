<?php
/**
 * AJAX API to Add Reflectorized Order to Cart/Session for Review
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

header('Content-Type: application/json');

function reflectorized_redirect_url(string $path, array $query = []): string {
    $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : (function_exists('pf_app_base_path') ? pf_app_base_path() : '')), '/');
    $path = '/' . ltrim($path, '/');
    $relative = $base . $path;
    if (!empty($query)) {
        $relative .= '?' . http_build_query($query);
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $relative;
    }

    $is_https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($is_https ? 'https://' : 'http://') . $host . $relative;
}

if (!is_logged_in() || !is_customer()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$customer_id = get_user_id();
$raw_fields = $_POST;

$type = $raw_fields['product_type'] ?? '';
$isTempPlate = ($type === 'Plate Number / Temporary Plate');
$isGatePass = (strpos($type, 'Subdivision / Gate Pass') !== false || strpos($type, 'Gate Pass Sticker') !== false);
$isSignage = (strpos($type, 'Sign') !== false || strpos($type, 'Street') !== false);

$fields = [];
$fields['service_type'] = $raw_fields['service_type'] ?? 'Reflectorized Signage';
$fields['product_type'] = $type;
$fields['branch_id'] = trim($raw_fields['branch_id'] ?? '');
$branchId = (int)$fields['branch_id'];
if ($branchId < 1 || empty(db_query("SELECT id FROM branches WHERE id = ? AND status = 'Active' LIMIT 1", 'i', [$branchId]))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please select a valid branch.']);
    exit;
}
$fields['needed_date'] = trim($raw_fields['needed_date'] ?? '');

$fields['quantity'] = 1;
if (!empty($raw_fields['quantity_gatepass'])) {
    $fields['quantity'] = (int)$raw_fields['quantity_gatepass'];
} elseif (!empty($raw_fields['quantity_signage'])) {
    $fields['quantity'] = (int)$raw_fields['quantity_signage'];
} elseif (!empty($raw_fields['quantity'])) {
    $fields['quantity'] = (int)$raw_fields['quantity'];
}

if ($isTempPlate) {
    foreach(['temp_plate_material', 'temp_plate_number', 'temp_plate_text', 'mv_file_number', 'dealer_name', 'other_instructions'] as $f) {
        if(isset($raw_fields[$f]) && trim($raw_fields[$f]) !== '') $fields[$f] = $raw_fields[$f];
    }
    $fields['dimensions'] = 'Standard';
} elseif ($isGatePass) {
    foreach(['gate_pass_subdivision', 'gate_pass_number', 'gate_pass_plate', 'gate_pass_year', 'gate_pass_vehicle_type', 'dimensions', 'unit', 'other_instructions'] as $f) {
        if(isset($raw_fields[$f]) && trim($raw_fields[$f]) !== '') $fields[$f] = $raw_fields[$f];
    }
} elseif ($isSignage) {
    // Custom Reflectorized Sign
    foreach(['dimensions', 'unit', 'material_type', 'layout', 'laminate_option', 'reflective_color', 'other_instructions'] as $f) {
        if(isset($raw_fields[$f]) && trim($raw_fields[$f]) !== '') $fields[$f] = trim($raw_fields[$f]);
    }
} else {
    // Fallback or Other types
    foreach(['dimensions', 'unit', 'material_type', 'layout', 'laminate_option', 'other_instructions'] as $f) {
        if(isset($raw_fields[$f]) && trim($raw_fields[$f]) !== '') $fields[$f] = trim($raw_fields[$f]);
    }
}

// Validation based only on selected category
if (empty($fields['product_type']) || empty($fields['needed_date']) || $fields['quantity'] < 1) {
    echo json_encode(['success' => false, 'message' => 'Please fill in product type, quantity and needed date.']);
    exit;
}

if ($isTempPlate) {
    if (empty($fields['temp_plate_material']) || empty($fields['temp_plate_number'])) {
        echo json_encode(['success' => false, 'message' => 'Please select material and enter plate number for temporary plate.']);
        exit;
    }
} elseif ($isGatePass) {
    if (empty($fields['gate_pass_subdivision']) || empty($fields['gate_pass_number']) || empty($fields['gate_pass_plate']) || empty($fields['gate_pass_year']) || empty($fields['dimensions'])) {
        echo json_encode(['success' => false, 'message' => 'Please complete all required fields for gate pass sticker.']);
        exit;
    }
} elseif ($isSignage) {
    if (empty($fields['dimensions']) || empty($fields['material_type']) || empty($fields['layout'])) {
        echo json_encode(['success' => false, 'message' => 'Please complete all required fields for custom reflectorized sign.']);
        exit;
    }
}

// Handle Logo/Design File
$design_tmp_path = null;
$design_name = null;
$design_mime = null;

$logo_key = 'logo_file';
if (isset($_FILES['gate_pass_logo']) && $_FILES['gate_pass_logo']['error'] === UPLOAD_ERR_OK) {
    $logo_key = 'gate_pass_logo';
} elseif (isset($_FILES['signage_logo']) && $_FILES['signage_logo']['error'] === UPLOAD_ERR_OK) {
    $logo_key = 'signage_logo';
}

if (isset($_FILES[$logo_key]) && $_FILES[$logo_key]['error'] === UPLOAD_ERR_OK) {
    $valid = service_order_validate_file($_FILES[$logo_key]);
    if (!$valid['ok']) {
        echo json_encode(['success' => false, 'message' => 'Logo upload error: ' . $valid['error']]);
        exit;
    }
    
    $tmp_dir = service_order_temp_dir();
    $ext = pathinfo($_FILES[$logo_key]['name'], PATHINFO_EXTENSION);
    $tmp_filename = uniqid('ref_tmp_') . '.' . $ext;
    $design_tmp_path = $tmp_dir . DIRECTORY_SEPARATOR . $tmp_filename;
    
    if (move_uploaded_file($_FILES[$logo_key]['tmp_name'], $design_tmp_path)) {
        $design_name = $_FILES[$logo_key]['name'];
        $design_mime = $valid['mime'];
    }
}

// Prepare Cart Item
$item_key = uniqid('item_');
$product_name = $fields['product_type'];
$price = 0; // Service orders usually have price determined after review or via helper

// For Reflectorized, let's try to get a base price if possible, or default to 0
$price = 0; 
if ($isTempPlate) $price = 450; // Example static price for temp plates if needed

// Ensure branch_id is not duplicated in customization if stored at top level
$customization = $fields;
unset($customization['branch_id']);

$cart_item = [
    'product_id' => 0, // 0 for service/custom items not in products table
    'source_page' => 'services',
    'branch_id'  => $fields['branch_id'],
    'name' => 'Reflectorized: ' . $product_name,
    'category' => 'Reflectorized Signage',
    'price' => $price,
    'quantity' => (int)$fields['quantity'],
    'customization' => $customization,
    'design_tmp_path' => $design_tmp_path,
    'design_name' => $design_name,
    'design_mime' => $design_mime,
    // Mapping for standard order_review.php compatibility
    'width' => $fields['dimensions'] ?? '',
    'height' => '',
    'thickness' => '',
    'stand_type' => '',
    'lamination' => '',
    'cut_type' => $fields['shape'] ?? '',
    'design_notes' => $fields['other_instructions'] ?? ''
];

$_SESSION['cart'][$item_key] = $cart_item;

echo json_encode([
    'success' => true,
    'item_key' => $item_key,
    'redirect_url' => reflectorized_redirect_url('/customer/order_review.php', ['item' => $item_key]),
    'cart_url' => reflectorized_redirect_url('/customer/cart.php'),
]);

