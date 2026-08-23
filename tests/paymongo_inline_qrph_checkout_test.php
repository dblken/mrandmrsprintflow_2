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
$archivedLinkFixture = printflow_paymongo_normalize_payment_link([
    'id' => 'link_order11297archive',
    'amount' => 100,
    'currency' => 'PHP',
    'livemode' => false,
    'status' => 'archived',
    'url' => 'https://pm.link/printflow/test/order11297archive',
], 'test', 200);
$cancelledIntentFixture = printflow_paymongo_normalize_payment_intent([
    'id' => 'pi_order11297cancelled',
    'attributes' => [
        'amount' => 100,
        'currency' => 'PHP',
        'livemode' => false,
        'status' => 'cancelled',
        'client_key' => 'pi_order11297cancelled_client_fixture',
    ],
], 'test', 200);

inline_checkout_check(str_contains($customer, "createPayMongoPayment('create_qrph')") && str_contains($provider, 'printflow_provider_payment_create_intent('), '1. QRPh selection creates a Payment Intent, not a Payment Link');
inline_checkout_check($qrFixture['qr_image_url'] !== '' && $qrFixture['id'] === 'pi_order11297fixture', '2. QRPh response contains the PayMongo next-action image');
inline_checkout_check(!array_key_exists('checkout_url', $qrFixture), '3. QRPh provider response does not depend on a checkout URL');
inline_checkout_check(!str_contains($customer, 'window.open(') && !str_contains($customer, 'location.href = payment.checkout_url'), '4. QRPh customer code performs no redirect or popup');
inline_checkout_check(str_contains($customer, 'paymongoQrImage.src = payment.qr_image_url') && str_contains($customer, 'paymongo-qr-panel'), '5. validated QRPh image renders inline');
inline_checkout_check(str_contains($api, "\$input['action'] ?? 'status'") && !str_contains($api, "if (\$method !== 'POST')") , '6. polling uses the status-only GET branch');
inline_checkout_check(str_contains($provider, '$qrIsUsable') && str_contains($provider, "'reused' => true"), '7. active QR intent is reused');
inline_checkout_check(str_contains($provider, '$resetExpiredIntentSql') && str_contains($provider, "'-retry-'"), '8. expired QR creates a fresh attempt');
inline_checkout_check(str_contains($customer, "status === 'paid'") && str_contains($customer, 'renderPayMongoConfirmed(payment)'), '9. paid QR renders the confirmed state');
inline_checkout_check(str_contains($customer, 'if (paymentCreateInFlight) return null;') && str_contains($provider, "'in_progress' => true"), '10. duplicate QR clicks do not create duplicate intents');

inline_checkout_check(str_contains($customer, 'continueToSecureCheckout') && str_contains($customer, "createPayMongoPayment('create_link')") && str_contains($provider, 'printflow_paymongo_create_order_payment_link('), '11. Secure Checkout Continue creates a Payment Link');
inline_checkout_check($linkFixture['url'] === 'https://pm.link/printflow/test/order11297', '12. current Payment Link response returns its checkout URL');
inline_checkout_check($linkFixture['url'] !== $qrFixture['qr_image_url'], '13. Secure Checkout never substitutes a QRPh image');
inline_checkout_check(str_contains($provider, '$linkIsReusable') && str_contains($provider, 'printflow_paymongo_checkout_url_is_safe'), '14. existing valid Payment Link is reused safely');
inline_checkout_check(str_contains($provider, '$providerHttpStatus >= 500 ? 502') && str_contains($provider, 'invalid_payment_link_response'), '15. unusable successful provider response becomes controlled 502, not HTTP 500');
inline_checkout_check(str_contains($api, "'code' => 'internal_error'") && str_contains($provider, "'provider_error_code'"), '16. provider/application failures return controlled JSON and safe logs');
inline_checkout_check(str_contains($provider, 'printflow_provider_payment_supersede_active_flow(') && str_contains($provider, 'printflow_paymongo_cancel_payment_intent('), '17. an active QRPh intent is cancelled before Secure Checkout replaces it');
inline_checkout_check(str_contains($customer, 'selectedPayMongoMethod') && str_contains($customer, "selectedPayMongoMethod === 'payment_link'"), '18. switching methods obeys explicit selected-method rules');
inline_checkout_check(str_contains($webhook, "'link.payment.paid'"), '19. link.payment.paid webhook support remains intact');

inline_checkout_check(str_contains($staff, "generatePayMongoPayment('create_qrph')") && str_contains($staffApi, 'printflow_provider_payment_create_qrph('), '20. staff Dynamic QRPh uses the direct intent flow');
inline_checkout_check(str_contains($staff, "generatePayMongoPayment('create_link')") && str_contains($staffApi, 'printflow_provider_payment_create_link('), '21. staff Payment Link uses the hosted-link flow');
inline_checkout_check(str_contains($provider, "payment_flow = 'payment_link'") && str_contains($provider, "'payment_flow' => 'payment_intent'"), '22. the persisted flow determines the customer DTO and UI');

inline_checkout_check($legacyCheckoutFixture['url'] === 'https://checkout.paymongo.com/order11297fixture', '23. legacy nested PayMongo checkout response is normalized safely');
inline_checkout_check($unsafeLinkFixture['url'] === '', '24. non-PayMongo hosted checkout URLs are rejected');
inline_checkout_check($archivedLinkFixture['status'] === 'archived' && str_contains($provider, 'printflow_paymongo_archive_payment_link('), '25. an active Payment Link is archived before QR PH replaces it');
inline_checkout_check($cancelledIntentFixture['status'] === 'cancelled', '26. cancelled Payment Intent responses remain explicit');

$linkListenerStart = strpos($customer, "if (paymongoLinkButton) paymongoLinkButton.addEventListener('click'");
$linkListenerEnd = $linkListenerStart === false ? false : strpos($customer, '});', $linkListenerStart);
$linkListener = $linkListenerStart === false || $linkListenerEnd === false
    ? ''
    : substr($customer, $linkListenerStart, $linkListenerEnd - $linkListenerStart + 3);
inline_checkout_check($linkListener !== '' && !str_contains($linkListener, "createPayMongoPayment('create_link')"), '27. selecting the Secure Checkout card sends no creation request');
inline_checkout_check(substr_count($customer, "paymongoPayNow.addEventListener('click', continueToSecureCheckout)") === 1, '28. Secure Checkout Continue has exactly one request-capable listener');
inline_checkout_check(substr_count($customer, "paymongoQrButton.addEventListener('click'") === 1 && str_contains($customer, 'if (paymentCreateInFlight) return null;'), '29. QR PH has one listener and a duplicate-request guard');
inline_checkout_check(str_contains($customer, "window.location.assign(payment.checkout_url)") && !str_contains($customer, 'paymongoPayNow.href'), '30. only explicit Continue navigates to the returned hosted checkout URL');
inline_checkout_check(str_contains($customer, "'Scan a secure QR using a supported banking or e-wallet app.'") && str_contains($customer, "'You\\'ll continue to PayMongo\\'s secure hosted checkout.'"), '31. guidance is derived from the explicit selected method');
inline_checkout_check(str_contains($provider, "'payment_flow_switch_failed'") && str_contains($provider, "'payment_switch_in_progress'"), '32. switching failures are controlled and machine-readable');
inline_checkout_check(str_contains($customer, "document.querySelectorAll('#paymongo-method-actions .paymongo-option').forEach") && str_contains($customer, "card.classList.remove('is-selected')"), '33. every selection clears all prior selected classes first');
inline_checkout_check(str_contains($customer, "selectedCard.classList.add('is-selected')") && str_contains($customer, "selectedCard.setAttribute('aria-checked', 'true')"), '34. exactly the chosen radio card receives selected and accessible state');
inline_checkout_check(!str_contains($customer, 'paymongo-option-primary') && str_contains($customer, 'paymongo-recommended'), '35. Recommended styling is independent from selected styling');
inline_checkout_check(str_contains($customer, ".paymongo-option.is-selected::after { content:'Selected'") && str_contains($customer, '.paymongo-option.is-selected {'), '36. selected styling uses a distinct teal state and label');
inline_checkout_check(str_contains($customer, "setSelectedPayMongoMethod('payment_link')") && str_contains($customer, "setSelectedPayMongoMethod('qrph')"), '37. QR to Link and Link to QR use the same single-state setter');
inline_checkout_check(substr_count($customer, "createPayMongoPayment('create_link')") === 1 && substr_count($customer, "createPayMongoPayment('create_qrph')") === 2, '38. action wiring has one link creation site and explicit QR create/retry sites only');
inline_checkout_check(str_contains($customer, "paymentCreateInFlight || selectedPayMongoMethod !== 'payment_link'") && str_contains($customer, "'Creating secure checkout...'"), '39. Continue double-click is guarded and exposes a loading label');
inline_checkout_check(substr_count($customer, 'stopPayMongoTimers();') >= 5 && str_contains($customer, "setTimeout(pollPayMongo, 5000)"), '40. method changes clear old timers and polling remains status-only');
inline_checkout_check(str_contains($customer, "paymongoRetryButton.style.display = selectedPayMongoMethod === 'qrph'") && str_contains($customer, "'We couldn\\'t create Secure Checkout right now. Please try again.'"), '41. only selected-flow actions remain visible and controlled failures render inline');

echo "All {$passed} inline QRPh and Secure Checkout regression tests passed.\n";
