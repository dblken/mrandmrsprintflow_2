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
    "SELECT j.id, j.job_uuid, j.order_id, j.receipt_number, j.printer_id, j.status,
            j.attempts, j.max_attempts, j.error_message, j.claimed_at, j.printed_at,
            j.failed_at, j.created_at, j.updated_at, j.branch_id,
            p.name AS printer_name, p.pushy_device_token, p.pushy_registered_at, p.last_seen_at
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

$events = db_query(
    'SELECT status, message, created_at FROM receipt_print_job_events WHERE job_id = ? ORDER BY id DESC LIMIT 12',
    'i',
    [$jobId]
) ?: [];
$events = array_reverse($events);
$deliveryStatus = (string)$job['status'];
foreach ($events as $event) {
    if ((string)$event['status'] === 'notified') $deliveryStatus = 'notification_accepted';
    if (str_contains((string)($event['message'] ?? ''), 'Push notification unavailable:')) {
        $deliveryStatus = 'notification_failed_polling_fallback';
    }
}
if ((string)$job['status'] !== 'pending') $deliveryStatus = (string)$job['status'];
$errorMessage = trim((string)($job['error_message'] ?? ''));
$message = $errorMessage !== '' ? $errorMessage : 'Receipt print job is ' . (string)$job['status'] . '.';

echo json_encode([
    'success' => true,
    'status' => (string)$job['status'],
    'message' => $message,
    'error' => $errorMessage !== '' ? $errorMessage : null,
    'job_id' => (int)$job['id'],
    'print_job_id' => (int)$job['id'],
    'printer_id' => (int)$job['printer_id'],
    'attempts' => (int)$job['attempts'],
    'provider' => 'pushy',
    'delivery_status' => $deliveryStatus,
    'job' => [
        'id' => (int)$job['id'],
        'job_id' => (int)$job['id'],
        'print_job_id' => (int)$job['id'],
        'job_uuid' => (string)$job['job_uuid'],
        'order_id' => (int)$job['order_id'],
        'order_number' => (string)$job['receipt_number'],
        'printer_id' => (int)$job['printer_id'],
        'status' => (string)$job['status'],
        'attempts' => (int)$job['attempts'],
        'max_attempts' => (int)$job['max_attempts'],
        'error_message' => (string)($job['error_message'] ?? ''),
        'claimed_at' => $job['claimed_at'],
        'printed_at' => $job['printed_at'],
        'failed_at' => $job['failed_at'],
        'created_at' => $job['created_at'],
        'updated_at' => $job['updated_at'],
        'printer_name' => (string)$job['printer_name'],
        'provider' => 'pushy',
        'delivery_status' => $deliveryStatus,
        'pushy_secret_configured' => printflow_receipt_pushy_secret() !== '',
        'pushy_device_registered' => trim((string)($job['pushy_device_token'] ?? '')) !== '',
        'pushy_registered_at' => $job['pushy_registered_at'],
        'printer_last_seen_at' => $job['last_seen_at'],
        'events' => array_map(static fn(array $event): array => [
            'status' => (string)$event['status'],
            'message' => (string)($event['message'] ?? ''),
            'created_at' => $event['created_at'],
        ], $events),
    ],
]);
