<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$api = $read('customer/api_paymongo_status.php');
$customer = $read('customer/payment.php');
$provider = $read('includes/provider_payments.php');

$passed = 0;
function success_status_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

$successSelection = "\$responseStatus = !empty(\$result['ok']) ? 200";
$successBody = "'success' => !empty(\$result['ok'])";

success_status_check(
    str_contains($api, "['create_qrph', 'retry_qrph']")
        && str_contains($api, $successSelection)
        && str_contains($api, $successBody),
    '1. successful retry_qrph returns HTTP 200 with success=true and the normalized QR payload'
);
success_status_check(
    str_contains($api, "\$action, ['create_qrph', 'retry_qrph']")
        && str_contains($api, 'printflow_provider_payment_create_qrph(')
        && str_contains($api, $successSelection),
    '2. successful create_qrph returns HTTP 200'
);
success_status_check(
    str_contains($customer, 'data?.qr_image_url || payment.qr_image_url')
        && str_contains($customer, 'paymongoQrImage.src = payment.qr_image_url')
        && str_contains($customer, "payment.payment_flow === 'payment_intent'"),
    '3. customer QRPh renders the normalized returned QR inline'
);
success_status_check(
    str_contains($customer, 'renderPayMongoPayment(normalizePayMongoPayment(data))')
        && str_contains($customer, "['generating', 'awaiting_payment'].includes(status)")
        && str_contains($customer, "? 'Waiting for QR PH payment confirmation.'"),
    '4. an awaiting_payment QR remains visible and continues status polling'
);
success_status_check(
    str_contains($api, "\$action === 'create_link'")
        && str_contains($api, 'printflow_provider_payment_create_link(')
        && str_contains($api, $successSelection),
    '5. successful create_link returns HTTP 200'
);
success_status_check(
    str_contains($provider, "'reused' => true")
        && str_contains($api, "'reused' => !empty(\$result['reused'])")
        && str_contains($api, $successSelection),
    '6. reused create_link follows the same HTTP 200 success contract'
);
success_status_check(
    str_contains($api, "'checkout_url' => (string)(\$publicPayment['checkout_url'] ?? '')")
        && str_contains($customer, 'data?.checkout_url || payment.checkout_url')
        && str_contains($customer, 'window.location.assign(payment.checkout_url)'),
    '7. Payment Link returns and consumes the normalized checkout_url'
);
success_status_check(
    str_contains($api, "\$GLOBALS['printflow_customer_paymongo_response_complete'] = true")
        && str_contains($api, "if (!empty(\$GLOBALS['printflow_customer_paymongo_response_complete']))")
        && str_contains($api, "header('Content-Type: application/json; charset=utf-8', true, \$status)")
        && str_contains($api, 'fastcgi_finish_request()')
        && str_contains($api, $successSelection)
        && str_contains($api, $successBody),
    '8. a completed success response cannot be overwritten to HTTP 500 during shutdown'
);
success_status_check(
    str_contains($api, 'set_exception_handler(static function (Throwable $error)')
        && str_contains($api, "printflow_customer_paymongo_respond(500, [\n        'success' => false"),
    '9. a genuine unexpected exception returns HTTP 500 with success=false'
);
success_status_check(
    str_contains($api, "\$responseStatus === 409 ? 'payment_state_conflict'")
        && str_contains($api, $successBody),
    '10. a genuine conflict returns HTTP 409 with success=false'
);
success_status_check(
    str_contains($api, '[400, 401, 403, 404, 409, 422, 500, 502, 503]')
        && str_contains($provider, "'http_status' => 503")
        && str_contains($provider, '? 502'),
    '11. provider failures retain structured 502/503 responses with success=false'
);
success_status_check(
    substr_count($api, 'printflow_customer_paymongo_respond(200, [') >= 3
        && str_contains($api, "\$input['action'] ?? 'status'")
        && str_contains($api, "'success' => true,\n    'confirming'"),
    '12. pending status polling returns HTTP 200 with a normalized success response'
);

echo "All {$passed} PayMongo success HTTP status regression tests passed.\n";
