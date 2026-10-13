<?php
/**
 * Field-authorized order revision workflow.
 *
 * This layer is deliberately independent from pricing, payment, and production.
 * It records staff authorization and applies only the approved customer fields.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/order_ui_helper.php';
require_once __DIR__ . '/service_field_config_helper.php';
require_once __DIR__ . '/product_field_config_helper.php';
require_once __DIR__ . '/order_item_design_files.php';
require_once __DIR__ . '/service_order_helper.php';
require_once __DIR__ . '/add_details_labels.php';

/**
 * Normalize multi-file design uploads from the current request.
 *
 * @return list<array<string,mixed>>
 */
function printflow_revision_collect_design_uploads(array $files): array
{
    if (function_exists('printflow_normalize_php_file_uploads')) {
        $uploads = printflow_normalize_php_file_uploads('design_file');
        if ($uploads !== []) {
            return $uploads;
        }
        $uploads = printflow_normalize_php_file_uploads('design_file[]');
        if ($uploads !== []) {
            return $uploads;
        }
    }

    $candidates = ['design_file', 'design_file[]'];
    foreach ($candidates as $field) {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            continue;
        }
        $upload = $files[$field];
        if (!is_array($upload['name'] ?? null)) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            return [$upload];
        }
        $out = [];
        $count = count($upload['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => $upload['name'][$i] ?? '',
                'type' => $upload['type'][$i] ?? '',
                'tmp_name' => $upload['tmp_name'][$i] ?? '',
                'error' => $upload['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $upload['size'][$i] ?? 0,
            ];
        }
        if ($out !== []) {
            return $out;
        }
    }

    return [];
}

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

    $historyReady = (bool)db_execute(
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

    // CREATE TABLE IF NOT EXISTS does not upgrade older production tables.
    // Add only missing columns; no existing revision row or media is rewritten.
    $ensureColumns = static function (string $table, array $columns): bool {
        foreach ($columns as $column => $definition) {
            if (db_table_has_column($table, $column)) continue;
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
            if ($safeTable === '' || $safeColumn === ''
                || !db_execute("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeColumn}` {$definition}")) {
                error_log("Revision schema upgrade failed for {$safeTable}.{$safeColumn}.");
                return false;
            }
            if (!db_table_has_column($safeTable, $safeColumn, true)) {
                error_log("Revision schema verification failed for {$safeTable}.{$safeColumn}.");
                return false;
            }
        }
        return true;
    };

    $requestColumnsReady = $ensureColumns('order_revision_requests', [
        'staff_id' => 'INT NULL',
        'customer_id' => 'INT NULL',
        'reason_code' => "VARCHAR(64) NOT NULL DEFAULT 'others'",
        'revision_reason' => "VARCHAR(255) NOT NULL DEFAULT 'Revision requested'",
        'staff_instruction' => 'TEXT NULL',
        'permitted_fields' => 'LONGTEXT NULL',
        'previous_values' => 'LONGTEXT NULL',
        'revised_values' => 'LONGTEXT NULL',
        'request_status' => "VARCHAR(40) NOT NULL DEFAULT 'Requested'",
        'active_flag' => 'TINYINT NULL DEFAULT 1',
        'requested_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'resubmitted_at' => 'DATETIME NULL',
        'order_item_id' => 'INT NULL DEFAULT 0',
        'customization_id' => 'INT NULL DEFAULT 0',
        'selected_design_file_ids' => 'LONGTEXT NULL',
        'request_sequence' => 'INT NULL DEFAULT 1',
    ]);
    $historyColumnsReady = $ensureColumns('order_item_revisions', [
        'design_image' => 'LONGBLOB NULL',
        'design_image_name' => 'VARCHAR(255) NULL',
        'design_image_mime' => 'VARCHAR(100) NULL',
        'design_file' => 'VARCHAR(512) NULL',
    ]);

    $repairAuditReady = (bool)db_execute(
        "CREATE TABLE IF NOT EXISTS order_revision_permission_repairs (
            repair_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            revision_request_id BIGINT UNSIGNED NOT NULL,
            order_id INT NOT NULL,
            previous_permitted_fields LONGTEXT NULL,
            repaired_permitted_fields LONGTEXT NOT NULL,
            repair_rule VARCHAR(100) NOT NULL,
            repaired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (repair_id),
            UNIQUE KEY uq_revision_permission_repair (revision_request_id),
            KEY idx_permission_repair_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = $ready && $historyReady && $requestColumnsReady && $historyColumnsReady && $repairAuditReady;
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

function printflow_revision_has_design_permission(array $permissions): bool
{
    return in_array('uploaded_design', $permissions, true);
}

function printflow_revision_has_detail_permission(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if ((string)$permission !== 'uploaded_design') {
            return true;
        }
    }
    return false;
}

function printflow_revision_design_files_zip_url(int $revisionRequestId, int $orderItemId = 0): string
{
    if ($revisionRequestId <= 0) {
        return '';
    }
    $base = function_exists('pf_app_base_path') ? rtrim((string)pf_app_base_path(), '/') : '';
    $qs = 'revision_request_id=' . $revisionRequestId;
    if ($orderItemId > 0) {
        $qs .= '&order_item_id=' . $orderItemId;
    }
    return $base . '/public/download_revision_design_files_zip.php?' . $qs;
}

/**
 * @return list<int>
 */
function printflow_revision_decode_selected_design_file_ids($raw): array
{
    if (is_array($raw)) {
        $ids = $raw;
    } else {
        $decoded = printflow_revision_decode_json((string)$raw);
        $ids = is_array($decoded) ? $decoded : [];
    }
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function printflow_revision_hydrate_request_row(array $row): array
{
    $row['selected_design_file_ids_array'] = printflow_revision_decode_selected_design_file_ids($row['selected_design_file_ids'] ?? '[]');
    $row['request_sequence'] = max(1, (int)($row['request_sequence'] ?? 1));
    return $row;
}

/**
 * @param list<int> $fileIds
 * @return list<array<string,mixed>>
 */
function printflow_revision_resolve_selected_design_files(int $orderId, int $orderItemId, array $fileIds): array
{
    if ($orderId <= 0 || $orderItemId <= 0 || $fileIds === []) {
        return [];
    }
    $fileIds = printflow_revision_decode_selected_design_file_ids($fileIds);
    if ($fileIds === []) {
        return [];
    }
    printflow_ensure_order_item_design_files_schema();
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $types = str_repeat('i', count($fileIds)) . 'ii';
    $params = array_merge($fileIds, [$orderItemId, $orderId]);
    $rows = db_query(
        "SELECT * FROM order_item_design_files
         WHERE file_id IN ({$placeholders})
           AND order_item_id = ?
           AND order_id = ?
           AND (revision_request_id IS NULL OR revision_request_id = 0)",
        $types,
        $params
    ) ?: [];
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)($row['file_id'] ?? 0)] = $row;
    }
    $ordered = [];
    foreach ($fileIds as $fileId) {
        if (isset($byId[$fileId])) {
            $ordered[] = $byId[$fileId];
        }
    }
    return printflow_order_item_design_files_to_api($ordered);
}

/**
 * @return list<array{key:string,label:string,files:list<array<string,mixed>>,revision_request_id:int,request_sequence:int}>
 */
function printflow_revision_file_sections_for_request(array $revisionRow): array
{
    $revisionRequestId = (int)($revisionRow['revision_request_id'] ?? 0);
    $orderId = (int)($revisionRow['order_id'] ?? 0);
    $orderItemId = (int)($revisionRow['order_item_id'] ?? 0);
    if ($revisionRequestId <= 0 || $orderId <= 0) {
        return [];
    }
    if ($orderItemId <= 0) {
        $items = is_array($revisionRow['previous_values_array']['items'] ?? null)
            ? $revisionRow['previous_values_array']['items']
            : [];
        if (count($items) === 1) {
            $orderItemId = (int)($items[0]['order_item_id'] ?? 0);
        }
    }
    $sections = [];
    if ($orderItemId > 0 && function_exists('printflow_order_item_design_files_list_for_item')) {
        $original = printflow_order_item_design_files_list_for_item($orderItemId, false);
        if ($original !== []) {
            $sections[] = [
                'key' => 'original',
                'label' => 'Original design',
                'files' => $original,
                'revision_request_id' => 0,
                'request_sequence' => 0,
            ];
        }
    }
    $selected = printflow_revision_resolve_selected_design_files(
        $orderId,
        $orderItemId,
        $revisionRow['selected_design_file_ids_array'] ?? []
    );
    if ($selected !== []) {
        $sections[] = [
            'key' => 'selected',
            'label' => 'Requested replacement',
            'files' => $selected,
            'revision_request_id' => $revisionRequestId,
            'request_sequence' => (int)($revisionRow['request_sequence'] ?? 1),
        ];
    }
    $currentResponse = printflow_revision_design_files_list_for_request($revisionRequestId, $orderItemId);
    if ($currentResponse !== []) {
        $seq = max(1, (int)($revisionRow['request_sequence'] ?? 1));
        $sections[] = [
            'key' => 'response_current',
            'label' => 'Response to Request Add Details #' . $seq,
            'files' => $currentResponse,
            'revision_request_id' => $revisionRequestId,
            'request_sequence' => $seq,
        ];
    }
    $historyRows = db_query(
        'SELECT revision_request_id, request_sequence, resubmitted_at
         FROM order_revision_requests
         WHERE order_id = ? AND revision_request_id <> ? AND resubmitted_at IS NOT NULL
         ORDER BY request_sequence ASC, revision_request_id ASC',
        'ii',
        [$orderId, $revisionRequestId]
    ) ?: [];
    foreach ($historyRows as $historyRow) {
        $histId = (int)($historyRow['revision_request_id'] ?? 0);
        $histSeq = max(1, (int)($historyRow['request_sequence'] ?? 1));
        $histFiles = printflow_revision_design_files_list_for_request($histId, $orderItemId);
        if ($histFiles === []) {
            continue;
        }
        $sections[] = [
            'key' => 'response_history_' . $histId,
            'label' => 'Response to Request Add Details #' . $histSeq,
            'files' => $histFiles,
            'revision_request_id' => $histId,
            'request_sequence' => $histSeq,
        ];
    }
    return $sections;
}

/**
 * @param array<string,mixed> $file
 */
function printflow_revision_design_file_display_title(array $file): string
{
    if (function_exists('printflow_order_item_design_file_display_title')) {
        return printflow_order_item_design_file_display_title($file);
    }
    $name = trim((string)($file['display_name'] ?? ''));
    return $name !== '' ? $name : 'Design file';
}

/**
 * @param array<string,mixed> $file
 */
function printflow_revision_design_file_storage_key(array $file): string
{
    $path = strtolower(str_replace('\\', '/', trim((string)($file['storage_path'] ?? ''))));
    if ($path !== '') {
        return 'path:' . $path;
    }
    return 'id:' . (int)($file['file_id'] ?? 0);
}

/**
 * @param array<string,mixed> $file
 */
function printflow_revision_design_file_content_fingerprint(array $file): string
{
    $cached = trim((string)($file['content_fingerprint'] ?? ''));
    if ($cached !== '') {
        return $cached;
    }
    $path = trim((string)($file['storage_path'] ?? ''));
    if ($path === '' || !function_exists('printflow_resolve_order_upload_disk_path')) {
        return '';
    }
    $disk = printflow_resolve_order_upload_disk_path($path);
    if ($disk === null || !is_file($disk) || !is_readable($disk)) {
        return '';
    }
    $hash = hash_file('sha256', $disk);
    return is_string($hash) ? $hash : '';
}

/**
 * @param array<string,mixed> $file
 */
function printflow_revision_design_file_identity_key(array $file): string
{
    $fingerprint = printflow_revision_design_file_content_fingerprint($file);
    if ($fingerprint !== '') {
        return 'hash:' . $fingerprint;
    }
    return printflow_revision_design_file_storage_key($file);
}

/**
 * Deduplicate by file_id, then by physical identity (hash or normalized storage path).
 *
 * @param list<array<string,mixed>> $files
 * @return list<array<string,mixed>>
 */
function printflow_revision_dedupe_design_files_physical(array $files): array
{
    $byId = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $fileId = (int)($file['file_id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }
        $byId[$fileId] = $file;
    }
    $deduped = [];
    $seenPhysical = [];
    foreach (array_values($byId) as $file) {
        $identity = printflow_revision_design_file_identity_key($file);
        if (isset($seenPhysical[$identity])) {
            continue;
        }
        $seenPhysical[$identity] = true;
        $file['display_name'] = printflow_revision_design_file_display_title($file);
        $fingerprint = printflow_revision_design_file_content_fingerprint($file);
        if ($fingerprint !== '') {
            $file['content_fingerprint'] = $fingerprint;
        }
        $deduped[] = $file;
    }
    usort($deduped, static function (array $a, array $b): int {
        $sortDelta = ((int)($a['upload_sort'] ?? 0)) <=> ((int)($b['upload_sort'] ?? 0));
        if ($sortDelta !== 0) {
            return $sortDelta;
        }
        return ((int)($a['file_id'] ?? 0)) <=> ((int)($b['file_id'] ?? 0));
    });
    return $deduped;
}

/**
 * @param list<array<string,mixed>> $files
 * @param array<string,mixed> $meta
 * @return list<array<string,mixed>>
 */
function printflow_revision_tag_design_files(array $files, array $meta): array
{
    $out = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $tagged = array_merge($file, $meta);
        $tagged['display_name'] = printflow_revision_design_file_display_title($tagged);
        $label = trim((string)($meta['source_label'] ?? ''));
        $tagged['source_labels'] = $label !== '' ? [$label] : [];
        $out[] = $tagged;
    }
    return $out;
}

/**
 * Original vs current response comparison groups for staff review (no cross-column duplicates).
 *
 * @return array{
 *   original_files:list<array<string,mixed>>,
 *   current_response_files:list<array<string,mixed>>,
 *   previous_response_files:list<array{revision_request_id:int,request_sequence:int,label:string,files:list<array<string,mixed>>}>,
 *   unified_design_files:list<array<string,mixed>>,
 *   counts:array<string,int>
 * }
 */
function printflow_revision_comparison_design_files_for_request(array $revisionRow): array
{
    $revisionRequestId = (int)($revisionRow['revision_request_id'] ?? 0);
    $orderId = (int)($revisionRow['order_id'] ?? 0);
    $orderItemId = (int)($revisionRow['order_item_id'] ?? 0);
    if ($orderItemId <= 0) {
        $items = is_array($revisionRow['previous_values_array']['items'] ?? null)
            ? $revisionRow['previous_values_array']['items']
            : [];
        if (count($items) === 1) {
            $orderItemId = (int)($items[0]['order_item_id'] ?? 0);
        }
    }
    $requestSequence = max(1, (int)($revisionRow['request_sequence'] ?? 1));
    $responseLabel = 'Customer\'s new upload';

    $originalRaw = ($orderItemId > 0 && function_exists('printflow_order_item_design_files_list_for_item'))
        ? printflow_order_item_design_files_list_for_item($orderItemId, false)
        : [];
    $originalFiles = printflow_revision_dedupe_design_files_physical(
        printflow_revision_tag_design_files($originalRaw, [
            'source_type' => 'original',
            'source_label' => 'Original design',
            'request_sequence' => 0,
            'revision_request_id' => 0,
        ])
    );

    $currentResponseRaw = ($revisionRequestId > 0)
        ? printflow_revision_design_files_list_for_request($revisionRequestId, $orderItemId)
        : [];
    $currentResponseTagged = printflow_revision_tag_design_files(
        printflow_revision_dedupe_design_files_physical($currentResponseRaw),
        [
            'source_type' => 'response_current',
            'source_label' => $responseLabel,
            'request_sequence' => $requestSequence,
            'revision_request_id' => $revisionRequestId,
        ]
    );

    $originalIdentity = [];
    foreach ($originalFiles as $index => $file) {
        $originalIdentity[printflow_revision_design_file_identity_key($file)] = $index;
    }

    $currentResponseFiles = [];
    foreach ($currentResponseTagged as $responseFile) {
        $identity = printflow_revision_design_file_identity_key($responseFile);
        if (isset($originalIdentity[$identity])) {
            $origIndex = $originalIdentity[$identity];
            $originalFiles[$origIndex]['same_as_original'] = true;
            if (!in_array('Same as original', $originalFiles[$origIndex]['source_labels'] ?? [], true)) {
                $originalFiles[$origIndex]['source_labels'] = array_values(array_unique(array_merge(
                    is_array($originalFiles[$origIndex]['source_labels'] ?? null) ? $originalFiles[$origIndex]['source_labels'] : [],
                    ['Same as original']
                )));
            }
            continue;
        }
        $responseFileId = (int)($responseFile['file_id'] ?? 0);
        if ($responseFileId > 0) {
            $skip = false;
            foreach ($originalFiles as $orig) {
                if ((int)($orig['file_id'] ?? 0) === $responseFileId) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
        }
        $currentResponseFiles[] = $responseFile;
    }

    $previousResponseFiles = [];
    if ($orderId > 0 && $revisionRequestId > 0) {
        $historyRows = db_query(
            'SELECT revision_request_id, request_sequence, resubmitted_at
             FROM order_revision_requests
             WHERE order_id = ? AND revision_request_id <> ? AND resubmitted_at IS NOT NULL
             ORDER BY request_sequence ASC, revision_request_id ASC',
            'ii',
            [$orderId, $revisionRequestId]
        ) ?: [];
        foreach ($historyRows as $historyRow) {
            $histId = (int)($historyRow['revision_request_id'] ?? 0);
            $histSeq = max(1, (int)($historyRow['request_sequence'] ?? 1));
            if ($histId <= 0) {
                continue;
            }
            $histFiles = printflow_revision_design_files_list_for_request($histId, $orderItemId);
            $histFiles = printflow_revision_dedupe_design_files_physical($histFiles);
            if ($histFiles === []) {
                continue;
            }
            $previousResponseFiles[] = [
                'revision_request_id' => $histId,
                'request_sequence' => $histSeq,
                'label' => 'Previous response — Request Add Details #' . $histSeq,
                'files' => printflow_revision_tag_design_files($histFiles, [
                    'source_type' => 'response_history',
                    'source_label' => 'Previous response #' . $histSeq,
                    'request_sequence' => $histSeq,
                    'revision_request_id' => $histId,
                ]),
            ];
        }
    }

    $unifiedById = [];
    foreach (array_merge($originalFiles, $currentResponseFiles) as $file) {
        $fileId = (int)($file['file_id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }
        $unifiedById[$fileId] = $file;
    }
    foreach ($previousResponseFiles as $group) {
        foreach ($group['files'] as $file) {
            $fileId = (int)($file['file_id'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }
            if (!isset($unifiedById[$fileId])) {
                $unifiedById[$fileId] = $file;
            }
        }
    }
    $unifiedDesignFiles = array_values($unifiedById);
    usort($unifiedDesignFiles, static function (array $a, array $b): int {
        $sortDelta = ((int)($a['upload_sort'] ?? 0)) <=> ((int)($b['upload_sort'] ?? 0));
        if ($sortDelta !== 0) {
            return $sortDelta;
        }
        return ((int)($a['file_id'] ?? 0)) <=> ((int)($b['file_id'] ?? 0));
    });

    $historyFileCount = 0;
    foreach ($previousResponseFiles as $group) {
        $historyFileCount += count($group['files']);
    }

    return [
        'original_files' => $originalFiles,
        'current_response_files' => $currentResponseFiles,
        'previous_response_files' => $previousResponseFiles,
        'unified_design_files' => $unifiedDesignFiles,
        'counts' => [
            'unique_total' => count($unifiedDesignFiles),
            'original' => count($originalFiles),
            'response_current' => count($currentResponseFiles),
            'response_history' => $historyFileCount,
        ],
    ];
}

/**
 * Merge all design file sources for one request into one deduplicated list (key: file_id).
 *
 * @return array{files:list<array<string,mixed>>,counts:array<string,int>}
 */
function printflow_revision_unified_design_files_for_request(array $revisionRow): array
{
    $comparison = printflow_revision_comparison_design_files_for_request($revisionRow);
    return [
        'files' => $comparison['unified_design_files'],
        'counts' => $comparison['counts'],
    ];
}

function printflow_staff_add_details_notification_url(int $orderId, int $revisionRequestId = 0, int $orderItemId = 0, int $customizationId = 0, int $jobOrderId = 0): string
{
    $base = function_exists('printflow_notification_base_path')
        ? printflow_notification_base_path()
        : (function_exists('pf_app_base_path') ? rtrim((string)pf_app_base_path(), '/') : '');
    if ($orderId <= 0) {
        return $base . '/staff/customizations.php';
    }
    if ($revisionRequestId > 0 && printflow_revision_ensure_schema()) {
        $rows = db_query(
            'SELECT revision_request_id, order_item_id, customization_id FROM order_revision_requests WHERE revision_request_id = ? AND order_id = ? LIMIT 1',
            'ii',
            [$revisionRequestId, $orderId]
        ) ?: [];
        if (!empty($rows[0])) {
            if ($orderItemId <= 0) {
                $orderItemId = (int)($rows[0]['order_item_id'] ?? 0);
            }
            if ($customizationId <= 0) {
                $customizationId = (int)($rows[0]['customization_id'] ?? 0);
            }
        }
    }
    if ($customizationId <= 0) {
        $custRows = db_query(
            'SELECT customization_id FROM customizations WHERE order_id = ? ORDER BY customization_id DESC LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];
        $customizationId = (int)($custRows[0]['customization_id'] ?? 0);
    }
    if ($jobOrderId <= 0) {
        $jobRows = db_query('SELECT id FROM job_orders WHERE order_id = ? ORDER BY id DESC LIMIT 1', 'i', [$orderId]) ?: [];
        $jobOrderId = (int)($jobRows[0]['id'] ?? 0);
    }
    $params = [
        'order_id' => $orderId,
        'job_type' => 'ORDER',
        'status' => 'PENDING',
        'focus_add_details' => '1',
    ];
    if ($revisionRequestId > 0) {
        $params['revision_request_id'] = $revisionRequestId;
    }
    if ($orderItemId > 0) {
        $params['order_item_id'] = $orderItemId;
    }
    if ($customizationId > 0) {
        $params['customization_id'] = $customizationId;
    }
    if ($jobOrderId > 0) {
        $params['job_order_id'] = $jobOrderId;
    }
    return $base . '/staff/customizations.php?' . http_build_query($params);
}

function printflow_revision_action_label(array $permissions): string
{
    if (empty($permissions)) return '';
    $hasDesign = printflow_revision_has_design_permission($permissions);
    $hasDetails = printflow_revision_has_detail_permission($permissions);
    if ($hasDesign && !$hasDetails) return 'Respond to Request Add Details';
    if (!$hasDesign && $hasDetails) return 'Respond to Request Add Details';
    return 'Respond to Request Add Details';
}

function printflow_revision_price_impacting(array $permissions): bool
{
    foreach ($permissions as $permission) {
        $permission = (string)$permission;
        if (in_array($permission, ['quantity', 'type_specifications', 'layout'], true)
            || str_starts_with($permission, 'spec:')) {
            return true;
        }
    }
    return false;
}

function printflow_revision_permission_labels(array $permissions, array $snapshot = []): array
{
    $base = [
        'uploaded_design' => 'Uploaded Design',
        'needed_date' => 'Needed Date',
        'type_specifications' => 'Type / Order Specifications',
        'layout' => 'Layout',
        'quantity' => 'Quantity',
        'order_notes' => 'Order Notes',
    ];
    $labels = [];
    foreach ($permissions as $permission) {
        $permission = (string)$permission;
        if (isset($base[$permission])) {
            $labels[] = $base[$permission];
            continue;
        }
        $parsed = printflow_revision_parse_spec_token($permission);
        if ($parsed !== null) {
            $labels[] = ucwords(str_replace(['_', '-'], ' ', (string)$parsed['key']));
        }
    }
    return array_values(array_unique($labels));
}

function printflow_revision_find_field_config(array $configs, string $key): array
{
    if (is_array($configs[$key] ?? null)) {
        return $configs[$key];
    }
    $normalized = printflow_revision_normalize_key($key);
    foreach ($configs as $configKey => $config) {
        if (!is_array($config)) continue;
        $label = (string)($config['label'] ?? '');
        if (printflow_revision_normalize_key((string)$configKey) === $normalized
            || ($label !== '' && printflow_revision_normalize_key($label) === $normalized)) {
            return $config;
        }
    }
    return [];
}

function printflow_revision_validate_spec_value(array $item, array $custom, string $key, $value, int $serviceIdFallback = 0)
{
    $config = [];
    $serviceId = (int)($custom['service_id'] ?? $serviceIdFallback);
    if ($serviceId <= 0) $serviceId = $serviceIdFallback;
    if ($serviceId > 0) {
        $configs = get_service_field_config($serviceId);
        $config = printflow_revision_find_field_config($configs, $key);
    }
    if (empty($config) && (int)($item['product_id'] ?? 0) > 0) {
        $configs = get_product_field_config((int)$item['product_id']);
        $config = printflow_revision_find_field_config($configs, $key);
    }
    if (is_array($value)) {
        $value = array_map(static fn($entry) => sanitize((string)$entry), $value);
    } else {
        $value = sanitize((string)$value);
    }
    $scalar = is_array($value) ? implode(', ', $value) : (string)$value;
    if (!empty($config['required']) && trim($scalar) === '') {
        throw new RuntimeException('A required revised specification was left blank.');
    }
    $type = strtolower((string)($config['type'] ?? 'text'));
    if ($type === 'date' || printflow_revision_key_group($key) === 'needed_date') {
        $date = DateTime::createFromFormat('Y-m-d', $scalar);
        if ($scalar !== '' && (!$date || $date->format('Y-m-d') !== $scalar)) {
            throw new RuntimeException('Enter a valid needed date.');
        }
    }
    if (in_array($type, ['number', 'quantity'], true) && ($scalar === '' || !is_numeric($scalar) || (float)$scalar < 1)) {
        throw new RuntimeException('Enter a valid positive number.');
    }
    $options = is_array($config['options'] ?? null) ? $config['options'] : [];
    if (in_array($type, ['select', 'radio', 'dimension'], true) && !empty($options) && empty($config['allow_others'])) {
        $allowed = [];
        foreach ($options as $option) {
            $allowed[] = is_array($option) ? (string)($option['value'] ?? $option['label'] ?? '') : (string)$option;
        }
        if (!in_array($scalar, $allowed, true)) {
            throw new RuntimeException('An invalid specification option was submitted.');
        }
    }
    if (strlen($scalar) > 10000) {
        throw new RuntimeException('A revised specification is too long.');
    }
    return $value;
}

/**
 * Treat two persisted keys as aliases only when the catalog explicitly maps
 * a field key to its display label, or when they are the conservative
 * canonical_key / "Canonical Key" storage pair used by legacy checkout.
 */
function printflow_revision_keys_are_verified_aliases(
    array $item,
    array $custom,
    string $leftKey,
    string $rightKey,
    int $serviceIdFallback = 0
): bool {
    if (hash_equals($leftKey, $rightKey)) return true;
    $normalized = printflow_revision_normalize_key($leftKey);
    if ($normalized === '' || $normalized !== printflow_revision_normalize_key($rightKey)) return false;

    $configSets = [];
    $serviceId = (int)($custom['service_id'] ?? 0);
    if ($serviceId <= 0) $serviceId = $serviceIdFallback;
    if ($serviceId > 0) $configSets[] = get_service_field_config($serviceId);
    $productId = (int)($item['product_id'] ?? 0);
    if ($productId > 0) $configSets[] = get_product_field_config($productId);
    foreach ($configSets as $configs) {
        foreach ($configs as $configKey => $config) {
            if (!is_array($config)) continue;
            $label = trim((string)($config['label'] ?? ''));
            if ($label === '') continue;
            $leftIsKey = strcasecmp(trim($leftKey), trim((string)$configKey)) === 0;
            $rightIsKey = strcasecmp(trim($rightKey), trim((string)$configKey)) === 0;
            $leftIsLabel = strcasecmp(trim($leftKey), $label) === 0;
            $rightIsLabel = strcasecmp(trim($rightKey), $label) === 0;
            if (($leftIsKey && $rightIsLabel) || ($rightIsKey && $leftIsLabel)) return true;
        }
    }

    $isCanonical = static fn(string $key): bool => strtolower(trim($key)) === $normalized;
    $display = str_replace('_', ' ', $normalized);
    $isDisplay = static fn(string $key): bool => strtolower(trim((string)preg_replace('/\s+/', ' ', $key))) === $display;
    return ($isCanonical($leftKey) && $isDisplay($rightKey))
        || ($isCanonical($rightKey) && $isDisplay($leftKey));
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
        'design_upload_data', 'design_upload_mime', 'design_upload_files', 'uploaded_files',
        'reference_file', 'reference_image', 'csrf_token',
    ];
    if (function_exists('pf_order_ui_should_skip_spec_key') && pf_order_ui_should_skip_spec_key($key)) {
        return true;
    }
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
                'serve_url' => (function_exists('pf_app_base_path') ? pf_app_base_path() : '') . '/public/serve_design.php?type=order_item&id=' . (int)($item['order_item_id'] ?? 0),
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
    return printflow_revision_hydrate_request_row($row);
}

function printflow_revision_get_latest(int $orderId): ?array
{
    if ($orderId <= 0 || !printflow_revision_ensure_schema()) return null;
    $rows = db_query(
        'SELECT * FROM order_revision_requests WHERE order_id = ? ORDER BY revision_request_id DESC LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    if (empty($rows)) return null;
    $row = $rows[0];
    $row['permitted_fields_array'] = printflow_revision_decode_json($row['permitted_fields'] ?? '[]');
    $row['previous_values_array'] = printflow_revision_decode_json($row['previous_values'] ?? '{}');
    $row['revised_values_array'] = printflow_revision_decode_json($row['revised_values'] ?? '{}');
    $row['permitted_field_labels'] = printflow_revision_permission_labels($row['permitted_fields_array'], $row['previous_values_array']);
    return printflow_revision_hydrate_request_row($row);
}

function printflow_revision_mark_customer_updating(int $requestId, int $orderId, int $customerId): void
{
    if ($requestId <= 0 || $orderId <= 0 || $customerId <= 0) return;
    db_execute(
        "UPDATE order_revision_requests
         SET request_status = 'Customer Updating Details'
         WHERE revision_request_id = ? AND order_id = ? AND customer_id = ?
           AND active_flag = 1 AND request_status = 'Requested'",
        'iii',
        [$requestId, $orderId, $customerId]
    );
}

function printflow_revision_mark_staff_reviewing(int $requestId, int $orderId): void
{
    if ($requestId <= 0 || $orderId <= 0) return;
    db_execute(
        "UPDATE order_revision_requests
         SET request_status = 'Staff Reviewing'
         WHERE revision_request_id = ? AND order_id = ?
           AND active_flag IS NULL AND request_status = 'Resubmitted for Review'",
        'ii',
        [$requestId, $orderId]
    );
}

function printflow_revision_flatten_snapshot(array $value, string $prefix = ''): array
{
    $flat = [];
    foreach ($value as $key => $entry) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($entry)) {
            $flat += printflow_revision_flatten_snapshot($entry, $path);
        } else {
            $flat[$path] = $entry;
        }
    }
    return $flat;
}

function printflow_revision_changes(array $previous, array $revised): array
{
    $before = printflow_revision_flatten_snapshot($previous);
    $after = printflow_revision_flatten_snapshot($revised);
    $changes = [];
    $seen = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
        $label = '';
        if ($path === 'order.notes') {
            $label = 'Order Notes';
        } elseif (preg_match('/^items\.\d+\.quantity$/', $path)) {
            $label = 'Quantity';
        } elseif (preg_match('/^items\.\d+\.(?:customization_data|specifications)\.(.+)$/', $path, $matches)) {
            $key = rawurldecode((string)$matches[1]);
            if (preg_match('/(?:^|_)(?:path|file|filename|mime|blob|url|id|status|size|sort)(?:_|$)/i', $key)) {
                continue;
            }
            if (preg_match('/design_upload_files|uploaded_files|design_upload|upload_design/i', $key)) {
                continue;
            }
            if (preg_match('/\.(\d+)\./', $path)) {
                continue;
            }
            if (printflow_revision_key_group($key) === 'order_notes') {
                continue;
            }
            if (printflow_revision_is_protected_spec($key)) {
                continue;
            }
            $label = ucwords(trim(str_replace(['_', '-'], ' ', $key)));
        } else {
            // Product IDs, branch IDs, upload internals, generated filenames,
            // MIME values and status bookkeeping are not customer-facing edits.
            continue;
        }
        $old = $before[$path] ?? null;
        $new = $after[$path] ?? null;
        if ((string)$old === (string)$new) continue;
        if (($old === null || $old === '') && ($new === null || $new === '')) continue;
        preg_match('/^items\.(\d+)\./', $path, $itemMatch);
        $dedupeKey = (string)($itemMatch[1] ?? 'order') . '|' . printflow_revision_normalize_key($label);
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;
        $changes[] = [
            'path' => $path,
            'label' => $label,
            'previous' => $old,
            'revised' => $new,
        ];
    }
    return $changes;
}

/**
 * Staff-facing revision state shared by every order-details endpoint.
 * Raw filesystem paths and snapshot internals are deliberately not exposed.
 */
function printflow_revision_review_payload(int $orderId, bool $markReviewing = false): ?array
{
    if ($orderId <= 0) return null;
    $revision = printflow_revision_get_latest($orderId);
    if ($revision === null) return null;

    if ($markReviewing && (string)($revision['request_status'] ?? '') === 'Resubmitted for Review') {
        printflow_revision_mark_staff_reviewing((int)$revision['revision_request_id'], $orderId);
        $revision['request_status'] = 'Staff Reviewing';
    }

    $previous = is_array($revision['previous_values_array'] ?? null)
        ? $revision['previous_values_array']
        : [];
    $revised = is_array($revision['revised_values_array'] ?? null)
        ? $revision['revised_values_array']
        : [];
    $base = function_exists('pf_app_base_path') ? pf_app_base_path() : '';
    $previousDesign = null;
    $replacementDesign = null;
    $designWasRequested = in_array('uploaded_design', $revision['permitted_fields_array'] ?? [], true);
    $orderItemRows = $designWasRequested
        ? (db_query('SELECT order_item_id FROM order_items WHERE order_id = ?', 'i', [$orderId]) ?: [])
        : [];
    $orderItemIds = array_fill_keys(array_map(
        static fn(array $row): int => (int)($row['order_item_id'] ?? 0),
        $orderItemRows
    ), true);

    $designItemId = (int)($revised['_revision']['design_order_item_id'] ?? 0);
    foreach ($designWasRequested ? ($previous['items'] ?? []) : [] as $item) {
        if ($designItemId > 0 && (int)($item['order_item_id'] ?? 0) !== $designItemId) continue;
        $design = is_array($item['design'] ?? null) ? $item['design'] : [];
        $historyId = (int)($design['history_revision_id'] ?? 0);
        if ($historyId > 0) {
            $previousMime = strtolower((string)($design['mime'] ?? ''));
            $previousDesign = [
                'url' => $base . '/public/serve_design.php?type=revision_history&id=' . $historyId,
                'media_type' => $previousMime === 'application/pdf' ? 'pdf' : (str_starts_with($previousMime, 'image/') ? 'image' : 'file'),
            ];
            break;
        }
    }
    foreach ($designWasRequested ? ($revised['items'] ?? []) : [] as $item) {
        $itemId = (int)($item['order_item_id'] ?? 0);
        if ($designItemId > 0 && $itemId !== $designItemId) continue;
        if ($itemId <= 0 || !isset($orderItemIds[$itemId])) continue;
        $design = is_array($item['design'] ?? null) ? $item['design'] : [];
        $replacementMime = strtolower((string)($design['mime'] ?? ''));
        $replacementDesign = [
            'url' => $base . '/public/serve_design.php?type=revision_submission&id=' . (int)$revision['revision_request_id'] . '&item_id=' . $itemId,
            'media_type' => $replacementMime === 'application/pdf' ? 'pdf' : (str_starts_with($replacementMime, 'image/') ? 'image' : 'file'),
        ];
        break;
    }

    $revision = printflow_revision_hydrate_request_row($revision);
    $designItemId = $designItemId > 0 ? $designItemId : (int)($revision['order_item_id'] ?? 0);
    $replacementFiles = printflow_revision_design_files_list_for_request(
        (int)$revision['revision_request_id'],
        $designItemId > 0 ? $designItemId : 0
    );
    $selectedSourceFiles = printflow_revision_resolve_selected_design_files(
        $orderId,
        $designItemId,
        $revision['selected_design_file_ids_array'] ?? []
    );
    $comparisonDesign = printflow_revision_comparison_design_files_for_request($revision);

    return [
        'id' => (int)$revision['revision_request_id'],
        'reason' => (string)$revision['revision_reason'],
        'instruction' => (string)$revision['staff_instruction'],
        'status' => (string)$revision['request_status'],
        'requested_at' => (string)$revision['requested_at'],
        'resubmitted_at' => (string)($revision['resubmitted_at'] ?? ''),
        'request_sequence' => (int)($revision['request_sequence'] ?? 1),
        'order_item_id' => $designItemId,
        'customization_id' => (int)($revision['customization_id'] ?? 0),
        'permitted_fields' => $revision['permitted_fields_array'] ?? [],
        'permitted_field_labels' => $revision['permitted_field_labels'] ?? [],
        'changes' => !empty($revised) ? printflow_revision_changes($previous, $revised) : [],
        'previous_design' => $previousDesign,
        'replacement_design' => $replacementDesign,
        'replacement_design_files' => $replacementFiles,
        'selected_source_files' => $selectedSourceFiles,
        'file_sections' => [],
        'original_files' => $comparisonDesign['original_files'],
        'current_response_files' => $comparisonDesign['current_response_files'],
        'previous_response_files' => $comparisonDesign['previous_response_files'],
        'unified_design_files' => $comparisonDesign['unified_design_files'],
        'design_file_counts' => $comparisonDesign['counts'],
        'price_review_required' => !empty($revised['_revision']['price_review_required']),
    ];
}

/**
 * Returns permissions only where a legacy request is unambiguous enough to
 * repair without granting broader edit access than staff intended.
 */
function printflow_revision_safe_legacy_permissions(string $reasonCode, string $instruction): array
{
    $reasonCode = printflow_revision_reason_code($reasonCode);
    if (in_array($reasonCode, ['low_image_quality', 'wrong_design', 'invalid_format'], true)) {
        return ['uploaded_design'];
    }
    if ($reasonCode === 'incorrect_details'
        && preg_match('/\b(?:neede*d\s+date|date\s+neede*d)\b/i', $instruction)) {
        return ['needed_date'];
    }
    return [];
}

function printflow_revision_record_permission_repair(
    int $requestId,
    int $orderId,
    string $before,
    array $after,
    string $rule
): bool {
    if ($requestId <= 0 || $orderId <= 0) return false;
    return (bool)db_execute(
        'INSERT IGNORE INTO order_revision_permission_repairs
         (revision_request_id, order_id, previous_permitted_fields, repaired_permitted_fields, repair_rule)
         VALUES (?, ?, ?, ?, ?)',
        'iisss',
        [$requestId, $orderId, $before, printflow_revision_encode_json($after), $rule]
    );
}

function printflow_revision_get_active_or_legacy(int $orderId, int $customerId): ?array
{
    $active = printflow_revision_get_active($orderId, $customerId);
    if ($active !== null) {
        if (empty($active['permitted_fields_array'])) {
            $safeFields = printflow_revision_safe_legacy_permissions(
                (string)($active['reason_code'] ?? ''),
                (string)($active['staff_instruction'] ?? '')
            );
            if (!empty($safeFields)) {
                $before = (string)($active['permitted_fields'] ?? '');
                $auditSaved = printflow_revision_record_permission_repair(
                    (int)$active['revision_request_id'],
                    $orderId,
                    $before,
                    $safeFields,
                    $safeFields === ['needed_date'] ? 'incorrect_details_explicit_needed_date' : 'image_reason'
                );
                $updated = $auditSaved && db_execute(
                    "UPDATE order_revision_requests SET permitted_fields = ?
                     WHERE revision_request_id = ? AND active_flag = 1
                       AND COALESCE(permitted_fields, '') = ?",
                    'sis',
                    [printflow_revision_encode_json($safeFields), (int)$active['revision_request_id'], $before]
                );
                if ($updated) {
                    $active = printflow_revision_get_active($orderId, $customerId);
                }
            }
        }
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
    if ($legacyReason === '') return null;
    $legacyReasonLower = strtolower($legacyReason);
    $legacyCode = '';
    $legacyReasonPhrasePosition = false;
    foreach ([
        'low image quality' => 'low_image_quality',
        'wrong design uploaded' => 'wrong_design',
        'not printable / invalid format' => 'invalid_format',
        'incorrect details provided' => 'incorrect_details',
    ] as $phrase => $code) {
        if (str_contains($legacyReasonLower, $phrase)) {
            $legacyCode = $code;
            $legacyReasonPhrasePosition = strpos($legacyReasonLower, $phrase);
            break;
        }
    }
    $instruction = trim($legacyReason);
    $separator = $legacyReasonPhrasePosition !== false
        ? strpos($legacyReason, ':', (int)$legacyReasonPhrasePosition)
        : strpos($legacyReason, ':');
    if ($separator !== false) {
        $instruction = trim(substr($legacyReason, $separator + 1));
    }
    $safeFields = printflow_revision_safe_legacy_permissions($legacyCode, $instruction);
    if ($legacyCode === '' || empty($safeFields)) {
        error_log("Legacy revision for Order #{$orderId} has no persisted field authorization and cannot be safely inferred.");
        return null;
    }
    global $conn;
    $repairTransactionStarted = $conn instanceof mysqli && !($conn->in_transaction ?? false);
    try {
        if ($repairTransactionStarted && !$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start the legacy revision repair transaction.');
        }
        $created = printflow_revision_create_request(
            $orderId,
            (int)($order['reviewed_by'] ?? 0),
            $legacyCode,
            $instruction,
            $safeFields,
            printflow_revision_reason_labels()[$legacyCode] ?? 'Revision requested'
        );
        if (!printflow_revision_record_permission_repair(
            (int)($created['revision_request_id'] ?? 0),
            $orderId,
            'missing_active_request',
            $safeFields,
            $safeFields === ['needed_date'] ? 'legacy_create_explicit_needed_date' : 'legacy_create_image_reason'
        )) {
            throw new RuntimeException('Unable to preserve the legacy revision repair audit.');
        }
        if ($repairTransactionStarted && !$conn->commit()) {
            throw new RuntimeException('Unable to commit the legacy revision repair.');
        }
    } catch (Throwable $e) {
        if ($repairTransactionStarted && $conn instanceof mysqli && ($conn->in_transaction ?? false)) {
            $conn->rollback();
        }
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
    $resolvedStatus = str_contains(strtoupper($status), 'APPROVED') ? 'Approved to Set Price' : $status;
    db_execute(
        "UPDATE order_revision_requests
         SET request_status = ?, active_flag = NULL
         WHERE order_id = ?
           AND (active_flag = 1 OR request_status IN ('Resubmitted for Review', 'Staff Reviewing'))",
        'si',
        [$resolvedStatus, $orderId]
    );
}

function printflow_revision_create_request(
    int $orderId,
    int $staffId,
    string $reasonCode,
    string $instruction,
    array $permittedFields,
    ?string $reasonLabelOverride = null,
    ?array $context = null
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
    if (in_array($reasonCode, ['low_image_quality', 'wrong_design', 'invalid_format'], true)) {
        $permittedFields = ['uploaded_design'];
    }
    $permittedFields = printflow_revision_normalize_permissions($permittedFields, $orderId);
    if (empty($permittedFields)) {
        throw new InvalidArgumentException('Select at least one field the customer may edit.');
    }

    $context = is_array($context) ? $context : [];
    $targetOrderItemId = (int)($context['order_item_id'] ?? 0);
    $targetCustomizationId = (int)($context['customization_id'] ?? 0);
    $selectedDesignFileIds = printflow_revision_decode_selected_design_file_ids($context['selected_design_file_ids'] ?? []);
    $requiresDesignSelection = printflow_revision_has_design_permission($permittedFields);
    if ($requiresDesignSelection && $selectedDesignFileIds === []) {
        throw new InvalidArgumentException('Select at least one design image that needs replacement or additional details.');
    }
    if ($targetOrderItemId <= 0) {
        $itemRows = db_query('SELECT order_item_id FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC LIMIT 1', 'i', [$orderId]) ?: [];
        $targetOrderItemId = (int)($itemRows[0]['order_item_id'] ?? 0);
    }
    if ($requiresDesignSelection && $targetOrderItemId > 0) {
        $resolvedSelected = printflow_revision_resolve_selected_design_files($orderId, $targetOrderItemId, $selectedDesignFileIds);
        if ($resolvedSelected === []) {
            throw new InvalidArgumentException('The selected design files could not be verified for this order item.');
        }
        $selectedDesignFileIds = array_map(
            static fn(array $file): int => (int)($file['file_id'] ?? 0),
            $resolvedSelected
        );
    }

    $orderRows = db_query('SELECT customer_id, payment_status FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE', 'i', [$orderId]) ?: [];
    if (empty($orderRows)) {
        throw new RuntimeException('Order not found.');
    }
    if (printflow_revision_get_active($orderId, null, true) !== null) {
        throw new RuntimeException('This order already has an active revision request.');
    }
    if (strtoupper(trim((string)($orderRows[0]['payment_status'] ?? ''))) === 'PAID'
        && printflow_revision_price_impacting($permittedFields)) {
        throw new RuntimeException('This order is already paid. Price-impacting details must be handled by staff; request a replacement order or use the controlled adjustment workflow.');
    }

    $customerId = (int) ($orderRows[0]['customer_id'] ?? 0);
    $snapshot = printflow_revision_snapshot($orderId);
    $archiveOk = db_execute(
        'INSERT INTO order_item_revisions (order_id, order_item_id, staff_id, revision_reason, design_image, design_image_name, design_image_mime, design_file)
         SELECT order_id, order_item_id, ?, ?, design_image, design_image_name, design_image_mime, design_file
         FROM order_items WHERE order_id = ?',
        'isi',
        [$staffId, $reasonLabel . ': ' . $instruction, $orderId]
    );
    if (!$archiveOk) {
        throw new RuntimeException('Unable to preserve the current design in revision history.');
    }
    $historyRows = db_query(
        'SELECT order_item_id, MAX(revision_id) AS revision_id
         FROM order_item_revisions WHERE order_id = ? GROUP BY order_item_id',
        'i',
        [$orderId]
    ) ?: [];
    $historyByItem = [];
    foreach ($historyRows as $historyRow) {
        $historyByItem[(int)$historyRow['order_item_id']] = (int)$historyRow['revision_id'];
    }
    foreach ($snapshot['items'] as &$snapshotItem) {
        $historyId = $historyByItem[(int)($snapshotItem['order_item_id'] ?? 0)] ?? 0;
        if ($historyId > 0) {
            $snapshotItem['design']['history_revision_id'] = $historyId;
            $snapshotItem['design']['history_url'] = (function_exists('pf_app_base_path') ? pf_app_base_path() : '') . '/public/serve_design.php?type=revision_history&id=' . $historyId;
        }
    }
    unset($snapshotItem);

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
    $revisionRequestId = (int) ($conn->insert_id ?? 0);
    $sequenceRows = db_query(
        'SELECT COALESCE(MAX(request_sequence), 0) + 1 AS next_seq FROM order_revision_requests WHERE order_id = ?',
        'i',
        [$orderId]
    ) ?: [];
    $requestSequence = max(1, (int)($sequenceRows[0]['next_seq'] ?? 1));
    if ($revisionRequestId > 0) {
        db_execute(
            'UPDATE order_revision_requests
             SET order_item_id = ?, customization_id = ?, selected_design_file_ids = ?, request_sequence = ?
             WHERE revision_request_id = ? AND order_id = ?',
            'iisiii',
            [
                $targetOrderItemId,
                $targetCustomizationId,
                printflow_revision_encode_json($selectedDesignFileIds),
                $requestSequence,
                $revisionRequestId,
                $orderId,
            ]
        );
    }
    return [
        'revision_request_id' => $revisionRequestId,
        'reason_code' => $reasonCode,
        'reason_label' => $reasonLabel,
        'instruction' => $instruction,
        'permitted_fields' => $permittedFields,
        'customer_id' => $customerId,
        'legacy_reason' => $reasonLabel . ': ' . $instruction,
    ];
}

function printflow_revision_permission_allows(
    array $permissions,
    int $itemId,
    string $key,
    array $item = [],
    array $custom = [],
    int $serviceIdFallback = 0
): bool
{
    if (printflow_revision_is_protected_spec($key)) {
        return false;
    }
    if (in_array(printflow_revision_spec_token($itemId, $key), $permissions, true)) {
        return true;
    }
    foreach ($permissions as $permission) {
        $parsed = printflow_revision_parse_spec_token((string)$permission);
        if ($parsed !== null
            && (int)$parsed['item_id'] === $itemId
            && printflow_revision_keys_are_verified_aliases(
                $item,
                $custom,
                (string)$parsed['key'],
                $key,
                $serviceIdFallback
            )) {
            return true;
        }
    }
    return in_array(printflow_revision_key_group($key), $permissions, true);
}

/**
 * Persist a customer revision upload to the canonical order-upload directory.
 * The returned database path is always /uploads/orders/<generated-name>;
 * absolute server paths never leave this function.
 *
 * @return array{path:string,mime:string,name:string,size:int,disk_path:string}
 */
function printflow_revision_store_uploaded_design(
    array $file,
    int $orderId,
    int $revisionRequestId,
    int $orderItemId
): array {
    $reference = 'REV-UP-' . $orderId . '-' . $revisionRequestId . '-' . $orderItemId;
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        error_log("[{$reference}] PHP upload error code {$uploadError}.");
        throw new RuntimeException('The replacement design upload did not complete. Please select the file again.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        error_log("[{$reference}] The submitted temporary file was not recognized as an HTTP upload.");
        throw new RuntimeException('The replacement design upload could not be verified. Please select the file again.');
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    $validation = validate_file_upload($file, $allowedMimes, 10485760);
    if (empty($validation['valid'])) {
        error_log("[{$reference}] Upload validation failed: " . (string)($validation['message'] ?? 'unknown'));
        throw new RuntimeException((string)($validation['message'] ?? 'The revised design is invalid.'));
    }

    $mime = strtolower(trim((string)($validation['file_info']['type'] ?? '')));
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];
    $allowedOriginalExtensions = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'application/pdf' => ['pdf'],
    ];
    $originalName = basename(str_replace('\\', '/', (string)($file['name'] ?? 'replacement')));
    $originalExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset($extensionMap[$mime]) || !in_array($originalExtension, $allowedOriginalExtensions[$mime], true)) {
        error_log("[{$reference}] File extension did not match the detected MIME type.");
        throw new RuntimeException('The replacement design extension does not match its file type.');
    }
    $safeStem = preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeStem = trim((string)$safeStem, " ._-");
    if ($safeStem === '') $safeStem = 'replacement';
    $safeStem = function_exists('mb_strcut') ? mb_strcut($safeStem, 0, 180, 'UTF-8') : substr($safeStem, 0, 180);
    $originalName = $safeStem . '.' . $originalExtension;

    $uploadDir = function_exists('printflow_order_uploads_dir')
        ? printflow_order_uploads_dir()
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'orders';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        error_log("[{$reference}] Canonical uploads/orders directory could not be created.");
        throw new RuntimeException('The shop upload directory is unavailable. Please try again or contact the shop.');
    }
    if (!is_writable($uploadDir)) {
        error_log("[{$reference}] Canonical uploads/orders directory is not writable.");
        throw new RuntimeException('The shop upload directory is unavailable. Please try again or contact the shop.');
    }

    try {
        $nonce = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $nonce = substr(hash('sha256', uniqid((string)$orderItemId, true)), 0, 24);
    }
    $storedName = sprintf(
        'revision_%d_%d_%d_%s.%s',
        $orderId,
        $revisionRequestId,
        $orderItemId,
        $nonce,
        $extensionMap[$mime]
    );
    $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        error_log("[{$reference}] move_uploaded_file failed for the canonical uploads/orders destination.");
        throw new RuntimeException('The replacement design could not be saved. No order details were changed.');
    }
    clearstatcache(true, $targetPath);
    $storedSize = is_file($targetPath) ? (int)filesize($targetPath) : 0;
    if ($storedSize <= 0 || !is_readable($targetPath)) {
        error_log("[{$reference}] The upload move reported success but the stored file was missing or empty.");
        if (is_file($targetPath) && !@unlink($targetPath)) {
            error_log("[{$reference}] Failed to remove an invalid replacement upload after post-move verification.");
        }
        throw new RuntimeException('The replacement design could not be verified after saving. No order details were changed.');
    }
    @chmod($targetPath, 0640);

    return [
        'path' => '/uploads/orders/' . $storedName,
        'mime' => $mime,
        'name' => $originalName !== '' ? $originalName : ('replacement.' . $extensionMap[$mime]),
        'size' => $storedSize,
        'disk_path' => $targetPath,
    ];
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

    $newRevisionUploadDiskPath = null;
    $stagedRevisionDiskPaths = [];
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
        if (!in_array((string)($request['request_status'] ?? ''), ['Requested', 'Customer Updating Details'], true)) {
            throw new RuntimeException('This revision request has already been resolved or submitted.');
        }
        $permissions = $request['permitted_fields_array'];
        if (strtoupper(trim((string)($order['payment_status'] ?? ''))) === 'PAID'
            && printflow_revision_price_impacting($permissions)) {
            throw new RuntimeException('This paid order contains price-impacting changes and must be handled by staff. No order values were changed.');
        }
        $allowedPostKeys = ['csrf_token', 'order_id', 'resubmit_order', 'spec', 'quantity', 'order_notes', 'design_order_item_id', 'design_upload_idempotency'];
        foreach (array_keys($post) as $postKey) {
            if (!in_array((string)$postKey, $allowedPostKeys, true)) {
                throw new RuntimeException('An unauthorized order field change was rejected.');
            }
        }
        foreach (array_keys($files) as $fileKey) {
            $normalizedFileKey = preg_replace('/\[\]$/', '', (string)$fileKey) ?? (string)$fileKey;
            if ($normalizedFileKey !== 'design_file') {
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
            $changedSpecs = [];
            $existingKeys = array_fill_keys(array_keys(array_merge($custom, $specs)), true);
            $serviceIdFallback = function_exists('printflow_resolve_service_catalog_service_id_for_order_line')
                ? printflow_resolve_service_catalog_service_id_for_order_line($custom, $order, $item)
                : 0;
            foreach ($changes as $keyToken => $value) {
                $key = printflow_revision_decode_form_key((string) $keyToken);
                if (!isset($existingKeys[$key]) || !printflow_revision_permission_allows(
                    $permissions,
                    $itemId,
                    $key,
                    $item,
                    $custom,
                    $serviceIdFallback
                )) {
                    throw new RuntimeException('An unauthorized specification change was rejected.');
                }
                $value = printflow_revision_validate_spec_value($item, $custom, $key, $value, $serviceIdFallback);
                $normalizedKey = printflow_revision_normalize_key($key);
                $equivalentKeys = [];
                foreach (array_unique(array_merge(array_keys($custom), array_keys($specs), [$key])) as $candidateKey) {
                    if (printflow_revision_normalize_key((string)$candidateKey) === $normalizedKey
                        && printflow_revision_keys_are_verified_aliases(
                            $item,
                            $custom,
                            $key,
                            (string)$candidateKey,
                            $serviceIdFallback
                        )) {
                        $equivalentKeys[] = (string)$candidateKey;
                    }
                }
                foreach ($equivalentKeys as $equivalentKey) {
                    $changedSpecs[$equivalentKey] = $value;
                    if (array_key_exists($equivalentKey, $custom) || !array_key_exists($equivalentKey, $specs)) {
                        $custom[$equivalentKey] = $value;
                    }
                    if (array_key_exists($equivalentKey, $specs)) {
                        $specs[$equivalentKey] = $value;
                    }
                }
            }
            if (!db_execute(
                'UPDATE order_items SET customization_data = ? WHERE order_item_id = ? AND order_id = ?',
                'sii',
                [printflow_revision_encode_json($custom), $itemId, $orderId]
            )) throw new RuntimeException('A revised specification could not be saved.');
            if (printflow_revision_column_exists('order_items', 'specifications')) {
                if (!db_execute(
                    'UPDATE order_items SET specifications = ? WHERE order_item_id = ? AND order_id = ?',
                    'sii',
                    [printflow_revision_encode_json($specs), $itemId, $orderId]
                )) throw new RuntimeException('A revised specification could not be saved.');
            }
            // Customer and staff order views may overlay this legacy mirror on
            // top of order_items, so keep it synchronized inside this same
            // transaction instead of allowing the old value to reappear.
            if (printflow_revision_column_exists('customizations', 'customization_details')) {
                $mirrorSql = 'SELECT customization_id, customization_details FROM customizations
                              WHERE order_id = ? AND order_item_id = ?';
                $mirrorRows = db_query($mirrorSql, 'ii', [$orderId, $itemId]) ?: [];
                if (count($itemMap) === 1) {
                    $orphanRows = db_query(
                        'SELECT customization_id, customization_details FROM customizations
                         WHERE order_id = ? AND (order_item_id IS NULL OR order_item_id = 0)',
                        'i',
                        [$orderId]
                    ) ?: [];
                    $mirrorRows = array_merge($mirrorRows, $orphanRows);
                }
                foreach ($mirrorRows as $mirrorRow) {
                    $mirror = printflow_revision_decode_json($mirrorRow['customization_details'] ?? '');
                    foreach ($changedSpecs as $changedKey => $changedValue) {
                        $mirror[$changedKey] = $changedValue;
                    }
                    if (!db_execute(
                        'UPDATE customizations SET customization_details = ?, updated_at = NOW() WHERE customization_id = ?',
                        'si',
                        [printflow_revision_encode_json($mirror), (int)$mirrorRow['customization_id']]
                    )) throw new RuntimeException('The customization detail mirror could not be synchronized.');
                }
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
            if (!db_execute('UPDATE order_items SET quantity = ? WHERE order_item_id = ? AND order_id = ?', 'iii', [$quantity, $itemId, $orderId])) {
                throw new RuntimeException('The revised quantity could not be saved.');
            }
        }

        if (array_key_exists('order_notes', $post)) {
            if (!in_array('order_notes', $permissions, true)) {
                throw new RuntimeException('An unauthorized order-notes change was rejected.');
            }
            if (!db_execute('UPDATE orders SET notes = ? WHERE order_id = ? AND customer_id = ?', 'sii', [sanitize((string) $post['order_notes']), $orderId, $customerId])) {
                throw new RuntimeException('The revised order notes could not be saved.');
            }
        }

        $designRequired = in_array('uploaded_design', $permissions, true);
        $designTargetItemId = 0;
        $designUploads = printflow_revision_collect_design_uploads($files);
        $revisionRequestId = (int)$request['revision_request_id'];
        $registeredRevisionFiles = [];
        if ($designRequired) {
            if ($designUploads === []) {
                throw new RuntimeException('Please upload the design file(s) requested by staff for this Request Add Details.');
            }
            $designTargetItemId = (int)($post['design_order_item_id'] ?? 0);
            if ($designTargetItemId <= 0 && count($itemMap) === 1) {
                $designTargetItemId = (int)array_key_first($itemMap);
            }
            if ($designTargetItemId <= 0 || !isset($itemMap[$designTargetItemId])) {
                throw new RuntimeException('The replacement design was not linked to a valid order item. No design was changed.');
            }
            $limitCheck = printflow_revision_design_files_assert_can_add($revisionRequestId, count($designUploads));
            if (!$limitCheck['ok']) {
                throw new RuntimeException($limitCheck['message']);
            }
            $clientIdempotency = trim((string)($post['design_upload_idempotency'] ?? ''));
            if ($clientIdempotency === '') {
                $clientIdempotency = 'rev-client-' . hash('sha256', $revisionRequestId . '|' . $designTargetItemId . '|' . count($designUploads));
            }
            foreach ($designUploads as $uploadIndex => $designFile) {
                if (!is_array($designFile) || (int)($designFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('One or more replacement design uploads did not complete. Please try again.');
                }
                if (printflow_revision_design_files_active_count($revisionRequestId) >= printflow_order_item_design_files_max_per_item()) {
                    throw new RuntimeException(printflow_order_item_design_files_limit_error_message(0));
                }
                $storedDesign = printflow_revision_store_uploaded_design(
                    $designFile,
                    $orderId,
                    $revisionRequestId,
                    $designTargetItemId
                );
                $stagedRevisionDiskPaths[] = (string)$storedDesign['disk_path'];
                $idempotencyKey = substr(
                    hash('sha256', 'rev-' . $revisionRequestId . '|' . $clientIdempotency . '|' . $uploadIndex . '|' . (string)$storedDesign['path']),
                    0,
                    64
                );
                $registered = printflow_order_item_design_file_register([
                    'order_item_id' => $designTargetItemId,
                    'order_id' => $orderId,
                    'customer_id' => $customerId,
                    'revision_request_id' => $revisionRequestId,
                    'display_name' => (string)$storedDesign['name'],
                    'mime_type' => (string)$storedDesign['mime'],
                    'storage_path' => (string)$storedDesign['path'],
                    'file_size' => (int)$storedDesign['size'],
                    'upload_sort' => $uploadIndex,
                    'idempotency_key' => $idempotencyKey,
                    'file_role' => 'revision_replacement',
                ]);
                if (!empty($registered['limit_reached']) || (int)($registered['file_id'] ?? 0) <= 0) {
                    throw new RuntimeException(printflow_order_item_design_files_limit_error_message(0));
                }
                $registeredRevisionFiles[] = (int)$registered['file_id'];
            }
            if ($registeredRevisionFiles === []) {
                throw new RuntimeException('Please upload the design file(s) requested by staff for this Request Add Details.');
            }
            $primaryFileRows = db_query(
                'SELECT storage_path, mime_type, display_name, file_size FROM order_item_design_files WHERE file_id = ? LIMIT 1',
                'i',
                [$registeredRevisionFiles[0]]
            ) ?: [];
            $primaryRow = $primaryFileRows[0] ?? [];
            $storedWebPath = trim((string)($primaryRow['storage_path'] ?? ''));
            $storedMime = trim((string)($primaryRow['mime_type'] ?? ''));
            $storedOriginalName = trim((string)($primaryRow['display_name'] ?? 'Uploaded design'));
            $primaryDisk = $storedWebPath !== '' && function_exists('printflow_resolve_order_upload_disk_path')
                ? printflow_resolve_order_upload_disk_path($storedWebPath)
                : null;
            $storedBinary = ($primaryDisk !== null && is_file($primaryDisk)) ? file_get_contents($primaryDisk) : false;
            if ($storedBinary === false || $storedBinary === '') {
                throw new RuntimeException('The revised design could not be verified before database storage.');
            }
            $newRevisionUploadDiskPath = is_string($primaryDisk) ? $primaryDisk : null;
            $designStmt = $conn->prepare(
                'UPDATE order_items
                 SET design_image = ?, design_image_mime = ?, design_image_name = ?, design_file = ?
                 WHERE order_item_id = ? AND order_id = ?'
            );
            if (!$designStmt) {
                throw new RuntimeException('The revised design could not be prepared for database storage.');
            }
            $blobPlaceholder = null;
            $designStmt->bind_param(
                'bsssii',
                $blobPlaceholder,
                $storedMime,
                $storedOriginalName,
                $storedWebPath,
                $designTargetItemId,
                $orderId
            );
            $designStmt->send_long_data(0, $storedBinary);
            $designSaved = $designStmt->execute();
            $designStmt->close();
            if (!$designSaved) {
                error_log('[REV-UP-' . $orderId . '-' . $revisionRequestId . '-' . $designTargetItemId . '] Order-item media metadata update failed after upload storage.');
                throw new RuntimeException('The revised design could not be saved.');
            }
        } elseif ($designUploads !== []) {
            throw new RuntimeException('A design upload was not authorized for this revision.');
        }

        $after = printflow_revision_snapshot($orderId);
        $after['_revision'] = [
            'price_review_required' => printflow_revision_price_impacting($permissions),
            'submitted_permissions' => $permissions,
            'design_order_item_id' => $designTargetItemId,
            'design_file_ids' => $registeredRevisionFiles,
        ];
        if (!db_execute(
            "UPDATE order_revision_requests
             SET revised_values = ?, request_status = 'Resubmitted for Review', active_flag = NULL, resubmitted_at = NOW()
             WHERE revision_request_id = ? AND active_flag = 1",
            'si',
            [printflow_revision_encode_json($after), (int) $request['revision_request_id']]
        )) throw new RuntimeException('The revision audit record could not be completed.');

        $revisionCountSql = printflow_revision_column_exists('orders', 'revision_count')
            ? ', revision_count = COALESCE(revision_count, 0) + 1'
            : '';
        if (!db_execute(
            "UPDATE orders SET status = 'Pending Approval', design_status = 'Revision Submitted', revision_reason = '', updated_at = NOW(){$revisionCountSql}
             WHERE order_id = ? AND customer_id = ?",
            'ii',
            [$orderId, $customerId]
        )) throw new RuntimeException('The order could not be returned to staff review.');
        if (!db_execute("UPDATE job_orders SET status = 'PENDING', updated_at = NOW() WHERE order_id = ?", 'i', [$orderId])) {
            throw new RuntimeException('The staff review queue could not be updated.');
        }
        if (printflow_revision_column_exists('customizations', 'status')) {
            if (!db_execute("UPDATE customizations SET status = 'Pending Review', updated_at = NOW() WHERE order_id = ?", 'i', [$orderId])) {
                throw new RuntimeException('The customization review queue could not be updated.');
            }
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
        foreach ($stagedRevisionDiskPaths as $stagedPath) {
            if (is_string($stagedPath) && $stagedPath !== '' && is_file($stagedPath) && !@unlink($stagedPath)) {
                error_log('[REV-UP-' . $orderId . '] Failed to remove an uncommitted replacement upload after transaction rollback.');
            }
        }
        if (is_string($newRevisionUploadDiskPath) && $newRevisionUploadDiskPath !== '' && is_file($newRevisionUploadDiskPath)) {
            if (!@unlink($newRevisionUploadDiskPath)) {
                error_log('[REV-UP-' . $orderId . '] Failed to remove an uncommitted replacement upload after transaction rollback.');
            }
        }
        throw $e;
    }
}
