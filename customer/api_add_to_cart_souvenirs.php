<?php
/**
 * AJAX API to Add Souvenirs Order to Cart/Session for Review
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

header('Content-Type: application/json');

function souvenir_redirect_url(string $path, array $query = []): string {
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

$requiredMsg = 'This field is required';

if (!is_logged_in() || !is_customer()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$fields = $_POST;
$branch_id_raw = trim($fields['branch_id'] ?? '');
$branch_id = $branch_id_raw === '' ? 0 : (int)$branch_id_raw;
$souvenir_type = trim($fields['souvenir_type'] ?? '');
$souvenir_type_other = trim($fields['souvenir_type_other'] ?? '');
$allowed_souvenir_types = ['Mug', 'Keychain', 'Tote Bag', 'Pen', 'Tumbler', 'T-Shirt', 'Others'];
$needed_date = trim($fields['needed_date'] ?? '');
$lamination = trim($fields['lamination'] ?? '');
$quantity = (int)($fields['quantity'] ?? 1);
$custom_print = trim($fields['custom_print'] ?? '');
$notes = trim($fields['notes'] ?? '');

if ($branch_id < 1 || empty(db_query("SELECT id FROM branches WHERE id = ? AND status = 'Active' LIMIT 1", 'i', [$branch_id]))) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid branch.']);
    exit;
}

if (!in_array($custom_print, ['Yes', 'No'], true)) {
    echo json_encode(['success' => false, 'message' => $requiredMsg]);
    exit;
}

if (!in_array($lamination, ['With Lamination', 'Without Lamination'], true)) {
    echo json_encode(['success' => false, 'message' => $requiredMsg]);
    exit;
}

if (!in_array($souvenir_type, $allowed_souvenir_types, true) || empty($needed_date) || $quantity < 1) {
    echo json_encode(['success' => false, 'message' => $requiredMsg]);
    exit;
}

if ($souvenir_type === 'Others') {
    $otherLen = function_exists('mb_strlen') ? mb_strlen($souvenir_type_other) : strlen($souvenir_type_other);
    if ($otherLen < 1 || $otherLen > 50) {
        echo json_encode(['success' => false, 'message' => $requiredMsg]);
        exit;
    }
}

$souvenir_type_display = ($souvenir_type === 'Others') ? $souvenir_type_other : $souvenir_type;

$design_tmp_path = null;
$design_name = null;
$design_mime = null;

if ($custom_print === 'Yes') {
    if (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => $requiredMsg]);
        exit;
    }
    
    $valid = service_order_validate_file($_FILES['design_file']);
    if (!$valid['ok']) {
        echo json_encode(['success' => false, 'message' => 'Design upload error: ' . $valid['error']]);
        exit;
    }
    
    $tmp_dir = service_order_temp_dir();
    $ext = pathinfo($_FILES['design_file']['name'], PATHINFO_EXTENSION);
    $tmp_filename = uniqid('souv_tmp_') . '.' . $ext;
    $design_tmp_path = $tmp_dir . DIRECTORY_SEPARATOR . $tmp_filename;
    
    if (move_uploaded_file($_FILES['design_file']['tmp_name'], $design_tmp_path)) {
        $design_name = $_FILES['design_file']['name'];
        $design_mime = $valid['mime'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to transform uploaded file.']);
        exit;
    }
}

$item_key = uniqid('item_');
$product_name = 'Souvenir: ' . $souvenir_type_display;

$cart_item = [
    'product_id' => 0,
    'source_page' => 'services',
    'branch_id'  => $branch_id,
    'name' => $product_name,
    'category' => 'Souvenirs',
    'price' => 0, // Pending review
    'quantity' => $quantity,
    'customization' => [
        'service_type' => 'Souvenirs',
        'souvenir_type' => $souvenir_type_display,
        'needed_date' => $needed_date,
        'lamination' => $lamination,
        'custom_print' => $custom_print,
        'notes' => $notes
    ],
    'design_tmp_path' => $design_tmp_path,
    'design_name' => $design_name,
    'design_mime' => $design_mime,
    'width' => '',
    'height' => '',
    'thickness' => '',
    'stand_type' => '',
    'cut_type' => '',
    'design_notes' => $notes
];

$_SESSION['cart'][$item_key] = $cart_item;

echo json_encode([
    'success' => true,
    'item_key' => $item_key,
    'redirect_url' => souvenir_redirect_url('/customer/order_review.php', ['item' => $item_key]),
    'cart_url' => souvenir_redirect_url('/customer/cart.php'),
]);

