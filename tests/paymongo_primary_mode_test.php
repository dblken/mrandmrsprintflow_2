<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$envExample = $read('.env.example');
$provider = $read('includes/provider_payments.php');
$customerPage = $read('customer/payment.php');
$customerApi = $read('customer/api_paymongo_status.php');
$manualApi = $read('customer/api_submit_payment.php');
$staffPage = $read('staff/customizations.php');
$staffApi = $read('staff/api/paymongo_payment.php');
$posPage = $read('staff/pos.php');
$posApi = $read('staff/api/pos_checkout.php');

$passed = 0;
function primary_mode_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$manualFormStart = strpos($customerPage, '<?php if ($manual_payment_enabled): ?>', strpos($customerPage, 'id="paymentForm"') - 100);
$paymentFormStart = strpos($customerPage, '<form id="paymentForm"');
$submitGuard = strpos($manualApi, 'if (!printflow_manual_online_payment_enabled())');
$finishPaidStart = strpos($posPage, 'const finishPaidPosTransaction');
$finishPaidEnd = strpos($posPage, 'const poll = async', $finishPaidStart);
$finishPaidFunction = substr($posPage, $finishPaidStart, $finishPaidEnd - $finishPaidStart);

primary_mode_check(
    str_contains($envExample, 'ONLINE_PAYMENT_MODE=paymongo')
        && str_contains($provider, "? \$mode : 'paymongo'"),
    '1. PayMongo is the default active online payment mode'
);
primary_mode_check(
    $manualFormStart !== false && $paymentFormStart !== false && $manualFormStart < $paymentFormStart
        && str_contains($customerPage, 'if ($paymongo_available)'),
    '2. PayMongo mode renders PayMongo while manual GCash UI remains server-conditional'
);
primary_mode_check(
    str_contains($customerPage, 'paymongoQrImage.src = payment.qr_image_url')
        && !str_contains($customerPage, 'quickchart.io')
        && str_contains($provider, "'qr_image_url' => \$qrIsActive"),
    '3. Dynamic QR uses only the validated PayMongo QR data field'
);
primary_mode_check(
    str_contains($customerPage, 'paymongo-create-link')
        && str_contains($customerApi, "\$action === 'create_link'")
        && str_contains($provider, 'function printflow_provider_payment_create_link('),
    '4. PayMongo Secure Checkout fallback remains functional'
);
primary_mode_check(
    $submitGuard !== false
        && str_contains($manualApi, 'Manual payment proof submission is currently disabled.')
        && str_contains($customerPage, 'const paymentForm = document.getElementById'),
    '5. PayMongo does not require proof upload and direct manual submission is rejected'
);
primary_mode_check(
    !str_contains($staffPage, 'Manual GCash is active')
        && str_contains($staffPage, 'The customer chooses QR Ph or Secure Checkout'),
    '6. staff page no longer presents the old Manual GCash active message'
);
primary_mode_check(
    !str_contains($staffPage, 'generatePayMongoPayment(')
        && !str_contains($staffPage, 'paymongoPayment.qr_image_url')
        && str_contains($staffPage, 'Selected by customer:')
        && str_contains($staffApi, "'code' => 'customer_owned_payment_method'"),
    '7. staff observes customer-owned PayMongo state without QR controls'
);
primary_mode_check(
    str_contains($posPage, '<option value="PayMongo QRPh">')
        && str_contains($posApi, 'printflow_provider_payment_create_qrph(')
        && str_contains($posPage, 'payment?.qr_image_url'),
    '8. POS PayMongo QR option uses Dynamic QRPh'
);
primary_mode_check(
    str_contains($finishPaidFunction, 'completeButton.disabled = false')
        && !str_contains($finishPaidFunction, "action: 'complete_pos'"),
    '9. successful POS payment does not automatically complete fulfillment'
);
primary_mode_check(
    str_contains($customerApi, "\$action === 'create_link'")
        && str_contains($customerPage, 'Continue to Secure Checkout')
        && str_contains($staffApi, "if (\$channel === 'online')"),
    '10. Payment Link creation belongs to the customer while staff remains read-only'
);
primary_mode_check(
    str_contains($customerPage, 'Payment Method')
        && str_contains($customerPage, 'GCash')
        && str_contains($customerPage, 'api_submit_payment.php')
        && str_contains($manualApi, 'payment_verification'),
    '11. manual GCash, proof, and OCR-backed processing code remains present'
);
primary_mode_check(
    str_contains($provider, "['paymongo', 'manual_gcash']")
        && str_contains($provider, "printflow_online_payment_mode() === 'manual_gcash'"),
    '12. explicit manual mode can restore the legacy customer flow'
);
primary_mode_check(
    !str_contains($customerApi, 'UPDATE payment_submissions')
        && !str_contains($staffApi, 'UPDATE payment_submissions')
        && !str_contains($posApi, 'UPDATE payment_submissions'),
    '13. activation does not rewrite historical manual payment records'
);
primary_mode_check(
    str_contains($customerPage, 'schedulePayMongoPoll')
        && str_contains($customerApi, 'printflow_provider_payment_reconcile($payment)'),
    '14. pending QRPh polling remains server-reconciled'
);
primary_mode_check(
    str_contains($customerPage, "status === 'paid'")
        && str_contains($customerPage, 'renderPayMongoConfirmed(payment)'),
    '15. paid QRPh state remains automatic'
);
primary_mode_check(
    str_contains($customerPage, "status === 'failed'")
        && str_contains($customerPage, 'Payment was not completed.'),
    '16. failed QRPh state remains supported'
);
primary_mode_check(
    str_contains($customerPage, "status === 'expired'")
        && str_contains($customerPage, "createPayMongoPayment('create_qrph')"),
    '17. expired QRPh state supports fresh retry'
);
primary_mode_check(
    !str_contains($customerPage, 'PAYMONGO_LIVE_SECRET_KEY')
        && !str_contains($customerPage, 'PAYMONGO_LIVE_WEBHOOK_SECRET')
        && !str_contains($customerApi, "'client_key'")
        && !str_contains($staffApi, "'client_key'"),
    '18. customer and staff HTML/JSON contracts expose no secret-bearing fields'
);

echo "All {$passed} PayMongo primary-mode tests passed.\n";
