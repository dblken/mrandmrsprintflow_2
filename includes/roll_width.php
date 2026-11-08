<?php
/** Roll width input normalization. Stored inv_rolls.width_ft is always feet. */
function printflow_roll_width_to_feet($value, string $unit): float
{
    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric($raw)) {
        throw new InvalidArgumentException('Enter a valid roll width.');
    }
    $width = (float)$raw;
    if (!is_finite($width) || $width <= 0) {
        throw new InvalidArgumentException('Roll width must be greater than zero.');
    }

    $normalizedUnit = strtolower(trim($unit));
    $factor = match ($normalizedUnit) {
        'ft', 'feet', 'foot' => 1.0,
        'in', 'inch', 'inches' => 1.0 / 12.0,
        default => throw new InvalidArgumentException('Roll width unit must be ft or in.'),
    };
    $widthFt = $width * $factor;
    if (!is_finite($widthFt) || $widthFt <= 0 || $widthFt > 100) {
        throw new InvalidArgumentException('Roll width must be greater than zero and no more than 100 ft.');
    }
    return round($widthFt, 6);
}

/** Normalize an item's configured nominal width for a Receive In form default. */
function printflow_roll_width_default(array $item): array
{
    $value = $item['material_width'] ?? null;
    $unit = strtolower(trim((string)($item['material_dimension_unit'] ?? '')));
    if (!is_numeric($value) || (float)$value <= 0 || !in_array($unit, ['ft', 'feet', 'foot', 'in', 'inch', 'inches'], true)) {
        return ['value' => '', 'unit' => 'ft'];
    }
    return ['value' => (string)$value, 'unit' => in_array($unit, ['in', 'inch', 'inches'], true) ? 'in' : 'ft'];
}
