<?php

/** Normalize optional inventory material specification fields without changing stock semantics. */
function printflow_inventory_material_configuration(array $input): array
{
    $allowedFormats = ['', 'piece', 'sheet', 'roll', 'liquid_other'];
    $allowedMethods = ['', 'per_piece', 'sheet_yield', 'dimension_sheet', 'by_length', 'by_area', 'manual'];
    $allowedUnits = ['', 'mm', 'cm', 'in', 'ft'];
    $errors = [];
    foreach (['size_variant', 'material_format', 'material_width', 'material_height', 'material_dimension_unit', 'consumption_method', 'designs_per_unit', 'usable_margin_width', 'usable_margin_height', 'wastage_percent'] as $field) {
        if (array_key_exists($field, $input) && !is_scalar($input[$field]) && $input[$field] !== null) {
            $errors[$field] = 'A single scalar value is required.';
            $input[$field] = '';
        }
    }
    $config = [
        'size_variant' => trim((string)($input['size_variant'] ?? '')),
        'material_format' => strtolower(trim((string)($input['material_format'] ?? ''))),
        'material_width' => null,
        'material_height' => null,
        'material_dimension_unit' => strtolower(trim((string)($input['material_dimension_unit'] ?? ''))),
        'consumption_method' => strtolower(trim((string)($input['consumption_method'] ?? ''))),
        'designs_per_unit' => null,
        'usable_margin_width' => null,
        'usable_margin_height' => null,
        'wastage_percent' => null,
    ];
    if (strlen($config['size_variant']) > 100) $errors['size_variant'] = 'Size / Variant must be 100 characters or fewer.';
    if (!in_array($config['material_format'], $allowedFormats, true)) $errors['material_format'] = 'Select a supported material format.';
    if (!in_array($config['consumption_method'], $allowedMethods, true)) $errors['consumption_method'] = 'Select a supported consumption method.';
    if (!in_array($config['material_dimension_unit'], $allowedUnits, true)) $errors['material_dimension_unit'] = 'Select a supported dimension unit.';

    foreach (['material_width', 'material_height', 'usable_margin_width', 'usable_margin_height', 'wastage_percent'] as $field) {
        $raw = trim((string)($input[$field] ?? ''));
        if ($raw === '') continue;
        if (!is_numeric($raw) || !is_finite((float)$raw)) {
            $errors[$field] = 'Enter a finite numeric value.';
            continue;
        }
        $number = (float)$raw;
        if ($field === 'wastage_percent') {
            if ($number < 0 || $number > 100) $errors[$field] = 'Wastage must be from 0 to 100 percent.';
        } elseif ($field === 'material_width' || $field === 'material_height') {
            if ($number <= 0) $errors[$field] = 'Material dimensions must be greater than zero.';
        } elseif ($number < 0) {
            $errors[$field] = 'Margins cannot be negative.';
        }
        $config[$field] = $number;
    }

    $yieldRaw = trim((string)($input['designs_per_unit'] ?? ''));
    if ($yieldRaw !== '') {
        if (!preg_match('/^[1-9][0-9]*$/', $yieldRaw) || (float)$yieldRaw > 1000000) {
            $errors['designs_per_unit'] = 'Designs per Sheet / Unit must be a whole number from 1 to 1,000,000.';
        } else {
            $config['designs_per_unit'] = (int)$yieldRaw;
        }
    }

    if ($config['consumption_method'] === 'sheet_yield' && $config['designs_per_unit'] === null) {
        $errors['designs_per_unit'] = 'Enter a verified designs-per-sheet yield.';
    }
    if (in_array($config['consumption_method'], ['sheet_yield', 'dimension_sheet'], true) && $config['material_format'] !== 'sheet') {
        $errors['material_format'] = 'Sheet consumption methods require Material Format = Sheet.';
    }
    if ($config['consumption_method'] === 'by_length' && strtolower(trim((string)($input['unit'] ?? ''))) !== 'ft') {
        $errors['consumption_method'] = 'By Length estimates require inventory UOM = ft.';
    }
    if ($config['consumption_method'] === 'dimension_sheet') {
        if ($config['material_format'] !== 'sheet') $errors['material_format'] = 'Dimension-based sheet fit requires Material Format = Sheet.';
        if ($config['material_width'] === null || $config['material_height'] === null || $config['material_dimension_unit'] === '') {
            $errors['material_width'] = 'Enter sheet width, height, and dimension unit for dimension-based estimates.';
        }
    }
    if ($config['material_dimension_unit'] === '' && ($config['material_width'] !== null || $config['material_height'] !== null)) {
        $errors['material_dimension_unit'] = 'Choose a dimension unit for the configured dimensions.';
    }
    return ['config' => $config, 'errors' => $errors];
}
