<?php
/**
 * notifications/list.php — Fetch latest notifications as JSON for dropdown.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = (int) get_user_id();
$user_type = get_user_type() ?? 'Customer';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 15;

try {
    if ($user_type === 'Customer') {
        $rows = db_query(
            "SELECT notification_id AS id, notification_id, message, type, data_id, is_read, created_at
             FROM notifications
             WHERE customer_id = ?
             ORDER BY created_at DESC
             LIMIT " . (int)$limit,
            'i',
            [$user_id]
        );
    } else {
        $rows = db_query(
            "SELECT notification_id AS id, notification_id, message, type, data_id, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT " . (int)$limit,
            'i',
            [$user_id]
        );
        $branchId = in_array($user_type, ['Staff', 'Manager'], true)
            ? (printflow_branch_filter_for_user() ?? 0)
            : 0;
        $rows = printflow_filter_notifications_for_user($rows ?: [], (string)$user_type, $branchId > 0 ? (int)$branchId : null);
    }

    $rows = $rows ?: [];
    if ($user_type === 'Customer') {
        $rows = printflow_collapse_duplicate_notifications_latest($rows);
    } else {
        $rows = printflow_dedupe_notifications($rows, 120);
    }

    // Return rows with unread count
    foreach ($rows as &$row) {
        $dataId = (int)($row['data_id'] ?? 0);
        if ($dataId > 0) {
            $snapshot = printflow_notification_order_snapshot($dataId);
            $row['order_type'] = (string)($snapshot['order_type'] ?? '');
        } else {
            $row['order_type'] = '';
        }
        $row['target_url'] = printflow_notification_target_url_for_user((string)$user_type, $row);
        if ($user_type === 'Customer') {
            $base = defined('BASE_URL') ? BASE_URL : '/printflow';
            $fallback = printflow_notification_placeholder_image_url();
            if ($fallback === '') {
                $fallback = printflow_notification_normalize_media_url($base . '/public/assets/uploads/profiles/default.png');
            }
            $row['message'] = printflow_notification_display_message($row);
            $row['title'] = customer_notification_title((string)($row['type'] ?? ''), (string)($row['message'] ?? ''), $row);
            $row['image'] = customer_notification_image_url($row, $fallback, $user_id);
            $row['fallback'] = $fallback;
            $target = customer_notification_target_url($row);
            $row['link'] = ((int)($row['is_read'] ?? 0) === 0)
                ? ($base . '/customer/notifications.php?mark_read=' . (int)($row['notification_id'] ?? $row['id'] ?? 0) . '&next=' . urlencode($target))
                : $target;
        } else {
            $fallback = (defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/assets/images/services/default.png';
            $row['message'] = printflow_notification_display_message($row);
            $row['item_kind'] = printflow_notification_item_kind($row);
            $row['image'] = staff_admin_notification_image_url($row, $fallback);
            $row['fallback'] = $fallback;
        }
    }
    unset($row);

    $unread = get_unread_notification_count($user_id, $user_type);

    echo json_encode([
        'success'       => true,
        'notifications' => $rows ?: [],
        'unread_count'  => (int) $unread
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'notifications' => [],
        'unread_count' => 0
    ]);
}
