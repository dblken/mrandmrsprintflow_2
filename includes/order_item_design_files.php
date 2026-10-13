<?php
declare(strict_types=1);

require_once __DIR__ . '/ensure_order_item_design_files_schema.php';

if (!function_exists('printflow_order_item_design_files_base_url')) {
    function printflow_order_item_design_files_base_url(): string
    {
        if (defined('BASE_URL') && trim((string)BASE_URL) !== '') {
            return rtrim((string)BASE_URL, '/');
        }
        if (function_exists('pf_app_base_path')) {
            return rtrim((string)pf_app_base_path(), '/');
        }
        return '';
    }
}

if (!function_exists('printflow_order_item_design_file_serve_urls')) {
    /**
     * @return array{preview_url:string,download_url:string}
     */
    function printflow_order_item_design_file_serve_urls(int $fileId, int $orderItemId = 0): array
    {
        $base = printflow_order_item_design_files_base_url();
        $qs = 'type=order_item_file&id=' . max(0, $fileId);
        if ($orderItemId > 0) {
            $qs .= '&item_id=' . $orderItemId;
        }
        $url = $base . '/public/serve_design.php?' . $qs;
        return ['preview_url' => $url, 'download_url' => $url];
    }
}

if (!function_exists('printflow_order_item_design_files_normalize_rows')) {
    /**
     * Deduplicate by file_id and sort by upload_sort, file_id.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    function printflow_order_item_design_files_normalize_rows(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fileId = (int)($row['file_id'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }
            $byId[$fileId] = $row;
        }
        $out = array_values($byId);
        usort($out, static function (array $a, array $b): int {
            $sortA = (int)($a['upload_sort'] ?? 0);
            $sortB = (int)($b['upload_sort'] ?? 0);
            if ($sortA !== $sortB) {
                return $sortA <=> $sortB;
            }
            return ((int)($a['file_id'] ?? 0)) <=> ((int)($b['file_id'] ?? 0));
        });
        return $out;
    }
}

if (!function_exists('printflow_order_item_design_files_to_api')) {
    /**
     * @param list<array<string,mixed>> $dbRows
     * @return list<array<string,mixed>>
     */
    function printflow_order_item_design_files_to_api(array $dbRows): array
    {
        $normalized = printflow_order_item_design_files_normalize_rows($dbRows);
        $out = [];
        foreach ($normalized as $row) {
            $fileId = (int)($row['file_id'] ?? 0);
            $orderItemId = (int)($row['order_item_id'] ?? 0);
            $name = trim((string)($row['display_name'] ?? ''));
            if ($name === '') {
                $name = 'Uploaded design';
            }
            $mime = trim((string)($row['mime_type'] ?? ''));
            $urls = printflow_order_item_design_file_serve_urls($fileId, $orderItemId);
            $isPreviewable = $mime !== '' ? str_starts_with(strtolower($mime), 'image/') : false;
            if (!$isPreviewable && $name !== '') {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $isPreviewable = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'], true);
            }
            $out[] = [
                'file_id' => $fileId,
                'order_item_id' => $orderItemId,
                'customization_id' => isset($row['customization_id']) ? (int)$row['customization_id'] : null,
                'file_role' => (string)($row['file_role'] ?? 'design'),
                'display_name' => $name,
                'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                'file_size' => (int)($row['file_size'] ?? 0),
                'upload_sort' => (int)($row['upload_sort'] ?? 0),
                'storage_path' => (string)($row['storage_path'] ?? ''),
                'preview_url' => $urls['preview_url'],
                'download_url' => $urls['download_url'],
                'is_previewable_image' => $isPreviewable,
                'exists' => printflow_order_item_design_file_exists_on_disk($row),
            ];
        }
        return $out;
    }
}

if (!function_exists('printflow_order_item_design_file_exists_on_disk')) {
    /**
     * @param array<string,mixed> $row
     */
    function printflow_order_item_design_file_exists_on_disk(array $row): bool
    {
        $path = trim((string)($row['storage_path'] ?? ''));
        if ($path === '') {
            return false;
        }
        if (function_exists('printflow_resolve_order_upload_disk_path')) {
            $disk = printflow_resolve_order_upload_disk_path($path);
            return $disk !== null && is_file($disk);
        }
        return false;
    }
}

if (!function_exists('printflow_order_item_design_file_idempotency_key')) {
    function printflow_order_item_design_file_idempotency_key(
        int $orderItemId,
        string $clientKey,
        string $storagePath = '',
        string $displayName = ''
    ): string {
        $clientKey = trim($clientKey);
        if ($clientKey !== '') {
            return substr(preg_replace('/[^A-Za-z0-9._:-]/', '-', $clientKey) ?? '', 0, 64);
        }
        $seed = implode('|', [
            (string)$orderItemId,
            trim($storagePath),
            trim($displayName),
        ]);
        return substr(hash('sha256', $seed), 0, 64);
    }
}

if (!function_exists('printflow_order_item_design_files_next_sort')) {
    function printflow_order_item_design_files_next_sort(int $orderItemId): int
    {
        if ($orderItemId <= 0) {
            return 0;
        }
        printflow_ensure_order_item_design_files_schema();
        $rows = db_query(
            'SELECT COALESCE(MAX(upload_sort), -1) AS max_sort FROM order_item_design_files WHERE order_item_id = ?',
            'i',
            [$orderItemId]
        ) ?: [];
        return (int)($rows[0]['max_sort'] ?? -1) + 1;
    }
}

if (!function_exists('printflow_order_item_design_file_register')) {
    /**
     * Insert or return existing row by idempotency_key. Does not overwrite storage on duplicate.
     *
     * @return array{file_id:int,reused:bool,row:array<string,mixed>|null}
     */
    function printflow_order_item_design_file_register(array $input): array
    {
        printflow_ensure_order_item_design_files_schema();

        $orderItemId = max(0, (int)($input['order_item_id'] ?? 0));
        $orderId = max(0, (int)($input['order_id'] ?? 0));
        $customerId = max(0, (int)($input['customer_id'] ?? 0));
        $customizationId = max(0, (int)($input['customization_id'] ?? 0));
        $role = trim((string)($input['file_role'] ?? 'design'));
        if ($role === '') {
            $role = 'design';
        }
        $displayName = trim((string)($input['display_name'] ?? ''));
        $mime = trim((string)($input['mime_type'] ?? ''));
        $storagePath = trim((string)($input['storage_path'] ?? ''));
        $fileSize = max(0, (int)($input['file_size'] ?? 0));
        $uploadSort = array_key_exists('upload_sort', $input) ? max(0, (int)$input['upload_sort']) : null;
        $idempotencyKey = printflow_order_item_design_file_idempotency_key(
            $orderItemId,
            trim((string)($input['idempotency_key'] ?? '')),
            $storagePath,
            $displayName
        );

        if ($orderItemId <= 0 || $storagePath === '' || $idempotencyKey === '') {
            return ['file_id' => 0, 'reused' => false, 'row' => null];
        }

        if ($orderId <= 0 || $customerId <= 0) {
            $ctx = db_query(
                'SELECT oi.order_id, o.customer_id
                 FROM order_items oi
                 INNER JOIN orders o ON o.order_id = oi.order_id
                 WHERE oi.order_item_id = ?
                 LIMIT 1',
                'i',
                [$orderItemId]
            ) ?: [];
            if (!empty($ctx[0])) {
                if ($orderId <= 0) {
                    $orderId = (int)($ctx[0]['order_id'] ?? 0);
                }
                if ($customerId <= 0) {
                    $customerId = (int)($ctx[0]['customer_id'] ?? 0);
                }
            }
        }

        $existing = db_query(
            'SELECT * FROM order_item_design_files WHERE idempotency_key = ? LIMIT 1',
            's',
            [$idempotencyKey]
        ) ?: [];
        if (!empty($existing[0])) {
            return [
                'file_id' => (int)($existing[0]['file_id'] ?? 0),
                'reused' => true,
                'row' => $existing[0],
            ];
        }

        if ($uploadSort === null) {
            $uploadSort = printflow_order_item_design_files_next_sort($orderItemId);
        }

        if ($fileSize <= 0 && function_exists('printflow_resolve_order_upload_disk_path')) {
            $disk = printflow_resolve_order_upload_disk_path($storagePath);
            if ($disk !== null && is_file($disk)) {
                $fileSize = (int)filesize($disk);
            }
        }

        if ($displayName === '') {
            $displayName = basename(str_replace('\\', '/', $storagePath));
        }
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $ok = db_execute(
            'INSERT INTO order_item_design_files
                (order_item_id, order_id, customer_id, customization_id, file_role,
                 display_name, mime_type, file_size, storage_path, upload_sort, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiiisssisis',
            [
                $orderItemId,
                $orderId,
                $customerId,
                $customizationId > 0 ? $customizationId : 0,
                $role,
                $displayName,
                $mime,
                $fileSize,
                $storagePath,
                $uploadSort,
                $idempotencyKey,
            ]
        );

        if (!$ok) {
            $again = db_query(
                'SELECT * FROM order_item_design_files WHERE idempotency_key = ? LIMIT 1',
                's',
                [$idempotencyKey]
            ) ?: [];
            if (!empty($again[0])) {
                return [
                    'file_id' => (int)($again[0]['file_id'] ?? 0),
                    'reused' => true,
                    'row' => $again[0],
                ];
            }
            return ['file_id' => 0, 'reused' => false, 'row' => null];
        }

        global $conn;
        $fileId = ($conn instanceof mysqli) ? (int)$conn->insert_id : 0;
        $row = $fileId > 0
            ? (db_query('SELECT * FROM order_item_design_files WHERE file_id = ? LIMIT 1', 'i', [$fileId]) ?: [])[0] ?? null
            : null;

        return ['file_id' => $fileId, 'reused' => false, 'row' => is_array($row) ? $row : null];
    }
}

if (!function_exists('printflow_order_item_design_files_list_raw')) {
    /**
     * @return list<array<string,mixed>>
     */
    function printflow_order_item_design_files_list_raw(int $orderItemId, bool $backfillLegacy = true): array
    {
        if ($orderItemId <= 0) {
            return [];
        }
        printflow_ensure_order_item_design_files_schema();
        if ($backfillLegacy) {
            printflow_order_item_design_files_backfill_legacy($orderItemId);
        }
        $rows = db_query(
            'SELECT * FROM order_item_design_files WHERE order_item_id = ? ORDER BY upload_sort ASC, file_id ASC',
            'i',
            [$orderItemId]
        ) ?: [];
        return printflow_order_item_design_files_normalize_rows($rows);
    }
}

if (!function_exists('printflow_order_item_design_files_list_for_item')) {
    /**
     * Authoritative API list for one order item.
     *
     * @return list<array<string,mixed>>
     */
    function printflow_order_item_design_files_list_for_item(int $orderItemId, bool $backfillLegacy = true): array
    {
        return printflow_order_item_design_files_to_api(
            printflow_order_item_design_files_list_raw($orderItemId, $backfillLegacy)
        );
    }
}

if (!function_exists('printflow_order_item_design_files_list_for_items')) {
    /**
     * @param list<int>|array<int,int> $orderItemIds
     * @return array<int,list<array<string,mixed>>>
     */
    function printflow_order_item_design_files_list_for_items(array $orderItemIds, bool $backfillLegacy = true): array
    {
        $ids = [];
        foreach ($orderItemIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        printflow_ensure_order_item_design_files_schema();
        if ($backfillLegacy) {
            foreach ($ids as $orderItemId) {
                printflow_order_item_design_files_backfill_legacy($orderItemId);
            }
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $rows = db_query(
            "SELECT * FROM order_item_design_files WHERE order_item_id IN ({$placeholders}) ORDER BY order_item_id ASC, upload_sort ASC, file_id ASC",
            $types,
            array_values($ids)
        ) ?: [];
        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }
        foreach ($rows as $row) {
            $oid = (int)($row['order_item_id'] ?? 0);
            if ($oid <= 0 || !isset($grouped[$oid])) {
                continue;
            }
            $grouped[$oid][] = $row;
        }
        foreach ($grouped as $oid => $list) {
            $grouped[$oid] = printflow_order_item_design_files_to_api($list);
        }
        return $grouped;
    }
}

if (!function_exists('printflow_order_item_design_files_backfill_legacy')) {
    function printflow_order_item_design_files_backfill_legacy(int $orderItemId): void
    {
        if ($orderItemId <= 0) {
            return;
        }
        printflow_ensure_order_item_design_files_schema();

        $existing = db_query(
            'SELECT file_id FROM order_item_design_files WHERE order_item_id = ? LIMIT 1',
            'i',
            [$orderItemId]
        ) ?: [];
        $hasAny = $existing !== [];

        $itemRows = db_query(
            'SELECT oi.order_item_id, oi.order_id, oi.design_file, oi.design_image_name, oi.design_image_mime,
                    IFNULL(LENGTH(oi.design_image), 0) AS design_blob_len, oi.customization_data, o.customer_id
             FROM order_items oi
             INNER JOIN orders o ON o.order_id = oi.order_id
             WHERE oi.order_item_id = ?
             LIMIT 1',
            'i',
            [$orderItemId]
        ) ?: [];
        if ($itemRows === []) {
            return;
        }
        $item = $itemRows[0];
        $orderId = (int)($item['order_id'] ?? 0);
        $customerId = (int)($item['customer_id'] ?? 0);
        $custom = function_exists('customer_orders_decode_customization_payload')
            ? customer_orders_decode_customization_payload((string)($item['customization_data'] ?? ''))
            : [];

        $candidates = [];
        $sort = 0;

        $primaryPath = trim((string)($item['design_file'] ?? ''));
        if ($primaryPath !== '') {
            $candidates[] = [
                'path' => $primaryPath,
                'name' => trim((string)($item['design_image_name'] ?? '')) ?: basename($primaryPath),
                'mime' => trim((string)($item['design_image_mime'] ?? '')),
                'sort' => $sort++,
                'key' => 'legacy-primary-' . $orderItemId . '-' . hash('sha256', $primaryPath),
            ];
        }

        foreach (['design_upload_path', 'design_file', 'upload_design_path'] as $key) {
            $path = trim((string)($custom[$key] ?? ''));
            if ($path === '' || $path === $primaryPath) {
                continue;
            }
            $candidates[] = [
                'path' => $path,
                'name' => trim((string)($custom['design_upload_name'] ?? ($custom['design_upload'] ?? basename($path)))),
                'mime' => trim((string)($custom['design_upload_mime'] ?? '')),
                'sort' => $sort++,
                'key' => 'legacy-custom-' . $orderItemId . '-' . hash('sha256', $path),
            ];
        }

        foreach (['design_upload_files', '_uploaded_files'] as $listKey) {
            if (empty($custom[$listKey]) || !is_array($custom[$listKey])) {
                continue;
            }
            foreach ($custom[$listKey] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $path = trim((string)($entry['path'] ?? ($entry['storage_path'] ?? ($entry['design_upload_path'] ?? ''))));
                if ($path === '') {
                    continue;
                }
                $candidates[] = [
                    'path' => $path,
                    'name' => trim((string)($entry['name'] ?? ($entry['display_name'] ?? ($entry['label'] ?? basename($path))))),
                    'mime' => trim((string)($entry['mime'] ?? ($entry['mime_type'] ?? ''))),
                    'sort' => array_key_exists('upload_sort', $entry) ? (int)$entry['upload_sort'] : $sort++,
                    'key' => trim((string)($entry['idempotency_key'] ?? '')) !== ''
                        ? (string)$entry['idempotency_key']
                        : 'legacy-list-' . $orderItemId . '-' . hash('sha256', $path . '|' . ($entry['name'] ?? '')),
                ];
            }
        }

        if ($candidates === [] && $hasAny) {
            return;
        }

        $seenPath = [];
        foreach ($candidates as $candidate) {
            $path = function_exists('printflow_normalize_order_upload_web_path')
                ? printflow_normalize_order_upload_web_path((string)$candidate['path'])
                : (string)$candidate['path'];
            if ($path === '' || isset($seenPath[$path])) {
                continue;
            }
            $seenPath[$path] = true;
            printflow_order_item_design_file_register([
                'order_item_id' => $orderItemId,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'display_name' => (string)$candidate['name'],
                'mime_type' => (string)$candidate['mime'],
                'storage_path' => $path,
                'upload_sort' => (int)$candidate['sort'],
                'idempotency_key' => (string)$candidate['key'],
                'file_role' => 'design',
            ]);
        }
    }
}

if (!function_exists('printflow_order_item_design_files_sync_from_customization')) {
    /**
     * Persist all staged design files from customization payload onto an order item (checkout).
     *
     * @param array<string,mixed> $custom
     * @return list<array<string,mixed>> API file list
     */
    function printflow_order_item_design_files_sync_from_customization(
        int $orderItemId,
        int $orderId,
        int $customerId,
        int $customizationId,
        array $custom
    ): array {
        if ($orderItemId <= 0) {
            return [];
        }

        $entries = [];
        $sort = 0;

        if (!empty($custom['design_upload_files']) && is_array($custom['design_upload_files'])) {
            foreach ($custom['design_upload_files'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $path = trim((string)($entry['path'] ?? ($entry['storage_path'] ?? '')));
                $tmpPath = trim((string)($entry['tmp_path'] ?? ''));
                if ($path === '' && $tmpPath !== '' && is_file($tmpPath)) {
                    $binary = @file_get_contents($tmpPath);
                    if ($binary !== false && $binary !== '') {
                        $path = printflow_write_order_upload_file(
                            $binary,
                            (string)($entry['name'] ?? basename($tmpPath)),
                            $orderItemId,
                            'design'
                        ) ?? '';
                    }
                }
                if ($path === '') {
                    continue;
                }
                $entries[] = [
                    'path' => $path,
                    'name' => trim((string)($entry['name'] ?? ($entry['display_name'] ?? 'design'))),
                    'mime' => trim((string)($entry['mime'] ?? ($entry['mime_type'] ?? ''))),
                    'sort' => array_key_exists('upload_sort', $entry) ? (int)$entry['upload_sort'] : $sort++,
                    'idempotency_key' => trim((string)($entry['idempotency_key'] ?? '')),
                ];
            }
        }

        $primaryPath = trim((string)($custom['design_upload_path'] ?? ($custom['design_file'] ?? '')));
        if ($primaryPath !== '') {
            $already = false;
            foreach ($entries as $entry) {
                if ($entry['path'] === $primaryPath) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                array_unshift($entries, [
                    'path' => $primaryPath,
                    'name' => trim((string)($custom['design_upload_name'] ?? ($custom['design_upload'] ?? basename($primaryPath)))),
                    'mime' => trim((string)($custom['design_upload_mime'] ?? '')),
                    'sort' => 0,
                    'idempotency_key' => trim((string)($custom['design_upload_idempotency_key'] ?? '')),
                ]);
            }
        }

        if (!empty($custom['_uploaded_files']) && is_array($custom['_uploaded_files'])) {
            foreach ($custom['_uploaded_files'] as $upload) {
                if (!is_array($upload)) {
                    continue;
                }
                $path = trim((string)($upload['path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $entries[] = [
                    'path' => $path,
                    'name' => trim((string)($upload['name'] ?? ($upload['label'] ?? basename($path)))),
                    'mime' => trim((string)($upload['mime'] ?? '')),
                    'sort' => $sort++,
                    'idempotency_key' => trim((string)($upload['idempotency_key'] ?? '')),
                ];
            }
        }

        foreach ($entries as $index => $entry) {
            $path = function_exists('printflow_normalize_order_upload_web_path')
                ? printflow_normalize_order_upload_web_path((string)$entry['path'])
                : (string)$entry['path'];
            if ($path === '') {
                continue;
            }
            $key = (string)($entry['idempotency_key'] ?? '');
            if ($key === '') {
                $key = 'checkout-' . $orderItemId . '-' . hash('sha256', $path . '|' . (string)$index);
            }
            printflow_order_item_design_file_register([
                'order_item_id' => $orderItemId,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'customization_id' => $customizationId,
                'display_name' => (string)$entry['name'],
                'mime_type' => (string)$entry['mime'],
                'storage_path' => $path,
                'upload_sort' => (int)$entry['sort'],
                'idempotency_key' => $key,
                'file_role' => 'design',
            ]);
        }

        return printflow_order_item_design_files_list_for_item($orderItemId, false);
    }
}

if (!function_exists('printflow_customization_apply_synced_design_files')) {
    /**
     * Merge authoritative design file rows into customization JSON (legacy single-file keys + design_upload_files).
     *
     * @param list<array<string,mixed>> $apiFiles
     * @return array<string,mixed>
     */
    function printflow_customization_apply_synced_design_files(array $customization, array $apiFiles): array
    {
        if ($apiFiles === []) {
            return $customization;
        }

        $entries = [];
        foreach ($apiFiles as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = trim((string)($row['storage_path'] ?? ($row['path'] ?? '')));
            if ($path === '') {
                continue;
            }
            $entries[] = [
                'file_id' => (int)($row['file_id'] ?? 0),
                'path' => $path,
                'name' => trim((string)($row['display_name'] ?? ($row['name'] ?? 'design'))),
                'mime' => trim((string)($row['mime_type'] ?? ($row['mime'] ?? ''))),
                'size' => (int)($row['file_size'] ?? ($row['size'] ?? 0)),
                'upload_sort' => (int)($row['upload_sort'] ?? 0),
                'idempotency_key' => trim((string)($row['idempotency_key'] ?? '')),
            ];
        }

        if ($entries === []) {
            return $customization;
        }

        usort($entries, static function (array $a, array $b): int {
            $sortDelta = ($a['upload_sort'] ?? 0) <=> ($b['upload_sort'] ?? 0);
            if ($sortDelta !== 0) {
                return $sortDelta;
            }
            return ($a['file_id'] ?? 0) <=> ($b['file_id'] ?? 0);
        });

        $customization['design_upload_files'] = $entries;
        $first = $entries[0];
        $customization['design_upload_path'] = (string)$first['path'];
        $customization['design_file'] = (string)$first['path'];
        $customization['design_upload_name'] = (string)$first['name'];
        $customization['design_upload'] = (string)$first['name'];
        if (($first['mime'] ?? '') !== '') {
            $customization['design_upload_mime'] = (string)$first['mime'];
        }

        return $customization;
    }
}

if (!function_exists('printflow_order_item_design_file_assert_access')) {
    function printflow_order_item_design_file_assert_access(int $fileId, int $userId, bool $isStaff): bool
    {
        if ($fileId <= 0) {
            return false;
        }
        $rows = db_query(
            'SELECT f.file_id, f.order_id, f.customer_id, o.branch_id
             FROM order_item_design_files f
             INNER JOIN orders o ON o.order_id = f.order_id
             WHERE f.file_id = ?
             LIMIT 1',
            'i',
            [$fileId]
        ) ?: [];
        if ($rows === []) {
            return false;
        }
        $row = $rows[0];
        if (!$isStaff) {
            return (int)($row['customer_id'] ?? 0) === $userId;
        }
        if (function_exists('printflow_assert_order_branch_access')) {
            printflow_assert_order_branch_access((int)($row['order_id'] ?? 0));
        }
        return true;
    }
}
