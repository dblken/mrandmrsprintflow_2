<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cart = file_get_contents($root . '/customer/cart.php');
$api = file_get_contents($root . '/customer/api_cart.php');
if ($cart === false || $api === false) {
    throw new RuntimeException('Unable to read cart sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};

$assert(strpos($cart, 'class="qty-val"') !== false, 'cart renders editable quantity input');
$assert(strpos($cart, 'inputmode="numeric"') !== false && strpos($cart, "replace(/[^0-9]/g, '')") !== false, 'quantity input accepts whole numbers only');
$assert(strpos($cart, "if (nextValue === '0') nextValue = '1';") !== false, 'quantity input blocks zero immediately');
$assert(strpos($cart, "max=\"' . (int)\$available_stock . '\"") !== false, 'quantity input exposes the actual available stock as its maximum');
$assert(strpos($cart, 'newQty > availableStock') !== false, 'quantity input rejects values above available stock');
$assert(strpos($cart, "Only ' + Math.max(availableStock, 0) + ' item(s) available.") !== false, 'quantity input shows an over-stock error message');
$assert(strpos($cart, 'class="qty-error"') !== false && strpos($cart, 'setQtyError(row') !== false, 'quantity errors render below the quantity field');
$assert(strpos($cart, 'Your cart cannot exceed 99 items.') !== false, 'cart-limit error is distinct from stock error');
$assert(strpos($cart, 'onblur="commitQty(') !== false, 'quantity saves on blur');
$assert(strpos($cart, 'data-available-stock') !== false, 'cart exposes available product stock');
$assert(strpos($cart, "printflow_product_effective_stock") !== false, 'cart resolves branch stock');
$assert(strpos($cart, "parseInt(s.value, 10)") !== false, 'cart totals read typed quantity values');
$updatePos = strpos($api, "if (\$action === 'update')");
$stockMessagePos = strpos($api, "Quantity exceeds available stock.", $updatePos === false ? 0 : $updatePos);
$assert($updatePos !== false && $stockMessagePos !== false, 'cart API validates branch stock on update');
$assert(strpos($api, "Quantity must be a whole number.") !== false, 'cart API rejects non-whole quantities');

echo "OK cart_quantity_input_test\n";
