<?php
/**
 * Dynamic Service Field Renderer
 * Renders form fields based on admin configuration
 */

require_once __DIR__ . '/service_field_config_helper.php';

function pf_format_service_time_label($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/\b(am|pm)\b/i', $value)) {
        return $value;
    }

    $formatSingle = static function ($time) {
        $time = trim((string)$time);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return null;
        }

        $hour = (int)$m[1];
        $minute = $m[2];
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $hour12 . ':' . $minute . ' ' . $suffix;
    };

    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)\s*-\s*([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
        $start = $formatSingle($m[1] . ':' . $m[2]);
        $end = $formatSingle($m[3] . ':' . $m[4]);
        if ($start !== null && $end !== null) {
            return $start . ' - ' . $end;
        }
    }

    $single = $formatSingle($value);
    return $single !== null ? $single : $value;
}

function pf_service_option_label($value) {
    return pf_format_service_time_label($value);
}

/**
 * Render a single field based on configuration
 */
function render_service_field($field_key, $config, $branches = [], $existing_data = []) {
    if (!$config['visible']) {
        return '';
    }
    
    // Extract saved values from existing_data
    $saved_value = '';
    $saved_customization = $existing_data['customization'] ?? [];
    
    // Get the label to match against customization keys
    $field_label = $config['label'];
    
    // Try to find saved value
    if ($field_key === 'branch') {
        $saved_value = $existing_data['branch_id'] ?? '';
    } elseif (($config['type'] ?? '') === 'quantity') {
        $saved_value = $existing_data[$field_key] ?? $existing_data['quantity'] ?? 2;
    } elseif (($config['type'] ?? '') === 'date') {
        $saved_value = $saved_customization[$field_key]
            ?? $saved_customization[$field_label]
            ?? ($field_key === 'needed_date' ? ($saved_customization['needed_date'] ?? '') : '');
    } elseif (($config['type'] ?? '') === 'textarea') {
        $saved_value = $saved_customization[$field_key]
            ?? $saved_customization[$field_label]
            ?? ($field_key === 'notes' ? ($saved_customization['notes'] ?? '') : '');
    } else {
        $saved_value = $saved_customization[$field_label] ?? $saved_customization[$field_key] ?? '';
    }
    
    $label = htmlspecialchars($config['label']);
    $required = $config['required'] ? ' *' : '';
    $required_attr = $config['required'] ? 'required' : '';
    
    // Add unit to label for dimension fields
    if ($config['type'] === 'dimension' && !empty($config['unit'])) {
        $label .= ' (' . htmlspecialchars($config['unit']) . ')';
    }
    
    $parent_field = $config['parent_field_key'] ?? '';
    $parent_value = $config['parent_value'] ?? '';
    
    $row_attrs = ' data-field-key="' . htmlspecialchars($field_key) . '"';
    if ($parent_field && $parent_value) {
        $row_attrs .= ' data-parent-field="' . htmlspecialchars($parent_field) . '"';
        $row_attrs .= ' data-parent-value="' . htmlspecialchars($parent_value) . '"';
        // Initial state: hidden if it has a parent (will be shown by JS if condition met)
        $row_attrs .= ' style="display: none; opacity: 0; transform: translateY(-10px); transition: all 0.3s ease;"';
    } else {
        $row_attrs .= ' style="transition: all 0.3s ease;"';
    }
    
    $html = '<div class="shopee-form-row" id="card-' . htmlspecialchars($field_key) . '"' . $row_attrs . '>';
    $html .= '<div class="shopee-form-label">' . $label . $required . '</div>';
    $html .= '<div class="shopee-form-field">';
    
    // Pre-scan for all values that appear inside nested fields to avoid duplication at the top level
    $nestedValuesSet = [];
    if (!empty($config['options']) && is_array($config['options'])) {
        foreach ($config['options'] as $option) {
            if (is_array($option) && !empty($option['nested_fields'])) {
                foreach ($option['nested_fields'] as $nestedField) {
                    if (!empty($nestedField['options']) && is_array($nestedField['options'])) {
                        foreach ($nestedField['options'] as $nOpt) {
                            $nOptVal = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                            if ($nOptVal !== '') {
                                $nValStr = (string)$nOptVal;
                                $nestedValuesSet[strtolower(trim($nValStr))] = true;
                                
                                // Dimension-specific: hide parts (e.g. '2' from '2x2')
                                if (($nestedField['type'] ?? '') === 'dimension') {
                                    $normalized = str_replace(['x', 'X', '*', '-', '×'], '|', $nValStr);
                                    $parts = explode('|', $normalized);
                                    foreach ($parts as $p) {
                                        $pTrim = trim($p);
                                        if ($pTrim !== '') $nestedValuesSet[strtolower($pTrim)] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    switch ($config['type']) {
        case 'select':
            if ($field_key === 'branch') {
                $selected_branch = $existing_data['branch_id'] ?? '';
                $html .= '<select name="branch_id" id="branch_id" class="shopee-opt-btn" ' . $required_attr . ' style="width: 175px; cursor: pointer;">';
                $html .= '<option value="">Select Branch</option>';
                foreach ($branches as $b) {
                    $selected = ($selected_branch == $b['id']) ? ' selected' : '';
                    $html .= '<option value="' . (int)$b['id'] . '"' . $selected . '>' . htmlspecialchars($b['branch_name']) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<select name="' . htmlspecialchars($field_key) . '" class="shopee-opt-btn pricing-field" ' . $required_attr . ' style="width: 175px; cursor: pointer;">';
                $html .= '<option value="">Select ' . $label . '</option>';
                foreach ($config['options'] ?? [] as $option) {
                    $optionValue = is_array($option) ? ($option['value'] ?? '') : $option;
                    $optionPrice = is_array($option) ? ($option['price'] ?? 0) : 0;
                    if ($optionValue === '') continue;
                    
                    // Skip if this option is already defined in a nested field
                    if (isset($nestedValuesSet[strtolower(trim($optionValue))])) continue;
                    
                    $value = htmlspecialchars($optionValue);
                    $displayValue = htmlspecialchars(pf_service_option_label($optionValue));
                    $html .= '<option value="' . $value . '" data-price="' . htmlspecialchars((string)$optionPrice) . '">' . $displayValue . '</option>';
                }
                $html .= '</select>';
            }
            break;
            
        case 'radio':
            $allowRadioOthers = !empty($config['allow_others']);
            $rawOptions = array_values($config['options'] ?? []);
            $presetValues = [];
            foreach ($rawOptions as $opt) {
                $ov = is_array($opt) ? trim((string)($opt['value'] ?? '')) : trim((string)$opt);
                if ($ov !== '') {
                    $presetValues[$ov] = true;
                }
            }
            $hasOthersInList = isset($presetValues['Others']);
            $radioOptions = $rawOptions;
            if ($allowRadioOthers && !$hasOthersInList) {
                $radioOptions[] = ['value' => 'Others', 'price' => 0];
            }
            $othersAvailable = $hasOthersInList || $allowRadioOthers;

            $savedOtherText = trim((string)($saved_customization[$field_label . ' (Other)'] ?? ''));
            $radioSaved = trim((string)$saved_value);
            if ($othersAvailable && $radioSaved !== '' && $radioSaved !== 'Others' && !isset($presetValues[$radioSaved])) {
                $radioSaved = 'Others';
                if ($savedOtherText === '') {
                    $savedOtherText = trim((string)$saved_value);
                }
            }

            $html .= '<div class="shopee-opt-group">';
            foreach ($radioOptions as $idx => $option) {
                $optionValue = is_array($option) ? ($option['value'] ?? '') : $option;
                $nestedFields = is_array($option) ? ($option['nested_fields'] ?? []) : [];

                if ($optionValue === '') {
                    continue;
                }
                if (empty($nestedFields) && isset($nestedValuesSet[strtolower(trim((string)$optionValue))])) {
                    continue;
                }

                $value = htmlspecialchars((string)$optionValue);
                $displayValue = htmlspecialchars(pf_service_option_label((string)$optionValue));
                $is_checked = ($radioSaved === (string)$optionValue) ? ' checked' : '';
                $optionPrice = is_array($option) ? ($option['price'] ?? 0) : 0;
                $html .= '<label class="shopee-opt-btn' . ($is_checked ? ' active' : '') . '">';
                $html .= '<input type="radio" name="' . htmlspecialchars($field_key) . '" value="' . $value . '"' . $is_checked . ' style="display:none;" class="pricing-field" data-pf-radio-option-index="' . (int)$idx . '" data-price="' . htmlspecialchars((string)$optionPrice) . '" ' . $required_attr . '>';
                $html .= '<span>' . $displayValue . '</span>';
                $html .= '</label>';
            }
            $html .= '</div>';

            if ($othersAvailable) {
                $showOthersInput = ($radioSaved === 'Others');
                $html .= '<div class="radio-others-wrap" id="radio-others-' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '" style="margin-top:12px;display:' . ($showOthersInput ? 'block' : 'none') . '">';
                $html .= '<input type="text" name="' . htmlspecialchars($field_key) . '_other" class="input-field radio-others-input" placeholder="Please specify..." value="' . htmlspecialchars($savedOtherText, ENT_QUOTES, 'UTF-8') . '" style="max-width:400px;" autocomplete="off">';
                $html .= '</div>';
            }

            // Render nested fields containers (initially hidden)
            foreach ($rawOptions as $idx => $option) {
                $optionValue = is_array($option) ? ($option['value'] ?? '') : $option;
                $nestedFields = is_array($option) ? ($option['nested_fields'] ?? []) : [];
                if (!empty($nestedFields)) {
                    $html .= '<div id="nested-' . htmlspecialchars($field_key) . '-' . $idx . '" class="nested-fields-container" style="display:none; margin-top:16px; padding:16px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">';
                    
                    foreach ($nestedFields as $nIdx => $nestedField) {
                        $nestedKey = $field_key . '_nested_' . $idx . '_' . $nIdx;
                        $nestedLabel = htmlspecialchars($nestedField['label'] ?? '');
                        $nestedType = $nestedField['type'] ?? 'text';
                        $nestedRequired = ($nestedField['required'] ?? false) ? 'required' : '';
                        $nestedRequiredMark = ($nestedField['required'] ?? false) ? ' *' : '';
                        
                        $html .= '<div class="shopee-form-row" style="margin-bottom:12px;">';
                        
                        // Check if nested label is redundant (same as parent option value)
                        $cleanNestedLabel = trim(str_replace('*', '', $nestedField['label'] ?? ''));
                        $isRedundant = (strtolower($cleanNestedLabel) === strtolower(trim($optionValue)));
                        
                        if ($isRedundant) {
                            $html .= '<div class="shopee-form-label" style="min-width:100px;font-size:13px;"></div>';
                        } else {
                            $html .= '<div class="shopee-form-label" style="min-width:100px;font-size:13px;">' . $nestedLabel . $nestedRequiredMark . '</div>';
                        }
                        
                        $html .= '<div class="shopee-form-field">';
                        
                        switch ($nestedType) {
                            case 'select':
                                $html .= '<select name="' . htmlspecialchars($nestedKey) . '" class="shopee-opt-btn" ' . $nestedRequired . ' style="width:175px;cursor:pointer;">';
                                $html .= '<option value="">Select ' . $nestedLabel . '</option>';
                                foreach ($nestedField['options'] ?? [] as $nOpt) {
                                    $nOptVal = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                                    $nVal = htmlspecialchars($nOptVal);
                                    $nDisplay = htmlspecialchars(pf_service_option_label($nOptVal));
                                    $html .= '<option value="' . $nVal . '">' . $nDisplay . '</option>';
                                }
                                $html .= '</select>';
                                break;
                                
                            case 'radio':
                                $html .= '<div class="shopee-opt-group">';
                                foreach ($nestedField['options'] ?? [] as $nOpt) {
                                    $nOptVal = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                                    $nVal = htmlspecialchars($nOptVal);
                                    $nDisplay = htmlspecialchars(pf_service_option_label($nOptVal));
                                    $html .= '<label class="shopee-opt-btn">';
                                    $html .= '<input type="radio" name="' . htmlspecialchars($nestedKey) . '" value="' . $nVal . '" style="display:none;" ' . $nestedRequired . ' onchange="updateOptVisual(this)">';
                                    $html .= '<span>' . $nDisplay . '</span>';
                                    $html .= '</label>';
                                }
                                $html .= '</div>';
                                break;
                                
                            case 'dimension':
                                $nUnit = $nestedField['unit'] ?? 'ft';
                                $nAllowOthers = $nestedField['allow_others'] ?? true;
                                
                                $html .= '<div class="shopee-opt-group mb-3">';
                                foreach ($nestedField['options'] ?? [] as $nOpt) {
                                    $nOptValue = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                                    $nOptValue = trim((string)$nOptValue);
                                    if ($nOptValue === '') {
                                        continue;
                                    }
                                    $parts = explode('×', $nOptValue);
                                    if (count($parts) === 2) {
                                        $w = trim($parts[0]);
                                        $h = trim($parts[1]);
                                        $html .= '<button type="button" class="shopee-opt-btn" onclick="selectNestedDimension(\'' . $nestedKey . '\', ' . $w . ', ' . $h . ', event)">' . $w . '×' . $h . '</button>';
                                    }
                                }
                                if ($nAllowOthers) {
                                    $html .= '<button type="button" class="shopee-opt-btn" onclick="selectNestedDimensionOthers(\'' . $nestedKey . '\', event)">Others</button>';
                                }
                                $html .= '</div>';
                                
                                if ($nAllowOthers) {
                                    $html .= '<div id="nested-dim-others-' . $nestedKey . '" style="display:none;margin-top:12px;">';
                                    $html .= '<div style="display:flex;gap:12px;max-width:300px;">';
                                    $html .= '<input type="text" id="nested-w-' . $nestedKey . '" placeholder="Width" class="input-field" style="text-align:center;" oninput="syncNestedDimension(\'' . $nestedKey . '\')"> ';
                                    $html .= '<span style="padding-top:8px;">×</span>';
                                    $html .= '<input type="text" id="nested-h-' . $nestedKey . '" placeholder="Height" class="input-field" style="text-align:center;" oninput="syncNestedDimension(\'' . $nestedKey . '\')"> ';
                                    $html .= '</div></div>';
                                }
                                
                                $html .= '<input type="hidden" name="' . htmlspecialchars($nestedKey) . '" id="nested-hidden-' . $nestedKey . '" ' . $nestedRequired . '>';
                                break;
                                
                            case 'file':
                                $html .= '<input type="file" name="' . htmlspecialchars($nestedKey) . '" class="input-field" ' . $nestedRequired . ' style="max-width:400px;">';
                                break;
                                
                            case 'textarea':
                                $html .= '<textarea name="' . htmlspecialchars($nestedKey) . '" rows="3" class="shopee-opt-btn" ' . $nestedRequired . ' style="max-width:400px;resize:none;"></textarea>';
                                break;
                                
                            case 'date':
                                $html .= '<input type="date" name="' . htmlspecialchars($nestedKey) . '" class="input-field" ' . $nestedRequired . ' min="' . date('Y-m-d') . '" style="max-width:200px;">';
                                break;
                                
                            case 'number':
                                $html .= '<input type="number" name="' . htmlspecialchars($nestedKey) . '" class="input-field" ' . $nestedRequired . ' style="max-width:200px;">';
                                break;
                                
                            default:
                                $html .= '<input type="text" name="' . htmlspecialchars($nestedKey) . '" class="input-field" ' . $nestedRequired . ' style="max-width:400px;">';
                        }
                        
                        $html .= '</div></div>';
                    }
                    
                    $html .= '</div>';
                }
            }
            break;
            
        case 'dimension':
            $unit = $config['unit'] ?? 'ft';
            $allowOthers = $config['allow_others'] ?? true;
            
            // Parse saved dimension (e.g., "2×3 ft" or "2x3")
            $saved_width = '';
            $saved_height = '';
            $is_custom_dimension = false;
            
            if ($saved_value) {
                // Remove unit and normalize separators
                $dim_value = preg_replace('/\s*(ft|in|cm|m)\s*$/i', '', $saved_value);
                $dim_value = str_replace(['×', 'X', '*', '-'], 'x', $dim_value);
                $parts = explode('x', $dim_value);
                if (count($parts) === 2) {
                    $saved_width = trim($parts[0]);
                    $saved_height = trim($parts[1]);
                    
                    // Check if it's a preset or custom
                    $is_preset = false;
                    foreach ($config['options'] ?? [] as $option) {
                        $option_value = is_array($option) ? ($option['value'] ?? '') : $option;
                        $option_value = trim((string)$option_value);
                        if ($option_value === '') {
                            continue;
                        }
                        $opt_normalized = str_replace(['×', 'X', '*', '-'], 'x', $option_value);
                        if (strtolower($opt_normalized) === strtolower($saved_width . 'x' . $saved_height)) {
                            $is_preset = true;
                            break;
                        }
                    }
                    $is_custom_dimension = !$is_preset;
                }
            }
            
            $html .= '<div class="shopee-opt-group mb-3">';
            
            if (!empty($config['options']) && is_array($config['options'])) {
                foreach ($config['options'] as $option) {
                    $option_value = is_array($option) ? ($option['value'] ?? '') : $option;
                    $option_price = is_array($option) ? ($option['price'] ?? 0) : 0;
                    $option_value = trim((string)$option_value);
                    if ($option_value === '') {
                        continue;
                    }
                    $normalized = str_replace(['x', 'X', '*', '-', '×'], '|', $option_value);
                    $parts = explode('|', $normalized);
                    
                    if (count($parts) === 2) {
                        $w = trim($parts[0]);
                        $h = trim($parts[1]);
                        if ($w && $h) {
                            $displayLabel = $w . '×' . $h;
                            $is_active = (!$is_custom_dimension && $saved_width == $w && $saved_height == $h) ? ' active' : '';
                            $html .= '<button type="button" class="shopee-opt-btn pricing-field' . $is_active . '" data-price="' . htmlspecialchars((string)$option_price) . '" data-width="' . htmlspecialchars($w) . '" data-height="' . htmlspecialchars($h) . '" data-dimension-key="' . htmlspecialchars($field_key) . '" data-dimension-choice="1" onclick="var r=this.closest(\'.shopee-form-row\');if(r){r.querySelectorAll(\'.shopee-opt-btn\').forEach(function(b){b.classList.remove(\'active\')});this.classList.add(\'active\');var o=r.querySelector(\'.dim-others-inputs\');if(o)o.style.display=\'none\';var cw=r.querySelector(\'.custom-dim-width\');var ch=r.querySelector(\'.custom-dim-height\');if(cw)cw.value=\'\';if(ch)ch.value=\'\';var w=r.querySelector(\'[data-dimension-role=width]\')||r.querySelector(\'input[name=width]\');var h=r.querySelector(\'[data-dimension-role=height]\')||r.querySelector(\'input[name=height]\');if(w)w.value=this.dataset.width||\'\';if(h)h.value=this.dataset.height||\'\';var lw=r.querySelector(\'input[name=width]\');var lh=r.querySelector(\'input[name=height]\');if(lw)lw.value=this.dataset.width||\'\';if(lh)lh.value=this.dataset.height||\'\';}if(window.calculateEstimatedPrice)window.calculateEstimatedPrice();return false;">' . $displayLabel . '</button>';
                        }
                    }
                }
            }
            
            if ($allowOthers) {
                $others_active = $is_custom_dimension ? ' active' : '';
                $html .= '<button type="button" class="shopee-opt-btn dim-others-btn' . $others_active . '" data-dimension-key="' . htmlspecialchars($field_key) . '" data-dimension-others="1" onclick="var r=this.closest(\'.shopee-form-row\');if(r){r.querySelectorAll(\'.shopee-opt-btn\').forEach(function(b){b.classList.remove(\'active\')});this.classList.add(\'active\');var o=r.querySelector(\'.dim-others-inputs\');if(o)o.style.display=\'block\';var w=r.querySelector(\'[data-dimension-role=width]\')||r.querySelector(\'input[name=width]\');var h=r.querySelector(\'[data-dimension-role=height]\')||r.querySelector(\'input[name=height]\');if(w)w.value=\'\';if(h)h.value=\'\';var lw=r.querySelector(\'input[name=width]\');var lh=r.querySelector(\'input[name=height]\');if(lw)lw.value=\'\';if(lh)lh.value=\'\';}if(window.calculateEstimatedPrice)window.calculateEstimatedPrice();return false;">Others</button>';
            }
            $html .= '</div>';
            
            if ($allowOthers) {
                $others_display = $is_custom_dimension ? 'block' : 'none';
                $html .= '<div class="dim-others-inputs" style="display: ' . $others_display . '; border-top: 1px dashed #eee; padding-top: 1rem; margin-top: 1rem;">';
                $html .= '<div style="display: flex; gap: 0.75rem; align-items: flex-start; max-width: 400px;">';
                $html .= '<div style="flex: 1;">';
                $html .= '<label class="dim-label" style="display: block; margin-bottom: 0.5rem; font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase;">WIDTH</label>';
                $html .= '<input type="text" inputmode="numeric" class="input-field custom-dim-width" data-dimension-key="' . htmlspecialchars($field_key) . '" placeholder="' . htmlspecialchars($unit) . '" maxlength="2" pattern="[0-9]*" value="' . ($is_custom_dimension ? htmlspecialchars($saved_width) : '') . '" style="text-align: center;">';
                $html .= '</div>';
                $html .= '<div style="padding-top: 1.75rem; color: #cbd5e1; font-weight: bold; font-size: 1.25rem;">×</div>';
                $html .= '<div style="flex: 1;">';
                $html .= '<label class="dim-label" style="display: block; margin-bottom: 0.5rem; font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase;">HEIGHT</label>';
                $html .= '<input type="text" inputmode="numeric" class="input-field custom-dim-height" data-dimension-key="' . htmlspecialchars($field_key) . '" placeholder="' . htmlspecialchars($unit) . '" maxlength="2" pattern="[0-9]*" value="' . ($is_custom_dimension ? htmlspecialchars($saved_height) : '') . '" style="text-align: center;">';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';
            }
            
            $html .= '<input type="hidden" id="' . htmlspecialchars($field_key) . '_width_hidden" data-dimension-role="width" data-dimension-key="' . htmlspecialchars($field_key) . '" name="' . htmlspecialchars($field_key) . '_width" value="' . htmlspecialchars($saved_width) . '" ' . $required_attr . '>';
            $html .= '<input type="hidden" id="' . htmlspecialchars($field_key) . '_height_hidden" data-dimension-role="height" data-dimension-key="' . htmlspecialchars($field_key) . '" name="' . htmlspecialchars($field_key) . '_height" value="' . htmlspecialchars($saved_height) . '" ' . $required_attr . '>';
            $html .= '<input type="hidden" id="width_hidden" name="width" value="' . htmlspecialchars($saved_width) . '">';
            $html .= '<input type="hidden" id="height_hidden" name="height" value="' . htmlspecialchars($saved_height) . '">';
            $html .= '<input type="hidden" name="unit" value="' . htmlspecialchars($unit) . '">';
            break;
            
        case 'file':
            $html .= '<input type="file" name="design_file" id="design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field" ' . $required_attr . ' style="max-width: 400px;">';
            break;
            
        case 'date':
            $html .= '<div class="shopee-opt-group">';
            $html .= '<input type="date" name="' . htmlspecialchars($field_key) . '" id="' . htmlspecialchars($field_key) . '" class="shopee-opt-btn" ' . $required_attr . ' min="' . date('Y-m-d') . '" value="' . htmlspecialchars($saved_value) . '" style="cursor: pointer; width: 175px;">';
            $html .= '</div>';
            break;
            
        case 'quantity':
            $saved_qty = (int)($saved_value !== '' && $saved_value !== null ? $saved_value : 2);
            if ($saved_qty < 1) {
                $saved_qty = 1;
            }
            $qty_name = htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8');
            $qty_id = 'pf-qty-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$field_key);
            $html .= '<div class="shopee-opt-group">';
            $html .= '<div class="quantity-container shopee-opt-btn" style="display: inline-flex; justify-content: space-between; gap: 1rem; width: 175px; cursor: default;">';
            $html .= '<button type="button" class="qty-btn-minus" style="background: none; border: none; color: #6b7280; font-size: 1.125rem; font-weight: 600; cursor: pointer; padding: 0; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;" onclick="const w=this.closest(\'.quantity-container\');const i=w?w.querySelector(\'.pf-service-quantity-input\'):null;if(i&&parseInt(i.value)>1){i.value=parseInt(i.value)-1;if(window.calculateEstimatedPrice)window.calculateEstimatedPrice();}">&minus;</button>';
            $html .= '<input type="text" inputmode="numeric" id="' . htmlspecialchars($qty_id, ENT_QUOTES, 'UTF-8') . '" name="' . $qty_name . '" class="qty-input-field pf-service-quantity-input" style="border: none; text-align: center; width: 60px; font-size: 0.875rem; font-weight: 500; color: #374151; background: transparent; outline: none;" value="' . $saved_qty . '" oninput="if(window.validateQuantity)window.validateQuantity(this);" onkeydown="return event.key === \'Backspace\' || event.key === \'Delete\' || event.key === \'ArrowLeft\' || event.key === \'ArrowRight\' || event.key === \'Tab\' || (event.key >= \'0\' && event.key <= \'9\');">';
            $html .= '<button type="button" class="qty-btn-plus" style="background: none; border: none; color: #6b7280; font-size: 1.125rem; font-weight: 600; cursor: pointer; padding: 0; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;" onclick="const w=this.closest(\'.quantity-container\');const i=w?w.querySelector(\'.pf-service-quantity-input\'):null;if(i){const max=100;const v=parseInt(i.value)||1;if(v<max){i.value=v+1;if(window.calculateEstimatedPrice)window.calculateEstimatedPrice();}}">+</button>';
            $html .= '</div>';
            $html .= '</div>';
            break;
            
        case 'textarea':
            $html .= '<textarea name="' . htmlspecialchars($field_key) . '" rows="4" class="shopee-opt-btn notes-textarea" placeholder="Any special instructions..." maxlength="500" ' . $required_attr . ' style="width: 100%; max-width: 100%; height: 100px; resize: none; align-items: flex-start; justify-content: flex-start; text-align: left; padding: 0.75rem;">' . htmlspecialchars($saved_value) . '</textarea>';
            break;
            
        case 'text':
        case 'number':
            $type = $config['type'] === 'number' ? 'number' : 'text';
            $html .= '<input type="' . $type . '" name="' . htmlspecialchars($field_key) . '" class="input-field" value="' . htmlspecialchars($saved_value) . '" ' . $required_attr . ' style="max-width: 400px;">';
            break;
    }
    
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * Render all fields for a service
 */
function render_service_fields($service_id, $branches = [], $existing_data = []) {
    $configs = get_service_field_config($service_id);
    
    if (empty($configs)) {
        return '<p style="color:#ef4444; padding:20px; text-align:center;">No field configuration found. Please contact administrator.</p>';
    }

    // Separate fields into categories (restores classic layout: branch → specs → needed date / qty / notes).
    $branch_field = [];
    $custom_fields = [];
    $default_bottom_fields = [];

    foreach ($configs as $key => $config) {
        if ($key === 'branch') {
            $branch_field[$key] = $config;
        } elseif (in_array($key, ['needed_date', 'quantity', 'notes'], true)) {
            $default_bottom_fields[$key] = $config;
        } else {
            $custom_fields[$key] = $config;
        }
    }

    uasort($custom_fields, function ($a, $b) {
        return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
    });

    $bottom_order = ['needed_date' => 1, 'quantity' => 2, 'notes' => 3];
    uasort($default_bottom_fields, function ($a, $b) use ($bottom_order, $default_bottom_fields) {
        $key_a = array_search($a, $default_bottom_fields);
        $key_b = array_search($b, $default_bottom_fields);
        return ($bottom_order[$key_a] ?? 999) - ($bottom_order[$key_b] ?? 999);
    });

    $html = '';

    foreach ($branch_field as $key => $config) {
        $html .= render_service_field($key, $config, $branches, $existing_data);
    }
    foreach ($custom_fields as $key => $config) {
        $html .= render_service_field($key, $config, $branches, $existing_data);
    }
    foreach ($default_bottom_fields as $key => $config) {
        $html .= render_service_field($key, $config, $branches, $existing_data);
    }

    return $html;
}

/**
 * Get JavaScript for dynamic field behavior
 */
function get_service_field_scripts() {
    return <<<'JSEND'
<script>
var dimensionMode = window.__pfServiceDimensionMode || 'preset';

function updateOptVisual(input) {
    const name = input.name;
    document.querySelectorAll('input[name="' + name + '"]').forEach(r => {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) wrap.classList.toggle('active', r.checked);
    });
}

function handleNestedFields(radio, fieldKey, optionIndex) {
    // Hide all nested field containers for this field first
    document.querySelectorAll('[id^="nested-' + fieldKey + '-"]').forEach(container => {
        container.style.display = 'none';
        // Clear nested field values when hiding
        container.querySelectorAll('input, select, textarea').forEach(input => {
            if (input.type === 'radio' || input.type === 'checkbox') {
                input.checked = false;
            } else {
                input.value = '';
            }
            // Remove visual active states
            const wrap = input.closest('.shopee-opt-btn');
            if (wrap) wrap.classList.remove('active');
        });
    });
    
    // Only show the nested fields for the currently selected option
    if (radio.checked) {
        const nestedContainer = document.getElementById('nested-' + fieldKey + '-' + optionIndex);
        if (nestedContainer) {
            nestedContainer.style.display = 'block';
        }
    }
}

function pfSyncRadioOthersWrap(radio) {
    if (!radio || !radio.name) return;
    const wrap = document.getElementById('radio-others-' + radio.name);
    if (!wrap) return;
    const row = radio.closest('.shopee-form-row[data-field-key]');
    if (!row || row.getAttribute('data-field-key') !== radio.name) return;
    let val = '';
    row.querySelectorAll('input[type="radio"].pricing-field').forEach(function(r) {
        if (r.name === radio.name && r.checked) {
            val = r.value;
        }
    });
    wrap.style.display = val === 'Others' ? 'block' : 'none';
}

function selectNestedDimension(key, w, h, e) {
    e.preventDefault();
    const btnGroup = e.target.closest('.shopee-opt-group');
    if (btnGroup) {
        btnGroup.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
    }
    e.target.classList.add('active');
    
    const hidden = document.getElementById('nested-hidden-' + key);
    if (hidden) hidden.value = w + 'x' + h;
    
    const othersDiv = document.getElementById('nested-dim-others-' + key);
    if (othersDiv) othersDiv.style.display = 'none';
}

function selectNestedDimensionOthers(key, e) {
    e.preventDefault();
    const btnGroup = e.target.closest('.shopee-opt-group');
    if (btnGroup) {
        btnGroup.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
    }
    e.target.classList.add('active');
    
    const othersDiv = document.getElementById('nested-dim-others-' + key);
    if (othersDiv) othersDiv.style.display = 'block';
}

function syncNestedDimension(key) {
    const w = document.getElementById('nested-w-' + key)?.value || '';
    const h = document.getElementById('nested-h-' + key)?.value || '';
    const hidden = document.getElementById('nested-hidden-' + key);
    if (hidden && w && h) {
        hidden.value = w + 'x' + h;
    }
}

function validateDimensionInput(input) {
    input.value = input.value.replace(/[^0-9]/g, '').substring(0, 2);
    const row = input.closest('.shopee-form-row');
    syncDimensionToHidden(row);
}

function updateDimensionUnit(unit, row) {
    const scope = row || document;
    const unitHidden = scope.querySelector('input[name="unit"]');
    if (unitHidden) unitHidden.value = unit;
    
    const widthInput = scope.querySelector('.custom-dim-width');
    const heightInput = scope.querySelector('.custom-dim-height');
    if (widthInput) widthInput.placeholder = unit;
    if (heightInput) heightInput.placeholder = unit;
}

function syncDimensionToHidden(row) {
    const scope = row || document;
    const wh = scope.querySelector('input[data-dimension-role="width"]') || scope.querySelector('input[name="width"]');
    const hh = scope.querySelector('input[data-dimension-role="height"]') || scope.querySelector('input[name="height"]');
    if (!wh || !hh) return;
    const legacyWidth = scope.querySelector('input[name="width"]');
    const legacyHeight = scope.querySelector('input[name="height"]');
    
    if (dimensionMode === 'preset') {
        const btn = scope.querySelector('.shopee-opt-btn.active[data-width]');
        if (btn && btn.dataset.width) {
            wh.value = btn.dataset.width;
            hh.value = btn.dataset.height;
        } else {
            wh.value = '';
            hh.value = '';
        }
    } else {
        wh.value = scope.querySelector('.custom-dim-width')?.value || '';
        hh.value = scope.querySelector('.custom-dim-height')?.value || '';
    }
    if (legacyWidth && legacyWidth !== wh) legacyWidth.value = wh.value;
    if (legacyHeight && legacyHeight !== hh) legacyHeight.value = hh.value;
}

function selectDimension(w, h, e) {
    if (e) e.preventDefault();
    dimensionMode = 'preset';
    window.__pfServiceDimensionMode = dimensionMode;
    const target = e ? e.target : null;
    const row = target ? target.closest('.shopee-form-row') : document;
    const btnGroup = target ? target.closest('.shopee-opt-group') : null;
    if (btnGroup) {
        btnGroup.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
    }
    if (target) target.closest('.shopee-opt-btn')?.classList.add('active');
    const othersInput = row ? row.querySelector('.dim-others-inputs') : null;
    if (othersInput) othersInput.style.display = 'none';
    
    const widthInput = row ? row.querySelector('.custom-dim-width') : null;
    const heightInput = row ? row.querySelector('.custom-dim-height') : null;
    if (widthInput) widthInput.value = '';
    if (heightInput) heightInput.value = '';
    
    syncDimensionToHidden(row);
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function selectDimensionOthers(e) {
    if (e) e.preventDefault();
    dimensionMode = 'others';
    window.__pfServiceDimensionMode = dimensionMode;
    const target = e ? e.target : null;
    const row = target ? target.closest('.shopee-form-row') : document;
    const btnGroup = target ? target.closest('.shopee-opt-group') : null;
    if (btnGroup) {
        btnGroup.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
    }
    const othersBtn = row ? row.querySelector('.dim-others-btn') : null;
    if (othersBtn) othersBtn.classList.add('active');
    const othersInput = row ? row.querySelector('.dim-others-inputs') : null;
    if (othersInput) othersInput.style.display = 'block';
    syncDimensionToHidden(row);
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function increaseQty() {
    const i = document.querySelector('#serviceForm .pf-service-quantity-input') || document.querySelector('.pf-service-quantity-input');
    if (i) i.value = Math.min(100, (parseInt(i.value) || 1) + 1);
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function decreaseQty() {
    const i = document.querySelector('#serviceForm .pf-service-quantity-input') || document.querySelector('.pf-service-quantity-input');
    if (i && parseInt(i.value) > 1) i.value = parseInt(i.value) - 1;
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function validateQuantity(input) {
    let val = parseInt(input.value);
    if (isNaN(val) || val < 1) {
        input.value = 1;
    } else if (val > 100) {
        input.value = 100;
    }
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function pfChangeQty(btn, delta) {
    if (!btn) return;
    const container = btn.closest('.quantity-container') || btn.parentElement;
    const input = container ? container.querySelector('.pf-service-quantity-input') : (document.querySelector('#serviceForm .pf-service-quantity-input') || document.querySelector('.pf-service-quantity-input'));
    if (!input) return;
    let val = parseInt(input.value);
    if (isNaN(val) || val < 1) val = 1;
    val = val + delta;
    if (val < 1) val = 1;
    if (val > 100) val = 100;
    input.value = val;
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function serviceFieldEventProxy(method, target) {
    return {
        target: target,
        preventDefault: function() {},
        stopPropagation: function() {}
    };
}

function pfSelectDimensionButton(button) {
    if (!button) return false;
    dimensionMode = 'preset';
    window.__pfServiceDimensionMode = dimensionMode;
    const row = button.closest('.shopee-form-row');
    if (row) {
        row.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
        button.classList.add('active');
        const othersInput = row.querySelector('.dim-others-inputs');
        if (othersInput) othersInput.style.display = 'none';
        const widthInput = row.querySelector('.custom-dim-width');
        const heightInput = row.querySelector('.custom-dim-height');
        if (widthInput) widthInput.value = '';
        if (heightInput) heightInput.value = '';
        const widthHidden = row.querySelector('[data-dimension-role="width"]') || row.querySelector('input[name="width"]');
        const heightHidden = row.querySelector('[data-dimension-role="height"]') || row.querySelector('input[name="height"]');
        if (widthHidden) widthHidden.value = button.dataset.width || '';
        if (heightHidden) heightHidden.value = button.dataset.height || '';
        const legacyWidth = row.querySelector('input[name="width"]');
        const legacyHeight = row.querySelector('input[name="height"]');
        if (legacyWidth) legacyWidth.value = button.dataset.width || '';
        if (legacyHeight) legacyHeight.value = button.dataset.height || '';
    } else {
        selectDimension(button.dataset.width || '', button.dataset.height || '', serviceFieldEventProxy('selectDimension', button));
    }
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
    return false;
}

function pfSelectDimensionOthersButton(button) {
    if (!button) return false;
    dimensionMode = 'others';
    window.__pfServiceDimensionMode = dimensionMode;
    const row = button.closest('.shopee-form-row');
    if (row) {
        row.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
        button.classList.add('active');
        const othersInput = row.querySelector('.dim-others-inputs');
        if (othersInput) othersInput.style.display = 'block';
        const widthHidden = row.querySelector('[data-dimension-role="width"]') || row.querySelector('input[name="width"]');
        const heightHidden = row.querySelector('[data-dimension-role="height"]') || row.querySelector('input[name="height"]');
        if (widthHidden) widthHidden.value = '';
        if (heightHidden) heightHidden.value = '';
        const legacyWidth = row.querySelector('input[name="width"]');
        const legacyHeight = row.querySelector('input[name="height"]');
        if (legacyWidth) legacyWidth.value = '';
        if (legacyHeight) legacyHeight.value = '';
    } else {
        selectDimensionOthers(serviceFieldEventProxy('selectDimensionOthers', button));
    }
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
    return false;
}

window.updateOptVisual = updateOptVisual;
window.handleNestedFields = handleNestedFields;
window.selectNestedDimension = selectNestedDimension;
window.selectNestedDimensionOthers = selectNestedDimensionOthers;
window.syncNestedDimension = syncNestedDimension;
window.validateDimensionInput = validateDimensionInput;
window.updateDimensionUnit = updateDimensionUnit;
window.syncDimensionToHidden = syncDimensionToHidden;
window.selectDimension = selectDimension;
window.selectDimensionOthers = selectDimensionOthers;
window.pfSelectDimensionButton = pfSelectDimensionButton;
window.pfSelectDimensionOthersButton = pfSelectDimensionOthersButton;
window.increaseQty = increaseQty;
window.decreaseQty = decreaseQty;
window.validateQuantity = validateQuantity;
window.pfChangeQty = pfChangeQty;
window.updateConditionalFields = updateConditionalFields;

if (!window.__pfServiceFieldDelegatesBound) {
    window.__pfServiceFieldDelegatesBound = true;

    document.addEventListener('click', function(e) {
        const dimBtn = e.target.closest('[data-dimension-choice="1"]');
        if (dimBtn) {
            e.preventDefault();
            e.stopPropagation();
            pfSelectDimensionButton(dimBtn);
            return;
        }

        const otherBtn = e.target.closest('[data-dimension-others="1"]');
        if (otherBtn) {
            e.preventDefault();
            e.stopPropagation();
            pfSelectDimensionOthersButton(otherBtn);
            return;
        }

        const qtyBtn = e.target.closest('[data-qty-action]');
        if (qtyBtn) {
            e.preventDefault();
            e.stopPropagation();
            const delta = qtyBtn.dataset.qtyAction === 'increase' ? 1 : -1;
            pfChangeQty(qtyBtn, delta);
        }
    }, true);

    document.addEventListener('change', function(e) {
        const radio = e.target.closest('.shopee-opt-btn input[type="radio"]');
        if (radio) {
            updateOptVisual(radio);
            updateConditionalFields();
        }
    }, true);

    document.addEventListener('input', function(e) {
        if (e.target.matches('.custom-dim-width, .custom-dim-height')) {
            validateDimensionInput(e.target);
        } else if (e.target.matches('.pf-service-quantity-input')) {
            validateQuantity(e.target);
        }
    }, true);
}

// --- Conditional Fields Logic ---

function updateConditionalFields() {
    const allRows = document.querySelectorAll('.shopee-form-row[data-parent-field]');
    
    // Create a map of current field values
    const fieldValues = {};
    
    // Get values from all potential parent fields
    // 1. Radios
    document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
        fieldValues[radio.name] = radio.value;
    });
    
    // 2. Selects
    document.querySelectorAll('select').forEach(select => {
        fieldValues[select.name] = select.value;
    });
    
    allRows.forEach(row => {
        const parentField = row.getAttribute('data-parent-field');
        const triggerValue = row.getAttribute('data-parent-value');
        const currentValue = fieldValues[parentField];
        
        if (currentValue === triggerValue) {
            showFieldRow(row);
        } else {
            hideFieldRow(row);
        }
    });
}

function showFieldRow(row) {
    if (row.style.display === 'none' || row.style.display === '') {
        row.style.display = 'flex';
        // Force reflow for transition
        row.offsetHeight;
        row.style.opacity = '1';
        row.style.transform = 'translateY(0)';
    }
}

function hideFieldRow(row) {
    if (row.style.display !== 'none') {
        row.style.opacity = '0';
        row.style.transform = 'translateY(-10px)';
        
        // Wait for transition to finish before hiding
        setTimeout(() => {
            // Re-check condition before hiding (in case user toggled back quickly)
            const parentField = row.getAttribute('data-parent-field');
            const triggerValue = row.getAttribute('data-parent-value');
            
            // Get current value again
            let currentVal = '';
            const radio = document.querySelector('input[name="' + parentField + '"]:checked');
            if (radio) {
                currentVal = radio.value;
            } else {
                const select = document.querySelector('select[name="' + parentField + '"]');
                if (select) currentVal = select.value;
            }
            
            if (currentVal !== triggerValue) {
                row.style.display = 'none';
                clearFieldRowValues(row);
            }
        }, 300);
    }
}

function clearFieldRowValues(row) {
    // 1. Inputs (text, number, date)
    row.querySelectorAll('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])').forEach(input => {
        input.value = '';
    });
    
    // 2. Textarea
    row.querySelectorAll('textarea').forEach(textarea => {
        textarea.value = '';
    });
    
    // 3. Select
    row.querySelectorAll('select').forEach(select => {
        select.selectedIndex = 0;
    });
    
    // 4. Radios
    row.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.checked = false;
        const wrap = radio.closest('.shopee-opt-btn');
        if (wrap) wrap.classList.remove('active');
    });
    
    // 5. Files
    row.querySelectorAll('input[type="file"]').forEach(file => {
        file.value = '';
    });
    
    // 6. Dimensions (custom)
    row.querySelectorAll('[data-dimension-role="width"], [data-dimension-role="height"], #width_hidden, #height_hidden').forEach(input => {
        input.value = '';
    });
    
    row.querySelectorAll('.shopee-opt-btn').forEach(btn => btn.classList.remove('active'));
    row.querySelectorAll('.dim-others-inputs, #dim-others-inputs').forEach(othersInput => {
        othersInput.style.display = 'none';
    });
}

function initServiceFieldRenderer() {
    // Ensure all nested fields are hidden initially
    document.querySelectorAll('.nested-fields-container').forEach(container => {
        container.style.display = 'none';
    });
    
    // Initialize radio buttons visual state
    document.querySelectorAll('.shopee-opt-btn input[type="radio"]').forEach(radio => {
        if (radio.checked) {
            updateOptVisual(radio);
            if (radio.classList.contains('pricing-field')) {
                const idx = parseInt(radio.getAttribute('data-pf-radio-option-index') || '', 10);
                if (!Number.isNaN(idx)) {
                    handleNestedFields(radio, radio.name, idx);
                }
                pfSyncRadioOthersWrap(radio);
            }
        }
        if (radio.dataset.pfServiceFieldBound === '1') return;
        radio.dataset.pfServiceFieldBound = '1';
        radio.addEventListener('change', function() {
            updateOptVisual(this);
            if (this.classList.contains('pricing-field')) {
                const idx = parseInt(this.getAttribute('data-pf-radio-option-index') || '', 10);
                if (!Number.isNaN(idx)) {
                    handleNestedFields(this, this.name, idx);
                }
                pfSyncRadioOthersWrap(this);
            }
            updateConditionalFields();
        });
    });
    
    // Initialize select listeners
    document.querySelectorAll('select').forEach(select => {
        if (select.dataset.pfServiceFieldBound === '1') return;
        select.dataset.pfServiceFieldBound = '1';
        select.addEventListener('change', updateConditionalFields);
    });
    
    document.querySelectorAll('.custom-dim-width, .custom-dim-height').forEach(input => {
        if (input.dataset.pfServiceFieldBound === '1') return;
        input.dataset.pfServiceFieldBound = '1';
        input.addEventListener('input', function() {
            const row = input.closest('.shopee-form-row');
            syncDimensionToHidden(row);
        });
    });
    
    // Run once on load to show initial state
    updateConditionalFields();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initServiceFieldRenderer);
} else {
    initServiceFieldRenderer();
}
document.addEventListener('turbo:load', initServiceFieldRenderer);
</script>
JSEND;
}
