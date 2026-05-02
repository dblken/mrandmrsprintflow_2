<?php
/**
 * PrintFlow Order Chat System Helpers
 * Handles automatic system messages in order chats.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Sends an automatic system message to the order chat.
 * 
 * @param int $order_id The ID of the order (store order_id or job_order_id depending on context)
 * @param string $message The text message to display
 * @param string $action_type Type of action (view_only, redirect_payment, retry_payment, rate_order)
 * @param string $thumbnail Optional thumbnail path
 * @param string $action_url Optional custom URL (if not standard)
 * @param array $meta Optional metadata as associative array
 * @return int|bool Message ID or false on failure
 */
if (!function_exists('printflow_send_order_update')) {
function printflow_send_order_update($order_id, $message, $action_type = 'view_status', $thumbnail = '', $action_url = '', $meta = []) {
    if (!$order_id) return false;

    // Handle step name aliases (backward compatibility with legacy callers)
    $known_steps = ['pending', 'approved', 'send_to_payment', 'payment_submitted', 'payment_verified', 'payment_rejected', 'in_production', 'for_revision', 'ready_to_pickup', 'completed', 'cancelled', 'inquiry'];
    if (in_array($message, $known_steps) && function_exists('printflow_send_order_update_legacy')) {
        return printflow_send_order_update_legacy($order_id, $message, '', $meta);
    }

    // Resolve base path for relative thumbnails
    $base = function_exists('printflow_notification_base_path') ? printflow_notification_base_path() : ((defined('BASE_PATH')) ? BASE_PATH : '');

    // Default thumbnails if not provided
    if (empty($thumbnail)) {
        // Try to fetch service thumbnail from order items
        $items = db_query("SELECT service_type FROM order_items WHERE order_id = ? LIMIT 1", "i", [$order_id]);
        if (!empty($items)) {
            $service = normalize_service_name($items[0]['service_type']);
            
            // Try to find a real thumbnail for this service
            $svc_data = db_query("SELECT image_path FROM services WHERE name = ? LIMIT 1", "s", [$service]);
            if (!empty($svc_data) && !empty($svc_data[0]['image_path'])) {
                $thumbnail = $svc_data[0]['image_path'];
            }
        }
    }
    
    if (empty($thumbnail)) {
        $thumbnail = $base . "/public/assets/images/services/default.png";
    } else {
        // Ensure thumbnail has base path if it's relative
        $is_absolute = (preg_match('#^https?://#i', $thumbnail) || ($base !== '' && strpos($thumbnail, $base) === 0) || strpos($thumbnail, '/') === 0);
        if (!$is_absolute) {
            $thumbnail = $base . '/' . $thumbnail;
        }
    }

    $meta_json = !empty($meta) ? json_encode($meta) : null;
    $sender = 'System';
    $sender_id = 0; // System messages have sender_id = 0
    $message_type = 'order_update';

    $sql = "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, thumbnail, action_type, action_url, meta_json, read_receipt, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())";
    
    $params = [$order_id, $sender, $sender_id, $message, $message_type, $thumbnail, $action_type, $action_url, $meta_json];
    $res = db_execute($sql, "isissssss", $params);

    if ($res) {
        // Also trigger real-time notification via existing system if any
        if (function_exists('printflow_notify_chat_message')) {
            printflow_notify_chat_message($order_id, 'System');
        }
        return $res;
    }

    return false;
}
}
