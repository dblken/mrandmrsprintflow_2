<?php
/**
 * Staff API - Review Reply
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// Ensure tables exist before processing
ensure_ratings_table_exists();

if (!in_array($_SESSION['user_type'] ?? '', ['Staff', 'Admin'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid data input.']);
    exit;
}

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

$review_id = (int)($input['review_id'] ?? 0);
$message = trim((string)($input['message'] ?? ''));
$staff_id = get_user_id();

if ($review_id <= 0 || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
    exit;
}

if (mb_strlen($message) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Reply is too long (max 1000 chars).']);
    exit;
}

try {
    // Check if review exists
    $review_columns = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
    $review_customer_expr = isset($review_columns['customer_id']) ? 'r.customer_id' : (isset($review_columns['user_id']) ? 'r.user_id' : '0');
    $branchFilter = printflow_branch_filter_for_user();
    $branchSql = '';
    $types = 'i';
    $params = [$review_id];
    if ($branchFilter !== null) {
        $branchSql = ' AND (o.branch_id = ? OR r.order_id IS NULL OR r.order_id = 0)';
        $types .= 'i';
        $params[] = (int)$branchFilter;
    }
    $review_check = db_query("
        SELECT r.id, {$review_customer_expr} AS customer_id, r.order_id
        FROM reviews r
        LEFT JOIN orders o ON o.order_id = r.order_id
        WHERE r.id = ? {$branchSql}
        LIMIT 1
    ", $types, $params);
    if (empty($review_check)) {
        echo json_encode(['success' => false, 'error' => 'Review not found.']);
        exit;
    }

    $customer_id = (int)$review_check[0]['customer_id'];
    $order_id = (int)$review_check[0]['order_id'];

    db_execute("
        INSERT INTO review_replies (review_id, staff_id, reply_message, created_at)
        VALUES (?, ?, ?, NOW())
    ", 'iis', [$review_id, $staff_id, $message]);

    // Notify customer
    $notif_msg = "PrintFlow Staff replied to your review.";
    create_notification($customer_id, 'Customer', $notif_msg, 'Rating', false, false, $order_id, $review_id);

    echo json_encode(['success' => true, 'message' => 'Reply posted successfully.']);
} catch (Throwable $e) {
    error_log("Error in api_review_reply: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
