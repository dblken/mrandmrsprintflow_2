<?php
/**
 * Starts OCR for a receipt owned by the logged-in customer.
 * The response intentionally excludes OCR text and technical provider errors.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_verification.php';

require_role('Customer');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

$submissionId = (int)($_POST['submission_id'] ?? 0);
$customerId = get_user_id();
$rows = payment_verification_ensure_schema()
    ? db_query(
        'SELECT id, ocr_status FROM payment_submissions WHERE id = ? AND customer_id = ? LIMIT 1',
        'ii',
        [$submissionId, $customerId]
    )
    : [];
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Payment submission not found.']);
    exit;
}

session_write_close();
try {
    $result = payment_ocr_process_submission($submissionId);
} catch (Throwable $error) {
    payment_verification_record_ocr_failure($submissionId, $error, 'Failed');
    payment_verification_log('customer_ocr_endpoint_failed', [
        'submission_id' => $submissionId,
        'customer_id' => $customerId,
        'reason' => $error->getMessage(),
    ]);
    $result = [
        'success' => false,
        'status' => 'Failed',
        'verification_status' => 'Needs Review',
    ];
}
$status = (string)($result['status'] ?? 'Failed');
echo json_encode([
    'success' => true,
    'status' => $status === 'Completed' ? 'Processed' : 'Pending Staff Review',
]);
