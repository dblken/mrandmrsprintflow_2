<?php
require_once __DIR__ . '/../../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

function pf_support_chat_has_column(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = str_replace(['\\', "'"], ['', "\\'"], $column);
    if ($safeTable === '') {
        $cache[$key] = false;
        return $cache[$key];
    }

    $cache[$key] = !empty(db_query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"));
    return $cache[$key];
}

function pf_support_chat_touch_user(?int $userId, string $userType): void {
    if (!$userId || $userId <= 0) {
        return;
    }

    if ($userType === 'Customer') {
        if (pf_support_chat_has_column('customers', 'last_activity')) {
            db_execute("UPDATE customers SET last_activity = CURRENT_TIMESTAMP WHERE customer_id = ?", 'i', [$userId]);
        }
        return;
    }

    if (pf_support_chat_has_column('users', 'last_activity')) {
        db_execute("UPDATE users SET last_activity = CURRENT_TIMESTAMP WHERE user_id = ?", 'i', [$userId]);
    }
}

function pf_support_staff_online(): bool {
    if (!pf_support_chat_has_column('users', 'last_activity')) {
        return false;
    }

    $rows = db_query(
        "SELECT user_id
         FROM users
         WHERE role IN ('Admin', 'Manager', 'Staff')
           AND last_activity >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
         LIMIT 1"
    );

    return !empty($rows);
}

function pf_support_customer_online(?int $conversationId): bool {
    if (!$conversationId || $conversationId <= 0 || !pf_support_chat_has_column('customers', 'last_activity')) {
        return false;
    }

    $rows = db_query(
        "SELECT c.customer_id
         FROM chatbot_conversations cc
         INNER JOIN customers c ON c.customer_id = cc.customer_id
         WHERE cc.id = ?
           AND c.last_activity >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
         LIMIT 1",
        'i',
        [$conversationId]
    );

    return !empty($rows);
}

$userId = function_exists('is_logged_in') && is_logged_in() && function_exists('get_user_id')
    ? (int)get_user_id()
    : 0;
$userType = function_exists('is_logged_in') && is_logged_in() && function_exists('get_user_type')
    ? (string)get_user_type()
    : '';

$dbErrorBaseline = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
if ($userId > 0 && $userType !== '') {
    pf_support_chat_touch_user($userId, $userType);
}

$conversationId = isset($_REQUEST['conversation_id']) ? (int)$_REQUEST['conversation_id'] : 0;
$supportOnline = pf_support_staff_online();
$customerOnline = pf_support_customer_online($conversationId);
if (function_exists('printflow_db_errors') && count(printflow_db_errors()) > $dbErrorBaseline) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Support presence is temporarily unavailable.']);
    exit;
}

echo json_encode([
    'success' => true,
    'support_online' => $supportOnline,
    'customer_online' => $customerOnline,
]);
