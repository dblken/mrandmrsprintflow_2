<?php

/**
 * POS service cart pricing: server calculator + cart/checkout wiring.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

require_once __DIR__ . '/../includes/service_order_helper.php';
$assert(function_exists('printflow_calculate_service_unit_price'), 'printflow_calculate_service_unit_price exists');

$invalid = printflow_calculate_service_unit_price(0, []);
$assert($invalid['ok'] === false, 'invalid service id rejected');

$posSource = (string) file_get_contents(__DIR__ . '/../staff/pos.php');
$assert(str_contains($posSource, 'resolveServiceModalPricing'), 'POS resolves modal pricing before add');
$assert(str_contains($posSource, 'price: priced.unitPrice'), 'POS sends calculated unit price to cart API');
$assert(!preg_match('/price:\s*0,\s*\n\s*qty:.*is_service:\s*true/s', $posSource), 'POS no longer hardcodes service price 0 on add');

$cartSource = (string) file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
$assert(str_contains($cartSource, 'printflow_calculate_service_unit_price'), 'Cart handler recalculates service unit price');

$checkoutSource = (string) file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
$assert(str_contains($checkoutSource, 'pos_checkout_validate_service_item_prices'), 'Checkout validates service prices server-side');

echo PHP_EOL . 'All POS service cart price tests passed.' . PHP_EOL;
