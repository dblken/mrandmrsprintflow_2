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

/**
 * Parse a production material quantity using the inventory item's configured
 * UOM and consumption method. Fractional values are allowed only for the
 * already-supported, explicitly measured linear-foot workflow.
 */
function printflow_pos_parse_positive_material_quantity($value, array $item): float
{
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? '')));
    $method = strtolower(trim((string)($item['consumption_method'] ?? '')));
    $allowsMeasuredFeet = $uom === 'ft' && in_array($method, ['by_length', 'manual'], true);
    if (!$allowsMeasuredFeet) {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = sprintf('%.0f', $value);
        }
        $quantity = (float)printflow_pos_parse_positive_quantity($value);
        if ($quantity > 99999999.99) {
            throw new InvalidArgumentException('Material quantity exceeds the supported DECIMAL(10,2) range.');
        }
        return $quantity;
    }

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Measured material quantity must be a positive number of feet with at most two decimal places.');
    }
    $raw = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $raw)) {
        throw new InvalidArgumentException('Measured material quantity must be a positive number of feet with at most two decimal places.');
    }
    $quantity = (float)$raw;
    if (!is_finite($quantity) || $quantity <= 0 || $quantity > 99999999.99) {
        throw new InvalidArgumentException('Measured material quantity must be a positive number of feet with at most two decimal places.');
    }
    return $quantity;
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

/** Strict final selling price: exact positive cents within DECIMAL(9,2). */
function printflow_pos_parse_final_price($value): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Enter a final price from PHP 0.01 to PHP 9,999,999.99 with no more than two decimal places.');
    }
    $raw = trim((string)$value);
    $error = 'Enter a final price from PHP 0.01 to PHP 9,999,999.99 with no more than two decimal places.';
    if (!preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/', $raw)) {
        throw new InvalidArgumentException($error);
    }
    $normalized = str_replace(',', '', $raw);
    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    $whole = ltrim($whole, '0');
    if ($whole === '') $whole = '0';
    if (strlen($whole) > 7) throw new InvalidArgumentException($error);
    $centavos = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    if ($centavos < 1 || $centavos > 999999999) {
        throw new InvalidArgumentException($error);
    }
    return $centavos / 100;
}
