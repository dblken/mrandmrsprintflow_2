<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo_webhook_events.php';

if (!function_exists('printflow_money_to_centavos')) {
    function printflow_money_to_centavos($amount): int {
        return (int)round((float)$amount * 100);
    }
}

$root = dirname(__DIR__);
$webhook = (string)file_get_contents($root . '/webhooks/paymongo.php');
$events = (string)file_get_contents($root . '/includes/paymongo_webhook_events.php');
$provider = (string)file_get_contents($root . '/includes/provider_payments.php');

$passed = 0;
function qrph_expired_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$ledger = [
    'id' => 184,
    'provider' => 'paymongo',
    'mode' => 'live',
    'payment_flow' => 'payment_intent',
    'payment_intent_id' => 'pi_prod_expired_fixture',
    'payment_method_id' => 'pm_prod_expired_fixture',
    'provider_payment_id' => '',
    'status' => 'awaiting_payment',
    'amount_centavos' => 100,
    'subject_type' => 'order',
    'subject_id' => 99184,
    'order_id' => 99184,
    'job_order_id' => 0,
    'customer_id' => 77,
    'channel' => 'online',
];
$metadata = [
    'printflow_payment_id' => '184',
    'subject_type' => 'order',
    'subject_id' => '99184',
    'order_id' => '99184',
    'job_order_id' => '0',
    'customer_id' => '77',
    'channel' => 'online',
    'mode' => 'live',
    'payment_flow' => 'payment_intent',
];
$subject = [
    'customer_id' => 77,
    'order_id' => 99184,
    'total_amount' => '1.00',
    'order_status' => 'To Pay',
];
$awaitingNextActionIntent = [
    'ok' => true,
    'id' => 'pi_prod_expired_fixture',
    'mode' => 'live',
    'livemode' => true,
    'amount' => 100,
    'currency' => 'PHP',
    'status' => 'awaiting_next_action',
    'metadata' => $metadata,
];

qrph_expired_check(
    printflow_paymongo_webhook_intent_errors(
        $ledger,
        [],
        $awaitingNextActionIntent,
        $subject,
        'qrph.expired',
        'live',
        'pm_prod_expired_fixture',
        'expired'
    ) === [],
    '1. production qrph.expired with source_status=expired and provider intent awaiting_next_action is valid'
);

qrph_expired_check(
    printflow_paymongo_webhook_transition_action('awaiting_payment', 'qrph.expired') === 'mark_expired',
    '2. valid qrph.expired marks the local QR attempt expired'
);

qrph_expired_check(
    printflow_paymongo_webhook_transition_action('paid', 'qrph.expired') === 'already_paid',
    '3. qrph.expired after paid preserves Paid'
);

qrph_expired_check(
    printflow_paymongo_webhook_transition_action('expired', 'qrph.expired') === 'already_terminal',
    '4. duplicate expired local state acknowledges as terminal'
);

qrph_expired_check(
    str_contains($webhook, "\$retryable = \$eventType === 'payment.paid' && in_array('intent_status', \$verificationErrors, true)")
        && str_contains($webhook, "printflow_paymongo_webhook_complete(\$eventRowId, \$auditMetadata, !empty(\$claim['duplicate']), \$errorCode)"),
    '5. qrph.expired intent_status mismatches no longer become HTTP 503 retry failures'
);

qrph_expired_check(
    str_contains($events, "\$eventType === 'qrph.expired'")
        && str_contains($events, "\$eventSourceStatus !== 'expired'")
        && str_contains($events, "\$intentStatus === 'succeeded'"),
    '6. qrph.expired validates source expiration and still rejects succeeded intents'
);

qrph_expired_check(
    str_contains($webhook, '[paymongo-webhook] qrph_expired_context')
        && str_contains($webhook, "'source_status'")
        && str_contains($webhook, "'provider_intent_status'")
        && str_contains($webhook, "'local_ledger_status'")
        && str_contains($webhook, "'payment_flow'")
        && str_contains($webhook, "'payment_intent_id'")
        && !str_contains($webhook, 'PAYMONGO_LIVE_SECRET_KEY')
        && !str_contains($webhook, 'PAYMONGO_LIVE_WEBHOOK_SECRET'),
    '7. qrph.expired diagnostics log safe fields only'
);

qrph_expired_check(
    str_contains($provider, "status NOT IN ('paid', 'failed', 'expired', 'cancelled')")
        && str_contains($provider, "in_array(\$oldStatus, ['failed', 'expired', 'cancelled'], true)"),
    '8. already terminal local ledgers are preserved'
);

echo "All {$passed} PayMongo qrph.expired intent_status production regression tests passed.\n";
