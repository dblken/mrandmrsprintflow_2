<?php
/**
 * Dynamic Service Form Helper Functions
 * Safely checks for and loads dynamic form configurations
 */

/**
 * Check if a product has an active dynamic form
 * @param int $product_id
 * @return array|null Returns config data if active form exists, null otherwise
 */
function get_active_service_form($product_id) {
    try {
        $config = db_query(
            "SELECT sfc.*, p.name as service_name, p.base_price 
             FROM service_form_configs sfc 
             JOIN products p ON sfc.product_id = p.product_id 
             WHERE sfc.product_id = ? AND sfc.is_active = 1",
            'i',
            [$product_id]
        );
        
        if (empty($config)) {
            return null;
        }
        
        return $config[0];
    } catch (Exception $e) {
        error_log("Error checking for dynamic form: " . $e->getMessage());
        return null;
    }
}

/**
 * Get form steps for a configuration
 * @param int $config_id
 * @return array
 */
function get_form_steps($config_id) {
    try {
        return db_query(
            "SELECT * FROM service_form_steps WHERE config_id = ? ORDER BY step_number",
            'i',
            [$config_id]
        ) ?: [];
    } catch (Exception $e) {
        error_log("Error loading form steps: " . $e->getMessage());
        return [];
    }
}

/**
 * Get form fields for a configuration
 * @param int $config_id
 * @return array
 */
function get_form_fields($config_id) {
    try {
        return db_query(
            "SELECT * FROM service_form_fields WHERE config_id = ? ORDER BY step_number, display_order",
            'i',
            [$config_id]
        ) ?: [];
    } catch (Exception $e) {
        error_log("Error loading form fields: " . $e->getMessage());
        return [];
    }
}

/**
 * Render a dynamic form field
 * @param array $field Field configuration
 * @return string HTML output
 */
function render_dynamic_field($field) {
    $required = $field['is_required'] ? 'required' : '';
    $label = htmlspecialchars($field['field_label']);
    $name = htmlspecialchars($field['field_name']);
    $help = $field['help_text'] ? '<p class="text-xs text-gray-500 mt-1">' . htmlspecialchars($field['help_text']) . '</p>' : '';
    
    $html = '<div class="mb-4">';
    $html .= '<label class="block text-sm font-bold text-gray-900 mb-2 uppercase">';
    $html .= $label;
    if ($field['is_required']) {
        $html .= ' <span class="text-red-500">*</span>';
    }
    $html .= '</label>';
    
    switch ($field['field_type']) {
        case 'text':
            $html .= '<input type="text" name="' . $name . '" class="form-input w-full" ' . $required . '>';
            break;
            
        case 'number':
            $html .= '<input type="number" name="' . $name . '" class="form-input w-full" step="0.01" ' . $required . '>';
            break;
            
        case 'textarea':
            $html .= '<textarea name="' . $name . '" class="form-input w-full" rows="3" ' . $required . '></textarea>';
            break;
            
        case 'select':
            $options = $field['options_json'] ? json_decode($field['options_json'], true) : [];
            $html .= '<select name="' . $name . '" class="form-input w-full" ' . $required . '>';
            $placeholder = preg_match('/upload\s+design|design\s+upload/i', (string)$field['field_label'])
                ? 'Select Upload Design Option'
                : 'Select ' . $label;
            $html .= '<option value="" selected disabled>' . $placeholder . '</option>';
            foreach ($options as $option) {
                $html .= '<option value="' . htmlspecialchars($option) . '">' . htmlspecialchars($option) . '</option>';
            }
            $html .= '</select>';
            break;
            
        case 'radio':
            $options = $field['options_json'] ? json_decode($field['options_json'], true) : [];
            $html .= '<div class="space-y-2">';
            foreach ($options as $option) {
                $html .= '<label class="flex items-center">';
                $html .= '<input type="radio" name="' . $name . '" value="' . htmlspecialchars($option) . '" class="mr-2" ' . $required . '>';
                $html .= '<span>' . htmlspecialchars($option) . '</span>';
                $html .= '</label>';
            }
            $html .= '</div>';
            break;
            
        case 'checkbox':
            $options = $field['options_json'] ? json_decode($field['options_json'], true) : [];
            $html .= '<div class="space-y-2">';
            foreach ($options as $option) {
                $html .= '<label class="flex items-center">';
                $html .= '<input type="checkbox" name="' . $name . '[]" value="' . htmlspecialchars($option) . '" class="mr-2">';
                $html .= '<span>' . htmlspecialchars($option) . '</span>';
                $html .= '</label>';
            }
            $html .= '</div>';
            break;
            
        case 'file':
            $html .= '<input type="file" name="' . $name . '" class="form-input w-full" ' . $required . '>';
            break;
    }
    
    $html .= $help;
    $html .= '</div>';
    
    return $html;
}
