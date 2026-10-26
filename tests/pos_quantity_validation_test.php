<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pos_quantity_validation.php';

$cartApi = file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
$checkoutApi = file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
if ($cartApi === false || $checkoutApi === false) {
    throw new RuntimeException('Unable to read POS cart and checkout quantity handlers.');
}

$valid = ['1' => 1, '25' => 25, '1000001' => 1000001, 2147483647 => 2147483647];
foreach ($valid as $input => $expected) {
    if (printflow_pos_parse_positive_quantity($input) !== $expected) {
        throw new RuntimeException('A valid general positive whole quantity was rejected or changed.');
    }
}
if (printflow_pos_parse_positive_quantity(' 2 ') !== 2) {
    throw new RuntimeException('The general quantity parser compatibility behavior changed.');
}
foreach (['', '0', '-1', '1.5', 'abc', 1.5, null, true] as $input) {
    try {
        printflow_pos_parse_positive_quantity($input);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('Invalid general quantity was accepted: ' . var_export($input, true));
}

$cartValid = ['1' => 1, '999' => 999, '1000' => 1000, '999999' => 999999, '1000000' => 1000000, '0001' => 1, '0001000000' => 1000000, 1000000 => 1000000];
foreach ($cartValid as $input => $expected) {
    if (printflow_pos_parse_cart_quantity($input) !== $expected) {
        throw new RuntimeException('A valid cart quantity was rejected or changed.');
    }
}
foreach (['', ' ', ' 2 ', "2\n", '0', '000', '-1', '+1', '1.5', '1e3', '1E3', 'abc', '12abc', '1000001', '999999999999999999999999', 1.5, null, true, [], (object)['qty' => 1]] as $input) {
    try {
        printflow_pos_parse_cart_quantity($input);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('Invalid quantity was accepted: ' . var_export($input, true));
}

echo "POS quantity validation tests passed.\n";

$validPrices = ['100' => 100.0, '200.50' => 200.5, '1500.99' => 1500.99, 100 => 100.0];
foreach ($validPrices as $input => $expected) {
    if (printflow_pos_parse_final_price($input) !== $expected) {
        throw new RuntimeException('A valid final price was rejected or changed.');
    }
}
foreach (['', '0', '-100', 'abc', '200.999', '10000000', '1e2', NAN, INF, null, true] as $input) {
    try {
        printflow_pos_parse_final_price($input);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('Invalid final price was accepted: ' . var_export($input, true));
}

echo "POS final price validation tests passed.\n";

if (strpos($cartApi, "array_key_exists('qty', \$data) ? \$data['qty'] : 1") === false
    || strpos($cartApi, 'return printflow_pos_parse_cart_quantity($value)') === false
    || strpos($checkoutApi, 'return printflow_pos_parse_cart_quantity($value)') === false) {
    throw new RuntimeException('Cart add/update and checkout must validate raw quantity values through the shared strict parser.');
}

echo "POS cart and checkout raw quantity validation contracts passed.\n";
