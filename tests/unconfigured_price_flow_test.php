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

$posModalMessage = 'This specification does not have an estimated price yet. The final price will be set by Staff before payment or order completion. Do you want to continue?';
$customerZeroPriceMessage = printflow_customer_zero_estimated_price_message();
$check(!str_contains($customerZeroPriceMessage, 'Price Pending') && !str_contains($customerZeroPriceMessage, 'Needs Staff Pricing'), 'customer zero-price note is not a status label');
$check(str_contains($customerZeroPriceMessage, 'The price is not zero'), 'customer zero-price note explains staff will confirm price');
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
$servicesViewStart = strpos($pos, 'id="services-view"');
$servicesViewEnd = strpos($pos, 'RIGHT: CART');
$servicesView = ($servicesViewStart !== false && $servicesViewEnd !== false)
    ? substr($pos, $servicesViewStart, $servicesViewEnd - $servicesViewStart)
    : '';
$check($servicesView !== '' && !str_contains($servicesView, 'pos-catalog-card__price') && !str_contains($servicesView, 'Custom Pricing'), 'service cards do not show a fixed, default, or placeholder price');
$check(str_contains($pos, 'aria-label="Price"'), 'product cards still show their price');
$check(str_contains($pos, 'id="pos-estimated-price-display" class="pos-estimated-price-display"') && !str_contains($pos, 'pos-estimated-price-display" hidden'), 'the order form shows Estimated Price');
$check(str_contains($pos, 'pos-est-price-label">Estimated Price') && str_contains($pos, '₱0.00') && str_contains($pos, 'showSetPrice && item.price_set !== true'), 'a zero estimated price is still displayed');
$check(str_contains($pos, $posModalMessage) && str_contains($pos, 'confirmMissingEstimatedPrice'), 'POS asks before adding a specification with no estimated price');
$check(str_contains($pos, "if (priced.unitPrice <= 0)") && str_contains($pos, "if (!proceed) return"), 'Cancel leaves the item off the order');
$check(
    str_contains($customerOrder, 'pf-customer-zero-price-note')
        && str_contains($customerOrder, 'pf-estimated-price-tooltip')
        && str_contains($customerOrder, 'aria-label="Estimated price information"')
        && str_contains($customerOrder, 'printflow_customer_zero_estimated_price_message'),
    'customer ordering shows zero-price guidance in an accessible Estimated Price tooltip'
);
$check(!str_contains($customerOrder, '<p id="pf-customer-zero-price-note"'), 'customer ordering removes the large inline zero-price notice');
$check(!str_contains($customerOrder, $posModalMessage), 'customer ordering does not reuse the POS Continue/Cancel modal copy');
$check(strpos($customerOrder, 'pf-missing-estimate-continue') === false, 'customer ordering does not block submit with a Continue/Cancel modal');
$check(!str_contains($estimator, 'no configured price yet') && !str_contains($cart, 'no configured price yet') && !str_contains($pos, 'pos-unconfigured-price-note'), 'the ordering page no longer shows an inline missing-price message');
$check(str_contains($customerOrder, 'specificationHasEstimatedPrice') && str_contains($customerOrder, 'syncCustomerZeroPriceNote'), 'customer page shows guidance when estimated price is 0 without blocking submit');
$check(str_contains($payment, 'total_to_pay <= 0'), 'payment stays blocked while the order total is not a valid price');
$check(str_contains($priceApi, 'Price must be a valid amount of at least PHP 1.00'), 'Staff cannot save a zero or negative final price');

echo "Unconfigured price flow: {$passed} assertions passed.\n";
