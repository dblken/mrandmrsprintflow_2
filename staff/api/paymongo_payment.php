<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/provider_payments.php';
require_once __DIR__ . '/../../includes/pos_receipt.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!has_role(['Admin', 'Staff'])) {
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

if ($method === 'POST') {
    if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $result = printflow_provider_payment_create_link(
        $subjectType,
        $subjectId,
        $channel,
        (int)get_user_id()
    );
    http_response_code(!empty($result['ok']) ? 200 : (int)($result['http_status'] ?? 400));
    echo json_encode([
        'success' => !empty($result['ok']),
        'test_mode' => true,
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
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
if (empty($payment)) {
    echo json_encode(['success' => true, 'payment' => null, 'receipt_available' => false]);
    exit;
}

if ((string)$payment['status'] === 'awaiting_payment' && !empty($payment['link_id'])) {
    $remote = printflow_paymongo_get_paid_link_payment((string)$payment['link_id']);
    if (!empty($remote['ok']) && !empty($remote['paid']) && empty($remote['livemode'])
        && (int)($remote['amount'] ?? 0) === (int)$payment['amount_centavos']
        && strtoupper((string)($remote['currency'] ?? '')) === 'PHP'
        && !empty($remote['payment_id'])) {
        printflow_provider_payment_mark_paid((int)$payment['id'], (string)$remote['payment_id']);
        $payment = printflow_provider_payment_find($subjectType, $subjectId, $channel);
    }
}

$paid = (string)($payment['status'] ?? '') === 'paid';
$orderId = (int)($payment['order_id'] ?? 0);
echo json_encode([
    'success' => true,
    'payment' => printflow_provider_payment_public($payment),
    'receipt_available' => $paid && $channel === 'pos' && $orderId > 0,
    'receipt' => $paid && $channel === 'pos' && $orderId > 0
        ? printflow_pos_build_receipt($orderId)
        : null,
], JSON_UNESCAPED_SLASHES);
