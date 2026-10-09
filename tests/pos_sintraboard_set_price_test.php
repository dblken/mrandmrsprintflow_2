<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/pos_set_price_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(printflow_service_requires_pos_set_price(0, 'Sintraboard Standees', 'Signage'), 'sintraboard service name detected');
$assert(!printflow_service_requires_pos_set_price(0, 'Tarpaulin Printing', 'Print'), 'tarpaulin not flagged');
$assert(pos_cart_item_requires_pos_set_price([
    'is_service' => true,
    'product_id' => 12,
    'name' => 'Sintraboard Standees',
    'customization' => ['service_id' => 12],
    'price_set' => false,
]), 'cart sintraboard item requires set price');
$assert(pos_cart_item_requires_pos_set_price([
    'is_service' => true,
    'product_id' => 67,
    'name' => 'Stickers Decals',
    'customization' => ['service_id' => 67],
    'price_set' => true,
]), 'all POS service lines require staff pricing flow');
$assert(!pos_cart_item_requires_pos_set_price([
    'is_service' => false,
    'product_id' => 5,
    'name' => 'Bond Paper',
]), 'ready-made products do not require set price');
$assert(pos_cart_item_needs_pos_set_price_completion([
    'is_service' => true,
    'product_id' => 12,
    'name' => 'Sintraboard Standees',
    'customization' => [],
    'price_set' => false,
]), 'unfinished sintraboard needs completion');
$assert(!pos_cart_item_needs_pos_set_price_completion([
    'is_service' => true,
    'product_id' => 12,
    'name' => 'Sintraboard Standees',
    'customization' => [],
    'price_set' => true,
]), 'finalized sintraboard does not need completion');

$pos = file_get_contents($root . '/staff/pos.php');
$cart = file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$assert($pos !== false && str_contains($pos, 'posCartItemShowsSetPriceButton'), 'POS cart shows set price button helper');
$assert($cart !== false && str_contains($cart, 'pos_cart_resolve_price_set_on_add'), 'cart handler respects price_set flag');
$assert($checkout !== false && str_contains($checkout, 'pos_cart_item_requires_pos_set_price'), 'checkout validates sintraboard set price');
$assert(str_contains((string) file_get_contents($root . '/includes/pos_set_price_helpers.php'), 'printflow_pos_customization_estimated_total'), 'estimated total helper exists');
$assert(!str_contains((string) $pos, 'Upload Warning'), 'POS set price flow does not block on upload warning');

echo "OK pos_sintraboard_set_price_test\n";
