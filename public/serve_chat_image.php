<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/chat_http.php';

ob_start();
printflow_chat_require_login();

$message_id = max(0, (int)($_GET['message_id'] ?? 0));
$rows = db_query(
    "SELECT order_id, COALESCE(NULLIF(message_file, ''), NULLIF(image_path, ''), file_path) AS stored_path FROM order_messages WHERE message_id = ? AND (message_type = 'image' OR file_type = 'image') LIMIT 1",
    'i',
    [$message_id]
);
if (empty($rows)) {
    http_response_code(404);
    exit('Image not found');
}
printflow_chat_authorize_order((int)$rows[0]['order_id']);

$candidate = printflow_chat_resolve_upload_path((string)$rows[0]['stored_path']);
if (!$candidate) {
    http_response_code(404);
    exit('Image not found');
}

$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($candidate);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || @getimagesize($candidate) === false) {
    http_response_code(415);
    exit('Unsupported image');
}

if (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($candidate));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($candidate);
exit;
