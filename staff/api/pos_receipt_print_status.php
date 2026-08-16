<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in() || !in_array((string)get_user_type(), ['Admin', 'Manager', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$jobId = (int)($_GET['job_id'] ?? 0);
printflow_receipt_printer_ensure_schema();
$rows = $jobId > 0 ? (db_query(
    "SELECT j.id, j.status, j.attempts, j.max_attempts, j.error_message, j.printed_at,
            j.branch_id, p.name AS printer_name
     FROM receipt_print_jobs j
     INNER JOIN receipt_printers p ON p.id = j.printer_id
     WHERE j.id = ? LIMIT 1",
    'i',
    [$jobId]
) ?: []) : [];

if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Print job not found.']);
    exit;
}

$job = $rows[0];
$staffBranch = (int)($_SESSION['branch_id'] ?? 0);
if (get_user_type() !== 'Admin' && $staffBranch > 0 && (int)$job['branch_id'] !== $staffBranch) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Print job belongs to another branch.']);
    exit;
}

echo json_encode([
    'success' => true,
    'job' => [
        'id' => (int)$job['id'],
        'status' => (string)$job['status'],
        'attempts' => (int)$job['attempts'],
        'max_attempts' => (int)$job['max_attempts'],
        'error_message' => (string)($job['error_message'] ?? ''),
        'printed_at' => $job['printed_at'],
        'printer_name' => (string)$job['printer_name'],
    ],
]);
