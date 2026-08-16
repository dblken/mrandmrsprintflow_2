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
        'branch', 'branch_id', 'service_id', 'customization_id', 'order_id', 'order_item_id',
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
        'size' => ['size', 'Size', 30],
        'sizes' => ['size', 'Size', 30],
        'dimension' => ['size', 'Size', 30],
        'dimensions' => ['size', 'Size', 30],
        'tarp_size' => ['size', 'Size', 30],
        'needed_date' => ['needed_date', 'Needed Date', 40],
        'date_needed' => ['needed_date', 'Needed Date', 40],
        'need_date' => ['needed_date', 'Needed Date', 40],
        'due_date' => ['needed_date', 'Needed Date', 40],
        'notes' => ['notes', 'Notes', 50],
        'additional_notes' => ['notes', 'Notes', 50],
        'special_instructions' => ['notes', 'Notes', 50],
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

    foreach ($customization as $key => $value) {
        if (!is_string($key)) continue;
        $meta = printflow_customization_field_meta($key);
        if ($meta['hidden']) continue;
        if ($meta['group'] === 'service' && !$includeService) continue;
        if ($meta['group'] === 'notes' && !$includeNotes) continue;
        if ($meta['group'] === 'quantity' && !$includeQuantity) continue;
        if ($meta['design'] && !$includeDesign) continue;
        $text = printflow_customization_value_text($value);
        if ($text === '' || stripos($text, 'data:') === 0 || in_array(strtolower($text), ['none', 'no'], true)) continue;
        if ($meta['design']) $text = basename(str_replace('\\', '/', $text));
        if ($meta['group'] === 'needed_date' && preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) {
            $stamp = strtotime(substr($text, 0, 10));
            if ($stamp !== false) $text = date('M j, Y', $stamp);
        }
        $fingerprint = $meta['group'] . '|' . printflow_customization_value_fingerprint($text);
        if (isset($seen[$fingerprint])) continue;
        $seen[$fingerprint] = true;
        $label = $meta['label'];
        if (isset($rows[$label]) && printflow_customization_value_fingerprint($rows[$label]['value']) !== printflow_customization_value_fingerprint($text)) {
            $label = ucwords(str_replace('_', ' ', printflow_customization_key_token($key)));
            if (isset($rows[$label])) $label .= ' 2';
        }
        $rows[$label] = ['value' => $text, 'priority' => $meta['priority'], 'position' => count($rows)];
    }

    uasort($rows, static fn($a, $b) => [$a['priority'], $a['position']] <=> [$b['priority'], $b['position']]);
    return array_map(static fn($row) => $row['value'], $rows);
}
