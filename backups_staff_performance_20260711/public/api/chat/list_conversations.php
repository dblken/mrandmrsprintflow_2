<?php
/**
 * Unified Conversation List API
 * Returns list of orders (conversations) for both Staff and Customers
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';

// Prevent accidental output from breaking JSON
ob_start();

header('Content-Type: application/json');

if (!is_logged_in()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = get_user_id();
$user_type = get_user_type();
$q = trim($_GET['q'] ?? '');
$show_archived = (int)($_GET['archived'] ?? 0);

try {
    $params = [];
    $types = "";
    $search_clause = "";
    if ($q !== '') {
        $q_param = "%$q%";
        $search_clause = " AND (
            o.order_id LIKE ? 
            OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.order_id AND oi.customization_data LIKE ?)
            OR EXISTS (SELECT 1 FROM customers c_search WHERE c_search.customer_id = o.customer_id AND (c_search.first_name LIKE ? OR c_search.last_name LIKE ?))
            OR EXISTS (SELECT 1 FROM users u_search WHERE u_search.branch_id = o.branch_id AND (u_search.first_name LIKE ? OR u_search.last_name LIKE ?))
        )";
        $params[] = $q_param;
        $params[] = $q_param;
        $params[] = $q_param;
        $params[] = $q_param;
        $params[] = $q_param;
        $params[] = $q_param;
        $types .= "ssssss";
    }

    if ($user_type === 'Customer') {
        // Check if is_archived column exists
        $has_archived = db_table_has_column('orders', 'is_archived');
        $archive_col = $has_archived ? "o.is_archived" : "0";

        $sql = "
        SELECT o.order_id, o.status, o.order_date, $archive_col as is_archived,
               (SELECT CASE 
                    WHEN m.message_type = 'order_update' THEN (CASE WHEN JSON_VALID(m.message) THEN 'Order update' ELSE m.message END)
                    WHEN m.message_type = 'order_update' THEN (CASE WHEN JSON_VALID(m.message) THEN 'Order update' ELSE m.message END)
                    WHEN m.message != '' THEN m.message 
                    WHEN m.message_type = 'image' THEN (CASE WHEN m.file_type = 'video' THEN '🎥 Video' ELSE '📸 Photo' END)
                    WHEN m.message_type = 'voice' THEN '🎤 Voice message'
                    WHEN m.message_type = 'order_update' THEN '📦 Order Update'
                    ELSE 'Attachment'
                END FROM order_messages m WHERE m.order_id = o.order_id ORDER BY m.message_id DESC LIMIT 1) AS last_message,
               (SELECT m.created_at FROM order_messages m WHERE m.order_id = o.order_id ORDER BY m.message_id DESC LIMIT 1) AS last_message_at,
               (SELECT COUNT(*) FROM order_messages m WHERE m.order_id = o.order_id AND m.sender = 'Staff' AND m.read_receipt != 2) AS unread_count,
               (SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.service_type')), p.name, 'Order') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id LIMIT 1) AS product_name,
               COALESCE(
                    (SELECT jo.assigned_to FROM job_orders jo WHERE jo.order_id = o.order_id AND jo.assigned_to IS NOT NULL ORDER BY jo.updated_at DESC LIMIT 1),
                    (SELECT m.sender_id FROM order_messages m WHERE m.order_id = o.order_id AND m.sender_id > 0 AND m.sender = 'Staff' ORDER BY m.message_id DESC LIMIT 1),
                    (SELECT us.user_id FROM user_status us WHERE us.order_id = o.order_id AND us.user_type = 'Staff' ORDER BY us.last_activity DESC LIMIT 1)
               ) AS staff_id,
               COALESCE(
                    (SELECT TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) 
                     FROM job_orders jo 
                     JOIN users u ON u.user_id = jo.assigned_to
                     WHERE jo.order_id = o.order_id AND jo.assigned_to IS NOT NULL AND u.role != 'Admin'
                     ORDER BY jo.updated_at DESC LIMIT 1),
                    (SELECT TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) 
                     FROM order_messages m 
                     JOIN users u ON u.user_id = m.sender_id
                     WHERE m.order_id = o.order_id AND m.sender_id > 0 AND m.sender = 'Staff' AND u.role != 'Admin'
                     ORDER BY m.message_id DESC
                     LIMIT 1),
                    (SELECT TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) 
                     FROM order_messages m 
                     JOIN users u ON u.user_id = m.sender_id
                     WHERE m.order_id = o.order_id AND m.sender_id > 0 AND m.sender = 'Staff'
                     ORDER BY m.message_id DESC
                     LIMIT 1),
                    (SELECT TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) 
                     FROM users u 
                     WHERE u.branch_id = o.branch_id AND u.role = 'Staff' AND u.status = 'Activated'
                     ORDER BY u.user_id ASC LIMIT 1),
                    'PrintFlow Team'
               ) AS staff_name,
               COALESCE(
                    (SELECT u.profile_picture
                     FROM job_orders jo
                     JOIN users u ON u.user_id = jo.assigned_to
                     WHERE jo.order_id = o.order_id AND jo.assigned_to IS NOT NULL AND u.role != 'Admin'
                     ORDER BY jo.updated_at DESC, jo.id DESC
                     LIMIT 1),
                    (SELECT u.profile_picture
                     FROM order_messages m
                     JOIN users u ON u.user_id = m.sender_id
                     WHERE m.order_id = o.order_id AND m.sender_id > 0 AND m.sender = 'Staff' AND u.role != 'Admin'
                     ORDER BY m.message_id DESC
                     LIMIT 1),
                    (SELECT u.profile_picture
                     FROM user_status us
                     JOIN users u ON u.user_id = us.user_id
                     WHERE us.order_id = o.order_id AND us.user_type = 'Staff' AND u.role != 'Admin'
                     ORDER BY us.last_activity DESC
                     LIMIT 1),
                    (SELECT u.profile_picture
                     FROM job_orders jo
                     JOIN users u ON u.user_id = jo.assigned_to
                     WHERE jo.order_id = o.order_id AND jo.assigned_to IS NOT NULL
                     ORDER BY jo.updated_at DESC, jo.id DESC
                     LIMIT 1),
                    (SELECT u.profile_picture FROM users u WHERE u.role = 'Staff' AND u.branch_id = o.branch_id AND u.status = 'Activated' AND u.profile_picture IS NOT NULL ORDER BY u.user_id ASC LIMIT 1),
                    (SELECT u.profile_picture FROM users u WHERE u.role = 'Staff' AND u.status = 'Activated' AND u.profile_picture IS NOT NULL ORDER BY u.user_id ASC LIMIT 1),
                    ''
               ) AS staff_avatar,
               COALESCE(
                    (SELECT u.online_status FROM users u WHERE u.user_id = (SELECT m.sender_id FROM order_messages m WHERE m.order_id = o.order_id AND m.sender_id > 0 AND m.sender = 'Staff' ORDER BY m.message_id DESC LIMIT 1)),
                    (SELECT u.online_status FROM users u WHERE u.user_id = (SELECT us.user_id FROM user_status us WHERE us.order_id = o.order_id AND us.user_type = 'Staff' ORDER BY us.last_activity DESC LIMIT 1)),
                    (SELECT u.online_status FROM users u WHERE u.user_id = (SELECT jo.assigned_to FROM job_orders jo WHERE jo.order_id = o.order_id AND jo.assigned_to IS NOT NULL ORDER BY jo.updated_at DESC LIMIT 1)),
                    'offline'
               ) AS staff_status
        FROM orders o
        WHERE o.customer_id = ?" . ($has_archived ? " AND $archive_col = ?" : "") . " $search_clause
        ORDER BY COALESCE((SELECT MAX(mx.created_at) FROM order_messages mx WHERE mx.order_id = o.order_id), o.order_date) DESC
    ";

        $full_params = $has_archived ? array_merge([$user_id, ($show_archived ? 1 : 0)], $params) : array_merge([$user_id], $params);
        $full_types = $has_archived ? "ii" . $types : "i" . $types;
        $rows = db_query($sql, $full_types, $full_params);
        if ($rows === false)
            throw new Exception("Database lookup failed on orders.");
    } else {
        // Check if is_archived column exists
        $has_archived = db_table_has_column('orders', 'is_archived');
        $archive_col = $has_archived ? "o.is_archived" : "0";
        $has_activity = db_table_has_column('customers', 'last_activity');
        $activity_sel = $has_activity ? "c.last_activity as partner_last_activity," : "NULL as partner_last_activity,";
        $sql = "
        SELECT o.order_id, o.customer_id, o.status, o.order_date, $archive_col as is_archived,
               TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS customer_name,
               c.profile_picture AS customer_avatar,
               c.online_status AS customer_status,
               $activity_sel
               (SELECT CASE 
                    WHEN m.message_type = 'order_update' THEN (CASE WHEN JSON_VALID(m.message) THEN 'Order update' ELSE m.message END)
                    WHEN m.message != '' THEN m.message 
                    WHEN m.message_type = 'image' THEN (CASE WHEN m.file_type = 'video' THEN '🎥 Video' ELSE '📸 Photo' END)
                    WHEN m.message_type = 'voice' THEN '🎤 Voice message'
                    WHEN m.message_type = 'order_update' THEN '📦 Order Update'
                    ELSE 'Attachment'
                END FROM order_messages m WHERE m.order_id = o.order_id ORDER BY m.message_id DESC LIMIT 1) AS last_message,
               (SELECT m.created_at FROM order_messages m WHERE m.order_id = o.order_id ORDER BY m.message_id DESC LIMIT 1) AS last_message_at,
               (SELECT COUNT(*) FROM order_messages m WHERE m.order_id = o.order_id AND m.sender = 'Customer' AND m.read_receipt != 2) AS unread_count,
               (SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.service_type')), p.name, 'Order') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id LIMIT 1) AS product_name
        FROM orders o
        LEFT JOIN customers c ON c.customer_id = o.customer_id
        WHERE o.status != 'Cancelled'" . ($has_archived ? " AND $archive_col = ?" : "") . " $search_clause
        AND (
            EXISTS (SELECT 1 FROM order_messages m WHERE m.order_id = o.order_id)
            OR o.order_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        )
        ORDER BY COALESCE((SELECT MAX(mx.created_at) FROM order_messages mx WHERE mx.order_id = o.order_id), o.order_date) DESC
    ";

        $full_params = $has_archived ? array_merge([($show_archived ? 1 : 0)], $params) : $params;
        $full_types = $has_archived ? "i" . $types : $types;
        $rows = db_query($sql, $full_types, $full_params);
        if ($rows === false)
            throw new Exception("Database lookup failed on staff view.");
    }

    $conversations = [];
    foreach ($rows ?: [] as $r) {
        $conversations[] = $r;
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'conversations' => $conversations,
        'user_type' => $user_type,
        'user_id' => $user_id
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
