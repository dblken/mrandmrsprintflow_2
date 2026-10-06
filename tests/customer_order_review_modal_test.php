<?php

$root = dirname(__DIR__);
$orders = (string)file_get_contents($root . '/customer/orders.php');
$rateOrder = (string)file_get_contents($root . '/customer/rate_order.php');
$notifJs = (string)file_get_contents($root . '/public/assets/js/notifications.js');
$reviewJs = (string)file_get_contents($root . '/public/assets/js/customer-order-review.js');
$functions = (string)file_get_contents($root . '/includes/functions.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($orders, 'completedReviewModal') !== false, 'Orders page defines the completed-order prompt modal.');
$assert(strpos($orders, 'orderReviewModal') !== false, 'Orders page defines the review submission modal.');
$assert(strpos($orders, 'customer-order-review.js') !== false, 'Orders page loads the review modal controller script.');
$assert(strpos($orders, 'data-pf-open-review') !== false, 'Orders list opens review via modal trigger instead of inline page form.');
$assert(strpos($rateOrder, 'customer_rate_order_handle_post') !== false, 'Rate order endpoint reuses shared submission handler.');
$assert(strpos($rateOrder, 'review_prompt=1') !== false, 'Direct rate_order visits redirect back to orders with review prompt.');
$assert(strpos($rateOrder, 'fragment') !== false, 'Rate order endpoint exposes modal form fragment.');
$assert(strpos($notifJs, 'pf:completed-order') !== false, 'Completed-order notifications on orders page dispatch modal event instead of only toast.');
$assert(strpos($functions, 'review_prompt=1') !== false, 'Completed notification deep links target orders review prompt.');
$assert(strpos($reviewJs, 'Maximum 5 images allowed.') !== false, 'Modal review flow keeps image upload limits.');
$assert(strpos($reviewJs, 'Only MP4 videos are allowed.') !== false, 'Modal review flow keeps video upload restrictions.');

if ($failures !== []) {
    fwrite(STDERR, "Customer order review modal test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Customer order review modal test passed.\n";
