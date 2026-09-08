<?php

$root = dirname(__DIR__);
$paymentPage = (string)file_get_contents($root . '/customer/payment.php');
$ordersPage = (string)file_get_contents($root . '/staff/orders.php');
$statusEndpoint = (string)file_get_contents($root . '/staff/update_order_status_process.php');
$functions = (string)file_get_contents($root . '/includes/functions.php');
$staffCancel = (string)file_get_contents($root . '/staff/cancel_order_process.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($paymentPage, "'Payment Successful'") !== false, 'Paid ready-made orders must render Payment Successful.');
$assert(strpos($paymentPage, "'Awaiting Production'") !== false, 'Service payment copy must remain available.');
$assert(strpos($paymentPage, "'Your payment has been received successfully.") !== false, 'Paid ready-made orders must receive claim guidance.');
$assert(strpos($ordersPage, "'TO_PICK_UP': 'TO PICK UP'") === false, 'Ready-made staff tabs must not expose To Pick Up.');
$assert(strpos($ordersPage, "'CANCELLED': 'CANCELLED'") === false, 'Ready-made staff tabs must not expose Cancelled.');
$assert(strpos($ordersPage, "'AWAITING_PAYMENT'") !== false && strpos($ordersPage, "'PAID'") !== false, 'Ready-made staff cards must separate awaiting payment from paid claims.');
$assert(strpos($ordersPage, "providerStatus === 'paid'") !== false, 'Completion UI must require a paid provider ledger status.');
$assert(strpos($statusEndpoint, "printflow_provider_payment_find('order', \$orderId, 'online', printflow_paymongo_mode())") !== false, 'Completion must consult the PayMongo ledger server-side.');
$assert(strpos($statusEndpoint, 'PayMongo has not verified this payment as paid.') !== false, 'Unverified provider payments must be rejected server-side.');
$assert(strpos($functions, 'function printflow_is_ready_made_product_order') !== false, 'Ready-made product classification must be centralized.');
$assert(strpos($functions, 'if (printflow_is_ready_made_product_order($order)) return false;') !== false, 'Customer cancellation must be disabled for ready-made products.');
$assert(strpos($staffCancel, 'printflow_is_ready_made_product_order($orderRows[0])') !== false, 'Staff cancellation must be disabled for ready-made products.');

if ($failures !== []) {
    fwrite(STDERR, "Ready-made product workflow regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Ready-made product workflow regression test passed.\n";
