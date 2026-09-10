<?php

/**
 * DB-free normalization shared by order-detail and receipt renderers.
 * It intentionally keeps differing values, while collapsing aliases that carry
 * the same value and hiding storage/transport metadata from business views.
 */

function printflow_customization_key_token(string $key): string {
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
}

function printflow_customization_value_text($value): string {
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $entry) {
            if (is_scalar($entry) && trim((string)$entry) !== '') $parts[] = trim((string)$entry);
        }
        return implode(', ', $parts);
    }
    return is_scalar($value) ? trim((string)$value) : '';
}

function printflow_customization_value_fingerprint(string $value): string {
    $value = preg_replace('/\s+/', ' ', trim($value));
    return strtolower((string)$value);
}

function printflow_customization_semantic_value_fingerprint(string $group, string $value): string {
    $fingerprint = printflow_customization_value_fingerprint($value);
    if (in_array($group, ['width', 'height'], true)) {
        $fingerprint = trim((string)preg_replace('/\s*(?:ft|feet|foot)\.?$/i', '', $fingerprint));
    } elseif ($group === 'total_area') {
        $fingerprint = trim((string)preg_replace('/\s*(?:sq\.?\s*ft|sqft|square\s*feet)\.?$/i', '', $fingerprint));
    } elseif ($group === 'quantity') {
        $fingerprint = trim((string)preg_replace('/\s*(?:pcs?|pieces?)\.?$/i', '', $fingerprint));
    } elseif ($group === 'dimensions') {
        $fingerprint = str_replace(['×', '*'], 'x', $fingerprint);
        $fingerprint = (string)preg_replace('/\s*x\s*/i', 'x', $fingerprint);
        $fingerprint = (string)preg_replace('/\s*(?:feet|foot)\.?$/i', ' ft', $fingerprint);
    }
    if (is_numeric($fingerprint)) {
        $fingerprint = rtrim(rtrim(number_format((float)$fingerprint, 6, '.', ''), '0'), '.');
    }
    return $fingerprint;
}

/** Remove duplicate label aliases from newly persisted customization JSON. */
function printflow_customization_normalize_storage(array $customization): array {
    if (isset($customization['branch_id'])) unset($customization['branch'], $customization['Branch']);

    $seen = [];
    $out = [];
    foreach ($customization as $key => $value) {
        if (!is_string($key)) {
            $out[$key] = $value;
            continue;
        }
        $token = printflow_customization_key_token($key);
        $text = printflow_customization_value_text($value);
        $fingerprint = $token . '|' . printflow_customization_value_fingerprint($text);
        if ($token !== '' && isset($seen[$fingerprint])) continue;
        if ($token !== '') $seen[$fingerprint] = true;
        $out[$key] = $value;
    }
    return $out;
}

/** @return array{group:string,label:string,priority:int,hidden:bool,design:bool} */
function printflow_customization_field_meta(string $key): array {
    $token = printflow_customization_key_token($key);
    $hidden = [
        'branch', 'branch_id', 'branch_name', 'branchname', 'pickup_branch', 'pickupbranch', 'service_id', 'customization_id', 'order_id', 'order_item_id',
        'product_id', 'config_id', 'source', 'source_page', 'form_type', 'cart_key',
        'design_upload_path', 'design_file', 'design_mime', 'design_upload_mime',
        'design_image', 'design_image_path',
        'reference_upload', 'reference_upload_name', 'upload_reference',
        'reference_upload_path', 'reference_file', 'reference_mime', 'reference_upload_mime',
        'design_data', 'reference_data', 'design_blob', 'reference_blob',
        'design_upload_data', 'reference_upload_data', 'design_tmp_path', 'reference_tmp_path'
    ];
    $looksInternal = str_ends_with($token, '_id')
        || str_contains($token, '_mime')
        || str_contains($token, '_blob')
        || str_contains($token, '_tmp_path')
        || str_ends_with($token, '_path');
    if ($token === '' || $token[0] === '_' || $looksInternal || in_array($token, $hidden, true)) {
        return ['group' => $token, 'label' => '', 'priority' => 999, 'hidden' => true, 'design' => false];
    }

    $map = [
        'service_type' => ['service', 'Service', 10],
        'product_type' => ['service', 'Service', 10],
        'layout' => ['layout', 'Layout', 20],
        'layout_option' => ['layout', 'Layout', 20],
        'layoutoption' => ['layout', 'Layout', 20],
        'selected_layout' => ['layout', 'Layout', 20],
        'selectedlayout' => ['layout', 'Layout', 20],
        'layout_selected' => ['layout', 'Layout', 20],
        'width' => ['width', 'Width', 30],
        'width_ft' => ['width', 'Width', 30],
        'widthft' => ['width', 'Width', 30],
        'height' => ['height', 'Height', 31],
        'height_ft' => ['height', 'Height', 31],
        'heightft' => ['height', 'Height', 31],
        'size' => ['dimensions', 'Size', 32],
        'sizes' => ['dimensions', 'Size', 32],
        'dimension' => ['dimensions', 'Size', 32],
        'dimensions' => ['dimensions', 'Size', 32],
        'dimensions_ft' => ['dimensions', 'Size', 32],
        'dimensionsft' => ['dimensions', 'Size', 32],
        'dimension_ft' => ['dimensions', 'Size', 32],
        'tarp_size' => ['dimensions', 'Size', 32],
        'total_sqft' => ['total_area', 'Total Area', 33],
        'totalsqft' => ['total_area', 'Total Area', 33],
        'total_sq_ft' => ['total_area', 'Total Area', 33],
        'total_area' => ['total_area', 'Total Area', 33],
        'area_sqft' => ['total_area', 'Total Area', 33],
        'areasqft' => ['total_area', 'Total Area', 33],
        'needed_date' => ['needed_date', 'Needed Date', 40],
        'neededdate' => ['needed_date', 'Needed Date', 40],
        'date_needed' => ['needed_date', 'Needed Date', 40],
        'dateneeded' => ['needed_date', 'Needed Date', 40],
        'need_date' => ['needed_date', 'Needed Date', 40],
        'due_date' => ['needed_date', 'Needed Date', 40],
        'notes' => ['notes', 'Notes', 50],
        'additional_notes' => ['notes', 'Notes', 50],
        'special_instructions' => ['notes', 'Notes', 50],
        'job_notes' => ['notes', 'Notes', 50],
        'jobnotes' => ['notes', 'Notes', 50],
        'order_notes' => ['notes', 'Notes', 50],
        'ordernotes' => ['notes', 'Notes', 50],
        'customer_notes' => ['notes', 'Notes', 50],
        'customernotes' => ['notes', 'Notes', 50],
        'material' => ['material', 'Material', 25],
        'material_type' => ['material', 'Material', 25],
        'temp_plate_material' => ['material', 'Material', 25],
        'material_selection' => ['material', 'Material', 25],
        'design_upload' => ['uploaded_design', 'Uploaded Design', 60],
        'design_upload_name' => ['uploaded_design', 'Uploaded Design', 60],
        'upload_design' => ['uploaded_design', 'Uploaded Design', 60],
        'upload_design_name' => ['uploaded_design', 'Uploaded Design', 60],
        'design_filename' => ['uploaded_design', 'Uploaded Design', 60],
        'design_file_name' => ['uploaded_design', 'Uploaded Design', 60],
        'uploaded_design_name' => ['uploaded_design', 'Uploaded Design', 60],
        'uploaded_design' => ['uploaded_design', 'Uploaded Design', 60],
        'quantity' => ['quantity', 'Quantity', 5],
        'qty' => ['quantity', 'Quantity', 5],
        'print_type' => ['print_type', 'Print Type', 45],
        'printed_type' => ['print_type', 'Print Type', 45],
        'printtype' => ['print_type', 'Print Type', 45],
        'printedtype' => ['print_type', 'Print Type', 45],
        'sticker_type' => ['sticker_type', 'Sticker Type', 21],
        'stickers_type' => ['sticker_type', 'Sticker Type', 21],
        'sticker_type_size' => ['sticker_size', 'Sticker Size', 22],
        'stickers_type_size' => ['sticker_size', 'Sticker Size', 22],
    ];
    if (isset($map[$token])) {
        [$group, $label, $priority] = $map[$token];
        return ['group' => $group, 'label' => $label, 'priority' => $priority, 'hidden' => false, 'design' => $group === 'uploaded_design'];
    }
    $label = ucwords(str_replace('_', ' ', $token));
    return ['group' => $token, 'label' => $label, 'priority' => 100, 'hidden' => false, 'design' => false];
}

/**
 * @param array{include_service?:bool,include_design?:bool,include_notes?:bool,include_quantity?:bool} $options
 * @return array<string,string>
 */
function printflow_customization_display_specs(array $customization, array $options = []): array {
    $includeService = (bool)($options['include_service'] ?? true);
    $includeDesign = (bool)($options['include_design'] ?? true);
    $includeNotes = (bool)($options['include_notes'] ?? true);
    $includeQuantity = (bool)($options['include_quantity'] ?? false);
    $rows = [];
    $seen = [];
    $seenValues = [];
    $presentGroups = [];
    foreach ($customization as $key => $value) {
        if (!is_string($key)) continue;
        $probeText = printflow_customization_value_text($value);
        if ($probeText === '') continue;
        $probe = printflow_customization_field_meta($key);
        if (in_array($probe['group'], ['width', 'height', 'total_area'], true) && is_numeric($probeText) && abs((float)$probeText) < 0.000001) continue;
        $presentGroups[$probe['group']] = true;
    }

    foreach ($customization as $key => $value) {
        if (!is_string($key)) continue;
        $meta = printflow_customization_field_meta($key);
        if ($meta['hidden']) continue;
        if ($meta['group'] === 'service' && !$includeService) continue;
        if ($meta['group'] === 'notes' && !$includeNotes) continue;
        if ($meta['group'] === 'quantity' && !$includeQuantity) continue;
        if ($meta['design'] && !$includeDesign) continue;
        if (
            $meta['group'] === 'dimensions'
            && !empty($presentGroups['width'])
            && !empty($presentGroups['height'])
            && $key !== 'Dimensions'
            && $key !== 'Size / Dimensions'
        ) continue;
        if ($meta['group'] === 'total_area' && (!empty($presentGroups['dimensions']) || (!empty($presentGroups['width']) && !empty($presentGroups['height'])))) continue;
        $text = printflow_customization_value_text($value);
        if ($text === '' || stripos($text, 'data:') === 0 || in_array(strtolower($text), ['none', 'no'], true)) continue;
        if (in_array($meta['group'], ['width', 'height', 'total_area'], true) && is_numeric($text) && abs((float)$text) < 0.000001) continue;
        if ($meta['design']) $text = basename(str_replace('\\', '/', $text));
        $token = printflow_customization_key_token($key);
        if (in_array($meta['group'], ['width', 'height', 'total_area'], true) && is_numeric($text)) {
            $text = rtrim(rtrim(number_format((float)$text, 6, '.', ''), '0'), '.');
        }
        if (in_array($token, ['width_ft', 'widthft', 'height_ft', 'heightft'], true) && is_numeric($text)) $text .= ' ft';
        if (in_array($token, ['total_sqft', 'totalsqft', 'total_sq_ft', 'area_sqft', 'areasqft'], true) && is_numeric($text)) $text .= ' sq ft';
        if ($meta['group'] === 'needed_date' && preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) {
            $stamp = strtotime(substr($text, 0, 10));
            if ($stamp !== false) $text = date('M j, Y', $stamp);
        }
        $fingerprint = $meta['group'] . '|' . printflow_customization_semantic_value_fingerprint($meta['group'], $text);
        if (isset($seen[$fingerprint])) {
            $existingLabel = $seen[$fingerprint];
            if (isset($rows[$existingLabel]) && (str_ends_with($token, '_ft') || str_ends_with($token, 'ft') || str_contains($token, 'sqft'))) {
                $rows[$existingLabel]['value'] = $text;
            }
            continue;
        }
        $valueFingerprint = str_replace(['×', '*'], 'x', printflow_customization_value_fingerprint($text));
        $valueFingerprint = (string)preg_replace('/\s*x\s*/i', 'x', $valueFingerprint);
        $relationToken = (string)preg_replace('/(^|_)stickers(?=_|$)/', '$1sticker', $token);
        $relationToken = (string)preg_replace('/_(?:selected|selection|option|value|label)$/', '', $relationToken);
        $relatedDuplicate = false;
        foreach ($seenValues[$valueFingerprint] ?? [] as $previousToken) {
            $previousRelation = (string)preg_replace('/(^|_)stickers(?=_|$)/', '$1sticker', $previousToken);
            $previousRelation = (string)preg_replace('/_(?:selected|selection|option|value|label)$/', '', $previousRelation);
            if (
                $relationToken === $previousRelation
                || str_starts_with($relationToken, $previousRelation . '_')
                || str_starts_with($previousRelation, $relationToken . '_')
            ) {
                $relatedDuplicate = true;
                break;
            }
        }
        if ($relatedDuplicate) continue;
        $label = $meta['group'] === 'notes' ? 'Notes' : $meta['label'];
        if (isset($rows[$label]) && printflow_customization_semantic_value_fingerprint($meta['group'], $rows[$label]['value']) !== printflow_customization_semantic_value_fingerprint($meta['group'], $text)) {
            $label = ucwords(str_replace('_', ' ', printflow_customization_key_token($key)));
            if (isset($rows[$label])) $label .= ' 2';
        }
        $rows[$label] = ['value' => $text, 'priority' => $meta['priority'], 'position' => count($rows)];
        $seen[$fingerprint] = $label;
        $seenValues[$valueFingerprint][] = $token;
    }

    uasort($rows, static fn($a, $b) => [$a['priority'], $a['position']] <=> [$b['priority'], $b['position']]);
    return array_map(static fn($row) => $row['value'], $rows);
}

/**
 * Resolve structured width/height from customization payloads.
 *
 * @return array{width:string,height:string}
 */
function printflow_resolve_customization_dimensions($custom): array
{
    $custom = is_string($custom) ? json_decode($custom, true) : $custom;
    if (!is_array($custom)) {
        $custom = [];
    }

    $normalizeKey = static function ($key) {
        $key = strtolower(trim((string)$key));
        $key = str_replace(['-', '_'], ' ', $key);
        return preg_replace('/\s+/', ' ', $key);
    };

    $firstValue = static function (array $source, array $candidates) use ($normalizeKey) {
        $wanted = [];
        foreach ($candidates as $candidate) {
            $wanted[$normalizeKey($candidate)] = true;
        }
        foreach ($source as $key => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }
            if (isset($wanted[$normalizeKey($key)])) {
                return $value;
            }
        }
        return null;
    };

    $formatScalar = static function ($value): string {
        if ($value === null) {
            return '';
        }
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $number = (float)$value;
            if (abs($number - round($number)) < 0.00001) {
                return (string)(int)round($number);
            }
            return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        }
        return $value;
    };

    $width = $formatScalar($firstValue($custom, ['width_ft', 'width']));
    $height = $formatScalar($firstValue($custom, ['height_ft', 'height']));

    foreach ($custom as $key => $value) {
        if (!is_scalar($value) || trim((string)$value) === '') {
            continue;
        }
        $token = printflow_customization_key_token((string)$key);
        if (str_ends_with($token, '_width') && $width === '') {
            $width = $formatScalar($value);
        }
        if (str_ends_with($token, '_height') && $height === '') {
            $height = $formatScalar($value);
        }
    }

    $dimension_raw = $firstValue($custom, [
        'dimensions',
        'dimension',
        'size',
        'size dimensions',
        'exact size',
        'tarp size',
        'size ft',
        'size (ft)',
    ]);
    $dimension_text = trim((string)$dimension_raw);

    if (($width === '' || $height === '') && $dimension_text !== '') {
        $normalized_dimension = preg_replace('/\s*(ft|feet|in|inch|inches|cm|mm|m)\s*$/i', '', $dimension_text);
        $normalized_dimension = str_replace(['X', 'x', '*', '-'], '×', $normalized_dimension);
        if (preg_match('/(\d+(?:\.\d+)?)\s*×\s*(\d+(?:\.\d+)?)/u', $normalized_dimension, $m)) {
            if ($width === '') {
                $width = $formatScalar($m[1]);
            }
            if ($height === '') {
                $height = $formatScalar($m[2]);
            }
        }
    }

    return [
        'width' => $width,
        'height' => $height,
    ];
}
