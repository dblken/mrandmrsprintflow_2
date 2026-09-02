<?php
/**
 * Shared authorization and request helpers for the HTTP/MySQL chat system.
 */

if (!function_exists('printflow_chat_json')) {
    function printflow_chat_json(array $payload, int $status = 200): void
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('printflow_chat_require_login')) {
    function printflow_chat_require_login(): void
    {
        if (!is_logged_in()) {
            printflow_chat_json(['success' => false, 'error' => 'Unauthorized'], 401);
        }
    }
}

if (!function_exists('printflow_chat_require_post')) {
    function printflow_chat_require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            printflow_chat_json(['success' => false, 'error' => 'Method not allowed'], 405);
        }
    }
}

if (!function_exists('printflow_chat_require_csrf')) {
    function printflow_chat_require_csrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!verify_csrf_token($token)) {
            printflow_chat_json(['success' => false, 'error' => 'Invalid CSRF token'], 419);
        }
    }
}

if (!function_exists('printflow_chat_authorize_order')) {
    function printflow_chat_authorize_order(int $order_id): array
    {
        if ($order_id <= 0) {
            printflow_chat_json(['success' => false, 'error' => 'Missing order ID'], 422);
        }

        $rows = db_query(
            'SELECT order_id, customer_id, branch_id FROM orders WHERE order_id = ? LIMIT 1',
            'i',
            [$order_id]
        );
        if (empty($rows)) {
            printflow_chat_json(['success' => false, 'error' => 'Conversation not found'], 404);
        }

        $order = $rows[0];
        if (get_user_type() === 'Customer') {
            if ((int)$order['customer_id'] !== (int)get_user_id()) {
                printflow_chat_json(['success' => false, 'error' => 'Conversation access denied'], 403);
            }
        } else {
            printflow_assert_order_branch_access($order_id);
        }

        return $order;
    }
}

if (!function_exists('printflow_chat_message_order')) {
    function printflow_chat_message_order(int $message_id): int
    {
        $rows = db_query('SELECT order_id FROM order_messages WHERE message_id = ? LIMIT 1', 'i', [$message_id]);
        if (empty($rows)) {
            printflow_chat_json(['success' => false, 'error' => 'Message not found'], 404);
        }
        $order_id = (int)$rows[0]['order_id'];
        printflow_chat_authorize_order($order_id);
        return $order_id;
    }
}

if (!function_exists('printflow_chat_validate_reply')) {
    function printflow_chat_validate_reply(?int $reply_id, int $order_id): void
    {
        if (!$reply_id) {
            return;
        }
        $rows = db_query(
            'SELECT 1 FROM order_messages WHERE message_id = ? AND order_id = ? LIMIT 1',
            'ii',
            [$reply_id, $order_id]
        );
        if (empty($rows)) {
            printflow_chat_json(['success' => false, 'error' => 'Reply must reference a message in this conversation'], 422);
        }
    }
}

if (!function_exists('printflow_chat_rate_limit')) {
    function printflow_chat_rate_limit(string $bucket, int $max_actions, int $window_seconds): void
    {
        $now = time();
        $key = 'pf_chat_rate_' . preg_replace('/[^a-z0-9_-]/i', '', $bucket);
        $events = array_values(array_filter(
            (array)($_SESSION[$key] ?? []),
            static fn($timestamp): bool => (int)$timestamp > ($now - $window_seconds)
        ));
        if (count($events) >= $max_actions) {
            printflow_chat_json(['success' => false, 'error' => 'Please wait a moment before trying again'], 429);
        }
        $events[] = $now;
        $_SESSION[$key] = $events;
    }
}

if (!function_exists('printflow_chat_pagination')) {
    function printflow_chat_pagination(array $query): array
    {
        $after_id = max(0, (int)($query['after_id'] ?? ($query['last_id'] ?? 0)));
        $before_id = max(0, (int)($query['before_id'] ?? 0));
        return [
            'after_id' => $before_id > 0 ? 0 : $after_id,
            'before_id' => $before_id,
            'limit' => max(1, min(50, (int)($query['limit'] ?? 40))),
        ];
    }
}

if (!function_exists('printflow_chat_reaction_allowed')) {
    function printflow_chat_reaction_allowed(string $reaction): bool
    {
        return in_array($reaction, ['like', 'love', 'haha', 'wow', 'sad', 'angry'], true);
    }
}

if (!function_exists('printflow_chat_inspect_image')) {
    function printflow_chat_inspect_image(string $path, int $size): array
    {
        if ($size <= 0 || $size > 8 * 1024 * 1024 || !is_file($path)) {
            return ['success' => false, 'error' => 'Each image must be 8 MB or smaller'];
        }
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        if (!isset($allowed[$mime]) || $dimensions === false || ($dimensions['mime'] ?? '') !== $mime) {
            return ['success' => false, 'error' => 'Only valid JPG, PNG, and WebP images are allowed'];
        }
        if ((int)$dimensions[0] > 12000 || (int)$dimensions[1] > 12000) {
            return ['success' => false, 'error' => 'Image dimensions are too large'];
        }
        return ['success' => true, 'mime' => $mime, 'extension' => $allowed[$mime]];
    }
}

if (!function_exists('printflow_chat_resolve_upload_path')) {
    function printflow_chat_resolve_upload_path(string $stored_path)
    {
        $url_path = (string)(parse_url(rawurldecode($stored_path), PHP_URL_PATH) ?: $stored_path);
        $relative = ltrim(str_replace('\\', '/', $url_path), '/');
        $marker = strpos($relative, 'uploads/');
        if ($marker !== false) $relative = substr($relative, $marker + strlen('uploads/'));
        $root = realpath(dirname(__DIR__) . '/uploads');
        $candidate = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) : false;
        if (!$root || !$candidate || !is_file($candidate) || strpos($candidate, $root . DIRECTORY_SEPARATOR) !== 0) return false;
        return $candidate;
    }
}
