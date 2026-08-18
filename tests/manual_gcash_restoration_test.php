<?php

function manual_gcash_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$provider = (string)file_get_contents($root . '/includes/provider_payments.php');
$customerPage = (string)file_get_contents($root . '/customer/payment.php');
$submitApi = (string)file_get_contents($root . '/customer/api_submit_payment.php');
$storage = (string)file_get_contents($root . '/includes/payment_verification.php');
$staffReview = (string)file_get_contents($root . '/staff/api_verify_payment.php');
$staffQueue = (string)file_get_contents($root . '/staff/payment_verification.php');
$staffCustomizations = (string)file_get_contents($root . '/staff/customizations.php');
$paymongoApi = (string)file_get_contents($root . '/staff/api/paymongo_payment.php');
$paymongoProduction = (string)file_get_contents($root . '/staff/api/start_paymongo_production.php');
$receiptAccess = (string)file_get_contents($root . '/includes/receipt_access.php');
$migration = (string)file_get_contents($root . '/database/migrate_payment_verification_storage_20260730.php');

manual_gcash_assert(
    strpos($provider, "return in_array(\$mode, ['paymongo', 'manual_gcash'], true) ? \$mode : 'manual_gcash';") !== false,
    'manual GCash is the safe default and PayMongo remains a selectable mode'
);
manual_gcash_assert(
    strpos($customerPage, 'printflow_manual_online_payment_enabled()') !== false
        && strpos($customerPage, 'printflow_paymongo_online_payment_enabled()') !== false
        && strpos($customerPage, 'Amount Due') !== false
        && strpos($customerPage, 'Payment Method — GCash') !== false,
    'customer payment UI is mode-gated and displays the GCash amount due flow'
);
manual_gcash_assert(
    strpos($customerPage, 'accept="image/jpeg,image/png,image/webp"') !== false
        && strpos($customerPage, 'application/pdf') === false,
    'customer proof input accepts only supported receipt image formats'
);
manual_gcash_assert(
    strpos($submitApi, 'verify_csrf_token') !== false
        && strpos($submitApi, 'printflow_manual_online_payment_enabled()') !== false
        && strpos($submitApi, "(string)(\$providerPayment['status'] ?? '') === 'paid'") !== false
        && strpos($submitApi, '$requires_staff_final_price') !== false,
    'submission enforces CSRF, active mode, paid-provider conflict, and server-side final pricing'
);
manual_gcash_assert(
    strpos($submitApi, 'customer_id = ?') !== false
        && strpos($submitApi, 'begin_transaction()') !== false
        && strpos($submitApi, 'LIMIT 1 FOR UPDATE') !== false
        && strpos($submitApi, "\$payment_choice = 'full';") !== false
        && strpos($submitApi, '$amount = $total_to_pay;') !== false
        && strpos($submitApi, 'payment_verification_create_submission') !== false,
    'submission is ownership-scoped, order-locked, server-priced, and transactionally linked to the payment record'
);
manual_gcash_assert(
    strpos($storage, "'image/jpeg' => 'jpg'") !== false
        && strpos($storage, "'image/png' => 'png'") !== false
        && strpos($storage, "'image/webp' => 'webp'") !== false
        && strpos($storage, "'application/pdf' => 'pdf'") === false
        && strpos($storage, 'getimagesize($tmp)') !== false
        && strpos($storage, "hash_file('sha256'") !== false,
    'receipt storage validates image MIME/integrity and records a content hash'
);
manual_gcash_assert(
    strpos($migration, 'UNIQUE KEY `uq_payment_submissions_token` (`customer_id`, `submission_token`)') !== false,
    'database schema contains the customer submission-token idempotency constraint'
);
manual_gcash_assert(
    strpos($staffReview, 'No payment proof submission is available') !== false
        && substr_count($staffReview, 'FOR UPDATE') >= 3
        && substr_count($staffReview, 'begin_transaction()') >= 2
        && strpos($staffReview, 'payment_verification_mark_order_decision') !== false,
    'approval and rejection require a real proof and serialize the final decision'
);
manual_gcash_assert(
    strpos($staffReview, "'Approved'") !== false
        && strpos($staffReview, "'Rejected'") !== false
        && strpos($staffReview, "'IN_PRODUCTION'") !== false
        && strpos($staffReview, "'READY_TO_COLLECT'") !== false
        && strpos($staffReview, "payment_status = 'UNPAID', amount_paid = 0") !== false,
    'staff decisions reuse canonical paid, rejected, production, and pickup states'
);
manual_gcash_assert(
    strpos($staffReview, 'printflow_order_in_branch') !== false
        && strpos($staffReview, 'require_role') !== false
        && strpos($staffReview, 'verify_csrf_token') !== false,
    'staff review enforces role, branch scope, and CSRF protection'
);
manual_gcash_assert(
    strpos($staffQueue, 'selected_payment_method') !== false
        && strpos($staffQueue, 'expected_amount') !== false
        && strpos($staffQueue, 'payment_verification_proof_url') !== false
        && strpos($staffQueue, 'created_at') !== false,
    'staff queue exposes payment method, amount, proof, and submission time'
);
manual_gcash_assert(
    strpos($staffCustomizations, '$paymongoOnlineEnabled') !== false
        && strpos($staffCustomizations, 'Manual GCash is active') !== false
        && strpos($paymongoApi, "\$channel === 'online' && !printflow_paymongo_online_payment_enabled()") !== false
        && strpos($paymongoProduction, '!printflow_paymongo_online_payment_enabled()') !== false,
    'online PayMongo controls are inactive while code and POS support remain present'
);
manual_gcash_assert(
    strpos($receiptAccess, 'printflow_customer_receipt_is_available') !== false
        && strpos($receiptAccess, "strcasecmp(trim(\$paymentStatus), 'Paid')") !== false
        && strpos($receiptAccess, "'in production'") !== false,
    'final receipts still require paid status and production progress'
);

echo "Manual GCash restoration regression test passed.\n";
