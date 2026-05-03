<?php
/**
 * Service Field Configuration Helper
 * Extracts and manages dynamic field configurations for services
 */

/**
 * Extract field structure from a customer order page
 * Analyzes the HTML/PHP to detect all form fields
 */
function extract_service_fields_from_page($service_link) {
    $fields = [];
    
    // Map of service links to their field structures
    $field_map = [
        'order_tarpaulin.php' => [
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'select', 'required' => true, 'order' => 1],
            ['key' => 'dimensions', 'label' => 'Size (ft)', 'type' => 'dimension', 'required' => true, 'order' => 2,
             'options' => ['3×4', '4×6', '5×8', '6×8', 'Others']],
            ['key' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'required' => true, 'order' => 3,
             'options' => ['Matte', 'Glossy']],
            ['key' => 'lamination', 'label' => 'Laminate', 'type' => 'radio', 'required' => true, 'order' => 4,
             'options' => ['With Laminate', 'Without Laminate']],
            ['key' => 'eyelets', 'label' => 'Eyelets', 'type' => 'radio', 'required' => true, 'order' => 5,
             'options' => ['Yes', 'No']],
            ['key' => 'design_file', 'label' => 'Design', 'type' => 'file', 'required' => true, 'order' => 6],
            ['key' => 'layout', 'label' => 'Layout', 'type' => 'radio', 'required' => true, 'order' => 7,
             'options' => ['With Layout', 'Without Layout']]
        ],
        'order_tshirt.php' => [
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'select', 'required' => true, 'order' => 1],
            ['key' => 'size', 'label' => 'Size', 'type' => 'radio', 'required' => true, 'order' => 2,
             'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
            ['key' => 'color', 'label' => 'Color', 'type' => 'radio', 'required' => true, 'order' => 3,
             'options' => ['White', 'Black', 'Gray', 'Navy', 'Red']],
            ['key' => 'design_file', 'label' => 'Design', 'type' => 'file', 'required' => true, 'order' => 4]
        ],
        'order_stickers.php' => [
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'select', 'required' => true, 'order' => 1],
            ['key' => 'type', 'label' => 'Sticker Type', 'type' => 'radio', 'required' => true, 'order' => 2,
             'options' => ['Vinyl', 'Paper', 'Transparent']],
            ['key' => 'dimensions', 'label' => 'Size', 'type' => 'dimension', 'required' => true, 'order' => 3,
             'options' => ['2×2', '3×3', '4×4', 'Others']],
            ['key' => 'design_file', 'label' => 'Design', 'type' => 'file', 'required' => true, 'order' => 4]
        ]
    ];
    
    // Get service-specific fields or empty array
    $serviceFields = $field_map[$service_link] ?? [];
    
    // Always ensure these default required fields exist at the bottom
    $defaultFields = [
        'needed_date' => ['key' => 'needed_date', 'label' => 'Needed Date', 'type' => 'date', 'required' => true],
        'quantity' => ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'quantity', 'required' => true],
        'notes' => ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false]
    ];
    
    // Remove default fields from existing list if they exist (we'll add them at the end)
    $serviceFields = array_filter($serviceFields, function($field) {
        return !in_array($field['key'], ['needed_date', 'quantity', 'notes']);
    });
    
    // Reorder remaining fields
    $serviceFields = array_values($serviceFields);
    foreach ($serviceFields as $idx => $field) {
        $serviceFields[$idx]['order'] = $idx + 1;
    }
    
    // Add default fields at the bottom
    $maxOrder = empty($serviceFields) ? 0 : max(array_column($serviceFields, 'order'));
    foreach ($defaultFields as $key => $field) {
        $field['order'] = ++$maxOrder;
        $serviceFields[] = $field;
    }
    
    return $serviceFields;
}

/**
 * Get field configuration for a service
 */
function get_service_field_config($service_id) {
    $configs = db_query(
        "SELECT * FROM service_field_configs WHERE service_id = ? ORDER BY display_order ASC",
        'i',
        [$service_id]
    );
    
    $result = [];
    foreach ($configs as $config) {
        $result[$config['field_key']] = [
            'label' => $config['field_label'],
            'type' => $config['field_type'],
            'options' => $config['field_options'] ? json_decode($config['field_options'], true) : null,
            'visible' => (bool)$config['is_visible'],
            'required' => (bool)$config['is_required'],
            'default' => $config['default_value'],
            'unit' => $config['unit'] ?? 'ft',
            'allow_others' => isset($config['allow_others']) ? (bool)$config['allow_others'] : true,
            'order' => (int)$config['display_order'],
            'parent_field_key' => $config['parent_field_key'] ?? null,
            'parent_value' => $config['parent_value'] ?? null
        ];
    }
    
    return $result;
}

/**
 * Save field configuration for a service
 */
function save_service_field_config($service_id, $field_key, $config) {
    $existing = db_query(
        "SELECT config_id FROM service_field_configs WHERE service_id = ? AND field_key = ?",
        'is',
        [$service_id, $field_key]
    );
    
    $options_json = isset($config['options']) ? json_encode($config['options']) : null;
    $unit = $config['unit'] ?? 'ft';
    $allow_others = array_key_exists('allow_others', $config)
        ? ($config['allow_others'] ? 1 : 0)
        : 1;
    
    if (!empty($existing)) {
        db_execute(
            "UPDATE service_field_configs SET 
                field_label = ?, 
                field_type = ?, 
                field_options = ?, 
                is_visible = ?, 
                is_required = ?, 
                default_value = ?, 
                unit = ?,
                allow_others = ?,
                display_order = ?,
                parent_field_key = ?,
                parent_value = ?,
                updated_at = NOW()
            WHERE service_id = ? AND field_key = ?",
            'sssiissiissis',
            [
                $config['label'],
                $config['type'],
                $options_json,
                $config['visible'] ? 1 : 0,
                $config['required'] ? 1 : 0,
                $config['default'] ?? null,
                $unit,
                $allow_others,
                $config['order'] ?? 0,
                $config['parent_field_key'] ?? null,
                $config['parent_value'] ?? null,
                $service_id,
                $field_key
            ]
        );
    } else {
        db_execute(
            "INSERT INTO service_field_configs 
                (service_id, field_key, field_label, field_type, field_options, is_visible, is_required, default_value, unit, allow_others, display_order, parent_field_key, parent_value) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'issssiissiiss',
            [
                $service_id,
                $field_key,
                $config['label'],
                $config['type'],
                $options_json,
                $config['visible'] ? 1 : 0,
                $config['required'] ? 1 : 0,
                $config['default'] ?? null,
                $unit,
                $allow_others,
                $config['order'] ?? 0,
                $config['parent_field_key'] ?? null,
                $config['parent_value'] ?? null
            ]
        );
    }
}

/**
 * Initialize default field configuration from existing page structure
 */
function init_service_field_config($service_id, $service_link) {
    $fields = extract_service_fields_from_page($service_link);
    
    // Always ensure branch field exists as first field
    $hasBranch = false;
    foreach ($fields as $field) {
        if ($field['key'] === 'branch') {
            $hasBranch = true;
            break;
        }
    }
    
    if (!$hasBranch) {
        array_unshift($fields, [
            'key' => 'branch',
            'label' => 'Branch',
            'type' => 'select',
            'required' => true,
            'order' => 0
        ]);
        // Reorder other fields
        foreach ($fields as $idx => $field) {
            if ($field['key'] !== 'branch') {
                $fields[$idx]['order'] = $idx;
            }
        }
    }
    
    foreach ($fields as $field) {
        save_service_field_config($service_id, $field['key'], [
            'label' => $field['label'],
            'type' => $field['type'],
            'options' => $field['options'] ?? null,
            'visible' => true,
            'required' => $field['required'],
            'default' => null,
            'order' => $field['order']
        ]);
    }
}

/**
 * Check if service has field configuration
 */
function service_has_field_config($service_id) {
    $count = db_query(
        "SELECT COUNT(*) as cnt FROM service_field_configs WHERE service_id = ?",
        'i',
        [$service_id]
    );
    return ($count[0]['cnt'] ?? 0) > 0;
}

/**
 * Delete all field configurations for a service
 */
function delete_service_field_config($service_id) {
    db_execute(
        "DELETE FROM service_field_configs WHERE service_id = ?",
        'i',
        [$service_id]
    );
}
