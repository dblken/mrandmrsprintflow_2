<?php
declare(strict_types=1);

function is_logged_in(): bool { return true; }
function get_user_id(): int { return 1; }
function get_user_type(): string { return 'Customer'; }
function verify_csrf_token($token): bool { return $token === 'valid'; }
function pf_default_profile_image_url(): string { return '/public/assets/uploads/profiles/default.png'; }
function get_profile_image($image): string {
    $known = [
        'staff_6_1777689842.jpg' => '/public/assets/uploads/profiles/staff_6_1777689842.jpg',
        'customer_25_1774758162.jpg' => '/public/assets/uploads/profiles/customer_25_1774758162.jpg',
        '/public/assets/uploads/profiles/staff_6_1777689842.jpg' => '/public/assets/uploads/profiles/staff_6_1777689842.jpg',
    ];
    return $known[(string)$image] ?? pf_default_profile_image_url();
}
function printflow_branch_filter_for_user(): ?int { global $fixtureBranchId; return $fixtureBranchId; }
$fixtureBranchId = 3;
$fixtureOrders = [101 => ['customer_id' => 7, 'branch_id' => 3], 102 => ['customer_id' => 7, 'branch_id' => 3], 201 => ['customer_id' => 8, 'branch_id' => 3], 301 => ['customer_id' => 9, 'branch_id' => 4]];
$fixtureMessages = [
    ['order_id' => 101, 'sender' => 'Staff', 'read_receipt' => 0],
    ['order_id' => 102, 'sender' => 'Staff', 'read_receipt' => 1],
    ['order_id' => 101, 'sender' => 'Staff', 'read_receipt' => 2],
    ['order_id' => 101, 'sender' => 'Customer', 'read_receipt' => 0],
    ['order_id' => 102, 'sender' => 'Customer', 'read_receipt' => 0],
    ['order_id' => 201, 'sender' => 'Customer', 'read_receipt' => 1],
    ['order_id' => 201, 'sender' => 'Customer', 'read_receipt' => 2],
    ['order_id' => 201, 'sender' => 'Staff', 'read_receipt' => 0],
    ['order_id' => 301, 'sender' => 'Customer', 'read_receipt' => 0],
];
$lastDbQuery = [];
function db_query(string $sql, string $types = '', array $params = []): array {
    global $lastDbQuery, $fixtureOrders, $fixtureMessages;
    $lastDbQuery = compact('sql', 'types', 'params');
    $sender = str_contains($sql, "m.sender = 'Staff'") ? 'Staff' : 'Customer';
    $customerId = str_contains($sql, 'o.customer_id = ?') ? (int)($params[0] ?? 0) : null;
    $branchId = str_contains($sql, 'o.branch_id = ?') ? (int)($params[0] ?? 0) : null;
    $count = 0;
    foreach ($fixtureMessages as $message) {
        $order = $fixtureOrders[$message['order_id']] ?? null;
        if (!$order || $message['sender'] !== $sender || $message['read_receipt'] >= 2) continue;
        if ($customerId !== null && $order['customer_id'] !== $customerId) continue;
        if ($branchId !== null && $order['branch_id'] !== $branchId) continue;
        $count++;
    }
    return [['unread_count' => $count]];
}

require dirname(__DIR__) . '/includes/chat_http.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$page = printflow_chat_pagination([]);
$expect($page === ['after_id' => 0, 'before_id' => 0, 'limit' => 40], 'Default pagination must request a 40-message initial window.');
$page = printflow_chat_pagination(['after_id' => '91', 'limit' => '500']);
$expect($page['after_id'] === 91 && $page['limit'] === 50, 'Incremental pagination must clamp limit to 50.');
$page = printflow_chat_pagination(['after_id' => '91', 'before_id' => '50', 'limit' => '0']);
$expect($page === ['after_id' => 0, 'before_id' => 50, 'limit' => 1], 'Older-message cursor must take precedence and enforce a positive limit.');

foreach (['like', 'love', 'haha', 'wow', 'sad', 'angry'] as $reaction) {
    $expect(printflow_chat_reaction_allowed($reaction), "Allowed reaction {$reaction} was rejected.");
}
$expect(!printflow_chat_reaction_allowed('script'), 'Unknown reactions must be rejected.');

$expect(
    printflow_chat_profile_image_url('staff_6_1777689842.jpg') === '/public/assets/uploads/profiles/staff_6_1777689842.jpg',
    'Stored profile filenames must resolve through the canonical profile-image helper.'
);
$expect(
    printflow_chat_profile_image_url('customer_25_1774758162.jpg') === '/public/assets/uploads/profiles/customer_25_1774758162.jpg',
    'Customer profile filenames must resolve through the same canonical profile-image helper.'
);
$expect(
    printflow_chat_profile_image_url('/public/assets/uploads/profiles/staff_6_1777689842.jpg') === '/public/assets/uploads/profiles/staff_6_1777689842.jpg',
    'Already resolved application profile paths must remain stable.'
);
$expect(printflow_chat_profile_image_url('staff_6_1777689842.jpg') !== '/staff_6_1777689842.jpg', 'Profile filenames must never become domain-root image URLs.');
$expect(printflow_chat_profile_image_url('missing.jpg') === '', 'Missing profile files must use initials instead of a broken/default image URL.');
$expect(printflow_chat_profile_image_url('') === '', 'Empty profile values must use initials without issuing an image request.');

$expect(printflow_chat_unread_count(7, 'Customer') === 2, 'Customer total must sum incoming unread staff messages across conversations only.');
$expect(str_contains($lastDbQuery['sql'], "m.sender = 'Staff'") && $lastDbQuery['params'] === [7], 'Customer unread query must be scoped to the authenticated customer.');
$expect(printflow_chat_unread_count(9, 'Staff') === 3, 'Staff total must sum unread customer messages across permitted conversations only.');
$expect(str_contains($lastDbQuery['sql'], "m.sender = 'Customer'") && str_contains($lastDbQuery['sql'], 'o.branch_id = ?') && $lastDbQuery['params'] === [3], 'Staff unread query must exclude own messages and enforce the staff branch filter.');
$fixtureMessages[3]['read_receipt'] = 2;
$fixtureMessages[4]['read_receipt'] = 2;
$expect(printflow_chat_unread_count(9, 'Staff') === 1, 'Reading one conversation must reduce the staff total without clearing another conversation.');
$expect(printflow_chat_unread_count(8, 'Customer') === 1, 'Each customer unread total must remain isolated to that customer account.');

$png = tempnam(sys_get_temp_dir(), 'pf-chat-png-');
$text = tempnam(sys_get_temp_dir(), 'pf-chat-text-');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
file_put_contents($text, '<svg onload="alert(1)"></svg>');
$validImage = printflow_chat_inspect_image($png, filesize($png));
$invalidImage = printflow_chat_inspect_image($text, filesize($text));
$expect($validImage['success'] && $validImage['extension'] === 'png', 'A genuine PNG must pass server-side image inspection.');
$expect(!$invalidImage['success'], 'SVG/text content must fail server-side image inspection.');
$expect(printflow_chat_resolve_upload_path('../.env') === false, 'Attachment path traversal must not escape the uploads directory.');
@unlink($png);
@unlink($text);

$fixture = __DIR__ . '/fixtures/chat_http_authorization_case.php';
$run = static function (string $scenario) use ($fixture): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($scenario);
    return (string)shell_exec($command);
};
$expect(str_contains($run('owner'), 'AUTHORIZED:42'), 'A customer must access their own conversation.');
$expect(str_contains($run('forbidden'), 'Conversation access denied'), 'A customer must not access another customer conversation.');
$expect(str_contains($run('branch'), 'BRANCH_CHECKED:42') && str_contains($run('branch'), 'AUTHORIZED:42'), 'Staff authorization must enforce branch access.');
$expect(str_contains($run('bad_reply'), 'Reply must reference a message in this conversation'), 'Cross-conversation or missing reply IDs must be rejected.');

$root = dirname(__DIR__);
$chatCss = (string)file_get_contents($root . '/public/assets/css/chat_http.css');
$chatJs = (string)file_get_contents($root . '/public/assets/js/chat_http.js');
$fetchMessages = (string)file_get_contents($root . '/public/api/chat/fetch_messages.php');
$sendMessage = (string)file_get_contents($root . '/public/api/chat/send_message.php');
$chatSchema = (string)file_get_contents($root . '/includes/ensure_order_messages.php');
$expect(
    str_contains($chatJs, "row.className = `pf-message-row \${message.is_self ? 'self' : 'other'}`"),
    'Every message type uses the same server-owned is_self row classification.'
);
$expect(
    str_contains($fetchMessages, "\$is_self = \$sender_type !== null ? (\$sender_type === \$current_user_type) : false;")
        && str_contains($fetchMessages, "if (\$sender === 'Customer')")
        && str_contains($fetchMessages, "if (\$sender === 'Staff')"),
    'Message ownership is derived server-side from the canonical authenticated participant type.'
);
$expect(
    substr_count($sendMessage, "\$db_sender, \$user_id") >= 2
        && str_contains($chatSchema, "ENUM('Customer','Staff','System')"),
    'Current text/image inserts and historical schema use the same canonical sender identity fields.'
);
$expect(
    str_contains($chatCss, '.pf-message-row.self .pf-message-bubble.pf-media-only-message { justify-content: flex-end; }')
        && str_contains($chatCss, '.pf-message-row.other .pf-message-bubble.pf-media-only-message { justify-content: flex-start; }'),
    'Intrinsic image space aligns to the sender edge for both self and incoming media-only messages.'
);
$expect(
    str_contains($chatCss, 'display: flex; width: fit-content; flex: 0 1 auto; padding: 0;')
        && str_contains($chatCss, 'background: transparent; border: 0;')
        && str_contains($chatCss, 'width: auto; height: auto;')
        && str_contains($chatCss, 'object-fit: contain;'),
    'Media-only wrappers remain transparent and responsive images retain their aspect ratio.'
);
$expect(
    str_contains($chatCss, '.pf-message-row.self .pf-reply-text { color: #fff; opacity: 1; }'),
    'Outgoing reply contrast remains protected.'
);

if ($failures) {
    fwrite(STDERR, "Chat HTTP unit test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Chat HTTP unit test passed.\n";
