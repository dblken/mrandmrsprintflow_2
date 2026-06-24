<?php
/**
 * Stage a POS design/reference upload on disk before checkout JSON (avoids post_max_size issues).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/order_items_persistence.php';

header('Content-Type: application/json');

if (!has_role(['Admin', 'Staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$field = trim((string)($_POST['field'] ?? 'design'));
$field = $field === 'reference' ? 'reference' : 'design';
$inputName = $field === 'reference' ? 'reference_file' : 'design_file';

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

$webPath = printflow_write_order_upload_file($binary, $originalName, 0, $field === 'reference' ? 'ref_staged' : 'design_staged');
if ($webPath === null) {
    echo json_encode(['success' => false, 'message' => 'Could not save upload. Check that uploads/orders is writable.']);
    exit;
}

echo json_encode([
    'success' => true,
    'path'    => $webPath,
    'name'    => $originalName,
    'mime'    => $mime,
    'field'   => $field,
]);
