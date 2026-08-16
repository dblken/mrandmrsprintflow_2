<?php

require_once __DIR__ . '/../../../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$input = json_decode((string)file_get_contents('php://input'), true);
$jobUuid = trim((string)($input['_id'] ?? ''));
$error = trim((string)($input['error'] ?? 'PushPrinter reported an unknown printing error.'));
$rows = $jobUuid !== ''
    ? (db_query('SELECT id FROM receipt_print_jobs WHERE job_uuid = ? LIMIT 1', 's', [$jobUuid]) ?: [])
    : [];
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['success' => false]);
    exit;
}

$jobId = (int)$rows[0]['id'];
error_log('[receipt-pushprinter] Job ' . $jobUuid . ' failed: ' . substr($error, 0, 1000));
db_execute(
    "UPDATE receipt_print_jobs SET status = 'failed', failed_at = NOW(), error_message = ? WHERE id = ? AND status != 'printed'",
    'si',
    [substr($error, 0, 1000), $jobId]
);
db_execute(
    'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
    'iss',
    [$jobId, 'failed', substr($error, 0, 1000)]
);
echo json_encode(['success' => true]);
