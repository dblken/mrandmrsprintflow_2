<?php
/** Roll width input normalization. Stored inv_rolls.width_ft is always feet. */
function printflow_assert_received_roll_values(array $saved, int $itemId, int $branchId, float $widthFt, float $lengthFt): void
{
    foreach (['item_id' => $itemId, 'branch_id' => $branchId, 'width_ft' => $widthFt,
        'total_length_ft' => $lengthFt, 'remaining_length_ft' => $lengthFt] as $field => $expected) {
        if (!isset($saved[$field]) || !is_numeric($saved[$field])
            || abs((float)$saved[$field] - $expected) > 0.000001) {
            throw new RuntimeException('Roll receipt verification failed for ' . $field . '. No receipt should be committed; an Admin must review the roll schema and submitted measurement.');
        }
    }
}

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
