<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/chat_http.php';

ob_start();
printflow_chat_require_login();
$message_id = max(0, (int)($_GET['message_id'] ?? 0));
$rows = db_query(
    "SELECT order_id, message_type, file_type, COALESCE(NULLIF(message_file, ''), NULLIF(file_path, ''), image_path) AS stored_path FROM order_messages WHERE message_id = ? LIMIT 1",
    'i',
    [$message_id]
);
if (empty($rows) || strtolower((string)($rows[0]['message_type'] ?: $rows[0]['file_type'])) !== 'video') {
    http_response_code(404);
    exit('Video not found');
}
printflow_chat_authorize_order((int)$rows[0]['order_id']);
$candidate = printflow_chat_resolve_upload_path((string)$rows[0]['stored_path']);
if (!$candidate) {
    http_response_code(404);
    exit('Video not found');
}
$mime_map = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
$extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
if (!isset($mime_map[$extension])) {
    http_response_code(415);
    exit('Unsupported media');
}
if (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime_map[$extension]);
header('Content-Length: ' . filesize($candidate));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
readfile($candidate);
exit;
