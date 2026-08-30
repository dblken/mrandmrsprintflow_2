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

customer_owned_check(str_contains($customer, 'chooseInitialQrAction') && str_contains($customer, "return 'create_qrph';"), '6. customer auto-starts QR when no provider ledger exists');
customer_owned_check(str_contains($customer, "'Preparing your QR Ph payment...'"), '7. customer state gives QR-first guidance');
customer_owned_check(str_contains($customer, '<strong>QR PH</strong>') && !str_contains($customer, 'paymongo-create-link'), '8. customer QR flow owns the single visible online payment path');
customer_owned_check($legacyStaffQr['qr_image_url'] !== '' && str_contains($customer, 'paymongoQrImage.src = payment.qr_image_url'), '9. legacy staff-created PayMongo QR can render inline on the customer page');
customer_owned_check(str_contains($provider, "channel = 'online' AND provider = 'paymongo' AND mode = ?"), '10. customer lookup is restricted to the current online provider ledger');
customer_owned_check(str_contains($provider, '$qrIsUsable') && str_contains($provider, "'reused' => true"), '11. a valid active legacy QR is returned with reuse semantics');
customer_owned_check(str_contains($customerApi, "['create_qrph', 'retry_qrph']") && str_contains($customer, "'retry_qrph'"), '12. expired QR retry is explicit and customer-owned');

customer_owned_check(!str_contains($customer, 'Secure Checkout') && !str_contains($customer, "createPayMongoPayment('create_link')"), '13. Secure Checkout is removed from customer UI');
customer_owned_check(str_contains($customerApi, "\$action === 'create_link'") && str_contains($provider, 'function printflow_provider_payment_create_link('), '14. Payment Link backend remains available outside the customer UI');
customer_owned_check(str_contains($provider, 'printflow_provider_payment_supersede_active_flow(') && str_contains($provider, 'printflow_paymongo_cancel_payment_intent('), '15. backend can still safely close a previous intent when link flow is used elsewhere');
customer_owned_check(str_contains($provider, 'printflow_paymongo_archive_payment_link(') && str_contains($provider, "'superseded' => true"), '16. Link to QR safely archives the previous link');
customer_owned_check(str_contains($schema, 'UNIQUE KEY `uq_provider_payment_subject` (`subject_type`,`subject_id`,`channel`,`provider`,`mode`)'), '17. one subject ledger is reused without changing the production unique index');
customer_owned_check(!str_contains($customer, '.paymongo-recommended') && !str_contains($customer, '.paymongo-option.is-selected::after'), '18. Recommended and Selected badge styling is removed');
customer_owned_check(str_contains($customer, "paymongoCurrentPayment?.payment_flow !== 'payment_intent'") && !str_contains($customer, 'selectedPayMongoMethod'), '19. only active inline QR state schedules customer polling');
customer_owned_check(str_contains($webhook, "'payment.paid'") && str_contains($webhook, "'payment.failed'") && str_contains($webhook, "'qrph.expired'") && str_contains($webhook, "'link.payment.paid'"), '20. QRPh and Payment Link webhook routing remains intact');

echo "All {$passed} customer-owned PayMongo selection tests passed.\n";
