<?php

declare(strict_types=1);

/**
 * Shared customer order review context, eligibility, and submission handling.
 */

function customer_rate_order_schema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    $review_cols_raw = db_query('SHOW COLUMNS FROM reviews') ?: [];
    $review_cols = array_filter(array_map(static function ($col) {
        return (string)($col['Field'] ?? '');
    }, $review_cols_raw));

    $schema = [
        'user_col' => in_array('user_id', $review_cols, true) ? 'user_id' : (in_array('customer_id', $review_cols, true) ? 'customer_id' : 'user_id'),
        'message_col' => in_array('comment', $review_cols, true) ? 'comment' : (in_array('message', $review_cols, true) ? 'message' : 'comment'),
        'has_video' => in_array('video_path', $review_cols, true),
        'has_type' => in_array('review_type', $review_cols, true),
        'has_ref' => in_array('reference_id', $review_cols, true),
        'has_service' => in_array('service_type', $review_cols, true),
    ];

    return $schema;
}

function customer_rate_order_resolve_service_label(array $order): string
{
    $service = '';
    if (!empty($order['customization_data'])) {
        $json = json_decode((string)$order['customization_data'], true);
        if (is_array($json)) {
            $service = (string)($json['service_type'] ?? $json['product_type'] ?? '');
        }
    }
    if ($service === '') {
        $service = (string)($order['product_name'] ?? 'Print Service');
    }

    return normalize_service_name($service, 'Print Service');
}

function customer_rate_order_load(int $order_id, int $customer_id): ?array
{
    if ($order_id <= 0 || $customer_id <= 0) {
        return null;
    }

    $schema = customer_rate_order_schema();
    $message_col = $schema['message_col'];

    $order_rows = db_query(
        "SELECT o.order_id, o.customer_id, o.status,
                (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) AS order_sku,
                (SELECT oi.customization_data FROM order_items oi WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) AS customization_data,
                (SELECT p.name FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) AS product_name,
                (SELECT oi.order_item_id FROM order_items oi WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) AS first_item_id
         FROM orders o
         WHERE o.order_id = ? AND o.customer_id = ?
         LIMIT 1",
        'ii',
        [$order_id, $customer_id]
    );

    if (empty($order_rows)) {
        return null;
    }

    $order = $order_rows[0];
    if (!in_array((string)$order['status'], ['Completed', 'To Rate', 'Rated'], true)) {
        return null;
    }

    $existing = db_query(
        "SELECT id, rating, {$message_col} AS review_message, created_at FROM reviews WHERE order_id = ? LIMIT 1",
        'i',
        [$order_id]
    );
    $already_rated = !empty($existing);
    $review_id = $already_rated ? (int)$existing[0]['id'] : 0;
    $existing_rating = $already_rated ? (int)$existing[0]['rating'] : 0;
    $existing_message = $already_rated ? (string)($existing[0]['review_message'] ?? '') : '';
    $needs_message_update = $already_rated && (trim($existing_message) === '' || $existing_message === '(No comment provided)');
    $prompt_dismissed = customer_review_prompt_is_dismissed($customer_id, $order_id);

    return [
        'order' => $order,
        'order_id' => $order_id,
        'order_code' => printflow_format_order_code($order['order_id'] ?? 0, $order['order_sku'] ?? ''),
        'service_type_label' => customer_rate_order_resolve_service_label($order),
        'already_rated' => $already_rated,
        'review_id' => $review_id,
        'existing_rating' => $existing_rating,
        'existing_message' => $existing_message,
        'needs_message_update' => $needs_message_update,
        'can_submit' => !$already_rated || $needs_message_update,
        'prompt_dismissed' => $prompt_dismissed,
        'can_prompt' => in_array((string)$order['status'], ['Completed', 'To Rate'], true)
            && (!$already_rated || $needs_message_update)
            && !$prompt_dismissed,
    ];
}

function customer_review_prompt_dismissals_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $rows = db_query("SHOW TABLES LIKE 'customer_review_prompt_dismissals'") ?: [];
    if (empty($rows)) {
        db_execute(
            'CREATE TABLE IF NOT EXISTS customer_review_prompt_dismissals (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id INT NOT NULL,
                order_id INT NOT NULL,
                dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_customer_review_prompt_dismiss (customer_id, order_id),
                KEY idx_order_id (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $rows = db_query("SHOW TABLES LIKE 'customer_review_prompt_dismissals'") ?: [];
    $ready = !empty($rows);

    return $ready;
}

function customer_review_prompt_is_dismissed(int $customer_id, int $order_id): bool
{
    if ($customer_id <= 0 || $order_id <= 0 || !customer_review_prompt_dismissals_table_ready()) {
        return false;
    }
    $rows = db_query(
        'SELECT id FROM customer_review_prompt_dismissals WHERE customer_id = ? AND order_id = ? LIMIT 1',
        'ii',
        [$customer_id, $order_id]
    );

    return !empty($rows);
}

function customer_review_prompt_dismiss(int $customer_id, int $order_id): bool
{
    if ($customer_id <= 0 || $order_id <= 0 || !customer_review_prompt_dismissals_table_ready()) {
        return false;
    }

    $result = db_execute(
        'INSERT INTO customer_review_prompt_dismissals (customer_id, order_id, dismissed_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE dismissed_at = VALUES(dismissed_at)',
        'ii',
        [$customer_id, $order_id]
    );

    return $result !== false;
}

function customer_review_prompt_payload_from_context(?array $context): ?array
{
    if ($context === null || empty($context['can_prompt'])) {
        return null;
    }

    return [
        'order_id' => (int)$context['order_id'],
        'order_code' => (string)$context['order_code'],
        'service_label' => (string)$context['service_type_label'],
        'message' => 'Your order has been successfully picked up. We hope to see you again!',
    ];
}

function customer_rate_order_handle_post(int $order_id, int $customer_id, array $post, array $files): array
{
    $context = customer_rate_order_load($order_id, $customer_id);
    if ($context === null) {
        return ['success' => false, 'error' => 'Order not found or not eligible for review.'];
    }

    if (!$context['can_submit']) {
        return ['success' => false, 'error' => 'You already rated this order.'];
    }

    if (!verify_csrf_token($post['csrf_token'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid security token. Please refresh and try again.'];
    }

    $schema = customer_rate_order_schema();
    $review_message_col = $schema['message_col'];
    $review_user_col = $schema['user_col'];
    $review_has_video = $schema['has_video'];
    $review_has_type = $schema['has_type'];
    $review_has_ref = $schema['has_ref'];
    $review_has_service = $schema['has_service'];

    $order = $context['order'];
    $service_type_label = $context['service_type_label'];
    $needs_message_update = $context['needs_message_update'];
    $review_id = $context['review_id'];

    $rating = (int)($post['rating'] ?? 0);
    $message = trim((string)($post['message'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        return ['success' => false, 'error' => 'Please select a star rating from 1 to 5.'];
    }
    if (mb_strlen($message) < 5) {
        return ['success' => false, 'error' => 'Please write at least 5 characters in your feedback.'];
    }
    if (mb_strlen($message) > 500) {
        return ['success' => false, 'error' => 'Feedback message is too long (max 500 characters).'];
    }

    $error = '';
    $video_path = null;
    $uploaded_images = [];

    if (!empty($files['review_video']['name'])) {
        $ext = strtolower(pathinfo((string)$files['review_video']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'mp4') {
            $error = 'Video must be in MP4 format.';
        } elseif ((int)$files['review_video']['size'] > 15 * 1024 * 1024) {
            $error = 'Video size exceeds 15MB limit.';
        } else {
            $video_name = 'review_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.mp4';
            $upload = upload_file($files['review_video'], ['mp4'], 'reviews_videos', $video_name);
            if (empty($upload['success'])) {
                $error = $upload['error'] ?? 'Failed to upload video.';
            } else {
                $video_path = $upload['file_path'];
            }
        }
    }

    if ($error === '' && !empty($files['review_images']['name'])) {
        $file_list = $files['review_images'];
        $file_count = is_array($file_list['name']) ? count($file_list['name']) : 1;

        if ($file_count > 5) {
            $error = 'You can only upload up to 5 images.';
        } else {
            for ($i = 0; $i < $file_count; $i++) {
                $f = [
                    'name' => is_array($file_list['name']) ? $file_list['name'][$i] : $file_list['name'],
                    'type' => is_array($file_list['type']) ? $file_list['type'][$i] : $file_list['type'],
                    'tmp_name' => is_array($file_list['tmp_name']) ? $file_list['tmp_name'][$i] : $file_list['tmp_name'],
                    'error' => is_array($file_list['error']) ? $file_list['error'][$i] : $file_list['error'],
                    'size' => is_array($file_list['size']) ? $file_list['size'][$i] : $file_list['size'],
                ];

                if (empty($f['name'])) {
                    continue;
                }

                $upload = upload_file($f, ['jpg', 'jpeg', 'png', 'webp'], 'reviews_images');
                if (empty($upload['success'])) {
                    $error = $upload['error'] ?? 'Failed to upload image ' . ($i + 1);
                    break;
                }
                $uploaded_images[] = $upload['file_path'];
            }
        }
    }

    if ($error !== '') {
        return ['success' => false, 'error' => $error];
    }

    try {
        $ref_id = 0;
        $rev_type = 'custom';

        $item_ref = db_query('SELECT product_id FROM order_items WHERE order_id = ? LIMIT 1', 'i', [$order_id]);
        if (!empty($item_ref) && !empty($item_ref[0]['product_id'])) {
            $ref_id = (int)$item_ref[0]['product_id'];
            $rev_type = 'product';
        }

        if ($needs_message_update) {
            $update_sets = "rating = ?, {$review_message_col} = ?";
            $update_types = 'is';
            $update_vals = [$rating, $message];
            if ($review_has_video) {
                $update_sets .= ', video_path = COALESCE(?, video_path)';
                $update_types .= 's';
                $update_vals[] = $video_path;
            }
            $update_types .= 'i';
            $update_vals[] = $review_id;

            $updated = db_execute(
                "UPDATE reviews SET {$update_sets} WHERE id = ?",
                $update_types,
                $update_vals
            );
            if ($updated === false) {
                throw new RuntimeException('Failed to update your review.');
            }
            $new_review_id = $review_id;
        } else {
            $cols = ['order_id', $review_user_col, 'rating', $review_message_col];
            $vals = [$order_id, $customer_id, $rating, $message];
            $types = 'iiss';

            if ($review_has_service) {
                $cols[] = 'service_type';
                $vals[] = $service_type_label;
                $types .= 's';
            }
            if ($review_has_video) {
                $cols[] = 'video_path';
                $vals[] = $video_path;
                $types .= 's';
            }
            if ($review_has_type) {
                $cols[] = 'review_type';
                $vals[] = $rev_type;
                $types .= 's';
            }
            if ($review_has_ref) {
                $cols[] = 'reference_id';
                $vals[] = $ref_id;
                $types .= 'i';
            }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $insert_result = db_execute(
                'INSERT INTO reviews (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')',
                $types,
                $vals
            );
            if ($insert_result === false) {
                throw new RuntimeException('Failed to save your review.');
            }

            $new_review_id = is_numeric($insert_result) ? (int)$insert_result : 0;
            if ($new_review_id <= 0) {
                $last_insert = db_query('SELECT LAST_INSERT_ID() as id');
                $new_review_id = (int)($last_insert[0]['id'] ?? 0);
            }
            if ($new_review_id <= 0) {
                throw new RuntimeException('Could not confirm the saved review ID.');
            }
        }

        foreach ($uploaded_images as $img) {
            $image_result = db_execute('INSERT INTO review_images (review_id, image_path) VALUES (?, ?)', 'is', [$new_review_id, $img]);
            if ($image_result === false) {
                throw new RuntimeException('Failed to save one of the review images.');
            }
        }

        $order_update = db_execute("UPDATE orders SET status = 'Rated' WHERE order_id = ? AND customer_id = ?", 'ii', [$order_id, $customer_id]);
        if ($order_update === false) {
            throw new RuntimeException('Failed to update the order status.');
        }

        $staff_msg = "Customer submitted a review for Order #{$order_id}: {$rating}/5 stars.";
        notify_shop_users($staff_msg, 'Rating', false, false, $order_id, ['Staff', 'Admin', 'Manager'], $new_review_id);

        return [
            'success' => true,
            'message' => 'Your feedback has been submitted successfully.',
            'order_id' => $order_id,
            'review_id' => $new_review_id,
        ];
    } catch (Throwable $e) {
        error_log('[customer_rate_order] order_id=' . $order_id . ' customer_id=' . $customer_id . ' error=' . $e->getMessage());

        return ['success' => false, 'error' => 'Could not submit your review: ' . $e->getMessage()];
    }
}
