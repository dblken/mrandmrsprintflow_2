<?php
/**
 * push/poll.php — Lightweight in-tab notification poll.
 * GET ?since=<unix_timestamp>
 * Returns new notifications created after `since` for the logged-in user.
 * Used as fallback when the tab is open (in-tab toasts); the service worker
 * handles background push when the tab is closed.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';

header('Content-Type: application/json');

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal server error', 'error' => $error['message']]);
        exit;
    }
});

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'notifications' => []]);
    exit;
}

$user_id   = (int) get_user_id();
$user_type = get_user_type() ?? 'Customer';
$since     = isset($_GET['since']) ? (int) $_GET['since'] : (time() - 30);

// Pull notifications newer than the timestamp
if ($user_type === 'Customer') {
    $rows = db_query(
        "SELECT notification_id AS id, notification_id, message, type, data_id, is_read,
                UNIX_TIMESTAMP(created_at) AS ts
         FROM notifications
         WHERE customer_id = ? AND UNIX_TIMESTAMP(created_at) > ?
         ORDER BY created_at ASC
         LIMIT 20",
        'ii',
        [$user_id, $since]
    );
} else {
    $rows = db_query(
        "SELECT notification_id AS id, message, type, data_id, is_read,
                UNIX_TIMESTAMP(created_at) AS ts
         FROM notifications
         WHERE user_id = ? AND UNIX_TIMESTAMP(created_at) > ?
         ORDER BY created_at ASC
         LIMIT 20",
        'ii',
        [$user_id, $since]
    );
    $branchId = in_array($user_type, ['Staff', 'Manager'], true)
        ? (printflow_branch_filter_for_user() ?? 0)
        : 0;
    $rows = printflow_filter_notifications_for_user($rows ?: [], (string)$user_type, $branchId > 0 ? (int)$branchId : null);
}

$rows = $rows ?: [];

if ($user_type === 'Customer') {
    $rows = printflow_collapse_duplicate_notifications_latest($rows);
    usort($rows, static function ($a, $b): int {
        $tsRawA = $a['ts'] ?? null;
        $tsA = is_numeric($tsRawA) ? (int)$tsRawA : 0;
        $tsRawB = $b['ts'] ?? null;
        $tsB = is_numeric($tsRawB) ? (int)$tsRawB : 0;
        if ($tsA !== $tsB) {
            return $tsA <=> $tsB;
        }
        return ((int)($a['notification_id'] ?? ($a['id'] ?? 0))) <=> ((int)($b['notification_id'] ?? ($b['id'] ?? 0)));
    });
} else {
    $rows = printflow_dedupe_notifications($rows, 120);
}

foreach ($rows as &$row) {
    $row['target_url'] = printflow_notification_target_url_for_user((string)$user_type, $row);
    if ($user_type === 'Customer') {
        $base = defined('BASE_URL') ? BASE_URL : '/printflow';
        $fallback = printflow_notification_placeholder_image_url();
        if ($fallback === '') {
            $fallback = printflow_notification_normalize_media_url($base . '/public/assets/uploads/profiles/default.png');
        }
        $row['message'] = printflow_notification_display_message($row);
        $row['title'] = customer_notification_title((string)($row['type'] ?? ''), (string)($row['message'] ?? ''), $row);
        $row['image'] = customer_notification_image_url($row, $fallback);
        $row['fallback'] = $fallback;
        $target = customer_notification_target_url($row);
        $row['link'] = ((int)($row['is_read'] ?? 0) === 0)
            ? ($base . '/customer/notifications.php?mark_read=' . (int)($row['notification_id'] ?? $row['id'] ?? 0) . '&next=' . urlencode($target))
            : $target;
    } else {
        $base = defined('BASE_URL') ? BASE_URL : '/printflow';
        $fallback = $base . '/public/assets/images/services/default.png';
        $row['message'] = printflow_notification_display_message($row);
        $row['image'] = staff_admin_notification_image_url($row, $fallback);
        $row['fallback'] = $fallback;
    }
}
unset($row);

// Unread count
$unread = get_unread_notification_count($user_id, $user_type);

echo json_encode([
    'success'       => true,
    'notifications' => $rows ?: [],
    'unread_count'  => (int) $unread,
    'server_time'   => time(),
]);
