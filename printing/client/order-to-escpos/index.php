<?php

require_once __DIR__ . '/../../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$apiKey = printflow_receipt_printer_request_api_key();
$printer = printflow_receipt_printer_authenticate($apiKey);
if (empty($printer)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid printer API key.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$jobUuid = trim((string)($input['query']['_id'] ?? ''));
$rows = $jobUuid !== '' ? (db_query(
    "SELECT * FROM receipt_print_jobs
     WHERE job_uuid = ? AND printer_id = ?
       AND status IN ('pending', 'claimed')
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
     LIMIT 1",
    'si',
    [$jobUuid, (int)$printer['id']]
) ?: []) : [];

if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['error' => 'Receipt print job not found.']);
    exit;
}

$jobId = (int)$rows[0]['id'];
$claimed = db_execute_affected_rows(
    "UPDATE receipt_print_jobs
     SET status = 'delivering', claimed_at = COALESCE(claimed_at, NOW())
     WHERE id = ? AND status IN ('pending', 'claimed')
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)",
    'i',
    [$jobId]
);
if ($claimed !== 1) {
    http_response_code(409);
    echo json_encode(['error' => 'Receipt print job was already delivered.']);
    exit;
}
db_execute(
    'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
    'iss',
    [$jobId, 'delivering', 'ESC/POS payload delivered once to PushPrinter.']
);

echo json_encode(['data' => (string)$rows[0]['escpos_base64']], JSON_UNESCAPED_SLASHES);
