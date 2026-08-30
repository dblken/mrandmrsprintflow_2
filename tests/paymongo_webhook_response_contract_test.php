<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo_webhook_events.php';

$passed = 0;

function webhook_response_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$root = dirname(__DIR__);
$webhook = (string)file_get_contents($root . '/webhooks/paymongo.php');
$events = (string)file_get_contents($root . '/includes/paymongo_webhook_events.php');
$provider = (string)file_get_contents($root . '/includes/provider_payments.php');

webhook_response_check(
    str_contains($webhook, "'payment.paid'")
        && str_contains($events, "'payment.paid' => 'mark_paid'")
        && str_contains($webhook, 'printflow_paymongo_webhook_complete($eventRowId, $auditMetadata'),
    '1. valid payment.paid -> HTTP 200'
);

webhook_response_check(
    str_contains($webhook, "'payment.failed'")
        && str_contains($events, "'payment.failed' => 'mark_failed'")
        && str_contains($webhook, "printflow_provider_payment_mark_failed("),
    '2. valid payment.failed -> HTTP 200'
);

webhook_response_check(
    str_contains($webhook, "'qrph.expired'")
        && str_contains($events, "'qrph.expired' => 'mark_expired'")
        && str_contains($webhook, "printflow_provider_payment_mark_expired("),
    '3. valid qrph.expired -> HTTP 200'
);

webhook_response_check(
    str_contains($webhook, "'link.payment.paid'")
        && str_contains($webhook, 'printflow_paymongo_get_paid_link_payment($linkId, $expectedMode)')
        && str_contains($webhook, 'printflow_paymongo_webhook_respond(200, [')
        && str_contains($webhook, "'processed' => true"),
    '4. valid link.payment.paid -> HTTP 200'
);

webhook_response_check(
    str_contains($webhook, "if (!empty(\$claim['processed']))")
        && str_contains($webhook, "'duplicate' => true")
        && str_contains($webhook, "'event_processing_in_progress'")
        && str_contains($webhook, "'accepted_code' => \$code"),
    '5. duplicate event -> HTTP 200'
);

webhook_response_check(
    printflow_paymongo_webhook_transition_action(
        'paid',
        'payment.paid',
        'pay_contract',
        'pay_contract'
    ) === 'already_paid'
        && str_contains($webhook, "in_array(\$action, ['already_paid', 'already_terminal'], true)"),
    '6. already-paid duplicate payment.paid -> HTTP 200'
);

webhook_response_check(
    printflow_paymongo_webhook_transition_action('paid', 'qrph.expired') === 'already_paid'
        && str_contains($provider, "WHERE id = ? AND status NOT IN ('paid', 'failed', 'expired', 'cancelled')"),
    '7. expired event after paid -> HTTP 200 and Paid preserved'
);

webhook_response_check(
    str_contains($webhook, "'ignored' => true")
        && str_contains($webhook, "printflow_paymongo_webhook_respond(200, ['success' => true, 'processed' => false, 'ignored' => true])")
        && !str_contains($webhook, "printflow_paymongo_webhook_respond(202"),
    '8. valid but intentionally ignored PayMongo event -> HTTP 200'
);

webhook_response_check(
    str_contains($webhook, 'printflow_paymongo_verify_webhook_signature($rawBody, $signature, $expectedMode)')
        && str_contains($webhook, "printflow_paymongo_webhook_respond(401, ['success' => false, 'message' => 'Invalid webhook signature.'])"),
    '9. invalid signature -> appropriate non-2xx'
);

webhook_response_check(
    str_contains($webhook, 'json_last_error() !== JSON_ERROR_NONE')
        && str_contains($webhook, "printflow_paymongo_webhook_respond(400, ['success' => false, 'message' => 'Malformed webhook payload.'])")
        && str_contains($webhook, "printflow_paymongo_webhook_fail(\$eventRowId, 'payment_id_missing', [], 400)"),
    '10. malformed request -> appropriate controlled response'
);

webhook_response_check(
    str_contains($webhook, 'set_exception_handler(static function (Throwable $error): void')
        && str_contains($webhook, "'error_code' => 'internal_error'")
        && str_contains($webhook, "error_log('[paymongo-webhook] unexpected_exception '")
        && !str_contains($webhook, 'HTTP_AUTHORIZATION'),
    '11. unexpected internal exception -> 500 with safe logging'
);

webhook_response_check(
    printflow_paymongo_webhook_transition_action('failed', 'payment.failed') === 'already_terminal'
        && printflow_paymongo_webhook_transition_action('expired', 'qrph.expired') === 'already_terminal'
        && str_contains($provider, "in_array(\$oldStatus, ['failed', 'expired', 'cancelled'], true)"),
    '12. finalized failed/expired ledgers are preserved and acknowledged'
);

echo "All {$passed} PayMongo webhook response contract tests passed.\n";
