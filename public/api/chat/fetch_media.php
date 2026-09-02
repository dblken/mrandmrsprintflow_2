<?php
/**
 * Fetch all shared media for a specific conversation.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

// Global Output Buffer to trap notices
ob_start();

header('Content-Type: application/json');

printflow_chat_require_login();

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$user_id = get_user_id();
$user_type = get_user_type();

if (!$order_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'media' => []]);
    exit();
}

printflow_chat_authorize_order($order_id);

// Fetch all media using proper columns
    $sql = "SELECT message_id, COALESCE(message_file, image_path, file_path) as media_path, file_type, message_type
        FROM order_messages 
        WHERE order_id = ? 
        AND (message_file IS NOT NULL OR image_path IS NOT NULL OR file_path IS NOT NULL)
        AND (message_type = 'image' OR file_type = 'image')
        ORDER BY created_at DESC";
$media = db_query($sql, 'i', [$order_id]);

if ($media === false) {
    ob_end_clean();
    echo json_encode(['success' => true, 'media' => []]);
    exit();
}

function pf_chat_media_public_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^(https?:|data:)#i', $path)) {
        return $path;
    }

    $path = str_replace('<?php echo $base_path; ?>', '', $path);
    $path = preg_replace('#/+#', '/', $path);
    $base = rtrim(defined('BASE_PATH') ? BASE_PATH : (defined('AUTH_REDIRECT_BASE') ? AUTH_REDIRECT_BASE : '/printflow'), '/');

    if ($base === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        return $path;
    }
    if ($path !== '' && $path[0] === '/') {
        return $base . $path;
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

// Prepend BASE_URL if needed
$results = [];
foreach ($media as $item) {
    $f_type = strtolower($item['file_type'] ?? '');
    $m_type = strtolower($item['message_type'] ?? '');
    $path = $item['media_path'] ?? '';
    
    if (!$path) continue;
    
    // Clean path from query strings or legacy tags
    $clean_path = explode('?', $path)[0];
    $ext = strtolower(pathinfo($clean_path, PATHINFO_EXTENSION));
    
    // STRICT VOICE FILTER: Never show voice messages in media gallery
    if ($f_type === 'voice' || $m_type === 'voice') continue;
    if (in_array($ext, ['mp3', 'wav', 'ogg'])) continue;

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $f_type = 'image';
    } elseif ($f_type === 'image' || $m_type === 'image') {
        $f_type = 'image';
    }
    
    if ($f_type !== 'image') continue;

    $public_url = preg_match('#^https?://#i', $path)
        ? $path
        : rtrim(defined('BASE_PATH') ? BASE_PATH : '', '/') . '/public/serve_chat_image.php?message_id=' . (int)$item['message_id'];
    
    $results[] = [
        'message_file' => $public_url,
        'file_type' => $f_type
    ];
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'media' => $results
]);
exit();
?>
