<?php
/**
 * Support chat conversations API - Admin
 * GET: List conversations (with filters, search, pagination)
 * GET ?id=X: Get messages for a conversation
 * POST: Send admin reply
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Admin');

header('Content-Type: application/json');

function _truncate_msg($msg, $len = 50) {
    $msg = strip_tags((string)$msg);
    return mb_strlen($msg) > $len ? mb_substr($msg, 0, $len) . '...' : $msg;
}

function _chatbot_notification_excerpt(string $message, int $length = 80): string {
    $message = preg_replace('/\s+/', ' ', trim($message));
    if ($message === null) {
        $message = '';
    }
    return mb_strlen($message) > $length ? mb_substr($message, 0, $length) . '...' : $message;
}

// Run expiration logic on each request (24h inactive → expired/archived)
$tables_exist = db_query("SHOW TABLES LIKE 'chatbot_conversations'");
if (!empty($tables_exist)) {
    db_execute("UPDATE chatbot_conversations c SET status = 'expired', is_archived = 1 WHERE c.status != 'expired' AND c.last_activity_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND " . printflow_customer_exclusion_customer_scope_sql('c.customer_id'));
}

// GET ?id=X — Fetch messages for a conversation (lazy load)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $conversation_id = (int)$_GET['id'];
    $conv = db_query("SELECT c.*, cm.profile_picture,
                             COALESCE(CONCAT(cm.first_name, ' ', cm.last_name), c.customer_name) as display_name
                      FROM chatbot_conversations c
                      LEFT JOIN customers cm ON c.customer_id = cm.customer_id
                      WHERE c.id = ? AND " . printflow_customer_exclusion_customer_scope_sql('c.customer_id'), 'i', [$conversation_id]);
    if (empty($conv)) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found']);
        exit;
    }
    $conv = $conv[0];
    $messages = db_query("SELECT id, sender_type, message, created_at FROM chatbot_messages WHERE conversation_id = ? ORDER BY created_at ASC", 'i', [$conversation_id]);
    
    echo json_encode([
        'success' => true,
        'conversation' => [
            'id' => (int)$conv['id'],
            'customer_name' => $conv['display_name'] ?: $conv['customer_name'] ?: 'Guest',
            'customer_email' => $conv['customer_email'] ?? '',
            'customer_id' => $conv['customer_id'] ? (int)$conv['customer_id'] : null,
            'guest_id' => $conv['guest_id'] ?? '',
            'profile_picture' => !empty($conv['profile_picture'])
                ? (defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/assets/uploads/profiles/' . ltrim((string)$conv['profile_picture'], '/')
                : null,
            'status' => $conv['status'],
        ],
        'messages' => array_map(function($m) {
            return [
                'id' => (int)$m['id'],
                'sender_type' => $m['sender_type'],
                'message' => $m['message'],
                'created_at' => $m['created_at'],
            ];
        }, $messages ?: [])
    ]);
    exit;
}

// POST — Send admin reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $conversation_id = (int)($data['conversation_id'] ?? 0);
    $message = trim($data['message'] ?? '');
    
    if (!$conversation_id || empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Conversation ID and message required']);
        exit;
    }
    
    $conv = db_query("SELECT id, customer_id FROM chatbot_conversations c WHERE id = ? AND " . printflow_customer_exclusion_customer_scope_sql('c.customer_id'), 'i', [$conversation_id]);
    if (empty($conv)) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found']);
        exit;
    }
    $conv = $conv[0];
    
    $ins = db_execute("INSERT INTO chatbot_messages (conversation_id, sender_type, message, created_at) VALUES (?, 'admin', ?, NOW())", 'is', [$conversation_id, $message]);
    if ($ins === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to save message']);
        exit;
    }
    $msg_id = (is_int($ins) || (is_numeric($ins) && (int)$ins > 0)) ? (int)$ins : 0;
    if ($msg_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Failed to save message']);
        exit;
    }
    db_execute("UPDATE chatbot_conversations SET status = 'answered', last_message_preview = ?, last_activity_at = NOW() WHERE id = ?", 'si', [_truncate_msg($message, 100), $conversation_id]);

    $customer_id = !empty($conv['customer_id']) ? (int)$conv['customer_id'] : 0;
    if ($customer_id > 0 && function_exists('create_notification')) {
        create_notification(
            $customer_id,
            'Customer',
            'New support chat reply from PrintFlow: ' . _chatbot_notification_excerpt($message),
            'System',
            false,
            false,
            $conversation_id
        );
    }

    $msg_row = db_query("SELECT id, sender_type, message, created_at FROM chatbot_messages WHERE id = ?", 'i', [$msg_id]);
    $m = $msg_row[0] ?? null;
    if (!$m) {
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => [
            'id' => (int)$m['id'],
            'sender_type' => $m['sender_type'],
            'message' => $m['message'],
            'created_at' => $m['created_at'],
        ],
    ]);
    exit;
}

// GET — List conversations (with filters, search, pagination)
$filter = $_GET['filter'] ?? 'all'; // all, pending, answered, archived
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(50, max(10, (int)($_GET['per_page'] ?? 15)));
$offset = ($page - 1) * $per_page;

$where = ["1=1"];
$where[] = printflow_customer_exclusion_customer_scope_sql('c.customer_id');
$params = [];
$types = '';

if ($filter === 'pending') {
    $where[] = "c.status = 'pending' AND (c.is_archived = 0 OR c.is_archived IS NULL)";
} elseif ($filter === 'answered') {
    $where[] = "c.status = 'answered' AND (c.is_archived = 0 OR c.is_archived IS NULL)";
} elseif ($filter === 'archived') {
    $where[] = "c.is_archived = 1";
} else {
    // "all" = active inbox (exclude archived)
    $where[] = "(c.is_archived = 0 OR c.is_archived IS NULL)";
}

if (!empty($search)) {
    $where[] = "(c.customer_name LIKE ? OR c.customer_email LIKE ? OR c.last_message_preview LIKE ?)";
    $term = '%' . $search . '%';
    $params = array_merge($params, [$term, $term, $term]);
    $types .= 'sss';
}

$where_sql = implode(' AND ', $where);

$total = db_query("SELECT COUNT(*) as cnt FROM chatbot_conversations c WHERE $where_sql", $types, $params);
$total_count = $total[0]['cnt'] ?? 0;

$conversations = db_query(
    "SELECT c.id, c.customer_id, c.guest_id, c.customer_name, c.customer_email, c.last_message_preview, c.status, c.last_activity_at, c.is_archived,
            COALESCE(CONCAT(cm.first_name, ' ', cm.last_name), c.customer_name, 'Guest') as display_name
     FROM chatbot_conversations c
     LEFT JOIN customers cm ON c.customer_id = cm.customer_id
     WHERE $where_sql
     ORDER BY c.last_activity_at DESC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$per_page, $offset])
);

$list = [];
foreach ($conversations ?: [] as $c) {
    if ($c['customer_id']) {
        $name = trim($c['display_name'] ?? '') ?: trim($c['customer_name'] ?? '') ?: 'Guest';
    } else {
        $name = 'Guest #' . $c['id'];
    }
    $list[] = [
        'id' => (int)$c['id'],
        'customer_name' => $name,
        'customer_email' => $c['customer_email'] ?? '',
        'last_message' => $c['last_message_preview'] ?? '',
        'status' => $c['status'],
        'last_activity_at' => $c['last_activity_at'],
        'is_archived' => (bool)($c['is_archived'] ?? 0),
    ];
}

echo json_encode([
    'success' => true,
    'conversations' => $list,
    'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int)$total_count,
        'total_pages' => (int)ceil(max(1, $total_count) / $per_page),
    ],
]);
