<?php

function paymongo_gate_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$production = (string)file_get_contents($root . '/staff/api/start_paymongo_production.php');
$staffModal = (string)file_get_contents($root . '/staff/get_order_for_modal.php');

paymongo_gate_assert(
    strpos($production, '$paymentMode = strtolower((string)($payment[\'mode\'] ?? \'\'));') !== false
        && strpos($production, "mode = 'test'") === false
        && strpos($production, 'AND mode = ? AND channel = \'online\'') !== false,
    'production uses the saved PayMongo ledger mode instead of a hard-coded test ledger'
);
paymongo_gate_assert(
    strpos($production, 'verify_csrf_token') !== false
        && strpos($production, 'printflow_assert_order_branch_access($orderId)') !== false
        && strpos($production, '$requiredBranchId') !== false,
    'production enforces CSRF and checks branch access both before and after locking'
);
paymongo_gate_assert(
    substr_count($production, 'FOR UPDATE') >= 4
        && strpos($production, 'printflow_provider_payment_reconcile($payment)') !== false,
    'production reconciles provider state before taking row locks for the final gate'
);
foreach (['paid_amount_centavos', 'provider_payment_id', 'payment_date', 'printflow_order_price_is_final'] as $needle) {
    paymongo_gate_assert(
        strpos($production, $needle) !== false,
        "production verifies {$needle}"
    );
}
paymongo_gate_assert(
    strpos($production, 'design_status') !== false
        && strpos($production, 'Every customization must be approved') !== false,
    'production requires an approved design and approved customization records'
);
paymongo_gate_assert(
    strpos($production, "'Resubmitted for Review', 'Staff Reviewing'") !== false
        && strpos($production, 'Resolve the active customer revision') !== false,
    'production blocks requested and resubmitted revisions'
);
paymongo_gate_assert(
    strpos($production, "'start_production'") !== false
        && strpos($production, '$transitionInserted') !== false
        && strpos($production, '$notificationExists') !== false,
    'production transition and customer notification are idempotency-gated'
);
paymongo_gate_assert(
    strpos($staffModal, '$canReconcileAwaiting') !== false
        && strpos($staffModal, 'printflow_provider_payment_claim_reconciliation') !== false
        && strpos($staffModal, 'printflow_provider_payment_reconcile($provider_payment)') !== false,
    'staff order detail performs throttled server-side reconciliation of awaiting payments'
);
foreach (['amount_paid', 'remaining_balance', 'payment_reference', 'payment_paid_at', 'payment_mode', 'payment_test_mode'] as $field) {
    paymongo_gate_assert(
        strpos($staffModal, "'{$field}' =>") !== false,
        "staff order detail exposes {$field}"
    );
}

echo "PayMongo production-gate regression test passed.\n";
