<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/provider_payments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in() || get_user_type() !== 'Customer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Customer access is required.']);
    exit;
}

$subjectType = trim((string)($_GET['subject_type'] ?? 'order'));
$subjectId = (int)($_GET['subject_id'] ?? $_GET['order_id'] ?? 0);
if (!in_array($subjectType, ['order', 'job_order'], true) || $subjectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}

$payment = printflow_provider_payment_for_customer(
    (int)get_user_id(),
    $subjectType,
    $subjectId
);
if (empty($payment)) {
    echo json_encode(['success' => true, 'payment' => null]);
    exit;
}

if ((string)$payment['status'] === 'awaiting_payment' && !empty($payment['link_id'])) {
    $remote = printflow_paymongo_get_paid_link_payment((string)$payment['link_id']);
    if (!empty($remote['ok']) && !empty($remote['paid']) && empty($remote['livemode'])
        && (int)($remote['amount'] ?? 0) === (int)$payment['amount_centavos']
        && strtoupper((string)($remote['currency'] ?? '')) === 'PHP'
        && !empty($remote['payment_id'])) {
        printflow_provider_payment_mark_paid((int)$payment['id'], (string)$remote['payment_id']);
        $payment = printflow_provider_payment_for_customer(
            (int)get_user_id(),
            $subjectType,
            $subjectId
        );
    }
}

echo json_encode([
    'success' => true,
    'payment' => printflow_provider_payment_public($payment),
], JSON_UNESCAPED_SLASHES);
