<?php
/**
 * push_helper.php ? Web Push dispatch helpers.
 * Requires: includes/WebPush.php, includes/db.php
 */

if (!class_exists('WebPush')) {
    require_once __DIR__ . '/WebPush.php';
}
require_once __DIR__ . '/vapid_bootstrap.php';
require_once __DIR__ . '/push_debug_helper.php';

/**
 * Return a WebPush instance using the stored VAPID config.
 * Returns null if VAPID keys are not configured yet.
 */
function get_webpush(): ?WebPush
{
    static $instance = null;
    if ($instance !== null) return $instance;

    $cfg = printflow_vapid_config();
    if (empty($cfg['public_key']) || empty($cfg['private_key'])) return null;

    $instance = new WebPush(
        $cfg['subject']     ?? 'mailto:admin@printflow.com',
        $cfg['public_key'],
        $cfg['private_key']
    );
    return $instance;
}

/**
 * Build a notification URL based on type and context.
 */
function push_url_for_type(string $type, ?int $data_id, string $user_type): string
{
    $base = function_exists('printflow_notification_base_path')
        ? printflow_notification_base_path()
        : '/printflow';
    if ($base === '') {
        $base = '';
    }

    switch ($type) {
        case 'Order':
        case 'New Order':
            // Redirect to chat when order-related (data_id = order_id)
            if ($data_id && $user_type === 'Customer') {
                return $base . '/customer/chat.php?order_id=' . $data_id;
            }
            if ($user_type === 'Customer') {
                return $base . '/customer/orders.php';
            }
            if ($user_type === 'Staff' || $user_type === 'Manager') {
                if ($data_id) {
                    return $base . '/staff/order_details.php?id=' . (int)$data_id;
                }
                return $base . '/staff/notifications.php';
            }
            return $base . '/admin/orders_management.php';
        case 'Job Order':
            return $user_type === 'Customer'
                ? $base . '/customer/new_job_order.php'
                : (($user_type === 'Staff' || $user_type === 'Manager')
                    ? ($data_id
                        ? (function_exists('printflow_staff_order_management_url')
                            ? printflow_staff_order_management_url((int)$data_id, true)
                            : $base . '/staff/customizations.php?order_id=' . (int)$data_id . '&job_type=ORDER')
                        : $base . '/staff/customizations.php')
                    : $base . '/admin/orders_management.php');
        case 'Chat':
        case 'Message':
            if ($user_type === 'Customer') {
                return $data_id
                    ? $base . '/customer/chat.php?order_id=' . $data_id
                    : $base . '/customer/messages.php';
            }
            if ($user_type === 'Staff' || $user_type === 'Manager') {
                return $data_id
                    ? $base . '/staff/chats.php?order_id=' . (int)$data_id
                    : $base . '/staff/chats.php';
            }
            return $data_id
                ? $base . '/admin/orders_management.php?order_id=' . (int)$data_id
                : $base . '/admin/notifications.php';
        case 'Stock':
        case 'Inventory':
            if ($user_type === 'Manager') return $base . '/manager/inventory_items.php';
            if ($user_type === 'Staff') return $base . '/staff/notifications.php';
            return $base . '/admin/inv_items_management.php';
        case 'Design':
        case 'Customization':
            if ($data_id && $user_type === 'Customer') {
                return $base . '/customer/chat.php?order_id=' . $data_id;
            }
            if ($user_type === 'Staff' || $user_type === 'Manager') {
                return $data_id
                    ? (function_exists('printflow_staff_order_management_url')
                        ? printflow_staff_order_management_url((int)$data_id, true)
                        : $base . '/staff/customizations.php?order_id=' . (int)$data_id . '&job_type=ORDER')
                    : $base . '/staff/customizations.php';
            }
            return $base . '/admin/orders_management.php';
        case 'Payment':
        case 'Payment Issue':
            if ($user_type === 'Customer') {
                return $data_id
                    ? $base . '/customer/orders.php?highlight=' . (int)$data_id
                    : $base . '/customer/notifications.php';
            }
            if ($user_type === 'Staff' || $user_type === 'Manager') {
                return $data_id
                    ? $base . '/staff/payment_verification.php?submission_id=' . (int)$data_id
                    : $base . '/staff/payment_verification.php';
            }
            return $base . '/admin/notifications.php';
        case 'Rating':
        case 'Review':
            if ($user_type === 'Customer') return $base . '/customer/reviews.php';
            if ($user_type === 'Staff') return $base . '/staff/reviews.php';
            if ($user_type === 'Manager') return $base . '/manager/notifications.php';
            return $base . '/admin/notifications.php';
        case 'Status':
            if ($user_type === 'Customer') {
                return $data_id
                    ? $base . '/customer/orders.php?highlight=' . (int)$data_id
                    : $base . '/customer/notifications.php';
            }
            if ($user_type === 'Staff' || $user_type === 'Manager') {
                return $data_id
                    ? $base . '/staff/orders.php?order_id=' . (int)$data_id
                    : $base . '/staff/notifications.php';
            }
            return $base . '/admin/notifications.php';
        case 'Profile':
            return $base . '/admin/user_staff_management.php';
        case 'System':
            if ($user_type === 'Customer') return $base . '/customer/notifications.php';
            if ($user_type === 'Manager') return $base . '/manager/notifications.php';
            if ($user_type === 'Staff') return $base . '/staff/notifications.php';
            return $base . '/admin/notifications.php';
        default:
            if ($user_type === 'Customer') return $base . '/customer/notifications.php';
            if ($user_type === 'Manager') return $base . '/manager/notifications.php';
            if ($user_type === 'Staff') return $base . '/staff/notifications.php';
            return $base . '/admin/notifications.php';
    }
}

/**
 * Push a notification payload to every subscribed device of one user.
 *
 * @param  int    $user_id
 * @param  string $user_type   'Customer' | 'Admin' | 'Staff' | ...
 * @param  array  $payload     ['title', 'body', 'url', 'tag', 'icon']
 * @param  int    $ttl
 * @return int    Number of successful pushes
 */
function push_notify_user(int $user_id, string $user_type, array $payload, int $ttl = 86400): int
{
    $result = push_dispatch_user($user_id, $user_type, $payload, $ttl);
    return (int)($result['sent'] ?? 0);
}

/**
 * Push a notification payload to every subscribed device of one user and
 * return delivery diagnostics for retry/logging decisions.
 *
 * @return array{
 *   user_id:int,
 *   user_type:string,
 *   subscriptions:int,
 *   sent:int,
 *   expired:int,
 *   failed:int,
 *   last_error:string
 * }
 */
function push_dispatch_user(int $user_id, string $user_type, array $payload, int $ttl = 86400): array
{
    printflow_push_debug_log('push_dispatch_start', [
        'ttl' => $ttl,
        'payload_title' => (string)($payload['title'] ?? ''),
        'payload_tag' => (string)($payload['tag'] ?? ''),
        'payload_url' => (string)($payload['url'] ?? ''),
    ], $user_id, $user_type);

    $wp = get_webpush();
    if (!$wp) {
        printflow_push_debug_log('push_dispatch_no_webpush', [], $user_id, $user_type);
        return [
            'user_id' => $user_id,
            'user_type' => $user_type,
            'subscriptions' => 0,
            'sent' => 0,
            'expired' => 0,
            'failed' => 0,
            'last_error' => 'webpush_unavailable',
        ];
    }

    $rows = db_query(
        'SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions
         WHERE user_id = ? AND user_type = ?',
        'is',
        [$user_id, $user_type]
    );
    if (empty($rows)) {
        printflow_push_debug_log('push_dispatch_no_subscription', [], $user_id, $user_type);
        return [
            'user_id' => $user_id,
            'user_type' => $user_type,
            'subscriptions' => 0,
            'sent' => 0,
            'expired' => 0,
            'failed' => 0,
            'last_error' => 'no_subscriptions',
        ];
    }

    $base = function_exists('printflow_notification_base_path')
        ? printflow_notification_base_path()
        : '/printflow';
    if ($base === '') {
        $base = '';
    }

    // Defaults
    $payload += [
        'title' => 'PrintFlow',
        'icon'  => $base . '/public/assets/images/icon-192.png',
        'badge' => $base . '/public/assets/images/icon-72.png',
        'url'   => $base . '/',
    ];

    $sent = 0;
    $expired = 0;
    $failed = 0;
    $lastError = '';
    foreach ($rows as $row) {
        $subscriptionId = (int)($row['id'] ?? 0);
        $endpoint = (string)($row['endpoint'] ?? '');
        try {
            $ok = $wp->send(
                ['endpoint' => $endpoint, 'p256dh' => $row['p256dh'], 'auth' => $row['auth_key']],
                $payload,
                $ttl
            );
            if ($ok) {
                $sent++;
                printflow_push_debug_log('push_dispatch_sent', [
                    'subscription_id' => $subscriptionId,
                ], $user_id, $user_type, $endpoint);
            } else {
                // One immediate retry helps with intermittent push gateway failures.
                $okRetry = $wp->send(
                    ['endpoint' => $endpoint, 'p256dh' => $row['p256dh'], 'auth' => $row['auth_key']],
                    $payload,
                    $ttl
                );
                if ($okRetry) {
                    $sent++;
                    printflow_push_debug_log('push_dispatch_sent_retry', [
                        'subscription_id' => $subscriptionId,
                    ], $user_id, $user_type, $endpoint);
                } else {
                    $failed++;
                    $lastError = 'push_send_failed';
                    printflow_push_debug_log('push_dispatch_failed', [
                        'subscription_id' => $subscriptionId,
                        'error' => $lastError,
                    ], $user_id, $user_type, $endpoint);
                }
            }
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'subscription_expired' || $e->getMessage() === 'subscription_invalid_auth') {
                db_execute('DELETE FROM push_subscriptions WHERE id = ?', 'i', [$subscriptionId]);
                $expired++;
                if ($e->getMessage() === 'subscription_invalid_auth') {
                    $lastError = 'subscription_invalid_auth';
                }
                printflow_push_debug_log('push_dispatch_subscription_removed', [
                    'subscription_id' => $subscriptionId,
                    'error' => $e->getMessage(),
                ], $user_id, $user_type, $endpoint);
            } else {
                error_log('[push_notify_user] Unexpected error: ' . $e->getMessage());
                $failed++;
                $lastError = $e->getMessage();
                printflow_push_debug_log('push_dispatch_runtime_exception', [
                    'subscription_id' => $subscriptionId,
                    'error' => $lastError,
                ], $user_id, $user_type, $endpoint);
            }
        } catch (Throwable $e) {
            error_log('[push_notify_user] Fatal push error: ' . $e->getMessage());
            $failed++;
            $lastError = $e->getMessage();
            printflow_push_debug_log('push_dispatch_fatal', [
                'subscription_id' => $subscriptionId,
                'error' => $lastError,
            ], $user_id, $user_type, $endpoint);
        }
    }

    $result = [
        'user_id' => $user_id,
        'user_type' => $user_type,
        'subscriptions' => count($rows),
        'sent' => $sent,
        'expired' => $expired,
        'failed' => $failed,
        'last_error' => $lastError,
    ];

    printflow_push_debug_log('push_dispatch_result', $result, $user_id, $user_type);

    return $result;
}

/**
 * Push to ALL admin/staff users (useful for order alerts).
 *
 * @param  string[] $user_types  e.g. ['Admin', 'Staff']
 * @param  array    $payload
 * @return int
 */
function push_notify_role(array $user_types, array $payload, int $ttl = 86400): int
{
    $wp = get_webpush();
    if (!$wp) return 0;

    $placeholders = implode(',', array_fill(0, count($user_types), '?'));
    $types        = str_repeat('s', count($user_types));
    $rows = db_query(
        "SELECT id, user_id, user_type, endpoint, p256dh, auth_key
         FROM push_subscriptions WHERE user_type IN ($placeholders)",
        $types,
        $user_types
    );
    if (empty($rows)) return 0;

    $base = function_exists('printflow_notification_base_path')
        ? printflow_notification_base_path()
        : '/printflow';
    if ($base === '') {
        $base = '';
    }

    $payload += [
        'title' => 'PrintFlow',
        'icon'  => $base . '/public/assets/images/icon-192.png',
        'badge' => $base . '/public/assets/images/icon-72.png',
        'url'   => $base . '/',
    ];

    $sent = 0;
    foreach ($rows as $row) {
        try {
            $ok = $wp->send(
                ['endpoint' => $row['endpoint'], 'p256dh' => $row['p256dh'], 'auth' => $row['auth_key']],
                $payload,
                $ttl
            );
            if ($ok) $sent++;
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'subscription_expired' || $e->getMessage() === 'subscription_invalid_auth') {
                db_execute('DELETE FROM push_subscriptions WHERE id = ?', 'i', [(int)$row['id']]);
            }
        }
    }
    return $sent;
}
