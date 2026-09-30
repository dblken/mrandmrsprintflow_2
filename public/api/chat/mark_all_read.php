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

$user_id = (int)get_user_id();
$user_type = (string)get_user_type();

if ($user_type === 'Customer') {
    $cutoff_rows = db_query(
        "SELECT MAX(m.message_id) AS max_id
         FROM order_messages m
         JOIN orders o ON o.order_id = m.order_id
         WHERE o.customer_id = ?
           AND m.sender = 'Staff'
           AND m.read_receipt < 2",
        'i',
        [$user_id]
    ) ?: [];
    $max_id = (int)($cutoff_rows[0]['max_id'] ?? 0);
    if ($max_id > 0) {
        db_execute(
            "UPDATE order_messages m
             JOIN orders o ON o.order_id = m.order_id
             SET m.read_receipt = 2
             WHERE o.customer_id = ?
               AND m.sender = 'Staff'
               AND m.read_receipt < 2
               AND m.message_id <= ?",
            'ii',
            [$user_id, $max_id]
        );
    }
} else {
    if (!in_array($user_type, ['Staff', 'Admin', 'Manager'], true)) {
        printflow_chat_json(['success' => false, 'error' => 'Unauthorized'], 403);
    }
    $branch_id = function_exists('printflow_branch_filter_for_user')
        ? printflow_branch_filter_for_user()
        : null;
    $branch_clause = $branch_id ? ' AND o.branch_id = ?' : '';
    $cutoff_rows = db_query(
        "SELECT MAX(m.message_id) AS max_id
         FROM order_messages m
         JOIN orders o ON o.order_id = m.order_id
         WHERE m.sender = 'Customer'
           AND m.read_receipt < 2{$branch_clause}",
        $branch_id ? 'i' : '',
        $branch_id ? [$branch_id] : []
    ) ?: [];
    $max_id = (int)($cutoff_rows[0]['max_id'] ?? 0);
    if ($max_id > 0) {
        db_execute(
            "UPDATE order_messages m
             JOIN orders o ON o.order_id = m.order_id
             SET m.read_receipt = 2
             WHERE m.sender = 'Customer'
               AND m.read_receipt < 2
               AND m.message_id <= ?{$branch_clause}",
            $branch_id ? 'ii' : 'i',
            $branch_id ? [$max_id, $branch_id] : [$max_id]
        );
    }
}

printflow_chat_json([
    'success' => true,
    'cutoff_message_id' => $max_id ?? 0,
    'remaining_unread_count' => printflow_chat_unread_count($user_id, $user_type),
]);
