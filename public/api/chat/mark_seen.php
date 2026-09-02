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

$order_id = (int)($_POST['order_id'] ?? 0);
$up_to_id = max(0, (int)($_POST['up_to_id'] ?? 0));
printflow_chat_authorize_order($order_id);

$target_sender = get_user_type() === 'Customer' ? 'Staff' : 'Customer';
if ($up_to_id > 0) {
    $ok = db_execute(
        'UPDATE order_messages SET read_receipt = 2 WHERE order_id = ? AND sender = ? AND message_id <= ? AND read_receipt < 2',
        'isi',
        [$order_id, $target_sender, $up_to_id]
    );
} else {
    $ok = true;
}

printflow_chat_json(['success' => (bool)$ok, 'up_to_id' => $up_to_id]);
