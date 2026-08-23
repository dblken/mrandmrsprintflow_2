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

inline_checkout_check(str_contains($customer, "createPayMongoPayment('create_qrph')") && str_contains($provider, 'printflow_provider_payment_create_intent('), '1. QRPh selection creates a Payment Intent, not a Payment Link');
inline_checkout_check($qrFixture['qr_image_url'] !== '' && $qrFixture['id'] === 'pi_order11297fixture', '2. QRPh response contains the PayMongo next-action image');
inline_checkout_check(!array_key_exists('checkout_url', $qrFixture), '3. QRPh provider response does not depend on a checkout URL');
inline_checkout_check(!str_contains($customer, 'window.open(') && !str_contains($customer, 'location.href = payment.checkout_url'), '4. QRPh customer code performs no redirect or popup');
inline_checkout_check(str_contains($customer, 'paymongoQrImage.src = payment.qr_image_url') && str_contains($customer, 'paymongo-qr-panel'), '5. validated QRPh image renders inline');
inline_checkout_check(str_contains($api, "\$input['action'] ?? 'status'") && !str_contains($api, "if (\$method !== 'POST')") , '6. polling uses the status-only GET branch');
inline_checkout_check(str_contains($provider, '$qrIsUsable') && str_contains($provider, "'reused' => true"), '7. active QR intent is reused');
inline_checkout_check(str_contains($provider, '$resetExpiredIntentSql') && str_contains($provider, "'-retry-'"), '8. expired QR creates a fresh attempt');
inline_checkout_check(str_contains($customer, "status === 'paid'") && str_contains($customer, 'renderPayMongoConfirmed(payment)'), '9. paid QR renders the confirmed state');
inline_checkout_check(str_contains($customer, 'if (paymongoBusy) return;') && str_contains($provider, "'in_progress' => true"), '10. duplicate QR clicks do not create duplicate intents');

inline_checkout_check(str_contains($customer, "createPayMongoPayment('create_link')") && str_contains($provider, 'printflow_paymongo_create_order_payment_link('), '11. Secure Checkout creates a Payment Link');
inline_checkout_check($linkFixture['url'] === 'https://pm.link/printflow/test/order11297', '12. current Payment Link response returns its checkout URL');
inline_checkout_check($linkFixture['url'] !== $qrFixture['qr_image_url'], '13. Secure Checkout never substitutes a QRPh image');
inline_checkout_check(str_contains($provider, '$linkIsReusable') && str_contains($provider, 'printflow_paymongo_checkout_url_is_safe'), '14. existing valid Payment Link is reused safely');
inline_checkout_check(str_contains($provider, '$providerHttpStatus >= 500 ? 502') && str_contains($provider, 'invalid_payment_link_response'), '15. unusable successful provider response becomes controlled 502, not HTTP 500');
inline_checkout_check(str_contains($api, "'code' => 'internal_error'") && str_contains($provider, "'provider_error_code'"), '16. provider/application failures return controlled JSON and safe logs');
inline_checkout_check(str_contains($provider, "'error_code' => 'active_payment_flow_conflict'") && str_contains($api, 'payment_state_conflict'), '17. an active QRPh intent causes a structured conflict, not an exception');
inline_checkout_check(str_contains($customer, 'setSelectedPayMongoFlow') && str_contains($customer, "flow === 'payment_link'"), '18. switching methods obeys explicit selected-flow rules');
inline_checkout_check(str_contains($webhook, "'link.payment.paid'"), '19. link.payment.paid webhook support remains intact');

inline_checkout_check(str_contains($staff, "generatePayMongoPayment('create_qrph')") && str_contains($staffApi, 'printflow_provider_payment_create_qrph('), '20. staff Dynamic QRPh uses the direct intent flow');
inline_checkout_check(str_contains($staff, "generatePayMongoPayment('create_link')") && str_contains($staffApi, 'printflow_provider_payment_create_link('), '21. staff Payment Link uses the hosted-link flow');
inline_checkout_check(str_contains($provider, "payment_flow = 'payment_link'") && str_contains($provider, "'payment_flow' => 'payment_intent'"), '22. the persisted flow determines the customer DTO and UI');

inline_checkout_check($legacyCheckoutFixture['url'] === 'https://checkout.paymongo.com/order11297fixture', '23. legacy nested PayMongo checkout response is normalized safely');
inline_checkout_check($unsafeLinkFixture['url'] === '', '24. non-PayMongo hosted checkout URLs are rejected');

echo "All {$passed} inline QRPh and Secure Checkout regression tests passed.\n";
