<?php
require_once __DIR__ . '/../includes/customer_paid_order_status.php';
require_once __DIR__ . '/../includes/receipt_access.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$receiptOrder = ['order_id' => 28745, 'customer_id' => 25, 'status' => 'Pending', 'payment_status' => 'Unpaid'];
$receiptItem = [['order_item_id' => 9123]];
$verifiedPayMongo = [
    'id' => 701,
    'provider' => 'paymongo',
    'channel' => 'online',
    'subject_type' => 'order',
    'subject_id' => 28745,
    'customer_id' => 25,
    'status' => 'paid',
];
$assert(
    printflow_customer_ready_made_receipt_is_available($receiptOrder, $verifiedPayMongo, $receiptItem),
    'A matching verified PayMongo payment plus a persisted order item enables receipt access.'
);
$receiptOrderMarkedPaid = $receiptOrder;
$receiptOrderMarkedPaid['payment_status'] = 'pAiD';
$assert(
    printflow_customer_ready_made_receipt_is_available($receiptOrderMarkedPaid, [], $receiptItem),
    'A server-marked Paid order plus a persisted order item enables receipt access.'
);
$unpaidOrder = $receiptOrder;
$unpaidOrder['status'] = 'Awaiting Payment';
$assert(
    !printflow_customer_ready_made_receipt_is_available($unpaidOrder, ['status' => 'awaiting_payment'], $receiptItem),
    'Awaiting or unpaid orders do not receive receipts.'
);
foreach (['Cancelled', 'Expired', 'Failed', 'Rejected', 'Declined', 'Abandoned'] as $blockedStatus) {
    $blockedOrder = $receiptOrderMarkedPaid;
    $blockedOrder['status'] = $blockedStatus;
    $assert(
        !printflow_customer_ready_made_receipt_is_available($blockedOrder, $verifiedPayMongo, $receiptItem),
        "{$blockedStatus} orders remain ineligible even when stale paid metadata exists."
    );
}
$failedPaymentState = $receiptOrder;
$failedPaymentState['payment_status'] = 'Failed';
$assert(
    !printflow_customer_ready_made_receipt_is_available($failedPaymentState, $verifiedPayMongo, $receiptItem),
    'A failed payment-status value blocks a receipt even when conflicting paid metadata exists.'
);
$assert(
    !printflow_customer_ready_made_receipt_is_available($receiptOrderMarkedPaid, [], []),
    'A paid order without a persisted order item does not receive a receipt.'
);
$mismatchedPayMongo = $verifiedPayMongo;
$mismatchedPayMongo['subject_id'] = 28744;
$assert(
    !printflow_customer_ready_made_receipt_is_available($receiptOrder, $mismatchedPayMongo, $receiptItem),
    'A provider payment for a different order does not authorize this receipt.'
);
$mismatchedCustomerPayment = $verifiedPayMongo;
$mismatchedCustomerPayment['customer_id'] = 26;
$assert(
    !printflow_customer_ready_made_receipt_is_available($receiptOrder, $mismatchedCustomerPayment, $receiptItem),
    'A provider payment belonging to another customer does not authorize this receipt.'
);

$paidServiceOrder = ['order_id' => 28906, 'customer_id' => 25, 'status' => 'Pending', 'payment_status' => 'Paid'];
$assert(
    printflow_customer_service_paid_receipt_is_available($paidServiceOrder, $verifiedPayMongo, $receiptItem, true),
    'A paid service order awaiting production exposes the same payment-verified receipt as ready-made products.'
);
$assert(
    !printflow_customer_service_paid_receipt_is_available($paidServiceOrder, $verifiedPayMongo, $receiptItem, false),
    'Unpaid service orders do not expose a receipt from the paid-service path.'
);
$assert(
    !printflow_customer_service_paid_receipt_is_available($unpaidOrder, $verifiedPayMongo, $receiptItem, true),
    'Service orders without confirmed payment remain ineligible even when the helper is invoked.'
);

$assert(
    printflow_customer_paid_order_waiting_label('Pending', true, true) === 'Paid',
    'paid ready-made product displays only Paid'
);
$assert(
    printflow_customer_paid_order_waiting_label('Pending', true, false) === 'Payment Received / Awaiting Production',
    'paid customizable service or mixed order retains production wording'
);
foreach (['In Production', 'Ready for Pickup', 'Completed', 'Cancelled', 'Rejected'] as $status) {
    $assert(
        printflow_customer_paid_order_waiting_label($status, true, true) === '',
        "existing downstream status {$status} is preserved"
    );
}
$assert(
    printflow_customer_paid_order_waiting_label('Pending', false, true) === '',
    'unpaid order receives no paid status label'
);

$root = dirname(__DIR__);
$payment = (string)file_get_contents($root . '/customer/payment.php');
$orders = (string)file_get_contents($root . '/customer/orders.php');
$itemsApi = (string)file_get_contents($root . '/customer/get_order_items.php');
$payloadBuilder = substr($itemsApi, strpos($itemsApi, 'function customer_receipt_build_payload'), 6500);

$assert(str_contains($payment, 'id="payment-download-receipt"') && str_contains($payment, 'onclick="downloadPaymentReceipt()"'), 'payment success uses a button that does not navigate');
$assert(substr_count($payment, 'id="payment-download-receipt"') === 2, 'both provider-confirmed and server-marked paid ready-made states expose the same receipt action');
$assert(str_contains($payment, '$is_ready_made_product && $is_paid_ui') && str_contains($payment, "? 'Paid'"), 'ready-made payment UI uses a concise Paid label instead of fulfillment wording');
$assert(str_contains($payment, 'const paidStatusLabel = <?php echo json_encode($paid_status_label'), 'the dynamic payment-success panel receives the customer-safe status label');
$assert(str_contains($payment, 'orders.php?receipt_order_id=${encodeURIComponent(receiptOrderId)}&receipt_download=1'), 'receipt PDF is requested in a hidden iframe');
$assert(str_contains($orders, "params.get('receipt_download') === '1'") && str_contains($orders, 'downloadReceiptPdf()'), 'existing receipt modal template and PDF generator run in download mode');
$assert(str_contains($orders, "window.parent.postMessage({ type: 'printflow-receipt-download', success: true }") && str_contains($payment, "event.source !== receiptDownloadFrame?.contentWindow"), 'download result is reported to the payment page from its own same-origin frame');
$assert(str_contains($itemsApi, 'WHERE o.order_id = ? AND o.customer_id = ?') && str_contains($itemsApi, "'ii', [\$order_id, \$customer_id]"), 'receipt data API scopes order access to the authenticated customer');
$assert(str_contains($itemsApi, 'printflow_customer_ready_made_receipt_is_available($order, $provider_payment, $items)'), 'ready-made receipt uses shared payment, item, and failure-status eligibility');
$assert(str_contains($itemsApi, 'printflow_customer_service_paid_receipt_is_available'), 'paid service orders reuse the shared payment-verified receipt helper');
$assert(str_contains($itemsApi, '$is_ready_made_product = printflow_is_ready_made_product_order($order);'), 'receipt API uses the same ready-made classifier as the payment page without an extra service-inference veto');
$assert(str_contains($itemsApi, '$awaiting_fulfillment = false;'), 'customer order details no longer emit an awaiting-fulfillment flag for paid ready-made orders');
foreach (['order_number', 'quantity', 'unit_price', 'line_total', 'payment', 'method', 'status', 'amount_paid', 'reference'] as $field) {
    $assert(str_contains($payloadBuilder, "'{$field}'"), "receipt payload includes {$field}");
}
$assert(str_contains($itemsApi, "'receipt'          => \$receipt_payload") && str_contains($itemsApi, '$is_ready_made_product'), 'receipt payload is available only through the existing authorized order details API');
$assert(str_contains($orders, "data.awaiting_fulfillment ? 'Paid'") && str_contains($orders, "data.awaiting_production ? 'Payment Received / Awaiting Production'") && !str_contains($orders, 'Paid / Awaiting Fulfillment'), 'order details payment information uses Paid for ready-made orders while retaining service production wording');

if ($failures !== []) {
    fwrite(STDERR, "Customer paid-order receipt regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Customer paid-order receipt regression test passed.\n";
