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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'queue_snapshot') {
    $errorBaseline = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
    if (!payment_verification_ensure_schema()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Payment verification storage is unavailable.']);
        exit;
    }
    payment_verification_import_legacy_submissions(100);
    $branchId = function_exists('printflow_branch_filter_for_user') ? printflow_branch_filter_for_user() : null;
    if (($_SESSION['user_type'] ?? '') === 'Staff' && ($branchId === null || $branchId <= 0)) {
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
    }
    $where = " FROM payment_submissions ps
               LEFT JOIN orders o ON o.order_id = ps.order_id
               LEFT JOIN job_orders jo ON jo.id = ps.job_order_id
               WHERE 1=1";
    $types = '';
    $params = [];
    if ($branchId !== null) {
        $where .= ' AND COALESCE(NULLIF(ps.branch_id, 0), NULLIF(o.branch_id, 0), NULLIF(jo.branch_id, 0), 0) = ?';
        $types = 'i';
        $params[] = (int)$branchId;
    }
    $rows = db_query(
        "SELECT COUNT(*) AS total,
                SUM(ps.verification_status = 'Pending Review') AS pending,
                SUM(ps.verification_status = 'Matched') AS matched,
                SUM(ps.verification_status IN ('Needs Review', 'Duplicate Suspected')) AS review,
                SUM(ps.verification_status = 'Approved') AS approved
         {$where}",
        $types,
        $params
    );
    $newErrors = function_exists('printflow_db_errors') ? array_slice(printflow_db_errors(), $errorBaseline) : [];
    if (!empty($newErrors) || empty($rows)) {
        payment_verification_log('staff_queue_api_failed', ['branch_id' => $branchId, 'error_count' => count($newErrors)]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'The payment queue could not be loaded. Your current results were kept.']);
        exit;
    }
    $snapshot = array_map('intval', $rows[0]);
    payment_verification_log('staff_queue_api_loaded', ['branch_id' => $branchId, 'records' => $snapshot['total'] ?? 0]);
    echo json_encode(['success' => true, 'queue' => $snapshot, 'refreshed_at' => date(DATE_ATOM)]);
    exit;
}

// ── GET: ocr_diagnostic ──────────────────────────────────────────────────────
// Admin/Staff only. Runs a live OCR test on a submission without modifying any
// database record. Returns full diagnostic JSON for troubleshooting.
// Usage: GET /staff/api_payment_verification.php?action=ocr_diagnostic&submission_id=N
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'ocr_diagnostic') {
    // Admin-only for full diagnostic details.
    if (($_SESSION['user_type'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required for OCR diagnostics.']);
        exit;
    }
    $diagId = (int)($_GET['submission_id'] ?? 0);
    if ($diagId <= 0) {
        echo json_encode(['success' => false, 'error' => 'submission_id is required.']);
        exit;
    }
    $diagRow = payment_verification_ensure_schema()
        ? (db_query('SELECT * FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$diagId])[0] ?? null)
        : null;
    if (!$diagRow) {
        echo json_encode(['success' => false, 'error' => "Submission #{$diagId} not found."]);
        exit;
    }

    // --- environment ---
    $provider    = payment_verification_env('PAYMENT_OCR_PROVIDER', 'auto');
    $apiKey      = payment_verification_env('PAYMENT_OCR_API_KEY');
    if ($apiKey === '') $apiKey = payment_verification_env('OCR_SPACE_API_KEY');
    $apiKeySet   = $apiKey !== '';
    $tesseractBin = function_exists('payment_ocr_tesseract_binary') ? payment_ocr_tesseract_binary() : 'tesseract';
    $tesseractOk  = function_exists('payment_ocr_tesseract_available') && payment_ocr_tesseract_available();

    // --- file resolution ---
    $storedPath  = (string)($diagRow['receipt_file'] ?? '');
    $localFile   = function_exists('payment_verification_local_file')
        ? payment_verification_local_file($storedPath)
        : null;
    $fileExists  = $localFile !== null && is_file($localFile);
    $fileReadable = $fileExists && is_readable($localFile);
    $fileSize    = $fileExists ? (int)filesize($localFile) : 0;
    $mime        = $fileExists ? (string)(@mime_content_type($localFile) ?: 'unknown') : 'n/a';

    $diag = [
        'submission_id'           => $diagId,
        'stored_receipt_field'    => $storedPath,
        'resolved_local_path'     => $localFile ?? 'NOT_RESOLVED',
        'file_exists'             => $fileExists,
        'file_readable'           => $fileReadable,
        'file_size_bytes'         => $fileSize,
        'mime_type'               => $mime,
        'db_ocr_status'           => (string)($diagRow['ocr_status'] ?? ''),
        'db_ocr_error'            => (string)($diagRow['ocr_error'] ?? ''),
        'db_ocr_attempts'         => (int)($diagRow['ocr_attempts'] ?? 0),
        'env_provider'            => $provider,
        'env_api_key_set'         => $apiKeySet,
        'tesseract_binary'        => $tesseractBin,
        'tesseract_available'     => $tesseractOk,
        'curl_available'          => function_exists('curl_init'),
    ];

    if (!$fileExists) {
        $diag['ocr_test'] = 'SKIPPED — receipt file not found on server';
        echo json_encode(['success' => false, 'diagnostic' => $diag], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!$apiKeySet && !$tesseractOk) {
        $diag['ocr_test'] = 'SKIPPED — no OCR provider available (Tesseract not found + API key missing)';
        echo json_encode(['success' => false, 'diagnostic' => $diag], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // --- live OCR call (read-only, no DB write) ---
    $ocrResult = payment_ocr_extract($localFile, $mime);
    $diag['ocr_success']       = !empty($ocrResult['success']);
    $diag['ocr_provider_used'] = (string)($ocrResult['provider'] ?? 'none');
    $diag['ocr_unavailable']   = !empty($ocrResult['unavailable']);
    $diag['ocr_error']         = (string)($ocrResult['error'] ?? '');
    $rawText = (string)($ocrResult['text'] ?? '');
    $diag['raw_text_length']   = strlen($rawText);
    $diag['raw_text_preview']  = mb_substr($rawText, 0, 400);

    if (!empty($ocrResult['success']) && $rawText !== '') {
        $parsed = payment_ocr_parse_receipt_text($rawText, $ocrResult['tokens'] ?? [], (float)($ocrResult['confidence'] ?? 0));
        $diag['parsed'] = [
            'detected_method'    => $parsed['detected_payment_method'] ?? '',
            'amount_sent'        => $parsed['amount_sent'],
            'reference_number'   => $parsed['reference_number'] ?? '',
            'transaction_date'   => $parsed['transaction_date'] ?? '',
            'transaction_time'   => $parsed['transaction_time'] ?? '',
            'sender_name'        => $parsed['sender_name'] ?? '',
            'receiver_name'      => $parsed['receiver_name'] ?? '',
            'receiver_account'   => $parsed['receiver_account'] ?? '',
            'overall_confidence' => $parsed['overall_confidence'] ?? 0,
        ];
    }

    payment_verification_log('ocr_diagnostic_run', [
        'submission_id' => $diagId,
        'success'       => $diag['ocr_success'],
        'provider'      => $diag['ocr_provider_used'],
    ]);
    echo json_encode(['success' => !empty($ocrResult['success']), 'diagnostic' => $diag], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── GET: rescan_status ───────────────────────────────────────────────────────
// Returns current OCR status + error for a submission. Used by the Re-scan UI
// to poll for completion and show meaningful error messages.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'rescan_status') {
    $diagId = (int)($_GET['submission_id'] ?? 0);
    $branchId = function_exists('printflow_branch_filter_for_user') ? printflow_branch_filter_for_user() : null;
    if (($_SESSION['user_type'] ?? '') === 'Staff' && ($branchId === null || $branchId <= 0)) {
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
    }
    $diagRow = payment_verification_ensure_schema() && $diagId > 0
        ? (db_query('SELECT id, ocr_status, ocr_error, ocr_attempts, overall_confidence,
                            ocr_sender_name, ocr_reference_number, ocr_amount_sent, ocr_detected_payment_method,
                            ocr_transaction_date, ocr_transaction_time, ocr_receiver_name, ocr_receiver_account,
                            verification_status
                     FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$diagId])[0] ?? null)
        : null;
    if (!$diagRow || !payment_verification_can_access($diagRow + ['branch_id' => null, 'order_id' => null, 'job_order_id' => null], $branchId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Not found.']);
        exit;
    }
    $ocrStatus = (string)($diagRow['ocr_status'] ?? '');
    $ocrError  = (string)($diagRow['ocr_error'] ?? '');
    $humanError = match(true) {
        str_contains($ocrError, 'API key') => 'OCR is not configured on this server. Contact the administrator.',
        str_contains($ocrError, 'unavailable') => 'OCR service is unavailable. Try again later.',
        str_contains($ocrError, 'cURL') => 'Server cannot reach the OCR service.',
        str_contains($ocrError, 'file') || str_contains($ocrError, 'File') => 'The receipt file could not be read.',
        $ocrError !== '' => 'OCR could not process this receipt.',
        default => '',
    };
    echo json_encode([
        'success'             => true,
        'ocr_status'          => $ocrStatus,
        'ocr_error_internal'  => ($_SESSION['user_type'] ?? '') === 'Admin' ? $ocrError : '',
        'ocr_error_human'     => $humanError,
        'ocr_attempts'        => (int)($diagRow['ocr_attempts'] ?? 0),
        'overall_confidence'  => (float)($diagRow['overall_confidence'] ?? 0),
        'verification_status' => (string)($diagRow['verification_status'] ?? ''),
        'fields' => [
            'sender_name'       => (string)($diagRow['ocr_sender_name'] ?? ''),
            'reference_number'  => (string)($diagRow['ocr_reference_number'] ?? ''),
            'amount_sent'       => $diagRow['ocr_amount_sent'],
            'payment_method'    => (string)($diagRow['ocr_detected_payment_method'] ?? ''),
            'transaction_date'  => (string)($diagRow['ocr_transaction_date'] ?? ''),
            'transaction_time'  => (string)($diagRow['ocr_transaction_time'] ?? ''),
            'receiver_name'     => (string)($diagRow['ocr_receiver_name'] ?? ''),
            'receiver_account'  => (string)($diagRow['ocr_receiver_account'] ?? ''),
        ],
    ]);
    exit;
}


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
    payment_verification_import_legacy_submissions(100);
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
        'error' => !empty($result['success']) ? null : ($result['error'] ?? 'OCR could not complete. The receipt is still available for manual review.'),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown payment verification action.']);
