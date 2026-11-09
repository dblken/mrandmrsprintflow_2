<?php

/** Staff Actual Usage in Production Assignment may not exceed this amount per material line. */
if (!defined('PRINTFLOW_POS_MAX_MATERIAL_QUANTITY')) {
    define('PRINTFLOW_POS_MAX_MATERIAL_QUANTITY', 10000.0);
}

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
 * Subli Paper (#55) is inventoried in sheets but Mugs consume half-sheets
 * (0.5 sheet per mug). Other sheet materials remain whole-unit only.
 */
function printflow_pos_material_allows_fractional_sheet_usage(array $item): bool
{
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? '')));
    if (!in_array($uom, ['sheet', 'sheets'], true)) {
        return false;
    }
    $itemId = (int)($item['id'] ?? $item['item_id'] ?? 0);
    $name = strtolower(trim((string)($item['name'] ?? '')));
    return $itemId === 55 || $name === 'subli paper';
}

/** Parse a positive staff-entered quantity in the item's saved inventory UOM. */
function printflow_pos_parse_positive_material_quantity($value, array $item): float
{
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? '')));
    if ($uom === '') {
        throw new InvalidArgumentException('The inventory item must have a configured unit of measure.');
    }
    $wholeUnit = in_array($uom, [
        'pc', 'pcs', 'piece', 'pieces', 'sheet', 'sheets', 'roll', 'rolls',
        'unit', 'units', 'set', 'sets', 'bottle', 'bottles', 'pack', 'packs',
    ], true);
    $allowFractionalSheet = printflow_pos_material_allows_fractional_sheet_usage($item);
    if ($wholeUnit && !$allowFractionalSheet) {
        // Accept already-canonical floats and DECIMAL round-trips such as
        // "1.00" from job_order_materials.quantity. Still reject fractional
        // discrete quantities like "1.5" or "1.01".
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value > 0) {
            $value = sprintf('%.0f', $value);
        } elseif (is_int($value) && $value > 0) {
            $value = (string)$value;
        } elseif (is_string($value)) {
            $rawWhole = trim($value);
            if (preg_match('/^[1-9][0-9]{0,7}(?:\.0+)?$/', $rawWhole) === 1) {
                $value = (string)(int)(float)$rawWhole;
            }
        }
        $quantity = (float)printflow_pos_parse_positive_quantity($value);
        if ($quantity > PRINTFLOW_POS_MAX_MATERIAL_QUANTITY) {
            throw new InvalidArgumentException('Material quantity cannot exceed 10,000 in its inventory unit.');
        }
        return $quantity;
    }

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Material quantity must be a positive amount in its inventory unit with at most two decimal places.');
    }
    if (is_float($value) && is_finite($value)) {
        $raw = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        if ($raw === '') {
            $raw = '0';
        }
    } else {
        $raw = trim((string)$value);
    }
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $raw)) {
        throw new InvalidArgumentException('Material quantity must be a positive amount in its inventory unit with at most two decimal places.');
    }
    $quantity = (float)$raw;
    if (!is_finite($quantity) || $quantity <= 0 || $quantity > PRINTFLOW_POS_MAX_MATERIAL_QUANTITY) {
        throw new InvalidArgumentException('Material quantity must be a positive amount in its inventory unit with at most two decimal places.');
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
