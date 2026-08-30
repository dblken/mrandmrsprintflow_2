<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !in_array((string)get_user_type(), ['Admin', 'Manager', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || !verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$jobId = (int)($input['job_id'] ?? 0);
$rows = $jobId > 0 ? (db_query(
    'SELECT id, branch_id FROM receipt_print_jobs WHERE id = ? LIMIT 1',
    'i',
    [$jobId]
) ?: []) : [];
$staffBranch = (int)($_SESSION['branch_id'] ?? 0);
$allowed = !empty($rows) && (
    get_user_type() === 'Admin'
    || $staffBranch <= 0
    || (int)$rows[0]['branch_id'] === $staffBranch
);
$ok = $allowed && printflow_receipt_retry_job($jobId);
echo json_encode([
    'success' => $ok,
    'message' => $ok
        ? 'Receipt print job queued for retry.'
        : 'Receipt print job could not be retried. It may still be printing or may belong to another branch.',
    'print_job' => $ok ? ['ok' => true, 'job_id' => $jobId, 'status' => 'pending'] : null,
]);
