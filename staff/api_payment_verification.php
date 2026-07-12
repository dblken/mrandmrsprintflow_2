<?php
/**
 * Staff-only OCR review actions that do not alter the order payment state.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/payment_verification.php';

require_role(['Admin', 'Staff']);
printflow_require_staff_module('payment_verification');
if (($_SESSION['user_type'] ?? '') === 'Staff') {
    require_once __DIR__ . '/../includes/staff_pending_check.php';
}
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

$submissionId = (int)($_POST['submission_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($action === 'process_queue') {
    session_write_close();
    $summary = payment_ocr_process_queue(2);
    echo json_encode(['success' => true, 'processed' => (int)$summary['processed']]);
    exit;
}
$submission = payment_verification_get_submission($submissionId);
$branchId = function_exists('printflow_branch_filter_for_user') ? printflow_branch_filter_for_user() : null;
if (($_SESSION['user_type'] ?? '') === 'Staff' && ($branchId === null || $branchId <= 0)) {
    $branchId = (int)($_SESSION['branch_id'] ?? 0);
}
if (!$submission || !payment_verification_can_access($submission, $branchId)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Payment submission not found.']);
    exit;
}

$staffId = get_user_id();
if ($action === 'save_corrections') {
    $result = payment_verification_save_corrections($submissionId, $_POST, $staffId);
    echo json_encode($result + ['csrf_token' => generate_csrf_token()]);
    exit;
}

if ($action === 'mark_duplicate') {
    $notes = trim((string)($_POST['staff_notes'] ?? ''));
    $ok = payment_verification_mark_decision($submissionId, 'Duplicate Suspected', $staffId, '', $notes);
    echo json_encode([
        'success' => $ok,
        'error' => $ok ? null : 'This submission can no longer be changed.',
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

if ($action === 'rescan') {
    if (in_array((string)$submission['verification_status'], ['Approved', 'Rejected'], true)) {
        echo json_encode(['success' => false, 'error' => 'Finalized submissions cannot be re-scanned.']);
        exit;
    }
    session_write_close();
    $result = payment_ocr_process_submission($submissionId, true);
    echo json_encode([
        'success' => !empty($result['success']),
        'status' => (string)($result['status'] ?? 'Failed'),
        'verification_status' => (string)($result['verification_status'] ?? 'Needs Review'),
        'error' => !empty($result['success']) ? null : 'OCR could not complete. The receipt is still available for manual review.',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown payment verification action.']);
