<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo_webhook_events.php';

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$customerApi = $read('customer/api_paymongo_status.php');
$customerPage = $read('customer/payment.php');
$staffApi = $read('staff/api/paymongo_payment.php');
$staffPage = $read('staff/customizations.php');
$posApi = $read('staff/api/pos_checkout.php');
$posPage = $read('staff/pos.php');
$provider = $read('includes/provider_payments.php');
$paymongo = $read('includes/paymongo.php');
$webhook = $read('webhooks/paymongo.php');
$manualApi = $read('customer/api_submit_payment.php');
$ocr = $read('includes/payment_verification.php');

$passed = 0;
function phase3_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$publicStart = strpos($provider, 'function printflow_provider_payment_public');
$publicEnd = strpos($provider, 'function printflow_provider_payment_claim_reconciliation', $publicStart);
$publicFunction = substr($provider, $publicStart, $publicEnd - $publicStart);
$finishPaidStart = strpos($posPage, 'const finishPaidPosTransaction');
$finishPaidEnd = strpos($posPage, 'const poll = async', $finishPaidStart);
$finishPaidFunction = substr($posPage, $finishPaidStart, $finishPaidEnd - $finishPaidStart);

phase3_check(
    str_contains($customerApi, "['create_qrph', 'retry_qrph']")
        && str_contains($customerApi, 'printflow_provider_payment_create_qrph('),
    '1. customer can request server-side Dynamic QRPh creation'
);
phase3_check(
    str_contains($publicFunction, "'qr_image_url'")
        && str_contains($publicFunction, "'qr_expires_at_epoch'")
        && !str_contains($publicFunction, "'client_key'"),
    '2. customer-safe DTO includes QR data and expiry without the client key'
);
phase3_check(
    str_contains($customerApi, "(int)(\$subject['customer_id'] ?? 0) !== \$customerId"),
    '3. customer cannot create or inspect another customer order payment'
);
phase3_check(
    str_contains($provider, 'printflow_provider_payment_load_subject($subjectType, $subjectId)')
        && str_contains($provider, "\$amountCentavos = printflow_money_to_centavos(\$subject['total_amount'] ?? '')"),
    '4. PayMongo amount comes from the locked server-side subject total'
);
phase3_check(
    str_contains($customerPage, 'schedulePayMongoPoll')
        && str_contains($customerApi, 'printflow_provider_payment_reconcile($payment)'),
    '5. pending customer QRPh uses throttled server-side reconciliation polling'
);
phase3_check(
    str_contains($customerPage, "status === 'paid'")
        && str_contains($customerPage, 'renderPayMongoConfirmed(payment)'),
    '6. paid polling response renders success and stops payment polling'
);
phase3_check(
    str_contains($customerPage, "status === 'failed'")
        && str_contains($customerPage, 'Payment was not completed.'),
    '7. failed polling response renders a safe retry message'
);
phase3_check(
    str_contains($customerPage, "status === 'expired'")
        && str_contains($customerPage, 'QR code expired.'),
    '8. expired polling response renders a terminal expired state'
);
phase3_check(
    str_contains($provider, 'payment_intent_id = NULL, payment_method_id = NULL, client_key = NULL')
        && str_contains($provider, "'-retry-' . substr(hash('sha256', \$previousIntentId)"),
    '9. retry after expiry creates a fresh intent with a fresh idempotency key'
);
phase3_check(
    str_contains($customerApi, "\$action === 'create_link'")
        && str_contains($customerPage, 'Continue to Secure Checkout')
        && str_contains($provider, 'function printflow_provider_payment_create_link('),
    '10. existing customer Payment Link flow remains available'
);
phase3_check(
    !str_contains($staffPage, 'generatePayMongoPayment(')
        && !str_contains($staffPage, 'paymongoPayment.qr_image_url')
        && str_contains($staffPage, 'Awaiting Customer Payment')
        && str_contains($staffApi, "'code' => 'customer_owned_payment_method'"),
    '11. staff online payment UI is read-only and does not render customer QRPh'
);
phase3_check(
    str_contains($staffApi, "has_role(['Admin', 'Staff', 'Manager'])")
        && str_contains($staffApi, 'This order belongs to another branch.')
        && str_contains($staffApi, 'verify_csrf_token('),
    '12. unauthorized or cross-branch staff payment access is rejected'
);
phase3_check(
    str_contains($posApi, '$isPayMongoQrph')
        && str_contains($posApi, 'printflow_provider_payment_create_qrph(')
        && str_contains($posPage, 'payment?.qr_image_url'),
    '13. POS creates and displays provider-returned Dynamic QRPh'
);
phase3_check(
    str_contains($staffApi, "(string)(\$payment['status'] ?? '') !== 'paid'")
        && str_contains($staffApi, 'PayMongo payment is not yet confirmed.'),
    '14. POS completion is rejected before provider-confirmed payment'
);
phase3_check(
    str_contains($staffApi, 'printflow_provider_payment_complete_pos(')
        && str_contains($posPage, 'completePayMongoPosTransaction()'),
    '15. POS can explicitly proceed through the existing gate after payment'
);
phase3_check(
    str_contains($finishPaidFunction, 'completeButton.disabled = false')
        && !str_contains($finishPaidFunction, "action: 'complete_pos'"),
    '16. polling confirmation unlocks completion without auto-completing the order'
);
phase3_check(
    str_contains($provider, "FOR UPDATE")
        && str_contains($provider, 'QRPh regeneration is already in progress.')
        && str_contains($provider, 'channel <> ?')
        && str_contains($provider, 'active PayMongo payment in another channel')
        && str_contains($provider, 'idempotency_key'),
    '17. duplicate and cross-channel QR creation is serialized and idempotent'
);
phase3_check(
    printflow_paymongo_webhook_transition_action('paid', 'payment.paid', 'pay_same', 'pay_same') === 'already_paid'
        && str_contains($provider, 'printflow_provider_payment_claim_reconciliation('),
    '18. webhook and polling paid race remains idempotent'
);
phase3_check(str_contains($webhook, "'link.payment.paid'"), '19. link.payment.paid remains supported');
phase3_check(str_contains($webhook, "'payment.paid'"), '20. payment.paid remains supported');
phase3_check(str_contains($webhook, "'payment.failed'"), '21. payment.failed remains supported');
phase3_check(str_contains($webhook, "'qrph.expired'"), '22. qrph.expired remains supported');
phase3_check(str_contains($provider, "\$errors[] = 'amount'"), '23. wrong paid amount remains rejected');
phase3_check(str_contains($provider, "\$errors[] = 'currency'"), '24. wrong currency remains rejected');
phase3_check(str_contains($webhook, '$livemode !== $expectedLivemode'), '25. wrong webhook livemode remains rejected');
phase3_check(
    strpos($webhook, 'printflow_paymongo_verify_webhook_signature') < strpos($webhook, 'json_decode($rawBody, true)'),
    '26. invalid signatures are rejected before payload parsing'
);
phase3_check(
    printflow_paymongo_webhook_transition_action('expired', 'payment.paid') === 'mark_paid',
    '27. a verified paid event can safely recover an expired local ledger'
);
phase3_check(
    printflow_paymongo_webhook_transition_action('paid', 'qrph.expired') === 'already_paid',
    '28. an expired event cannot overwrite an already-paid ledger'
);
phase3_check(
    str_contains($customerPage, 'id="paymentForm"')
        && str_contains($manualApi, 'payment_verification')
        && str_contains($ocr, 'ocr'),
    '29. manual receipt upload and OCR verification paths remain present'
);
phase3_check(
    str_contains($posApi, "\$order_status = \$isPayMongo ? 'Pending' :")
        && str_contains($posApi, "\$initial_payment_status = \$isPayMongo ? 'Awaiting Payment' : 'Paid'"),
    '30. existing non-PayMongo POS completion behavior remains separate'
);

echo "All {$passed} PayMongo Phase 3 integration contract tests passed.\n";
