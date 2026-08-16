<?php

require_once __DIR__ . '/../../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function printer_api_respond(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function printer_api_input(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function printer_api_key(array $input): string {
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }
    $forwarded = trim((string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $forwarded, $matches)) {
        return trim($matches[1]);
    }
    return trim((string)(
        $_SERVER['HTTP_X_PRINTFLOW_PRINTER_KEY']
        ?? $input['api_key']
        ?? $_REQUEST['api_key']
        ?? ''
    ));
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    printer_api_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$input = printer_api_input();
$printer = printflow_receipt_printer_authenticate(printer_api_key($input));
if (empty($printer)) {
    printer_api_respond(401, ['ok' => false, 'error' => 'invalid_printer_api_key']);
}

$action = strtolower(trim((string)($input['action'] ?? $_GET['action'] ?? 'poll')));

try {
    if (in_array($action, ['ack', 'acknowledge', 'complete', 'status'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            printer_api_respond(405, ['ok' => false, 'error' => 'ack_requires_post']);
        }
        $jobId = (int)($input['job_id'] ?? $input['id'] ?? 0);
        $reportedStatus = strtolower(trim((string)($input['status'] ?? '')));
        $status = in_array($reportedStatus, ['printed', 'success', 'completed', 'done'], true)
            ? 'printed'
            : (in_array($reportedStatus, ['failed', 'error'], true) ? 'failed' : '');
        if ($jobId <= 0 || $status === '') {
            printer_api_respond(422, ['ok' => false, 'error' => 'invalid_acknowledgement']);
        }
        $ok = printflow_receipt_ack_job(
            $printer,
            $jobId,
            $status,
            trim((string)($input['message'] ?? $input['error'] ?? ''))
        );
        printer_api_respond($ok ? 200 : 404, ['ok' => $ok, 'job_id' => $jobId, 'status' => $status]);
    }

    if (in_array($action, ['heartbeat', 'ping'], true)) {
        printer_api_respond(200, [
            'ok' => true,
            'printer_id' => (int)$printer['id'],
            'printer_name' => (string)$printer['name'],
            'server_time' => date(DATE_ATOM),
        ]);
    }

    if (!in_array($action, ['poll', 'next', ''], true)) {
        printer_api_respond(400, ['ok' => false, 'error' => 'unknown_action']);
    }

    $job = printflow_receipt_claim_next_job($printer);
    if (empty($job)) {
        printer_api_respond(200, [
            'ok' => true,
            'job' => null,
            'printer_id' => (int)$printer['id'],
            'poll_after_ms' => 2000,
        ]);
    }

    printer_api_respond(200, [
        'ok' => true,
        'job' => printflow_receipt_public_job_payload($job, $printer),
        'poll_after_ms' => 0,
    ]);
} catch (Throwable $e) {
    error_log('[printer-api] ' . $e->getMessage());
    printer_api_respond(500, ['ok' => false, 'error' => 'printer_api_error']);
}

