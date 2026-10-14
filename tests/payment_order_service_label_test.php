<?php
/**
 * Admin Payments must show saved order-item service names (not job_orders mirror or placeholder products).
 */

$root = dirname(__DIR__);
require_once $root . '/includes/job_order_summary.php';

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$paymentPage = (string)file_get_contents($root . '/admin/payment.php');
$assert(str_contains($paymentPage, 'printflow_dashboard_order_service_labels'), 'payments page must resolve labels from order-item batch loader');
$assert(str_contains($paymentPage, 'pf_payment_item_label($paymentTx, $paymentIdentityLabels)'), 'payments must overwrite stale SQL item labels');
$assert(
    !preg_match('/return\s+\$services\s*!==\s*\'\'\s*\?\s*\$services\s*:\s*\(\$products/s', $paymentPage),
    'payments must not prefer job_orders.service_type over saved order lines'
);

$row = [
    'order_id' => 12180,
    'order_type' => 'custom',
    'service_names' => 'Tshirt',
    'product_names' => 'Tshirt',
];
$assert(
    printflow_payment_transaction_item_label($row, [12180 => 'Tarpaulin']) === 'Tarpaulin',
    'identity labels must override stale job_orders and placeholder product names for custom orders'
);
$assert(
    printflow_payment_transaction_item_label($row, []) === 'Customization',
    'custom orders without a resolved label must not fall back to placeholder product names'
);

$productRow = [
    'order_id' => 500,
    'order_type' => 'product',
    'service_names' => '',
    'product_names' => 'Bond Paper A4',
];
$assert(
    printflow_payment_transaction_item_label($productRow, []) === 'Bond Paper A4',
    'product orders still use catalog product names'
);

if ($failures !== []) {
    fwrite(STDERR, "Payment order service label test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Payment order service label test passed.\n";
