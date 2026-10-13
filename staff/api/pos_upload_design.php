<?php
/**
 * Stage a POS design/reference upload on disk before checkout JSON (avoids post_max_size issues).
 * When order_item_id is provided, registers the file in order_item_design_files (idempotent).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
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
$idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));
$uploadSort = max(0, (int)($_POST['upload_sort'] ?? 0));

if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
    echo json_encode(['success' => false, 'message' => 'No file received.']);
    exit;
}

$file = $_FILES[$inputName];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Upload failed (code ' . (int)($file['error'] ?? 0) . ').']);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    echo json_encode(['success' => false, 'message' => 'Invalid upload.']);
    exit;
}

$originalName = trim((string)($file['name'] ?? ($field . '.bin')));
$mime = trim((string)($file['type'] ?? ''));
if ($mime === '') {
    $detected = @mime_content_type($tmpPath);
    $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
}

$binary = @file_get_contents($tmpPath);
if ($binary === false || $binary === '') {
    echo json_encode(['success' => false, 'message' => 'Uploaded file is empty.']);
    exit;
}

$fileSize = (int)($file['size'] ?? strlen($binary));
$webPath = printflow_write_order_upload_file($binary, $originalName, $orderItemId, $field === 'reference' ? 'ref_staged' : 'design_staged');
if ($webPath === null) {
    echo json_encode(['success' => false, 'message' => 'Could not save upload. Check that uploads/orders is writable.']);
    exit;
}

$response = [
    'success' => true,
    'path'    => $webPath,
    'name'    => $originalName,
    'mime'    => $mime,
    'size'    => $fileSize,
    'field'   => $field,
    'upload_sort' => $uploadSort,
    'idempotency_key' => $idempotencyKey,
    'files'   => [],
];

if ($idempotencyKey === '') {
    $idempotencyKey = 'pos-stage-' . hash('sha256', $webPath . '|' . $originalName . '|' . $uploadSort);
    $response['idempotency_key'] = $idempotencyKey;
}

if ($orderItemId > 0 && $field === 'design') {
    $ctxRows = db_query('SELECT order_id FROM order_items WHERE order_item_id = ? LIMIT 1', 'i', [$orderItemId]) ?: [];
    $ctxOrderId = (int)($ctxRows[0]['order_id'] ?? 0);
    if ($ctxOrderId > 0) {
        printflow_assert_order_branch_access($ctxOrderId);
    }
    $registered = printflow_order_item_design_file_register([
        'order_item_id' => $orderItemId,
        'display_name' => $originalName,
        'mime_type' => $mime,
        'storage_path' => $webPath,
        'file_size' => $fileSize,
        'upload_sort' => $uploadSort,
        'idempotency_key' => $idempotencyKey,
        'file_role' => 'design',
    ]);
    $response['file_id'] = (int)($registered['file_id'] ?? 0);
    $response['reused'] = !empty($registered['reused']);
    $response['files'] = printflow_order_item_design_files_list_for_item($orderItemId, false);
} else {
    $response['file_id'] = 0;
    $response['reused'] = false;
}

echo json_encode($response);
