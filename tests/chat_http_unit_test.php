<?php
declare(strict_types=1);

function is_logged_in(): bool { return true; }
function get_user_id(): int { return 1; }
function get_user_type(): string { return 'Customer'; }
function verify_csrf_token($token): bool { return $token === 'valid'; }

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

if ($failures) {
    fwrite(STDERR, "Chat HTTP unit test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Chat HTTP unit test passed.\n";
