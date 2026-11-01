<?php
/**
 * Admin: Save Tarpaulin Specifications API
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order_item_id = (int)($input['order_item_id'] ?? 0);
$roll_id      = (int)($input['roll_id'] ?? 0);
$width_ft     = (float)($input['width_ft'] ?? 0);
$height_ft    = (float)($input['height_ft'] ?? 0);

if (!$order_item_id || !$roll_id || $width_ft <= 0 || $height_ft <= 0) {
    echo json_encode(['success' => false, 'error' => 'All fields required']);
    exit;
}

// Get item quantity to calculate total required length
$item = db_query(
    'SELECT oi.quantity, oi.order_id, o.branch_id FROM order_items oi JOIN orders o ON o.order_id = oi.order_id WHERE oi.order_item_id = ? LIMIT 1',
    'i',
    [$order_item_id]
);
if (empty($item)) {
    echo json_encode(['success' => false, 'error' => 'Order item not found']);
    exit;
}
printflow_assert_order_branch_access((int)$item[0]['order_id']);
$order_branch_id = (int)($item[0]['branch_id'] ?? 0);
if ($order_branch_id <= 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'The order branch could not be verified. No roll was assigned.']);
    exit;
}
$qty = (int)$item[0]['quantity'];
$required_length = $height_ft * $qty;

// Require an open, branch-owned roll; NULL/zero ownership is not inferred.
$roll = db_query(
    "SELECT width_ft FROM inv_rolls WHERE id = ? AND branch_id = ? AND status = 'OPEN' AND remaining_length_ft > 0 LIMIT 1",
    'ii',
    [$roll_id, $order_branch_id]
);
if (empty($roll)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'The selected roll is not open and assigned to this order branch. No roll was assigned.']);
    exit;
}
// if ((int)$roll[0]['width_ft'] !== (int)$width_ft) {
//     echo json_encode(['success' => false, 'error' => 'Roll width mismatch']);
//     exit;
// }

// Upsert into order_tarp_details
$existing = db_query("SELECT order_item_id FROM order_tarp_details WHERE order_item_id = ?", 'i', [$order_item_id]);

if (!empty($existing)) {
    $ok = db_execute(
        "UPDATE order_tarp_details SET roll_id = ?, width_ft = ?, height_ft = ?, qty = ?, required_length_ft = ? WHERE order_item_id = ?",
        'iddidi', [$roll_id, $width_ft, $height_ft, $qty, $required_length, $order_item_id]
    );
} else {
    $ok = db_execute(
        "INSERT INTO order_tarp_details (order_item_id, roll_id, width_ft, height_ft, qty, required_length_ft) VALUES (?, ?, ?, ?, ?, ?)",
        'iiddid', [$order_item_id, $roll_id, $width_ft, $height_ft, $qty, $required_length]
    );
}

if ($ok) {
    echo json_encode(['success' => true, 'message' => 'Tarpaulin specifications saved']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save specifications']);
}
