<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/provider_payments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function printflow_customer_paymongo_respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_logged_in() || get_user_type() !== 'Customer') {
    printflow_customer_paymongo_respond(403, ['success' => false, 'message' => 'Customer access is required.']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST'
    ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;
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
        printflow_customer_paymongo_respond(409, [
            'success' => false,
            'message' => 'PayMongo payment is not available for this order.',
        ]);
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    if ($action === 'create_qrph' && !$availableFlows['qrph']) {
        printflow_customer_paymongo_respond(409, [
            'success' => false,
            'message' => 'QR Ph is not available for this payment environment.',
        ]);
    }
    if ($action === 'create_qrph') {
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
    printflow_customer_paymongo_respond(
        !empty($result['ok']) ? 200 : (int)($result['http_status'] ?? 409),
        [
            'success' => !empty($result['ok']),
            'reused' => !empty($result['reused']),
            'payment' => $publicPayment !== [] ? $publicPayment : null,
            'available_flows' => $availableFlows,
            'message' => !empty($result['ok'])
                ? null
                : 'The payment could not be prepared. Please try again or choose another method.',
        ]
    );
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
