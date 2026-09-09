<?php

$root = dirname(__DIR__);
$payment = (string)file_get_contents($root . '/customer/payment.php');
$itemsApi = (string)file_get_contents($root . '/customer/get_order_items.php');
$orders = (string)file_get_contents($root . '/customer/orders.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$printer = (string)file_get_contents($root . '/includes/pos_receipt_printer.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert(strpos($payment, 'receipt_order_id=') !== false && strpos($payment, 'Download Receipt') !== false, 'Paid ready-made payment confirmation links to the existing receipt download flow.');
$assert(strpos($itemsApi, '$is_ready_made_product') !== false && strpos($itemsApi, '? $provider_is_paid') !== false, 'Ready-made receipt eligibility requires the verified PayMongo ledger state.');
$assert(strpos($itemsApi, "WHERE o.order_id = ? AND o.customer_id = ?") !== false, 'Receipt data remains scoped to the authenticated order owner.');
$assert(strpos($itemsApi, "printflow_customer_receipt_is_available") !== false, 'Existing service receipt eligibility remains available.');
$assert(strpos($orders, 'Official Online Receipt') !== false, 'Online receipt keeps the existing online receipt design context.');
$assert(strpos($orders, 'Claim Status') !== false && strpos($orders, 'Ready to Claim') !== false, 'Online receipt declares ready-made claim status.');
$assert(strpos($orders, 'Please present this receipt when claiming your order.') !== false, 'Online receipt includes claim guidance.');
$assert(strpos($orders, 'Visit our Online Store') === false, 'Online receipt has no redundant store QR section.');
$assert(strpos($pos, 'Visit our Online Store') !== false, 'POS preview keeps its store QR section.');
$assert(strpos($printer, 'printflow_pos_online_store_url()') !== false, 'POS thermal printing keeps its store QR behavior.');
$assert(strpos($orders, 'printflow_receipt_enqueue') === false && strpos($orders, 'PushPrinter') === false, 'Customer receipt download never invokes a physical printer.');

if ($failures !== []) {
    fwrite(STDERR, "Online ready-made receipt regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Online ready-made receipt regression test passed.\n";
