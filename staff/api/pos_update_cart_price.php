<?php
/**
 * API: Update POS Cart Item Price
 * Called when returning from customizations page with updated price
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!is_array($data) || !verify_csrf_token((string)($data['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

if (!isset($data['index']) || !isset($data['price'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

$index = (int)$data['index'];
$price = (float)$data['price'];

if (!isset($_SESSION['pos_cart'][$index])) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found.']);
    exit;
}

if ($price < 0) {
    echo json_encode(['success' => false, 'message' => 'Price cannot be negative.']);
    exit;
}

$_SESSION['pos_cart'][$index]['price'] = $price;
$_SESSION['pos_cart'][$index]['price_set'] = true;

echo json_encode([
    'success' => true,
    'cart' => array_values($_SESSION['pos_cart'])
]);
