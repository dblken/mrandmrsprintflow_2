<?php
/**
 * Order line-item persistence helpers.
 * Ensures checkout always saves order_items + customizations with correct schema/types.
 */

require_once __DIR__ . '/design_resolver.php';

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

if (!function_exists('printflow_order_uploads_dir')) {
    function printflow_order_uploads_dir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'orders';
    }
}

if (!function_exists('printflow_normalize_order_upload_web_path')) {
    /**
     * Canonical web path for persisted order uploads: /uploads/orders/filename.ext
     */
    function printflow_normalize_order_upload_web_path(string $storedPath): string
    {
        $path = trim(str_replace('\\', '/', $storedPath));
        if ($path === '') {
            return '';
        }

        $pathOnly = parse_url($path, PHP_URL_PATH);
        if (is_string($pathOnly) && $pathOnly !== '') {
            $path = $pathOnly;
        }

        $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/printflow/')) {
            $path = substr($path, strlen('/printflow'));
        }

        if (preg_match('#(/uploads/orders/.+)$#', $path, $m)) {
            return $m[1];
        }

        if (str_starts_with($path, '/uploads/orders/')) {
            return $path;
        }

        if ($path !== '' && $path[0] !== '/') {
            if (str_starts_with($path, 'uploads/orders/')) {
                return '/' . $path;
            }
            return '/uploads/orders/' . ltrim($path, '/');
        }

        if ($path !== '' && !str_contains($path, '/')) {
            return '/uploads/orders/' . $path;
        }

        return $path;
    }
}

if (!function_exists('printflow_resolve_order_upload_disk_path')) {
    function printflow_resolve_order_upload_disk_path(string $storedPath): ?string
    {
        $webPath = printflow_normalize_order_upload_web_path($storedPath);
        if ($webPath === '') {
            return null;
        }

        $root = dirname(__DIR__);
        $candidates = [
            $root . str_replace('/', DIRECTORY_SEPARATOR, $webPath),
        ];
        if (str_starts_with($webPath, '/uploads/')) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $webPath);
        }

        foreach ($candidates as $abs) {
            if (is_file($abs)) {
                return $abs;
            }
        }

        return null;
    }
}

if (!function_exists('printflow_order_item_row_has_retrievable_design')) {
    /**
     * @param array<string,mixed> $row
     */
    function printflow_order_item_row_has_retrievable_design(array $row): bool
    {
        $blobLen = (int)($row['design_image_bytes'] ?? $row['blob_len'] ?? 0);
        if ($blobLen > 0) {
            return true;
        }

        if (isset($row['design_image']) && is_string($row['design_image']) && strlen($row['design_image']) > 0) {
            return true;
        }

        $designFile = trim((string)($row['design_file'] ?? ''));
        if ($designFile !== '' && printflow_resolve_order_upload_disk_path($designFile) !== null) {
            return true;
        }

        return false;
    }
}

if (!function_exists('printflow_write_order_upload_file')) {
    function printflow_write_order_upload_file(string $binary, string $originalName, int $orderItemId, string $prefix = 'design'): ?string
    {
        if ($binary === '') {
            return null;
        }

        $uploadDir = printflow_order_uploads_dir();
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            error_log('printflow_write_order_upload_file: uploads/orders is not writable');
            return null;
        }

        $baseName = trim($originalName) !== '' ? trim($originalName) : ($prefix . '.bin');
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName);
        if ($safeName === '' || $safeName === null) {
            $safeName = $prefix . '.bin';
        }

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
            $safeName .= '.jpg';
        }

        $targetName = time() . '_' . $prefix . '_' . max(0, $orderItemId) . '_' . $safeName;
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
        if (@file_put_contents($targetPath, $binary) === false) {
            error_log('printflow_write_order_upload_file: failed writing ' . $targetName);
            return null;
        }

        return '/uploads/orders/' . $targetName;
    }
}

if (!function_exists('printflow_persist_order_item_design_media')) {
    /**
     * Save customer design to disk + order_items BLOB columns. Returns canonical web path.
     */
    function printflow_persist_order_item_design_media(
        int $orderItemId,
        ?string $binary = null,
        ?string $mime = null,
        ?string $name = null,
        ?string $sourceTmpPath = null
    ): ?string {
        if ($orderItemId <= 0) {
            return null;
        }

        printflow_ensure_order_items_columns();

        if (($binary === null || $binary === '') && $sourceTmpPath !== null && $sourceTmpPath !== '' && is_file($sourceTmpPath)) {
            $binary = @file_get_contents($sourceTmpPath);
        }

        if ($binary === null || $binary === '') {
            return null;
        }

        $mime = trim((string)$mime) !== '' ? trim((string)$mime) : 'application/octet-stream';
        $name = trim((string)$name) !== '' ? trim((string)$name) : 'design_upload';

        $webPath = printflow_write_order_upload_file($binary, $name, $orderItemId, 'design');
        if ($webPath === null) {
            return null;
        }

        global $conn;
        $blobSaved = false;
        if ($conn instanceof mysqli) {
            $stmt = $conn->prepare(
                'UPDATE order_items
                 SET design_image = ?, design_image_mime = ?, design_image_name = ?, design_file = ?
                 WHERE order_item_id = ?'
            );
            if ($stmt) {
                $nullBlob = null;
                $stmt->bind_param('bsssi', $nullBlob, $mime, $name, $webPath, $orderItemId);
                $stmt->send_long_data(0, $binary);
                $blobSaved = $stmt->execute();
                if (!$blobSaved) {
                    error_log('printflow_persist_order_item_design_media blob failed: ' . $stmt->error);
                }
                $stmt->close();
            }
        }

        if (!$blobSaved) {
            db_execute(
                'UPDATE order_items
                 SET design_image_mime = ?, design_image_name = ?, design_file = ?
                 WHERE order_item_id = ?',
                'sssi',
                [$mime, $name, $webPath, $orderItemId]
            );
        }

        return $webPath;
    }
}

if (!function_exists('printflow_heal_order_item_design_from_payload')) {
    /**
     * Recover missing files from customization JSON (inline base64 or stored path metadata).
     */
    function printflow_heal_order_item_design_from_payload(int $orderItemId): bool
    {
        if ($orderItemId <= 0) {
            return false;
        }

        $rows = db_query(
            'SELECT design_file, IFNULL(LENGTH(design_image), 0) AS blob_len, customization_data
             FROM order_items WHERE order_item_id = ? LIMIT 1',
            'i',
            [$orderItemId]
        ) ?: [];
        if ($rows === []) {
            return false;
        }

        $row = $rows[0];
        if (printflow_order_item_row_has_retrievable_design($row)) {
            return true;
        }

        $payload = function_exists('customer_orders_decode_customization_payload')
            ? customer_orders_decode_customization_payload((string)($row['customization_data'] ?? ''))
            : [];

        foreach (['design_upload_data', 'upload_design_data', 'design_data'] as $dataKey) {
            if (empty($payload[$dataKey]) || !is_scalar($payload[$dataKey])) {
                continue;
            }
            $raw = trim((string)$payload[$dataKey]);
            if ($raw === '') {
                continue;
            }
            $binary = null;
            $mime = trim((string)($payload['design_upload_mime'] ?? ''));
            if (preg_match('#^data:([^;]+);base64,(.+)$#s', $raw, $matches)) {
                $binary = base64_decode($matches[2], true);
                if ($mime === '') {
                    $mime = trim((string)$matches[1]);
                }
            } else {
                $binary = base64_decode(preg_replace('/\s+/', '', $raw), true);
            }
            if ($binary !== false && $binary !== '') {
                $name = trim((string)($payload['design_upload_name'] ?? ($payload['design_upload'] ?? 'design')));
                return printflow_persist_order_item_design_media($orderItemId, $binary, $mime, $name) !== null;
            }
        }

        foreach (['design_upload_path', 'design_file', 'upload_design_path'] as $pathKey) {
            if (empty($payload[$pathKey]) || !is_scalar($payload[$pathKey])) {
                continue;
            }
            $disk = printflow_resolve_order_upload_disk_path((string)$payload[$pathKey]);
            if ($disk === null) {
                continue;
            }
            $binary = @file_get_contents($disk);
            if ($binary === false || $binary === '') {
                continue;
            }
            $name = trim((string)($payload['design_upload_name'] ?? ($payload['design_upload'] ?? basename($disk))));
            return printflow_persist_order_item_design_media($orderItemId, $binary, '', $name) !== null;
        }

        $custRows = db_query(
            'SELECT customization_details FROM customizations WHERE order_item_id = ? ORDER BY customization_id DESC LIMIT 3',
            'i',
            [$orderItemId]
        ) ?: [];
        foreach ($custRows as $custRow) {
            $custPayload = function_exists('customer_orders_decode_customization_payload')
                ? customer_orders_decode_customization_payload((string)($custRow['customization_details'] ?? ''))
                : [];
            if ($custPayload === []) {
                continue;
            }
            foreach (['design_upload_data', 'upload_design_data', 'design_data'] as $dataKey) {
                if (empty($custPayload[$dataKey]) || !is_scalar($custPayload[$dataKey])) {
                    continue;
                }
                $raw = trim((string)$custPayload[$dataKey]);
                if ($raw === '') {
                    continue;
                }
                $binary = null;
                $mime = trim((string)($custPayload['design_upload_mime'] ?? ''));
                if (preg_match('#^data:([^;]+);base64,(.+)$#s', $raw, $matches)) {
                    $binary = base64_decode($matches[2], true);
                    if ($mime === '') {
                        $mime = trim((string)$matches[1]);
                    }
                } else {
                    $binary = base64_decode(preg_replace('/\s+/', '', $raw), true);
                }
                if ($binary !== false && $binary !== '') {
                    $name = trim((string)($custPayload['design_upload_name'] ?? ($custPayload['design_upload'] ?? 'design')));
                    return printflow_persist_order_item_design_media($orderItemId, $binary, $mime, $name) !== null;
                }
            }
            foreach (['design_upload_path', 'design_file', 'upload_design_path'] as $pathKey) {
                if (empty($custPayload[$pathKey]) || !is_scalar($custPayload[$pathKey])) {
                    continue;
                }
                $disk = printflow_resolve_order_upload_disk_path((string)$custPayload[$pathKey]);
                if ($disk === null) {
                    continue;
                }
                $binary = @file_get_contents($disk);
                if ($binary === false || $binary === '') {
                    continue;
                }
                $name = trim((string)($custPayload['design_upload_name'] ?? ($custPayload['design_upload'] ?? basename($disk))));
                return printflow_persist_order_item_design_media($orderItemId, $binary, '', $name) !== null;
            }
        }

        return false;
    }
}

if (!function_exists('printflow_order_item_has_retrievable_design_by_id')) {
    function printflow_order_item_has_retrievable_design_by_id(int $orderItemId): bool
    {
        if ($orderItemId <= 0) {
            return false;
        }

        $rows = db_query(
            'SELECT design_file, IFNULL(LENGTH(design_image), 0) AS blob_len, design_image, customization_data
             FROM order_items WHERE order_item_id = ? LIMIT 1',
            'i',
            [$orderItemId]
        ) ?: [];

        if ($rows === []) {
            return false;
        }

        if (printflow_order_item_row_has_retrievable_design($rows[0])) {
            return true;
        }

        if (!printflow_heal_order_item_design_from_payload($orderItemId)) {
            return false;
        }

        $healed = db_query(
            'SELECT design_file, IFNULL(LENGTH(design_image), 0) AS blob_len, design_image
             FROM order_items WHERE order_item_id = ? LIMIT 1',
            'i',
            [$orderItemId]
        ) ?: [];

        return $healed !== [] && printflow_order_item_row_has_retrievable_design($healed[0]);
    }
}

if (!function_exists('printflow_resolve_order_service_catalog_image_url')) {
    /**
     * Same catalog art as customer/services.php (not customer uploads).
     *
     * @param array<string,mixed> $order
     */
    function printflow_resolve_order_service_catalog_image_url(array $order, string $displayName = ''): string
    {
        $custom = function_exists('customer_orders_primary_customization')
            ? customer_orders_primary_customization($order)
            : [];

        $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
        $isServiceOrder = ($orderType === 'custom')
            || !empty($custom['service_type'])
            || (int)($custom['service_id'] ?? 0) > 0
            || in_array(strtolower(trim((string)($custom['source_page'] ?? ''))), ['services', 'service'], true);

        if (!$isServiceOrder) {
            if (!empty($order['first_product_image'])) {
                $resolved = pf_order_ui_asset_url((string)$order['first_product_image']);
                if ($resolved !== null && $resolved !== '') {
                    return $resolved;
                }
            }

            $prodId = (int)($order['first_product_id'] ?? 0);
            if ($prodId > 0) {
                $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
                $imgBase = dirname(__DIR__) . '/public/images/products/product_' . $prodId;
                foreach (['jpg', 'png', 'jpeg', 'webp'] as $ext) {
                    if (is_file($imgBase . '.' . $ext)) {
                        return $base . '/public/images/products/product_' . $prodId . '.' . $ext;
                    }
                }
            }
        }

        $serviceName = trim((string)($custom['service_type'] ?? ($order['first_customization_service_type'] ?? '')));
        if ($serviceName === '' || (function_exists('customer_orders_is_generic_item_name') && customer_orders_is_generic_item_name($serviceName))) {
            $serviceName = trim((string)(function_exists('get_service_name_from_customization')
                ? get_service_name_from_customization($custom, '')
                : ''));
        }
        if (($serviceName === '' || (function_exists('customer_orders_is_generic_item_name') && customer_orders_is_generic_item_name($serviceName)))
            && trim($displayName) !== '') {
            $serviceName = trim($displayName);
        }

        $sid = (int)($custom['service_id'] ?? 0);
        if ($sid <= 0 && $orderType === 'custom') {
            $sid = (int)($order['reference_id'] ?? 0);
        }

        if ($sid > 0 && function_exists('printflow_service_catalog_image_from_id')) {
            $fromId = printflow_service_catalog_image_from_id($sid);
            if ($fromId !== '') {
                return $fromId;
            }
        }

        if ($serviceName !== '' && function_exists('printflow_service_catalog_image_from_name')) {
            $fromName = printflow_service_catalog_image_from_name($serviceName);
            if ($fromName !== '') {
                return $fromName;
            }
        }

        if (function_exists('get_service_image_url')) {
            return get_service_image_url($serviceName !== '' ? $serviceName : $displayName, $sid);
        }

        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '');
        return $base . '/public/assets/images/services/default.png';
    }
}

if (!function_exists('printflow_resolve_order_preview_image_url')) {
    /**
     * Order list thumbnail: customer upload when retrievable, else service catalog art.
     *
     * @param array<string,mixed> $order
     */
    function printflow_resolve_order_preview_image_url(array $order, string $displayName = ''): string
    {
        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '');
        $catalogFallback = printflow_resolve_order_service_catalog_image_url($order, $displayName);

        $firstItemId = (int)($order['first_item_id'] ?? 0);
        if ($firstItemId > 0 && function_exists('getOrderDesignImage')) {
            $row = db_query(
                'SELECT order_item_id, order_id, design_file, design_image_mime,
                        IFNULL(LENGTH(design_image), 0) AS design_image_bytes, customization_data
                 FROM order_items WHERE order_item_id = ? LIMIT 1',
                'i',
                [$firstItemId]
            ) ?: [];
            if (!empty($row[0])) {
                $design = getOrderDesignImage($row[0], [
                    'order_id' => (int)($order['order_id'] ?? $row[0]['order_id'] ?? 0),
                    'heal'     => true,
                ]);
                if (!empty($design['exists'])) {
                    if (!empty($design['direct_url'])) {
                        return (string)$design['direct_url'];
                    }
                    if (!empty($design['serve_url'])) {
                        return (string)$design['serve_url'];
                    }
                    if (!empty($design['url'])) {
                        return (string)$design['url'];
                    }
                }
            }
        }

        return $catalogFallback !== '' ? $catalogFallback : ($base . '/public/assets/images/services/default.png');
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

        if ($designFilePath !== null && trim((string)$designFilePath) !== '') {
            $designFilePath = printflow_normalize_order_upload_web_path((string)$designFilePath);
        }
        if ($referenceFilePath !== null && trim((string)$referenceFilePath) !== '') {
            $referenceFilePath = printflow_normalize_order_upload_web_path((string)$referenceFilePath);
        }

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
                        if ($designFilePath !== null && trim((string)$designFilePath) !== ''
                            && printflow_resolve_order_upload_disk_path((string)$designFilePath) === null
                            && $designBinary !== null && $designBinary !== '') {
                            printflow_persist_order_item_design_media(
                                $id,
                                $designBinary,
                                $designMime,
                                $designName
                            );
                        }
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
        } elseif ($designBinary !== null && $designBinary !== '') {
            printflow_persist_order_item_design_media(
                $id,
                $designBinary,
                $designMime,
                $designName,
                null
            );
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
            $normalized = printflow_normalize_order_upload_web_path(trim($designFilePath));
            $custom['design_upload_path'] = $normalized;
            $custom['design_file'] = $normalized;
            if ($designName !== null && trim($designName) !== '') {
                $custom['Upload Design'] = trim($designName);
                $custom['design_upload'] = trim($designName);
            }
        }
        if ($referenceFilePath !== null && trim($referenceFilePath) !== '') {
            $normalizedRef = printflow_normalize_order_upload_web_path(trim($referenceFilePath));
            $custom['reference_upload_path'] = $normalizedRef;
            $custom['reference_upload'] = basename($normalizedRef);
            $custom['reference_file'] = $normalizedRef;
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
