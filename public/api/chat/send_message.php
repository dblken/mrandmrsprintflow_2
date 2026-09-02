<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

ob_start();
printflow_chat_require_login();
printflow_chat_require_post();
printflow_chat_require_csrf();
printflow_chat_rate_limit('send', 12, 10);

$order_id = (int)($_POST['order_id'] ?? 0);
$reply_id = (int)($_POST['reply_id'] ?? 0) ?: null;
$message = trim((string)($_POST['message'] ?? ''));
$user_id = (int)get_user_id();
$db_sender = get_user_type() === 'Customer' ? 'Customer' : 'Staff';

printflow_chat_authorize_order($order_id);
printflow_chat_validate_reply($reply_id, $order_id);

if (mb_strlen($message) > 2000) {
    printflow_chat_json(['success' => false, 'error' => 'Message cannot exceed 2,000 characters'], 422);
}

$files = [];
if (isset($_FILES['image'])) {
    $upload = $_FILES['image'];
    $is_array = is_array($upload['name']);
    $count = $is_array ? count($upload['name']) : 1;
    if ($count > 4) {
        printflow_chat_json(['success' => false, 'error' => 'You can send up to 4 images at a time'], 422);
    }
    for ($i = 0; $i < $count; $i++) {
        $files[] = [
            'name' => (string)($is_array ? $upload['name'][$i] : $upload['name']),
            'tmp_name' => (string)($is_array ? $upload['tmp_name'][$i] : $upload['tmp_name']),
            'error' => (int)($is_array ? $upload['error'][$i] : $upload['error']),
            'size' => (int)($is_array ? $upload['size'][$i] : $upload['size']),
        ];
    }
}

if ($message === '' && $files === []) {
    printflow_chat_json(['success' => false, 'error' => 'Enter a message or choose an image'], 422);
}

$client_token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['client_token'] ?? ''));
if ($client_token !== '') {
    $dedupe_key = 'pf_chat_client_' . hash('sha256', $order_id . '|' . $db_sender . '|' . $user_id . '|' . $client_token);
    $recent_tokens = array_filter(
        (array)($_SESSION['pf_chat_client_tokens'] ?? []),
        static fn($timestamp): bool => (int)$timestamp > time() - 300
    );
    if (!empty($recent_tokens[$dedupe_key])) {
        printflow_chat_json(['success' => true, 'messages_sent' => 0, 'duplicate_ignored' => true]);
    }
    $recent_tokens[$dedupe_key] = time();
    $_SESSION['pf_chat_client_tokens'] = array_slice($recent_tokens, -100, null, true);
}

$validated = [];
foreach ($files as $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        printflow_chat_json(['success' => false, 'error' => 'An image could not be uploaded'], 422);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        printflow_chat_json(['success' => false, 'error' => 'Invalid upload'], 422);
    }
    $inspection = printflow_chat_inspect_image($file['tmp_name'], $file['size']);
    if (!$inspection['success']) printflow_chat_json(['success' => false, 'error' => $inspection['error']], 422);
    $validated[] = $file + ['extension' => $inspection['extension']];
}

$upload_dir = __DIR__ . '/../../../uploads/chat/images';
if ($validated !== [] && !is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
    printflow_chat_json(['success' => false, 'error' => 'Image storage is unavailable'], 500);
}

$saved_paths = [];
$inserted_ids = [];
global $conn;
try {
    if ($conn) {
        $conn->begin_transaction();
    }
    if ($message !== '') {
        $ok = db_execute("INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt, reply_id) VALUES (?, ?, ?, ?, 'text', 0, ?)", 'isisi', [$order_id, $db_sender, $user_id, $message, $reply_id]);
        if (!$ok) throw new RuntimeException('Could not save message');
        $inserted_ids[] = (int)$conn->insert_id;
    }
    $base_path = rtrim(defined('BASE_PATH') ? BASE_PATH : '', '/');
    foreach ($validated as $file) {
        $filename = bin2hex(random_bytes(20)) . '.' . $file['extension'];
        $absolute_path = $upload_dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $absolute_path)) throw new RuntimeException('Could not save image');
        $saved_paths[] = $absolute_path;
        $public_path = $base_path . '/uploads/chat/images/' . $filename;
        $safe_original = mb_substr(basename($file['name']), 0, 190);
        $ok = db_execute("INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, image_path, file_type, file_path, message_file, file_name, file_size, read_receipt, reply_id) VALUES (?, ?, ?, '', 'image', ?, 'image', ?, ?, ?, ?, 0, ?)", 'isissssii', [$order_id, $db_sender, $user_id, $public_path, $public_path, $public_path, $safe_original, $file['size'], $reply_id]);
        if (!$ok) throw new RuntimeException('Could not save image message');
        $inserted_ids[] = (int)$conn->insert_id;
    }
    if ($conn) $conn->commit();
} catch (Throwable $error) {
    if ($conn) $conn->rollback();
    foreach ($saved_paths as $path) if (is_file($path)) @unlink($path);
    printflow_chat_json(['success' => false, 'error' => 'Message could not be sent'], 500);
}

printflow_notify_chat_message($order_id, $db_sender, $message === '' ? 'attachment' : 'message');
printflow_chat_json(['success' => true, 'messages_sent' => count($inserted_ids), 'message_ids' => $inserted_ids]);
