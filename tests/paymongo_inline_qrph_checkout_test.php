<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo.php';

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$customer = $read('customer/payment.php');
$api = $read('customer/api_paymongo_status.php');
$provider = $read('includes/provider_payments.php');
$staff = $read('staff/customizations.php');
$staffApi = $read('staff/api/paymongo_payment.php');
$pos = $read('staff/pos.php');
$posApi = $read('staff/api/pos_checkout.php');
$webhook = $read('webhooks/paymongo.php');

$passed = 0;
function inline_checkout_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$qrFixture = printflow_paymongo_normalize_payment_intent([
    'id' => 'pi_order11297fixture',
    'attributes' => [
        'amount' => 100,
        'currency' => 'PHP',
        'livemode' => false,
        'status' => 'awaiting_next_action',
        'client_key' => 'pi_order11297fixture_client_fixture',
        'next_action' => ['code' => ['image_url' => 'data:image/png;base64,iVBORw0KGgo=']],
    ],
], 'test', 200);
$linkFixture = printflow_paymongo_normalize_payment_link([
    'id' => 'link_order11297fixture',
    'amount' => 100,
    'currency' => 'PHP',
    'livemode' => false,
    'status' => 'active',
    'url' => 'https://pm.link/printflow/test/order11297',
    'reference_number' => 'order11297',
], 'test', 200);
$legacyCheckoutFixture = printflow_paymongo_normalize_payment_link([
    'id' => 'link_order11297legacy',
    'attributes' => [
        'amount' => 100,
        'currency' => 'PHP',
        'livemode' => true,
        'status' => 'unpaid',
        'checkout_url' => 'https://checkout.paymongo.com/order11297fixture',
    ],
], 'live', 200);
$unsafeLinkFixture = printflow_paymongo_normalize_payment_link([
    'id' => 'link_order11297unsafe',
    'amount' => 100,
    'currency' => 'PHP',
    'livemode' => true,
    'status' => 'active',
    'url' => 'https://example.invalid/order11297',
], 'live', 200);

inline_checkout_check(!str_contains($customer, 'SECURE CHECKOUT') && !str_contains($customer, 'Secure Checkout'), '1. customer page no longer contains Secure Checkout copy');
inline_checkout_check(!str_contains($customer, 'Continue to Secure Checkout') && !str_contains($customer, 'paymongo-create-link'), '2. customer page has no hosted-checkout card or button');
inline_checkout_check(!str_contains($customer, "createPayMongoPayment('create_link')") && !str_contains($customer, 'continueToSecureCheckout'), '3. customer page does not call create_link');
inline_checkout_check(!str_contains($customer, 'selectedPayMongoMethod') && !str_contains($customer, 'paymongo-method-actions'), '4. customer page has no payment-method selector state');
inline_checkout_check(str_contains($customer, 'paymongo-method-summary') && str_contains($customer, '<strong>QR PH</strong>'), '5. QR PH is the only visible PayMongo method');
inline_checkout_check(str_contains($customer, "return 'create_qrph';") && str_contains($api, 'printflow_provider_payment_create_qrph(') && str_contains($provider, 'printflow_provider_payment_create_intent('), '6. customer QR can create a Payment Intent');
inline_checkout_check(str_contains($customer, "'retry_qrph'") && str_contains($provider, '$resetExpiredIntentSql'), '7. expired or failed QR can be retried');
inline_checkout_check(str_contains($provider, '$qrIsUsable') && str_contains($provider, "'reused' => true"), '8. existing valid QR is reused by the backend');
inline_checkout_check($qrFixture['qr_image_url'] !== '' && $qrFixture['id'] === 'pi_order11297fixture', '9. QRPh provider response contains an inline image');
inline_checkout_check(str_contains($customer, 'paymongoQrImage.src = payment.qr_image_url') && str_contains($customer, 'paymongo-qr-panel'), '10. QR displays inline on the customer page');
inline_checkout_check(str_contains($customer, 'startQrCountdown') && str_contains($customer, 'QR expires in ${minutes}:${seconds}'), '11. QR countdown remains active');
inline_checkout_check(str_contains($customer, 'schedulePayMongoPoll') && str_contains($customer, "setTimeout(pollPayMongo, 5000)"), '12. pending polling remains active');
inline_checkout_check(str_contains($customer, "status === 'paid'") && str_contains($customer, 'renderPayMongoConfirmed(payment)'), '13. payment.paid still renders the paid state');
inline_checkout_check(str_contains($customer, "status === 'expired'") && str_contains($customer, 'QR code expired.'), '14. qrph.expired still renders retry state');
inline_checkout_check(str_contains($customer, 'chooseInitialQrAction') && str_contains($customer, "return 'create_qrph';"), '15. page load creates QR when no QR attempt exists');
inline_checkout_check(str_contains($customer, "status === 'awaiting_payment' && payment.qr_image_url") && str_contains($customer, "return '';"), '16. page load reuses an existing valid QR without forcing retry');
inline_checkout_check(str_contains($customer, "['failed', 'expired', 'cancelled'].includes(status)) return 'retry_qrph';"), '17. page load uses retry only for failed/expired/cancelled QR intents');
inline_checkout_check(!str_contains($customer, 'window.open(') && !str_contains($customer, 'window.location.assign('), '18. customer QR flow performs no redirect or popup');
inline_checkout_check(str_contains($api, "\$action === 'create_link'") && str_contains($provider, 'function printflow_provider_payment_create_link('), '19. Payment Link backend code remains intact');
inline_checkout_check($linkFixture['url'] === 'https://pm.link/printflow/test/order11297', '20. Payment Link normalization remains intact');
inline_checkout_check($legacyCheckoutFixture['url'] === 'https://checkout.paymongo.com/order11297fixture', '21. historical nested Payment Link URLs still normalize');
inline_checkout_check($unsafeLinkFixture['url'] === '', '22. unsafe Payment Link URLs remain rejected');
inline_checkout_check(str_contains($provider, 'printflow_paymongo_archive_payment_link(') && str_contains($provider, "'superseded' => true"), '23. switching historical link ledgers to QR remains safe');
inline_checkout_check(str_contains($webhook, "'link.payment.paid'"), '24. historical link.payment.paid webhook support remains intact');
inline_checkout_check(str_contains($webhook, "'payment.paid'") && str_contains($webhook, "'qrph.expired'"), '25. QR payment webhooks remain intact');
inline_checkout_check(!str_contains($staff, 'generatePayMongoPayment(') && !str_contains($staff, 'paymongoPayment.qr_image_url') && str_contains($staffApi, "'code' => 'customer_owned_payment_method'"), '26. staff online order flow remains read-only');
inline_checkout_check(str_contains($posApi, 'printflow_provider_payment_create_qrph(') && str_contains($pos, 'payment?.qr_image_url'), '27. POS PayMongo QR flow remains unchanged');
inline_checkout_check(str_contains($provider, "payment_flow = 'payment_link'") && str_contains($provider, "'payment_flow' => 'payment_intent'"), '28. persisted payment flow support remains intact');
inline_checkout_check(!str_contains($customer, '.paymongo-option') && !str_contains($customer, '.paymongo-recommended'), '29. old selector and badge styling are removed from customer UI');
inline_checkout_check(!str_contains($customer, 'CREATE TABLE') && !str_contains($customer, 'ALTER TABLE'), '30. customer UI change does not introduce database migration code');

echo "All {$passed} inline QRPh customer-only regression tests passed.\n";
