<?php
require_once __DIR__ . '/../includes/customer_paid_order_status.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert(
    printflow_customer_paid_order_waiting_label('Pending', true, true) === 'Paid / Awaiting Fulfillment',
    'paid ready-made product gets fulfillment wording'
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
$assert(str_contains($payment, 'orders.php?receipt_order_id=${encodeURIComponent(receiptOrderId)}&receipt_download=1'), 'receipt PDF is requested in a hidden iframe');
$assert(str_contains($orders, "params.get('receipt_download') === '1'") && str_contains($orders, 'downloadReceiptPdf()'), 'existing receipt modal template and PDF generator run in download mode');
$assert(str_contains($orders, "window.parent.postMessage({ type: 'printflow-receipt-download', success: true }") && str_contains($payment, "event.source !== receiptDownloadFrame?.contentWindow"), 'download result is reported to the payment page from its own same-origin frame');
$assert(str_contains($itemsApi, 'WHERE o.order_id = ? AND o.customer_id = ?') && str_contains($itemsApi, "'ii', [\$order_id, \$customer_id]"), 'receipt data API scopes order access to the authenticated customer');
foreach (['order_number', 'quantity', 'unit_price', 'line_total', 'payment', 'method', 'status', 'amount_paid', 'reference'] as $field) {
    $assert(str_contains($payloadBuilder, "'{$field}'"), "receipt payload includes {$field}");
}
$assert(str_contains($itemsApi, "'receipt'          => \$receipt_payload") && str_contains($itemsApi, '$is_ready_made_product'), 'receipt payload is available only through the existing authorized order details API');
$assert(str_contains($orders, "data.awaiting_fulfillment ? 'Paid / Awaiting Fulfillment'") && str_contains($orders, "data.awaiting_production ? 'Payment Received / Awaiting Production'"), 'order details payment information uses type-aware labels');

if ($failures !== []) {
    fwrite(STDERR, "Customer paid-order receipt regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Customer paid-order receipt regression test passed.\n";
