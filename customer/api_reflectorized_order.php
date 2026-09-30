<?php
/**
 * AJAX API for Reflectorized Signage Order Submission
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !has_role('Customer')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

$customer_id = get_user_id();

// Collect all POST data
$fields = $_POST;
unset($fields['csrf_token']); // Security token not needed in stored details

// Handle Booleans/Checkboxes properly for display
$checkboxes = ['with_border', 'rounded_corners', 'with_numbering', 'install_service', 'need_proof'];
foreach ($checkboxes as $cb) {
    $fields[$cb] = isset($_POST[$cb]) ? 'Yes' : 'No';
}

// Basic Validation
$isTempPlate = ($fields['product_type'] === 'Plate Number / Temporary Plate');
if (empty($fields['product_type']) || (empty($fields['dimensions']) && !$isTempPlate) || $fields['quantity'] < 1) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

$files = [];
if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
    $valid = service_order_validate_file($_FILES['logo_file']);
    if (!$valid['ok']) {
        echo json_encode(['success' => false, 'message' => 'Logo upload error: ' . $valid['error']]);
        exit;
    }
    $files[] = ['file' => $_FILES['logo_file'], 'prefix' => 'logo'];
}

// Create the service order
$result = service_order_create('Reflectorized Signage', $customer_id, $fields, $files);

if ($result['success']) {
    $_SESSION['order_success_id'] = $result['order_id'];
    echo json_encode(['success' => true, 'order_id' => $result['order_id']]);
} else {
    echo json_encode(['success' => false, 'message' => $result['error'] ?: 'Failed to create order.']);
}

