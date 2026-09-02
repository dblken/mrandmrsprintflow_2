<?php
declare(strict_types=1);

$scenario = $argv[1] ?? '';

function is_logged_in(): bool { return true; }
function get_user_id(): int { global $scenario; return $scenario === 'forbidden' ? 99 : 7; }
function get_user_type(): string { global $scenario; return $scenario === 'branch' ? 'Staff' : 'Customer'; }
function printflow_assert_order_branch_access(int $order_id): void { echo "BRANCH_CHECKED:$order_id\n"; }
function verify_csrf_token($token): bool { return $token === 'valid'; }
function db_query(string $sql, string $types = '', array $params = []): array {
    global $scenario;
    if (str_contains($sql, 'FROM orders')) {
        return [['order_id' => 42, 'customer_id' => 7, 'branch_id' => 3]];
    }
    if (str_contains($sql, 'FROM order_messages')) {
        return $scenario === 'bad_reply' ? [] : [['order_id' => 42]];
    }
    return [];
}

require dirname(__DIR__, 2) . '/includes/chat_http.php';

if ($scenario === 'owner' || $scenario === 'forbidden' || $scenario === 'branch') {
    $order = printflow_chat_authorize_order(42);
    echo 'AUTHORIZED:' . $order['order_id'];
} elseif ($scenario === 'bad_reply') {
    printflow_chat_validate_reply(123, 42);
}
