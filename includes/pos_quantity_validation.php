<?php
/** Shared positive whole-number validation for POS cart and checkout quantities. */
function printflow_pos_parse_positive_quantity($value): int
{
    if (!is_int($value) && !is_string($value)) {
        throw new InvalidArgumentException('Quantity must be a positive whole number.');
    }
    $raw = trim((string)$value);
    if (!preg_match('/^[1-9][0-9]*$/', $raw) || (float)$raw > 2147483647) {
        throw new InvalidArgumentException('Quantity must be a positive whole number.');
    }
    return (int)$raw;
}

/** Strict POS service selling price: exact positive cents within DECIMAL(9,2). */
function printflow_pos_parse_final_price($value): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Enter a valid final price with no more than two decimal places.');
    }
    $raw = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/', $raw)) {
        throw new InvalidArgumentException('Enter a valid final price with no more than two decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $centavos = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    if ($centavos < 100) {
        throw new InvalidArgumentException('Final price must be at least PHP 1.00.');
    }
    return $centavos / 100;
}
