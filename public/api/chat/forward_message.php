<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

ob_start();
header('Content-Type: application/json');

printflow_chat_require_login();
printflow_chat_require_post();
printflow_chat_require_csrf();
printflow_chat_rate_limit('forward', 10, 10);

$target_order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$original_message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$user_id = get_user_id();
$user_type = get_user_type();

if (!$target_order_id || !$original_message_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Missing target order ID or original message ID']);
    exit();
}

// 1. Fetch the original message
$orig = db_query("SELECT * FROM order_messages WHERE message_id = ?", 'i', [$original_message_id]);
if (!$orig) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Original message not found']);
    exit();
}
$orig = $orig[0];
printflow_chat_authorize_order((int)$orig['order_id']);

// 2. Access control for target order
printflow_chat_authorize_order($target_order_id);

// 3. Prepare the forwarded message
$db_sender = ($user_type === 'Customer') ? 'Customer' : 'Staff';
$message_text = trim($orig['message'] ?? '');
$message_type = $orig['message_type'];

// If the message is just the legacy fallback string, clear it for media messages
if (($message_type !== 'text' && $message_type !== 'message') && $message_text === '[Forwarded Attachment]') {
    $message_text = '';
}
// Note: We no longer prepend "[Forwarded]:" because we now use a dedicated UI indicator via is_forwarded column.

// 4. Insert the new message
$sql = "INSERT INTO order_messages (
            order_id, sender, sender_id, message, message_type, 
            image_path, file_type, file_path, message_file, 
            file_name, file_size, is_forwarded, read_receipt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)";

$success = db_execute($sql, 'isisssssssi', [
    $target_order_id, 
    $db_sender, 
    $user_id, 
    $message_text, 
    $message_type,
    $orig['image_path'],
    $orig['file_type'],
    $orig['file_path'],
    $orig['message_file'],
    $orig['file_name'],
    $orig['file_size']
]);

if ($success) {
    // 5. Notify the opposite side
    $message_kind = ($message_type === 'text' || $message_type === 'message') ? 'message' : 'attachment';
    printflow_notify_chat_message($target_order_id, $db_sender, $message_kind);

    ob_end_clean();
    echo json_encode(['success' => true]);
} else {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to insert forwarded message']);
}
?>
