<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

ob_start();
printflow_chat_require_login();

$user_id = (int)get_user_id();
$user_type = (string)get_user_type();
if ($user_type !== 'Customer' && $user_type !== 'Staff') {
    printflow_chat_json(['success' => false, 'error' => 'Chat access denied'], 403);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

printflow_chat_json([
    'success' => true,
    'unread_count' => printflow_chat_unread_count($user_id, $user_type),
]);
