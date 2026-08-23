<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/provider_payments.php';
require_once __DIR__ . '/../../includes/pos_receipt.php';
require_once __DIR__ . '/../../includes/pos_receipt_printer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!has_role(['Admin', 'Staff', 'Manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Staff access is required.']);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST'
    ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;
$subjectType = trim((string)($input['subject_type'] ?? 'order'));
$subjectId = (int)($input['subject_id'] ?? $input['order_id'] ?? 0);
$channel = trim((string)($input['channel'] ?? 'online'));

if (!in_array($subjectType, ['order', 'job_order'], true) || $subjectId <= 0
    || !in_array($channel, ['online', 'pos'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment subject.']);
    exit;
}

$subject = printflow_provider_payment_load_subject($subjectType, $subjectId);
if (empty($subject)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}
$staffBranch = (int)($_SESSION['branch_id'] ?? 0);
$isAdmin = get_user_type() === 'Admin';
if (!$isAdmin && $staffBranch > 0 && (int)($subject['branch_id'] ?? 0) !== $staffBranch) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This order belongs to another branch.']);
    exit;
}
$mode = printflow_paymongo_mode();
$directMethods = in_array($mode, ['test', 'live'], true)
    ? printflow_paymongo_enabled_methods($mode)
    : [];
$availableFlows = [
    'qrph' => in_array('qrph', $directMethods, true),
    'payment_link' => in_array($mode, ['test', 'live'], true)
        && printflow_paymongo_secret_key_for_mode($mode) !== '',
];

if ($method === 'POST') {
    if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    if ($channel === 'online') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'code' => 'customer_owned_payment_method',
            'message' => 'Online payment method selection belongs to the customer. Staff access is read-only.',
        ]);
        exit;
    }
    if (($input['action'] ?? '') === 'complete_pos') {
        if ($channel !== 'pos') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid POS completion request.']);
            exit;
        }
        $payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
        if (empty($payment)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Payment record not found.']);
            exit;
        }
        if ((string)$payment['status'] === 'awaiting_payment'
            && printflow_provider_payment_claim_reconciliation((int)$payment['id'], 3)) {
            printflow_provider_payment_reconcile($payment);
            $payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
        }
        if ((string)($payment['status'] ?? '') !== 'paid') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'PayMongo payment is not yet confirmed.']);
            exit;
        }
        $completed = printflow_provider_payment_complete_pos((int)$payment['id'], (int)get_user_id());
        if (!empty($completed['ok']) && !empty($payment['order_id'])) {
            foreach ($_SESSION['pos_paymongo_checkouts'] ?? [] as $token => $mappedOrderId) {
                if ((int)$mappedOrderId === (int)$payment['order_id']) {
                    unset($_SESSION['pos_paymongo_checkouts'][$token]);
                }
            }
        }
        $receipt = !empty($completed['ok'])
            ? printflow_pos_build_receipt((int)($payment['order_id'] ?? 0))
            : [];
        $printJob = !empty($completed['ok'])
            ? printflow_receipt_enqueue_order_print_safe(
                (int)($payment['order_id'] ?? 0),
                $receipt,
                (int)($subject['branch_id'] ?? 0) ?: null
            )
            : null;
        http_response_code(!empty($completed['ok']) ? 200 : 409);
        echo json_encode([
            'success' => !empty($completed['ok']),
            'message' => $completed['message'] ?? null,
            'already_completed' => !empty($completed['already_completed']),
            'receipt' => !empty($receipt) ? $receipt : null,
            'print_job' => $printJob,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $action = strtolower(trim((string)($input['action'] ?? 'create_link')));
    if ($action === 'create_qrph' && !$availableFlows['qrph']) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'QR Ph is not enabled for this payment environment.',
            'available_flows' => $availableFlows,
        ]);
        exit;
    }
    if ($action === 'create_qrph') {
        $result = printflow_provider_payment_create_qrph(
            $subjectType,
            $subjectId,
            $channel,
            (int)get_user_id()
        );
    } elseif ($action === 'create_link') {
        $result = printflow_provider_payment_create_link(
            $subjectType,
            $subjectId,
            $channel,
            (int)get_user_id()
        );
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unsupported payment action.']);
        exit;
    }
    http_response_code(!empty($result['ok']) ? 200 : (int)($result['http_status'] ?? 400));
    echo json_encode([
        'success' => !empty($result['ok']),
        'mode' => (string)($result['payment']['mode'] ?? printflow_paymongo_mode()),
        'test_mode' => !empty($result['payment']['test_mode']),
        'order_id' => (int)($subject['order_id'] ?? 0),
        'payment_link_id' => (string)($result['payment']['payment_link_id'] ?? ''),
        'payment_url' => (string)($result['payment']['checkout_url'] ?? ''),
        'amount' => (int)($result['payment']['amount'] ?? 0),
        'currency' => (string)($result['payment']['currency'] ?? 'PHP'),
        'payment_status' => (string)($result['payment']['status'] ?? ''),
        'reused' => !empty($result['reused']),
        'manual_proof_under_review' => !empty($result['manual_proof_under_review']),
        'message' => $result['message'] ?? null,
        'payment' => $result['payment'] ?? null,
        'available_flows' => $availableFlows,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
if (empty($payment)) {
    echo json_encode([
        'success' => true,
        'payment' => null,
        'receipt_available' => false,
        'available_flows' => $availableFlows,
    ]);
    exit;
}

if ((string)$payment['status'] === 'awaiting_payment'
    && printflow_provider_payment_claim_reconciliation((int)$payment['id'], 5)) {
    printflow_provider_payment_reconcile($payment);
    $payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
} elseif ((string)$payment['status'] === 'paid' && !empty($payment['provider_payment_id'])) {
    printflow_provider_payment_reconcile($payment);
    $payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
}

$paid = (string)($payment['status'] ?? '') === 'paid';
$posCompleted = !empty($payment['fulfillment_applied_at']);
$orderId = (int)($payment['order_id'] ?? 0);
$receipt = $paid && $channel === 'pos' && $posCompleted && $orderId > 0
    ? printflow_pos_build_receipt($orderId)
    : [];
$printJob = !empty($receipt)
    ? printflow_receipt_enqueue_order_print_safe($orderId, $receipt, (int)($subject['branch_id'] ?? 0) ?: null)
    : null;
echo json_encode([
    'success' => true,
    'payment' => printflow_provider_payment_public($payment),
    'can_complete' => $paid && $channel === 'pos' && !$posCompleted && $orderId > 0,
    'receipt_available' => $paid && $channel === 'pos' && $posCompleted && $orderId > 0,
    'receipt' => !empty($receipt) ? $receipt : null,
    'print_job' => $printJob,
    'available_flows' => $availableFlows,
], JSON_UNESCAPED_SLASHES);
