<?php

$root = dirname(__DIR__);
$orders = (string)file_get_contents($root . '/customer/orders.php');
$footer = (string)file_get_contents($root . '/includes/footer.php');
$notifJs = (string)file_get_contents($root . '/public/assets/js/notifications.js');
$reviewJs = (string)file_get_contents($root . '/public/assets/js/customer-order-review.js');
$rateOrderInclude = (string)file_get_contents($root . '/includes/customer_rate_order.php');
$api = (string)file_get_contents($root . '/customer/api_review_prompt.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($footer, 'customer_review_modals.php') !== false, 'Customer footer loads global review modals.');
$assert(strpos($footer, 'api_review_prompt.php') !== false, 'Customer footer configures review prompt API.');
$assert(strpos($orders, 'id="completedReviewModal"') === false, 'Orders page no longer embeds duplicate review modals.');
$assert(strpos($rateOrderInclude, 'customer_review_prompt_dismissals') !== false, 'Review prompt dismissals persist in database.');
$assert(strpos($api, "action !== 'dismiss'") !== false, 'Review prompt API supports permanent dismiss.');
$assert(strpos($notifJs, 'completionByOrder') !== false, 'Notification poll merges completion notices per order.');
$assert(strpos($reviewJs, 'persistDismiss') !== false, 'Review modal skip persists through API.');
$assert(strpos($reviewJs, 'Your feedback has been submitted successfully.') !== false, 'Review success toast uses required message.');

if ($failures !== []) {
    fwrite(STDERR, "Customer order review modal test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Customer order review modal test passed.\n";
