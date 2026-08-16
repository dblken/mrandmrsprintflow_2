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
    "SELECT * FROM receipt_print_jobs WHERE job_uuid = ? AND printer_id = ? LIMIT 1",
    'si',
    [$jobUuid, (int)$printer['id']]
) ?: []) : [];

if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['error' => 'Receipt print job not found.']);
    exit;
}

echo json_encode(['data' => (string)$rows[0]['escpos_base64']], JSON_UNESCAPED_SLASHES);
