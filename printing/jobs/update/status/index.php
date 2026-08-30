<?php

require_once __DIR__ . '/../../../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$jobUuid = trim((string)($_GET['_id'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$allowed = ['received', 'data_fetched', 'printed'];
if ($jobUuid === '' || !in_array($status, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['success' => false]);
    exit;
}

$jobStatuses = $status === 'received' ? ['pending', 'claimed'] : ['claimed', 'delivering'];
$statusPlaceholders = implode(',', array_fill(0, count($jobStatuses), '?'));
$rows = db_query(
    "SELECT id, status FROM receipt_print_jobs
     WHERE job_uuid = ?
       AND status IN ($statusPlaceholders)
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
     LIMIT 1",
    's' . str_repeat('s', count($jobStatuses)),
    array_merge([$jobUuid], $jobStatuses)
) ?: [];
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['success' => false]);
    exit;
}
$jobId = (int)$rows[0]['id'];

if ($status === 'received') {
    db_execute(
        "UPDATE receipt_print_jobs
         SET status = 'claimed', claimed_at = COALESCE(claimed_at, NOW()),
             attempts = CASE WHEN status = 'pending' THEN attempts + 1 ELSE attempts END
         WHERE id = ? AND status != 'printed'",
        'i',
        [$jobId]
    );
} elseif ($status === 'printed') {
    db_execute(
        "UPDATE receipt_print_jobs SET status = 'printed', printed_at = NOW(), error_message = NULL WHERE id = ?",
        'i',
        [$jobId]
    );
}
db_execute(
    'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
    'iss',
    [$jobId, $status === 'data_fetched' ? 'claimed' : $status, 'PushPrinter status: ' . $status]
);

echo json_encode(['success' => true]);
