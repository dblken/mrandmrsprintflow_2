<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pos_quantity_validation.php';

$valid = ['1' => 1, '25' => 25, 2147483647 => 2147483647];
foreach ($valid as $input => $expected) {
    if (printflow_pos_parse_positive_quantity($input) !== $expected) {
        throw new RuntimeException('A valid positive whole quantity was rejected or changed.');
    }
}
if (printflow_pos_parse_positive_quantity(' 2 ') !== 2) {
    throw new RuntimeException('Surrounding whitespace should be safely trimmed before parsing.');
}

foreach (['', '0', '-1', '1.5', 'abc', '01', '2147483648', 1.5, null, true] as $input) {
    try {
        printflow_pos_parse_positive_quantity($input);
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
