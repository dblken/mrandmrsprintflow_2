<?php
/**
 * Order line-item persistence helpers.
 * Ensures checkout always saves order_items + customizations with correct schema/types.
 */

if (!function_exists('printflow_ensure_order_items_columns')) {
    /**
     * Ensure order_items has every column checkout/staff need, with safe types.
     */
    function printflow_ensure_order_items_columns(): bool
    {
        if (!function_exists('db_table_has_column')) {
            return false;
        }

        global $conn;
        if (!$conn instanceof mysqli) {
            return false;
        }

        if (function_exists('printflow_ensure_order_items_specifications_column')) {
            printflow_ensure_order_items_specifications_column();
        }

        $adds = [
            'customization_data'  => 'LONGTEXT NULL',
            'specifications'      => 'LONGTEXT NULL',
            'design_image_mime'   => 'VARCHAR(128) NULL',
            'design_image_name'   => 'VARCHAR(255) NULL',
            'design_file'         => 'VARCHAR(512) NULL',
            'reference_image_file'=> 'VARCHAR(512) NULL',
        ];

        foreach ($adds as $col => $def) {
            if (!db_table_has_column('order_items', $col)) {
                @$conn->query("ALTER TABLE `order_items` ADD COLUMN `{$col}` {$def}");
                db_table_has_column('order_items', $col, true);
            }
        }

        if (!db_table_has_column('order_items', 'design_image')) {
            @$conn->query('ALTER TABLE `order_items` ADD COLUMN `design_image` LONGBLOB NULL');
            db_table_has_column('order_items', 'design_image', true);
        } else {
            $typeRow = db_query(
                "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'design_image'
                 LIMIT 1"
            ) ?: [];
            $dataType = strtolower((string)($typeRow[0]['DATA_TYPE'] ?? ''));
            if ($dataType !== '' && !in_array($dataType, ['blob', 'mediumblob', 'longblob', 'tinyblob'], true)) {
                @$conn->query('ALTER TABLE `order_items` MODIFY COLUMN `design_image` LONGBLOB NULL');
            }
        }

        if (db_table_has_column('order_items', 'customization_data')) {
            @$conn->query('ALTER TABLE `order_items` MODIFY COLUMN `customization_data` LONGTEXT NULL');
        }

        if (db_table_has_column('customizations', 'customization_details')) {
            @$conn->query('ALTER TABLE `customizations` MODIFY COLUMN `customization_details` LONGTEXT NULL');
        }

        return db_table_has_column('order_items', 'customization_data');
    }
}

if (!function_exists('printflow_resolve_order_item_product_id')) {
    /**
     * FK-safe product_id for order_items (services may not have a catalog product row).
     */
    function printflow_resolve_order_item_product_id(array $item, array $custom = []): int
    {
        static $firstProductId = null;

        $candidates = [];
        foreach ([
            (int)($item['product_id'] ?? 0),
            (int)($item['service_id'] ?? 0),
            (int)($custom['service_id'] ?? 0),
        ] as $pid) {
            if ($pid > 0) {
                $candidates[$pid] = true;
            }
        }

        foreach (array_keys($candidates) as $pid) {
            $chk = db_query('SELECT product_id FROM products WHERE product_id = ? LIMIT 1', 'i', [$pid]);
            if (!empty($chk)) {
                return (int)$pid;
            }
        }

        $label = strtolower(trim((string)($item['category'] ?? '') . ' ' . ($item['name'] ?? '') . ' ' . ($custom['service_type'] ?? '')));
        $skuHints = [
            'sticker' => 'STICKER',
            'decal'   => 'STICKER',
            't-shirt' => 'TSHIRT',
            'shirt'   => 'TSHIRT',
            'tarp'    => 'TARPAULIN',
            'mug'     => 'MUG',
            'poster'  => 'POSTER',
        ];
        foreach ($skuHints as $needle => $skuPrefix) {
            if (strpos($label, $needle) === false) {
                continue;
            }
            $rows = db_query(
                "SELECT product_id FROM products WHERE sku LIKE ? OR name LIKE ? ORDER BY product_id ASC LIMIT 1",
                'ss',
                [$skuPrefix . '%', '%' . $needle . '%']
            ) ?: [];
            if (!empty($rows[0]['product_id'])) {
                return (int)$rows[0]['product_id'];
            }
        }

        if ($firstProductId === null) {
            $rows = db_query('SELECT product_id FROM products ORDER BY product_id ASC LIMIT 1') ?: [];
            $firstProductId = !empty($rows[0]['product_id']) ? (int)$rows[0]['product_id'] : 0;
        }
        if ($firstProductId > 0) {
            return $firstProductId;
        }

        return 3;
    }
}

if (!function_exists('printflow_order_items_insert_line')) {
    /**
     * Insert one order_items row; retries without BLOB if blob insert fails.
     *
     * @return int order_item_id or 0
     */
    function printflow_order_items_insert_line(
        int $orderId,
        int $productId,
        int $quantity,
        float $unitPrice,
        string $customizationJson,
        ?string $designBinary = null,
        ?string $designMime = null,
        ?string $designName = null,
        ?string $designFilePath = null,
        ?string $referenceFilePath = null
    ): int {
        printflow_ensure_order_items_columns();

        $orderId = (int)$orderId;
        $productId = (int)$productId;
        $quantity = max(1, (int)$quantity);
        $unitPrice = (float)$unitPrice;
        $hasSpecs = function_exists('db_table_has_column') && db_table_has_column('order_items', 'specifications');
        $specJson = $customizationJson;

        global $conn;

        if ($designBinary !== null && $designBinary !== '' && $conn instanceof mysqli) {
            $sql = $hasSpecs
                ? 'INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data,
                    design_image, design_image_mime, design_image_name, design_file, reference_image_file, specifications)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data,
                    design_image, design_image_mime, design_image_name, design_file, reference_image_file)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $blobPlaceholder = null;
                if ($hasSpecs) {
                    $stmt->bind_param(
                        'iiidsbsssss',
                        $orderId,
                        $productId,
                        $quantity,
                        $unitPrice,
                        $customizationJson,
                        $blobPlaceholder,
                        $designMime,
                        $designName,
                        $designFilePath,
                        $referenceFilePath,
                        $specJson
                    );
                } else {
                    $stmt->bind_param(
                        'iiidsbssss',
                        $orderId,
                        $productId,
                        $quantity,
                        $unitPrice,
                        $customizationJson,
                        $blobPlaceholder,
                        $designMime,
                        $designName,
                        $designFilePath,
                        $referenceFilePath
                    );
                }
                $stmt->send_long_data(5, $designBinary);
                if ($stmt->execute()) {
                    $id = (int)$conn->insert_id;
                    $stmt->close();
                    if ($id > 0) {
                        return $id;
                    }
                } else {
                    error_log('printflow_order_items_insert_line blob failed: ' . $stmt->error);
                    $stmt->close();
                }
            }
        }

        if ($hasSpecs) {
            $id = (int)db_execute(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data, design_file, reference_image_file, specifications)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'iiidssss',
                [$orderId, $productId, $quantity, $unitPrice, $customizationJson, $designFilePath, $referenceFilePath, $specJson]
            );
        } else {
            $id = (int)db_execute(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data, design_file, reference_image_file)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'iiidsss',
                [$orderId, $productId, $quantity, $unitPrice, $customizationJson, $designFilePath, $referenceFilePath]
            );
        }

        if ($id <= 0) {
            error_log("printflow_order_items_insert_line failed for order_id={$orderId} product_id={$productId}");
        }

        return $id > 0 ? $id : 0;
    }
}

if (!function_exists('printflow_persist_service_customization_row')) {
    /**
     * Save (or update) customizations table row — works even when order_item_id is 0.
     */
    function printflow_persist_service_customization_row(
        int $orderId,
        int $orderItemId,
        int $customerId,
        string $serviceType,
        string $customizationJson
    ): int {
        if ($orderId <= 0 || $customerId <= 0 || trim($customizationJson) === '') {
            return 0;
        }

        if (!function_exists('db_table_has_column') || !db_table_has_column('customizations', 'customization_id')) {
            return 0;
        }

        $serviceType = trim($serviceType) !== '' ? trim($serviceType) : 'Service';

        if ($orderItemId > 0) {
            $existing = db_query(
                'SELECT customization_id FROM customizations WHERE order_id = ? AND order_item_id = ? LIMIT 1',
                'ii',
                [$orderId, $orderItemId]
            ) ?: [];
            if (!empty($existing[0]['customization_id'])) {
                db_execute(
                    'UPDATE customizations SET service_type = ?, customization_details = ?, updated_at = NOW() WHERE customization_id = ?',
                    'ssi',
                    [$serviceType, $customizationJson, (int)$existing[0]['customization_id']]
                );
                return (int)$existing[0]['customization_id'];
            }
        }

        $orphan = db_query(
            'SELECT customization_id FROM customizations WHERE order_id = ? AND (order_item_id IS NULL OR order_item_id = 0) ORDER BY customization_id ASC LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];
        if (!empty($orphan[0]['customization_id'])) {
            $cid = (int)$orphan[0]['customization_id'];
            db_execute(
                'UPDATE customizations SET order_item_id = ?, service_type = ?, customization_details = ?, updated_at = NOW() WHERE customization_id = ?',
                'iisi',
                [$orderItemId > 0 ? $orderItemId : 0, $serviceType, $customizationJson, $cid]
            );
            return $cid;
        }

        return (int)db_execute(
            "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'Pending Review', NOW(), NOW())",
            'iiiss',
            [$orderId, $orderItemId > 0 ? $orderItemId : 0, $customerId, $serviceType, $customizationJson]
        );
    }
}

if (!function_exists('printflow_attach_upload_paths_to_customization')) {
    /**
     * @param array<string,mixed> $custom
     * @return array<string,mixed>
     */
    function printflow_attach_upload_paths_to_customization(
        array $custom,
        ?string $designFilePath,
        ?string $referenceFilePath,
        ?string $designName = null
    ): array {
        if ($designFilePath !== null && trim($designFilePath) !== '') {
            $custom['design_upload_path'] = trim($designFilePath);
            if ($designName !== null && trim($designName) !== '') {
                $custom['Upload Design'] = trim($designName);
                $custom['design_upload'] = trim($designName);
            }
        }
        if ($referenceFilePath !== null && trim($referenceFilePath) !== '') {
            $custom['reference_upload_path'] = trim($referenceFilePath);
            $custom['reference_upload'] = basename(trim($referenceFilePath));
        }

        return $custom;
    }
}

if (!function_exists('printflow_repair_order_missing_line_items')) {
    /**
     * Backfill order_items (+ link job_orders) for orders that only have job_orders.
     *
     * @return array{repaired:bool,order_item_id:int,message:string}
     */
    function printflow_repair_order_missing_line_items(int $orderId): array
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) {
            return ['repaired' => false, 'order_item_id' => 0, 'message' => 'Invalid order id'];
        }

        $existing = db_query(
            'SELECT order_item_id FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];
        if (!empty($existing[0]['order_item_id'])) {
            $oid = (int)$existing[0]['order_item_id'];
            printflow_link_job_orders_to_order_item($orderId, $oid);
            return ['repaired' => true, 'order_item_id' => $oid, 'message' => 'order_items already exist'];
        }

        $orders = db_query(
            'SELECT o.order_id, o.customer_id, o.reference_id, o.total_amount, o.estimated_price, o.order_type, b.branch_name
             FROM orders o
             LEFT JOIN branches b ON b.id = o.branch_id
             WHERE o.order_id = ? LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];
        if ($orders === []) {
            return ['repaired' => false, 'order_item_id' => 0, 'message' => 'Order not found'];
        }
        $order = $orders[0];

        $customJson = '';
        $serviceType = 'Service';
        $custRows = db_query(
            'SELECT customization_details, service_type, order_item_id FROM customizations WHERE order_id = ? ORDER BY customization_id ASC',
            'i',
            [$orderId]
        ) ?: [];
        foreach ($custRows as $cRow) {
            $details = trim((string)($cRow['customization_details'] ?? ''));
            if ($details !== '' && !in_array($details, ['[]', '{}', 'null'], true)) {
                $customJson = $details;
                $serviceType = trim((string)($cRow['service_type'] ?? $serviceType)) ?: $serviceType;
                break;
            }
        }

        $jobRows = db_query(
            'SELECT id, job_title, service_type, notes, width_ft, height_ft, quantity, estimated_total, order_item_id
             FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];
        $job = $jobRows[0] ?? null;

        if ($customJson === '' && $job !== null) {
            $custom = [];
            $refId = (int)($order['reference_id'] ?? 0);
            if ($refId > 0) {
                $custom['service_id'] = $refId;
                $svc = db_query('SELECT name, category FROM services WHERE service_id = ? LIMIT 1', 'i', [$refId]) ?: [];
                if (!empty($svc[0]['name'])) {
                    $custom['service_type'] = (string)$svc[0]['name'];
                    $serviceType = (string)$svc[0]['name'];
                }
            }
            if (!empty($job['service_type'])) {
                $serviceType = (string)$job['service_type'];
                if (empty($custom['service_type'])) {
                    $custom['service_type'] = $serviceType;
                }
            }
            if (!empty($job['job_title']) && empty($custom['service_type'])) {
                $custom['service_type'] = (string)$job['job_title'];
                $serviceType = (string)$job['job_title'];
            }
            $branchName = trim((string)($order['branch_name'] ?? ''));
            if ($branchName !== '') {
                $custom['Branch'] = $branchName;
            }
            $qty = max(1, (int)($job['quantity'] ?? 1));
            $custom['Quantity'] = (string)$qty;
            $notes = trim((string)($job['notes'] ?? ''));
            if ($notes !== '') {
                $custom['notes'] = $notes;
                $custom['Job Notes'] = $notes;
            }
            $w = (float)($job['width_ft'] ?? 0);
            $h = (float)($job['height_ft'] ?? 0);
            if ($w > 0 && $h > 0) {
                $custom['dimensions'] = $w . 'ft x ' . $h . 'ft';
            }
            $custom['source_page'] = 'services';
            $customJson = function_exists('printflow_encode_customization_payload')
                ? printflow_encode_customization_payload($custom)
                : json_encode($custom);
        }

        if ($customJson === '') {
            return ['repaired' => false, 'order_item_id' => 0, 'message' => 'No customization data to rebuild from'];
        }

        $decoded = function_exists('customer_orders_decode_customization_payload')
            ? customer_orders_decode_customization_payload($customJson)
            : (json_decode($customJson, true) ?: []);

        $productId = printflow_resolve_order_item_product_id([], is_array($decoded) ? $decoded : []);
        $qty = max(1, (int)($job['quantity'] ?? ($decoded['Quantity'] ?? $decoded['quantity'] ?? 1)));
        $total = (float)($order['total_amount'] ?? 0);
        $est = (float)($order['estimated_price'] ?? 0);
        $unit = $total > 0 ? $total / $qty : ($est > 0 ? $est / $qty : 0.0);

        $orderItemId = printflow_order_items_insert_line(
            $orderId,
            $productId,
            $qty,
            $unit,
            $customJson,
            null,
            null,
            null,
            null,
            null
        );

        if ($orderItemId <= 0) {
            return ['repaired' => false, 'order_item_id' => 0, 'message' => 'Failed to insert order_items row'];
        }

        $customerId = (int)($order['customer_id'] ?? 0);
        if ($customerId > 0) {
            printflow_persist_service_customization_row($orderId, $orderItemId, $customerId, $serviceType, $customJson);
        }

        printflow_link_job_orders_to_order_item($orderId, $orderItemId);

        return ['repaired' => true, 'order_item_id' => $orderItemId, 'message' => 'Rebuilt order_items from stored/job data'];
    }
}

if (!function_exists('printflow_link_job_orders_to_order_item')) {
    function printflow_link_job_orders_to_order_item(int $orderId, int $orderItemId): void
    {
        if ($orderId <= 0 || $orderItemId <= 0) {
            return;
        }
        if (!function_exists('db_table_has_column') || !db_table_has_column('job_orders', 'order_item_id')) {
            return;
        }
        db_execute(
            'UPDATE job_orders SET order_item_id = ? WHERE order_id = ? AND (order_item_id IS NULL OR order_item_id = 0)',
            'ii',
            [$orderItemId, $orderId]
        );
    }
}

if (!function_exists('printflow_repair_all_orders_missing_line_items')) {
    /**
     * @return array{scanned:int,repaired:int,failed:int,details:array<int,array<string,mixed>>}
     */
    function printflow_repair_all_orders_missing_line_items(int $limit = 500): array
    {
        $limit = max(1, min(5000, (int)$limit));
        $rows = db_query(
            "SELECT o.order_id
             FROM orders o
             WHERE o.order_type = 'custom'
               AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.order_id)
               AND EXISTS (SELECT 1 FROM job_orders jo WHERE jo.order_id = o.order_id)
             ORDER BY o.order_id DESC
             LIMIT {$limit}"
        ) ?: [];

        $out = ['scanned' => count($rows), 'repaired' => 0, 'failed' => 0, 'details' => []];
        foreach ($rows as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $result = printflow_repair_order_missing_line_items($oid);
            $out['details'][] = ['order_id' => $oid] + $result;
            if (!empty($result['repaired'])) {
                $out['repaired']++;
            } else {
                $out['failed']++;
            }
        }

        return $out;
    }
}
