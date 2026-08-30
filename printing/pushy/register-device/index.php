<?php

require_once __DIR__ . '/../../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$apiKey = printflow_receipt_printer_request_api_key();
$printer = printflow_receipt_printer_authenticate($apiKey);
$deviceToken = trim((string)($_GET['deviceToken'] ?? ''));

if (empty($printer) || $deviceToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key or device token.']);
    exit;
}

if (!printflow_receipt_printer_register_device($printer, $deviceToken)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Device registration failed.']);
    exit;
}

echo json_encode(['success' => true, 'printer_id' => (string)$printer['id']]);
