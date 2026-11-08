<?php

$root = dirname(__DIR__);
$paymentPage = (string)file_get_contents($root . '/customer/payment.php');
$ordersPage = (string)file_get_contents($root . '/staff/orders.php');
$statusEndpoint = (string)file_get_contents($root . '/staff/update_order_status_process.php');
$functions = (string)file_get_contents($root . '/includes/functions.php');
$orderUi = (string)file_get_contents($root . '/includes/order_ui_helper.php');
$orderItemsApi = (string)file_get_contents($root . '/customer/get_order_items.php');
$staffCancel = (string)file_get_contents($root . '/staff/cancel_order_process.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($paymentPage, "'Payment Successful'") !== false, 'Paid ready-made orders must render Payment Successful.');
$assert(strpos((string)file_get_contents($root . '/includes/customer_paid_order_status.php'), 'Payment Received / Awaiting Production') !== false, 'Service payment status copy must remain available.');
$assert(strpos($paymentPage, "'Your payment has been received successfully.") !== false, 'Paid ready-made orders must receive claim guidance.');
$assert(strpos($ordersPage, "'TO_PICK_UP': 'TO PICK UP'") === false, 'Ready-made staff tabs must not expose To Pick Up.');
$assert(strpos($ordersPage, "'CANCELLED': 'CANCELLED'") === false, 'Ready-made staff tabs must not expose Cancelled.');
$assert(strpos($ordersPage, "'AWAITING_PAYMENT'") !== false && strpos($ordersPage, "'PAID'") !== false, 'Ready-made staff cards must separate awaiting payment from paid claims.');
$assert(strpos($ordersPage, "providerStatus === 'paid'") !== false, 'Completion UI must require a paid provider ledger status.');
$assert(strpos($statusEndpoint, "printflow_provider_payment_find('order', \$orderId, 'online', printflow_paymongo_mode())") !== false, 'Completion must consult the PayMongo ledger server-side.');
$assert(strpos($statusEndpoint, 'PayMongo has not verified this payment as paid.') !== false, 'Unverified provider payments must be rejected server-side.');
$assert(strpos($functions, 'function printflow_is_ready_made_product_order') !== false, 'Ready-made product classification must be centralized.');
$readyMadeStart = strpos($functions, 'function printflow_is_ready_made_product_order');
$readyMadeEnd = strpos($functions, 'function can_customer_cancel_order', $readyMadeStart !== false ? $readyMadeStart : 0);
$readyMadeSource = ($readyMadeStart !== false && $readyMadeEnd !== false) ? substr($functions, $readyMadeStart, $readyMadeEnd - $readyMadeStart) : '';
$assert($readyMadeSource !== '' && strpos($readyMadeSource, "['product', 'catalog_product']") !== false, 'Persisted catalog item type is considered when classifying ready-made orders.');
$assert($readyMadeSource !== '' && strpos($readyMadeSource, 'if ($verifiedCatalogProductSource)') < strpos($readyMadeSource, 'printflow_order_item_has_service_marker'), 'Verified Products-page lines are not reclassified by legacy service_type customization metadata.');
$assert($readyMadeSource !== '' && strpos($readyMadeSource, '!$allLinesExplicitlyCatalogProducts') !== false, 'Explicit product line identity is not overturned by the legacy reference_id service heuristic.');
$assert(strpos($orderUi, "['product', 'catalog_product']") !== false && strpos($paymentPage, 'catalog-placeholder.svg') !== false, 'Payment product rendering honors persisted type and uses an existing catalog image fallback.');
$assert(is_file($root . '/public/assets/images/services/catalog-placeholder.svg'), 'The configured catalog image fallback exists in the release tree.');
$assert(strpos($paymentPage, '$item[\'type\'] = $looksServiceLike ? \'Service\' : \'Product\';') !== false, 'Payment item renderer receives the resolved line identity explicitly.');
$assert(strpos($orderItemsApi, '$first_item_explicit_product') !== false && strpos($orderItemsApi, "catalog_product'], true)") !== false, 'Receipt/order details API preserves explicit product lines despite legacy service labels.');
$assert(strpos($paymentPage, "? 'Paid'") !== false && strpos($paymentPage, 'Your payment is confirmed. Please present your order reference') !== false, 'Ready-made paid status and fallback payment copy do not say Awaiting Production.');
$assert(strpos($paymentPage, 'if ($can_download_payment_receipt): ?><button type="button" id="payment-download-receipt"') !== false, 'Paid ready-made orders with a persisted order item keep the existing Download Receipt action.');
$assert(strpos($functions, 'if (printflow_is_ready_made_product_order($order)) return false;') !== false, 'Customer cancellation must be disabled for ready-made products.');
$assert(strpos($staffCancel, 'printflow_is_ready_made_product_order($orderRows[0])') !== false, 'Staff cancellation must be disabled for ready-made products.');

if ($failures !== []) {
    fwrite(STDERR, "Ready-made product workflow regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Ready-made product workflow regression test passed.\n";
