<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mutation = (string)file_get_contents($root . '/includes/order_status_mutation.php');
$payment = (string)file_get_contents($root . '/includes/order_completion_payment.php');
$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($payment, 'function printflow_order_staff_completion_payment_gate') !== false, 'Payment gate helper exists.');
$expect(strpos($mutation, 'pos_merged') !== false, 'Archived POS merged shells must be blocked from completion.');
$expect(strpos($customizations, 'posWalkInCompletionPaymentReady') !== false, 'POS UI must pre-check payment readiness.');
$expect(strpos($customizations, '${label} rejected') !== false, 'Parser must log structured JSON rejection details.');
$expect(strpos($customizations, 'Complete order failed') !== false, 'Completion handler must log HTTP status and server error fields.');

if ($failures !== []) {
    fwrite(STDERR, "order_completion_payment_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "order_completion_payment_test passed.\n";
