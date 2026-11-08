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

$measuredFt = ['unit_of_measure' => 'ft', 'consumption_method' => 'per_piece'];
if (printflow_pos_parse_positive_material_quantity('0.25', $measuredFt) !== 0.25
    || printflow_pos_parse_positive_material_quantity('7.50', $measuredFt) !== 7.5
    || printflow_pos_parse_positive_material_quantity('2', $measuredFt) !== 2.0
    || printflow_pos_parse_positive_material_quantity(2.0, ['unit_of_measure' => 'pcs', 'consumption_method' => 'per_piece']) !== 2.0
    || printflow_pos_parse_positive_material_quantity('1.00', ['unit_of_measure' => 'pcs', 'consumption_method' => 'per_piece']) !== 1.0
    || printflow_pos_parse_positive_material_quantity('1.00', $measuredFt) !== 1.0) {
    throw new RuntimeException('Staff-entered inventory-unit quantities must preserve valid fractions and whole-unit values.');
}
foreach ([['pcs', 'by_length'], ['sheet', 'per_piece'], ['unit', 'manual']] as [$uom, $method]) {
    try {
        printflow_pos_parse_positive_material_quantity('0.25', ['unit_of_measure' => $uom, 'consumption_method' => $method]);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('A fractional material quantity was accepted for a discrete inventory unit.');
}
foreach (['0', '-0.25', '0.001', '1.234', '1e-1', '100000000.00'] as $invalid) {
    try {
        printflow_pos_parse_positive_material_quantity($invalid, $measuredFt);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('An invalid measured material quantity was accepted: ' . $invalid);
}
$wholeRangeRejected = false;
try {
    printflow_pos_parse_positive_material_quantity('100000000', ['unit_of_measure' => 'pcs', 'consumption_method' => 'per_piece']);
} catch (InvalidArgumentException $error) {
    // The material assignment and ledger storage are DECIMAL(10,2).
    $wholeRangeRejected = true;
}
if (!$wholeRangeRejected) throw new RuntimeException('Material quantity above the DECIMAL(10,2) storage range was accepted.');
if (printflow_pos_parse_positive_material_quantity('0.25', ['unit_of_measure' => 'ft', 'consumption_method' => 'per_piece']) !== 0.25
    || printflow_pos_parse_positive_material_quantity('0.25', ['unit_of_measure' => 'm', 'consumption_method' => 'by_area']) !== 0.25
    || printflow_pos_parse_positive_material_quantity('0.25', ['unit_of_measure' => 'l', 'consumption_method' => 'dimension_sheet']) !== 0.25) {
    throw new RuntimeException('Manual actual usage in measured inventory units must not depend on obsolete calculation methods.');
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

$validPrices = [
    '0.01' => 0.01,
    '100' => 100.0,
    '200.50' => 200.5,
    '1,000.00' => 1000.0,
    '10,000.00' => 10000.0,
    '250,000.50' => 250000.5,
    '1,000,000.00' => 1000000.0,
    '9,999,999.99' => 9999999.99,
    100 => 100.0,
];
foreach ($validPrices as $input => $expected) {
    if (printflow_pos_parse_final_price($input) !== $expected) {
        throw new RuntimeException('A valid final price was rejected or changed.');
    }
}
foreach (['', '0', '0.00', '-100', '-0.01', 'abc', '200.999', '200.000', '10000000', '10,000,000.00', '1,00', '1,,000', '1e2', NAN, INF, null, true] as $input) {
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
