<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$api = $read('customer/api_paymongo_status.php');
$provider = $read('includes/provider_payments.php');
$customer = $read('customer/payment.php');
$orders = $read('customer/orders.php');
$staff = $read('staff/customizations.php');
$staffApi = $read('staff/api/paymongo_payment.php');
$posApi = $read('staff/api/pos_checkout.php');

$passed = 0;
function qrph_409_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

qrph_409_check(str_contains($api, "['create_qrph', 'retry_qrph']") && str_contains($api, 'printflow_provider_payment_create_qrph('), '1. first QRPh creation uses the explicit creation action');
qrph_409_check(str_contains($customer, 'if (paymentCreateInFlight) return null;') && str_contains($provider, "'in_progress' => true"), '2. duplicate clicks and concurrent creation return an idempotent in-progress response');
qrph_409_check(str_contains($provider, "'reused' => true") && str_contains($provider, '$qrIsUsable'), '3. an existing usable QR is returned instead of recreated');
qrph_409_check(str_contains($api, "\$input['action'] ?? 'status'") && str_contains($api, 'printflow_customer_paymongo_respond(200'), '4. status polling is separate and returns HTTP 200');
qrph_409_check(str_contains($customer, 'schedulePayMongoPoll') && str_contains($customer, "['generating', 'awaiting_payment'].includes(status)"), '5. repeated pending polling remains HTTP-based and bounded to active states');
qrph_409_check(str_contains($provider, "status IN ('failed', 'expired', 'cancelled')") && str_contains($provider, '$resetExpiredIntentSql'), '6. expired QR can claim a fresh creation attempt');
qrph_409_check(str_contains($provider, "status IN ('failed', 'expired', 'cancelled')") && str_contains($provider, '$reuseIntent = false;'), '7. failed QR can create a fresh Payment Intent');
qrph_409_check(str_contains($api, "'code' => 'payment_already_paid'") && str_contains($api, "'success' => true"), '8. paid order creation returns a normalized paid response');
qrph_409_check(str_contains($provider, "This order is already paid.") && str_contains($api, 'printflow_provider_payment_public($existingPayment)'), '9. a paid order cannot create another provider payment');
qrph_409_check(str_contains($provider, 'Another request already owns this short-lived creation') && str_contains($provider, "'http_status' => 409, 'message' => 'Payment Intent generation is already in progress.'") === false, '10. two tabs do not receive the old generating-state 409');
qrph_409_check(str_contains($api, "(int)(\$subject['customer_id'] ?? 0) !== \$customerId"), '11. customers cannot access another customer payment');
qrph_409_check(str_contains($provider, 'printflow_paymongo_archive_payment_link(') && str_contains($provider, "'superseded' => true"), '12. an active Payment Link is archived before QRPh safely replaces it');
qrph_409_check(str_contains($provider, 'channel <> ?') && str_contains($staffApi, "'code' => 'customer_owned_payment_method'"), '13. staff cannot pre-create an online flow and cross-channel protection remains enforced');
qrph_409_check(str_contains($posApi, 'printflow_provider_payment_create_qrph(') && str_contains($provider, 'active PayMongo payment in another channel'), '14. POS/customer cross-channel active payment protection remains enforced');
qrph_409_check(str_contains($api, "'code' => 'invalid_action'") && str_contains($api, 'Unsupported payment action.'), '15. invalid requests receive structured JSON errors');
qrph_409_check(str_contains($api, "\$responseStatus === 409 ? 'payment_state_conflict'") && str_contains($provider, 'final price no longer matches'), '16. genuine business conflicts still return structured HTTP 409');
qrph_409_check(str_contains($customer, 'parsePayMongoJson') && str_contains($customer, 'data.message ||'), '17. frontend safely parses and displays structured conflict responses');
qrph_409_check(str_contains($customer, "selectedPayMongoMethod !== 'qrph'") && str_contains($customer, "!['generating', 'awaiting_payment'].includes(status)") && str_contains($customer, 'pagehide'), '18. non-QR and terminal states cannot create an infinite polling loop');
qrph_409_check(str_contains($customer, 'window.clearTimeout(paymongoPollTimer)') && str_contains($staff, 'if (this.paymongoPollTimer) window.clearTimeout'), '19. customer and staff maintain only one polling timer');
qrph_409_check(str_contains($provider, 'already finalized with a different provider transaction') && str_contains($provider, 'FOR UPDATE'), '20. duplicate successful payment application remains locked and rejected');

qrph_409_check(!str_contains($customer, '✓ Payment Confirmed') && !str_contains($customer, "icon.textContent = '✓'"), '21. Payment Confirmed has no check icon');
qrph_409_check(str_contains($customer, 'class="paid-total"') && str_contains($customer, 'payment-detail-grid'), '22. paid amount and key values use an emphasized professional layout');
qrph_409_check(str_contains($customer, 'payment-status-badge') && !str_contains($customer, 'confirmedButton'), '23. Awaiting Production is a compact semantic status');
qrph_409_check(!str_contains($orders, '<strong>Balance:</strong>') && !str_contains($orders, '>Remaining balance<') && !str_contains($customer, 'Remaining Balance:'), '24. customer Balance and Remaining Balance fields are removed');
qrph_409_check(str_contains($customer, 'paymongo-options') && str_contains($customer, 'Generating secure QR...'), '25. PayMongo selector and loading state use the redesigned UI');
qrph_409_check(str_contains($staff, 'id="paymongo-paid-details"') && !str_contains($staff, 'Remaining Balance:'), '26. staff Payment Received details are readable and omit the redundant balance');

echo "All {$passed} PayMongo QRPh 409 and payment UI regression tests passed.\n";
