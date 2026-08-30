<?php
/**
 * Staff-only, record-ID payment proof delivery.
 * Raw storage paths are resolved exclusively from authorized database rows.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/payment_verification.php';
require_once __DIR__ . '/../../includes/payment_proof_serve.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

if (!is_logged_in() || !in_array(get_user_type(), ['Admin', 'Staff', 'Manager'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$submissionId = max(0, (int)($_GET['id'] ?? 0));
$orderId = max(0, (int)($_GET['order_id'] ?? 0));
$jobOrderId = max(0, (int)($_GET['job_order_id'] ?? 0));
$variant = (string)($_GET['variant'] ?? 'full') === 'thumbnail' ? 'thumbnail' : 'full';

if (($submissionId > 0 ? 1 : 0) + ($orderId > 0 ? 1 : 0) + ($jobOrderId > 0 ? 1 : 0) !== 1) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$record = null;
if ($submissionId > 0) {
    $record = payment_verification_get_submission($submissionId);
} elseif ($orderId > 0) {
    $submissionRows = db_query(
        'SELECT id FROM payment_submissions WHERE order_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    if (!empty($submissionRows[0]['id'])) {
        $record = payment_verification_get_submission((int)$submissionRows[0]['id']);
    } else {
        $hasProof = db_table_has_column('orders', 'payment_proof');
        $hasProofPath = db_table_has_column('orders', 'payment_proof_path');
        $proofExpression = $hasProof && $hasProofPath
            ? "COALESCE(NULLIF(TRIM(o.payment_proof), ''), NULLIF(TRIM(o.payment_proof_path), ''))"
            : ($hasProof ? "NULLIF(TRIM(o.payment_proof), '')" : ($hasProofPath ? "NULLIF(TRIM(o.payment_proof_path), '')" : 'NULL'));
        $rows = db_query(
            "SELECT o.order_id, o.branch_id, {$proofExpression} AS receipt_file
             FROM orders o WHERE o.order_id = ? LIMIT 1",
            'i',
            [$orderId]
        ) ?: [];
        $record = $rows[0] ?? null;
        if (!$record || trim((string)($record['receipt_file'] ?? '')) === '') {
            $jobRows = db_query(
                "SELECT jo.id AS job_order_id, jo.order_id,
                        COALESCE(NULLIF(jo.branch_id, 0), NULLIF(o.branch_id, 0), 0) AS branch_id,
                        jo.payment_proof_path AS receipt_file
                 FROM job_orders jo LEFT JOIN orders o ON o.order_id = jo.order_id
                 WHERE jo.order_id = ? AND NULLIF(TRIM(jo.payment_proof_path), '') IS NOT NULL
                 ORDER BY jo.payment_proof_uploaded_at DESC, jo.id DESC LIMIT 1",
                'i',
                [$orderId]
            ) ?: [];
            $record = $jobRows[0] ?? null;
        }
    }
} else {
    $submissionRows = db_query(
        'SELECT id FROM payment_submissions WHERE job_order_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$jobOrderId]
    ) ?: [];
    if (!empty($submissionRows[0]['id'])) {
        $record = payment_verification_get_submission((int)$submissionRows[0]['id']);
    } else {
        $rows = db_query(
            "SELECT jo.id AS job_order_id, jo.order_id,
                    COALESCE(NULLIF(jo.branch_id, 0), NULLIF(o.branch_id, 0), 0) AS branch_id,
                    jo.payment_proof_path AS receipt_file
             FROM job_orders jo LEFT JOIN orders o ON o.order_id = jo.order_id
             WHERE jo.id = ? LIMIT 1",
            'i',
            [$jobOrderId]
        ) ?: [];
        $record = $rows[0] ?? null;
    }
}

if (!$record) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
if (!printflow_payment_proof_staff_can_access($record)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$storedPath = $variant === 'thumbnail' ? trim((string)($record['receipt_thumbnail'] ?? '')) : '';
if ($storedPath === '' || printflow_payment_proof_resolve_file($storedPath) === null) {
    $storedPath = trim((string)($record['receipt_file'] ?? ''));
}
if ($storedPath === '') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

printflow_payment_proof_stream_file($storedPath);
