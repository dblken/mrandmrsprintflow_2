<?php
/**
 * Resolve explicit physical dimensions for POS area-based material rules.
 * Select fields are eligible only when their configured key/label identifies
 * a size/dimension field and their preset options encode physical dimensions.
 */
require_once __DIR__ . '/service_dimension_ui.php';

function printflow_pos_service_dimension_configs(int $serviceId): array|false
{
    if ($serviceId <= 0) return false;
    $rows = db_query(
        "SELECT field_key, field_label, field_type, unit, allow_others, field_options, is_visible
         FROM service_field_configs
         WHERE service_id = ? AND is_visible = 1 AND field_type IN ('dimension', 'select', 'radio')
         ORDER BY display_order, config_id",
        'i', [$serviceId]
    );
    if (!is_array($rows)) return false;
    foreach ($rows as &$row) {
        $options = $row['field_options'] ?? [];
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        $row['_options'] = is_array($options) ? $options : [];
    }
    unset($row);
    return $rows;
}

function printflow_pos_dimension_pair_from_option(string $value): ?array
{
    $number = '\\d+(?:\\.\\d+)?';
    $unit = '(?:\\s*(?:feet|foot|ft|inches|inch|in|cm|mm|m)\\b|\\s*\")?';
    $pairPattern = '/(' . $number . ')' . $unit . '\\s*[x×*]\\s*(' . $number . ')' . $unit . '/iu';
    $triplePattern = '/'. $number . $unit . '\\s*[x×*]\\s*' . $number . $unit . '\\s*[x×*]\\s*' . $number . '/iu';
    if (preg_match($triplePattern, $value)) return null;
    if (preg_match_all($pairPattern, $value, $matches, PREG_SET_ORDER) !== 1) return null;
    $match = $matches[0];
    $width = (float)$match[1];
    $height = (float)$match[2];
    if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) return null;
    return ['width' => (string)$match[1], 'height' => (string)$match[2]];
}

/** Return a supported unit explicitly named in an option, rejecting conflicts. */
function printflow_pos_dimension_explicit_unit(string $value): ?string
{
    preg_match_all('/(?<![\p{L}])(?:feet|foot|ft|inches|inch|in|cm|mm)(?![\p{L}])|"/iu', $value, $matches);
    $units = [];
    foreach ($matches[0] ?? [] as $token) {
        $token = strtolower(trim($token));
        $unit = in_array($token, ['feet', 'foot', 'ft'], true) ? 'ft'
            : (in_array($token, ['inches', 'inch', 'in', '"'], true) ? 'in' : $token);
        $units[$unit] = true;
    }
    return count($units) === 1 ? (string)array_key_first($units) : null;
}

/** Normalize only units supported by the area-demand calculator; never default unknown units to feet. */
function printflow_pos_dimension_configured_unit(array $row): string
{
    $unit = strtolower(trim((string)($row['unit'] ?? '')));
    return match ($unit) {
        'ft', 'feet', 'foot' => 'ft',
        'in', 'inch', 'inches', '"' => 'in',
        'cm' => 'cm',
        'mm' => 'mm',
        default => '',
    };
}

function printflow_pos_dimension_field_candidate(array $row): bool
{
    $type = strtolower(trim((string)($row['field_type'] ?? $row['type'] ?? '')));
    if ($type === 'dimension') return true;
    if (!in_array($type, ['select', 'radio'], true)) return false;
    $key = strtolower(trim((string)($row['field_key'] ?? '')));
    $label = strtolower(trim((string)($row['field_label'] ?? $row['label'] ?? '')));
    $identity = $key . ' ' . $label;
    if (preg_match('/\b(finish|lamination|laminate|material|surface|color|colour)\b/', $key)) return false;
    if (preg_match('/\b(finish|lamination|laminate|material|surface|color|colour)\b/', $identity)
        && !preg_match('/\b(dimension|dimensions|size|sizes)\b/', $key)) return false;
    if (!preg_match('/\b(dimension|dimensions|size|sizes)\b/', $identity)) return false;

    $options = $row['_options'] ?? $row['options'] ?? [];
    $hasPhysicalOption = false;
    foreach (is_array($options) ? $options : [] as $option) {
        $value = trim((string)(is_array($option) ? ($option['value'] ?? '') : $option));
        if ($value === '' || in_array(strtolower($value), ['others', 'custom size'], true)) continue;
        if (printflow_pos_dimension_pair_from_option($value) === null) return false;
        $hasPhysicalOption = true;
    }
    return $hasPhysicalOption || !empty($row['allow_others']);
}

function printflow_pos_dimension_option_unit(string $value, array $row): string
{
    $explicit = printflow_pos_dimension_explicit_unit($value);
    $configured = printflow_pos_dimension_configured_unit($row);
    if ($explicit !== null) return $explicit;
    // Do not let an unsupported or conflicting unit token silently inherit a
    // different configured unit (for example, “8 x 11 m” with unit “ft”).
    if (preg_match('/(?<![\p{L}])(?:feet|foot|ft|inches|inch|in|cm|mm|m)(?![\p{L}])|"/iu', $value)) {
        return '';
    }
    return $configured;
}

/** Returns one unambiguous field/unit descriptor, or null if configuration is incomplete. */
function printflow_pos_service_dimension_descriptor(array $rows): ?array
{
    $candidates = array_values(array_filter($rows, 'printflow_pos_dimension_field_candidate'));
    if (count($candidates) !== 1) return null;
    $row = $candidates[0];
    $type = strtolower(trim((string)($row['field_type'] ?? $row['type'] ?? '')));
    $key = trim((string)($row['field_key'] ?? ''));
    $options = $row['_options'] ?? $row['options'] ?? [];
    $units = [];
    foreach (is_array($options) ? $options : [] as $option) {
        $value = trim((string)(is_array($option) ? ($option['value'] ?? '') : $option));
        if ($value === '' || in_array(strtolower($value), ['others', 'custom size'], true)) continue;
        if (printflow_pos_dimension_pair_from_option($value) === null) {
            if ($type !== 'dimension') return null;
            continue;
        }
        $unit = printflow_pos_dimension_option_unit($value, $row);
        if ($unit === '') return null;
        $units[$unit] = true;
    }
    // The legacy dimension-button renderer carries only width/height values,
    // not each option's unit. It can safely use only one resolved unit.
    if ($type === 'dimension' && count($units) > 1) return null;
    if ($units === [] && ($type === 'dimension' || !empty($row['allow_others']))) {
        $unit = printflow_pos_dimension_configured_unit($row);
        if ($unit !== '') $units[$unit] = true;
    }
    if ($units === []) return null;
    $contextUnit = count($units) === 1 ? (string)array_key_first($units) : '';
    return ['field_key' => $key, 'field_type' => $type, 'unit' => $contextUnit, 'row' => $row];
}

/** Resolve a selected preset using the saved option value; client dimensions are never authoritative. */
function printflow_pos_resolve_service_dimension_selection(array $rows, array $customization): array
{
    $descriptor = printflow_pos_service_dimension_descriptor($rows);
    if ($descriptor === null) throw new RuntimeException('Configure one visible dimension/size field with physical dimension options and a valid unit before using area-based materials.');
    $key = $descriptor['field_key'];
    $row = $descriptor['row'];
    if ($descriptor['field_type'] === 'dimension') {
        $width = trim((string)($customization['width'] ?? $customization[$key . '_width'] ?? ''));
        $height = trim((string)($customization['height'] ?? $customization[$key . '_height'] ?? ''));
        if (!is_numeric($width) || !is_numeric($height) || (float)$width <= 0 || (float)$height <= 0) {
            throw new RuntimeException('Select a configured dimension or enter valid custom width and height before checking material stock.');
        }
        if ($descriptor['unit'] === '') throw new RuntimeException('The configured dimension field has no valid measurement unit.');
        return ['width' => $width, 'height' => $height, 'unit' => $descriptor['unit'], 'field_key' => $key, 'selected_value' => ''];
    }
    $selected = trim((string)($customization[$key] ?? ''));
    $options = $row['_options'] ?? $row['options'] ?? [];
    if ($selected !== '' && !in_array(strtolower($selected), ['others', 'custom size'], true)) {
        foreach (is_array($options) ? $options : [] as $option) {
            $value = trim((string)(is_array($option) ? ($option['value'] ?? '') : $option));
            if ($value !== $selected) continue;
            $pair = printflow_pos_dimension_pair_from_option($value);
            if ($pair === null) throw new RuntimeException('The selected size option does not contain physical width and height.');
            $unit = printflow_pos_dimension_option_unit($value, $row);
            if ($unit === '') throw new RuntimeException('The selected size option has no valid measurement unit.');
            return $pair + ['unit' => $unit, 'field_key' => $key, 'selected_value' => $selected];
        }
        throw new RuntimeException('The selected size is not present in the active service field configuration.');
    }

    $width = trim((string)($customization['width'] ?? $customization[$key . '_width'] ?? ''));
    $height = trim((string)($customization['height'] ?? $customization[$key . '_height'] ?? ''));
    if (!is_numeric($width) || !is_numeric($height) || (float)$width <= 0 || (float)$height <= 0) {
        throw new RuntimeException('Select a configured size or enter valid custom width and height before checking material stock.');
    }
    $unit = $descriptor['unit'];
    if ($unit === '') {
        $postedUnit = trim((string)($customization[$key . '_unit'] ?? $customization['pos_material_dimension_unit'] ?? ''));
        $unit = match (strtolower($postedUnit)) {
            'ft', 'feet', 'foot' => 'ft',
            'in', 'inch', 'inches', '"' => 'in',
            'cm' => 'cm',
            'mm' => 'mm',
            default => '',
        };
        if ($unit === '') {
            throw new RuntimeException('Select a valid unit for the custom dimensions.');
        }
    }
    return ['width' => $width, 'height' => $height, 'unit' => $unit, 'field_key' => $key, 'selected_value' => 'Others'];
}
