<?php
/**
 * Fetch messages for a specific order.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../../../includes/auth.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';

    // Global Output Buffer to trap notices
    ob_start();

    header('Content-Type: application/json');

    if (!is_logged_in()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Your session has expired. Please login again.']);
        exit;
    }

    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $last_id = isset($_GET['last_id']) ? (int) $_GET['last_id'] : 0;
    $is_active = isset($_GET['is_active']) && $_GET['is_active'] == 1; // Is the chat window currently open by the requester?
    $user_id = get_user_id();
    $user_type = get_user_type();

    if (!$order_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Missing order ID']);
        exit;
    }

    $current_user_type = ($user_type === 'Customer') ? 'customer' : 'staff';
    $base_path = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
    $default_order_thumbnail = ($base_path !== '' ? $base_path : '') . '/public/assets/images/services/default.png';

    $chat_public_url = static function (?string $path) use ($base_path): string {
        $path = trim((string) $path);
        if ($path === '' || preg_match('#^(https?:|data:)#i', $path)) {
            return $path;
        }

        $path = str_replace('<?php echo $base_path; ?>', '', $path);
        $path = preg_replace('#/+#', '/', $path);

        if ($base_path === '' && strpos($path, '/printflow/') === 0) {
            $path = substr($path, strlen('/printflow'));
        }
        if ($base_path !== '' && strpos($path, $base_path . '/') === 0) {
            return $path;
        }
        if ($path !== '' && $path[0] === '/') {
            return $base_path . $path;
        }

        return ($base_path === '' ? '' : $base_path) . '/' . ltrim($path, '/');
    };

    $resolve_sender_type = static function (string $sender, string $message_type, array $meta): string {
        if ($sender === 'Customer') {
            return 'customer';
        }
        if ($sender === 'Staff') {
            return 'staff';
        }

        // Handle System messages
        $meta_sender_type = strtolower(trim((string) ($meta['sender_type'] ?? $meta['origin_actor'] ?? '')));
        if (in_array($meta_sender_type, ['customer', 'staff'], true)) {
            return $meta_sender_type;
        }

        $step = strtolower(trim((string) ($meta['step'] ?? '')));
        if (in_array($step, ['inquiry', 'payment_submitted'], true)) {
            return 'customer';
        }

        // Default for system messages/actions is staff
        return 'staff';
    };

    $resolve_order_update_status = static function (array $meta, array $order_state): string {
        $explicit_status = trim((string) ($meta['order_status'] ?? ''));
        if ($explicit_status !== '') {
            return $explicit_status;
        }

        $step = strtolower(trim((string) ($meta['step'] ?? '')));
        $step_status_map = [
            'inquiry' => 'Pending',
            'approved' => 'Approved',
            'send_to_payment' => 'To Pay',
            'payment_submitted' => 'Verify Payment',
            'payment_verified' => 'Payment Verified',
            'payment_rejected' => 'Payment Rejected',
            'in_production' => 'In Production',
            'ready_to_pickup' => 'Ready for Pickup',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'rate' => 'Completed',
        ];
        if (isset($step_status_map[$step])) {
            return $step_status_map[$step];
        }

        return trim((string) ($order_state['status'] ?? '')) ?: 'Order update';
    };

    $order_state = [
        'status' => '',
        'payment_status' => '',
    ];
    $order_state_raw = db_query("SELECT status, payment_status FROM orders WHERE order_id = ?", 'i', [$order_id]);
    if (!empty($order_state_raw)) {
        $order_state['status'] = trim((string) ($order_state_raw[0]['status'] ?? ''));
        $order_state['payment_status'] = trim((string) ($order_state_raw[0]['payment_status'] ?? ''));
    }

    // 1. Fetch new messages
    $sql = "SELECT m.*, 
        p.message AS reply_message, 
        p.image_path AS reply_image,
        p.sender_id AS reply_sender_id,
        m.is_pinned,
        CASE 
            WHEN m.sender = 'Customer' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM customers WHERE customer_id = m.sender_id)
            WHEN m.sender = 'Staff' OR (m.sender = 'System' AND m.sender_id > 0) THEN (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = m.sender_id)
            ELSE 'System' 
        END as sender_name,
        CASE 
            WHEN m.sender = 'Customer' THEN 'Customer'
            WHEN m.sender = 'Staff' OR (m.sender = 'System' AND m.sender_id > 0) THEN (SELECT role FROM users WHERE user_id = m.sender_id)
            ELSE 'System' 
        END as sender_role,
        CASE 
            WHEN m.sender = 'Customer' THEN (SELECT profile_picture FROM customers WHERE customer_id = m.sender_id)
            WHEN m.sender = 'Staff' OR (m.sender = 'System' AND m.sender_id > 0) THEN (SELECT profile_picture FROM users WHERE user_id = m.sender_id)
            ELSE NULL 
        END as sender_avatar
        FROM order_messages m 
        LEFT JOIN order_messages p ON m.reply_id = p.message_id
        WHERE m.order_id = ? AND m.message_id > ? 
        ORDER BY m.message_id ASC";
    $messages_raw = db_query($sql, 'ii', [$order_id, $last_id]);
    if ($messages_raw === false) {
        global $conn;
        $db_err = $conn ? mysqli_error($conn) : 'Unknown DB error';
        throw new Exception("Could not fetch messages. Database returned an error: " . $db_err);
    }

    $messages = [];
    if ($messages_raw) {
        foreach ($messages_raw as $msg) {
            $is_system = ($msg['sender'] === 'System');

            $image_path = (string) ($msg['image_path'] ?? '');
            if ($image_path !== '' && !preg_match('#^https?://#i', $image_path)) {
                // Use BASE_PATH constant ('' on production, '/printflow' on localhost)
                $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
                if ($image_path[0] !== '/') {
                    // Relative path — prefix with base
                    $image_path = $bp . '/' . $image_path;
                } elseif ($bp !== '' && strpos($image_path, $bp . '/') !== 0) {
                    // Absolute but missing base prefix (localhost only)
                    $image_path = $bp . $image_path;
                }
            }

            $sender_avatar = get_profile_image($msg['sender_avatar'] ?? null);

            $raw_m_type = $msg['message_type'] ?? 'text';
            $m_type = $raw_m_type;
            $f_type = $msg['file_type'] ?? 'text';
            
            $raw_m_file = $msg['message_file'] ?? $image_path;
            $media_ext = strtolower(pathinfo($raw_m_file, PATHINFO_EXTENSION));
            
            // Robust media type detection to fix older database rows
            if ($media_ext !== '') {
                if (in_array($media_ext, ['mp4', 'mov', 'avi'])) {
                    $m_type = 'video';
                    $f_type = 'video';
                } elseif (in_array($media_ext, ['mp3', 'wav', 'ogg'])) {
                    $m_type = 'voice';
                    $f_type = 'voice';
                } elseif (in_array($media_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    if ($m_type !== 'order_update') {
                        $m_type = 'image';
                        $f_type = 'image';
                    }
                }
                
                // .webm can be video or voice. If the DB says voice, trust it.
                if ($media_ext === 'webm') {
                    if ($raw_m_type === 'voice' || $f_type === 'voice') {
                        $m_type = 'voice';
                        $f_type = 'voice';
                    } else {
                        $m_type = 'video';
                        $f_type = 'video';
                    }
                }
            }
            
            // Explicit override if DB knows it's voice
            if ($raw_m_type === 'voice') {
                $m_type = 'voice';
            }

            $meta_data = [];
            if (!empty($msg['meta_json'])) {
                $decoded_meta = json_decode((string) $msg['meta_json'], true);
                if (is_array($decoded_meta)) {
                    $meta_data = $decoded_meta;
                }
            }

            // Ensure meta_data includes useful defaults for legacy rows
            if (empty($meta_data) || !is_array($meta_data)) {
                $meta_data = is_array($meta_data) ? $meta_data : [];
            }
            // If action_url or amount are missing, try to infer from orders table
            if (empty($meta_data['action_url']) || !isset($meta_data['amount'])) {
                try {
                    $ord = db_query("SELECT total_amount FROM orders WHERE order_id = ? LIMIT 1", 'i', [$order_id]);
                    if (!empty($ord) && isset($ord[0]['total_amount'])) {
                        if (!isset($meta_data['amount']) || $meta_data['amount'] === null) {
                            $meta_data['amount'] = (float) $ord[0]['total_amount'];
                        }
                    }
                } catch (Throwable $e) {
                    // ignore - leave values as-is
                }
                // If message suggests a payment request, set payment action URL
                $lowerMsg = strtolower((string)($msg['message'] ?? ''));
                $looksLikePay = strpos($lowerMsg, 'ready for payment') !== false || strpos($lowerMsg, 'proceed to complete your transaction') !== false || strpos($lowerMsg, 'proceed to payment') !== false;
                if ($looksLikePay && empty($meta_data['action_url'])) {
                    $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
                    $meta_data['action_url'] = ($bp === '' ? '' : $bp) . '/customer/payment.php?order_id=' . $order_id;
                    // also set a sensible button_label
                    if (empty($meta_data['button_label'])) {
                        $meta_data['button_label'] = 'Proceed to Payment';
                    }
                }
            }

            $sender_type = $resolve_sender_type((string) $msg['sender'], (string) $m_type, $meta_data);
            $is_self = $sender_type !== null ? ($sender_type === $current_user_type) : false;

            // Wrap locally stored media in proxy endpoints so playback works even
            // when direct /uploads access is blocked or rewritten by hosting rules.
            $raw_m_file = (string)($msg['message_file'] ?? '');
            if ($m_type === 'video' && $raw_m_file !== '' && !preg_match('#^https?://#i', $raw_m_file)) {
                $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
                $filename = basename($raw_m_file);
                $raw_m_file = $bp . '/public/serve_chat_video.php?file=' . urlencode($filename);
            } elseif ($m_type === 'voice' && $raw_m_file !== '' && !preg_match('#^https?://#i', $raw_m_file)) {
                $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
                $filename = basename($raw_m_file);
                $raw_m_file = $bp . '/public/serve_chat_audio.php?file=' . urlencode($filename);
            }

            $thumbnail = $chat_public_url((string) ($msg['thumbnail'] ?? ''));

            $order_update = null;
            if ($m_type === 'order_update') {
                $order_update = [
                    'order_id' => (int) ($meta_data['order_id'] ?? $order_id),
                    'product_name' => trim((string) ($meta_data['product_name'] ?? '')) ?: 'Order update',
                    'status' => $resolve_order_update_status($meta_data, $order_state),
                    'payment_status' => trim((string) ($meta_data['payment_status'] ?? $order_state['payment_status'])),
                    'thumbnail' => $thumbnail !== '' ? $thumbnail : $default_order_thumbnail,
                    'description' => $msg['message'] ?? '',
                    'sender_type' => $sender_type,
                ];
            }

            $order_card_service_name = '';
            $order_card_customer_name = '';
            $order_card_image = '';
            if ($m_type === 'order_card') {
                $preview = printflow_order_notification_preview((int) $order_id);
                $order_card_service_name = trim((string) ($preview['display_name'] ?? '')) ?: 'Order update';
                $order_card_customer_name = trim((string) ($msg['sender_name'] ?? '')) ?: 'Customer';
                $order_card_image = trim((string) ($preview['image_url'] ?? ''));
                if ($order_card_image === '') {
                    $order_card_image = $default_order_thumbnail;
                }
            }

            $messages[] = [
                'id' => $msg['message_id'],
                'sender' => $msg['sender'],
                'sender_type' => $sender_type,
                'message' => $msg['message'] ?? '',
                'message_type' => $m_type,
                'image_path' => $image_path,
                'message_file' => $raw_m_file,
                'file_type' => $f_type,
                'file_path' => $msg['file_path'] ?? null,
                'file_name' => $msg['file_name'] ?? null,
                'duration' => $msg['duration'] ?? null,
                'duration' => $msg['duration'] ?? null,
                'created_at_full' => $msg['created_at'],
                'created_at' => date('h:i A', strtotime($msg['created_at'])),
                'is_self' => $is_self,
                'status' => (int) $msg['read_receipt'], // 0=Sent, 1=Delivered, 2=Seen
                'is_system' => $is_system,
                'is_forwarded' => (int) ($msg['is_forwarded'] ?? 0),
                'reply_id' => $msg['reply_id'] ?: null,
                'reply_message' => $msg['reply_message'] ?? null,
                'reply_image' => $msg['reply_image'] ?? null,
                'reply_sender_id' => $msg['reply_sender_id'] ?? null,
                'sender_name' => $msg['sender_name'],
                'sender_role' => $msg['sender_role'],
                'sender_avatar' => $sender_avatar,
                'is_pinned' => (bool) ($msg['is_pinned'] ?? false),
                'thumbnail' => $thumbnail,
                'action_type' => $msg['action_type'] ?? null,
                'action_url' => $meta_data['action_url'] ?? ($msg['action_url'] ?? null),
                'meta_json' => !empty($meta_data) ? json_encode($meta_data) : ($msg['meta_json'] ?? null),
                'order_update' => $order_update,
                'service_name' => $order_card_service_name,
                'customer_name' => $order_card_customer_name,
                'image' => $order_card_image,
            ];
        }
    }

    // Fetch reactions for all messages in the order (efficient enough for polling limited chats)
    $reactions_sql = "SELECT r.message_id, r.reaction_type, r.sender, r.sender_id,
            CASE 
                WHEN r.sender = 'Customer' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM customers WHERE customer_id = r.sender_id)
                WHEN r.sender = 'Staff' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = r.sender_id)
                ELSE 'System' 
            END as reactor_name
            FROM message_reactions r
            JOIN order_messages m ON r.message_id = m.message_id
            WHERE m.order_id = ?";
    $reactions_raw = db_query($reactions_sql, 'i', [$order_id]);
    if ($reactions_raw === false) {
        global $conn;
        $db_err = $conn ? mysqli_error($conn) : 'Unknown DB error';
        throw new Exception("Could not fetch reactions. Database returned an error: " . $db_err);
    }
    $reactions = $reactions_raw ?: [];

    // 2. Mark messages as seen/delivered
    $target_sender = ($user_type === 'Customer') ? 'Staff' : 'Customer';
    if ($is_active) {
        // Current user has chat open -> Mark as SEEN
        db_execute("UPDATE order_messages SET read_receipt = 2 WHERE order_id = ? AND sender = ? AND read_receipt < 2", 'is', [$order_id, $target_sender]);
    } else {
        // Current user just fetched updates (sidebar/background) -> Mark as DELIVERED
        db_execute("UPDATE order_messages SET read_receipt = 1 WHERE order_id = ? AND sender = ? AND read_receipt = 0", 'is', [$order_id, $target_sender]);
    }

    // 3. Fetch partner online/typing status
    $partner_type = ($user_type === 'Customer') ? 'Staff' : 'Customer';
    $partner_sql = "SELECT last_activity, is_typing,
                CASE 
                    WHEN ? = 'Staff' THEN (SELECT online_status FROM users WHERE user_id = (SELECT user_id FROM user_status WHERE order_id = ? AND user_type = 'Staff' ORDER BY last_activity DESC LIMIT 1))
                    ELSE (SELECT online_status FROM customers WHERE customer_id = (SELECT customer_id FROM orders WHERE order_id = ?))
                END as online_status
                FROM user_status 
                WHERE order_id = ? AND user_type = ? 
                ORDER BY last_activity DESC LIMIT 1";
    $partner_raw = db_query($partner_sql, 'siiis', [$partner_type, $order_id, $order_id, $order_id, $partner_type]);

    $partner = ['id' => null, 'name' => null, 'is_online' => false, 'is_typing' => false, 'avatar' => null];
    if (!empty($partner_raw)) {
        $last_active = strtotime($partner_raw[0]['last_activity']);
        $partner['is_online'] = (time() - $last_active) < 90;
        $partner['is_typing'] = (bool) $partner_raw[0]['is_typing'] && $partner['is_online'];
        $partner['online_status'] = $partner_raw[0]['online_status'] ?? ($partner['is_online'] ? 'online' : 'offline');
    }

    // Get partner avatar and ID for seen indicator and call system
    if ($partner_type === 'Staff') {
        $av_res = db_query(
            "SELECT user_id, profile_picture, first_name, last_name FROM users WHERE user_id = COALESCE(
            (SELECT jo.assigned_to FROM job_orders jo JOIN users u ON u.user_id = jo.assigned_to WHERE jo.order_id = ? AND jo.assigned_to IS NOT NULL AND u.role != 'Admin' ORDER BY jo.updated_at DESC LIMIT 1),
            (SELECT m.sender_id FROM order_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.order_id = ? AND m.sender_id > 0 AND m.sender = 'Staff' AND u.role != 'Admin' ORDER BY m.message_id DESC LIMIT 1),
            (SELECT m.sender_id FROM order_messages m WHERE m.order_id = ? AND m.sender_id > 0 AND m.sender = 'Staff' ORDER BY m.message_id DESC LIMIT 1),
            (SELECT u.user_id FROM users u WHERE u.branch_id = (SELECT o.branch_id FROM orders o WHERE o.order_id = ?) AND u.role = 'Staff' AND u.status = 'Activated' ORDER BY u.user_id ASC LIMIT 1),
            (SELECT user_id FROM users WHERE role = 'Admin' ORDER BY user_id ASC LIMIT 1)
        )",
            'iiii',
            [$order_id, $order_id, $order_id, $order_id]
        );
        if ($av_res) {
            $partner['avatar'] = $av_res[0]['profile_picture'];
            $partner['id'] = (int) $av_res[0]['user_id'];
            $partner['name'] = trim(($av_res[0]['first_name'] ?? '') . ' ' . ($av_res[0]['last_name'] ?? '')) ?: 'Customer Support';
        }
    } else {
        $av_res = db_query("SELECT c.customer_id, c.profile_picture, c.first_name, c.last_name FROM customers c WHERE c.customer_id = (SELECT customer_id FROM orders WHERE order_id = ?)", 'i', [$order_id]);
        if ($av_res) {
            $partner['avatar'] = $av_res[0]['profile_picture'];
            $partner['id'] = (int) $av_res[0]['customer_id'];
            $partner['name'] = trim(($av_res[0]['first_name'] ?? '') . ' ' . ($av_res[0]['last_name'] ?? ''));
        }
    }

    $partner['avatar'] = get_profile_image($partner['avatar'] ?? null);

    // 4. Fetch order metadata (archive status)
    $has_archived_col = db_table_has_column('orders', 'is_archived');
    $order_meta = $has_archived_col
        ? db_query("SELECT is_archived FROM orders WHERE order_id = ?", 'i', [$order_id])
        : [];
    $is_archived = !empty($order_meta) ? (bool) $order_meta[0]['is_archived'] : false;

    // 5. Fetch last seen message ID for the current authenticated user's sent messages
    $user_sender_type = ($user_type === 'Customer') ? 'Customer' : 'Staff';
    $last_seen_id = -1;
    $seen_query = db_query("SELECT MAX(message_id) as last_seen FROM order_messages WHERE order_id = ? AND sender = ? AND read_receipt = 2", 'is', [$order_id, $user_sender_type]);
    if (!empty($seen_query) && $seen_query[0]['last_seen']) {
        $last_seen_id = (int) $seen_query[0]['last_seen'];
    }

    // 6. Fetch all pinned messages for the Pinned Bar
    $pinned_sql = "SELECT m.message_id as id, m.message, m.message_type, m.image_path, m.file_type, m.created_at,
                CASE 
                    WHEN m.sender = 'Customer' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM customers WHERE customer_id = m.sender_id)
                    WHEN m.sender = 'Staff' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = m.sender_id)
                    ELSE 'System' 
                END as sender_name
              FROM order_messages m 
              WHERE m.order_id = ? AND m.is_pinned = 1 
              ORDER BY m.created_at DESC";
    $pinned_messages_raw = db_query($pinned_sql, 'i', [$order_id]) ?: [];
    $pinned_messages = [];
    foreach ($pinned_messages_raw as $pm) {
        $image_path = (string) ($pm['image_path'] ?? '');
        if ($image_path !== '' && !preg_match('#^https?://#i', $image_path)) {
            $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
            if ($image_path[0] !== '/') {
                $image_path = $bp . '/' . $image_path;
            } elseif ($bp !== '' && strpos($image_path, $bp . '/') !== 0) {
                $image_path = $bp . $image_path;
            }
        }
        $pm['image_path'] = $image_path;
        $pm['created_at'] = date('M j, h:i A', strtotime($pm['created_at']));
        $pinned_messages[] = $pm;
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'current_user_type' => $current_user_type,
        'messages' => $messages,
        'reactions' => $reactions,
        'partner' => $partner,
        'is_archived' => $is_archived,
        'last_seen_message_id' => $last_seen_id,
        'pinned_messages' => $pinned_messages
    ]);

} catch (Exception $e) {
    if (ob_get_level() > 0)
        ob_end_clean();
    // Return 200 OK with success => false per user request to avoid 500s that break clients
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (ob_get_level() > 0)
        ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Critical error: ' . $t->getMessage()]);
}
?>
