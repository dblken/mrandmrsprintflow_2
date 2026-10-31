<?php
/** General positive whole-number validation for measured material quantities. */
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

/** Strict cart-item quantity parser used by POS add/update/checkout requests. */
function printflow_pos_parse_cart_quantity($value): int
{
    if (!is_int($value) && !is_string($value)) {
        throw new InvalidArgumentException('Quantity must be a whole number from 1 to 1,000,000 using digits 0–9 only.');
    }
    $raw = (string)$value;
    if ($raw === '' || strlen($raw) > 64 || !preg_match('/\A[0-9]+\z/', $raw)) {
        throw new InvalidArgumentException('Quantity must be a whole number from 1 to 1,000,000 using digits 0–9 only.');
    }
    $canonical = ltrim($raw, '0');
    if ($canonical === '' || strlen($canonical) > 7) {
        throw new InvalidArgumentException('Quantity must be between 1 and 1,000,000.');
    }
    $quantity = (int)$canonical;
    if ($quantity < 1 || $quantity > 1000000) {
        throw new InvalidArgumentException('Quantity must be between 1 and 1,000,000.');
    }
    return $quantity;
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
