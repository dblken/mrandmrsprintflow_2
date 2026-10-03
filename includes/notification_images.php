<?php
/** Read-time notification media. Never read customer artwork or saved thumbnails. */
function printflow_notification_is_message(array $notification): bool {
    $type = strtolower(trim((string)($notification['type'] ?? '')));
    return in_array($type, ['message', 'chat'], true)
        || ($type === 'system' && stripos((string)($notification['message'] ?? ''), 'support chat') !== false);
}

function printflow_notification_sender_image(array $notification): string {
    $default = pf_default_profile_image_url();
    // Support-chat records do not retain the individual admin sender ID.
    if (strtolower(trim((string)($notification['type'] ?? ''))) === 'system') return $default;
    $orderId = (int)($notification['data_id'] ?? 0);
    $timestamp = trim((string)($notification['created_at'] ?? ''));
    if ($orderId <= 0 || $timestamp === '') return $default;
    $sender = !empty($notification['customer_id']) ? 'Staff' : 'Customer';
    // Match the incoming message at event time, not the latest sender today.
    $rows = db_query(
        "SELECT sender_id, created_at FROM order_messages
         WHERE order_id = ? AND sender = ? AND sender_id > 0
           AND message_type IN ('text', 'image', 'file', 'voice', 'video', 'audio')
           AND created_at <= ? AND created_at >= DATE_SUB(?, INTERVAL 1 MINUTE)
         ORDER BY created_at DESC, message_id DESC LIMIT 2",
        'isss', [$orderId, $sender, $timestamp, $timestamp]
    ) ?: [];
    if (empty($rows[0])) return $default;
    // If timestamp precision cannot distinguish two senders, do not guess.
    if (isset($rows[1]) && $rows[0]['created_at'] === $rows[1]['created_at']
        && (int)$rows[0]['sender_id'] !== (int)$rows[1]['sender_id']) return $default;
    $table = $sender === 'Staff' ? 'users' : 'customers';
    $key = $sender === 'Staff' ? 'user_id' : 'customer_id';
    $profile = db_query("SELECT profile_picture FROM {$table} WHERE {$key} = ? LIMIT 1", 'i', [(int)$rows[0]['sender_id']]) ?: [];
    return get_profile_image($profile[0]['profile_picture'] ?? null);
}

/** Exact legacy service relationship; no aliases, substring guesses or arbitrary LIMIT 1. */
function printflow_notification_exact_service_id(string $name): int {
    if (trim($name) === '') return 0;
    $rows = db_query('SELECT service_id FROM services WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 2', 's', [trim($name)]) ?: [];
    return count($rows) === 1 ? (int)$rows[0]['service_id'] : 0;
}

function printflow_notification_catalog_image(string $kind, int $id): string {
    static $cache = [];
    $key = $kind . ':' . $id;
    if ($id <= 0) return '';
    if (array_key_exists($key, $cache)) return $cache[$key];
    if ($kind === 'Service') {
        // Legacy image_path is optional; selecting it explicitly breaks the
        // lookup on current Admin Services schemas that do not contain it.
        $rows = db_query('SELECT * FROM services WHERE service_id = ? LIMIT 1', 'i', [$id]) ?: [];
        if (empty($rows[0])) return $cache[$key] = '';
        // Admin stores ordered display_image CSV. Storefront primary is its
        // first still image, then hero_image. image_path is legacy catalog art.
        foreach (printflow_notification_service_image_candidates_from_service_row($rows[0]) as $path) {
            if (!printflow_is_video_media_path($path)) {
                return $cache[$key] = printflow_notification_normalize_media_url($path);
            }
        }
    } elseif ($kind === 'Product') {
        $rows = db_query('SELECT * FROM products WHERE product_id = ? LIMIT 1', 'i', [$id]) ?: [];
        foreach (['photo_path', 'product_image'] as $field) {
            $path = trim((string)($rows[0][$field] ?? ''));
            if ($path === '' || printflow_is_video_media_path($path)) continue;
            if (!str_contains(str_replace('\\', '/', $path), '/')) {
                $path = is_file(__DIR__ . '/../public/images/products/' . $path)
                    && !is_file(__DIR__ . '/../uploads/products/' . $path)
                    ? '/public/images/products/' . $path : '/uploads/products/' . $path;
            }
            return $cache[$key] = printflow_notification_normalize_media_url($path);
        }
    }
    return $cache[$key] = '';
}

/** Resolve the catalog entity from typed event IDs and linked order lines. */
function printflow_notification_catalog_context(array $notification, string $fallback): array {
    $result = ['image_url' => $fallback, 'order_id' => 0, 'review_id' => 0,
        'order_item_id' => 0, 'resolved_catalog_id' => 0, 'official_image_field' => '',
        'rejected_design_url' => '', 'image_source' => 'neutral_fallback', 'fallback_reason' => 'catalog_relationship_missing'];
    $type = strtolower(trim((string)($notification['type'] ?? '')));
    $orderId = (int)($notification['data_id'] ?? 0);
    if ($orderId <= 0 && preg_match('/\border\s*#(\d+)\b/i', (string)($notification['message'] ?? ''), $match)) {
        $orderId = (int)$match[1];
    }
    $itemId = (int)($notification['order_item_id'] ?? 0);
    $job = [];
    if ($type === 'payment' && empty($notification['customer_id'])
        && stripos((string)($notification['message'] ?? ''), 'submitted a payment proof for order') !== false) {
        // Manual proof events hold payment_submissions.id. PayMongo Payment
        // events hold orders.order_id, even for staff. Do not cross ID spaces.
        $rows = db_query('SELECT order_id, job_order_id FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$orderId]) ?: [];
        $orderId = (int)($rows[0]['order_id'] ?? 0);
        $jobId = (int)($rows[0]['job_order_id'] ?? 0);
        if ($orderId <= 0 && $jobId > 0) {
            $job = (db_query('SELECT order_id, service_type FROM job_orders WHERE id = ? LIMIT 1', 'i', [$jobId]) ?: [])[0] ?? [];
            $orderId = (int)($job['order_id'] ?? 0);
        }
    } elseif ($type === 'job order' || ($type === 'payment issue' && preg_match('/\bJob\s*#|\bJO-/i', (string)($notification['message'] ?? '')))) {
        $job = (db_query('SELECT order_id, service_type FROM job_orders WHERE id = ? LIMIT 1', 'i', [$orderId]) ?: [])[0] ?? [];
        $orderId = (int)($job['order_id'] ?? 0);
    }
    $reviewId = (int)($notification['review_id'] ?? 0);
    if ($reviewId > 0) {
        $review = (db_query('SELECT * FROM reviews WHERE id = ? LIMIT 1', 'i', [$reviewId]) ?: [])[0] ?? [];
        if (empty($review) || ($orderId > 0 && (int)$review['order_id'] !== $orderId)) return $result;
        $orderId = (int)$review['order_id'];
        $itemId = (int)($review['order_item_id'] ?? $itemId);
    }
    $result['order_id'] = $orderId;
    $result['review_id'] = $reviewId;
    if ($itemId <= 0 && $orderId > 0 && stripos((string)($notification['message'] ?? ''), 'change item') !== false
        && !empty($notification['created_at'])) {
        $changes = db_query('SELECT order_item_id FROM change_item_requests WHERE order_id = ? AND requested_at <= ? ORDER BY requested_at DESC, change_item_id DESC LIMIT 1', 'is', [$orderId, $notification['created_at']]) ?: [];
        $itemId = (int)($changes[0]['order_item_id'] ?? 0);
    }
    static $orderData = [];
    static $lineColumns = null;
    if ($lineColumns === null) $lineColumns = array_column(db_query('SHOW COLUMNS FROM order_items') ?: [], 'Field');
    $identityFields = (in_array('service_id', $lineColumns, true) ? 'oi.service_id' : '0 AS service_id')
        . ', ' . (in_array('item_type', $lineColumns, true) ? 'oi.item_type' : "'' AS item_type");
    if ($orderId > 0 && !isset($orderData[$orderId])) {
        $orderData[$orderId]['order'] = (db_query('SELECT order_type, reference_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]) ?: [])[0] ?? [];
        $orderData[$orderId]['lines'] = db_query(
        'SELECT oi.order_item_id, oi.product_id, oi.customization_data, ' . $identityFields . ', p.sku AS product_sku, p.name AS product_name
         FROM order_items oi LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ? ORDER BY oi.order_item_id ASC', 'i', [$orderId]
        ) ?: [];
        $orderData[$orderId]['customizations'] = db_query('SELECT order_item_id, service_type, customization_details FROM customizations WHERE order_id = ? ORDER BY customization_id ASC', 'i', [$orderId]) ?: [];
        $orderData[$orderId]['jobs'] = db_query('SELECT service_type FROM job_orders WHERE order_id = ? ORDER BY id ASC', 'i', [$orderId]) ?: [];
    }
    $order = $orderData[$orderId]['order'] ?? [];
    $lines = $orderData[$orderId]['lines'] ?? [];
    $customizations = $orderData[$orderId]['customizations'] ?? [];
    if ($orderId > 0 && empty($job)) {
        $jobs = $orderData[$orderId]['jobs'] ?? [];
        $names = array_unique(array_filter(array_column($jobs, 'service_type')));
        if (count($names) === 1) $job['service_type'] = reset($names);
    }
    $entities = [];
    foreach ($lines as $line) {
        if ($itemId > 0 && (int)$line['order_item_id'] !== $itemId) continue;
        $custom = printflow_decode_modal_customization_payload((string)($line['customization_data'] ?? ''));
        $sid = (int)($line['service_id'] ?? 0) ?: (int)($custom['service_id'] ?? 0);
        $names = array_filter([trim((string)($custom['service_type'] ?? ''))]);
        foreach ($customizations as $c) {
            if ((int)$c['order_item_id'] === (int)$line['order_item_id'] || (count($lines) === 1 && empty($c['order_item_id']))) {
                $details = printflow_decode_modal_customization_payload((string)($c['customization_details'] ?? ''));
                if ($sid <= 0) $sid = (int)($details['service_id'] ?? 0);
                if (trim((string)$c['service_type']) !== '') $names[] = trim((string)$c['service_type']);
            }
        }
        $source = strtolower(trim((string)($custom['source_page'] ?? '')));
        $lineType = strtolower(trim((string)($line['item_type'] ?? '')));
        $isProduct = in_array($lineType, ['product', 'catalog_product'], true)
            || in_array($source, ['product', 'products'], true)
            || ($sid <= 0 && !in_array($lineType, ['service', 'custom_service'], true)
                && customer_orders_custom_order_is_catalog_product($custom))
            || ($sid <= 0 && !$names && strtolower((string)($order['order_type'] ?? '')) === 'product');
        $isService = !$isProduct && ($sid > 0 || $names !== []
            || in_array($lineType, ['service', 'custom_service'], true)
            || in_array($source, ['service', 'services'], true)
            || printflow_is_pos_service_placeholder_row($line));
        if (!$isProduct && !$isService && count($lines) === 1 && !empty($job['service_type'])) {
            $isService = true;
            $names[] = (string)$job['service_type'];
        }
        if (!$isService && !$isProduct) return $result;
        if ($isService && $sid <= 0) {
            $ids = [];
            foreach (array_unique($names) as $name) {
                $exactId = printflow_notification_exact_service_id($name);
                if ($exactId > 0) $ids[$exactId] = $exactId;
            }
            if (count($ids) === 1) $sid = reset($ids);
            // Legacy custom reference_id can contain a placeholder product ID.
            // Never probe that value in the overlapping services ID namespace.
        }
        $kind = $isService ? 'Service' : 'Product';
        $id = $isService ? $sid : (int)($line['product_id'] ?? 0);
        if ($id <= 0) return $result;
        $entities[$kind . ':' . $id] = [$kind, $id, (int)$line['order_item_id']];
    }
    if (!$lines && $itemId <= 0) {
        $sid = printflow_notification_exact_service_id((string)($job['service_type'] ?? ''));
        if ($sid > 0) $entities['Service:' . $sid] = ['Service', $sid, 0];
        elseif (($order['order_type'] ?? '') === 'product' && (int)$order['reference_id'] > 0) {
            $entities['Product:' . $order['reference_id']] = ['Product', (int)$order['reference_id'], 0];
        }
    }
    // An order-level event for multiple distinct items cannot identify one exact
    // catalog entity. A neutral image is safer than silently choosing the first.
    if (count($entities) !== 1) return $result;
    [$kind, $id, $resolvedItem] = reset($entities);
    $image = printflow_notification_catalog_image($kind, $id);
    $result['order_item_id'] = $resolvedItem;
    $result['resolved_catalog_id'] = $id;
    $result['official_image_field'] = $kind === 'Service' ? 'services.display_image|hero_image|image_path' : 'products.photo_path|product_image';
    $result['fallback_reason'] = $image === '' ? 'catalog_image_missing' : '';
    if ($image !== '') {
        $result['image_url'] = $image;
        $result['image_source'] = 'official_catalog';
    }
    return $result;
}
