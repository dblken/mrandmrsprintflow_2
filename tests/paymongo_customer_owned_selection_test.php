<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo.php';

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$customer = $read('customer/payment.php');
$customerApi = $read('customer/api_paymongo_status.php');
$staff = $read('staff/customizations.php');
$staffApi = $read('staff/api/paymongo_payment.php');
$provider = $read('includes/provider_payments.php');
$schema = $read('database/paymongo_provider_payments_20260729.sql');
$pos = $read('staff/pos.php');
$posApi = $read('staff/api/pos_checkout.php');
$webhook = $read('webhooks/paymongo.php');

$passed = 0;
function customer_owned_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$legacyStaffQr = printflow_paymongo_normalize_payment_intent([
    'id' => 'pi_legacy_staff_order11297',
    'attributes' => [
        'amount' => 100,
        'currency' => 'PHP',
        'livemode' => false,
        'status' => 'awaiting_next_action',
        'client_key' => 'pi_legacy_staff_order11297_client_fixture',
        'next_action' => ['code' => ['image_url' => 'data:image/png;base64,iVBORw0KGgo=']],
    ],
], 'test', 200);

customer_owned_check(!str_contains($staff, 'generatePayMongoPayment('), '1. staff online order UI has no PayMongo creation function');
customer_owned_check(!str_contains($staff, 'paymongoPayment.qr_image_url') && !str_contains($staff, 'Open PayMongo Checkout'), '2. staff online order UI renders neither customer QR nor checkout action');
customer_owned_check(str_contains($staff, 'Awaiting Customer Payment') && str_contains($staff, 'Selected by customer:'), '3. staff Step 3 is read-only payment status');
customer_owned_check(str_contains($staffApi, "if (\$channel === 'online')") && str_contains($staffApi, "'code' => 'customer_owned_payment_method'"), '4. staff API rejects online provider creation');
customer_owned_check(str_contains($posApi, 'printflow_provider_payment_create_qrph(') && str_contains($pos, 'payment?.qr_image_url'), '5. POS QR capability remains separate and intact');

customer_owned_check(str_contains($customer, "payment_flow === 'payment_intent' ? 'qrph' : null"), '6. customer starts unselected when no provider ledger exists');
customer_owned_check(str_contains($customer, "'Choose a payment method to continue.'"), '7. unselected customer state gives neutral guidance');
customer_owned_check(substr_count($customer, "paymongoQrButton.addEventListener('click'") === 1 && str_contains($customer, "createPayMongoPayment('create_qrph')"), '8. customer QR selection owns the single QR create action');
customer_owned_check($legacyStaffQr['qr_image_url'] !== '' && str_contains($customer, 'paymongoQrImage.src = payment.qr_image_url'), '9. legacy staff-created PayMongo QR can render inline on the customer page');
customer_owned_check(str_contains($provider, "channel = 'online' AND provider = 'paymongo' AND mode = ?"), '10. customer lookup is restricted to the current online provider ledger');
customer_owned_check(str_contains($provider, '$qrIsUsable') && str_contains($provider, "'reused' => true"), '11. a valid active legacy QR is returned with reuse semantics');
customer_owned_check(str_contains($customerApi, "['create_qrph', 'retry_qrph']") && str_contains($customer, "createPayMongoPayment('retry_qrph')"), '12. expired QR retry is explicit and customer-owned');

$linkListenerAt = strpos($customer, "if (paymongoLinkButton) paymongoLinkButton.addEventListener('click'");
$linkListenerEnd = $linkListenerAt === false ? false : strpos($customer, '});', $linkListenerAt);
$linkListener = $linkListenerAt === false || $linkListenerEnd === false
    ? ''
    : substr($customer, $linkListenerAt, $linkListenerEnd - $linkListenerAt + 3);
customer_owned_check($linkListener !== '' && !str_contains($linkListener, 'createPayMongoPayment('), '13. Secure Checkout card selection creates no provider resource');
customer_owned_check(substr_count($customer, "createPayMongoPayment('create_link')") === 1 && str_contains($customer, 'continueToSecureCheckout'), '14. explicit Continue owns the single Payment Link request');
customer_owned_check(str_contains($provider, 'printflow_provider_payment_supersede_active_flow(') && str_contains($provider, 'printflow_paymongo_cancel_payment_intent('), '15. QR to Link safely cancels the previous intent');
customer_owned_check(str_contains($provider, 'printflow_paymongo_archive_payment_link(') && str_contains($provider, "'superseded' => true"), '16. Link to QR safely archives the previous link');
customer_owned_check(str_contains($schema, 'UNIQUE KEY `uq_provider_payment_subject` (`subject_type`,`subject_id`,`channel`,`provider`,`mode`)'), '17. one subject ledger is reused without changing the production unique index');
customer_owned_check(str_contains($customer, '.paymongo-recommended') && !str_contains($customer, '.paymongo-option.is-selected::after'), '18. Recommended badge cannot overlap a separate Selected badge');
customer_owned_check(str_contains($customer, "selectedPayMongoMethod !== 'qrph'") && str_contains($customer, "paymongoCurrentPayment?.payment_flow !== 'payment_intent'"), '19. only active inline QR state schedules customer polling');
customer_owned_check(str_contains($webhook, "'payment.paid'") && str_contains($webhook, "'payment.failed'") && str_contains($webhook, "'qrph.expired'") && str_contains($webhook, "'link.payment.paid'"), '20. QRPh and Payment Link webhook routing remains intact');

echo "All {$passed} customer-owned PayMongo selection tests passed.\n";
