<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_rate_order.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || strcasecmp((string)(get_user_type() ?? ''), 'Customer') !== 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$customer_id = (int)get_user_id();
if ($customer_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $order_id = (int)($_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid order.']);
        exit;
    }

    $context = customer_rate_order_load($order_id, $customer_id);
    if ($context === null) {
        echo json_encode([
            'success' => true,
            'eligible' => false,
            'can_prompt' => false,
            'dismissed' => false,
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'eligible' => !empty($context['can_submit']),
        'can_prompt' => !empty($context['can_prompt']),
        'dismissed' => !empty($context['prompt_dismissed']),
        'order_id' => (int)$context['order_id'],
        'order_code' => (string)$context['order_code'],
        'service_label' => (string)$context['service_type_label'],
        'message' => 'Your order has been successfully picked up. We hope to see you again!',
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = (string)($input['action'] ?? $_POST['action'] ?? '');
$order_id = (int)($input['order_id'] ?? $_POST['order_id'] ?? 0);

if ($action !== 'dismiss') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

if (!verify_csrf_token($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid order.']);
    exit;
}

$context = customer_rate_order_load($order_id, $customer_id);
if ($context === null || empty($context['can_submit'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Order not eligible for review prompt.']);
    exit;
}

if (!customer_review_prompt_dismiss($customer_id, $order_id)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save your preference.']);
    exit;
}

echo json_encode(['success' => true, 'order_id' => $order_id]);
