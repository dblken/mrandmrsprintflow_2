<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/provider_payments.php';

ini_set('display_errors', '0');
$GLOBALS['printflow_customer_paymongo_response_complete'] = false;
$GLOBALS['printflow_customer_paymongo_buffer_level'] = ob_get_level();
ob_start();

/**
 * TEMPORARY production-only response-path probe.  Entries deliberately omit
 * request data, account identifiers, provider identifiers, and credentials.
 */
function printflow_customer_paymongo_probe(string $phase, array $details = []): void {
    $safe = [
        'timestamp' => gmdate('c'),
        'phase' => $phase,
    ];
    foreach ($details as $key => $value) {
        if (in_array($key, ['status', 'ok', 'qr_created', 'provider_persisted', 'payload_built', 'json_encoded', 'response_complete', 'error_type', 'exception_class', 'error_line'], true)) {
            $safe[$key] = $value;
        } elseif ($key === 'error_file') {
            $safe[$key] = basename((string)$value);
        } elseif ($key === 'error_message') {
            $message = preg_replace('/(?:sk|pk|cs|pi|pm)_[A-Za-z0-9_-]+/', '[redacted]', (string)$value) ?? '';
            $safe[$key] = substr($message, 0, 240);
        }
    }
    @file_put_contents(
        dirname(__DIR__) . '/.printflow_qrph_response_probe.log',
        json_encode($safe, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function printflow_customer_paymongo_respond(int $status, array $payload): never {
    $status = $status >= 100 && $status <= 599 ? $status : 500;
    printflow_customer_paymongo_probe('response_payload_received', ['status' => $status, 'payload_built' => true]);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        printflow_customer_paymongo_probe('response_json_failed', ['status' => 500, 'json_encoded' => false]);
        $status = 500;
        $json = '{"success":false,"code":"response_encoding_failed","message":"The payment service could not encode its response."}';
    }
    printflow_customer_paymongo_probe('response_json_ready', ['status' => $status, 'json_encoded' => true]);

    $baseLevel = (int)($GLOBALS['printflow_customer_paymongo_buffer_level'] ?? 0);
    while (ob_get_level() > $baseLevel) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8', true, $status);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $GLOBALS['printflow_customer_paymongo_response_complete'] = true;
    printflow_customer_paymongo_probe('response_committed', ['status' => $status, 'response_complete' => true]);
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit(0);
}

set_exception_handler(static function (Throwable $error): void {
    $context = isset($GLOBALS['printflow_paymongo_request_context'])
        && is_array($GLOBALS['printflow_paymongo_request_context'])
        ? $GLOBALS['printflow_paymongo_request_context']
        : [];
    error_log('[customer-paymongo-api] ' . json_encode($context + [
        'exception_class' => get_class($error),
        'exception_code' => (string)$error->getCode(),
    ], JSON_UNESCAPED_SLASHES));
    printflow_customer_paymongo_probe('uncaught_exception', [
        'status' => 500,
        'exception_class' => get_class($error),
        'error_message' => $error->getMessage(),
        'error_file' => $error->getFile(),
        'error_line' => $error->getLine(),
    ]);
    printflow_customer_paymongo_respond(500, [
        'success' => false,
        'code' => 'internal_error',
        'message' => 'The payment service could not complete the request. Please try again.',
    ]);
});
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['printflow_customer_paymongo_response_complete'])) {
        return;
    }
    $error = error_get_last();
    if ($error === null || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    printflow_customer_paymongo_probe('shutdown_fatal', [
        'status' => 500,
        'error_type' => (int)$error['type'],
        'error_message' => (string)$error['message'],
        'error_file' => (string)$error['file'],
        'error_line' => (int)$error['line'],
    ]);
    $baseLevel = (int)($GLOBALS['printflow_customer_paymongo_buffer_level'] ?? 0);
    while (ob_get_level() > $baseLevel) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true, 500);
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => false,
        'code' => 'internal_error',
        'message' => 'The payment service could not complete the request. Please try again.',
    ], JSON_UNESCAPED_SLASHES);
});

if (!is_logged_in() || get_user_type() !== 'Customer') {
    printflow_customer_paymongo_respond(403, ['success' => false, 'message' => 'Customer access is required.']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST'
    ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;
$GLOBALS['printflow_paymongo_request_context'] = [
    'action' => strtolower(trim((string)($input['action'] ?? ($method === 'GET' ? 'status' : '')))),
    'payment_flow' => in_array(($input['action'] ?? ''), ['create_qrph', 'retry_qrph'], true) ? 'payment_intent' : ((($input['action'] ?? '') === 'create_link') ? 'payment_link' : 'status'),
    'subject_type' => trim((string)($input['subject_type'] ?? 'order')),
    'subject_id' => (int)($input['subject_id'] ?? $input['order_id'] ?? 0),
];
$subjectType = trim((string)($input['subject_type'] ?? 'order'));
$subjectId = (int)($input['subject_id'] ?? $input['order_id'] ?? 0);
if (!in_array($subjectType, ['order', 'job_order'], true) || $subjectId <= 0) {
    printflow_customer_paymongo_respond(400, ['success' => false, 'message' => 'Invalid order.']);
}

$customerId = (int)get_user_id();
$subject = printflow_provider_payment_load_subject($subjectType, $subjectId);
if (empty($subject) || (int)($subject['customer_id'] ?? 0) !== $customerId) {
    printflow_customer_paymongo_respond(404, ['success' => false, 'message' => 'Order not found.']);
}

$mode = printflow_paymongo_mode();
$paymongoEnabled = printflow_paymongo_online_payment_enabled()
    && in_array($mode, ['test', 'live'], true)
    && printflow_paymongo_secret_key_for_mode($mode) !== '';
$directMethods = $paymongoEnabled ? printflow_paymongo_enabled_methods($mode) : [];
$availableFlows = [
    'qrph' => in_array('qrph', $directMethods, true),
    'payment_link' => $paymongoEnabled,
];

if ($method === 'POST') {
    if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
        printflow_customer_paymongo_respond(403, ['success' => false, 'message' => 'Invalid security token.']);
    }
    if (!$paymongoEnabled) {
        printflow_customer_paymongo_respond(503, [
            'success' => false,
            'code' => 'paymongo_unavailable',
            'message' => 'PayMongo payment is not available for this order.',
        ]);
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (in_array($action, ['create_qrph', 'retry_qrph'], true) && !$availableFlows['qrph']) {
        printflow_customer_paymongo_respond(422, [
            'success' => false,
            'code' => 'qrph_unavailable',
            'message' => 'QR Ph is not available for this payment environment.',
        ]);
    }
    $existingPayment = printflow_provider_payment_for_customer($customerId, $subjectType, $subjectId);
    if (!empty($existingPayment) && (string)($existingPayment['status'] ?? '') === 'paid') {
        printflow_customer_paymongo_respond(200, [
            'success' => true,
            'reused' => true,
            'code' => 'payment_already_paid',
            'payment' => printflow_provider_payment_public($existingPayment),
            'available_flows' => $availableFlows,
            'message' => 'This order has already been paid.',
        ]);
    }
    if (in_array($action, ['create_qrph', 'retry_qrph'], true)) {
        $result = printflow_provider_payment_create_qrph($subjectType, $subjectId, 'online', $customerId);
    } elseif ($action === 'create_link') {
        $result = printflow_provider_payment_create_link($subjectType, $subjectId, 'online', $customerId);
    } else {
        printflow_customer_paymongo_respond(400, [
            'success' => false,
            'message' => 'Unsupported payment action.',
        ]);
    }

    $publicPayment = isset($result['payment']) && is_array($result['payment'])
        ? $result['payment']
        : [];
    printflow_customer_paymongo_probe('provider_result_received', [
        'ok' => !empty($result['ok']),
        'qr_created' => !empty($publicPayment['qr_image_url']),
        'provider_persisted' => !empty($publicPayment['payment_intent_id']) && !empty($publicPayment['payment_method_id']),
    ]);
    $responseStatus = !empty($result['ok']) ? 200 : (int)($result['http_status'] ?? 500);
    $responseStatus = in_array($responseStatus, [200, 400, 401, 403, 404, 409, 422, 500, 502, 503], true)
        ? $responseStatus
        : 500;
    $responsePayload = [
        'success' => !empty($result['ok']),
        'reused' => !empty($result['reused']),
        'in_progress' => !empty($result['in_progress']),
        'code' => (string)($result['error_code'] ?? ($responseStatus === 409 ? 'payment_state_conflict' : '')),
        'payment' => $publicPayment !== [] ? $publicPayment : null,
        'payment_flow' => (string)($publicPayment['payment_flow'] ?? ''),
        'payment_method' => (string)($publicPayment['payment_method'] ?? ''),
        'status' => (string)($publicPayment['status'] ?? ''),
        'qr_image_url' => (string)($publicPayment['qr_image_url'] ?? ''),
        'qr_expires_at' => $publicPayment['qr_expires_at'] ?? null,
        'qr_expires_at_epoch' => $publicPayment['qr_expires_at_epoch'] ?? null,
        'amount_centavos' => (int)($publicPayment['amount_due_centavos'] ?? 0),
        'checkout_url' => (string)($publicPayment['checkout_url'] ?? ''),
        'available_flows' => $availableFlows,
        'message' => !empty($result['ok'])
            ? null
            : (string)($result['message'] ?? 'The payment could not be prepared. Please try again or choose another method.'),
    ];
    printflow_customer_paymongo_probe('response_payload_built', ['status' => $responseStatus, 'payload_built' => true]);
    printflow_customer_paymongo_respond(
        $responseStatus,
        $responsePayload
    );
}

$action = strtolower(trim((string)($input['action'] ?? 'status')));
if ($action !== 'status') {
    printflow_customer_paymongo_respond(400, [
        'success' => false,
        'code' => 'invalid_action',
        'message' => 'Unsupported status action.',
    ]);
}

$payment = printflow_provider_payment_for_customer($customerId, $subjectType, $subjectId);
if (empty($payment)) {
    printflow_customer_paymongo_respond(200, [
        'success' => true,
        'payment' => null,
        'available_flows' => $availableFlows,
    ]);
}

$confirming = false;
if (in_array((string)$payment['status'], ['paid', 'awaiting_payment'], true)
    && printflow_provider_payment_claim_reconciliation((int)$payment['id'], 5)) {
    $reconciled = printflow_provider_payment_reconcile($payment);
    $confirming = !empty($reconciled['paid']) && empty($reconciled['ok']);
    $payment = printflow_provider_payment_for_customer($customerId, $subjectType, $subjectId);
}

printflow_customer_paymongo_respond(200, [
    'success' => true,
    'confirming' => $confirming,
    'reconciliation_pending' => !empty($payment['reconciliation_error_code']),
    'payment' => printflow_provider_payment_public($payment),
    'available_flows' => $availableFlows,
]);
