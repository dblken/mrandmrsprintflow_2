<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/change_item_workflow.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in() || (string)get_user_type() !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (!verify_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid session token. Please refresh and try again.']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$customerId = (int)get_user_id();

try {
    $photoFiles = printflow_change_item_collect_uploaded_files('proof_photos');
    $videoFile = printflow_change_item_collect_single_upload('proof_video');

    // Legacy single-file field (backward compatibility for older UI caches).
    if ($photoFiles === [] && $videoFile === null && !empty($_FILES['proof']) && is_array($_FILES['proof'])) {
        $legacy = $_FILES['proof'];
        if ((int)($legacy['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoFiles = [$legacy];
        }
    }

    printflow_change_item_validate_customer_evidence($photoFiles, $videoFile);

    $uploadedPhotos = [];
    foreach ($photoFiles as $file) {
        $uploadedPhotos[] = printflow_change_item_upload_customer_photo($file, $orderId);
    }
    $uploadedVideo = null;
    if ($videoFile !== null) {
        $uploadedVideo = printflow_change_item_upload_customer_video($videoFile, $orderId);
    }

    $primaryProof = $uploadedPhotos[0] ?? null;
    $proofPath = $primaryProof ? (string)($primaryProof['path'] ?? '') : '';
    $proofName = $primaryProof
        ? (string)($primaryProof['original_name'] ?? '')
        : ($uploadedVideo ? (string)($uploadedVideo['original_name'] ?? '') : '');

    $record = printflow_change_item_create([
        'order_id' => $orderId,
        'customer_id' => $customerId,
        'source_channel' => 'customer',
        'reason_code' => sanitize($_POST['reason_code'] ?? ''),
        'reason_label' => sanitize($_POST['reason_label'] ?? ''),
        'issue_description' => trim((string)($_POST['issue_description'] ?? '')),
        'customer_notes' => trim((string)($_POST['customer_notes'] ?? '')),
        'proof_path' => $proofPath,
        'proof_original_name' => $proofName,
        'auto_approve' => false,
        'idempotency_key' => trim((string)($_POST['idempotency_key'] ?? '')),
        'created_by_user_id' => $customerId,
        'created_by_role' => 'Customer',
    ]);

    $changeItemId = (int)($record['id'] ?? 0);
    if ($changeItemId > 0 && ($uploadedPhotos !== [] || $uploadedVideo !== null)) {
        printflow_change_item_attach_evidence($changeItemId, $uploadedPhotos, $uploadedVideo);
        $saved = db_query('SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1', 'i', [$changeItemId]) ?: [];
        if ($saved !== []) {
            $record = printflow_change_item_public_record($saved[0]) + ['duplicate' => !empty($record['duplicate'])];
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Change Item request submitted.',
        'data' => $record,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[change_item_request] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to submit Change Item request. Please try again.']);
}
