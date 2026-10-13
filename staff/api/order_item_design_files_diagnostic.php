<?php
/**
 * Read-only design file count diagnostic for one order item (staff/admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/order_item_design_files.php';
require_once __DIR__ . '/../../includes/branch_context.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !in_array(get_user_type(), ['Staff', 'Admin', 'Manager'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$orderItemId = max(0, (int)($_GET['order_item_id'] ?? 0));
if ($orderItemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'order_item_id is required']);
    exit;
}

if (!printflow_order_item_design_file_assert_order_item_access($orderItemId, (int)get_user_id(), true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$payload = printflow_order_item_design_files_diagnostic($orderItemId);
if (empty($payload['success'])) {
    http_response_code(404);
}
echo json_encode($payload);
