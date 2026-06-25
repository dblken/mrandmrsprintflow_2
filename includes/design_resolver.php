<?php
/**
 * Single source of truth for resolving customer/POS uploaded design images.
 *
 * Priority:
 *   1. order_items.design_file (verified on disk)
 *   2. order_items.design_image BLOB
 *   3. job_orders.artwork_path (legacy fallback)
 *   4. customization / reference payload paths
 */

if (!function_exists('getOrderDesignImage')) {
    /**
     * Resolve the best display URL and existence status for an order line's design.
     *
     * @param array<string,mixed> $orderItem  Raw or partial order_items row (+ optional customization_data decode)
     * @param array<string,mixed> $options    order_id, customization (decoded array), heal (bool), debug (bool)
     * @return array{
     *   url:?string,
     *   serve_url:?string,
     *   direct_url:?string,
     *   exists:bool,
     *   source:string,
     *   stored_path:?string,
     *   disk_path:?string,
     *   missing_path:?string,
     *   order_item_id:int,
     *   is_image:bool,
     *   design_name:?string
     * }
     */
    function getOrderDesignImage(array $orderItem, array $options = []): array
    {
        $orderItemId = (int)($orderItem['order_item_id'] ?? 0);
        $orderId = (int)($options['order_id'] ?? $orderItem['order_id'] ?? 0);
        $heal = !array_key_exists('heal', $options) || (bool)$options['heal'];
        $debug = (bool)($options['debug'] ?? false);

        $empty = static function (int $id) use ($orderId): array {
            return [
                'url'            => null,
                'serve_url'      => null,
                'direct_url'     => null,
                'exists'         => false,
                'source'         => 'none',
                'stored_path'    => null,
                'disk_path'      => null,
                'missing_path'   => null,
                'order_item_id'  => $id,
                'is_image'       => false,
                'design_name'    => null,
            ];
        };

        if ($orderItemId <= 0 && $orderId <= 0) {
            return $empty(0);
        }

        if ($heal && $orderItemId > 0 && function_exists('printflow_heal_order_item_design_from_payload')) {
            printflow_heal_order_item_design_from_payload($orderItemId);
        }

        if ($orderItemId > 0 && empty($orderItem['design_file']) && empty($orderItem['design_image_bytes'])) {
            $fresh = db_query(
                'SELECT order_item_id, order_id, design_file, design_image_name, design_image_mime,
                        IFNULL(LENGTH(design_image), 0) AS design_image_bytes, reference_image_file, customization_data
                 FROM order_items WHERE order_item_id = ? LIMIT 1',
                'i',
                [$orderItemId]
            ) ?: [];
            if (!empty($fresh[0])) {
                $orderItem = array_merge($orderItem, $fresh[0]);
                if ($orderId <= 0) {
                    $orderId = (int)($fresh[0]['order_id'] ?? 0);
                }
            }
        }

        $custom = is_array($options['customization'] ?? null)
            ? $options['customization']
            : [];
        if ($custom === [] && !empty($orderItem['customization_data'])) {
            $custom = function_exists('customer_orders_decode_customization_payload')
                ? customer_orders_decode_customization_payload((string)$orderItem['customization_data'])
                : [];
        }

        $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
        if ($base === '' && function_exists('pf_app_base_path')) {
            $base = rtrim((string)pf_app_base_path(), '/');
        }
        $serveUrl = $orderItemId > 0
            ? $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId
            : null;

        $designName = trim((string)($orderItem['design_image_name'] ?? ''));
        if ($designName === '') {
            $designName = trim((string)($custom['design_upload_name'] ?? ($custom['design_upload'] ?? ($custom['Upload Design'] ?? ''))));
        }

        $resolvePath = static function (?string $storedPath) use ($base): ?array {
            $storedPath = trim((string)$storedPath);
            if ($storedPath === '') {
                return null;
            }
            $normalized = function_exists('printflow_normalize_order_upload_web_path')
                ? printflow_normalize_order_upload_web_path($storedPath)
                : $storedPath;
            $disk = function_exists('printflow_resolve_order_upload_disk_path')
                ? printflow_resolve_order_upload_disk_path($normalized)
                : null;
            $directUrl = $base . $normalized;
            return [
                'stored'  => $normalized,
                'disk'    => $disk,
                'direct'  => $directUrl,
                'exists'  => $disk !== null && is_file($disk),
            ];
        };

        $looksImage = static function (?string $path, ?string $name, ?string $mime = null): bool {
            $mime = strtolower(trim((string)$mime));
            if ($mime !== '' && str_starts_with($mime, 'image/')) {
                return true;
            }
            $probe = strtolower(trim((string)($name ?: $path)));
            return $probe !== '' && preg_match('/\.(jpe?g|png|gif|webp|bmp|svg|avif|heic)$/i', $probe);
        };

        // 1. order_items.design_file
        $designFile = trim((string)($orderItem['design_file'] ?? ''));
        if ($designFile !== '') {
            $resolved = $resolvePath($designFile);
            if ($debug) {
                error_log('ORDER ITEM ID: ' . $orderItemId);
                error_log('DESIGN FILE: ' . $designFile);
                error_log('FULL PATH: ' . (string)($resolved['disk'] ?? 'null'));
            }
            if ($resolved !== null && $resolved['exists']) {
                return [
                    'url'            => $resolved['direct'],
                    'serve_url'      => $serveUrl,
                    'direct_url'     => $resolved['direct'],
                    'exists'         => true,
                    'source'         => 'design_file',
                    'stored_path'    => $resolved['stored'],
                    'disk_path'      => $resolved['disk'],
                    'missing_path'   => null,
                    'order_item_id'  => $orderItemId,
                    'is_image'       => $looksImage($resolved['stored'], $designName, (string)($orderItem['design_image_mime'] ?? '')),
                    'design_name'    => $designName !== '' ? $designName : basename($resolved['stored']),
                ];
            }
            $missingPath = $resolved['stored'] ?? $designFile;
        } else {
            $missingPath = null;
        }

        // 2. BLOB on order_items
        $blobLen = (int)($orderItem['design_image_bytes'] ?? 0);
        if ($blobLen <= 0 && isset($orderItem['design_image']) && is_string($orderItem['design_image'])) {
            $blobLen = strlen($orderItem['design_image']);
        }
        if ($blobLen > 0 && $serveUrl !== null) {
            if ($debug) {
                error_log('ORDER ITEM ID: ' . $orderItemId);
                error_log('DESIGN FILE: (blob)');
                error_log('FULL PATH: serve_design?id=' . $orderItemId);
            }
            return [
                'url'            => $serveUrl,
                'serve_url'      => $serveUrl,
                'direct_url'     => null,
                'exists'         => true,
                'source'         => 'blob',
                'stored_path'    => null,
                'disk_path'      => null,
                'missing_path'   => null,
                'order_item_id'  => $orderItemId,
                'is_image'       => $looksImage(null, $designName, (string)($orderItem['design_image_mime'] ?? '')),
                'design_name'    => $designName !== '' ? $designName : 'design',
            ];
        }

        // 3. job_orders.artwork_path (legacy)
        if ($orderItemId > 0 || $orderId > 0) {
            $jobQueries = [];
            if ($orderItemId > 0) {
                $jobQueries[] = [
                    "SELECT artwork_path FROM job_orders
                     WHERE order_item_id = ? AND TRIM(COALESCE(artwork_path, '')) <> ''
                     ORDER BY id DESC LIMIT 1",
                    'i',
                    [$orderItemId],
                ];
            }
            if ($orderId > 0) {
                $jobQueries[] = [
                    "SELECT artwork_path FROM job_orders
                     WHERE order_id = ? AND TRIM(COALESCE(artwork_path, '')) <> ''
                     ORDER BY CASE WHEN order_item_id = ? THEN 0 ELSE 1 END, id DESC LIMIT 1",
                    'ii',
                    [$orderId, $orderItemId],
                ];
            }
            foreach ($jobQueries as [$sql, $types, $params]) {
                $rows = db_query($sql, $types, $params) ?: [];
                $artwork = trim((string)($rows[0]['artwork_path'] ?? ''));
                if ($artwork === '') {
                    continue;
                }
                $resolved = $resolvePath($artwork);
                if ($resolved !== null && $resolved['exists']) {
                    if ($debug) {
                        error_log('ORDER ITEM ID: ' . $orderItemId);
                        error_log('DESIGN FILE: ' . $artwork);
                        error_log('FULL PATH: ' . (string)$resolved['disk']);
                    }
                    return [
                        'url'            => $resolved['direct'],
                        'serve_url'      => $serveUrl,
                        'direct_url'     => $resolved['direct'],
                        'exists'         => true,
                        'source'         => 'artwork_path',
                        'stored_path'    => $resolved['stored'],
                        'disk_path'      => $resolved['disk'],
                        'missing_path'   => null,
                        'order_item_id'  => $orderItemId,
                        'is_image'       => $looksImage($resolved['stored'], $designName),
                        'design_name'    => $designName !== '' ? $designName : basename($resolved['stored']),
                    ];
                }
                if ($missingPath === null) {
                    $missingPath = $resolved['stored'] ?? $artwork;
                }
            }
        }

        // 4. Customization payload paths
        $pathKeys = ['design_upload_path', 'design_file', 'upload_design_path', 'layout_file'];
        foreach ($pathKeys as $key) {
            if (empty($custom[$key]) || !is_scalar($custom[$key])) {
                continue;
            }
            $text = trim((string)$custom[$key]);
            if ($text === '' || preg_match('#^data:#i', $text)) {
                continue;
            }
            $resolved = $resolvePath($text);
            if ($resolved !== null && $resolved['exists']) {
                return [
                    'url'            => $resolved['direct'],
                    'serve_url'      => $serveUrl,
                    'direct_url'     => $resolved['direct'],
                    'exists'         => true,
                    'source'         => 'payload_path',
                    'stored_path'    => $resolved['stored'],
                    'disk_path'      => $resolved['disk'],
                    'missing_path'   => null,
                    'order_item_id'  => $orderItemId,
                    'is_image'       => $looksImage($resolved['stored'], $designName),
                    'design_name'    => $designName !== '' ? $designName : basename($resolved['stored']),
                ];
            }
            if ($missingPath === null) {
                $missingPath = $resolved['stored'] ?? $text;
            }
        }

        // Inline data URL in payload
        foreach (['design_upload_data', 'upload_design_data', 'design_data'] as $dataKey) {
            if (empty($custom[$dataKey]) || !is_scalar($custom[$dataKey])) {
                continue;
            }
            $raw = trim((string)$custom[$dataKey]);
            if ($raw !== '' && preg_match('#^data:#i', $raw)) {
                return [
                    'url'            => $raw,
                    'serve_url'      => $serveUrl,
                    'direct_url'     => null,
                    'exists'         => true,
                    'source'         => 'payload_data',
                    'stored_path'    => null,
                    'disk_path'      => null,
                    'missing_path'   => null,
                    'order_item_id'  => $orderItemId,
                    'is_image'       => true,
                    'design_name'    => $designName !== '' ? $designName : 'design',
                ];
            }
        }

        // Serve endpoint fallback when BLOB/path metadata suggests a file but disk check failed earlier
        if ($serveUrl !== null && function_exists('printflow_order_item_row_has_retrievable_design')
            && printflow_order_item_row_has_retrievable_design($orderItem)) {
            return [
                'url'            => $serveUrl,
                'serve_url'      => $serveUrl,
                'direct_url'     => null,
                'exists'         => true,
                'source'         => 'serve_fallback',
                'stored_path'    => $designFile !== '' ? $designFile : null,
                'disk_path'      => null,
                'missing_path'   => null,
                'order_item_id'  => $orderItemId,
                'is_image'       => $looksImage($designFile, $designName, (string)($orderItem['design_image_mime'] ?? '')),
                'design_name'    => $designName !== '' ? $designName : null,
            ];
        }

        if ($debug) {
            error_log('ORDER ITEM ID: ' . $orderItemId);
            error_log('DESIGN FILE: ' . ($designFile !== '' ? $designFile : '(empty)'));
            error_log('FULL PATH: ' . (string)($missingPath ?? 'not found'));
        }

        $result = $empty($orderItemId);
        $result['missing_path'] = $missingPath;
        $result['stored_path'] = $designFile !== '' ? $designFile : null;
        $result['design_name'] = $designName !== '' ? $designName : null;
        $result['serve_url'] = $serveUrl;
        return $result;
    }
}

if (!function_exists('printflow_copy_cart_design_to_orders_dir')) {
    /**
     * Copy a session temp design file into uploads/orders and return canonical web path.
     */
    function printflow_copy_cart_design_to_orders_dir(string $tmpPath, ?string $originalName = null): ?string
    {
        $tmpPath = trim($tmpPath);
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return null;
        }

        $uploadDir = printflow_order_uploads_dir();
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            error_log('printflow_copy_cart_design_to_orders_dir: uploads/orders is not writable');
            return null;
        }

        $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(pathinfo($tmpPath, PATHINFO_EXTENSION));
        }
        if ($ext === '') {
            $ext = 'jpg';
        }

        $newName = uniqid('design_') . '_' . time() . '.' . $ext;
        $dest = $uploadDir . DIRECTORY_SEPARATOR . $newName;
        if (!@copy($tmpPath, $dest)) {
            error_log('printflow_copy_cart_design_to_orders_dir: copy failed for ' . $tmpPath);
            return null;
        }

        return printflow_normalize_order_upload_web_path('/uploads/orders/' . $newName);
    }
}

if (!function_exists('printflow_sync_job_orders_artwork_for_order_item')) {
    /**
     * Copy order_items.design_file onto linked job_orders.artwork_path when empty.
     */
    function printflow_sync_job_orders_artwork_for_order_item(int $orderItemId): void
    {
        if ($orderItemId <= 0) {
            return;
        }

        $rows = db_query(
            'SELECT order_id, design_file, design_image_name FROM order_items WHERE order_item_id = ? LIMIT 1',
            'i',
            [$orderItemId]
        ) ?: [];
        if ($rows === []) {
            return;
        }

        $designFile = trim((string)($rows[0]['design_file'] ?? ''));
        $artworkPath = $designFile;
        if ($artworkPath === '') {
            $name = trim((string)($rows[0]['design_image_name'] ?? ''));
            $artworkPath = $name;
        }
        if ($artworkPath === '') {
            return;
        }

        $orderId = (int)($rows[0]['order_id'] ?? 0);

        db_execute(
            "UPDATE job_orders SET artwork_path = ?
             WHERE order_item_id = ? AND TRIM(COALESCE(artwork_path, '')) = ''",
            'si',
            [$artworkPath, $orderItemId]
        );

        if ($orderId > 0) {
            db_execute(
                "UPDATE job_orders SET artwork_path = ?
                 WHERE order_id = ? AND (order_item_id IS NULL OR order_item_id = 0 OR order_item_id = ?)
                   AND TRIM(COALESCE(artwork_path, '')) = ''",
                'sii',
                [$artworkPath, $orderId, $orderItemId]
            );
        }
    }
}
