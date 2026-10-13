<?php
/**
 * Stage POS design/reference uploads on disk before checkout JSON.
 * Accepts one or many files (design_file / design_file[]). When order_item_id is set,
 * registers each valid file in order_item_design_files (idempotent).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/service_order_helper.php';
require_once __DIR__ . '/../../includes/order_items_persistence.php';
require_once __DIR__ . '/../../includes/order_item_design_files.php';

header('Content-Type: application/json');

if (!has_role(['Admin', 'Staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

$field = trim((string)($_POST['field'] ?? 'design'));
$field = $field === 'reference' ? 'reference' : 'design';
$inputName = $field === 'reference' ? 'reference_file' : 'design_file';
$orderItemId = max(0, (int)($_POST['order_item_id'] ?? 0));
$uploadSortBase = max(0, (int)($_POST['upload_sort'] ?? 0));
$maxFiles = defined('PRINTFLOW_MAX_DESIGN_FILES_PER_ITEM') ? (int)PRINTFLOW_MAX_DESIGN_FILES_PER_ITEM : 10;

$uploads = printflow_normalize_php_file_uploads($inputName);
if ($uploads === [] && $field === 'design') {
    $uploads = printflow_normalize_php_file_uploads('design_file[]');
}
if ($uploads === []) {
    echo json_encode(['success' => false, 'message' => 'No file received.']);
    exit;
}
if (count($uploads) > $maxFiles) {
    echo json_encode([
        'success' => false,
        'message' => 'You can upload up to ' . $maxFiles . ' design files per item.',
    ]);
    exit;
}

$ctxOrderId = 0;
if ($orderItemId > 0) {
    $ctxRows = db_query('SELECT order_id FROM order_items WHERE order_item_id = ? LIMIT 1', 'i', [$orderItemId]) ?: [];
    $ctxOrderId = (int)($ctxRows[0]['order_id'] ?? 0);
    if ($ctxOrderId > 0) {
        printflow_assert_order_branch_access($ctxOrderId);
    }
}

global $conn;
$useTxn = $orderItemId > 0 && $field === 'design' && $conn instanceof mysqli;
if ($useTxn && !printflow_db_in_transaction($conn)) {
    $conn->begin_transaction();
}

$staged = [];
$errors = [];
$sort = $uploadSortBase;

foreach ($uploads as $index => $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = [
            'name' => (string)($file['name'] ?? ('file_' . $index)),
            'message' => 'Upload failed (code ' . (int)($file['error'] ?? 0) . ').',
        ];
        continue;
    }

    if ($field === 'design' && function_exists('service_order_validate_file')) {
        $valid = service_order_validate_file($file);
        if (!$valid['ok']) {
            $errors[] = [
                'name' => (string)($file['name'] ?? ('file_' . $index)),
                'message' => (string)($valid['error'] ?? 'Invalid file.'),
            ];
            continue;
        }
        $mime = (string)($valid['mime'] ?? '');
    } else {
        $mime = trim((string)($file['type'] ?? ''));
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $errors[] = [
            'name' => (string)($file['name'] ?? ('file_' . $index)),
            'message' => 'Invalid upload.',
        ];
        continue;
    }

    $originalName = trim((string)($file['name'] ?? ($field . '.bin')));
    $binary = @file_get_contents($tmpPath);
    if ($binary === false || $binary === '') {
        $errors[] = [
            'name' => $originalName,
            'message' => 'Uploaded file is empty.',
        ];
        continue;
    }

    if ($mime === '') {
        $detected = @mime_content_type($tmpPath);
        $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    $fileSize = (int)($file['size'] ?? strlen($binary));
    $webPath = printflow_write_order_upload_file(
        $binary,
        $originalName,
        $orderItemId,
        $field === 'reference' ? 'ref_staged' : 'design_staged'
    );
    if ($webPath === null) {
        $errors[] = [
            'name' => $originalName,
            'message' => 'Could not save upload. Check that uploads/orders is writable.',
        ];
        continue;
    }

    $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = 'pos-stage-' . hash('sha256', $webPath . '|' . $originalName . '|' . $sort . '|' . $index);
    } else {
        $idempotencyKey = $idempotencyKey . '-' . $index;
    }

    $entry = [
        'path' => $webPath,
        'name' => $originalName,
        'mime' => $mime,
        'size' => $fileSize,
        'field' => $field,
        'upload_sort' => $sort,
        'idempotency_key' => $idempotencyKey,
        'file_id' => 0,
        'reused' => false,
    ];

    if ($orderItemId > 0 && $field === 'design') {
        $registered = printflow_order_item_design_file_register([
            'order_item_id' => $orderItemId,
            'order_id' => $ctxOrderId,
            'display_name' => $originalName,
            'mime_type' => $mime,
            'storage_path' => $webPath,
            'file_size' => $fileSize,
            'upload_sort' => $sort,
            'idempotency_key' => $idempotencyKey,
            'file_role' => 'design',
        ]);
        $entry['file_id'] = (int)($registered['file_id'] ?? 0);
        $entry['reused'] = !empty($registered['reused']);
    }

    $staged[] = $entry;
    $sort++;
}

if ($staged === [] && $errors !== []) {
    if ($useTxn && printflow_db_in_transaction($conn)) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'message' => 'No files could be uploaded.',
        'errors' => $errors,
        'files' => [],
    ]);
    exit;
}

if ($useTxn && printflow_db_in_transaction($conn)) {
    $conn->commit();
}

$authoritative = $orderItemId > 0 && $field === 'design'
    ? printflow_order_item_design_files_list_for_item($orderItemId, false)
    : [];

$response = [
    'success' => true,
    'files' => $authoritative !== [] ? $authoritative : array_map(static function (array $row): array {
        return [
            'file_id' => (int)($row['file_id'] ?? 0),
            'display_name' => (string)($row['name'] ?? ''),
            'mime_type' => (string)($row['mime'] ?? ''),
            'file_size' => (int)($row['size'] ?? 0),
            'upload_sort' => (int)($row['upload_sort'] ?? 0),
            'storage_path' => (string)($row['path'] ?? ''),
            'preview_url' => null,
            'download_url' => null,
            'idempotency_key' => (string)($row['idempotency_key'] ?? ''),
        ];
    }, $staged),
    'staged' => $staged,
    'errors' => $errors,
    'partial' => $errors !== [],
];

if ($staged !== []) {
    $first = $staged[0];
    $response['path'] = $first['path'];
    $response['name'] = $first['name'];
    $response['mime'] = $first['mime'];
    $response['size'] = $first['size'];
    $response['upload_sort'] = $first['upload_sort'];
    $response['idempotency_key'] = $first['idempotency_key'];
    $response['file_id'] = $first['file_id'];
    $response['reused'] = $first['reused'];
}

echo json_encode($response);
