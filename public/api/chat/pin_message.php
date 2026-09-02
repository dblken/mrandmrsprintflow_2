<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

ob_start();
header('Content-Type: application/json; charset=utf-8');

printflow_chat_require_login();
printflow_chat_require_post();
printflow_chat_require_csrf();
printflow_chat_rate_limit('pin', 12, 10);

$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
if ($message_id <= 0) {
    printflow_chat_json(['success' => false, 'error' => 'Missing message ID'], 422);
}

$row = db_query('SELECT message_id, order_id, is_pinned FROM order_messages WHERE message_id = ?', 'i', [$message_id]);
if (empty($row)) {
    printflow_chat_json(['success' => false, 'error' => 'Message not found'], 404);
}

printflow_chat_authorize_order((int)$row[0]['order_id']);

$next = empty($row[0]['is_pinned']) ? 1 : 0;
$ok = db_execute('UPDATE order_messages SET is_pinned = ? WHERE message_id = ?', 'ii', [$next, $message_id]);

printflow_chat_json(['success' => (bool)$ok, 'is_pinned' => (bool)$next]);
