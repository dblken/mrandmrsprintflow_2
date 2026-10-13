<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_set_price_helpers.php';

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $passed++;
    echo 'PASS: ', $label, PHP_EOL;
};

$modalMessage = 'This specification does not have an estimated price yet. The final price will be set by Staff before payment or order completion. Do you want to continue?';
$check(!str_contains($modalMessage, 'Price Pending') && !str_contains($modalMessage, 'Needs Staff Pricing'), 'warning note is not a status label');
$check(printflow_price_is_configured(360.0), 'a configured price stays valid');
$check(!printflow_price_is_configured(0.0), 'a missing price is not treated as configured');

$priced = [
    ['name' => 'Tarpaulin', 'price' => 360],
    ['name' => 'Sticker', 'price' => 50],
];
$check(printflow_checkout_unconfigured_price_message($priced) === null, 'configured products and services can complete');

$missing = [
    ['name' => 'Mugs', 'price' => 0],
    ['name' => 'Sintraboard', 'price' => 180],
];
$missingMessage = printflow_checkout_unconfigured_price_message($missing);
$check(is_string($missingMessage) && str_contains($missingMessage, 'Mugs'), 'one unpriced specification blocks completion for any product');
$check(printflow_checkout_unconfigured_price_message([['name' => 'Custom Frame', 'price' => -5]]) === 'A price cannot be negative.', 'a negative price is rejected');
$check(
    printflow_checkout_unconfigured_price_message([
        ['name' => 'Mugs', 'price' => 0],
        ['name' => 'Invitation', 'price' => 0],
    ]) !== null,
    'several different unpriced specifications are handled the same way'
);

$root = dirname(__DIR__);
$pos = (string)file_get_contents($root . '/staff/pos.php');
$checkout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$cartApi = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$jobs = (string)file_get_contents($root . '/includes/JobOrderService.php');
$status = (string)file_get_contents($root . '/staff/update_order_status_process.php');
$cart = (string)file_get_contents($root . '/customer/cart.php');
$estimator = (string)file_get_contents($root . '/public/assets/js/service_estimated_price.js');
$customerOrder = (string)file_get_contents($root . '/customer/order_service_dynamic.php');
$payment = (string)file_get_contents($root . '/customer/api_submit_payment.php');
$priceApi = (string)file_get_contents($root . '/admin/job_orders_api.php');

$check(!str_contains($pos, 'Could not calculate a valid price from your selections'), 'adding an item no longer treats a missing price as an error');
$check(str_contains($pos, 'price_unconfigured'), 'the missing-price state is kept on the cart item');
$check(str_contains($pos, 'Set Price') && str_contains($pos, 'redirectToSetPrice'), 'Staff Set Price remains available');
$check(str_contains($pos, 'Please set the price for all items before completing the sale'), 'POS completion stays blocked until a price exists');
$check(str_contains($checkout, 'printflow_checkout_unconfigured_price_message'), 'checkout rejects an unpriced sale before payment and inventory');
$check(str_contains($cartApi, "=== 'unconfigured'") && str_contains($cartApi, "price_unconfigured"), 'the cart accepts a specification that has no configured price');
$check(str_contains($jobs, 'order_total_not_configured'), 'inventory deduction waits until the order has a valid price');
$check(str_contains($jobs, 'Set a valid price before completing this order.'), 'job completion waits for a valid price');
$check(str_contains($status, 'Set a valid price before completing this order.'), 'order completion waits for a valid price');
$check(str_contains($pos, $modalMessage) && str_contains($pos, 'confirmMissingEstimatedPrice'), 'POS asks before adding a specification with no estimated price');
$check(str_contains($pos, "if (priced.unitPrice <= 0)") && str_contains($pos, "if (!proceed) return"), 'Cancel leaves the item off the order');
$check(str_contains($customerOrder, $modalMessage) && str_contains($customerOrder, 'pf-missing-estimate-continue') && str_contains($customerOrder, 'pf-missing-estimate-cancel'), 'the customer ordering page uses the same Continue and Cancel warning');
$check(!str_contains($estimator, 'no configured price yet') && !str_contains($cart, 'no configured price yet') && !str_contains($pos, 'pos-unconfigured-price-note'), 'the ordering page no longer shows an inline missing-price message');
$check(str_contains($customerOrder, 'specificationHasEstimatedPrice') && str_contains($customerOrder, 'estimatedPrice > 0') && str_contains($customerOrder, 'form.requestSubmit'), 'the warning appears only when estimated price is 0, and Continue submits the order');
$check(str_contains($payment, 'total_to_pay <= 0'), 'payment stays blocked while the order total is not a valid price');
$check(str_contains($priceApi, 'Price must be a valid amount of at least PHP 1.00'), 'Staff cannot save a zero or negative final price');

echo "Unconfigured price flow: {$passed} assertions passed.\n";
