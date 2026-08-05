<?php
/**
 * Field-authorized order revision workflow.
 *
 * This layer is deliberately independent from pricing, payment, and production.
 * It records staff authorization and applies only the approved customer fields.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/order_ui_helper.php';

function printflow_revision_ensure_schema(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = (bool) db_execute(
        "CREATE TABLE IF NOT EXISTS order_revision_requests (
            revision_request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT NOT NULL,
            staff_id INT NOT NULL,
            customer_id INT NOT NULL,
            reason_code VARCHAR(64) NOT NULL,
            revision_reason VARCHAR(255) NOT NULL,
            staff_instruction TEXT NOT NULL,
            permitted_fields LONGTEXT NOT NULL,
            previous_values LONGTEXT NOT NULL,
            revised_values LONGTEXT DEFAULT NULL,
            request_status VARCHAR(40) NOT NULL DEFAULT 'Requested',
            active_flag TINYINT NULL DEFAULT 1,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resubmitted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (revision_request_id),
            UNIQUE KEY uq_order_active_revision (order_id, active_flag),
            KEY idx_revision_customer (customer_id, request_status),
            KEY idx_revision_staff (staff_id, request_status),
            KEY idx_revision_requested (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db_execute(
        "CREATE TABLE IF NOT EXISTS order_item_revisions (
            revision_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            order_item_id INT NOT NULL,
            staff_id INT DEFAULT NULL,
            revision_reason TEXT,
            design_image LONGBLOB,
            design_image_name VARCHAR(255),
            design_image_mime VARCHAR(100),
            design_file VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_item_revision_order (order_id),
            KEY idx_item_revision_item (order_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    return $ready;
}

function printflow_revision_reason_labels(): array
{
    return [
        'low_image_quality' => 'Low image quality',
        'wrong_design' => 'Wrong design uploaded',
        'incorrect_details' => 'Incorrect details provided',
        'invalid_format' => 'Not printable / invalid format',
        'others' => 'Others',
    ];
}

function printflow_revision_reason_code(string $value): string
{
    $value = strtolower(trim($value));
    $aliases = [
        'low image quality' => 'low_image_quality',
        'wrong design uploaded' => 'wrong_design',
        'incorrect details provided' => 'incorrect_details',
        'not printable / invalid format' => 'invalid_format',
        'others' => 'others',
    ];
    if (isset($aliases[$value])) {
        return $aliases[$value];
    }
    return array_key_exists($value, printflow_revision_reason_labels()) ? $value : 'others';
}

function printflow_revision_decode_json($value): array
{
    if (is_array($value)) {
        return $value;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return [];
    }
    if (function_exists('customer_orders_decode_customization_payload')) {
        $decoded = customer_orders_decode_customization_payload($raw);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function printflow_revision_encode_json(array $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function printflow_revision_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (!array_key_exists($cacheKey, $cache)) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        $cache[$cacheKey] = !empty(db_query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"));
    }
    return $cache[$cacheKey];
}

function printflow_revision_normalize_key(string $key): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
}

function printflow_revision_key_group(string $key): string
{
    $key = printflow_revision_normalize_key($key);
    if (in_array($key, ['needed_date', 'need_date', 'date_needed', 'required_date', 'due_date'], true)) {
        return 'needed_date';
    }
    if (strpos($key, 'layout') !== false) {
        return 'layout';
    }
    if (in_array($key, ['notes', 'order_notes', 'customer_notes', 'additional_notes', 'special_instructions', 'job_notes'], true)) {
        return 'order_notes';
    }
    return 'type_specifications';
}

function printflow_revision_is_protected_spec(string $key): bool
{
    $key = printflow_revision_normalize_key($key);
    if ($key === '') {
        return true;
    }
    $exact = [
        'service_id', 'product_id', 'variant_id', 'branch', 'branch_id', 'branch_name',
        'service_type', 'product_name', 'category', 'source_page', 'item_key', 'cart_key',
        'quantity', 'qty',
        'price', 'unit_price', 'subtotal', 'total', 'total_amount', 'estimated_price', 'final_price',
        'payment_status', 'payment_method', 'order_status', 'status', 'order_id', 'order_item_id',
        'design_file', 'design_image', 'design_upload', 'design_upload_path', 'design_upload_name',
        'design_upload_data', 'design_upload_mime', 'reference_file', 'reference_image', 'csrf_token',
    ];
    if (in_array($key, $exact, true)) {
        return true;
    }
    return str_starts_with($key, '_')
        || str_contains($key, 'payment')
        || str_contains($key, 'price')
        || str_contains($key, 'branch')
        || str_contains($key, 'service_id')
        || str_contains($key, 'product_id');
}

function printflow_revision_spec_token(int $itemId, string $key): string
{
    return 'spec:' . $itemId . ':' . rawurlencode($key);
}

function printflow_revision_form_key(string $key): string
{
    return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
}

function printflow_revision_decode_form_key(string $token): string
{
    $padding = strlen($token) % 4;
    if ($padding > 0) {
        $token .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function printflow_revision_parse_spec_token(string $token): ?array
{
    if (!preg_match('/^spec:(\d+):(.+)$/', $token, $matches)) {
        return null;
    }
    $key = rawurldecode($matches[2]);
    return ['item_id' => (int) $matches[1], 'key' => $key];
}

function printflow_revision_snapshot(int $orderId): array
{
    $orderRows = db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]) ?: [];
    $order = $orderRows[0] ?? [];
    $specSelect = printflow_revision_column_exists('order_items', 'specifications')
        ? 'specifications'
        : "'' AS specifications";
    $items = db_query(
        "SELECT order_item_id, order_id, product_id, quantity, customization_data, {$specSelect},
                design_image_name, design_image_mime, design_file,
                IFNULL(LENGTH(design_image), 0) AS design_blob_size
         FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC",
        'i',
        [$orderId]
    ) ?: [];
    $snapshotItems = [];
    foreach ($items as $item) {
        $snapshotItems[] = [
            'order_item_id' => (int) ($item['order_item_id'] ?? 0),
            'product_id' => (int) ($item['product_id'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'customization_data' => printflow_revision_decode_json($item['customization_data'] ?? ''),
            'specifications' => printflow_revision_decode_json($item['specifications'] ?? ''),
            'design' => [
                'name' => (string) ($item['design_image_name'] ?? ''),
                'mime' => (string) ($item['design_image_mime'] ?? ''),
                'file' => (string) ($item['design_file'] ?? ''),
                'blob_size' => (int)($item['design_blob_size'] ?? 0),
            ],
        ];
    }
    return [
        'order' => [
            'order_id' => $orderId,
            'customer_id' => (int) ($order['customer_id'] ?? 0),
            'branch_id' => (int) ($order['branch_id'] ?? 0),
            'status' => (string) ($order['status'] ?? ''),
            'design_status' => (string) ($order['design_status'] ?? ''),
            'notes' => (string) ($order['notes'] ?? ''),
        ],
        'items' => $snapshotItems,
    ];
}

function printflow_revision_normalize_permissions(array $fields, int $orderId): array
{
    $baseAllowed = ['uploaded_design', 'needed_date', 'type_specifications', 'layout', 'quantity', 'order_notes'];
    $specSelect = printflow_revision_column_exists('order_items', 'specifications')
        ? 'specifications'
        : "'' AS specifications";
    $items = db_query(
        "SELECT order_item_id, customization_data, {$specSelect} FROM order_items WHERE order_id = ?",
        'i',
        [$orderId]
    ) ?: [];
    $existing = [];
    foreach ($items as $item) {
        $itemId = (int) ($item['order_item_id'] ?? 0);
        $custom = printflow_revision_decode_json($item['customization_data'] ?? '');
        $specs = printflow_revision_decode_json($item['specifications'] ?? '');
        foreach (array_keys(array_merge($custom, $specs)) as $key) {
            if (!printflow_revision_is_protected_spec((string) $key)) {
                $existing[printflow_revision_spec_token($itemId, (string) $key)] = true;
            }
        }
    }

    $normalized = [];
    foreach ($fields as $field) {
        $field = trim((string) $field);
        if (in_array($field, $baseAllowed, true)) {
            $normalized[$field] = $field;
            continue;
        }
        $parsed = printflow_revision_parse_spec_token($field);
        if ($parsed !== null) {
            $canonical = printflow_revision_spec_token((int)$parsed['item_id'], (string)$parsed['key']);
            if (isset($existing[$canonical])) {
                $normalized[$canonical] = $canonical;
            }
        }
    }
    return array_values($normalized);
}

function printflow_revision_get_active(int $orderId, ?int $customerId = null, bool $forUpdate = false): ?array
{
    if (!printflow_revision_ensure_schema()) {
        return null;
    }
    $sql = 'SELECT * FROM order_revision_requests WHERE order_id = ? AND active_flag = 1';
    $types = 'i';
    $params = [$orderId];
    if ($customerId !== null) {
        $sql .= ' AND customer_id = ?';
        $types .= 'i';
        $params[] = $customerId;
    }
    $sql .= ' ORDER BY revision_request_id DESC LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $rows = db_query($sql, $types, $params) ?: [];
    if (empty($rows)) {
        return null;
    }
    $row = $rows[0];
    $row['permitted_fields_array'] = printflow_revision_decode_json($row['permitted_fields'] ?? '[]');
    $row['previous_values_array'] = printflow_revision_decode_json($row['previous_values'] ?? '{}');
    return $row;
}

function printflow_revision_get_active_or_legacy(int $orderId, int $customerId): ?array
{
    $active = printflow_revision_get_active($orderId, $customerId);
    if ($active !== null) {
        return $active;
    }
    $rows = db_query(
        "SELECT status, design_status, revision_reason, reviewed_by
         FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1",
        'ii',
        [$orderId, $customerId]
    ) ?: [];
    $order = $rows[0] ?? null;
    if ($order === null
        || (string)($order['status'] ?? '') !== 'For Revision'
        || (string)($order['design_status'] ?? '') !== 'Revision Requested') {
        return null;
    }
    $legacyReason = trim((string)($order['revision_reason'] ?? ''));
    if ($legacyReason === '') {
        $legacyReason = 'Please upload the corrected design requested by staff.';
    }
    try {
        printflow_revision_create_request(
            $orderId,
            (int)($order['reviewed_by'] ?? 0),
            $legacyReason,
            $legacyReason,
            ['uploaded_design'],
            $legacyReason
        );
    } catch (Throwable $e) {
        error_log('Legacy revision backfill failed for Order #' . $orderId . ': ' . $e->getMessage());
    }
    return printflow_revision_get_active($orderId, $customerId);
}

function printflow_revision_close_active(int $orderId, string $status): void
{
    if ($orderId <= 0 || !printflow_revision_ensure_schema()) {
        return;
    }
    $status = trim($status) !== '' ? trim($status) : 'Closed';
    db_execute(
        "UPDATE order_revision_requests
         SET request_status = ?, active_flag = NULL
         WHERE order_id = ? AND active_flag = 1",
        'si',
        [$status, $orderId]
    );
}

function printflow_revision_create_request(
    int $orderId,
    int $staffId,
    string $reasonCode,
    string $instruction,
    array $permittedFields,
    ?string $reasonLabelOverride = null
): array {
    if (!printflow_revision_ensure_schema()) {
        throw new RuntimeException('Revision request storage is unavailable.');
    }
    $instruction = sanitize(trim($instruction));
    if ($instruction === '') {
        throw new InvalidArgumentException('Instructions for the customer are required.');
    }
    $reasonCode = printflow_revision_reason_code($reasonCode);
    $labels = printflow_revision_reason_labels();
    $reasonLabel = sanitize(trim((string)$reasonLabelOverride));
    if ($reasonLabel === '') {
        $reasonLabel = $labels[$reasonCode] ?? $labels['others'];
    }
    $permittedFields = printflow_revision_normalize_permissions($permittedFields, $orderId);
    if (empty($permittedFields)) {
        throw new InvalidArgumentException('Select at least one field the customer may edit.');
    }
    if (in_array($reasonCode, ['low_image_quality', 'wrong_design', 'invalid_format'], true)) {
        $permittedFields = ['uploaded_design'];
    }

    $orderRows = db_query('SELECT customer_id FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE', 'i', [$orderId]) ?: [];
    if (empty($orderRows)) {
        throw new RuntimeException('Order not found.');
    }
    if (printflow_revision_get_active($orderId, null, true) !== null) {
        throw new RuntimeException('This order already has an active revision request.');
    }

    $customerId = (int) ($orderRows[0]['customer_id'] ?? 0);
    $snapshot = printflow_revision_snapshot($orderId);
    db_execute(
        'INSERT INTO order_item_revisions (order_id, order_item_id, staff_id, revision_reason, design_image, design_image_name, design_image_mime, design_file)
         SELECT order_id, order_item_id, ?, ?, design_image, design_image_name, design_image_mime, design_file
         FROM order_items WHERE order_id = ?',
        'isi',
        [$staffId, $reasonLabel . ': ' . $instruction, $orderId]
    );

    $ok = db_execute(
        "INSERT INTO order_revision_requests
         (order_id, staff_id, customer_id, reason_code, revision_reason, staff_instruction, permitted_fields, previous_values, request_status, active_flag, requested_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Requested', 1, NOW())",
        'iiisssss',
        [
            $orderId,
            $staffId,
            $customerId,
            $reasonCode,
            $reasonLabel,
            $instruction,
            printflow_revision_encode_json($permittedFields),
            printflow_revision_encode_json($snapshot),
        ]
    );
    if (!$ok) {
        throw new RuntimeException('Unable to save the revision request. The order may already have an active request.');
    }
    global $conn;
    return [
        'revision_request_id' => (int) ($conn->insert_id ?? 0),
        'reason_code' => $reasonCode,
        'reason_label' => $reasonLabel,
        'instruction' => $instruction,
        'permitted_fields' => $permittedFields,
        'customer_id' => $customerId,
        'legacy_reason' => $reasonLabel . ': ' . $instruction,
    ];
}

function printflow_revision_permission_allows(array $permissions, int $itemId, string $key): bool
{
    if (printflow_revision_is_protected_spec($key)) {
        return false;
    }
    if (in_array(printflow_revision_spec_token($itemId, $key), $permissions, true)) {
        return true;
    }
    return in_array(printflow_revision_key_group($key), $permissions, true);
}

function printflow_revision_submit(int $orderId, int $customerId, array $post, array $files): array
{
    global $conn;
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    if (!printflow_revision_ensure_schema()) {
        throw new RuntimeException('Revision request storage is unavailable.');
    }

    $started = true;
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start the revision transaction.');
    }

    try {
        $orderRows = db_query(
            'SELECT * FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1 FOR UPDATE',
            'ii',
            [$orderId, $customerId]
        ) ?: [];
        if (empty($orderRows)) {
            throw new RuntimeException('Order not found.');
        }
        $order = $orderRows[0];
        if ((string) ($order['status'] ?? '') !== 'For Revision') {
            throw new RuntimeException('This order is not currently open for revision.');
        }
        $request = printflow_revision_get_active($orderId, $customerId, true);
        if ($request === null) {
            throw new RuntimeException('No active revision request was found.');
        }
        $permissions = $request['permitted_fields_array'];
        $allowedPostKeys = ['csrf_token', 'order_id', 'resubmit_order', 'spec', 'quantity', 'order_notes'];
        foreach (array_keys($post) as $postKey) {
            if (!in_array((string)$postKey, $allowedPostKeys, true)) {
                throw new RuntimeException('An unauthorized order field change was rejected.');
            }
        }
        foreach (array_keys($files) as $fileKey) {
            if ((string)$fileKey !== 'design_file') {
                throw new RuntimeException('An unauthorized file field was rejected.');
            }
        }
        $specSelect = printflow_revision_column_exists('order_items', 'specifications')
            ? 'specifications'
            : "'' AS specifications";
        $items = db_query(
            "SELECT order_item_id, order_id, product_id, quantity, customization_data, {$specSelect},
                    design_image_name, design_image_mime, design_file
             FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC FOR UPDATE",
            'i',
            [$orderId]
        ) ?: [];
        $itemMap = [];
        foreach ($items as $item) {
            $itemMap[(int) $item['order_item_id']] = $item;
        }

        $postedSpecs = is_array($post['spec'] ?? null) ? $post['spec'] : [];
        foreach ($postedSpecs as $itemIdRaw => $changes) {
            $itemId = (int) $itemIdRaw;
            if (!isset($itemMap[$itemId]) || !is_array($changes)) {
                throw new RuntimeException('An invalid order item was submitted.');
            }
            $item = $itemMap[$itemId];
            $custom = printflow_revision_decode_json($item['customization_data'] ?? '');
            $specs = printflow_revision_decode_json($item['specifications'] ?? '');
            $existingKeys = array_fill_keys(array_keys(array_merge($custom, $specs)), true);
            foreach ($changes as $keyToken => $value) {
                $key = printflow_revision_decode_form_key((string) $keyToken);
                if (!isset($existingKeys[$key]) || !printflow_revision_permission_allows($permissions, $itemId, $key)) {
                    throw new RuntimeException('An unauthorized specification change was rejected.');
                }
                if (is_array($value)) {
                    $value = array_map(static fn($v) => sanitize((string) $v), $value);
                } else {
                    $value = sanitize((string) $value);
                }
                if (array_key_exists($key, $custom) || !array_key_exists($key, $specs)) {
                    $custom[$key] = $value;
                }
                if (array_key_exists($key, $specs)) {
                    $specs[$key] = $value;
                }
            }
            db_execute(
                'UPDATE order_items SET customization_data = ? WHERE order_item_id = ? AND order_id = ?',
                'sii',
                [printflow_revision_encode_json($custom), $itemId, $orderId]
            );
            if (printflow_revision_column_exists('order_items', 'specifications')) {
                db_execute(
                    'UPDATE order_items SET specifications = ? WHERE order_item_id = ? AND order_id = ?',
                    'sii',
                    [printflow_revision_encode_json($specs), $itemId, $orderId]
                );
            }
        }

        $postedQuantities = is_array($post['quantity'] ?? null) ? $post['quantity'] : [];
        if (!empty($postedQuantities) && !in_array('quantity', $permissions, true)) {
            throw new RuntimeException('An unauthorized quantity change was rejected.');
        }
        foreach ($postedQuantities as $itemIdRaw => $quantityRaw) {
            $itemId = (int) $itemIdRaw;
            $quantity = (int) $quantityRaw;
            if (!isset($itemMap[$itemId]) || $quantity < 1 || $quantity > 100000) {
                throw new RuntimeException('Enter a valid quantity.');
            }
            db_execute('UPDATE order_items SET quantity = ? WHERE order_item_id = ? AND order_id = ?', 'iii', [$quantity, $itemId, $orderId]);
        }

        if (array_key_exists('order_notes', $post)) {
            if (!in_array('order_notes', $permissions, true)) {
                throw new RuntimeException('An unauthorized order-notes change was rejected.');
            }
            db_execute('UPDATE orders SET notes = ? WHERE order_id = ? AND customer_id = ?', 'sii', [sanitize((string) $post['order_notes']), $orderId, $customerId]);
        }

        $designRequired = in_array('uploaded_design', $permissions, true);
        $designFile = $files['design_file'] ?? null;
        if ($designRequired) {
            if (!is_array($designFile) || (int) ($designFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please upload the revised design requested by staff.');
            }
            $validation = validate_file_upload($designFile);
            if (empty($validation['valid'])) {
                throw new RuntimeException((string) ($validation['message'] ?? 'The revised design is invalid.'));
            }
            $fileData = file_get_contents((string) $designFile['tmp_name']);
            if ($fileData === false) {
                throw new RuntimeException('The revised design could not be read.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file((string) $designFile['tmp_name']);
            $name = basename((string) $designFile['name']);
            $ok = db_execute(
                'UPDATE order_items SET design_image = ?, design_image_mime = ?, design_image_name = ?, design_file = NULL WHERE order_id = ?',
                'bssi',
                [$fileData, $mime, $name, $orderId]
            );
            if (!$ok) {
                throw new RuntimeException('The revised design could not be saved.');
            }
        } elseif (isset($designFile) && is_array($designFile) && (int) ($designFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('A design upload was not authorized for this revision.');
        }

        $after = printflow_revision_snapshot($orderId);
        db_execute(
            "UPDATE order_revision_requests
             SET revised_values = ?, request_status = 'Resubmitted for Review', active_flag = NULL, resubmitted_at = NOW()
             WHERE revision_request_id = ? AND active_flag = 1",
            'si',
            [printflow_revision_encode_json($after), (int) $request['revision_request_id']]
        );

        $revisionCountSql = printflow_revision_column_exists('orders', 'revision_count')
            ? ', revision_count = COALESCE(revision_count, 0) + 1'
            : '';
        db_execute(
            "UPDATE orders SET status = 'Pending Approval', design_status = 'Revision Submitted', revision_reason = '', updated_at = NOW(){$revisionCountSql}
             WHERE order_id = ? AND customer_id = ?",
            'ii',
            [$orderId, $customerId]
        );
        db_execute("UPDATE job_orders SET status = 'PENDING', updated_at = NOW() WHERE order_id = ?", 'i', [$orderId]);
        if (printflow_revision_column_exists('customizations', 'status')) {
            db_execute("UPDATE customizations SET status = 'Pending Review', updated_at = NOW() WHERE order_id = ?", 'i', [$orderId]);
        }

        if ($started && !$conn->commit()) {
            throw new RuntimeException('The revised order could not be committed.');
        }

        return [
            'revision_request_id' => (int) $request['revision_request_id'],
            'requesting_staff_id' => (int) $request['staff_id'],
            'after' => $after,
        ];
    } catch (Throwable $e) {
        if ($started) {
            $conn->rollback();
        }
        throw $e;
    }
}
