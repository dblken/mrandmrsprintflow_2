<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo_webhook_events.php';

if (!function_exists('printflow_money_to_centavos')) {
    function printflow_money_to_centavos($amount): int {
        return (int)round((float)$amount * 100);
    }
}

$passed = 0;
function phase2_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$webhookSource = (string)file_get_contents(__DIR__ . '/../webhooks/paymongo.php');
$providerSource = (string)file_get_contents(__DIR__ . '/../includes/provider_payments.php');
$ledger = [
    'id' => 71,
    'provider' => 'paymongo',
    'mode' => 'test',
    'payment_flow' => 'payment_intent',
    'payment_intent_id' => 'pi_fixture71',
    'payment_method_id' => 'pm_fixture71',
    'provider_payment_id' => '',
    'status' => 'pending',
    'amount_centavos' => 125000,
    'subject_type' => 'order',
    'subject_id' => 501,
    'order_id' => 501,
    'job_order_id' => 0,
    'customer_id' => 19,
    'channel' => 'customer',
];
$metadata = [
    'printflow_payment_id' => '71',
    'subject_type' => 'order',
    'subject_id' => '501',
    'order_id' => '501',
    'job_order_id' => '0',
    'customer_id' => '19',
    'channel' => 'customer',
    'mode' => 'test',
    'payment_flow' => 'payment_intent',
    'payment_method' => 'qrph',
];
$subject = [
    'customer_id' => 19,
    'order_id' => 501,
    'total_amount' => '1250.00',
    'order_status' => 'To Pay',
];
$intent = [
    'ok' => true,
    'id' => 'pi_fixture71',
    'mode' => 'test',
    'livemode' => false,
    'amount' => 125000,
    'currency' => 'PHP',
    'status' => 'succeeded',
    'metadata' => $metadata,
];
$paidPayment = [
    'ok' => true,
    'payment_id' => 'pay_fixture71',
    'payment_intent_id' => 'pi_fixture71',
    'payment_method_id' => 'pm_fixture71',
    'mode' => 'test',
    'livemode' => false,
    'amount' => 125000,
    'currency' => 'PHP',
    'status' => 'paid',
    'payment_method' => 'qrph',
];
$failedPayment = array_merge($paidPayment, [
    'payment_id' => 'pay_fixture_failed71',
    'status' => 'failed',
    'failure_code' => 'generic_decline',
]);
$awaitingIntent = array_merge($intent, ['status' => 'awaiting_payment_method']);

phase2_check(
    str_contains($webhookSource, "'link.payment.paid'")
        && str_contains($webhookSource, 'printflow_paymongo_get_paid_link_payment($linkId, $expectedMode)'),
    '1. existing valid link.payment.paid path remains routed and provider-verified'
);
phase2_check(
    printflow_paymongo_webhook_intent_errors(
        $ledger, $paidPayment, $intent, $subject, 'payment.paid', 'test', 'pm_fixture71'
    ) === []
        && printflow_paymongo_webhook_transition_action('pending', 'payment.paid') === 'mark_paid',
    '2. valid payment.paid is accepted and finalizes through the paid action'
);
phase2_check(
    printflow_paymongo_webhook_intent_errors(
        $ledger, $failedPayment, $awaitingIntent, $subject, 'payment.failed', 'test', 'pm_fixture71'
    ) === []
        && printflow_paymongo_webhook_transition_action('pending', 'payment.failed') === 'mark_failed',
    '3. valid payment.failed is accepted without a paid transition'
);
phase2_check(
    printflow_paymongo_webhook_intent_errors(
        $ledger, [], $awaitingIntent, $subject, 'qrph.expired', 'test', 'pm_fixture71', 'expired'
    ) === []
        && printflow_paymongo_webhook_transition_action('pending', 'qrph.expired') === 'mark_expired',
    '4. valid qrph.expired is accepted without a paid transition'
);
phase2_check(
    printflow_paymongo_webhook_transition_action(
        'paid', 'payment.paid', 'pay_fixture71', 'pay_fixture71'
    ) === 'already_paid',
    '5. duplicate payment.paid delivery is idempotent'
);
$wrongAmount = array_merge($paidPayment, ['amount' => 124999]);
phase2_check(
    in_array('amount', printflow_paymongo_webhook_intent_errors(
        $ledger, $wrongAmount, $intent, $subject, 'payment.paid', 'test', 'pm_fixture71'
    ), true),
    '6. wrong paid amount is rejected'
);
$wrongCurrency = array_merge($paidPayment, ['currency' => 'USD']);
phase2_check(
    in_array('currency', printflow_paymongo_webhook_intent_errors(
        $ledger, $wrongCurrency, $intent, $subject, 'payment.paid', 'test', 'pm_fixture71'
    ), true),
    '7. non-PHP payment currency is rejected'
);
$wrongIntentPayment = array_merge($paidPayment, ['payment_intent_id' => 'pi_someone_else']);
phase2_check(
    in_array('payment_intent_ownership', printflow_paymongo_webhook_intent_errors(
        $ledger, $wrongIntentPayment, $intent, $subject, 'payment.paid', 'test', 'pm_fixture71'
    ), true),
    '8. wrong Payment Intent ownership is rejected'
);
$livePayment = array_merge($paidPayment, ['mode' => 'live', 'livemode' => true]);
phase2_check(
    in_array('payment', printflow_paymongo_webhook_intent_errors(
        $ledger, $livePayment, $intent, $subject, 'payment.paid', 'test', 'pm_fixture71'
    ), true),
    '9. wrong mode or livemode is rejected'
);
$signaturePosition = strpos($webhookSource, 'printflow_paymongo_verify_webhook_signature');
$decodePosition = strpos($webhookSource, 'json_decode($rawBody, true)');
phase2_check(
    $signaturePosition !== false && $decodePosition !== false && $signaturePosition < $decodePosition
        && str_contains($providerSource, "hash_hmac('sha256'")
        && str_contains($providerSource, 'hash_equals'),
    '10. invalid signatures are rejected before payload processing using HMAC SHA-256'
);
$cancelledSubject = array_merge($subject, ['order_status' => 'Cancelled']);
phase2_check(
    in_array('subject_status', printflow_paymongo_webhook_intent_errors(
        $ledger, $paidPayment, $intent, $cancelledSubject, 'payment.paid', 'test', 'pm_fixture71'
    ), true),
    '11. cancelled or rejected orders cannot be finalized as paid'
);
phase2_check(
    printflow_paymongo_webhook_transition_action('paid', 'qrph.expired') === 'already_paid',
    '12. QR expiration cannot override an already paid ledger'
);
phase2_check(
    str_contains($providerSource, 'function printflow_provider_payment_create_link(')
        && str_contains($webhookSource, 'printflow_provider_payment_mark_paid(')
        && str_contains($webhookSource, "hash('sha256', \$rawBody)")
        && str_contains($webhookSource, "WHERE provider = 'paymongo' AND event_id = ?"),
    '13. Payment Link creation, finalization, payload hashing, and event inbox remain present'
);
phase2_check(
    printflow_paymongo_webhook_transition_action(
        'paid', 'payment.paid', 'pay_fixture71', 'pay_fixture71'
    ) === 'already_paid'
        && printflow_paymongo_webhook_transition_action(
            'paid', 'payment.paid', 'pay_fixture71', 'pay_other'
        ) === 'provider_payment_conflict'
        && str_contains($webhookSource, 'printflow_provider_payment_reconcile_intent($ledger)'),
    '14. reconciliation and webhook races remain idempotent and detect conflicts'
);

echo "All {$passed} PayMongo Phase 2 webhook tests passed.\n";
