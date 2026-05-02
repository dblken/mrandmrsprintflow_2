<?php
/**
 * Helper Functions
 * PrintFlow - Printing Shop PWA
 */

// Set Timezone – adjust this based on your location
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_sms_config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ensure_order_source_column.php'; // Ensure order_source column exists

// Global Environment Detection
if (!defined('BASE_PATH')) {
    $is_prod = (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'mrandmrsprintflow.com') !== false || strpos($_SERVER['HTTP_HOST'], 'hostinger') !== false));
    define('BASE_PATH', $is_prod ? '' : '/printflow');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', BASE_PATH);
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Check if request is AJAX/XHR
 * @return bool
 */
function is_xhr() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Send email notification using PHPMailer
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message Email body (HTML)
 * @param bool $is_html Whether message is HTML (default: true)
 * @return bool
 */
function send_email($to, $subject, $message, $is_html = true) {
    // Check if email is enabled
    if (!EMAIL_ENABLED) {
        error_log("Email sending disabled. Would send to: {$to}");
        return false;
    }
    
    try {
        $mail = new PHPMailer(true);

        $smtpFile = __DIR__ . '/smtp_config.php';
        $smtpCfg  = (is_file($smtpFile)) ? require $smtpFile : null;

        // Prefer includes/smtp_config.php (same as OTP / profile mailers) over email_sms_config placeholders
        if (is_array($smtpCfg) && !empty($smtpCfg['smtp_host']) && !empty($smtpCfg['smtp_user']) && ($smtpCfg['smtp_pass'] ?? '') !== '') {
            $mail->isSMTP();
            $mail->Host       = $smtpCfg['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpCfg['smtp_user'];
            $mail->Password   = $smtpCfg['smtp_pass'];
            $mail->SMTPSecure = ($smtpCfg['smtp_secure'] ?? 'tls') === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) ($smtpCfg['smtp_port'] ?? 587);
            $fromEmail        = $smtpCfg['from_email'] ?? $smtpCfg['smtp_user'];
            $fromName         = $smtpCfg['from_name'] ?? 'PrintFlow';
        } elseif (EMAIL_SERVICE === 'smtp') {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            $fromEmail        = EMAIL_FROM_ADDRESS;
            $fromName         = EMAIL_FROM_NAME;
        } elseif (EMAIL_SERVICE === 'sendmail') {
            $mail->isSendmail();
            $fromEmail = EMAIL_FROM_ADDRESS;
            $fromName  = EMAIL_FROM_NAME;
        } else {
            $mail->isMail();
            $fromEmail = EMAIL_FROM_ADDRESS;
            $fromName  = EMAIL_FROM_NAME;
        }
        
        // Recipients
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->addReplyTo($fromEmail, $fromName);
        
        // Content
        $mail->isHTML($is_html);
        $mail->Subject = $subject;
        
        if ($is_html) {
            $mail->Body = $message;
            $mail->AltBody = strip_tags($message);
        } else {
            $mail->Body = $message;
        }
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Failed to send email to {$to}: " . $mail->ErrorInfo);
        error_log("\n" . str_repeat('=', 70));
        error_log("PRINTFLOW EMAIL ERROR - Quick Fix Guide:");
        error_log("1. Open: includes/smtp_config.php");
        error_log("2. Replace 'your-email@gmail.com' with your actual Gmail");
        error_log("3. Get App Password: https://myaccount.google.com/apppasswords");
        error_log("4. Replace 'your-app-password' with the 16-char password");
        error_log("5. See SMTP_SETUP_GUIDE.md for detailed instructions");
        error_log(str_repeat('=', 70) . "\n");
        return false;
    }
}

/**
 * Send SMS notification
 * @param string $phone Phone number
 * @param string $message SMS message
 * @return bool
 */
function send_sms($phone, $message) {
    // Check if SMS is enabled
    if (!SMS_ENABLED) {
        error_log("SMS sending disabled. Would send to: {$phone} - Message: {$message}");
        return false;
    }
    
    try {
        if (SMS_SERVICE === 'semaphore') {
            // Semaphore SMS API (Philippines)
            $url = 'https://api.semaphore.co/api/v4/messages';
            $data = [
                'apikey' => SEMAPHORE_API_KEY,
                'number' => $phone,
                'message' => $message,
                'sendername' => SEMAPHORE_SENDER_NAME
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200) {
                return true;
            } else {
                error_log("Semaphore SMS failed: " . $response);
                return false;
            }
            
        } elseif (SMS_SERVICE === 'twilio') {
            // Twilio SMS API
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $twilio = new \Twilio\Rest\Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
            $twilio->messages->create($phone, [
                'from' => TWILIO_PHONE_NUMBER,
                'body' => $message
            ]);
            
            return true;
            
        } else {
            error_log("No SMS service configured. Message to {$phone}: {$message}");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Failed to send SMS to {$phone}: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a notification
 * @param int $user_id User or Customer ID
 * @param string $user_type 'Customer' or 'User'
 * @param string $message Notification message
 * @param string $type Notification type ('Order', 'Stock', 'System', 'Message')
 * @param bool $send_email Whether to send email
 * @param bool $send_sms Whether to send SMS
 * @return bool|int
 */
function create_notification($user_id, $user_type, $message, $type = 'System', $send_email = false, $send_sms = false, $data_id = null) {
    // ── Pre-check ENUM ───────────────────────────────────────────────────────
    static $enums_checked = false;
    if (!$enums_checked) {
        try {
            $col = db_query("SHOW COLUMNS FROM notifications LIKE 'type'");
            if (!empty($col[0]['Type'])) {
                $t = (string)$col[0]['Type'];
                if (stripos($t, "'Rating'") === false || stripos($t, "'Review'") === false) {
                    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $t, $m);
                    $vals = $m[1] ?? [];
                    if (!in_array('Rating', $vals)) $vals[] = 'Rating';
                    if (!in_array('Review', $vals)) $vals[] = 'Review';
                    $escaped = array_map(function($v) { return "'" . str_replace("'", "\\'", $v) . "'"; }, $vals);
                    db_execute("ALTER TABLE notifications MODIFY COLUMN type ENUM(" . implode(",", $escaped) . ") DEFAULT 'System'");
                }
            }

            // Ensure data_id column exists
            $has_data_id = db_query("SHOW COLUMNS FROM notifications LIKE 'data_id'");
            if (empty($has_data_id)) {
                db_execute("ALTER TABLE notifications ADD COLUMN data_id INT DEFAULT 0 AFTER type");
            }

            // Ensure is_read, send_email, send_sms exist
            $has_is_read = db_query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
            if (empty($has_is_read)) {
                db_execute("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER data_id");
            }
            $has_send_email = db_query("SHOW COLUMNS FROM notifications LIKE 'send_email'");
            if (empty($has_send_email)) {
                db_execute("ALTER TABLE notifications ADD COLUMN send_email TINYINT(1) DEFAULT 0 AFTER is_read");
            }
            $has_send_sms = db_query("SHOW COLUMNS FROM notifications LIKE 'send_sms'");
            if (empty($has_send_sms)) {
                db_execute("ALTER TABLE notifications ADD COLUMN send_sms TINYINT(1) DEFAULT 0 AFTER send_email");
            }
        } catch (Throwable $e) { error_log("Failed to ensure notification schema: " . $e->getMessage()); }
        $enums_checked = true;
    }

    $customer_id = $user_type === 'Customer' ? $user_id : null;
    $staff_user_id = $user_type !== 'Customer' ? $user_id : null;
    $safe_type = trim((string)$type);
    $safe_message = trim((string)$message);
    $safe_data_id = ($data_id === null || $data_id === '') ? 0 : (int)$data_id;

    // Guard against accidental duplicate inserts fired within a few seconds.
    if ($customer_id !== null) {
        $dup = db_query(
            "SELECT notification_id
             FROM notifications
             WHERE customer_id = ?
               AND type = ?
               AND message = ?
               AND COALESCE(data_id, 0) = ?
               AND created_at >= (NOW() - INTERVAL 20 SECOND)
             ORDER BY notification_id DESC
             LIMIT 1",
            'issi',
            [$customer_id, $safe_type, $safe_message, $safe_data_id]
        );
        if (!empty($dup)) {
            return (int)($dup[0]['notification_id'] ?? 0);
        }
    } else {
        $dup = db_query(
            "SELECT notification_id
             FROM notifications
             WHERE user_id = ?
               AND type = ?
               AND message = ?
               AND COALESCE(data_id, 0) = ?
               AND created_at >= (NOW() - INTERVAL 20 SECOND)
             ORDER BY notification_id DESC
             LIMIT 1",
            'iisi',
            [$staff_user_id, $safe_type, $safe_message, $safe_data_id]
        );
        if (!empty($dup)) {
            return (int)($dup[0]['notification_id'] ?? 0);
        }
    }
    
    $sql = "INSERT INTO notifications (user_id, customer_id, message, type, data_id, is_read, send_email, send_sms) 
            VALUES (?, ?, ?, ?, ?, 0, ?, ?)";
    
    $result = db_execute($sql, 'iissiii', [
        $staff_user_id,
        $customer_id,
        $safe_message,
        $safe_type,
        $safe_data_id,
        $send_email ? 1 : 0,
        $send_sms ? 1 : 0
    ]);
    
    if ($result && $send_email) {
        // Get user email
        if ($user_type === 'Customer') {
            $user = db_query("SELECT email FROM customers WHERE customer_id = ?", 'i', [$user_id]);
        } else {
            $user = db_query("SELECT email FROM users WHERE user_id = ?", 'i', [$user_id]);
        }
        
        if (!empty($user)) {
            send_email($user[0]['email'], "PrintFlow Notification", $message);
        }
    }

    // ── Web Push dispatch ────────────────────────────────────────────────────
    if ($result) {
        $push_helper = __DIR__ . '/push_helper.php';
        $push_queue_helper = __DIR__ . '/push_queue_helper.php';

        if (file_exists($push_queue_helper)) {
            require_once $push_queue_helper;
        }

        if (file_exists($push_helper)) {
            require_once $push_helper;
            if (function_exists('push_dispatch_user') && function_exists('push_url_for_type')) {
                $push_url = push_url_for_type($type, $data_id, $user_type);
                $push_media = printflow_push_media_payload((string)$type, $data_id, (string)$message);
                $push_title = printflow_push_title_for_notification((string)$type, (string)$message, (string)$user_type);
                if ($type === 'System' && $data_id !== null && $data_id !== '' && (int)$data_id > 0) {
                    $ml = strtolower((string)$message);
                    if (strpos($ml, 'support chat') !== false || strpos($ml, 'chatbot') !== false) {
                        if ($user_type === 'Customer') {
                            $push_url = printflow_notification_base_path() . '/customer/notifications.php?chatbot=open';
                        } elseif ($user_type === 'Admin') {
                            $push_url = printflow_notification_base_path() . '/admin/faq_chatbot_management.php?tab=inquiries';
                        } elseif ($user_type === 'Manager') {
                            $push_url = printflow_notification_base_path() . '/manager/notifications.php';
                        } elseif ($user_type === 'Staff') {
                            $push_url = printflow_notification_base_path() . '/staff/notifications.php';
                        }
                    }
                    if (strpos($ml, 'ready for admin review') !== false || strpos($ml, 'completed their profile') !== false) {
                        $push_url = printflow_notification_base_path() . '/admin/user_staff_management.php?open_user=' . (int)$data_id;
                    }
                }
                $push_payload = [
                    'title' => $push_title,
                    'body' => $message,
                    // Use the notification row id so every new event can surface as a
                    // distinct system notification instead of replacing older ones for
                    // the same order/chat thread while the app is inactive.
                    'tag'  => 'pf-' . strtolower($type) . '-notif-' . (int)$result,
                    'url'  => $push_url,
                    'icon' => $push_media['icon'],
                    'image' => $push_media['image'],
                ];

                if (function_exists('printflow_enqueue_push_notification')) {
                    printflow_enqueue_push_notification((int)$result, (int)$user_id, (string)$user_type, $push_payload);
                }

                $dispatch = push_dispatch_user((int)$user_id, $user_type, $push_payload);

                if ((int)($dispatch['sent'] ?? 0) > 0) {
                    if (function_exists('printflow_mark_push_queue_sent')) {
                        printflow_mark_push_queue_sent((int)$result, (int)$user_id, (string)$user_type);
                    }
                } else {
                    error_log('[push] Initial send did not complete for notification ' . (int)$result
                        . ' user=' . (int)$user_id
                        . ' type=' . (string)$user_type
                        . ' subscriptions=' . (int)($dispatch['subscriptions'] ?? 0)
                        . ' failed=' . (int)($dispatch['failed'] ?? 0)
                        . ' error=' . (string)($dispatch['last_error'] ?? ''));

                    // Fallback for shared-hosting setups where cron is unavailable:
                    // opportunistically process pending queue immediately.
                    if (function_exists('printflow_process_push_queue')) {
                        try {
                            printflow_process_push_queue(10);
                        } catch (Throwable $queueError) {
                            error_log('[push] Queue fallback failed: ' . $queueError->getMessage());
                        }
                    }
                }
            }
        }
    }

    return $result;
}

/**
 * Notify activated shop users and use each user's role for push subscription matching.
 */
function notify_shop_users(string $message, string $type = 'System', bool $send_email = false, bool $send_sms = false, $data_id = null, array $roles = ['Staff', 'Admin', 'Manager']): void {
    $allowed_roles = ['Staff', 'Admin', 'Manager'];
    $roles = array_values(array_unique(array_intersect($roles, $allowed_roles)));
    if (empty($roles)) {
        $roles = $allowed_roles;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $users = db_query(
        "SELECT user_id, role, branch_id FROM users WHERE role IN ($placeholders) AND status = 'Activated'",
        str_repeat('s', count($roles)),
        $roles
    );

    $targetBranchId = null;
    if (function_exists('printflow_notification_branch_id')) {
        $targetBranchId = printflow_notification_branch_id([
            'type' => $type,
            'data_id' => $data_id,
            'message' => $message,
        ]);
    }

    foreach ((array)$users as $u) {
        $role = $u['role'] ?? 'Staff';
        if (!in_array($role, $allowed_roles, true)) {
            $role = 'Staff';
        }

        if ($targetBranchId !== null && in_array($role, ['Staff', 'Manager'], true)) {
            if ((int)($u['branch_id'] ?? 0) !== $targetBranchId) {
                continue;
            }
        }

        create_notification((int)$u['user_id'], $role, $message, $type, $send_email, $send_sms, $data_id);
    }
}

/**
 * Resolve the related branch for a notification payload when possible.
 */
function printflow_notification_branch_id(array $notification): ?int {
    static $cache = [];

    $type = (string)($notification['type'] ?? '');
    $dataId = (int)($notification['data_id'] ?? 0);
    $cacheKey = $type . ':' . $dataId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if ($dataId <= 0) {
        return $cache[$cacheKey] = null;
    }

    $branchId = null;

    $orderRow = db_query('SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$dataId]);
    if (!empty($orderRow)) {
        $branchId = (int)($orderRow[0]['branch_id'] ?? 0);
        return $cache[$cacheKey] = ($branchId > 0 ? $branchId : null);
    }

    $jobRow = db_query(
        'SELECT COALESCE(jo.branch_id, o.branch_id) AS branch_id
         FROM job_orders jo
         LEFT JOIN orders o ON o.order_id = jo.order_id
         WHERE jo.id = ? LIMIT 1',
        'i',
        [$dataId]
    );
    if (!empty($jobRow)) {
        $branchId = (int)($jobRow[0]['branch_id'] ?? 0);
        return $cache[$cacheKey] = ($branchId > 0 ? $branchId : null);
    }

    $serviceRow = db_query('SELECT branch_id FROM service_orders WHERE id = ? LIMIT 1', 'i', [$dataId]);
    if (!empty($serviceRow)) {
        $branchId = (int)($serviceRow[0]['branch_id'] ?? 0);
        return $cache[$cacheKey] = ($branchId > 0 ? $branchId : null);
    }

    return $cache[$cacheKey] = null;
}

/**
 * True when a notification should be visible to the given staff/manager branch.
 */
function printflow_staff_notification_visible(array $notification, ?int $branchId): bool {
    if ($branchId === null || $branchId <= 0) {
        return true;
    }

    $type = (string)($notification['type'] ?? '');
    $shopScopedTypes = ['Order', 'Payment', 'Design', 'Job Order', 'Payment Issue'];
    if (in_array($type, $shopScopedTypes, true)) {
        $notificationBranch = printflow_notification_branch_id($notification);
        return $notificationBranch !== null && $notificationBranch === $branchId;
    }

    if ($type === 'Stock') {
        $notificationBranch = printflow_notification_branch_id($notification);
        if ($notificationBranch === null) {
            return false;
        }
        return $notificationBranch === $branchId;
    }

    return true;
}

/**
 * True when a notification should be visible to the current admin/manager viewer.
 */
function printflow_admin_notification_visible(array $notification, ?int $branchId = null, ?string $viewerRole = null): bool {
    $viewerRole = $viewerRole ?: (function_exists('get_user_type') ? (string)get_user_type() : '');
    if ($viewerRole !== 'Manager') {
        return true;
    }

    return printflow_staff_notification_visible($notification, $branchId);
}

/**
 * Filter raw notification rows to those visible for the current user role/branch.
 */
function printflow_filter_notifications_for_user(array $rows, string $userType, ?int $branchId = null): array {
    if (empty($rows)) {
        return [];
    }

    if ($userType === 'Staff') {
        return array_values(array_filter($rows, static function (array $row) use ($branchId): bool {
            return printflow_staff_notification_visible($row, $branchId);
        }));
    }

    if ($userType === 'Manager') {
        return array_values(array_filter($rows, static function (array $row) use ($branchId): bool {
            return printflow_admin_notification_visible($row, $branchId, 'Manager');
        }));
    }

    return array_values($rows);
}

/**
 * Resolve the current app base path without forcing legacy /printflow fallbacks.
 */
function printflow_notification_base_path(): string {
    $base = '';
    if (defined('BASE_PATH')) {
        $base = (string)BASE_PATH;
    } elseif (defined('BASE_URL')) {
        $base = (string)BASE_URL;
    } elseif (function_exists('pf_app_base_path')) {
        $base = (string)pf_app_base_path();
    }

    $base = rtrim(trim($base), '/');
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && strpos($host, 'mrandmrsprintflow.com') !== false && $base === '/printflow') {
        $base = '';
    }
    return $base === '/' ? '' : $base;
}

/**
 * Resolve the correct notification destination URL for the current user role.
 */
function printflow_notification_target_url_for_user(string $userType, array $notification): string {
    return match ($userType) {
        'Staff' => staff_notification_target_url($notification),
        'Admin', 'Manager' => admin_notification_target_url($notification),
        default => customer_notification_target_url($notification),
    };
}

/**
 * Notify all activated shop users (Staff, Admin, Manager) about a new customer order.
 */
function notify_staff_new_order(int $order_id, string $customer_first_name, int $customer_id = 0): void {
    $preview = printflow_order_notification_preview($order_id);
    $service_name = trim((string)($preview['display_name'] ?? ''));
    if ($service_name === '') {
        $snap = printflow_notification_order_snapshot($order_id);
        $service_name = trim((string)($snap['service_name'] ?? ''));
    }
    if ($service_name === '') {
        $oRow = db_query('SELECT order_type, reference_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$order_id]);
        if (!empty($oRow[0])) {
            $ot = strtolower(trim((string)($oRow[0]['order_type'] ?? '')));
            $ref = (int)($oRow[0]['reference_id'] ?? 0);
            if ($ref > 0) {
                if ($ot === 'custom') {
                    $service_name = customer_orders_resolve_service_name_by_id($ref);
                } else {
                    $pn = db_query('SELECT name FROM products WHERE product_id = ? LIMIT 1', 'i', [$ref]);
                    $service_name = trim((string)($pn[0]['name'] ?? ''));
                }
            }
        }
    }
    if ($service_name === '') {
        $service_name = trim((string)($preview['item_kind'] ?? '')) === 'Product' ? 'a product order' : 'a service order';
    }
    $name = trim($customer_first_name) !== '' ? trim($customer_first_name) : 'A customer';
    $msg = "{$name} sent an inquiry for {$service_name}";

    // Notify shop users (existing notification system)
    notify_shop_users($msg, 'Order', false, false, $order_id);

    // Previously we also inserted an `order_messages` entry here which caused
    // duplicate "order_card" chat messages because other order-update helpers
    // (e.g. `printflow_send_order_update`) also create an order card. To avoid
    // duplicate messages, we now only send notifications and let the dedicated
    // order update system insert chat messages where appropriate.
}

/**
 * Notify the opposite side about a new chat event for an order.
 * Sender role is the chat sender stored in order_messages: 'Customer' or 'Staff'.
 */
function printflow_notify_chat_message(int $order_id, string $senderRole, string $messageKind = 'message'): void {
    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return;
    }

    $senderRole = trim($senderRole);
    $kind = strtolower(trim($messageKind));
    $kindLabel = match ($kind) {
        'voice' => 'voice message',
        'image', 'attachment', 'media', 'video' => 'attachment',
        default => 'message',
    };

    if ($senderRole === 'Customer') {
        notify_shop_users("New {$kindLabel} from customer for Order #{$order_id}", 'Message', false, false, $order_id, ['Staff', 'Admin', 'Manager']);
        return;
    }

    $rows = db_query("SELECT customer_id FROM orders WHERE order_id = ? LIMIT 1", 'i', [$order_id]);
    $customerId = (int)($rows[0]['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return;
    }

    create_notification($customerId, 'Customer', "New {$kindLabel} from PrintFlow for Order #{$order_id}", 'Message', false, false, $order_id);
}

/**
 * Target URL when a staff user opens a notification (dashboard, list, etc.).
 */
function staff_notification_target_url(array $n): string {
    $base = printflow_notification_base_path();
    $msg = isset($n['message']) ? (string)$n['message'] : '';
    $msg_lower = strtolower($msg);
    $type = strtolower((string)($n['type'] ?? ''));
    $data_id = isset($n['data_id']) && $n['data_id'] !== null && $n['data_id'] !== ''
        ? (int)$n['data_id']
        : 0;

    $is_rating = (
        ((string)($n['type'] ?? '') === 'Rating') ||
        ((stripos($msg, 'rating') !== false || stripos($msg, 'review') !== false) && stripos($msg, 'design') === false)
    );
    if ($is_rating) {
        return $base . '/staff/reviews.php';
    }

    if ($type === 'system') {
        return $base . '/staff/notifications.php';
    }

    if ($type === 'stock' || strpos($type, 'inventory') !== false) {
        return $base . '/staff/notifications.php';
    }

    if ($data_id > 0) {
        if (strpos($type, 'chat') !== false || strpos($type, 'message') !== false) {
            return $base . '/staff/chats.php?order_id=' . $data_id;
        }

        if (strpos($type, 'job order') !== false || strpos($type, 'payment issue') !== false) {
            return $base . '/staff/customizations.php?order_id=' . $data_id . '&job_type=JOB';
        }

        if (
            strpos($type, 'design') !== false ||
            strpos($msg_lower, 're-uploaded design') !== false ||
            strpos($msg_lower, 'design re-upload') !== false ||
            strpos($msg_lower, 'revision') !== false
        ) {
            return printflow_staff_order_management_url($data_id, true);
        }

        $job_row = db_query(
            "SELECT id FROM job_orders WHERE id = ? LIMIT 1",
            'i',
            [$data_id]
        );
        if (!empty($job_row)) {
            return $base . '/staff/customizations.php?order_id=' . $data_id . '&job_type=JOB';
        }

        $ord_row = db_query(
            "SELECT order_id FROM orders WHERE order_id = ? LIMIT 1",
            'i',
            [$data_id]
        );
        if (!empty($ord_row)) {
            return printflow_staff_order_management_url($data_id, true);
        }

        if (strpos($type, 'payment') !== false || strpos($type, 'order') !== false) {
            return printflow_staff_order_management_url($data_id, false);
        }

        return printflow_staff_order_management_url($data_id, false);
    }

    return $base . '/staff/notifications.php';
}

/**
 * Link for a staff notification row (marks read then redirects when unread).
 */
function staff_notification_item_href(array $n): string {
    $target = staff_notification_target_url($n);
    $base = defined('BASE_URL') ? BASE_URL : '/printflow';
    if (isset($n['is_read']) && (int)$n['is_read'] === 0) {
        return $base . '/staff/notifications.php?mark_read=' . (int)($n['notification_id'] ?? 0) . '&next=' . urlencode($target);
    }
    return $target;
}

/**
 * Target URL when an admin/manager opens a notification.
 */
function admin_notification_target_url(array $n): string {
    $base = printflow_notification_base_path();
    $viewerRole = function_exists('get_user_type') ? (string)get_user_type() : '';
    $viewerBranch = function_exists('printflow_branch_filter_for_user') ? printflow_branch_filter_for_user() : null;
    $panelBase = $viewerRole === 'Manager' ? ($base . '/manager') : ($base . '/admin');
    $ordersPage = $viewerRole === 'Manager' ? ($panelBase . '/orders.php') : ($panelBase . '/orders_management.php');
    $notificationsPage = $panelBase . '/notifications.php';
    $type = isset($n['type']) ? (string)$n['type'] : '';
    $msg = isset($n['message']) ? strtolower((string)$n['message']) : '';
    $dataId = isset($n['data_id']) && $n['data_id'] !== null && $n['data_id'] !== ''
        ? (int)$n['data_id'] : 0;

    if (!printflow_admin_notification_visible($n, is_int($viewerBranch) ? $viewerBranch : null, $viewerRole)) {
        return $notificationsPage;
    }

    if ($type === 'System' && (
        strpos($msg, 'chatbot') !== false ||
        strpos($msg, 'support chat') !== false
    )) {
        if ($viewerRole === 'Manager') {
            return $notificationsPage;
        }
        return $panelBase . '/faq_chatbot_management.php?tab=inquiries';
    }

    // Staff submitted profile for activation (data_id = users.user_id)
    if ($dataId > 0 && $type === 'System' && (
        strpos($msg, 'ready for admin review') !== false ||
        strpos($msg, 'completed their profile') !== false
    )) {
        if ($viewerRole === 'Manager') {
            return $notificationsPage;
        }
        return $panelBase . '/user_staff_management.php?open_user=' . $dataId;
    }

    if ($dataId > 0 && $type === 'System' && (
        strpos($msg, 'submitted an id for verification') !== false ||
        strpos($msg, 'resubmitted an id for verification') !== false
    )) {
        return $panelBase . '/customers_management.php?open_customer=' . $dataId;
    }

    if ($dataId > 0) {
        if (in_array($type, ['Order', 'Design', 'Message'], true)) {
            return $ordersPage . '?open_order=' . $dataId;
        }
        if (in_array($type, ['Job Order', 'Payment Issue'], true)) {
            return $panelBase . '/job_orders.php?open_job=' . $dataId;
        }
        if ($type === 'Stock') {
            return $panelBase . '/inv_transactions_ledger.php?item_id=' . $dataId;
        }
        if ($type === 'Payment') {
            return $ordersPage . '?open_order=' . $dataId;
        }
    }

    if ($type === 'Payment') {
        return $ordersPage;
    }

    return $panelBase . '/dashboard.php';
}

/**
 * Log user activity
 * @param int $user_id
 * @param string $action Action performed
 * @param string $details Additional details
 * @return bool|int
 */
function log_activity($user_id, $action, $details = '') {
    // activity_logs.user_id has FK to users.user_id only.
    // Customer IDs are from customers.customer_id and can violate FK.
    // Logging must never break request flow.
    try {
        $resolved_user_id = 0;

        if (is_numeric($user_id)) {
            $candidate = (int)$user_id;
            if ($candidate > 0) {
                $exists = db_query("SELECT user_id FROM users WHERE user_id = ? LIMIT 1", 'i', [$candidate]);
                if (!empty($exists)) {
                    $resolved_user_id = $candidate;
                }
            }
        }

        // If the provided ID is not a valid staff/admin user, skip insert safely.
        if ($resolved_user_id <= 0) {
            return true;
        }

        $sql = "INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())";
        $result = db_execute($sql, 'iss', [$resolved_user_id, (string)$action, (string)$details]);
        return $result !== false;
    } catch (Throwable $e) {
        error_log("Activity log failed: " . $e->getMessage());
        return true; // Never block main feature when activity log fails
    }
}

/**
 * Get customer ID from session
 * @return int|null
 */
function get_customer_id() {
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Customer') {
        return $_SESSION['user_id'] ?? null;
    }
    return null;
}

/**
 * Load customer cart from database into session.
 * Call after customer login or when session cart is empty.
 * @param int $customer_id
 * @return void
 */
function load_customer_cart_into_session($customer_id) {
    if (!$customer_id) return;
    $rows = db_query("SELECT product_id, variant_id, quantity FROM customer_cart WHERE customer_id = ?", 'i', [$customer_id]);
    if (empty($rows)) return;
    $_SESSION['cart'] = [];
    foreach ($rows as $r) {
        $pid = (int)$r['product_id'];
        $vid = isset($r['variant_id']) && $r['variant_id'] !== '' && $r['variant_id'] !== null ? (int)$r['variant_id'] : null;
        $qty = max(0, (int)$r['quantity']);
        if ($qty <= 0 || $pid <= 0) continue;
        $product = db_query("SELECT name, price, category, product_type FROM products WHERE product_id = ? AND status = 'Activated'", 'i', [$pid]);
        if (empty($product)) continue;
        $product = $product[0];
        $price = (float)$product['price'];
        $variant_name = '';
        if ($vid) {
            $v = db_query("SELECT variant_name, price FROM product_variants WHERE variant_id = ? AND product_id = ? AND status = 'Active'", 'ii', [$vid, $pid]);
            if (!empty($v)) {
                $variant_name = $v[0]['variant_name'] ?? '';
                $price = (float)$v[0]['price'];
            }
        }
        $key = $pid . '_' . ($vid ?? '0');
        $_SESSION['cart'][$key] = [
            'product_id' => $pid,
            'variant_id' => $vid,
            'name' => $product['name'],
            'category' => $product['category'] ?? '',
            'catalog_product_type' => $product['product_type'] ?? 'fixed',
            'source_page' => 'products',
            'variant_name' => $variant_name,
            'quantity' => $qty,
            'price' => $price,
        ];
    }
}

/**
 * Sync session cart to customer_cart table.
 * @param int $customer_id
 * @return void
 */
function sync_cart_to_db($customer_id) {
    if (!$customer_id) return;
    db_execute("DELETE FROM customer_cart WHERE customer_id = ?", 'i', [$customer_id]);
    if (empty($_SESSION['cart'])) return;
    foreach ($_SESSION['cart'] as $key => $item) {
        $qty = (int)($item['quantity'] ?? 0);
        if ($qty <= 0) continue;
        // Persist only true catalog products in customer_cart.
        // Service/custom entries may carry non-catalog IDs and would violate FK.
        $source_page = strtolower(trim((string)($item['source_page'] ?? '')));
        if ($source_page === 'services') continue;
        $pid = (int)($item['product_id'] ?? 0);
        $vid = isset($item['variant_id']) && $item['variant_id'] !== null ? (int)$item['variant_id'] : 0;
        if ($pid <= 0) continue;
        $exists = db_query("SELECT product_id FROM products WHERE product_id = ? LIMIT 1", 'i', [$pid]);
        if (empty($exists)) continue;
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to avoid duplicate key errors
        db_execute(
            "INSERT INTO customer_cart (customer_id, product_id, variant_id, quantity, updated_at) 
             VALUES (?, ?, ?, ?, NOW()) 
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()",
            'iiii',
            [$customer_id, $pid, $vid, $qty]
        );
    }
}

/**
 * Get customer cancellation count (stubbed)
 * @param int $customer_id
 * @return int
 */
function get_customer_cancel_count($customer_id) {
    return 0;
}

/**
 * Check if customer is restricted due to cancellations
 * @param int $customer_id
 * @return bool
 */
function is_customer_restricted($customer_id) {
    return false;
}



/**
 * Validate file upload
 * @param array $file $_FILES array element
 * @param array $allowed_types Allowed MIME types
 * @param int $max_size Max file size in bytes
 * @return array ['valid' => bool, 'message' => string, 'file_info' => array]
 */
function validate_file_upload($file, $allowed_types = [], $max_size = 10485760) {
    // Default allowed types for design files
    if (empty($allowed_types)) {
        $allowed_types = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'application/pdf',
            'image/svg+xml'
        ];
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'message' => 'File upload error'];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        $max_mb = $max_size / 1048576;
        return ['valid' => false, 'message' => "File too large. Maximum size is {$max_mb}MB"];
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['valid' => false, 'message' => 'Invalid file type'];
    }
    
    return [
        'valid' => true,
        'message' => 'File is valid',
        'file_info' => [
            'name' => $file['name'],
            'size' => $file['size'],
            'type' => $mime_type,
            'tmp_name' => $file['tmp_name']
        ]
    ];
}

/**
 * Upload file to server
 * @param array $file $_FILES array element
 * @param array $allowed_extensions Array of allowed extensions (e.g., ['jpg', 'png', 'pdf'])
 * @param string $destination Directory name under uploads/ (e.g., 'designs', 'payments')
 * @param string|null $new_name Optional new filename
 * @return array ['success' => bool, 'message' => string, 'error' => string, 'file_path' => string]
 */
function upload_file($file, $allowed_extensions = [], $destination = 'uploads', $new_name = null, $max_bytes = 5242880) {
    // Check for upload errors  
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error'];
    }
    
    // Check file size
    if ($file['size'] > $max_bytes) {
        $mb = round($max_bytes / 1048576);
        return ['success' => false, 'error' => "File too large. Maximum size is {$mb}MB"];
    }
    
    // Check extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!empty($allowed_extensions) && !in_array($ext, $allowed_extensions)) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    // Create destination directory if it doesn't exist
    $upload_dir = __DIR__ . '/../uploads/' . $destination;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate filename
    if ($new_name === null) {
        $new_name = uniqid() . '_' . time() . '.' . $ext;
    }
    
    $target_path = $upload_dir . '/' . $new_name;
    
    // Determine base path correctly for relative path generation
    $base = '';
    if (defined('BASE_PATH')) {
        $base = BASE_PATH;
    } elseif (defined('BASE_URL')) {
        $base = BASE_URL;
    } else {
        $base = '/printflow';
    }
    
    $relative_path = rtrim($base, '/') . '/uploads/' . $destination . '/' . $new_name;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return [
            'success' => true,
            'message' => 'File uploaded successfully',
            'file_path' => $relative_path,
            'file_name' => $new_name
        ];
    }
    
    return ['success' => false, 'error' => 'Failed to upload file'];
}

/**
 * Ensure review and ratings tables exist.
 * One review entry per order. Multi-image, video, and staff replies supported.
 * @return void
 */
function ensure_ratings_table_exists() {
    static $ensured = false;
    if ($ensured) return;

    // 1. Core reviews table
    db_execute("
        CREATE TABLE IF NOT EXISTS reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NOT NULL,
            reference_id INT DEFAULT NULL,
            review_type ENUM('product', 'custom') DEFAULT 'custom',
            service_type VARCHAR(150) DEFAULT NULL,
            rating TINYINT NOT NULL,
            comment TEXT DEFAULT NULL,
            video_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_review_order (order_id),
            KEY idx_review_user (user_id),
            KEY idx_review_rating (rating),
            CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 2. Review multiple images
    db_execute("
        CREATE TABLE IF NOT EXISTS review_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            review_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            CONSTRAINT fk_review_images_review FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 3. Staff replies to reviews
    db_execute("
        CREATE TABLE IF NOT EXISTS review_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            review_id INT NOT NULL,
            staff_id INT NOT NULL,
            reply_message TEXT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_review_replies_review FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
            CONSTRAINT fk_review_replies_staff FOREIGN KEY (staff_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

/**
 * Format a timestamp into a relative "X ago" string.
 * @param string|int $timestamp
 * @return string
 */
function format_ago($timestamp) {
    if (!$timestamp) return 'n/a';
    $time = is_numeric($timestamp) ? $timestamp : strtotime($timestamp);
    if (!$time) return 'n/a';
    
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    if ($diff < 31536000) return floor($diff / 2592000) . 'mo ago';
    return floor($diff / 31536000) . 'y ago';
}

/**
 * Ensure `orders.status` enum contains required values.
 * Safe no-op if column is not enum or values already exist.
 * @param array $values
 * @return bool
 */
function ensure_order_status_values(array $values) {
    static $already_checked = [];
    $missing = array_values(array_filter(array_map('strval', $values), fn($v) => $v !== ''));
    if (empty($missing)) return true;
    sort($missing);
    $cache_key = implode('|', $missing);
    if (isset($already_checked[$cache_key])) return $already_checked[$cache_key];

    try {
        $col = db_query("SHOW COLUMNS FROM orders LIKE 'status'");
        if (empty($col[0]['Type'])) {
            return $already_checked[$cache_key] = false;
        }
        $type = (string)$col[0]['Type'];
        if (stripos($type, 'enum(') !== 0) {
            return $already_checked[$cache_key] = false;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
        $current = array_map(static function ($v) {
            return str_replace("\\'", "'", (string)$v);
        }, $m[1] ?? []);
        $all = $current;
        foreach ($missing as $v) {
            if (!in_array($v, $all, true)) $all[] = $v;
        }
        if (count($all) === count($current)) {
            return $already_checked[$cache_key] = true;
        }

        $escaped = array_map(static function ($v) {
            return "'" . str_replace("'", "\\'", (string)$v) . "'";
        }, $all);
        $default = in_array('Pending', $all, true) ? 'Pending' : $all[0];
        $sql = "ALTER TABLE orders MODIFY COLUMN status ENUM(" . implode(',', $escaped) . ") DEFAULT '" . str_replace("'", "\\'", $default) . "'";
        db_execute($sql);

        return $already_checked[$cache_key] = true;
    } catch (Throwable $e) {
        error_log('ensure_order_status_values failed: ' . $e->getMessage());
        return $already_checked[$cache_key] = false;
    }
}

/**
 * Friendly customer-facing status notification message.
 * @param int $order_id
 * @param string $status
 * @return array{type:string,message:string}
 */
function get_order_status_notification_payload($order_id, $status) {
    $order_id = (int)$order_id;
    $status = (string)$status;
    // Keep notification type enum-compatible across deployments.
    $type = 'Order';
    $base_url = defined('BASE_URL') ? BASE_URL : '/printflow';

    $map = [
        'Pending' => "Your order has been received and is pending confirmation.",
        'Pending Review' => "Your order has been received and is pending confirmation.",
        'Pending Approval' => "Your order has been received and is pending confirmation.",
        'For Revision' => "Your order needs revision. Please review the request details.",
        'Approved' => "Your order has been approved and will proceed to payment.",
        'To Pay' => "Your order is now ready for payment.",
        'To Verify' => "Your payment is currently being verified.",
        'Downpayment Submitted' => "Your payment is currently being verified.",
        'Pending Verification' => "Your payment is currently being verified.",
        'Processing' => "Your order is now being processed.",
        'In Production' => "Your order is now being processed.",
        'Printing' => "Your order is now being processed.",
        'Ready for Pickup' => "Your order is ready for pickup.",
        'Completed' => "Your order has been completed. You may now rate your experience.",
        'To Rate' => "Your order has been completed. You may now rate your experience.",
        'Rated' => "Thank you for rating your completed order.",
        'Cancelled' => "Your order has been cancelled."
    ];

    $message = $map[$status] ?? "Your order #{$order_id} status has been updated to: {$status}";
    if ($status === 'Completed' || $status === 'To Rate') {
        $message .= " Rate here: " . $base_url . "/customer/rate_order.php?order_id={$order_id}";
    }

    return ['type' => $type, 'message' => $message];
}

/**
 * Add a system message to an order's chat thread.
 * Call this when order status changes, payment verified, etc.
 *
 * @param int    $order_id
 * @param string $message
 * @return bool
 */
function add_order_system_message($order_id, $message) {
    $order_id = (int)$order_id;
    $message = trim($message);
    if (!$order_id || $message === '') return false;

    $sql = "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt)
            VALUES (?, 'System', 0, ?, 'text', 1)";
    return (bool) db_execute($sql, 'is', [$order_id, $message]);
}

/**
 * Format currency
 * @param float $amount
 * @param string $currency
 * @return string
 */
function format_currency($amount, $currency = '₱') {
    return $currency . ' ' . number_format($amount, 2);
}

/**
 * Build the visible order code from SKU(s) plus order id.
 */
function printflow_format_order_code($order_id, $order_sku = '') {
    $order_id = (int)$order_id;
    $order_sku = trim((string)$order_sku);

    // Hide technical placeholder SKU from customer-facing order codes.
    if ($order_sku !== '') {
        $order_sku = preg_replace('/(?:^|-)POS-SERVICE(?=-|$)/i', '', $order_sku);
        $order_sku = preg_replace('/(?:^|-)POS_SERVICE(?=-|$)/i', '', (string)$order_sku);
        $order_sku = preg_replace('/-{2,}/', '-', (string)$order_sku);
        $order_sku = trim((string)$order_sku, "- \t\n\r\0\x0B");
    }

    return $order_sku !== '' ? ($order_sku . '-' . $order_id) : ('ORD-' . $order_id);
}

function printflow_format_job_code($job_id): string {
    return 'JO-' . str_pad((string)((int)$job_id), 5, '0', STR_PAD_LEFT);
}

function printflow_format_customization_code($customization_id): string {
    return 'CUST-' . str_pad((string)((int)$customization_id), 5, '0', STR_PAD_LEFT);
}

/**
 * Resolve the visible reference label used for job-linked inventory entries.
 *
 * Priority:
 * 1. Store order code (e.g. ORD-6097 or MER-0001-2501) whenever the job is linked to orders.order_id
 * 2. Customization code when the job is not linked to a store order but has a customization row
 * 3. Raw job code fallback
 *
 * @return array{type:string,id:int,code:string,label:string}
 */
function printflow_get_job_inventory_reference(int $job_id): array {
    if ($job_id <= 0) {
        $code = printflow_format_job_code(0);
        return ['type' => 'job', 'id' => 0, 'code' => $code, 'label' => 'Job #' . $code];
    }

    $row = db_query(
        "SELECT jo.id,
                jo.order_id,
                GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') AS order_sku,
                cust_map.customization_id
         FROM job_orders jo
         LEFT JOIN order_items oi ON oi.order_id = jo.order_id
         LEFT JOIN products p ON oi.product_id = p.product_id
         LEFT JOIN (
            SELECT order_id, MIN(customization_id) AS customization_id
            FROM customizations
            GROUP BY order_id
         ) cust_map ON cust_map.order_id = jo.order_id
         WHERE jo.id = ?
         GROUP BY jo.id, jo.order_id, cust_map.customization_id
         LIMIT 1",
        'i',
        [$job_id]
    );

    $order_id = (int)($row[0]['order_id'] ?? 0);
    if ($order_id > 0) {
        // Match customer-facing order codes (ORD-… or SKU-…), including service rows with no product SKU.
        return printflow_get_order_inventory_reference($order_id);
    }

    $customization_id = (int)($row[0]['customization_id'] ?? 0);
    if ($customization_id > 0) {
        $code = printflow_format_customization_code($customization_id);
        return ['type' => 'customization', 'id' => $customization_id, 'code' => $code, 'label' => 'Customization #' . $code];
    }

    $code = printflow_format_job_code($job_id);
    return ['type' => 'job', 'id' => $job_id, 'code' => $code, 'label' => 'Job #' . $code];
}

function printflow_format_inventory_reference_note(string $notes, string $referenceLabel): string {
    $notes = trim($notes);
    if ($notes === '' || $referenceLabel === '') {
        return $notes;
    }

    $notes = (string)preg_replace('/\bJob\s*#(?:JO-)?\d+\b/i', $referenceLabel, $notes);
    $notes = (string)preg_replace('/\bCustomization\s*#(?:CUST-)?\d+\b/i', $referenceLabel, $notes);
    $notes = (string)preg_replace('/\bOrder\s*#(?:ORD-)?[A-Z0-9-]+\b/i', $referenceLabel, $notes);
    return $notes;
}

/**
 * Resolve a store order's visible code using the same SKU-based pattern as the order pages.
 *
 * @return array{type:string,id:int,code:string,label:string}
 */
function printflow_get_order_inventory_reference(int $order_id): array {
    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        $code = printflow_format_order_code(0, '');
        return ['type' => 'order', 'id' => 0, 'code' => $code, 'label' => 'Order #' . $code];
    }

    $row = db_query(
        "SELECT o.order_id,
                GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') AS order_sku
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.order_id
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE o.order_id = ?
         GROUP BY o.order_id
         LIMIT 1",
        'i',
        [$order_id]
    );

    $order_sku = trim((string)($row[0]['order_sku'] ?? ''));
    $code = printflow_format_order_code($order_id, $order_sku);
    return ['type' => 'order', 'id' => $order_id, 'code' => $code, 'label' => 'Order #' . $code];
}

/**
 * Format date
 * @param string $date
 * @param string $format
 * @return string
 */
function format_date($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

/**
 * Format datetime
 * @param string $datetime
 * @param string $format
 * @return string
 */
function format_datetime($datetime, $format = 'F j, Y g:i A') {
    return date($format, strtotime($datetime));
}

/**
 * Choose the customer-facing timestamp for an order card/list row.
 * Pending rows use the purchase time; progressed rows use the latest real status time.
 */
function printflow_customer_order_timestamp_meta(array $order): array {
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $orderDate = (string)($order['order_date'] ?? '');
    $updatedAt = (string)($order['updated_at'] ?? '');
    $cancelledAt = (string)($order['cancelled_at'] ?? '');

    $label = 'Ordered on';
    $chosen = $orderDate;

    if ($status === 'cancelled' && $cancelledAt !== '') {
        $label = 'Cancelled on';
        $chosen = $cancelledAt;
    } elseif (in_array($status, ['completed', 'to rate', 'rated'], true) && $updatedAt !== '') {
        $label = 'Completed on';
        $chosen = $updatedAt;
    } elseif (in_array($status, ['ready for pickup', 'to receive', 'ready'], true) && $updatedAt !== '') {
        $label = 'Ready on';
        $chosen = $updatedAt;
    } elseif (in_array($status, ['in production', 'processing', 'printing', 'paid – in process', 'paid - in process'], true) && $updatedAt !== '') {
        $label = 'Started on';
        $chosen = $updatedAt;
    } elseif (in_array($status, ['approved'], true) && $updatedAt !== '') {
        $label = 'Approved on';
        $chosen = $updatedAt;
    } elseif (!in_array($status, ['pending', 'pending approval', 'pending review'], true) && $updatedAt !== '') {
        $label = 'Updated on';
        $chosen = $updatedAt;
    }

    if ($chosen === '') {
        $chosen = $orderDate;
    }

    return [
        'label' => $label,
        'datetime' => $chosen,
        'formatted' => $chosen !== '' ? format_datetime($chosen) : '',
        'text' => trim($label . ' ' . ($chosen !== '' ? format_datetime($chosen) : '')),
    ];
}

/**
 * Get time ago
 * @param string $datetime
 * @return string
 */
function time_ago($datetime) {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    $periods = [
        'year' => 31536000,
        'month' => 2592000,
        'week' => 604800,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
        'second' => 1
    ];
    
    foreach ($periods as $key => $value) {
        $result = floor($difference / $value);
        
        if ($result >= 1) {
            return $result . ' ' . $key . ($result > 1 ? 's' : '') . ' ago';
        }
    }
    
    return 'Just now';
}

/**
 * Generate status badge HTML
 * @param string $status
 * @param string $type 'order', 'payment', 'design'
 * @return string
 */
function status_badge($status, $type = 'order') {
    // Map job order statuses to display-friendly format
    $job_order_status_map = [
        'PENDING' => 'Pending',
        'APPROVED' => 'Approved',
        'TO_PAY' => 'To Pay',
        'VERIFY_PAY' => 'To Verify',
        'IN_PRODUCTION' => 'Processing',
        'TO_RECEIVE' => 'Ready for Pickup',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled'
    ];
    
    // Map job order payment statuses
    $job_order_payment_status_map = [
        'UNPAID' => 'Unpaid',
        'PENDING_VERIFICATION' => 'Pending Verification',
        'PARTIAL' => 'Partially Paid',
        'PAID' => 'Paid'
    ];
    
    // Convert job order status if needed
    if (isset($job_order_status_map[$status])) {
        $status = $job_order_status_map[$status];
    }
    
    // Convert job order payment status if needed
    if ($type === 'payment' && isset($job_order_payment_status_map[$status])) {
        $status = $job_order_payment_status_map[$status];
    }
    
    $colors = [
        'order' => [
            'Pending' => 'background: #fef3c7; color: #92400e; border: none;',
            'Pending Review' => 'background: #fef3c7; color: #92400e; border: none;',
            'Approved' => 'background: #dbeafe; color: #1e40af; border: none;',
            'To Pay' => 'background: #dbeafe; color: #1e40af; border: none;',
            'To Verify' => 'background: #fef9c3; color: #854d0e; border: none;',
            'Downpayment Submitted' => 'background: #fce7f3; color: #be185d; border: none;',
            'Pending Verification' => 'background: #fef9c3; color: #854d0e; border: none;',
            'Processing' => 'background: #e0e7ff; color: #4338ca; border: none;',
            'In Production' => 'background: #cffafe; color: #0891b2; border: none;',
            'Printing' => 'background: #cffafe; color: #0891b2; border: none;',
            'For Revision' => 'background: #ffe4e6; color: #b91c1c; border: none;',
            'Revision Submitted' => 'background: #fef3c7; color: #92400e; border: none;',
            'Ready for Pickup' => 'background: #dcfce7; color: #15803d; border: none;',
            'Completed' => 'background: #dcfce7; color: #166534; border: none;',
            'To Rate' => 'background: #f3e8ff; color: #6b21a8; border: none;',
            'Rated' => 'background: #f3e8ff; color: #6b21a8; border: none;',
            'Cancelled' => 'background: #fee2e2; color: #991b1b; border: none;'
        ],
        'payment' => [
            'Unpaid' => 'background: #fee2e2; color: #991b1b; border: none;',
            'Partially Paid' => 'background: #fef3c7; color: #92400e; border: none;',
            'Paid' => 'background: #dcfce7; color: #166534; border: none;',
            'Refunded' => 'background: #f3f4f6; color: #374151; border: none;',
            'Pending Verification' => 'background: #fef3c7; color: #92400e; border: none;'
        ],
        'design' => [
            'Pending' => 'background: #fffbeb; color: #92400e; border: 1px solid #fef3c7;',
            'Approved' => 'background: #f0fdf4; color: #166534; border: 1px solid #dcfce7;',
            'Rejected' => 'background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2;'
        ]
    ];
    
    $style = $colors[$type][$status] ?? 'background: #f9fafb; color: #374151; border: 1px solid #f3f4f6;';
    // Display "Pending" instead of "Pending Review" only.
    if ($status === 'Pending Review') {
        $display = 'Pending';
    } else {
        $display = $status;
    }
    
    return "<span class='status-badge-pill' style='{$style}'>" . htmlspecialchars($display) . "</span>";
}


/**
 * Sanitize input
 * @param string $input
 * @return string
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize branch name for Add/Edit: trim, strip trailing "Branch", title-case
 * System auto-appends " Branch" — user should not type it.
 */
function normalize_branch_name($name) {
    $name = trim($name);
    $name = preg_replace('/\s+Branch\s*$/i', '', $name);
    return ucwords(strtolower($name));
}

/**
 * Redirect to URL
 * @param string $url
 */
function redirect($url) {
    header("Location: {$url}");
    exit();
}

/**
 * Get unread notification count
 * @param int $user_id
 * @param string $user_type
 * @return int
 */
function get_unread_notification_count($user_id, $user_type) {
    if ($user_type === 'Customer') {
        $rows = db_query(
            "SELECT notification_id, customer_id, message, type, data_id, is_read, created_at
             FROM notifications
             WHERE customer_id = ? AND is_read = 0
             ORDER BY created_at DESC, notification_id DESC",
            'i',
            [$user_id]
        ) ?: [];
        $rows = printflow_dedupe_notifications($rows, 300);
        return count($rows);
    } else {
        $rows = db_query(
            "SELECT notification_id, user_id, message, type, data_id, is_read, created_at
             FROM notifications
             WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC, notification_id DESC",
            'i',
            [$user_id]
        ) ?: [];

        $branchId = null;
        if (in_array($user_type, ['Staff', 'Manager'], true) && function_exists('printflow_branch_filter_for_user')) {
            $branchId = printflow_branch_filter_for_user();
        }

        $rows = printflow_filter_notifications_for_user($rows, (string)$user_type, is_int($branchId) ? $branchId : null);
        $rows = printflow_dedupe_notifications($rows, 300);
        return count($rows);
    }
}

/**
 * Build customer notification rows for the full page and header dropdown.
 *
 * The notifications table only stores the core event payload, so this helper
 * resolves display title, image fallback, relative time, and safe customer URLs.
 */
function get_customer_notifications_for_display($customer_id, $limit = 10, $offset = 0) {
    $customer_id = (int)$customer_id;
    $limit = max(1, (int)$limit);
    $offset = max(0, (int)$offset);
    $base = defined('BASE_URL') ? BASE_URL : '/printflow';
    $default_image = $base . '/public/assets/images/services/default.png';

    $rows = db_query(
        "SELECT notification_id, customer_id, message, type, data_id, is_read, created_at
         FROM notifications
         WHERE customer_id = ?
         ORDER BY created_at DESC
         LIMIT {$limit} OFFSET {$offset}",
        'i',
        [$customer_id]
    );

    if (empty($rows) || !is_array($rows)) {
        return [];
    }

    $rows = printflow_dedupe_notifications($rows, 120);

    $notifications = [];
    foreach ($rows as $row) {
        $type = (string)($row['type'] ?? 'System');
        $message = printflow_notification_display_message($row);
        $data_id = (int)($row['data_id'] ?? 0);
        $title = customer_notification_title($type, $message, $row);
        $target = customer_notification_target_url($row);
        $link = $target;

        if ((int)($row['is_read'] ?? 0) === 0) {
            $link = $base . '/customer/notifications.php?mark_read=' . (int)$row['notification_id'] . '&next=' . urlencode($target);
        }

        $image = customer_notification_image_url($row, $default_image);

        $notifications[] = [
            'notification_id' => (int)($row['notification_id'] ?? 0),
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data_id' => $data_id,
            'is_read' => (int)($row['is_read'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'time_ago' => !empty($row['created_at']) ? time_ago((string)$row['created_at']) : '',
            'link' => $link,
            'image' => $image,
            'fallback' => $default_image,
        ];
    }

    return $notifications;
}

/**
 * Remove near-identical duplicate notifications generated within a short window.
 * Keeps the most recent row for each duplicate signature.
 */
function printflow_dedupe_notifications(array $rows, int $windowSeconds = 300): array
{
    if (empty($rows)) {
        return [];
    }

    $windowSeconds = max(1, $windowSeconds);
    $seen = [];
    $out = [];

    foreach ($rows as $row) {
        $type = strtolower(trim((string)($row['type'] ?? '')));
        $displayMessage = printflow_notification_display_message($row);
        $message = strtolower(trim(preg_replace('/\s+/', ' ', (string)$displayMessage)));
        $dataId = (int)($row['data_id'] ?? 0);
        if ($dataId <= 0 && preg_match('/order\s*#?(\d+)/i', (string)$displayMessage, $m)) {
            $dataId = (int)($m[1] ?? 0);
        }
        $tsRaw = $row['ts'] ?? null;
        $ts = is_numeric($tsRaw)
            ? (int)$tsRaw
            : (strtotime((string)($row['created_at'] ?? '')) ?: 0);
        $nid = (int)($row['notification_id'] ?? ($row['id'] ?? 0));
        $signature = $type . '|' . $dataId . '|' . $message;

        if (isset($seen[$signature])) {
            $prevIdx = $seen[$signature];
            $prevRow = $out[$prevIdx];
            $prevTsRaw = $prevRow['ts'] ?? null;
            $prevTs = is_numeric($prevTsRaw)
                ? (int)$prevTsRaw
                : (strtotime((string)($prevRow['created_at'] ?? '')) ?: 0);
            $prevNid = (int)($prevRow['notification_id'] ?? ($prevRow['id'] ?? 0));

            if ($ts > 0 && $prevTs > 0 && abs($ts - $prevTs) <= $windowSeconds) {
                if ($ts > $prevTs || ($ts === $prevTs && $nid > $prevNid)) {
                    $out[$prevIdx] = $row;
                }
                continue;
            }
        }

        $seen[$signature] = count($out);
        $out[] = $row;
    }

    return $out;
}

/**
 * Generate a customer-facing notification title.
 * Accepts an optional $notification array so we can enrich the title with
 * the real service/product name from the linked order.
 */
function customer_notification_title($type, $message, array $notification = []) {
    $type    = (string)$type;
    $message_l = strtolower((string)$message);

    if (strpos($message_l, 'support chat') !== false || strpos($message_l, 'chatbot') !== false) {
        return 'Support chat update';
    }
    if ($type === 'Message' || strpos($message_l, 'message') !== false || strpos($message_l, 'chat') !== false) {
        return 'New message';
    }
    if ($type === 'Rating' || $type === 'Review' || strpos($message_l, 'rate') !== false || strpos($message_l, 'review') !== false) {
        return 'Review update';
    }

    // For payment / to-pay notifications, try to surface the service/product name
    $is_payment = (
        $type === 'Payment' ||
        strpos($message_l, 'proceed to payment') !== false ||
        strpos($message_l, 'ready for payment') !== false ||
        strpos($message_l, 'payment of') !== false
    );
    if ($is_payment) {
        $data_id = (int)($notification['data_id'] ?? 0);
        if ($data_id > 0) {
            $snapshot = printflow_notification_order_snapshot($data_id);
            $name = trim((string)($snapshot['service_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return 'Payment update';
    }

    if ($type === 'Design' || strpos($message_l, 'design') !== false || strpos($message_l, 'revision') !== false) {
        return 'Design update';
    }
    if ($type === 'Order' || $type === 'Job Order' || strpos($message_l, 'order') !== false) {
        // Also try to surface the service/product name for order status updates
        $data_id = (int)($notification['data_id'] ?? 0);
        if ($data_id > 0) {
            $snapshot = printflow_notification_order_snapshot($data_id);
            $name = trim((string)($snapshot['service_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return 'Order update';
    }
    if ($type === 'Status') {
        return 'Status update';
    }
    if ($type === 'System') {
        return 'Account update';
    }

    return 'Notification';
}

function customer_notification_target_url(array $notification) {
    $base = printflow_notification_base_path();
    $type = (string)($notification['type'] ?? '');
    $message = (string)($notification['message'] ?? '');
    $data_id = (int)($notification['data_id'] ?? 0);
    $message_l = strtolower($message);

    if (strpos($message_l, 'support chat') !== false || strpos($message_l, 'chatbot') !== false) {
        return $base . '/customer/notifications.php?chatbot=open';
    }

    if ($data_id > 0) {
        if ($type === 'Message' || strpos($message_l, 'message') !== false || strpos($message_l, 'chat') !== false) {
            return $base . '/customer/chat.php?order_id=' . $data_id;
        }
        if ($type === 'Rating' || $type === 'Review' || strpos($message_l, 'rate your') !== false || strpos($message_l, 'rate here') !== false) {
            return $base . '/customer/rate_order.php?order_id=' . $data_id;
        }
        // Payment-related: check live order status — if To Pay, send directly to payment page
        $is_payment_msg = (
            $type === 'Payment' ||
            strpos($message_l, 'proceed to payment') !== false ||
            strpos($message_l, 'payment of') !== false ||
            strpos($message_l, 'ready for payment') !== false
        );
        if ($is_payment_msg) {
            // Always redirect to payment page for these notifications
            return $base . '/customer/payment.php?order_id=' . $data_id;
        }
        // Generic pay/payment keyword — check live DB status before routing
        if (strpos($message_l, 'payment') !== false || strpos($message_l, 'pay') !== false) {
            $snap = printflow_notification_order_snapshot($data_id);
            if (in_array($snap['status'], ['To Pay', 'Approved'], true)) {
                return $base . '/customer/payment.php?order_id=' . $data_id;
            }
            return $base . '/customer/orders.php?highlight=' . $data_id;
        }
        if ($type === 'Design' || strpos($message_l, 'design') !== false || strpos($message_l, 'revision') !== false) {
            return $base . '/customer/chat.php?order_id=' . $data_id;
        }
        if ($type === 'Job Order') {
            return $base . '/customer/new_job_order.php';
        }
        return $base . '/customer/orders.php?highlight=' . $data_id;
    }

    if (preg_match('/order\s*#?(\d+)/i', $message, $m)) {
        return $base . '/customer/orders.php?highlight=' . (int)$m[1];
    }

    return $base . '/customer/notifications.php';
}

function customer_notification_image_url(array $notification, string $fallback) {
    $data_id = (int)($notification['data_id'] ?? 0);
    $type = strtolower((string)($notification['type'] ?? ''));
    if ($data_id <= 0) {
        return $fallback;
    }
    if ($type === 'job order') {
        $preview = printflow_job_notification_preview($data_id);
    } else {
        $preview = printflow_order_notification_preview($data_id);
    }
    return $preview['image_url'] ?: $fallback;
}


function printflow_push_title_for_notification(string $type, string $message, string $userType): string {
    $type = trim($type);
    $message = trim($message);
    $message_l = strtolower($message);

    if ($userType === 'Customer') {
        return customer_notification_title($type, $message);
    }

    if (strpos($message_l, 'support chat') !== false || strpos($message_l, 'chatbot') !== false) {
        return 'Support chat update';
    }

    if ($type !== '') {
        if (strcasecmp($type, 'Message') === 0) {
            return 'New message';
        }
        if (strcasecmp($type, 'Payment Issue') === 0) {
            return 'Payment issue';
        }
        return 'PrintFlow ' . $type;
    }

    return 'PrintFlow';
}

function printflow_push_media_payload(string $type, $data_id, string $message): array {
    $fallback = '';
    if (function_exists('push_logo_url')) {
        $fallback = (string)push_logo_url();
    }

    $data_id = (int)$data_id;
    $type_l = strtolower(trim($type));
    $message_l = strtolower($message);
    $order_types = ['order', 'new order', 'payment', 'payment issue', 'design', 'customization', 'message', 'chat', 'job order', 'rating', 'review'];

    if ($data_id > 0 && in_array($type_l, $order_types, true)) {
        $preview = printflow_order_notification_preview($data_id);
        $image = trim((string)($preview['image_url'] ?? ''));
        if ($image !== '') {
            return [
                'icon' => $image,
                'image' => $image,
            ];
        }
    }

    if ($data_id > 0 && $type_l === 'system' && (
        strpos($message_l, 'submitted an id for verification') !== false ||
        strpos($message_l, 'resubmitted an id for verification') !== false
    )) {
        $image = printflow_customer_id_notification_image_url($data_id, $fallback);
        if ($image !== '') {
            return [
                'icon' => $image,
                'image' => $image,
            ];
        }
    }

    return [
        'icon' => $fallback,
        'image' => '',
    ];
}

function printflow_customer_id_notification_image_url(int $customerId, string $fallback): string {
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        return $fallback;
    }

    $rows = db_query("SELECT id_image FROM customers WHERE customer_id = ? LIMIT 1", 'i', [$customerId]);
    $idImage = trim((string)($rows[0]['id_image'] ?? ''));
    if ($idImage === '') {
        return $fallback;
    }

    $base = printflow_notification_base_path();
    return $base . '/uploads/ids/' . rawurlencode($idImage);
}

function staff_admin_notification_image_url(array $notification, string $fallback): string {
    $data_id = (int)($notification['data_id'] ?? 0);
    $type = strtolower((string)($notification['type'] ?? ''));
    $message = strtolower((string)($notification['message'] ?? ''));
    if ($data_id <= 0) {
        return printflow_notification_normalize_media_url($fallback);
    }
    if ($type === 'system' && (
        strpos($message, 'submitted an id for verification') !== false ||
        strpos($message, 'resubmitted an id for verification') !== false
    )) {
        return printflow_customer_id_notification_image_url($data_id, $fallback);
    }
    if (!in_array($type, ['order', 'design', 'payment', 'payment issue', 'message', 'job order'], true)) {
        return printflow_notification_normalize_media_url($fallback);
    }
    if ($type === 'job order') {
        $preview = printflow_job_notification_preview($data_id);
    } else {
        $preview = printflow_order_notification_preview($data_id);
    }
    return printflow_notification_normalize_media_url($preview['image_url'] ?: $fallback);
}

function printflow_notification_normalize_media_url(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        $parts = parse_url($path);
        $host = strtolower((string)($parts['host'] ?? ''));
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && $currentHost !== '' && $host !== $currentHost) {
            return $path;
        }
        $path = (string)($parts['path'] ?? '');
    }

    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $path = preg_replace('#^[A-Za-z]:#', '', $path);
    }

    $publicPos = strpos($path, '/public/');
    $uploadsPos = strpos($path, '/uploads/');
    if ($publicPos !== false && ($uploadsPos === false || $publicPos <= $uploadsPos)) {
        $path = substr($path, $publicPos);
    } elseif ($uploadsPos !== false) {
        $path = substr($path, $uploadsPos);
    }

    $base = printflow_notification_base_path();
    if ($base === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }

    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    if ($base !== '' && strpos($path, $base . '/') !== 0 && strcasecmp($path, $base) !== 0) {
        $path = $base . $path;
    }

    return $path;
}

/**
 * Whether a path or URL points at video media (thumbnail URLs must skip these for <img>).
 */
function printflow_is_video_media_path(string $path): bool {
    $path = strtolower(str_replace('\\', '/', trim($path)));
    if ($path === '') {
        return false;
    }

    return (bool) preg_match('/\.(mp4|webm|mov|m4v)(?:[\?#].*)?$/', $path);
}

function printflow_notification_local_media_exists(string $urlPath): bool {
    $urlPath = trim($urlPath);
    if ($urlPath === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $urlPath)) {
        return true;
    }

    $clean = strtok($urlPath, '?#');
    if (!is_string($clean) || $clean === '') {
        return false;
    }

    $base = printflow_notification_base_path();
    if ($base !== '' && strpos($clean, $base . '/') === 0) {
        $clean = substr($clean, strlen($base));
    }
    if ($clean === false || $clean === '') {
        return false;
    }

    $clean = '/' . ltrim((string) $clean, '/');
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        return false;
    }

    // Same dual-path resolution as customer/services.php `pf_service_local_asset_exists`:
    // uploads may live under project root or under /public/.
    $diskCandidates = [$root . $clean];
    if (strpos($clean, '/uploads/') === 0 || strpos($clean, '/public/') === 0) {
        $suffix = strpos($clean, '/public/') === 0
            ? substr($clean, strlen('/public'))
            : $clean;
        $diskCandidates[] = $root . '/public' . $suffix;
    }

    foreach ($diskCandidates as $full) {
        $local = realpath($full);
        if ($local !== false && strpos($local, $root) === 0 && is_file($local)) {
            return true;
        }
    }

    return false;
}

/**
 * Build ordered image path candidates from a services table row (display_image may be comma-separated).
 * Order matches customer/services.php `pf_service_card_primary_image`: display CSV + hero, prefer still images over videos.
 *
 * @param array<string,mixed> $svcRow
 * @return list<string>
 */
function printflow_notification_service_image_candidates_from_service_row(array $svcRow): array {
    $ordered = [];
    $display = trim((string) ($svcRow['display_image'] ?? ''));
    if ($display !== '') {
        foreach (explode(',', $display) as $img) {
            $img = trim((string) $img);
            if ($img !== '') {
                $ordered[] = $img;
            }
        }
    }
    $hero = trim((string) ($svcRow['hero_image'] ?? ''));
    if ($hero !== '') {
        $ordered[] = $hero;
    }

    $still = [];
    $video = [];
    foreach ($ordered as $p) {
        if (printflow_is_video_media_path($p)) {
            $video[] = $p;
        } else {
            $still[] = $p;
        }
    }
    $candidates = array_merge($still, $video);

    $legacy = trim((string) ($svcRow['image_path'] ?? ''));
    if ($legacy !== '') {
        $candidates[] = $legacy;
    }

    return $candidates;
}

/**
 * Pick a usable URL for notifications/push: prefer verified local files or absolute URLs, otherwise first normalized path (browser may still resolve it).
 *
 * @param list<string> $candidates
 */
function printflow_notification_resolve_service_media_candidates(array $candidates): string {
    $normalized = [];
    foreach ($candidates as $candidate) {
        $url = printflow_notification_normalize_media_url($candidate);
        if ($url !== '') {
            $normalized[] = $url;
        }
    }
    if ($normalized === []) {
        return '';
    }
    $firstStill = '';
    foreach ($normalized as $url) {
        if (printflow_is_video_media_path($url)) {
            continue;
        }
        if ($firstStill === '') {
            $firstStill = $url;
        }
        if (preg_match('#^https?://#i', $url) || printflow_notification_local_media_exists($url)) {
            return $url;
        }
    }

    return $firstStill;
}

function printflow_notification_service_image_from_id(int $serviceId): string {
    $serviceId = (int)$serviceId;
    if ($serviceId <= 0) {
        return '';
    }

    // Use any non-archived row so staff notifications match catalog art even when a service is deactivated.
    $rows = db_query(
        "SELECT display_image, hero_image, image_path
         FROM services
         WHERE service_id = ?
           AND status <> 'Archived'
         LIMIT 1",
        'i',
        [$serviceId]
    ) ?: [];
    if (empty($rows)) {
        return '';
    }

    return printflow_notification_resolve_service_media_candidates(
        printflow_notification_service_image_candidates_from_service_row($rows[0])
    );
}

function printflow_notification_service_image_from_name(string $serviceName): string {
    $serviceName = trim($serviceName);
    if ($serviceName === '') {
        return '';
    }

    $aliases = function_exists('printflow_service_name_aliases')
        ? printflow_service_name_aliases($serviceName)
        : [$serviceName];
    $aliases = array_values(array_unique(array_filter(array_map(
        static fn($v) => trim((string)$v),
        $aliases
    ))));
    if (empty($aliases)) {
        return '';
    }

    $placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $rows = db_query(
        "SELECT display_image, hero_image, image_path
         FROM services
         WHERE status <> 'Archived'
           AND name IN ($placeholders)
         ORDER BY FIELD(name, $placeholders)
         LIMIT 1",
        str_repeat('s', count($aliases) * 2),
        array_merge($aliases, $aliases)
    ) ?: [];
    if (empty($rows)) {
        foreach ($aliases as $alias) {
            $alias = trim((string)$alias);
            if ($alias === '') {
                continue;
            }
            $try = db_query(
                "SELECT display_image, hero_image, image_path
                 FROM services
                 WHERE status <> 'Archived'
                   AND LOWER(TRIM(name)) = LOWER(?)
                 LIMIT 1",
                's',
                [$alias]
            ) ?: [];
            if (!empty($try)) {
                $rows = $try;
                break;
            }
        }
    }
    if (empty($rows)) {
        return '';
    }

    return printflow_notification_resolve_service_media_candidates(
        printflow_notification_service_image_candidates_from_service_row($rows[0])
    );
}


function printflow_notification_order_snapshot(int $order_id): array {
    static $cache = [];

    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return ['status' => '', 'order_type' => '', 'service_name' => '', 'total_amount' => 0.0];
    }
    if (isset($cache[$order_id])) {
        return $cache[$order_id];
    }

    $rows = db_query(
        "SELECT o.status, o.order_type, o.total_amount,
                COALESCE(
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.service_type')) FROM order_items oi WHERE oi.order_id = o.order_id AND JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.service_type')) IS NOT NULL LIMIT 1),
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.product_type')) FROM order_items oi WHERE oi.order_id = o.order_id AND JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.product_type')) IS NOT NULL LIMIT 1),
                    (SELECT p.name FROM order_items oi JOIN products p ON p.product_id = oi.product_id WHERE oi.order_id = o.order_id LIMIT 1)
                ) AS service_name
         FROM orders o
         WHERE o.order_id = ?
         LIMIT 1",
        'i',
        [$order_id]
    );

    $cache[$order_id] = [
        'status'       => trim((string)($rows[0]['status'] ?? '')),
        'order_type'   => strtolower(trim((string)($rows[0]['order_type'] ?? ''))),
        'service_name' => trim((string)($rows[0]['service_name'] ?? '')),
        'total_amount' => (float)($rows[0]['total_amount'] ?? 0),
    ];

    return $cache[$order_id];
}

function printflow_notification_customer_status(string $status, string $order_type): string {
    $status = trim($status);
    $order_type = strtolower(trim($order_type));

    if ($order_type === 'product' && in_array($status, ['Processing', 'In Production', 'Printing', 'Approved Design', 'Ready for Pickup'], true)) {
        return 'Ready for Pickup';
    }

    return $status;
}

function printflow_message_is_status_update(string $message): bool {
    $message = strtolower(trim($message));
    if ($message === '') {
        return false;
    }

    $markers = [
        'pending confirmation',
        'needs revision',
        'ready for payment',
        'being verified',
        'being processed',
        'in production',
        'ready for pickup',
        'to pickup',
        'status has been updated',
        'has been completed',
        'may now rate',
        'has been cancelled',
    ];

    foreach ($markers as $marker) {
        if (strpos($message, $marker) !== false) {
            return true;
        }
    }

    return false;
}

function printflow_notification_display_message(array $notification): string {
    $message = (string)($notification['message'] ?? '');
    $data_id = (int)($notification['data_id'] ?? 0);
    $type = strtolower((string)($notification['type'] ?? ''));

    if ($data_id > 0 && in_array($type, ['order', 'status'], true) && printflow_message_is_status_update($message)) {
        $snapshot = printflow_notification_order_snapshot($data_id);
        $current_status = printflow_notification_customer_status(
            (string)($snapshot['status'] ?? ''),
            (string)($snapshot['order_type'] ?? '')
        );
        if ($current_status !== '') {
            $payload = get_order_status_notification_payload($data_id, $current_status);
            if (!empty($payload['message'])) {
                return (string)$payload['message'];
            }
        }
    }

    if ($data_id > 0 && $type === 'payment' && preg_match('/^(.+?\b(?:re)?submitted payment for)\s+.*$/iu', $message, $pay)) {
        $jobPreview = printflow_job_notification_preview($data_id);
        $jn = trim((string)($jobPreview['display_name'] ?? ''));
        if ($jn !== '') {
            return rtrim($pay[1]) . ' ' . $jn;
        }
    }

    if ($data_id > 0 && $type === 'order') {
        $preview = printflow_order_notification_preview($data_id);
        $dn = trim((string)($preview['display_name'] ?? ''));
        if ($dn !== '') {
            if (preg_match('/^(.+?\bsent an inquiry for)\s+.*$/iu', $message, $inq)) {
                return rtrim($inq[1]) . ' ' . $dn;
            }
            if (preg_match('/^(.+?\b(?:re)?submitted payment for)\s+.*$/iu', $message, $pay)) {
                return rtrim($pay[1]) . ' ' . $dn;
            }

            $replacements = [
                '/placed an order for .+$/i' => 'placed an order for ' . $dn,
                '/order for .+? placed successfully!?$/i' => 'Order for ' . $dn . ' placed successfully!',
                '/resubmitted a revised design for .+$/i' => 'resubmitted a revised design for ' . $dn,
            ];

            foreach ($replacements as $pattern => $replacement) {
                if (preg_match($pattern, $message)) {
                    return preg_replace($pattern, $replacement, $message) ?: $message;
                }
            }
        }
    }

    return $message;
}

function printflow_staff_order_management_url(int $orderId, bool $preferPendingStatus = false): string {
    $base = printflow_notification_base_path();
    $orderId = (int)$orderId;
    if ($orderId <= 0) {
        return $base . '/staff/orders.php';
    }

    // Try to fetch order_type and order_source if they exist
    $orderType = 'product';
    $orderSource = 'customer';
    
    // Check columns first to avoid query failure if table hasn't been updated
    $hasTypeCol = db_table_has_column('orders', 'order_type');
    $hasSourceCol = db_table_has_column('orders', 'order_source');
    
    $cols = [];
    if ($hasTypeCol) $cols[] = 'order_type';
    if ($hasSourceCol) $cols[] = 'order_source';
    
    if (!empty($cols)) {
        $sql = "SELECT " . implode(', ', $cols) . " FROM orders WHERE order_id = ? LIMIT 1";
        $orderRows = db_query($sql, 'i', [$orderId]);
        if (!empty($orderRows)) {
            if ($hasTypeCol) $orderType = strtolower(trim((string)($orderRows[0]['order_type'] ?? 'product')));
            if ($hasSourceCol) $orderSource = strtolower(trim((string)($orderRows[0]['order_source'] ?? 'customer')));
        }
    }

    $isCustom = ($orderType === 'custom');

    if (!$isCustom) {
        $jobRows = db_query(
            "SELECT id FROM job_orders WHERE order_id = ? LIMIT 1",
            'i',
            [$orderId]
        );
        if (!empty($jobRows)) {
            $isCustom = true;
        }
    }

    if (!$isCustom) {
        $preview = printflow_order_notification_preview($orderId);
        $itemKind = strtolower(trim((string)($preview['item_kind'] ?? '')));
        if ($itemKind === 'service') {
            $isCustom = true;
        }
    }

    if ($isCustom) {
        $url = $base . '/staff/customizations.php?order_id=' . $orderId . '&job_type=ORDER';
        // For custom orders, we often want to default to PENDING status if they haven't been reviewed
        if ($preferPendingStatus && $orderSource !== 'pos' && $orderSource !== 'walk-in') {
            $url .= '&status=PENDING';
        }
        return $url;
    }

    return $base . '/staff/orders.php?order_id=' . $orderId;
}

function printflow_notification_item_kind(array $notification): string {
    $data_id = (int)($notification['data_id'] ?? 0);
    if ($data_id <= 0) {
        return '';
    }

    $type = strtolower(trim((string)($notification['type'] ?? '')));
    $message = strtolower(trim((string)($notification['message'] ?? '')));
    $order_linked_types = ['order', 'job order', 'payment', 'payment issue', 'design', 'message', 'status'];
    $is_order_linked = in_array($type, $order_linked_types, true)
        || str_contains($message, 'order')
        || str_contains($message, 'design')
        || str_contains($message, 'payment');

    if (!$is_order_linked) {
        return '';
    }

    if ($type === 'job order') {
        return 'Service';
    }

    $preview = printflow_order_notification_preview($data_id);
    $kind = trim((string)($preview['item_kind'] ?? ''));
    if ($kind === 'Product' || $kind === 'Service') {
        return $kind;
    }

    if (str_contains($message, 'product order')) {
        return 'Product';
    }

    $snapshot = printflow_notification_order_snapshot($data_id);
    $orderType = strtolower(trim((string)($snapshot['order_type'] ?? '')));
    if ($orderType === 'product') {
        return 'Product';
    }
    if ($orderType === 'custom') {
        return 'Service';
    }

    return '';
}

/**
 * Resolve display name, image, and kind for a JOB notification.
 */
function printflow_job_notification_preview(int $job_id): array {
    static $cache = [];
    $job_id = (int)$job_id;
    if ($job_id <= 0) {
        return ['display_name' => '', 'image_url' => '', 'item_kind' => 'Job'];
    }
    if (isset($cache[$job_id])) return $cache[$job_id];

    $base = printflow_notification_base_path();
    $row = db_query("SELECT id, job_title, service_type, artwork_path FROM job_orders WHERE id = ? LIMIT 1", 'i', [$job_id]);
    
    $preview = ['display_name' => '', 'image_url' => '', 'item_kind' => 'Service'];
    if (empty($row)) {
        $cache[$job_id] = $preview;
        return $preview;
    }
    $r = $row[0];
    $preview['display_name'] = trim((string)($r['job_title'] ?? $r['service_type'] ?? 'Job Order'));
    
    // Artwork path resolution
    $artwork = trim((string)($r['artwork_path'] ?? ''));
    if ($artwork !== '') {
        if (preg_match('#^https?://#i', $artwork) || $artwork[0] === '/') {
            $preview['image_url'] = $artwork;
        } else {
            // Check if path exists as is
            if (file_exists(__DIR__ . '/../' . $artwork)) {
                $preview['image_url'] = $base . '/' . ltrim($artwork, '/');
            } elseif (file_exists(__DIR__ . '/../uploads/artwork/' . $artwork)) {
                $preview['image_url'] = $base . '/uploads/artwork/' . $artwork;
            } elseif (file_exists(__DIR__ . '/../public/serve_design.php')) {
                $preview['image_url'] = $base . '/public/serve_design.php?type=job_order&id=' . $job_id;
            }
        }
    }


    if ($preview['image_url'] === '') {
        $preview['image_url'] = get_service_image_url($r['service_type'] ?: $preview['display_name']);
    }

    $cache[$job_id] = $preview;
    return $preview;
}

function printflow_is_pos_service_placeholder_row(array $row): bool {
    $sku = strtoupper(trim((string)($row['product_sku'] ?? '')));
    if ($sku !== '' && (strpos($sku, 'POS-SERVICE') !== false || strpos($sku, 'POS_SERVICE') !== false)) {
        return true;
    }
    $name = strtolower(trim((string)($row['product_name'] ?? '')));
    return $name === 'pos service item' || strpos($name, 'pos service') !== false;
}

function printflow_known_service_labels(): array {
    return [
        'Tarpaulin Printing',
        'T-Shirt Printing',
        'T-Shirt Printing (Vinyl)',
        'Decals/Stickers (Print/Cut)',
        'Sintraboard Standees',
        'Glass/Wall Stickers',
        'Transparent Stickers',
        'Reflectorized',
        'Souvenirs',
        'Layouts',
    ];
}

function printflow_order_item_has_service_marker(array $custom): bool {
    if (empty($custom)) {
        return false;
    }

    $serviceType = trim((string)($custom['service_type'] ?? ''));
    if ($serviceType !== '') {
        $blocked = [
            'service',
            'service order',
            'service item',
            'product order',
            'order item',
            'custom order',
            'custom service',
            'pos service item',
            'pos-service',
            'pos_service',
            'pos',
        ];
        $normalizedRaw = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $serviceType)));
        if (!in_array($normalizedRaw, $blocked, true)) {
            return true;
        }
    }

    if ((int)($custom['service_id'] ?? 0) > 0) {
        return true;
    }

    $source = strtolower(trim((string)($custom['source'] ?? '')));
    if ($source === 'service' || $source === 'services') {
        return true;
    }

    $productType = trim((string)($custom['product_type'] ?? ''));
    $sourcePage = strtolower(trim((string)($custom['source_page'] ?? '')));
    $fromCatalog = in_array($sourcePage, ['products', 'product', 'dynamic_form'], true);
    if (!$fromCatalog && $productType !== '') {
        $normalizedProductType = normalize_service_name($productType, '');
        if (in_array($normalizedProductType, printflow_known_service_labels(), true)) {
            return true;
        }
    }

    // Service-specific field signatures commonly present in customization payloads.
    $serviceMarkers = [
        'sintra_type',
        'tarp_size',
        'vinyl_type',
        'sticker_type',
        'cut_type',
        'with_eyelets',
        'temp_plate_number',
        'gate_pass_number',
        'reflective_color',
        'installation_fee',
    ];
    foreach ($serviceMarkers as $marker) {
        if (!empty($custom[$marker])) {
            return true;
        }
    }

    return false;
}

function customer_orders_decode_customization_payload($raw): array {
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $trimmed = trim($raw);
    $entityFlags = ENT_QUOTES | (defined('ENT_HTML5') ? ENT_HTML5 : 0);
    $candidates = [];
    $pushCandidate = static function ($value) use (&$candidates): void {
        if (!is_string($value)) {
            return;
        }
        $value = trim($value);
        if ($value === '' || in_array($value, $candidates, true)) {
            return;
        }
        $candidates[] = $value;
    };

    $pushCandidate($trimmed);
    $entityDecoded = html_entity_decode($trimmed, $entityFlags, 'UTF-8');
    $pushCandidate($entityDecoded);
    $pushCandidate(stripslashes($trimmed));
    $pushCandidate(stripslashes($entityDecoded));

    foreach ($candidates as $candidate) {
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (is_string($decoded) && trim($decoded) !== '' && trim($decoded) !== $candidate) {
            $decoded = customer_orders_decode_customization_payload($decoded);
            if ($decoded !== []) {
                return $decoded;
            }
        }
    }

    return [];
}

function printflow_encode_customization_payload(array $payload): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
        $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
    }

    $json = json_encode($payload, $flags);
    if ($json === false) {
        error_log('Failed to encode customization payload JSON: ' . json_last_error_msg());
        return '{}';
    }

    return $json;
}

function printflow_decode_modal_customization_payload($raw): array {
    return printflow_normalize_customization_for_modal(
        customer_orders_decode_customization_payload($raw)
    );
}

function customer_orders_is_generic_item_name(string $name): bool {
    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
    if ($normalized === '') {
        return true;
    }
    $generic = [
        'order item',
        'service order',
        'service item',
        'custom order',
        'customer order',
        'merchandise',
        'sticker pack',
        'pos service item',
        'pos-service item',
        'pos service',
        'pos-service',
    ];
    return in_array($normalized, $generic, true);
}

function customer_orders_resolve_service_name_by_id(int $service_id): string {
    static $cache = [];
    if ($service_id <= 0) {
        return '';
    }
    if (array_key_exists($service_id, $cache)) {
        return $cache[$service_id];
    }
    $rows = db_query('SELECT name FROM services WHERE service_id = ? LIMIT 1', 'i', [$service_id]);
    return $cache[$service_id] = trim((string)($rows[0]['name'] ?? ''));
}

function customer_orders_primary_customization(array $order): array {
    $itemCustom = customer_orders_decode_customization_payload((string)($order['first_item_customization'] ?? ''));
    $tableCustom = customer_orders_decode_customization_payload((string)($order['first_customization_details'] ?? ''));

    $merged = printflow_overlay_nonempty_assoc($tableCustom, $itemCustom);

    $serviceType = trim((string)($order['first_customization_service_type'] ?? ''));
    if ($serviceType !== '' && empty($merged['service_type'])) {
        $merged['service_type'] = $serviceType;
    }

    $sid = (int)($merged['service_id'] ?? 0);
    if ($sid <= 0) {
        $sid = (int)($itemCustom['service_id'] ?? $tableCustom['service_id'] ?? 0);
        if ($sid > 0) {
            $merged['service_id'] = $sid;
        }
    }

    if ($sid > 0) {
        $byId = customer_orders_resolve_service_name_by_id($sid);
        if ($byId !== '') {
            $merged['service_type'] = $byId;
        }
    }

    $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
    if ($orderType === 'custom' && $sid <= 0) {
        $ref = (int)($order['reference_id'] ?? 0);
        if ($ref > 0) {
            $byRef = customer_orders_resolve_service_name_by_id($ref);
            if ($byRef !== '') {
                $merged['service_type'] = $byRef;
                if ((int)($merged['service_id'] ?? 0) <= 0) {
                    $merged['service_id'] = $ref;
                }
            }
        }
    }

    return $merged;
}

/**
 * Legacy souvenir cart / order_souvenirs lines use souvenir_type (and service_type "Souvenirs").
 * Checkout stores placeholder product_id → JOIN products.name can be unrelated (e.g. "Mugs") and must not drive catalog resolution.
 */
function printflow_custom_order_line_looks_like_souvenir_cart(array $custom_data): bool {
    if (!empty($custom_data['souvenir_type'])) {
        return true;
    }

    return strcasecmp(trim((string)($custom_data['service_type'] ?? '')), 'Souvenirs') === 0;
}

/**
 * Strip placeholder service_type values (e.g. from products.name or legacy rows) so
 * orders.reference_id and field-based inference can supply the real catalog name.
 *
 * @param array<string, mixed> $custom
 * @return array<string, mixed>
 */
function customer_orders_sanitize_generic_service_labels(array $custom): array {
    $st = trim((string)($custom['service_type'] ?? ''));
    if ($st !== '' && customer_orders_is_generic_item_name($st)) {
        unset($custom['service_type']);
    }
    return $custom;
}

/**
 * Apply service_id / orders.reference_id resolution to an already-merged per-line customization array.
 */
function customer_orders_enrich_line_customization(array $merged, array $order): array {
    if (printflow_custom_order_line_looks_like_souvenir_cart($merged)) {
        $sidSouv = printflow_resolve_service_catalog_service_id('Souvenirs');
        if ($sidSouv > 0) {
            $merged['service_id'] = $sidSouv;
        }
        $merged['service_type'] = 'Souvenirs';

        return $merged;
    }

    $merged = customer_orders_sanitize_generic_service_labels($merged);

    $sid = (int)($merged['service_id'] ?? 0);
    if ($sid > 0) {
        $byId = customer_orders_resolve_service_name_by_id($sid);
        if ($byId !== '') {
            $merged['service_type'] = $byId;
        }
    }

    // Service checkout often stores orders.order_type as "product" (SKU line) while orders.reference_id
    // points at services.service_id — same as custom orders.
    $ref = (int)($order['reference_id'] ?? 0);
    if ($ref > 0 && trim((string)($merged['service_type'] ?? '')) === '') {
        $byRef = customer_orders_resolve_service_name_by_id($ref);
        if ($byRef !== '') {
            $merged['service_type'] = $byRef;
            if ((int)($merged['service_id'] ?? 0) <= 0) {
                $merged['service_id'] = $ref;
            }
        }
    }
    return $merged;
}

/**
 * Add service-config display labels to legacy/customization payloads so older hardcoded service pages
 * behave more like dynamic service orders in staff/customer detail modals.
 *
 * @param array<string,mixed> $custom
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function printflow_apply_service_field_config_display_labels(array $custom, int $serviceId, array $context = []): array {
    $serviceId = (int)$serviceId;
    if ($serviceId <= 0) {
        return $custom;
    }

    require_once __DIR__ . '/service_field_config_helper.php';
    if (!function_exists('get_service_field_config')) {
        return $custom;
    }

    $configs = get_service_field_config($serviceId);
    if (!is_array($configs) || $configs === []) {
        return $custom;
    }

    $normalize = static function (string $value): string {
        return strtolower(preg_replace('/[\s_\-]+/', '', trim($value)));
    };

    $firstValue = static function (array $source, array $aliases) use ($normalize) {
        $wanted = [];
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $alias = trim($alias);
            if ($alias === '') {
                continue;
            }
            $wanted[$normalize($alias)] = true;
        }

        foreach ($source as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            if (!isset($wanted[$normalize((string)$key)])) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if (is_array($value) && $value === []) {
                continue;
            }
            return $value;
        }

        return null;
    };

    $stringify = static function ($value): string {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return function_exists('pf_order_ui_value_to_text')
                ? pf_order_ui_value_to_text($value)
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return trim((string)$value);
    };

    $formatDimension = static function (array $source, array $aliases, array $context) use ($firstValue, $stringify): string {
        $direct = $firstValue($source, $aliases);
        $directText = $stringify($direct);
        if ($directText !== '') {
            return $directText;
        }

        $width = $stringify($firstValue($source, ['width', 'Width', 'width_ft', 'Width_Ft']));
        $height = $stringify($firstValue($source, ['height', 'Height', 'height_ft', 'Height_Ft']));
        $unit = trim((string)($firstValue($source, ['unit', 'Unit']) ?? ($context['dimension_unit'] ?? '')));
        if ($width !== '' && $height !== '') {
            $text = $width . ' x ' . $height;
            if ($unit !== '') {
                $text .= ' ' . $unit;
            }
            return $text;
        }

        return '';
    };

    $aliasMap = [
        'dimensions' => ['dimensions', 'dimension', 'size', 'sizes', 'exact_size', 'exact size', 'poster size', 'print size', 'tarp size', 'Dimensions', 'Size', 'Sizes'],
        'size' => ['size', 'sizes', 'dimensions', 'dimension', 'Size', 'Sizes', 'Dimensions'],
        'sizes' => ['sizes', 'size', 'dimensions', 'dimension', 'Sizes', 'Size', 'Dimensions'],
        'lamination' => ['lamination', 'laminate_option', 'Lamination', 'Laminate', 'Lamination Option'],
        'eyelets' => ['eyelets', 'with_eyelets', 'Eyelets'],
        'design_file' => ['design_file', 'design_upload', 'design_upload_path', 'upload_design', 'upload design', 'Design', 'Upload Design'],
        'reference_file' => ['reference_file', 'reference_upload', 'reference_upload_path', 'upload_reference', 'reference upload', 'Reference', 'Reference Upload'],
        'shirt_color' => ['shirt_color', 'tshirt_color', 'color', 'Color'],
        'size_ft' => ['size_ft', 'dimensions', 'size'],
        'type' => ['type', 'sintra_type', 'sticker_type', 'poster_type', 'Type'],
    ];

    $designName = trim((string)($context['design_name'] ?? ''));
    if ($designName === '') {
        $designCandidate = $firstValue($custom, $aliasMap['design_file']);
        $designName = basename($stringify($designCandidate));
    }
    $referenceName = trim((string)($context['reference_name'] ?? ''));
    if ($referenceName === '') {
        $referenceCandidate = $firstValue($custom, $aliasMap['reference_file']);
        $referenceName = basename($stringify($referenceCandidate));
    }

    foreach ($configs as $fieldKey => $cfg) {
        if (!is_array($cfg) || empty($cfg['visible'])) {
            continue;
        }

        $label = trim((string)($cfg['label'] ?? ''));
        if ($label === '') {
            $label = trim((string)$fieldKey);
        }
        if ($label === '') {
            continue;
        }

        $existingLabelValue = $firstValue($custom, [$label]);
        if ($stringify($existingLabelValue) !== '') {
            continue;
        }

        $aliases = $aliasMap[$fieldKey] ?? [$fieldKey, $label, str_replace(' ', '_', $label)];
        $resolved = '';
        $fieldType = strtolower(trim((string)($cfg['type'] ?? '')));

        if ($fieldKey === 'branch') {
            $resolved = trim((string)($context['branch_name'] ?? ''));
        } elseif ($fieldType === 'quantity') {
            $qty = (int)($context['quantity'] ?? 0);
            $resolved = $qty > 0 ? (string)$qty : '';
        } elseif ($fieldType === 'file') {
            $isReference = str_contains($normalize((string)$fieldKey), 'reference') || str_contains($normalize($label), 'reference');
            $resolved = $isReference ? $referenceName : $designName;
        } elseif ($fieldType === 'dimension') {
            $resolved = $formatDimension($custom, $aliases, $context);
        } else {
            $resolved = $stringify($firstValue($custom, $aliases));
        }

        if ($resolved !== '') {
            $custom[$label] = $resolved;
        }
    }

    return $custom;
}

/**
 * Fill gaps in per-line customization from a job_orders row (staff POS / job workflow).
 * Does not overwrite keys already present in $custom.
 */
function customer_orders_merge_job_order_row_into_customization(array $custom, ?array $jobRow): array {
    if ($jobRow === null || $jobRow === []) {
        return $custom;
    }

    // Treat numeric strings ("0", "0.00") as numbers so job_orders placeholders do not overwrite real line specs
    // or inject meaningless ft/sqft rows for inch-only services (e.g. posters).
    $nonEmpty = static function ($v): bool {
        if ($v === null) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') {
                return false;
            }
            if (is_numeric($t)) {
                return abs((float)$t) >= 1e-8;
            }
            return true;
        }
        if (is_numeric($v)) {
            return abs((float)$v) >= 1e-8;
        }
        return !empty($v);
    };

    $setIfMissing = static function (array &$target, string $key, $val) use ($nonEmpty): void {
        if (!$nonEmpty($val)) {
            return;
        }
        if (!array_key_exists($key, $target)) {
            $target[$key] = $val;
            return;
        }
        $cur = $target[$key];
        if ($cur === null || $cur === '' || !$nonEmpty($cur)) {
            $target[$key] = $val;
        }
    };

    $wt = $jobRow['width_ft'] ?? null;
    $ht = $jobRow['height_ft'] ?? null;
    if ($nonEmpty($wt) && $nonEmpty($ht)) {
        $combo = trim((string)$wt) . ' × ' . trim((string)$ht) . ' ft';
        $setIfMissing($custom, 'dimensions_ft', $combo);
    }
    $setIfMissing($custom, 'width_ft', $wt);
    $setIfMissing($custom, 'height_ft', $ht);

    $setIfMissing($custom, 'total_sqft', $jobRow['total_sqft'] ?? null);

    $jn = $jobRow['notes'] ?? '';
    if (is_string($jn)) {
        $jn = trim($jn);
    }
    if ($jn !== '') {
        $setIfMissing($custom, 'job_notes', $jn);
    }

    $jst = isset($jobRow['service_type']) ? trim((string)$jobRow['service_type']) : '';
    $lineServiceId = (int)($custom['service_id'] ?? 0);
    if ($lineServiceId <= 0 && $jst !== '') {
        $setIfMissing($custom, 'service_type', $jst);
    }

    $jt = isset($jobRow['job_title']) ? trim((string)$jobRow['job_title']) : '';
    if ($lineServiceId <= 0 && $jt !== '' && (empty($custom['service_type']) || customer_orders_is_generic_item_name((string)$custom['service_type']))) {
        $setIfMissing($custom, 'service_type', $jt);
    }

    if (!empty($jobRow['due_date'])) {
        $setIfMissing($custom, 'due_date', $jobRow['due_date']);
    }
    if (!empty($jobRow['branch_name'])) {
        $setIfMissing($custom, 'branch_name', $jobRow['branch_name']);
    }

    return $custom;
}

/**
 * Resolve services.service_id from a display/cart service name (legacy forms omit service_id).
 * Uses normalize_service_name() when available so shorthand labels still match the catalog.
 */
function printflow_resolve_service_catalog_service_id(?string $serviceName): int {
    $serviceName = trim((string)$serviceName);
    if ($serviceName === '' || !function_exists('db_query')) {
        return 0;
    }

    $candidates = [$serviceName];
    if (function_exists('normalize_service_name')) {
        $norm = normalize_service_name($serviceName, '');
        if (is_string($norm) && trim($norm) !== '' && strcasecmp(trim($norm), $serviceName) !== 0) {
            $candidates[] = trim($norm);
        }
    }

    foreach (array_unique($candidates) as $cand) {
        $cand = trim((string)$cand);
        if ($cand === '') {
            continue;
        }
        $rows = db_query(
            "SELECT service_id FROM services WHERE LOWER(TRIM(COALESCE(name,''))) = LOWER(?)
             AND LOWER(TRIM(COALESCE(status,''))) <> 'archived'
             ORDER BY service_id ASC LIMIT 2",
            's',
            [$cand]
        );
        if (is_array($rows) && count($rows) === 1) {
            return (int)($rows[0]['service_id'] ?? 0);
        }
    }

    // Last resort: unique substring relationship between hint and catalog name (Plates-style rows often match exactly;
    // legacy flows store abbreviated service_type / job titles that never hit equality above).
    $haystack = strtolower(preg_replace('/\s+/', ' ', $serviceName));
    $haystack = trim($haystack);
    if ($haystack !== '' && strlen($haystack) >= 5 && function_exists('db_query')) {
        $hits = db_query(
            'SELECT service_id FROM services
             WHERE LOWER(TRIM(COALESCE(status, \'\'))) <> \'archived\'
               AND (
                    (? LIKE CONCAT(\'%\', LOWER(TRIM(name)), \'%\') AND CHAR_LENGTH(TRIM(name)) >= 6)
                 OR (CHAR_LENGTH(?) >= 8 AND LOWER(TRIM(name)) LIKE CONCAT(\'%\', ?, \'%\'))
               )
             ORDER BY CHAR_LENGTH(TRIM(name)) ASC, service_id ASC',
            'sss',
            [$haystack, $haystack, $haystack]
        );
        if (is_array($hits) && count($hits) === 1) {
            return (int)($hits[0]['service_id'] ?? 0);
        }
    }

    return 0;
}

/**
 * Resolve catalog service_id for an existing order line (modal / history). Mirrors Plates-style reliability when
 * JSON carries service_id; fills gaps from orders.reference_id, product label, or job metadata.
 */
function printflow_resolve_service_catalog_service_id_for_order_line(array $custom_data, array $order, array $item): int {
    $validateServicePk = static function (int $sid): int {
        if ($sid <= 0 || !function_exists('db_query')) {
            return 0;
        }
        $hit = db_query(
            "SELECT service_id FROM services WHERE service_id = ? AND LOWER(TRIM(COALESCE(status,''))) <> 'archived' LIMIT 1",
            'i',
            [$sid]
        );

        return !empty($hit) ? $sid : 0;
    };

    $try = $validateServicePk((int)($custom_data['service_id'] ?? 0));
    if ($try > 0) {
        return $try;
    }

    $try = $validateServicePk((int)($order['reference_id'] ?? 0));
    if ($try > 0) {
        return $try;
    }

    $souvenirLine = printflow_custom_order_line_looks_like_souvenir_cart($custom_data);
    $hints = [];
    if ($souvenirLine) {
        $hints[] = 'Souvenirs';
    }
    $hints[] = trim((string)($custom_data['service_type'] ?? ''));
    $hints[] = trim((string)($order['first_job_title'] ?? ''));
    $hints[] = trim((string)($order['first_job_service_type'] ?? ''));
    if (!$souvenirLine) {
        $hints[] = trim((string)($item['product_name'] ?? ''));
    }

    foreach ($hints as $hint) {
        if ($hint === '') {
            continue;
        }
        $rid = printflow_resolve_service_catalog_service_id($hint);
        if ($rid > 0) {
            return $rid;
        }
        if (function_exists('normalize_service_name')) {
            $norm = normalize_service_name($hint, '');
            if (is_string($norm) && trim($norm) !== '' && strcasecmp(trim($norm), $hint) !== 0) {
                $rid = printflow_resolve_service_catalog_service_id(trim($norm));
                if ($rid > 0) {
                    return $rid;
                }
            }
        }
    }

    if ($souvenirLine) {
        return printflow_resolve_service_catalog_service_id('Souvenirs');
    }

    return 0;
}

/**
 * Prefer explicit IDs on the cart line/customization; otherwise resolve catalog service_id from names (legacy flows).
 */
function printflow_resolve_service_catalog_service_id_from_cart_line(array $item): int {
    $custom = $item['customization'] ?? [];
    if (is_string($custom)) {
        $decoded = json_decode($custom, true);
        $custom = is_array($decoded) ? $decoded : [];
    }

    $candidates = [
        (int)($custom['service_id'] ?? 0),
        (int)($item['service_id'] ?? 0),
    ];
    if (!printflow_custom_order_line_looks_like_souvenir_cart($custom)) {
        $candidates[] = (int)($item['product_id'] ?? 0);
    }
    foreach ($candidates as $cand) {
        if ($cand > 0) {
            return $cand;
        }
    }

    return printflow_resolve_service_catalog_service_id((string)($custom['service_type'] ?? $item['name'] ?? ''));
}

/**
 * Merge cart/session `dynamic_form_data` into the customization array before persisting to order_items.
 * Dynamic catalog items store answers alongside `customization`; without this merge only metadata (form_type, config_id, …) is saved.
 */
function printflow_merge_dynamic_form_data_into_customization(array $custom, array $item): array {
    $dfd = $item['dynamic_form_data'] ?? null;
    if (!is_array($dfd) || $dfd === []) {
        return $custom;
    }
    $clean = [];
    foreach ($dfd as $key => $value) {
        if (!is_string($key) && !is_int($key)) {
            continue;
        }
        $keyStr = trim((string)$key);
        if ($keyStr === '') {
            continue;
        }
        if (is_array($value) && isset($value['type']) && $value['type'] === 'file') {
            continue;
        }
        $clean[$keyStr] = $value;
    }
    if ($clean === []) {
        return $custom;
    }

    return printflow_overlay_nonempty_assoc($clean, $custom);
}

function customer_orders_custom_order_is_catalog_product(array $custom): bool {
    $src = strtolower(trim((string)($custom['source_page'] ?? '')));
    if (in_array($src, ['products', 'dynamic_form', 'product'], true)) {
        return true;
    }
    if (isset($custom['form_type']) && strtolower(trim((string)$custom['form_type'])) === 'dynamic') {
        return true;
    }
    if (!empty($custom['config_id'])) {
        return true;
    }
    return false;
}

function customer_orders_primary_item_name(array $order): string {
    $custom = isset($order['_merged_customization']) && is_array($order['_merged_customization'])
        ? customer_orders_enrich_line_customization($order['_merged_customization'], $order)
        : customer_orders_primary_customization($order);

    $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
    $serviceFallback = trim((string)($order['first_customization_service_type'] ?? ($custom['service_type'] ?? '')));
    $rawName = trim((string)($order['first_product_name'] ?? ''));

    // Custom orders may use a placeholder/unrelated product_id; do not trust products.name for the label.
    $serviceLikeCheckout = in_array($orderType, ['custom', 'product'], true) && !customer_orders_custom_order_is_catalog_product($custom);
    if ($serviceLikeCheckout && ($orderType === 'custom' || customer_orders_is_generic_item_name($rawName))) {
        $rawName = '';
    }

    if ($rawName === '' || customer_orders_is_generic_item_name($rawName)) {
        $rawName = $serviceFallback !== '' ? $serviceFallback : $rawName;
    }

    $useJobTitleFallback = !array_key_exists('_use_job_title_fallback', $order) || $order['_use_job_title_fallback'];

    if (in_array($orderType, ['custom', 'product'], true)) {
        $svcFromCustom = trim((string)($custom['service_type'] ?? ''));
        if ($svcFromCustom !== '' && !customer_orders_is_generic_item_name($svcFromCustom)) {
            return normalize_service_name($svcFromCustom, $svcFromCustom);
        }
        $ptFromCustom = trim((string)($custom['product_type'] ?? ''));
        if ($ptFromCustom !== '' && !customer_orders_is_generic_item_name($ptFromCustom)) {
            return normalize_service_name($ptFromCustom, $ptFromCustom);
        }
        $jobTitle = trim((string)($order['first_job_title'] ?? ''));
        if ($useJobTitleFallback && $jobTitle !== '' && !customer_orders_is_generic_item_name($jobTitle)) {
            return normalize_service_name($jobTitle, $jobTitle);
        }
        $fromFields = trim((string)get_service_name_from_customization($custom, ''));
        if ($fromFields !== '' && !customer_orders_is_generic_item_name($fromFields)) {
            $resolvedEarly = printflow_resolve_order_item_name($fromFields, $custom, $fromFields);
            if (!customer_orders_is_generic_item_name($resolvedEarly)) {
                return $resolvedEarly;
            }
            return normalize_service_name($fromFields, $fromFields);
        }
    }

    $fallback = $serviceFallback !== '' ? $serviceFallback : 'Order Item';
    $resolved = printflow_resolve_order_item_name($rawName !== '' ? $rawName : $fallback, $custom, $fallback);

    if (customer_orders_is_generic_item_name($resolved) && $serviceFallback !== '') {
        return normalize_service_name($serviceFallback, $serviceFallback);
    }
    if (customer_orders_is_generic_item_name($resolved)) {
        if (in_array($orderType, ['custom', 'product'], true)) {
            $ref = (int)($order['reference_id'] ?? 0);
            if ($ref > 0) {
                $nm = customer_orders_resolve_service_name_by_id($ref);
                if ($nm !== '') {
                    return normalize_service_name($nm, $nm);
                }
            }
            $jst = trim((string)($order['first_job_service_type'] ?? ''));
            if ($useJobTitleFallback && $jst !== '' && !customer_orders_is_generic_item_name($jst)) {
                return normalize_service_name($jst, $jst);
            }
        }
        $fromFields = trim((string)get_service_name_from_customization($custom, ''));
        if ($fromFields !== '' && !customer_orders_is_generic_item_name($fromFields)) {
            return normalize_service_name($fromFields, $fromFields);
        }
    }
    return $resolved;
}

/**
 * True when $arr is a non-empty list with keys 0..count-1 (JSON array).
 */
function printflow_is_sequential_list_array(array $arr): bool {
    if ($arr === []) {
        return false;
    }
    $i = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

/**
 * Merge associative customization payloads without letting empty overlay values wipe existing base values.
 * (PHP array_replace overwrites even when the new value is "", which drops specs when multiple customizations rows or wrappers are sparse.)
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $overlay
 * @return array<string, mixed>
 */
function printflow_overlay_nonempty_assoc(array $base, array $overlay): array {
    $out = $base;
    foreach ($overlay as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (is_string($v) && trim($v) === '') {
            continue;
        }
        if (is_array($v) && $v === []) {
            continue;
        }
        $out[$k] = $v;
    }

    return $out;
}

/**
 * Unwrap nested containers and list-shaped payloads so modal specs are not dropped (numeric PHP keys are skipped by flatten).
 */
function printflow_normalize_customization_for_modal(array $custom, int $depth = 0): array {
    if ($custom === [] || $depth > 8) {
        return $custom;
    }

    foreach (
        [
            'customization',
            'customization_details',
            'specs',
            'specifications',
            'order_info',
            'order_information',
            'form_data',
            'form_values',
            'fields',
            'responses',
            'answers',
            'data',
            'details',
            'values',
            'payload',
            'order_spec',
            'order_specification',
            'order_specifications',
            'dynamic_form_data',
            'service_specs',
            'item_specs',
            'order_customization',
            'cart_customization',
            'saved_specs',
            'selected_options',
            'attributes',
        ]
        as $wrap
    ) {
        if (!isset($custom[$wrap]) || !is_array($custom[$wrap])) {
            continue;
        }
        $inner = $custom[$wrap];
        unset($custom[$wrap]);
        $innerNorm = printflow_normalize_customization_for_modal($inner, $depth + 1);
        $custom = printflow_overlay_nonempty_assoc($innerNorm, $custom);
    }

    if (printflow_is_sequential_list_array($custom)) {
        $merged = [];
        $scalar_i = 0;
        foreach ($custom as $row) {
            if (!is_array($row)) {
                $scalar_i++;
                $merged['Item ' . $scalar_i] = $row;
                continue;
            }
            $lk = isset($row['label']) ? trim((string)$row['label']) : '';
            if ($lk === '' && isset($row['name'])) {
                $lk = trim((string)$row['name']);
            }
            $vk = $row['value'] ?? $row['val'] ?? null;
            if ($lk !== '' && $vk !== null && $vk !== '') {
                $merged[$lk] = $vk;
                continue;
            }
            foreach ($row as $sk => $sv) {
                if ($sk === 'value' || $sk === 'val') {
                    continue;
                }
                if (!is_string($sk) && !is_int($sk)) {
                    continue;
                }
                $skStr = trim((string)$sk);
                if ($skStr === '') {
                    continue;
                }
                if ($sv === null || $sv === '') {
                    continue;
                }
                $merged[$skStr] = $sv;
            }
        }
        return printflow_normalize_customization_for_modal($merged, $depth + 1);
    }

    return $custom;
}

/**
 * Keys synced from job_orders width/height/total_sqft into merged customization — not customer-facing specs.
 */
function printflow_is_job_orders_derived_dimension_key(string $key): bool {
    $k = strtolower(preg_replace('/[\s-]+/', '_', trim($key)));

    return $k === 'dimensions_ft'
        || $k === 'width_ft'
        || $k === 'height_ft'
        || $k === 'total_sqft';
}

/**
 * Remove zero placeholders from job_orders so the customer modal matches real order line specs (inches, poster size, etc.).
 *
 * @param array<string, string> $flat
 * @return array<string, string>
 */
function printflow_customer_modal_strip_placeholder_job_dimensions(array $flat): array {
    foreach ($flat as $k => $text) {
        if (!is_string($k) || !printflow_is_job_orders_derived_dimension_key($k)) {
            continue;
        }
        if (!printflow_modal_spec_value_is_empty_noise($k, $text, (string)$text)) {
            continue;
        }
        unset($flat[$k]);
    }

    return $flat;
}

/**
 * Hide all-zero dimension noise from customer order modal (job placeholders / unused ft fields on inch-based forms).
 */
function printflow_modal_spec_value_is_empty_noise(string $key, $rawVal, string $displayText): bool {
    $k = strtolower(preg_replace('/[\s-]+/', '_', $key));
    $isDim =
        str_contains($k, 'dimension')
        || str_contains($k, 'width')
        || str_contains($k, 'height')
        || str_contains($k, 'sqft')
        || str_contains($k, 'sq_ft')
        || str_contains($k, 'size_'); // e.g. size_ft, not plain "size" (often "3×4 in")

    if (!$isDim) {
        return false;
    }

    if (is_numeric($rawVal) && abs((float)$rawVal) < 1e-8) {
        return true;
    }

    $t = trim($displayText);
    if ($t === '' || $t === '0' || $t === '0.0' || $t === '0.00' || $t === '0.000') {
        return true;
    }

    if (is_numeric(str_replace([',', ' '], '', $t)) && abs((float)str_replace([',', ' '], '', $t)) < 1e-8) {
        return true;
    }

    $collapse = preg_replace('/\s+/', ' ', $t);

    return (bool)preg_match(
        '/^(?:0+(?:\\.0+)?\\s*[×x]\\s*)+0+(?:\\.0+)?(?:\\s*(?:ft|in|cm|m))?$/iu',
        $collapse
    );
}

/**
 * Normalize spec keys so "Needed Date", "needed_date", and "needed-date" collapse together.
 */
function printflow_customer_modal_nf_spec_key(string $k): string {
    return strtolower(preg_replace('/[\s_\-]+/', '', trim($k)));
}

/**
 * Prefer human labels (with spaces) over snake_case internal keys when collapsing duplicates.
 */
function printflow_customer_modal_spec_key_display_score(string $k): int {
    $k = trim($k);
    if ($k === '') {
        return 0;
    }
    if (preg_match('/^[a-z][a-z0-9_]*$/', $k)) {
        return 0;
    }
    if (preg_match('/\s/', $k)) {
        return 2;
    }

    return 1;
}

/**
 * Compare display values for duplicate detection (ISO dates vs formatted dates vs plain text).
 */
function printflow_customer_modal_normalize_value_bucket(string $text): string {
    $t = trim($text);
    if ($t === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $t, $m)) {
        return 'dt:' . substr($m[0], 0, 10);
    }
    $ts = strtotime($t);
    if ($ts !== false) {
        return 'dt:' . date('Y-m-d', $ts);
    }

    return 'v:' . strtolower(preg_replace('/\s+/', ' ', $t));
}

/**
 * Collapse duplicate rows (e.g. Needed Date + needed_date from order_service_dynamic) and drop quantity
 * when it only repeats the table Qty column.
 *
 * @param array<string, string> $flat
 * @return array<string, string>
 */
function printflow_customer_modal_dedupe_flat_specs(array $flat, ?int $lineQuantity = null, bool $is_staff = false): array {
    if ($flat === []) {
        return $flat;
    }

    $groups = [];
    foreach ($flat as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        $nk = printflow_customer_modal_nf_spec_key($k);
        if ($nk === '') {
            $nk = '_k_' . md5($k);
        }
        $groups[$nk][] = [$k, (string)$v];
    }

    $out = [];
    foreach ($groups as $rows) {
        if (count($rows) === 1) {
            $out[$rows[0][0]] = $rows[0][1];
            continue;
        }

        $bucketCounts = [];
        foreach ($rows as $row) {
            $b = printflow_customer_modal_normalize_value_bucket($row[1]);
            $bucketCounts[$b] = ($bucketCounts[$b] ?? 0) + 1;
        }

        if (count($bucketCounts) === 1) {
            usort($rows, static function ($a, $b) {
                return printflow_customer_modal_spec_key_display_score($b[0]) <=> printflow_customer_modal_spec_key_display_score($a[0]);
            });
            $out[$rows[0][0]] = $rows[0][1];
        } else {
            foreach ($rows as $row) {
                $out[$row[0]] = $row[1];
            }
        }
    }

    if (!$is_staff && $lineQuantity !== null && $lineQuantity > 0) {
        $qtyStr = (string)$lineQuantity;
        foreach (array_keys($out) as $k) {
            $nk = printflow_customer_modal_nf_spec_key($k);
            if (($nk === 'quantity' || $nk === 'qty') && trim((string)$out[$k]) === $qtyStr) {
                unset($out[$k]);
            }
        }
    }

    return $out;
}

/**
 * Prepare customization key/values for customer order details modal (human-readable strings, no binary/temp fields).
 */
function printflow_flatten_order_customization_for_customer_modal(array $custom, ?int $lineQuantity = null, bool $is_staff = false): array {
    $custom = printflow_normalize_customization_for_modal($custom);
    // Match render_order_item_clean skips where sensible; omit note-* keys so the modal can render long-form blocks.
    // Strip job_orders-derived ft/sqft rows when they are all-zero placeholders (see printflow_customer_modal_strip_placeholder_job_dimensions).
    $skip = [
        'design_upload',
        'reference_upload',
        'design_tmp_path',
        'reference_tmp_path',
        'reference_mime',
        'design_mime',
        'install_province',
        'install_city',
        'install_barangay',
        'install_street',
        'form_type',
        'config_id',
        'source_page',
        'source',
        'cart_key',
        '_cart_key',
    ];
    if (!$is_staff) {
        $skip[] = 'service_id';
        $skip[] = 'Branch_ID';
        $skip[] = 'service_type';
        $skip[] = 'product_type';
        $skip[] = 'notes';
        $skip[] = 'additional_notes';
    }
    $out = [];
    $unnamed = 0;
    foreach ($custom as $k => $v) {
        if (is_int($k)) {
            $unnamed++;
            $k = $unnamed === 1 ? 'Details' : ('Details ' . $unnamed);
        } elseif (!is_string($k)) {
            continue;
        }
        if ($k === '') {
            $unnamed++;
            $k = $unnamed === 1 ? 'Details' : ('Details ' . $unnamed);
        }
        if (($k !== '' && $k[0] === '_') || in_array($k, $skip, true)) {
            continue;
        }
        if ($v === null || $v === '') {
            continue;
        }
        $text = function_exists('pf_order_ui_value_to_text')
            ? pf_order_ui_value_to_text($v)
            : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : (string)$v);
        if (trim((string)$text) === '') {
            continue;
        }
        $keyLower = strtolower((string)$k);
        $trimText = trim((string)$text);
        if (
            (str_contains($keyLower, 'date') || str_contains($keyLower, 'needed'))
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $trimText)
            && function_exists('format_date')
        ) {
            $rawD = substr($trimText, 0, 10);
            $ts = strtotime($rawD);
            if ($ts !== false) {
                $text = format_date($rawD);
            }
        }
        $out[$k] = $text;
    }

    $out = printflow_customer_modal_strip_placeholder_job_dimensions($out);

    return printflow_customer_modal_dedupe_flat_specs($out, $lineQuantity, $is_staff);
}

/**
 * Flatten customization for the customer “View order” modal (normalized + human-readable; no dimension “noise” stripping).
 */
function printflow_flatten_customization_for_customer_order_modal(array $custom, ?int $lineQuantity = null, bool $is_staff = false): array {
    return printflow_flatten_order_customization_for_customer_modal($custom, $lineQuantity, $is_staff);
}

/**
 * Wider flatten for staff/production modals when the customer-modal flatten drops too many rows
 * (aggressive skips + qty dedupe) but merged customization still has usable scalar specs.
 *
 * @return array<string, string>
 */
function printflow_modal_customization_fallback_flatten_for_staff(array $custom, ?int $lineQuantity = null): array {
    $custom = printflow_normalize_customization_for_modal($custom);
    $skip = [
        'design_upload',
        'reference_upload',
        'design_tmp_path',
        'reference_tmp_path',
        'reference_mime',
        'design_mime',
        'cart_key',
        '_cart_key',
        'Branch_ID',
        'branch_id',
        'install_province',
        'install_city',
        'install_barangay',
        'install_street',
    ];
    $out = [];
    $unnamed = 0;
    foreach ($custom as $k => $v) {
        if (is_int($k)) {
            $unnamed++;
            $k = $unnamed === 1 ? 'Details' : ('Details ' . $unnamed);
        } elseif (!is_string($k)) {
            continue;
        }
        if ($k === '') {
            $unnamed++;
            $k = $unnamed === 1 ? 'Details' : ('Details ' . $unnamed);
        }
        if (($k !== '' && $k[0] === '_') || in_array($k, $skip, true)) {
            continue;
        }
        if ($v === null || $v === '') {
            continue;
        }
        $text = function_exists('pf_order_ui_value_to_text')
            ? pf_order_ui_value_to_text($v)
            : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : (string)$v);
        if (trim((string)$text) === '') {
            continue;
        }
        $keyLower = strtolower((string)$k);
        $trimText = trim((string)$text);
        if (
            (str_contains($keyLower, 'date') || str_contains($keyLower, 'needed'))
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $trimText)
            && function_exists('format_date')
        ) {
            $rawD = substr($trimText, 0, 10);
            $ts = strtotime($rawD);
            if ($ts !== false) {
                $text = format_date($rawD);
            }
        }
        $out[$k] = $text;
    }

    $out = printflow_customer_modal_strip_placeholder_job_dimensions($out);

    return printflow_customer_modal_dedupe_flat_specs($out, $lineQuantity, true);
}

/**
 * Sum of quantities for catalog product sales (non-cancelled). Counts product-type and legacy orders;
 * custom rows that are catalog/dynamic products (config_id / dynamic form / products source);
 * and custom checkout/review orders once source_page is stored in customization_data (see order_review/checkout).
 */
function printflow_product_units_sold(int $product_id): int {
    $product_id = (int)$product_id;
    if ($product_id <= 0) {
        return 0;
    }
    $rows = db_query(
        "SELECT COALESCE(SUM(oi.quantity), 0) AS cnt
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         WHERE oi.product_id = ?
           AND o.status != 'Cancelled'
           AND (
                LOWER(TRIM(COALESCE(o.order_type, ''))) != 'custom'
                OR oi.customization_data LIKE '%\"config_id\"%'
                OR oi.customization_data LIKE '%\"form_type\":\"dynamic\"%'
                OR oi.customization_data LIKE '%\"form_type\": \"dynamic\"%'
                OR oi.customization_data LIKE '%\"source_page\":\"products\"%'
                OR oi.customization_data LIKE '%\"source_page\":\"product\"%'
                OR oi.customization_data LIKE '%\"source_page\":\"dynamic_form\"%'
                OR oi.customization_data LIKE '%\"source_page\": \"products\"%'
                OR oi.customization_data LIKE '%\"source_page\": \"product\"%'
                OR oi.customization_data LIKE '%\"source_page\": \"dynamic_form\"%'
           )",
        'i',
        [$product_id]
    );
    return (int)(($rows[0]['cnt'] ?? 0));
}

/**
 * Sum of quantities for a configured service (services.service_id) from custom orders and legacy JSON matches.
 */
function printflow_service_units_sold(int $service_id): int {
    $service_id = (int)$service_id;
    if ($service_id <= 0) {
        return 0;
    }
    $meta = db_query('SELECT name FROM services WHERE service_id = ? LIMIT 1', 'i', [$service_id]);
    $sname = trim((string)($meta[0]['name'] ?? ''));

    $likeColon = '%"service_id":' . $service_id . '%';
    $likeColonSp = '%"service_id": ' . $service_id . '%';
    $likeQuoted = '%"service_id":"' . $service_id . '"%';

    $types = 'isss';
    $params = [$service_id, $likeColon, $likeColonSp, $likeQuoted];

    $sql = "SELECT COALESCE(SUM(oi.quantity), 0) AS cnt
            FROM order_items oi
            INNER JOIN orders o ON o.order_id = oi.order_id
            WHERE o.status != 'Cancelled'
              AND (
                (LOWER(TRIM(COALESCE(o.order_type, ''))) = 'custom' AND o.reference_id = ?)
                OR oi.customization_data LIKE ?
                OR oi.customization_data LIKE ?
                OR oi.customization_data LIKE ?";

    if ($sname !== '') {
        $sql .= " OR (oi.customization_data LIKE ? AND oi.customization_data LIKE ?)";
        $types .= 'ss';
        $params[] = '%"service_type"%';
        $params[] = '%' . $sname . '%';
    }

    $sql .= "\n              )";

    $rows = db_query($sql, $types, $params);
    return (int)(($rows[0]['cnt'] ?? 0));
}

function printflow_media_path_looks_like_image(string $path, string $fallbackName = ''): bool {
    $candidate = trim($path);
    if ($candidate === '' && trim($fallbackName) === '') {
        return false;
    }

    if ($candidate !== '') {
        $parsedPath = parse_url($candidate, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $candidate = $parsedPath;
        }
    } else {
        $candidate = $fallbackName;
    }

    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'], true);
}

function printflow_order_item_has_previewable_design(array $item): bool {
    $hasBlob = !empty($item['has_design_blob']) || !empty($item['design_image']);
    if ($hasBlob) {
        $mime = strtolower(trim((string)($item['design_image_mime'] ?? '')));
        return $mime === '' || strpos($mime, 'image/') === 0;
    }

    return printflow_media_path_looks_like_image(
        (string)($item['design_file'] ?? ''),
        (string)($item['design_image_name'] ?? '')
    );
}


function printflow_order_notification_preview(int $order_id): array {
    static $cache = [];

    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return ['display_name' => '', 'image_url' => '', 'item_kind' => ''];
    }
    if (isset($cache[$order_id])) {
        return $cache[$order_id];
    }

    $base = printflow_notification_base_path();
    $has_product_image = !empty(db_query("SHOW COLUMNS FROM products LIKE 'product_image'"));
    $has_photo_path = !empty(db_query("SHOW COLUMNS FROM products LIKE 'photo_path'"));
    $product_image_expr = "'' AS product_image";
    if ($has_product_image && $has_photo_path) {
        $product_image_expr = "COALESCE(p.photo_path, p.product_image) AS product_image";
    } elseif ($has_product_image) {
        $product_image_expr = "p.product_image AS product_image";
    } elseif ($has_photo_path) {
        $product_image_expr = "p.photo_path AS product_image";
    }

    $item = db_query(
        "SELECT oi.order_item_id,
                IF(oi.design_image IS NOT NULL AND oi.design_image != '', 1, 0) AS has_design_blob,
                oi.design_image_mime,
                oi.design_image_name,
                oi.design_file,
                oi.customization_data,
                p.name AS product_name,
                p.sku AS product_sku,
                p.product_id,
                p.product_type,
                o.order_type,
                o.reference_id,
                (SELECT COALESCE(
                    (SELECT c1.customization_details FROM customizations c1
                     WHERE c1.order_id = o.order_id
                       AND c1.order_item_id = oi.order_item_id
                     ORDER BY c1.customization_id ASC LIMIT 1),
                    (SELECT c2.customization_details FROM customizations c2 WHERE c2.order_id = o.order_id ORDER BY c2.customization_id ASC LIMIT 1)
                )) AS first_customization_details,
                (SELECT COALESCE(
                    (SELECT c1.service_type FROM customizations c1
                     WHERE c1.order_id = o.order_id
                       AND c1.order_item_id = oi.order_item_id
                     ORDER BY c1.customization_id ASC LIMIT 1),
                    (SELECT c2.service_type FROM customizations c2 WHERE c2.order_id = o.order_id ORDER BY c2.customization_id ASC LIMIT 1)
                )) AS first_customization_service_type,
                (SELECT jo.job_title FROM job_orders jo WHERE jo.order_id = o.order_id ORDER BY jo.id ASC LIMIT 1) AS first_job_title,
                (SELECT jo.service_type FROM job_orders jo WHERE jo.order_id = o.order_id ORDER BY jo.id ASC LIMIT 1) AS first_job_service_type,
                {$product_image_expr}
         FROM order_items oi
         LEFT JOIN products p ON oi.product_id = p.product_id
         LEFT JOIN orders o ON o.order_id = oi.order_id
         WHERE oi.order_id = ?
         ORDER BY oi.order_item_id ASC
         LIMIT 1",
        'i',
        [$order_id]
    );

    $preview = ['display_name' => '', 'image_url' => '', 'item_kind' => ''];
    if (empty($item[0])) {
        $snap = printflow_notification_order_snapshot($order_id);
        $preview['display_name'] = trim((string)($snap['service_name'] ?? ''));
        $oRow = db_query('SELECT order_type, reference_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$order_id]) ?: [];
        $ot = '';
        if (!empty($oRow[0])) {
            $ot = strtolower(trim((string)($oRow[0]['order_type'] ?? '')));
            $ref = (int)($oRow[0]['reference_id'] ?? 0);
            if ($preview['display_name'] === '' && $ref > 0) {
                if ($ot === 'custom') {
                    $preview['display_name'] = customer_orders_resolve_service_name_by_id($ref);
                } else {
                    $pn = db_query('SELECT name FROM products WHERE product_id = ? LIMIT 1', 'i', [$ref]);
                    $preview['display_name'] = trim((string)($pn[0]['name'] ?? ''));
                }
            }
            if ($ot === 'product') {
                $preview['item_kind'] = 'Product';
            } elseif ($ot === 'custom') {
                $preview['item_kind'] = 'Service';
            }
        }
        if (trim($preview['display_name']) === '') {
            if ($preview['item_kind'] === 'Product') {
                $preview['display_name'] = 'Product order';
            } elseif ($preview['item_kind'] === 'Service') {
                $preview['display_name'] = 'Service order';
            } else {
                $preview['display_name'] = 'Order';
            }
        }
        if ($preview['image_url'] === '' && $preview['display_name'] !== '') {
            if ($preview['item_kind'] === 'Product' && !empty($oRow[0]) && (int)($oRow[0]['reference_id'] ?? 0) > 0 && $ot === 'product') {
                $refPid = (int)$oRow[0]['reference_id'];
                foreach (['jpg', 'png', 'jpeg', 'webp'] as $ext) {
                    $candidateRel = '/public/images/products/product_' . $refPid . '.' . $ext;
                    if (file_exists(__DIR__ . '/..' . $candidateRel)) {
                        $preview['image_url'] = printflow_notification_normalize_media_url($base . $candidateRel);
                        break;
                    }
                }
            }
            if ($preview['image_url'] === '' && $preview['item_kind'] !== 'Product') {
                $refSid = (!empty($oRow[0]) && $ot === 'custom') ? (int)($oRow[0]['reference_id'] ?? 0) : 0;
                if ($refSid > 0) {
                    $preview['image_url'] = printflow_notification_service_image_from_id($refSid) ?: '';
                }
                if ($preview['image_url'] === '') {
                    $preview['image_url'] = printflow_notification_service_image_from_name($preview['display_name']) ?: '';
                }
            }
        }
        if ($preview['image_url'] === '') {
            $preview['image_url'] = printflow_notification_normalize_media_url($base . '/public/assets/images/services/default.png');
        }
        $cache[$order_id] = $preview;
        return $preview;
    }

    $row = $item[0];
    $custom = printflow_decode_modal_customization_payload((string)($row['customization_data'] ?? ''));
    $order_type = strtolower(trim((string)($row['order_type'] ?? '')));
    $is_pos_placeholder = printflow_is_pos_service_placeholder_row($row);
    $linePid = (int)($row['product_id'] ?? 0);
    $lineServiceId = (int)($custom['service_id'] ?? 0);
    $srcPage = strtolower(trim((string)($custom['source_page'] ?? '')));
    $resolvedServiceIdForImage = $lineServiceId;
    if ($resolvedServiceIdForImage <= 0 && $order_type === 'custom') {
        $resolvedServiceIdForImage = (int)($row['reference_id'] ?? 0);
    }

    if ($order_type === 'product' && !$is_pos_placeholder) {
        $preview['item_kind'] = 'Product';
    } elseif ($is_pos_placeholder) {
        $preview['item_kind'] = 'Service';
    } elseif ($lineServiceId > 0 || in_array($srcPage, ['services', 'service', 'dynamic_form'], true)) {
        $preview['item_kind'] = 'Service';
    } elseif ($linePid > 0 && in_array($srcPage, ['products', 'product'], true)) {
        $preview['item_kind'] = 'Product';
    } elseif ($order_type === 'custom') {
        if ($linePid > 0 && in_array($srcPage, ['products', 'product'], true)) {
            $preview['item_kind'] = 'Product';
        } elseif (printflow_order_item_has_service_marker($custom)) {
            $preview['item_kind'] = 'Service';
        } else {
            $preview['item_kind'] = $linePid > 0 ? 'Product' : 'Service';
        }
    } elseif (printflow_order_item_has_service_marker($custom)) {
        $preview['item_kind'] = 'Service';
    } elseif ($linePid > 0) {
        $preview['item_kind'] = 'Product';
    }

    $orderForDisplayName = [
        'order_type' => $row['order_type'] ?? '',
        'reference_id' => $row['reference_id'] ?? null,
        'first_product_name' => $row['product_name'] ?? '',
        'first_item_customization' => $row['customization_data'] ?? '',
        'first_customization_details' => $row['first_customization_details'] ?? '',
        'first_customization_service_type' => $row['first_customization_service_type'] ?? '',
        'first_job_title' => $row['first_job_title'] ?? '',
        'first_job_service_type' => $row['first_job_service_type'] ?? '',
    ];
    $preview['display_name'] = customer_orders_primary_item_name($orderForDisplayName);
    if (trim($preview['display_name']) === '' || customer_orders_is_generic_item_name($preview['display_name'])) {
        $is_service_item = ($preview['item_kind'] === 'Service');
        $service_name = $is_service_item ? get_service_name_from_customization($custom, '') : '';
        $display_name_source = $service_name !== '' ? $service_name : (string)($row['product_name'] ?? 'Order Item');
        if (!$is_service_item && trim($display_name_source) === '') {
            $display_name_source = (string)($custom['product_type'] ?? 'Order Item');
        }
        $preview['display_name'] = printflow_resolve_order_item_name(
            $display_name_source,
            $custom,
            'Order Item'
        );
    }

    if (printflow_order_item_has_previewable_design($row) && !empty($row['order_item_id'])) {
        $preview['image_url'] = $base . '/public/serve_design.php?type=order_item&id=' . (int)$row['order_item_id'];
        $preview['image_url'] = printflow_notification_normalize_media_url($preview['image_url']);
        $cache[$order_id] = $preview;
        return $preview;
    }

    $fallbackDesignRow = db_query(
        "SELECT order_item_id, design_image, design_image_mime, design_image_name, design_file
         FROM order_items
         WHERE order_id = ?
           AND (
               design_image IS NOT NULL
               OR (
                   design_file IS NOT NULL
                   AND TRIM(COALESCE(design_file, '')) <> ''
               )
           )
         ORDER BY order_item_id ASC
         LIMIT 1",
        'i',
        [$order_id]
    );
    if (!empty($fallbackDesignRow[0]) && printflow_order_item_has_previewable_design($fallbackDesignRow[0])) {
        $preview['image_url'] = $base . '/public/serve_design.php?type=order_item&id=' . (int)$fallbackDesignRow[0]['order_item_id'];
        $preview['image_url'] = printflow_notification_normalize_media_url($preview['image_url']);
        $cache[$order_id] = $preview;
        return $preview;
    }

    $product_image = trim((string)($row['product_image'] ?? ''));
    if ($product_image !== '' && $preview['item_kind'] !== 'Service') {
        $normalizedProductImage = printflow_notification_normalize_media_url($product_image);
        if ($normalizedProductImage !== '' && printflow_notification_local_media_exists($normalizedProductImage)) {
            $preview['image_url'] = $normalizedProductImage;
        } elseif (file_exists(__DIR__ . '/../uploads/products/' . $product_image)) {
            $preview['image_url'] = printflow_notification_normalize_media_url($base . '/uploads/products/' . $product_image);
        } elseif (file_exists(__DIR__ . '/../public/images/products/' . $product_image)) {
            $preview['image_url'] = printflow_notification_normalize_media_url($base . '/public/images/products/' . $product_image);
        }
    }

    if ($preview['image_url'] === '' && $preview['item_kind'] === 'Product') {
        $prodId = (int)($row['product_id'] ?? 0);
        if ($prodId > 0) {
            foreach (['jpg', 'png', 'jpeg', 'webp'] as $ext) {
                $candidateRel = '/public/images/products/product_' . $prodId . '.' . $ext;
                if (file_exists(__DIR__ . '/..' . $candidateRel)) {
                    $preview['image_url'] = printflow_notification_normalize_media_url($base . $candidateRel);
                    break;
                }
            }
        }
    }

    if ($preview['image_url'] === '' && $preview['item_kind'] === 'Service') {
        if ($resolvedServiceIdForImage > 0) {
            $preview['image_url'] = printflow_notification_service_image_from_id($resolvedServiceIdForImage);
        }
        if ($preview['image_url'] === '') {
            $serviceImage = printflow_notification_service_image_from_name($preview['display_name']);
            if ($serviceImage !== '') {
                $preview['image_url'] = $serviceImage;
            }
        }
    }

    if ($preview['image_url'] === '') {
        $fallbackImage = ($preview['item_kind'] === 'Product')
            ? ($base . '/public/images/products/product_1.jpg')
            : get_service_image_url($preview['display_name']);
        $normalizedFallback = printflow_notification_normalize_media_url($fallbackImage);
        if ($normalizedFallback !== '' && printflow_notification_local_media_exists($normalizedFallback)) {
            $preview['image_url'] = $normalizedFallback;
        } else {
            $preview['image_url'] = printflow_notification_normalize_media_url($base . '/public/assets/images/services/default.png');
        }
    }

    $cache[$order_id] = $preview;
    return $preview;
}

/**
 * Get count of unread chat messages for an order
 * @param int $order_id
 * @param string $viewer_role 'Customer' or 'Staff'
 * @return int
 */
function get_unread_chat_count($order_id, $viewer_role) {
    // If viewer is Customer, they haven't read messages from Staff
    // If viewer is Staff, they haven't read messages from Customer
    $sender_role = ($viewer_role === 'Customer') ? 'Staff' : 'Customer';
    
    $sql = "SELECT COUNT(*) as count FROM order_messages 
            WHERE order_id = ? AND sender = ? AND read_receipt = 0";
    $result = db_query($sql, 'is', [$order_id, $sender_role]);
    
    return $result[0]['count'] ?? 0;
}

/**
 * Generate random order number
 * @return string
 */
function generate_order_number() {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Check if product is low stock
 * @param int $product_id
 * @param int $threshold Default threshold
 * @return bool
 */
function is_low_stock($product_id, $threshold = 10) {
    $result = db_query("SELECT stock_quantity FROM products WHERE product_id = ?", 'i', [$product_id]);
    
    if (empty($result)) {
        return false;
    }
    
    return $result[0]['stock_quantity'] <= $threshold;
}

/**
 * Compute stock status from quantity and low_stock_level (not stored in DB)
 * @param int $stock_quantity
 * @param int $low_stock_level
 * @return string "Out of Stock"|"Low Stock"|"In Stock"
 */
function get_stock_status($stock_quantity, $low_stock_level = 10) {
    $qty = (int) $stock_quantity;
    $low = (int) ($low_stock_level ?? 10);
    if ($qty <= 0) return 'Out of Stock';
    if ($qty <= $low) return 'Low Stock';
    return 'In Stock';
}

/**
 * Get app setting
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function get_setting($key, $default = null) {
    $result = db_query("SELECT value FROM settings WHERE key_name = ?", 's', [$key]);
    
    if (empty($result)) {
        return $default;
    }
    
    return $result[0]['value'];
}

/**
 * Set app setting
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function set_setting($key, $value) {
    $existing = db_query("SELECT setting_id FROM settings WHERE key_name = ?", 's', [$key]);
    
    if (empty($existing)) {
        return db_execute("INSERT INTO settings (key_name, value) VALUES (?, ?)", 'ss', [$key, $value]);
    } else {
        return db_execute("UPDATE settings SET value = ? WHERE key_name = ?", 'ss', [$value, $key]);
    }
}

/**
 * Render pagination UI
 * @param int $current_page Current page number
 * @param int $total_pages Total number of pages
 * @param array $extra_params Extra query parameters to preserve (e.g. search, filters)
 * @param string $page_param Query param name for page number (default: 'page')
 * @return string HTML string
 */
function render_pagination($current_page, $total_pages, $extra_params = [], $page_param = 'page') {
    if ($total_pages <= 1) return '';

    $current_page = (int)$current_page;
    $window = 2; // Pages before/after current (classic admin pagination)
    $pages = [];

    $pages[] = 1;

    $range_start = max(2, $current_page - $window);
    $range_end   = min($total_pages - 1, $current_page + $window);
    for ($i = $range_start; $i <= $range_end; $i++) {
        $pages[] = $i;
    }

    if ($total_pages > 1) {
        $pages[] = $total_pages;
    }

    $pages = array_unique($pages);
    sort($pages);

    // Inline styles — 'all:unset' defeats Tailwind/output.css resets on <a> tags (PrintFlow primary #06A1A1)
    $base_btn   = 'all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:8px;border:1px solid #e5e7eb;background:#ffffff;color:#374151 !important;text-decoration:none !important;font-size:13px;font-weight:500;cursor:pointer;transition:all 0.2s;';
    $active_btn = 'all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:8px;border:1px solid #06A1A1;background:#06A1A1;color:#ffffff !important;text-decoration:none !important;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 2px 4px rgba(6,161,161,0.2);';
    $hover      = ' onmouseover="this.style.borderColor=\'#06A1A1\';this.style.color=\'#06A1A1\'" onmouseout="this.style.borderColor=\'#e5e7eb\';this.style.color=\'#374151\'"';
    $ellipsis   = '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;font-size:13px;color:#9ca3af;letter-spacing:1px;">···</span>';

    $params = $extra_params;
    unset($params[$page_param]);

    // Plain href navigation (no fetchUpdatedTable onclick): avoids mismatched signatures (e.g. fetchUpdatedTable(number) vs {page:n}) breaking Products/Services pagination.
    $html = '<div class="pagination-container" style="display:flex; align-items:center; justify-content:center; gap:6px; margin-top:24px; padding:16px 0; border-top:1px solid #f1f5f9; width:100%;">';

    if ($current_page > 1) {
        $params[$page_param] = $current_page - 1;
        $url = '?' . http_build_query($params);
        $html .= '<a class="pagination-link pagination-prev" href="' . htmlspecialchars($url) . '" style="' . $base_btn . '"' . $hover . '>
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>';
    }

    $prev_page = null;
    foreach ($pages as $p) {
        if ($prev_page !== null && $p - $prev_page > 1) {
            $html .= $ellipsis;
        }

        $params[$page_param] = $p;
        $url = '?' . http_build_query($params);

        if ((int)$p === (int)$current_page) {
            $html .= '<a class="pagination-link is-active" aria-current="page" href="' . htmlspecialchars($url) . '" style="' . $active_btn . '">' . $p . '</a>';
        } else {
            $html .= '<a class="pagination-link" href="' . htmlspecialchars($url) . '" style="' . $base_btn . '"' . $hover . '>' . $p . '</a>';
        }
        $prev_page = $p;
    }

    if ($current_page < $total_pages) {
        $params[$page_param] = $current_page + 1;
        $url = '?' . http_build_query($params);
        $html .= '<a class="pagination-link pagination-next" href="' . htmlspecialchars($url) . '" style="' . $base_btn . '"' . $hover . '>
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Alias for render_pagination (backward compatibility)
 */
function get_pagination_links($current_page, $total_pages, $extra_params = [], $page_param = 'page') {
    return render_pagination($current_page, $total_pages, $extra_params, $page_param);
}

/**
 * Check if a customer's ID is verified.
 */
function is_customer_id_verified($customer_id = null) {
    if ($customer_id === null) $customer_id = get_user_id();
    if (!$customer_id) return false;
    static $cache = [];
    if (isset($cache[$customer_id])) return $cache[$customer_id];
    // Ensure columns exist
    global $conn;
    $cols = db_query("SHOW COLUMNS FROM customers LIKE 'id_status'");
    if (empty($cols)) {
        $conn->query("ALTER TABLE customers ADD COLUMN id_image VARCHAR(255) DEFAULT NULL, ADD COLUMN id_type VARCHAR(100) DEFAULT NULL, ADD COLUMN id_status ENUM('None','Pending','Verified','Rejected') DEFAULT 'None', ADD COLUMN id_reject_reason VARCHAR(255) DEFAULT NULL");
    }
    $r = db_query("SELECT id_status FROM customers WHERE customer_id = ?", 'i', [$customer_id]);
    return $cache[$customer_id] = (!empty($r) && $r[0]['id_status'] === 'Verified');
}

/**
 * Determine if a customer can cancel an order based on its status.
 */
function can_customer_cancel_order($order) {
    if (!$order) return false;
    $status = strtoupper(trim((string)($order['status'] ?? '')));
    // Customers can still cancel before production starts, including To Pay.
    $allowed_statuses = ['PENDING', 'TO PAY', 'TO_PAY', 'FOR REVISION', 'PENDING VERIFICATION', 'PENDING_VERIFICATION'];
    return in_array($status, $allowed_statuses, true);
}

/**
 * Get base URL for the application
 * @return string
 */
function get_base_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $path = rtrim($path, '/');
    return $protocol . '://' . $host . $path;
}

/**
 * Build a public URL for a stored profile image.
 */
function get_profile_image($image) {
    $base = rtrim(defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : ''), '/');
    $fallback = $base . '/public/assets/uploads/profiles/default.png';
    $image = trim((string)$image);

    if ($image === '' || strtolower($image) === 'null' || strtolower($image) === 'undefined') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    $clean = str_replace('\\', '/', ltrim($image, '/'));
    $base_path = trim(parse_url($base, PHP_URL_PATH) ?: $base, '/');
    if ($base_path !== '' && strpos($clean, $base_path . '/') === 0) {
        $clean = substr($clean, strlen($base_path) + 1);
    }

    if (strpos($clean, 'public/') === 0 || strpos($clean, 'uploads/') === 0) {
        $relative_path = preg_replace('#/+#', '/', $clean);
    } else {
        $relative_path = 'public/assets/uploads/profiles/' . basename($clean);
    }

    $file_path = __DIR__ . '/../' . $relative_path;
    if (!is_file($file_path)) {
        return $fallback;
    }

    return $base . '/' . $relative_path;
}

/**
 * Detects the service name based on customization keys if not explicitly provided.
 */
function normalize_service_name($name, $fallback = 'Custom Order') {
    $clean = trim((string)$name);
    if ($clean === '') return $fallback;

    $normalized = strtolower(preg_replace('/\s+/', ' ', $clean));
    
    // Exact mapping for core services to ensure consistency across the system
    $map = [
        'tarpaulin' => 'Tarpaulin Printing',
        'tarpaulin printing' => 'Tarpaulin Printing',
        'tarp' => 'Tarpaulin Printing',
        't-shirt' => 'T-Shirt Printing',
        'tshirt' => 'T-Shirt Printing',
        't-shirt printing' => 'T-Shirt Printing',
        'tshirt printing' => 'T-Shirt Printing',
        'stickers' => 'Decals/Stickers (Print/Cut)',
        'sticker' => 'Decals/Stickers (Print/Cut)',
        'decal' => 'Decals/Stickers (Print/Cut)',
        'decals' => 'Decals/Stickers (Print/Cut)',
        'decals/stickers (print/cut)' => 'Decals/Stickers (Print/Cut)',
        'decals / stickers (print/cut)' => 'Decals/Stickers (Print/Cut)',
        'decals / stickers (print & cut)' => 'Decals/Stickers (Print/Cut)',
        'sintraboard' => 'Sintraboard Standees',
        'sintra board' => 'Sintraboard Standees',
        'standee' => 'Sintraboard Standees',
        'standees' => 'Sintraboard Standees',
        'glass sticker' => 'Glass/Wall Stickers',
        'frosted sticker' => 'Glass/Wall Stickers',
        'wall sticker' => 'Glass/Wall Stickers',
        'transparent sticker' => 'Transparent Stickers',
        'reflectorized' => 'Reflectorized',
        'souvenir' => 'Souvenirs',
        'souvenirs' => 'Souvenirs'
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    // Handle noisy labels from cart / legacy flows, e.g. "Reflectorized: ABC123"
    // while keeping arbitrary user-entered subtype fields out of the main service name.
    $contains_map = [
        'reflectorized' => 'Reflectorized',
        'tarpaulin' => 'Tarpaulin Printing',
        'tarp' => 'Tarpaulin Printing',
        't-shirt' => 'T-Shirt Printing',
        'tshirt' => 'T-Shirt Printing',
        'shirt printing' => 'T-Shirt Printing',
        'transparent sticker' => 'Transparent Stickers',
        'glass sticker' => 'Glass/Wall Stickers',
        'wall sticker' => 'Glass/Wall Stickers',
        'frosted sticker' => 'Glass/Wall Stickers',
        'sintra' => 'Sintraboard Standees',
        'standee' => 'Sintraboard Standees',
        'souvenir' => 'Souvenirs',
    ];
    foreach ($contains_map as $needle => $canonical) {
        if (strpos($normalized, $needle) !== false) {
            return $canonical;
        }
    }
    if (strpos($normalized, 'sticker') !== false || strpos($normalized, 'decal') !== false) {
        return 'Decals/Stickers (Print/Cut)';
    }

    return ucwords($clean);
}

function printflow_review_schema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    $review_cols = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
    $schema = [
        'user_col' => isset($review_cols['user_id']) ? 'user_id' : (isset($review_cols['customer_id']) ? 'customer_id' : 'user_id'),
        'message_col' => isset($review_cols['comment']) ? 'comment' : (isset($review_cols['message']) ? 'message' : 'comment'),
        'service_col' => isset($review_cols['service_type']) ? 'service_type' : '',
        'created_col' => isset($review_cols['created_at']) ? 'created_at' : '',
    ];

    return $schema;
}

function printflow_service_name_aliases(string $name): array
{
    $raw = trim(preg_replace('/\s+/', ' ', $name));
    if ($raw === '') {
        return [];
    }

    $normalized = normalize_service_name($raw, $raw);
    $aliases = [$raw, $normalized];

    $alias_map = [
        'Tarpaulin Printing' => ['Tarpaulin', 'Tarp'],
        'T-Shirt Printing' => ['T-Shirt', 'Tshirt', 'Tshirt Printing'],
        'Decals/Stickers (Print/Cut)' => ['Sticker', 'Stickers', 'Decal', 'Decals'],
        'Sintraboard Standees' => ['Sintraboard', 'Sintra Board', 'Standee', 'Standees'],
        'Glass/Wall Stickers' => ['Glass Stickers / Wall / Frosted Stickers', 'Glass Sticker', 'Wall Sticker', 'Frosted Sticker'],
        'Transparent Stickers' => ['Transparent Sticker'],
        'Reflectorized' => ['Reflectorized Signage', 'Reflectorized (Subdivision Stickers/Signages)'],
        'Souvenirs' => ['Souvenir'],
    ];

    foreach ($alias_map as $canonical => $variants) {
        if ($raw === $canonical || $normalized === $canonical || in_array($raw, $variants, true) || in_array($normalized, $variants, true)) {
            $aliases[] = $canonical;
            foreach ($variants as $variant) {
                $aliases[] = $variant;
            }
        }
    }

    $aliases = array_values(array_unique(array_filter(array_map(static function ($value) {
        return trim((string)$value);
    }, $aliases), static fn($value) => $value !== '')));

    return $aliases;
}

function printflow_get_service_review_stats(string $service_name): array
{
    $schema = printflow_review_schema();
    $aliases = printflow_service_name_aliases($service_name);
    if (empty($aliases)) {
        return ['avg_rating' => 0.0, 'review_count' => 0];
    }

    $where_parts = [];
    $types = '';
    $params = [];

    if ($schema['service_col'] !== '') {
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $where_parts[] = "r.{$schema['service_col']} COLLATE utf8mb4_unicode_ci IN ($placeholders)";
        $types .= str_repeat('s', count($aliases));
        array_push($params, ...$aliases);
    }

    $order_match_parts = [];
    $order_placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $order_match_parts[] = "p.name COLLATE utf8mb4_unicode_ci IN ($order_placeholders)";
    $types .= str_repeat('s', count($aliases));
    array_push($params, ...$aliases);

    foreach ($aliases as $alias) {
        $order_match_parts[] = "oi.customization_data COLLATE utf8mb4_unicode_ci LIKE ?";
        $types .= 's';
        $params[] = '%' . $alias . '%';
    }

    $where_parts[] = "EXISTS (
        SELECT 1
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = r.order_id
          AND (" . implode(' OR ', $order_match_parts) . ")
    )";

    $rows = db_query(
        "SELECT AVG(r.rating) AS avg_rating, COUNT(DISTINCT r.id) AS review_count
         FROM reviews r
         WHERE " . implode(' OR ', $where_parts),
        $types,
        $params
    ) ?: [];

    return [
        'avg_rating' => (float)($rows[0]['avg_rating'] ?? 0),
        'review_count' => (int)($rows[0]['review_count'] ?? 0),
    ];
}

function printflow_get_service_reviews(string $service_name, ?int $limit = null, int $viewer_user_id = 0): array
{
    $schema = printflow_review_schema();
    $review_cols = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
    $tables = array_flip(array_column(db_query("SHOW TABLES") ?: [], 0));
    $aliases = printflow_service_name_aliases($service_name);
    if (empty($aliases)) {
        return [];
    }

    $where_parts = [];
    $types = '';
    $params = [];

    if ($schema['service_col'] !== '') {
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $where_parts[] = "r.{$schema['service_col']} COLLATE utf8mb4_unicode_ci IN ($placeholders)";
        $types .= str_repeat('s', count($aliases));
        array_push($params, ...$aliases);
    }

    $order_match_parts = [];
    $order_placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $order_match_parts[] = "p.name COLLATE utf8mb4_unicode_ci IN ($order_placeholders)";
    $types .= str_repeat('s', count($aliases));
    array_push($params, ...$aliases);

    foreach ($aliases as $alias) {
        $order_match_parts[] = "oi.customization_data COLLATE utf8mb4_unicode_ci LIKE ?";
        $types .= 's';
        $params[] = '%' . $alias . '%';
    }

    $where_parts[] = "EXISTS (
        SELECT 1
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = r.order_id
          AND (" . implode(' OR ', $order_match_parts) . ")
    )";

    $message_col = $schema['message_col'];
    $user_col = $schema['user_col'];
    $service_select = $schema['service_col'] !== '' ? "r.{$schema['service_col']}" : "''";
    $created_select = $schema['created_col'] !== '' ? "r.{$schema['created_col']}" : 'NOW()';
    $video_select = isset($review_cols['video_path']) ? 'r.video_path' : "''";
    $has_review_helpful = isset($tables['review_helpful']);
    $helpful_count_sql = $has_review_helpful
        ? "(SELECT COUNT(*) FROM review_helpful WHERE review_id = r.id) AS helpful_count,"
        : "0 AS helpful_count,";
    $helpful_voted_sql = ($has_review_helpful && $viewer_user_id > 0)
        ? "(SELECT COUNT(*) FROM review_helpful WHERE review_id = r.id AND user_id = ?) AS user_voted,"
        : "0 AS user_voted,";
    $profile_picture_select = isset($tables['customers']) ? 'c.profile_picture' : "'' AS profile_picture";

    $sql = "SELECT DISTINCT
            r.id,
            r.order_id,
            {$user_col} AS user_id,
            {$service_select} AS service_type,
            r.rating,
            {$message_col} AS comment,
            {$video_select} AS video_path,
            {$created_select} AS created_at,
            COALESCE(c.first_name, u.first_name, 'Customer') AS first_name,
            COALESCE(c.last_name, u.last_name, '') AS last_name,
            {$profile_picture_select},
            {$helpful_count_sql}
            {$helpful_voted_sql}
            {$message_col} AS review_message
        FROM reviews r
        LEFT JOIN customers c ON c.customer_id = r.{$user_col}
        LEFT JOIN users u ON u.user_id = r.{$user_col}
        WHERE " . implode(' OR ', $where_parts) . "
        ORDER BY {$created_select} DESC";

    if ($has_review_helpful && $viewer_user_id > 0) {
        $types = 'i' . $types;
        array_unshift($params, $viewer_user_id);
    }

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    return db_query($sql, $types, $params) ?: [];
}

function get_service_name_from_customization($custom, $fallback = 'Custom Order') {
    $custom = printflow_decode_modal_customization_payload($custom);
    if ($custom === []) return $fallback;

    $souvItem = trim((string)($custom['souvenir_type'] ?? ''));
    if ($souvItem !== '') {
        return 'Souvenir: ' . $souvItem;
    }

    $sidEarly = (int)($custom['service_id'] ?? 0);
    if ($sidEarly > 0) {
        $fromCatalog = customer_orders_resolve_service_name_by_id($sidEarly);
        if ($fromCatalog !== '') {
            return $fromCatalog;
        }
    }

    $explicit_service_label = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        // Ignore technical/generic labels that should not be shown as service names.
        $blocked = [
            'service',
            'service order',
            'service item',
            'order item',
            'custom order',
            'custom service',
            'pos service item',
            'pos-service',
            'pos_service',
            'pos',
        ];
        $normalized_raw = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $raw)));
        if (in_array($normalized_raw, $blocked, true)) {
            return '';
        }

        $normalized = normalize_service_name($raw, '');
        $known = [
            'Tarpaulin Printing',
            'T-Shirt Printing',
            'Decals/Stickers (Print/Cut)',
            'Sintraboard Standees',
            'Glass/Wall Stickers',
            'Transparent Stickers',
            'Reflectorized',
            'Souvenirs',
            'Layouts',
        ];
        // Keep canonical names for known services, but do not discard valid
        // custom service names configured by staff (e.g. "Mug Printing").
        if (in_array($normalized, $known, true)) {
            return $normalized;
        }
        return normalize_service_name($raw, $raw);
    };

    // Source of truth: if the customer explicitly selected a service, prefer it.
    $explicit_service = $explicit_service_label($custom['service_type'] ?? '');
    if ($explicit_service !== '') {
        return $explicit_service;
    }

    // `product_type` is often a subtype/detail (especially for Reflectorized),
    // so only trust it when it clearly names a real service.
    $explicit_product_service = $explicit_service_label($custom['product_type'] ?? '');
    if ($explicit_product_service !== '') {
        return $explicit_product_service;
    }
    
    // User Requested Priority Logic
    // 1. Sintra Board
    if (!empty($custom['sintra_type']) || !empty($custom['Sintra Type']) || !empty($custom['is_standee'])) {
        return 'Sintraboard Standees';
    }
    // 2. Tarpaulin Printing
    if (!empty($custom['tarp_size']) || !empty($custom['Tarp Size']) || (!empty($custom['width']) && !empty($custom['height']) && (!empty($custom['finish']) || !empty($custom['with_eyelets'])))) {
        return 'Tarpaulin Printing';
    }
    // 3. T-Shirt Printing
    if (!empty($custom['vinyl_type']) || !empty($custom['print_placement']) || !empty($custom['tshirt_color']) || !empty($custom['shirt_source'])) {
        return 'T-Shirt Printing';
    }
    // 3.5 Reflectorized
    if (
        !empty($custom['gate_pass_subdivision']) ||
        !empty($custom['gate_pass_number']) ||
        !empty($custom['gate_pass_plate']) ||
        !empty($custom['gate_pass_year']) ||
        !empty($custom['temp_plate_material']) ||
        !empty($custom['temp_plate_number']) ||
        !empty($custom['temp_plate_text']) ||
        !empty($custom['mv_file_number']) ||
        !empty($custom['dealer_name']) ||
        !empty($custom['material_type']) ||
        !empty($custom['reflective_color']) ||
        (!empty($custom['product_type']) && stripos((string)$custom['product_type'], 'reflectorized') !== false)
    ) {
        return 'Reflectorized';
    }
    // 4. Decals/Stickers — require sticker-specific fields; do not use `shape` alone (mug/merch forms reuse "shape").
    if (!empty($custom['sticker_type']) || !empty($custom['Sticker Type']) || !empty($custom['Cut_Type'])) {
        return 'Decals/Stickers (Print/Cut)';
    }

    return normalize_service_name($fallback, $fallback);
}

function printflow_customization_summary($custom, $fallback = 'Custom Service') {
    $custom = is_string($custom) ? json_decode($custom, true) : $custom;
    if (!is_array($custom)) {
        $custom = [];
    }

    $normalizeKey = static function ($key) {
        $key = strtolower(trim((string)$key));
        $key = str_replace(['-', '_'], ' ', $key);
        return preg_replace('/\s+/', ' ', $key);
    };

    $firstValue = static function (array $source, array $candidates) use ($normalizeKey) {
        $wanted = [];
        foreach ($candidates as $candidate) {
            $wanted[$normalizeKey($candidate)] = true;
        }
        foreach ($source as $key => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }
            if (isset($wanted[$normalizeKey($key)])) {
                return $value;
            }
        }
        return null;
    };

    $formatScalar = static function ($value): string {
        if ($value === null) {
            return '';
        }
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $number = (float)$value;
            if (abs($number - round($number)) < 0.00001) {
                return (string)(int)round($number);
            }
            $formatted = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
            return $formatted;
        }
        return $value;
    };

    $fallback = trim((string)$fallback);
    if ($fallback === '') {
        $fallback = 'Custom Service';
    }

    $service_type = get_service_name_from_customization($custom, $fallback);
    $job_title = printflow_resolve_order_item_name($fallback, $custom, $service_type);
    if (trim((string)$job_title) === '') {
        $job_title = $service_type;
    }

    $quantity_raw = $firstValue($custom, ['quantity', 'qty']);
    $quantity = is_numeric((string)$quantity_raw) ? max(1, (int)$quantity_raw) : 1;

    $width_ft = $formatScalar($firstValue($custom, ['width_ft', 'width']));
    $height_ft = $formatScalar($firstValue($custom, ['height_ft', 'height']));

    $dimension_raw = $firstValue($custom, [
        'dimensions',
        'dimension',
        'size',
        'size dimensions',
        'exact size',
        'tarp size',
        'size ft'
    ]);
    $dimension_text = trim((string)$dimension_raw);

    if (($width_ft === '' || $height_ft === '') && $dimension_text !== '') {
        $normalized_dimension = preg_replace('/\s*(ft|feet|in|inch|inches|cm|mm|m)\s*$/i', '', $dimension_text);
        $normalized_dimension = str_replace(['X', 'x', '*', '-'], '×', $normalized_dimension);
        if (preg_match('/(\d+(?:\.\d+)?)\s*×\s*(\d+(?:\.\d+)?)/u', $normalized_dimension, $m)) {
            if ($width_ft === '') {
                $width_ft = $formatScalar($m[1]);
            }
            if ($height_ft === '') {
                $height_ft = $formatScalar($m[2]);
            }
        }
    }

    return [
        'service_type' => $service_type,
        'job_title' => $job_title,
        'width_ft' => $width_ft,
        'height_ft' => $height_ft,
        'quantity' => $quantity,
    ];
}

function printflow_resolve_order_item_name($raw_name, $custom, $fallback = 'Order Item') {
    $custom = is_string($custom) ? json_decode($custom, true) : $custom;
    if (!is_array($custom)) {
        $custom = [];
    }

    $raw_name = trim((string)$raw_name);
    $generic_names = [
        'custom order',
        'customer order',
        'service order',
        'service item',
        'order item',
        'sticker pack',
        'merchandise',
        'pos service item',
        'pos-service item',
        'pos service',
        'pos-service'
    ];
    $raw_lower = strtolower($raw_name);
    $custom_name = normalize_service_name(get_service_name_from_customization($custom, $fallback), $fallback);

    if (!empty($custom['sintra_type'])) {
        return 'Sintra Board - ' . $custom['sintra_type'];
    }

    if (!empty($custom['tarp_size'])) {
        return 'Tarpaulin Printing - ' . $custom['tarp_size'];
    }

    if (!empty($custom['width']) && !empty($custom['height']) && $custom_name === 'Tarpaulin Printing') {
        return 'Tarpaulin Printing - ' . $custom['width'] . 'x' . $custom['height'] . 'ft';
    }

    if (!empty($custom['vinyl_type'])) {
        return 'T-Shirt Printing (Vinyl)';
    }

    if (!empty($custom['sticker_type'])) {
        return 'Decals/Stickers (Print/Cut)';
    }

    if ($raw_name === '' || in_array($raw_lower, $generic_names, true)) {
        return $custom_name;
    }

    if ($custom_name !== '' && $custom_name !== normalize_service_name($fallback, $fallback)) {
        if ($raw_name === '' || in_array($raw_lower, $generic_names, true)) {
            return $custom_name;
        }
        $service_keywords = [
            'Tarpaulin Printing' => ['tarpaulin', 'tarp'],
            'T-Shirt Printing' => ['t-shirt', 'tshirt', 'shirt', 'vinyl'],
            'T-Shirt Printing (Vinyl)' => ['t-shirt', 'tshirt', 'shirt', 'vinyl'],
            'Decals/Stickers (Print/Cut)' => ['sticker', 'stickers', 'decal', 'decals'],
            'Sintraboard Standees' => ['sintra', 'standee', 'sintraboard'],
            'Glass/Wall Stickers' => ['glass', 'wall', 'frosted', 'sticker'],
            'Transparent Stickers' => ['transparent', 'sticker'],
            'Reflectorized' => ['reflectorized', 'signage'],
            'Souvenirs' => ['souvenir', 'mug', 'pin', 'merchandise'],
        ];
        $keywords = $service_keywords[$custom_name] ?? [];
        if ($keywords !== []) {
            $matches_expected_service = false;
            foreach ($keywords as $keyword) {
                if (strpos($raw_lower, $keyword) !== false) {
                    $matches_expected_service = true;
                    break;
                }
            }
            if (!$matches_expected_service) {
                return $custom_name;
            }
        } else {
            // For custom services not in the fixed keyword map, trust the
            // resolved customization service label over placeholder product names.
            return $custom_name;
        }
    }

    return normalize_service_name($raw_name, $fallback);
}

/**
 * Service image mapping - SAME as Services page ($core_services).
 * Source of truth: /customer/services.php
 */
function get_services_image_map() {
    $base = defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : '/printflow');
    return [
        'tarpaulin'   => $base . '/public/images/products/product_42.jpg',
        't-shirt'     => $base . '/public/images/products/product_31.jpg',
        'shirt'       => $base . '/public/images/products/product_31.jpg',
        'stickers'    => $base . '/public/images/products/product_21.jpg',
        'sticker'     => $base . '/public/images/products/product_21.jpg',
        'decal'       => $base . '/public/images/products/product_21.jpg',
        'glass'       => $base . '/public/images/products/Glass Stickers  Wall  Frosted Stickers.png',
        'frosted'     => $base . '/public/images/products/Glass Stickers  Wall  Frosted Stickers.png',
        'wall'        => $base . '/public/images/products/Glass Stickers  Wall  Frosted Stickers.png',
        'transparent' => $base . '/public/images/products/product_26.jpg',
        'reflectorized' => $base . '/public/images/products/signage.jpg',
        'signage'     => $base . '/public/images/products/signage.jpg',
        'sintraboard' => $base . '/public/images/products/standeeflat.jpg',
        'standee'     => $base . '/public/images/products/standeeflat.jpg',
        'souvenir'   => $base . '/public/assets/images/services/souvenir.png',
    ];
}

/**
 * Get service image URL for Orders/Notifications - exact same images as Services page.
 * @param string $service_type_or_name e.g. "T-Shirt Printing", "Tarpaulin", "Custom T-Shirt"
 * @return string URL path to image (same file as Services page)
 */
function get_service_image_url($service_type_or_name) {
    $cat = strtolower(trim(preg_replace('/\s+/', ' ', (string)$service_type_or_name)));
    $base = defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : '/printflow');
    if ($cat === '') return $base . '/public/assets/images/services/default.png';

    $map = get_services_image_map();
    foreach ($map as $keyword => $img) {
        if (strpos($cat, $keyword) !== false) {
            return $img;
        }
    }

    return $base . '/public/assets/images/services/default.png';
}

/**
 * Normalize PH-style phone to digits for comparison (63 + national mobile).
 */
function normalize_contact_phone_digits($raw) {
    $d = preg_replace('/\D/', '', (string)$raw);
    if ($d === '') {
        return '';
    }
    if (strlen($d) >= 11 && $d[0] === '0' && ($d[1] ?? '') === '9') {
        $d = '63' . substr($d, 1);
    } elseif (strlen($d) === 10 && $d[0] === '9') {
        $d = '63' . $d;
    }
    return $d;
}

/**
 * Whether email is already used on `users` or `customers` (case-insensitive).
 * Pass exclusions when updating the same account row.
 */
function email_in_use_across_accounts($email, $exclude_customer_id = null, $exclude_user_id = null) {
    $email = trim((string)$email);
    if ($email === '') {
        return false;
    }
    $u = db_query('SELECT user_id FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1', 's', [$email]);
    if (!empty($u)) {
        $uid = (int)$u[0]['user_id'];
        if ($exclude_user_id === null || $uid !== (int)$exclude_user_id) {
            return true;
        }
    }
    $c = db_query('SELECT customer_id FROM customers WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1', 's', [$email]);
    if (!empty($c)) {
        $cid = (int)$c[0]['customer_id'];
        if ($exclude_customer_id === null || $cid !== (int)$exclude_customer_id) {
            return true;
        }
    }
    return false;
}

/**
 * Whether normalized phone matches another row's `contact_number` on users or customers.
 */
function contact_phone_in_use_across_accounts($raw, $exclude_customer_id = null, $exclude_user_id = null) {
    $norm = normalize_contact_phone_digits($raw);
    if ($norm === '' || strlen($norm) < 10) {
        return false;
    }
    $users = db_query("SELECT user_id, contact_number FROM users WHERE contact_number IS NOT NULL AND TRIM(contact_number) <> ''", '', []);
    foreach ($users ?: [] as $row) {
        if ($exclude_user_id !== null && (int)$row['user_id'] === (int)$exclude_user_id) {
            continue;
        }
        if (normalize_contact_phone_digits($row['contact_number']) === $norm) {
            return true;
        }
    }
    $custs = db_query("SELECT customer_id, contact_number FROM customers WHERE contact_number IS NOT NULL AND TRIM(contact_number) <> ''", '', []);
    foreach ($custs ?: [] as $row) {
        if ($exclude_customer_id !== null && (int)$row['customer_id'] === (int)$exclude_customer_id) {
            continue;
        }
        if (normalize_contact_phone_digits($row['contact_number']) === $norm) {
            return true;
        }
    }
    return false;
}

/**
 * Application base path (e.g. /printflow). Uses AUTH_REDIRECT_BASE when defined.
 */
function pf_app_base_path(): string {
    $base = rtrim(defined('AUTH_REDIRECT_BASE') ? AUTH_REDIRECT_BASE : '/printflow', '/');
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && strpos($host, 'mrandmrsprintflow.com') !== false && $base === '/printflow') {
        return '';
    }
    return $base;
}

/**
 * Resolve a stored review video path to an on-disk file path if it still exists.
 */
function pf_resolve_review_video_file(string $video_path): string
{
    $normalized = trim(str_replace('\\', '/', $video_path));
    if ($normalized === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $normalized)) {
        $parts = parse_url($normalized);
        $normalized = (string)($parts['path'] ?? '');
    }

    if ($normalized === '') {
        return '';
    }

    $normalized = urldecode($normalized);
    $normalized = preg_replace('#^[A-Za-z]:#', '', $normalized);
    $normalized = preg_replace('#^/printflow#i', '', $normalized);
    $normalized = '/' . ltrim($normalized, '/');

    $candidates = [];

    $uploads_pos = strpos($normalized, '/uploads/');
    if ($uploads_pos !== false) {
        $relative = ltrim(substr($normalized, $uploads_pos + 9), '/');
        $candidates[] = __DIR__ . '/../uploads/' . $relative;
    }

    $public_pos = strpos($normalized, '/public/');
    if ($public_pos !== false) {
        $relative = ltrim(substr($normalized, $public_pos + 8), '/');
        $candidates[] = __DIR__ . '/../public/' . $relative;
    }

    $basename = basename($normalized);
    if ($basename !== '' && $basename !== '.' && $basename !== '/') {
        $candidates[] = __DIR__ . '/../uploads/reviews_videos/' . $basename;
        $candidates[] = __DIR__ . '/../public/uploads/reviews_videos/' . $basename;
        $candidates[] = __DIR__ . '/../public/assets/uploads/reviews_videos/' . $basename;
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        $path = $real !== false ? $real : $candidate;
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

/**
 * Encode URL path segments while preserving slashes and optional query/fragment.
 */
function pf_encode_url_path(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return $url;
    }

    if (preg_match('#^https?://#i', $url)) {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        $path = implode('/', array_map('rawurlencode', array_map('rawurldecode', explode('/', $path))));
        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $path;
        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    $fragment = '';
    $query = '';
    $path = $url;

    $hash_pos = strpos($path, '#');
    if ($hash_pos !== false) {
        $fragment = substr($path, $hash_pos);
        $path = substr($path, 0, $hash_pos);
    }

    $query_pos = strpos($path, '?');
    if ($query_pos !== false) {
        $query = substr($path, $query_pos);
        $path = substr($path, 0, $query_pos);
    }

    $segments = explode('/', $path);
    $encoded = [];
    foreach ($segments as $index => $segment) {
        if ($segment === '' && ($index === 0 || $index === count($segments) - 1)) {
            $encoded[] = $segment;
            continue;
        }
        $encoded[] = rawurlencode(rawurldecode($segment));
    }

    return implode('/', $encoded) . $query . $fragment;
}

/**
 * Build direct public review-video URLs, avoiding PHP file serving in normal playback.
 */
function pf_review_video_direct_candidates(string $video_path, ?string $base_path = null): array
{
    $raw = trim($video_path);
    if ($raw === '') {
        return [];
    }

    $base = rtrim((string)($base_path ?? pf_app_base_path()), '/');
    $variants = [];

    $normalized = str_replace('\\', '/', $raw);
    if (preg_match('#^https?://#i', $normalized)) {
        $variants[] = pf_encode_url_path($normalized);
    } else {
        if (preg_match('#^[A-Za-z]:/#', $normalized)) {
            $normalized = preg_replace('#^[A-Za-z]:#', '', $normalized);
        }
        if (strpos($normalized, '/printflow/') === 0) {
            $normalized = substr($normalized, strlen('/printflow'));
        }

        $uploads_pos = strpos($normalized, '/uploads/');
        $public_pos = strpos($normalized, '/public/');
        if ($uploads_pos !== false) {
            $normalized = substr($normalized, $uploads_pos);
        } elseif ($public_pos !== false) {
            $normalized = substr($normalized, $public_pos);
        } elseif ($normalized !== '' && $normalized[0] !== '/') {
            $normalized = '/' . ltrim($normalized, '/');
        }

        if ($normalized !== '') {
            $variants[] = $normalized;
            if (strpos($normalized, '/public/') === 0) {
                $variants[] = substr($normalized, strlen('/public'));
            }
        }

        $basename = basename($normalized !== '' ? $normalized : $raw);
        if ($basename !== '' && $basename !== '.' && $basename !== '/') {
            $variants[] = '/uploads/reviews_videos/' . $basename;
            $variants[] = '/public/uploads/reviews_videos/' . $basename;
            $variants[] = '/public/assets/uploads/reviews_videos/' . $basename;
        }
    }

    $resolved = pf_resolve_review_video_file($raw);
    if ($resolved !== '') {
        $resolved = str_replace('\\', '/', $resolved);
        $uploads_root = str_replace('\\', '/', realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads'));
        $public_root = str_replace('\\', '/', realpath(__DIR__ . '/../public') ?: (__DIR__ . '/../public'));

        if (strpos($resolved, $uploads_root . '/') === 0) {
            $variants[] = '/uploads/' . ltrim(substr($resolved, strlen($uploads_root) + 1), '/');
        } elseif (strpos($resolved, $public_root . '/') === 0) {
            $variants[] = '/public/' . ltrim(substr($resolved, strlen($public_root) + 1), '/');
        }
    }

    $clean = [];
    foreach ($variants as $variant) {
        $variant = trim((string)$variant);
        if ($variant === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $variant) && $variant[0] !== '/') {
            $variant = '/' . ltrim($variant, '/');
        }
        if ($base !== '' && !preg_match('#^https?://#i', $variant) && strpos($variant, $base . '/') !== 0) {
            $variant = $base . $variant;
        }
        $clean[pf_encode_url_path($variant)] = true;
    }

    return array_keys($clean);
}

/**
 * Build an absolute app URL for a script under admin/ with optional query and fragment.
 *
 * @param string $script File name (e.g. orders_management.php) or path starting with admin/
 * @param array  $query  Query parameters
 * @param string|null $fragment Hash without leading #
 */
function pf_admin_url(string $script, array $query = [], ?string $fragment = null): string {
    $script = ltrim($script, '/');
        $path = (strpos($script, 'admin/') === 0) ? $script : ('admin/' . $script);
    $url = pf_app_base_path() . '/' . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    if ($fragment !== null && $fragment !== '') {
        $url .= '#' . ltrim($fragment, '#');
    }
    return $url;
}

/**
 * Send an automated order update message to the chat.
 *
 * @param int    $order_id
 * @param string $step         'inquiry', 'approved', 'send_to_payment', 'payment_verified',
 *                             'payment_rejected', 'in_production', 'ready_to_pickup', 'completed'
 * @param string $custom_text  Optional override message text
 * @return bool|int
 */
if (!function_exists('printflow_send_order_update')) {
    require_once __DIR__ . '/order_chat_system.php';
}

function printflow_send_order_update_legacy($order_id, $step, $custom_text = '', $additional_meta = []) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return false;

    // 1. Get order + customer details
    $order = db_query(
        "SELECT o.*, c.first_name, c.last_name
         FROM orders o
         LEFT JOIN customers c ON c.customer_id = o.customer_id
         WHERE o.order_id = ?",
        'i', [$order_id]
    );
    if (empty($order)) return false;
    $order = $order[0];

    // 2. Get first item + product image for thumbnail
    $item = db_query(
        "SELECT oi.*, p.name as product_name, p.photo_path
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ? LIMIT 1",
        'i', [$order_id]
    );
    $item = !empty($item) ? $item[0] : null;

    $base          = printflow_notification_base_path();
    $customization = [];
    if (!empty($item['customization_data'])) {
        $customization = printflow_decode_modal_customization_payload((string)$item['customization_data']);
    }
    $service_type = trim((string)($customization['service_type'] ?? ''));
    $size_hint = trim((string)($customization['size'] ?? $customization['dimensions'] ?? ''));
    $product_base = $service_type !== '' ? $service_type : (trim((string)($item['product_name'] ?? '')) ?: 'Your Order');
    $product_name = $size_hint !== '' ? ($product_base . ' (' . $size_hint . ')') : $product_base;
    $amount        = number_format((float)($order['total_amount'] ?? 0), 2);
    $customer_name = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: 'Customer';

    // 3. Resolve thumbnail
    $thumbnail = '';
    if (!empty($item['photo_path'])) {
        $thumbnail = $item['photo_path'];
        if (!preg_match('#^https?://#i', $thumbnail) && strpos($thumbnail, $base) !== 0) {
            $thumbnail = $base . '/' . ltrim($thumbnail, '/');
        }
    } else {
        // Try fetching from services table via customization_data service_type
        $svc_data = db_query(
            "SELECT s.image_path
             FROM order_items oi
             LEFT JOIN services s ON LOWER(TRIM(s.name)) LIKE CONCAT('%', LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(oi.customization_data, '$.service_type')))), '%')
             WHERE oi.order_id = ? AND s.image_path IS NOT NULL LIMIT 1",
            'i', [$order_id]
        );
        if (!empty($svc_data) && !empty($svc_data[0]['image_path'])) {
            $thumbnail = $svc_data[0]['image_path'];
            if (!preg_match('#^https?://#i', $thumbnail) && strpos($thumbnail, $base) !== 0) {
                $thumbnail = $base . '/' . ltrim($thumbnail, '/');
            }
        } else {
            $thumbnail = $base . '/public/assets/images/services/default.png';
        }
    }

    // 4. Step -> message, action_type, action_url, message_type
    $step_configs = [ // UPDATED
        'inquiry' => [
            'message'      => "Hi! I have an inquiry about an order. Please check and assist me. Thank you!",
            'message_type' => 'order_card',
            'action_type' => 'view_inquiry',
            'action_url'  => '',
        ],
        'approved' => [
            'message'      => "Your inquiry has been approved! We're now preparing the final price based on your request.",
            'message_type' => 'text',
            'action_type' => 'view_details',
            'action_url'  => '',
        ],
        'send_to_payment' => [
            'message'      => "Your order is ready for payment! We've finalized the details and pricing for your request.",
            'message_type' => 'order_card',
            'button_label' => 'Proceed to Payment',
            'action_type' => 'to_payment',
            'action_url'  => "{$base}/customer/payment.php?order_id={$order_id}",
        ],
        'payment_submitted' => [
            'message'      => "We have received your payment. It is now under verification.",
            'message_type' => 'order_update',
            'action_type' => 'verify_payment',
            'action_url'  => '',
        ],
        'payment_verified' => [
            'message'      => "Your payment has been approved. We will now proceed with processing your order.",
            'message_type' => 'order_update',
            'action_type' => 'view_status',
            'action_url'  => '',
        ],
        'payment_rejected' => [
            'message'      => "Your payment proof was rejected. Reason: {reason}. Please resubmit your payment proof.",
            'message_type' => 'order_card',
            'button_label' => 'Upload Payment Again',
            'action_type' => 'retry_payment',
            'action_url'  => "{$base}/customer/payment.php?order_id={$order_id}",
        ],
        'in_production' => [
            'message'      => "Your payment has been approved. We will now proceed with processing your order.",
            'message_type' => 'order_update',
            'action_type'  => 'view_status',
            'action_url'   => '',
        ],
        'for_revision' => [
            'message'      => "Revision required: {reason}. Please check the requirements and resubmit.",
            'message_type' => 'order_card',
            'action_type' => 'view_details',
            'action_url'  => '',
        ],

        'ready_to_pickup' => [
            'message'      => "Order Ready! Your order is now ready for pickup. Thank you for choosing Mr. and Mrs. Print!",
            'message_type' => 'order_update',
            'action_type' => 'pickup_details',
            'action_url'  => '',
        ],
        'completed' => [
            'message'      => "Order Completed. Your order has been successfully picked up. We hope to see you again!",
            'message_type' => 'order_card',
            'button_label' => 'Leave a Review',
            'action_type' => 'rate',
            'action_url'  => "{$base}/customer/rate_order.php?order_id={$order_id}",
        ],
        'cancelled' => [
            'message'      => "Your order has been cancelled. Please contact our team if you need help with the next step.",
            'message_type' => 'order_update',
            'action_type' => 'view_status',
            'action_url'  => '',
        ],
        'rate' => [
            'message'      => "How was your experience? Please rate your order.",
            'message_type' => 'order_card',
            'button_label' => 'Leave a Review',
            'action_type' => 'rate',
            'action_url'  => "{$base}/customer/rate_order.php?order_id={$order_id}",
        ],
    ];

    // Legacy step aliases for backward compatibility
    $aliases = [
        'approved_with_price' => 'send_to_payment',
        'ready'               => 'ready_to_pickup',
        'rate_order'          => 'rate',
    ];
    if (isset($aliases[$step])) {
        $step = $aliases[$step];
    }

    $config = $step_configs[$step] ?? [
        'message'     => "Your order status has been updated.",
        'action_type' => 'view_status',
        'action_url'  => '',
    ];

    if ($step === 'send_to_payment') {
        $config['message'] = "Your order is now ready for payment. Please proceed to complete your transaction.";
    }

    if (($step === 'payment_rejected' || $step === 'for_revision') && !empty($additional_meta['reason'])) {
        $config['message'] = str_replace('{reason}', $additional_meta['reason'], $config['message']);
    }


    $message      = $custom_text ?: $config['message'];
    $message_type = $config['message_type'] ?? 'text';
    $button_label = $config['button_label'] ?? '';
    $action_type = $config['action_type'];
    $action_url  = $config['action_url'];
    $origin_actor_map = [
        'inquiry' => 'customer',
        'payment_submitted' => 'staff',
        'approved' => 'staff',
        'send_to_payment' => 'staff',
        'payment_verified' => 'staff',
        'payment_rejected' => 'staff',
        'in_production' => 'staff',
        'for_revision' => 'staff',
        'inquiry_rejected' => 'staff',

        'ready_to_pickup' => 'staff',
        'completed' => 'staff',
        'rate' => 'staff',
    ];
    $origin_actor = $origin_actor_map[$step] ?? 'staff';
    $session_user_type = function_exists('get_user_type') ? (string) get_user_type() : '';

    // Prefer the mapped origin actor (authoritative). Only fall back to the
    // current session user type if origin actor is not specified.
    $sender_type = $origin_actor ?: '';
    if (empty($sender_type)) {
        if ($session_user_type === 'Customer') {
            $sender_type = 'customer';
        } elseif (in_array($session_user_type, ['Staff', 'Admin', 'Manager'], true)) {
            $sender_type = 'staff';
        } else {
            $sender_type = 'staff';
        }
    }

    $db_sender = $sender_type === 'customer' ? 'Customer' : 'Staff';
    $sender_id = 0;
    if ($sender_type === 'customer') {
        $sender_id = (int) ($order['customer_id'] ?? 0);
        if ($session_user_type === 'Customer' && function_exists('get_user_id')) {
            $sender_id = (int) get_user_id();
        }
    } elseif ($sender_type === 'staff') {
        // Only attach a specific staff sender_id when the session user is a staff member.
        if (in_array($session_user_type, ['Staff', 'Admin', 'Manager'], true) && function_exists('get_user_id')) {
            $sender_id = max(0, (int) get_user_id());
        } else {
            $sender_id = 0; // system/staff generic
        }
    }

    // 5. meta_json for extra context
    $final_meta = [
        'step'         => $step,
        'order_id'     => $order_id,
        'product_name' => $product_name,
        'amount'       => (float)($order['total_amount'] ?? 0),
        'origin_actor' => $sender_type,
        'sender_type'  => $sender_type,
        'order_status'   => (string)($order['status'] ?? ''),
        'payment_status' => (string)($order['payment_status'] ?? ''),
        'thumbnail'      => $thumbnail,
        'button_label'   => $button_label,
        'action_url'     => $action_url,
    ];

    if (!empty($additional_meta) && is_array($additional_meta)) {
        $final_meta = array_merge($final_meta, $additional_meta);
    }

    $meta = json_encode($final_meta);

    // 6. Avoid duplicate/legacy order_card messages for the same order.
    // If this is an 'inquiry' step, remove legacy messages that use the
    // old "sent an inquiry for" wording to prevent unrelated duplicates
    // from showing up in the chat UI.
    if ($step === 'inquiry') {
        try {
            db_execute(
                "DELETE FROM order_messages WHERE order_id = ? AND message LIKE ? AND message_type = 'order_card'",
                'is',
                [$order_id, '%sent an inquiry for%']
            );
        } catch (Throwable $e) {
            // Non-fatal - continue
        }
    }

    // Prevent inserting the same order update repeatedly: look for an
    // existing recent order message of the same type and similar text.
    try {
        $shortText = mb_substr((string)$message, 0, 80);
        $existing = db_query(
            "SELECT message_id FROM order_messages WHERE order_id = ? AND message_type = ? AND message LIKE ? ORDER BY created_at DESC LIMIT 1",
            'iss',
            [$order_id, $message_type, $shortText . '%']
        );
        if (!empty($existing) && !empty($existing[0]['message_id'])) {
            // Return existing message id to indicate no new insert performed
            return (int)$existing[0]['message_id'];
        }
    } catch (Throwable $e) {
        // On error, fall back to inserting the message
    }

    // 7. Insert into order_messages using dedicated schema columns
    $sql = "INSERT INTO order_messages
                (order_id, sender, sender_id, message, message_type, thumbnail, action_type, action_url, meta_json, read_receipt)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";

    return db_execute($sql, 'isissssss', [
        $order_id,
        $db_sender,
        $sender_id,
        $message,
        $message_type,
        $thumbnail,
        $action_type,
        $action_url,
        $meta,
    ]);
}
