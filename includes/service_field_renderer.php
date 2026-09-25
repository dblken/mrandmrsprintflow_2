<?php
/**
 * Dynamic Service Field Renderer
 * Renders form fields based on admin configuration
 */

require_once __DIR__ . '/service_field_config_helper.php';
require_once __DIR__ . '/service_dimension_ui.php';

/**
 * Customer-facing ⓘ help control next to a field label (admin-configured help_text).
 */
function printflow_render_service_field_help_icon(string $field_key, string $help_text): string
{
    $help_text = printflow_normalize_service_field_help_text($help_text);
    if ($help_text === '') {
        return '';
    }
    $tipId = 'pf-field-help-tip-' . preg_replace('/[^a-z0-9_-]/i', '-', $field_key);
    $safeTip = nl2br(htmlspecialchars($help_text, ENT_QUOTES, 'UTF-8'));
    $html = '<span class="pf-field-help" data-pf-field-help="1">';
    $html .= '<button type="button" class="pf-field-help-trigger pf-custom-size-info" aria-label="Field help" aria-expanded="false" aria-controls="' . htmlspecialchars($tipId, ENT_QUOTES, 'UTF-8') . '">ⓘ</button>';
    $html .= '<span id="' . htmlspecialchars($tipId, ENT_QUOTES, 'UTF-8') . '" class="pf-field-help-tooltip" role="tooltip" hidden>' . $safeTip . '</span>';
    $html .= '</span>';
    return $html;
}

function printflow_service_field_help_styles(): string
{
    return <<<'CSS'
.shopee-form-label {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.35rem;
}
.pf-field-help {
    position: relative;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}
.pf-field-help-trigger {
    border: none;
    background: transparent;
    padding: 0 0.15rem;
    margin: 0;
    line-height: 1;
    font-size: 0.95rem;
    cursor: help;
    vertical-align: middle;
}
.pf-field-help-trigger:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: 2px;
    border-radius: 4px;
}
.pf-field-help-tooltip {
    position: absolute;
    left: 50%;
    bottom: calc(100% + 8px);
    transform: translateX(-50%);
    z-index: 40;
    min-width: 200px;
    max-width: min(280px, calc(100vw - 32px));
    padding: 10px 12px;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: #64748b;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
    text-align: left;
    font-weight: 400;
    text-transform: none;
    letter-spacing: normal;
}
.pf-field-help-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    margin-left: -6px;
    border: 6px solid transparent;
    border-top-color: #fff;
    filter: drop-shadow(0 1px 0 #e2e8f0);
}
@media (hover: hover) and (pointer: fine) {
    .pf-field-help:hover .pf-field-help-tooltip,
    .pf-field-help:focus-within .pf-field-help-tooltip {
        display: block !important;
    }
    .pf-field-help:hover .pf-field-help-tooltip[hidden],
    .pf-field-help:focus-within .pf-field-help-tooltip[hidden] {
        display: block !important;
    }
}
.pf-field-help.is-open .pf-field-help-tooltip {
    display: block !important;
}
.pf-field-help.is-open .pf-field-help-tooltip[hidden] {
    display: block !important;
}
CSS;
}

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
        $saved_value = $existing_data[$field_key] ?? $existing_data['quantity'] ?? 1;
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
    $html .= '<div class="shopee-form-label">';
    $html .= $label . $required;
    $fieldHelp = trim((string) ($config['help_text'] ?? ''));
    if ($fieldHelp !== '') {
        $html .= printflow_render_service_field_help_icon($field_key, $fieldHelp);
    }
    $html .= '</div>';
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
                foreach ($branches as $b) {
                    $selected = ($selected_branch == $b['id']) ? ' selected' : '';
                    $html .= '<option value="' . (int)$b['id'] . '"' . $selected . '>' . htmlspecialchars($b['branch_name']) . '</option>';
                }
                $html .= '</select>';
            } else {
                $allowSelectOthers = !empty($config['allow_others']);
                $rawOptions = array_values($config['options'] ?? []);
                $presetValues = [];
                foreach ($rawOptions as $opt) {
                    $ov = is_array($opt) ? trim((string)($opt['value'] ?? '')) : trim((string)$opt);
                    if ($ov !== '') {
                        $presetValues[$ov] = true;
                    }
                }
                $hasOthersInList = isset($presetValues['Others']);
                $selectOptions = $rawOptions;
                if ($allowSelectOthers && !$hasOthersInList) {
                    $selectOptions[] = ['value' => 'Others', 'price' => 0];
                }
                $othersAvailable = $hasOthersInList || $allowSelectOthers;

                $savedOtherText = trim((string)($saved_customization[$field_label . ' (Other)'] ?? ''));
                $selectSaved = trim((string)$saved_value);
                if ($othersAvailable && $selectSaved !== '' && $selectSaved !== 'Others' && !isset($presetValues[$selectSaved])) {
                    $selectSaved = 'Others';
                    if ($savedOtherText === '') {
                        $savedOtherText = trim((string)$saved_value);
                    }
                }

                $html .= '<select name="' . htmlspecialchars($field_key) . '" class="shopee-opt-btn pricing-field pf-select-with-others' . (printflow_service_field_uses_custom_size_panel($field_key, $config) ? ' pf-select-custom-size' : '') . '" data-field-key="' . htmlspecialchars($field_key) . '" data-other-option="Others" ' . $required_attr . ' style="width: 175px; cursor: pointer;">';
                $html .= '<option value="">Select ' . $label . '</option>';
                foreach ($selectOptions as $option) {
                    $optionValue = is_array($option) ? ($option['value'] ?? '') : $option;
                    $optionPrice = is_array($option) ? ($option['price'] ?? 0) : 0;
                    if ($optionValue === '') continue;
                    
                    // Skip if this option is already defined in a nested field
                    if (isset($nestedValuesSet[strtolower(trim($optionValue))])) continue;
                    
                    $value = htmlspecialchars($optionValue);
                    $displayValue = htmlspecialchars(pf_service_option_label(printflow_service_custom_size_option_label((string)$optionValue, $field_key, $config)));
                    $selected = ($selectSaved === (string)$optionValue) ? ' selected' : '';
                    $html .= '<option value="' . $value . '" data-price="' . htmlspecialchars((string)$optionPrice) . '"' . $selected . '>' . $displayValue . '</option>';
                }
                $html .= '</select>';

                if ($othersAvailable) {
                    $showOthersInput = ($selectSaved === 'Others');
                    if (printflow_service_field_uses_custom_size_panel($field_key, $config)) {
                        $html .= printflow_render_field_custom_size_others_wrap(
                            $field_key,
                            $config,
                            $showOthersInput,
                            $savedOtherText !== '' ? $savedOtherText : (string) $saved_value,
                            'select-others-wrap',
                            'select-others-' . $field_key
                        );
                    } else {
                        $placeholder = 'Enter custom ' . strtolower(trim((string)($config['label'] ?? 'value')));
                        $html .= '<div class="select-others-wrap" id="select-others-' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '" style="margin-top:12px;display:' . ($showOthersInput ? 'block' : 'none') . '">';
                        $html .= '<input type="text" name="' . htmlspecialchars($field_key) . '_other" class="input-field select-others-input" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($savedOtherText, ENT_QUOTES, 'UTF-8') . '" style="max-width:400px;" autocomplete="off">';
                        $html .= '</div>';
                    }
                }
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
                $displayValue = htmlspecialchars(pf_service_option_label(printflow_service_custom_size_option_label((string)$optionValue, $field_key, $config)));
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
                if (printflow_service_field_uses_custom_size_panel($field_key, $config)) {
                    $html .= printflow_render_field_custom_size_others_wrap(
                        $field_key,
                        $config,
                        $showOthersInput,
                        $savedOtherText !== '' ? $savedOtherText : (string) $saved_value,
                        'radio-others-wrap',
                        'radio-others-' . $field_key
                    );
                } else {
                    $html .= '<div class="radio-others-wrap" id="radio-others-' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '" style="margin-top:12px;display:' . ($showOthersInput ? 'block' : 'none') . '">';
                    $html .= '<input type="text" name="' . htmlspecialchars($field_key) . '_other" class="input-field radio-others-input" placeholder="Please specify..." value="' . htmlspecialchars($savedOtherText, ENT_QUOTES, 'UTF-8') . '" style="max-width:400px;" autocomplete="off">';
                    $html .= '</div>';
                }
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
                                $html .= '<option value="" data-price="0">Select ' . $nestedLabel . '</option>';
                                foreach ($nestedField['options'] ?? [] as $nOpt) {
                                    $nOptVal = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                                    $nOptPrice = is_array($nOpt) ? (float)($nOpt['price'] ?? 0) : 0;
                                    $nVal = htmlspecialchars($nOptVal);
                                    $nDisplay = htmlspecialchars(pf_service_option_label($nOptVal));
                                    $html .= '<option value="' . $nVal . '" data-price="' . htmlspecialchars((string)$nOptPrice) . '">' . $nDisplay . '</option>';
                                }
                                $html .= '</select>';
                                break;
                                
                            case 'radio':
                                $html .= '<div class="shopee-opt-group">';
                                foreach ($nestedField['options'] ?? [] as $nOpt) {
                                    $nOptVal = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                                    $nOptPrice = is_array($nOpt) ? (float)($nOpt['price'] ?? 0) : 0;
                                    $nVal = htmlspecialchars($nOptVal);
                                    $nDisplay = htmlspecialchars(pf_service_option_label($nOptVal));
                                    $html .= '<label class="shopee-opt-btn">';
                                    $html .= '<input type="radio" name="' . htmlspecialchars($nestedKey) . '" value="' . $nVal . '" style="display:none;" data-price="' . htmlspecialchars((string)$nOptPrice) . '" ' . $nestedRequired . ' onchange="updateOptVisual(this)">';
                                    $html .= '<span>' . $nDisplay . '</span>';
                                    $html .= '</label>';
                                }
                                $html .= '</div>';
                                break;
                                
                            case 'dimension':
                                $nCtx = printflow_service_field_custom_size_unit_context($nestedKey, $nestedField);
                                $nUnitMeta = printflow_service_dimension_unit_meta_for_context($nCtx, $nestedField);
                                $nAllowOthers = $nestedField['allow_others'] ?? true;
                                
                                $html .= '<div class="shopee-opt-group mb-3">';
                                foreach ($nestedField['options'] ?? [] as $nOpt) {
                                    $nOptValue = is_array($nOpt) ? ($nOpt['value'] ?? '') : $nOpt;
                                    $nOptPrice = is_array($nOpt) ? (float)($nOpt['price'] ?? 0) : 0;
                                    $nOptValue = trim((string)$nOptValue);
                                    if ($nOptValue === '') {
                                        continue;
                                    }
                                    $parts = preg_split('/[×xX*\-\s]+/', $nOptValue, 2);
                                    if (count($parts) === 2) {
                                        $w = trim($parts[0]);
                                        $h = trim($parts[1]);
                                        $html .= '<button type="button" class="shopee-opt-btn" data-price="' . htmlspecialchars((string)$nOptPrice) . '" onclick="selectNestedDimension(\'' . $nestedKey . '\', ' . $w . ', ' . $h . ', event)">' . $w . '×' . $h . '</button>';
                                    }
                                }
                                if ($nAllowOthers) {
                                    $html .= '<button type="button" class="shopee-opt-btn pf-dim-custom-size-btn" data-price="0" onclick="selectNestedDimensionOthers(\'' . $nestedKey . '\', event)">' . htmlspecialchars(printflow_service_dimension_custom_size_label(), ENT_QUOTES, 'UTF-8') . '</button>';
                                }
                                $html .= '</div>';
                                
                                if ($nAllowOthers) {
                                    $html .= printflow_render_service_custom_size_panel([
                                        'field_key' => $nestedKey,
                                        'unit_meta' => $nUnitMeta,
                                        'visible' => false,
                                        'fixed_unit' => $nCtx['fixed_unit'],
                                        'selected_unit' => $nUnitMeta['code'],
                                        'unit_field_name' => $nCtx['unit_field_name'],
                                        'max_ft' => printflow_service_dimension_max_for_unit('ft', $nestedField),
                                        'max_in' => printflow_service_dimension_max_for_unit('in', $nestedField),
                                        'container_class' => 'dim-others-inputs pf-nested-custom-size',
                                        'container_id' => 'nested-dim-others-' . $nestedKey,
                                        'width_input_class' => 'custom-dim-width pf-nested-custom-w',
                                        'height_input_class' => 'custom-dim-height pf-nested-custom-h',
                                        'width_input_id' => 'nested-w-' . $nestedKey,
                                        'height_input_id' => 'nested-h-' . $nestedKey,
                                    ]);
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
            $ctx = printflow_service_field_custom_size_unit_context($field_key, $config);
            $unitMeta = printflow_service_dimension_unit_meta_for_context($ctx, $config);
            $allowOthers = $config['allow_others'] ?? true;
            
            $parsed = printflow_service_dimension_parse_pair($saved_value);
            $saved_width = $parsed['width'];
            $saved_height = $parsed['height'];
            $is_custom_dimension = false;
            
            if ($saved_width !== '' && $saved_height !== '') {
                $is_custom_dimension = !printflow_service_dimension_is_preset($config, $saved_width, $saved_height);
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
                    $pairParsed = printflow_service_dimension_parse_pair($option_value);
                    $w = $pairParsed['width'];
                    $h = $pairParsed['height'];
                    
                    if ($w !== '' && $h !== '') {
                        $displayLabel = $w . '×' . $h;
                        $is_active = (!$is_custom_dimension && $saved_width == $w && $saved_height == $h) ? ' active' : '';
                        $html .= '<button type="button" class="shopee-opt-btn pricing-field' . $is_active . '" data-price="' . htmlspecialchars((string)$option_price) . '" data-width="' . htmlspecialchars($w) . '" data-height="' . htmlspecialchars($h) . '" data-dimension-key="' . htmlspecialchars($field_key) . '" data-dimension-choice="1" onclick="var r=this.closest(\'.shopee-form-row\');if(r){r.querySelectorAll(\'.shopee-opt-btn\').forEach(function(b){b.classList.remove(\'active\')});this.classList.add(\'active\');var o=r.querySelector(\'.dim-others-inputs\');if(o)o.style.display=\'none\';var cw=r.querySelector(\'.custom-dim-width\');var ch=r.querySelector(\'.custom-dim-height\');if(cw)cw.value=\'\';if(ch)ch.value=\'\';var w=r.querySelector(\'[data-dimension-role=width]\')||r.querySelector(\'input[name=width]\');var h=r.querySelector(\'[data-dimension-role=height]\')||r.querySelector(\'input[name=height]\');if(w)w.value=this.dataset.width||\'\';if(h)h.value=this.dataset.height||\'\';var lw=r.querySelector(\'input[name=width]\');var lh=r.querySelector(\'input[name=height]\');if(lw)lw.value=this.dataset.width||\'\';if(lh)lh.value=this.dataset.height||\'\';}if(window.calculateEstimatedPrice)window.calculateEstimatedPrice();return false;">' . htmlspecialchars($displayLabel) . '</button>';
                    }
                }
            }
            
            if ($allowOthers) {
                $others_active = $is_custom_dimension ? ' active' : '';
                $customLabel = htmlspecialchars(printflow_service_dimension_custom_size_label(), ENT_QUOTES, 'UTF-8');
                $html .= '<button type="button" class="shopee-opt-btn dim-others-btn pf-dim-custom-size-btn' . $others_active . '" data-dimension-key="' . htmlspecialchars($field_key) . '" data-dimension-others="1" onclick="var r=this.closest(\'.shopee-form-row\');if(r){r.querySelectorAll(\'.shopee-opt-btn\').forEach(function(b){b.classList.remove(\'active\')});this.classList.add(\'active\');var o=r.querySelector(\'.dim-others-inputs\');if(o)o.style.display=\'block\';var w=r.querySelector(\'[data-dimension-role=width]\')||r.querySelector(\'input[name=width]\');var h=r.querySelector(\'[data-dimension-role=height]\')||r.querySelector(\'input[name=height]\');if(w)w.value=\'\';if(h)h.value=\'\';var lw=r.querySelector(\'input[name=width]\');var lh=r.querySelector(\'input[name=height]\');if(lw)lw.value=\'\';if(lh)lh.value=\'\';}if(window.calculateEstimatedPrice)window.calculateEstimatedPrice();return false;">' . $customLabel . '</button>';
            }
            $html .= '</div>';
            
            if ($allowOthers) {
                $html .= printflow_render_service_custom_size_panel([
                    'field_key' => $field_key,
                    'unit_meta' => $unitMeta,
                    'visible' => $is_custom_dimension,
                    'saved_width' => $is_custom_dimension ? $saved_width : '',
                    'saved_height' => $is_custom_dimension ? $saved_height : '',
                    'fixed_unit' => $ctx['fixed_unit'],
                    'selected_unit' => $unitMeta['code'],
                    'unit_field_name' => $ctx['unit_field_name'],
                    'max_ft' => printflow_service_dimension_max_for_unit('ft', $config),
                    'max_in' => printflow_service_dimension_max_for_unit('in', $config),
                ]);
            }
            
            $html .= '<input type="hidden" id="' . htmlspecialchars($field_key) . '_width_hidden" data-dimension-role="width" data-dimension-key="' . htmlspecialchars($field_key) . '" name="' . htmlspecialchars($field_key) . '_width" value="' . htmlspecialchars($saved_width) . '" ' . $required_attr . '>';
            $html .= '<input type="hidden" id="' . htmlspecialchars($field_key) . '_height_hidden" data-dimension-role="height" data-dimension-key="' . htmlspecialchars($field_key) . '" name="' . htmlspecialchars($field_key) . '_height" value="' . htmlspecialchars($saved_height) . '" ' . $required_attr . '>';
            $html .= '<input type="hidden" id="width_hidden" name="width" value="' . htmlspecialchars($saved_width) . '">';
            $html .= '<input type="hidden" id="height_hidden" name="height" value="' . htmlspecialchars($saved_height) . '">';
            if (!$allowOthers) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($ctx['unit_field_name'], ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($unitMeta['code'], ENT_QUOTES, 'UTF-8') . '">';
            }
            break;
            
        case 'file':
            $link_post_name = function_exists('service_order_design_link_post_name')
                ? service_order_design_link_post_name($field_key)
                : ($field_key . '_link');
            $link_storage_key = function_exists('service_order_design_link_storage_key')
                ? service_order_design_link_storage_key((string)($config['label'] ?? 'Design'))
                : 'Design Link';
            $saved_link = trim((string)(
                $saved_customization[$link_storage_key]
                ?? $saved_customization[$link_post_name]
                ?? $saved_customization['design_link']
                ?? ''
            ));
            $accept_attr = function_exists('service_order_design_file_accept_attr')
                ? service_order_design_file_accept_attr()
                : '.jpg,.jpeg,.png,.webp,.gif,.svg,.pdf,.ai,.psd';
            $formats_label = defined('SERVICE_ORDER_SUPPORTED_FORMATS_LABEL')
                ? SERVICE_ORDER_SUPPORTED_FORMATS_LABEL
                : 'PNG, JPG, JPEG, WEBP, GIF, SVG, PDF, AI, PSD';
            $required_data = $config['required'] ? ' data-pf-required="1"' : '';
            $initial_mode = $saved_link !== '' ? 'link' : 'file';

            $html .= '<div class="pf-file-upload-group" data-pf-file-upload="1" data-pf-design-initial="' . htmlspecialchars($initial_mode, ENT_QUOTES, 'UTF-8') . '"' . $required_data . ' style="max-width:100%;width:100%;">';
            $html .= '<p class="pf-design-mode-question">How would you like to provide your design?</p>';
            $html .= '<div class="pf-design-mode-tabs" role="tablist" aria-label="Design input method">';
            $html .= '<button type="button" class="pf-design-mode-tab' . ($initial_mode === 'file' ? ' active' : '') . '" data-pf-design-mode="file" role="tab" aria-selected="' . ($initial_mode === 'file' ? 'true' : 'false') . '"><span aria-hidden="true">📁</span> Upload File</button>';
            $html .= '<button type="button" class="pf-design-mode-tab' . ($initial_mode === 'link' ? ' active' : '') . '" data-pf-design-mode="link" role="tab" aria-selected="' . ($initial_mode === 'link' ? 'true' : 'false') . '"><span aria-hidden="true">🔗</span> Use a Link</button>';
            $html .= '</div>';

            $html .= '<div class="pf-design-mode-panel" data-pf-design-panel="file"' . ($initial_mode === 'link' ? ' hidden' : '') . '>';
            $html .= '<div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">Upload your design</div>';
            $html .= '<input type="file" name="design_file" id="design_file" accept="' . htmlspecialchars($accept_attr, ENT_QUOTES, 'UTF-8') . '" class="input-field pf-design-file-input" style="max-width:100%;width:100%;margin-bottom:8px;">';
            $html .= '<p class="pf-supported-formats" style="margin:0;font-size:12px;color:#6b7280;line-height:1.5;">Supported: ' . htmlspecialchars($formats_label, ENT_QUOTES, 'UTF-8') . '<br>Maximum file size: 5 MB</p>';
            $html .= '</div>';

            $html .= '<div class="pf-design-mode-panel" data-pf-design-panel="link"' . ($initial_mode === 'link' ? '' : ' hidden') . '>';
            $html .= '<label for="' . htmlspecialchars($link_post_name, ENT_QUOTES, 'UTF-8') . '" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">Design / Canva Link</label>';
            $html .= '<input type="url" name="' . htmlspecialchars($link_post_name, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($link_post_name, ENT_QUOTES, 'UTF-8') . '" class="input-field pf-design-link-input" placeholder="https://..." value="' . htmlspecialchars($saved_link, ENT_QUOTES, 'UTF-8') . '" inputmode="url" autocomplete="url" style="max-width:100%;width:100%;">';
            $html .= '<p style="margin:8px 0 0;font-size:12px;color:#9ca3af;line-height:1.5;">Paste a publicly accessible design, image, or Canva link.</p>';
            $html .= '</div>';
            $html .= '</div>';
            break;
            
        case 'date':
            $html .= '<div class="shopee-opt-group">';
            $html .= '<input type="date" name="' . htmlspecialchars($field_key) . '" id="' . htmlspecialchars($field_key) . '" class="shopee-opt-btn" ' . $required_attr . ' min="' . date('Y-m-d') . '" value="' . htmlspecialchars($saved_value) . '" style="cursor: pointer; width: 175px;">';
            $html .= '</div>';
            break;
            
        case 'quantity':
            $saved_qty = (int)($saved_value !== '' && $saved_value !== null ? $saved_value : 1);
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
    $css = <<<'CSS'
.pf-file-upload-group {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px 16px;
    background: #fafafa;
    box-sizing: border-box;
}
.pf-design-mode-question {
    margin: 0 0 10px;
    font-size: 13px;
    color: #4b5563;
    font-weight: 500;
    line-height: 1.4;
}
.pf-design-mode-tabs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 14px;
}
.pf-design-mode-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    color: #374151;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    min-height: 44px;
    width: 100%;
    box-sizing: border-box;
}
.pf-design-mode-tab:hover {
    border-color: #c7d2fe;
    background: #f8fafc;
}
.pf-design-mode-tab.active {
    border-color: #6366f1;
    background: #eef2ff;
    color: #4338ca;
    box-shadow: 0 0 0 1px #6366f1;
}
.pf-design-mode-panel {
    border-top: 1px dashed #e5e7eb;
    padding-top: 14px;
}
.pf-design-mode-panel[hidden] {
    display: none !important;
}
@media (max-width: 480px) {
    .pf-design-mode-tab {
        font-size: 12px;
        padding: 10px 8px;
    }
}
CSS;
    $css .= printflow_service_custom_size_styles();
    $css .= printflow_service_field_help_styles();
    $js = <<<'JS'
function initPfFieldHelpTooltips(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-pf-field-help="1"]').forEach(function(wrap) {
        if (wrap.dataset.pfFieldHelpBound === '1') return;
        wrap.dataset.pfFieldHelpBound = '1';
        const btn = wrap.querySelector('.pf-field-help-trigger');
        const tip = wrap.querySelector('.pf-field-help-tooltip');
        if (!btn || !tip) return;
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const open = wrap.classList.contains('is-open');
            document.querySelectorAll('.pf-field-help.is-open').forEach(function(other) {
                if (other === wrap) return;
                other.classList.remove('is-open');
                const ob = other.querySelector('.pf-field-help-trigger');
                const ot = other.querySelector('.pf-field-help-tooltip');
                if (ob) ob.setAttribute('aria-expanded', 'false');
                if (ot) ot.hidden = true;
            });
            if (open) {
                wrap.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
                tip.hidden = true;
            } else {
                wrap.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
                tip.hidden = false;
            }
        });
        wrap.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    if (!document.body.dataset.pfFieldHelpDocBound) {
        document.body.dataset.pfFieldHelpDocBound = '1';
        document.addEventListener('click', function() {
            document.querySelectorAll('.pf-field-help.is-open').forEach(function(wrap) {
                wrap.classList.remove('is-open');
                const btn = wrap.querySelector('.pf-field-help-trigger');
                const tip = wrap.querySelector('.pf-field-help-tooltip');
                if (btn) btn.setAttribute('aria-expanded', 'false');
                if (tip) tip.hidden = true;
            });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('.pf-field-help.is-open').forEach(function(wrap) {
                wrap.classList.remove('is-open');
                const btn = wrap.querySelector('.pf-field-help-trigger');
                const tip = wrap.querySelector('.pf-field-help-tooltip');
                if (btn) btn.setAttribute('aria-expanded', 'false');
                if (tip) tip.hidden = true;
            });
        });
    }
}

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
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
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
    const show = val === 'Others';
    wrap.style.display = show ? 'block' : 'none';
    if (wrap.querySelector('.pf-custom-size-panel')) {
        if (!show) {
            wrap.querySelectorAll('.custom-dim-width, .custom-dim-height').forEach(function (el) { el.value = ''; });
        }
        const fakeSelect = { name: radio.name };
        pfSyncSelectCustomSizeHidden(fakeSelect);
        pfValidateCustomSizePanel(wrap.querySelector('.pf-custom-size-panel'), show);
        return;
    }
    const input = wrap.querySelector('input');
    if (input) {
        if (!show) input.value = '';
        const selectedRadio = row.querySelector('input[type="radio"].pricing-field[name="' + radio.name + '"]:checked');
        input.required = !!(show && selectedRadio && selectedRadio.hasAttribute('required'));
    }
}

function pfSyncSelectCustomSizeHidden(select) {
    if (!select || !select.name) return;
    const wrap = document.getElementById('select-others-' + select.name);
    if (!wrap || !wrap.querySelector('.pf-custom-size-panel')) return;
    const panel = wrap.querySelector('.pf-custom-size-panel');
    pfRefreshCustomSizePanelUnit(panel);
    const wEl = wrap.querySelector('.custom-dim-width');
    const hEl = wrap.querySelector('.custom-dim-height');
    const w = pfSanitizeDimensionInputValue(wEl ? wEl.value : '');
    const h = pfSanitizeDimensionInputValue(hEl ? hEl.value : '');
    if (wEl) wEl.value = w;
    if (hEl) hEl.value = h;
    const wHidden = wrap.querySelector('[data-dimension-role="width"]');
    const hHidden = wrap.querySelector('[data-dimension-role="height"]');
    const otherHidden = wrap.querySelector('.pf-custom-size-other-hidden');
    if (wHidden) wHidden.value = w;
    if (hHidden) hHidden.value = h;
    const unitShort = panel ? pfGetCustomSizePanelUnitShort(panel) : 'ft';
    if (otherHidden && w && h) {
        otherHidden.value = 'Custom Size — ' + w + ' × ' + h + ' ' + unitShort;
    } else if (otherHidden && (!w || !h)) {
        otherHidden.value = '';
    }
}

function pfSyncSelectOthersWrap(select) {
    if (!select || !select.name) return;
    const wrap = document.getElementById('select-others-' + select.name);
    if (!wrap) return;
    const otherValue = select.getAttribute('data-other-option') || 'Others';
    const show = select.value === otherValue;
    wrap.style.display = show ? 'block' : 'none';
    if (wrap.querySelector('.pf-custom-size-panel')) {
        if (!show) {
            wrap.querySelectorAll('.custom-dim-width, .custom-dim-height').forEach(function (el) { el.value = ''; });
            pfSyncSelectCustomSizeHidden(select);
        }
        pfValidateCustomSizePanel(wrap.querySelector('.pf-custom-size-panel'), show);
        return;
    }
    const input = wrap.querySelector('input');
    if (input) {
        if (!show) input.value = '';
        if (select.hasAttribute('required')) {
            input.required = show;
        }
    }
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
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
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
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function syncNestedDimension(key) {
    const panel = document.getElementById('nested-dim-others-' + key);
    const w = document.getElementById('nested-w-' + key)?.value || '';
    const h = document.getElementById('nested-h-' + key)?.value || '';
    const hidden = document.getElementById('nested-hidden-' + key);
    if (hidden && w && h) {
        hidden.value = w + 'x' + h;
    } else if (hidden) {
        hidden.value = '';
    }
    pfClearCustomSizePanelError(panel);
    if (typeof window.calculateEstimatedPrice === 'function') window.calculateEstimatedPrice();
}

function pfSanitizeDimensionInputValue(raw) {
    let v = String(raw || '').replace(/[^\d.]/g, '');
    const parts = v.split('.');
    if (parts.length > 2) {
        v = parts.shift() + '.' + parts.join('');
    }
    if (v.startsWith('.')) v = '0' + v;
    return v;
}

function pfGetCustomSizePanelMax(panel) {
    if (!panel) return 100;
    const max = parseFloat(panel.getAttribute('data-dimension-max') || '100');
    return isNaN(max) ? 100 : max;
}

function pfGetCustomSizePanelUnitShort(panel) {
    if (!panel) return 'ft';
    return panel.getAttribute('data-dimension-unit') || 'ft';
}

function pfRefreshCustomSizePanelUnit(panel) {
    if (!panel) return;
    const select = panel.querySelector('.pf-custom-size-unit-select');
    let unit = panel.getAttribute('data-dimension-unit') || 'ft';
    if (select) {
        unit = select.value === 'in' ? 'in' : 'ft';
    } else {
        const hidden = panel.querySelector('.pf-custom-size-unit-input');
        if (hidden && hidden.value) {
            unit = hidden.value === 'in' ? 'in' : 'ft';
        }
    }
    panel.setAttribute('data-dimension-unit', unit);
    const maxFt = parseFloat(panel.getAttribute('data-dimension-max-ft') || '100');
    const maxIn = parseFloat(panel.getAttribute('data-dimension-max-in') || '1200');
    const max = unit === 'in' ? maxIn : maxFt;
    panel.setAttribute('data-dimension-max', String(isNaN(max) ? 100 : max));
    const short = unit === 'in' ? 'in' : 'ft';
    const wLabel = panel.querySelector('.pf-custom-size-width-label');
    const hLabel = panel.querySelector('.pf-custom-size-height-label');
    if (wLabel) wLabel.textContent = 'Width (' + short + ')';
    if (hLabel) hLabel.textContent = 'Height (' + short + ')';
    const ew = panel.getAttribute('data-example-w-' + unit) || (unit === 'in' ? '8' : '2');
    const eh = panel.getAttribute('data-example-h-' + unit) || (unit === 'in' ? '11' : '3');
    const ex = panel.querySelector('.pf-custom-size-example');
    if (ex) ex.textContent = 'Example: ' + ew + ' × ' + eh + ' ' + short;
    const wInput = panel.querySelector('.custom-dim-width');
    const hInput = panel.querySelector('.custom-dim-height');
    if (wInput) wInput.placeholder = 'e.g. ' + ew;
    if (hInput) hInput.placeholder = 'e.g. ' + eh;
}

function pfValidateCustomSizePanel(panel, showError) {
    if (!panel || panel.style.display === 'none') {
        return { ok: true };
    }
    const wEl = panel.querySelector('.custom-dim-width, .pf-nested-custom-w');
    const hEl = panel.querySelector('.custom-dim-height, .pf-nested-custom-h');
    const w = pfSanitizeDimensionInputValue(wEl ? wEl.value : '');
    const h = pfSanitizeDimensionInputValue(hEl ? hEl.value : '');
    const errEl = panel.querySelector('.pf-custom-size-error');
    const max = pfGetCustomSizePanelMax(panel);
    const unitShort = pfGetCustomSizePanelUnitShort(panel);
    let message = '';
    if (!w || !h) {
        message = 'Please enter width and height for your custom size.';
    } else if (parseFloat(w) <= 0 || parseFloat(h) <= 0) {
        message = 'Width and height must be positive numbers.';
    } else if (parseFloat(w) > max || parseFloat(h) > max) {
        message = 'Maximum allowed size is ' + max + ' ' + unitShort + '.';
    }
    if (message && showError && errEl) {
        errEl.textContent = message;
        errEl.hidden = false;
    } else if (errEl) {
        errEl.textContent = '';
        errEl.hidden = true;
    }
    return message ? { ok: false, message: message } : { ok: true };
}

function pfClearCustomSizePanelError(panel) {
    if (!panel) return;
    const errEl = panel.querySelector('.pf-custom-size-error');
    if (errEl) {
        errEl.textContent = '';
        errEl.hidden = true;
    }
}

function validateDimensionInput(input) {
    input.value = pfSanitizeDimensionInputValue(input.value);
    const row = input.closest('.shopee-form-row');
    const panel = input.closest('.pf-custom-size-panel');
    pfValidateCustomSizePanel(panel, true);
    syncDimensionToHidden(row);
}

function pfRowUsesCustomSize(row) {
    if (!row) return false;
    return !!row.querySelector('.dim-others-btn.active, .pf-dim-custom-size-btn.active');
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
    
    if (pfRowUsesCustomSize(scope)) {
        const panel = scope.querySelector('.dim-others-inputs.pf-custom-size-panel, .pf-custom-size-panel.dim-others-inputs, .pf-custom-size-panel');
        const cw = panel ? panel.querySelector('.custom-dim-width') : scope.querySelector('.custom-dim-width');
        const ch = panel ? panel.querySelector('.custom-dim-height') : scope.querySelector('.custom-dim-height');
        wh.value = pfSanitizeDimensionInputValue(cw ? cw.value : '');
        hh.value = pfSanitizeDimensionInputValue(ch ? ch.value : '');
    } else {
        const btn = scope.querySelector('.shopee-opt-btn.active[data-width]');
        if (btn && btn.dataset.width) {
            wh.value = btn.dataset.width;
            hh.value = btn.dataset.height;
        } else {
            wh.value = '';
            hh.value = '';
        }
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
window.pfSyncSelectOthersWrap = pfSyncSelectOthersWrap;
window.pfRefreshCustomSizePanelUnit = pfRefreshCustomSizePanelUnit;
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
        const select = e.target.closest('select.pf-select-with-others');
        if (select) {
            pfSyncSelectOthersWrap(select);
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

function initPfDesignUploadGroups(root) {
    const scope = root || document;
    scope.querySelectorAll('.pf-file-upload-group:not([data-pf-design-init])').forEach(group => {
        group.dataset.pfDesignInit = '1';
        const tabs = Array.from(group.querySelectorAll('.pf-design-mode-tab'));
        const panels = Array.from(group.querySelectorAll('.pf-design-mode-panel'));
        const setMode = (mode) => {
            const nextMode = mode === 'link' ? 'link' : 'file';
            group.dataset.pfDesignMode = nextMode;
            tabs.forEach(tab => {
                const active = (tab.dataset.pfDesignMode || 'file') === nextMode;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(panel => {
                const show = (panel.dataset.pfDesignPanel || 'file') === nextMode;
                if (show) panel.removeAttribute('hidden');
                else panel.setAttribute('hidden', '');
            });
            const row = group.closest('.shopee-form-row');
            if (row) {
                row.querySelectorAll('.field-error').forEach(el => el.remove());
                row.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
            }
        };
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                setMode(this.dataset.pfDesignMode || 'file');
            });
        });
        const fileInput = group.querySelector('input[type="file"].pf-design-file-input, input[type="file"][name="design_file"]');
        const linkInput = group.querySelector('.pf-design-link-input');
        const clearLinkInput = () => {
            if (!linkInput) return;
            linkInput.value = '';
            linkInput.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const clearFileInput = () => {
            if (!fileInput) return;
            fileInput.value = '';
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        };
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    clearLinkInput();
                    setMode('file');
                }
            });
        }
        if (linkInput) {
            linkInput.addEventListener('input', function() {
                if (String(this.value || '').trim() !== '') {
                    clearFileInput();
                    setMode('link');
                }
            });
        }
        setMode(group.dataset.pfDesignInitial || 'file');
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
        if (select.classList && select.classList.contains('pf-select-with-others')) {
            pfSyncSelectOthersWrap(select);
        }
        if (select.dataset.pfServiceFieldBound === '1') return;
        select.dataset.pfServiceFieldBound = '1';
        select.addEventListener('change', function() {
            if (this.classList && this.classList.contains('pf-select-with-others')) {
                pfSyncSelectOthersWrap(this);
            }
            updateConditionalFields();
        });
    });
    
    document.querySelectorAll('.custom-dim-width, .custom-dim-height').forEach(input => {
        if (input.dataset.pfServiceFieldBound === '1') return;
        input.dataset.pfServiceFieldBound = '1';
        input.addEventListener('input', function() {
            input.value = pfSanitizeDimensionInputValue(input.value);
            const row = input.closest('.shopee-form-row');
            const panel = input.closest('.pf-custom-size-panel');
            pfRefreshCustomSizePanelUnit(panel);
            pfValidateCustomSizePanel(panel, true);
            syncDimensionToHidden(row);
            const selectWrap = input.closest('.select-others-wrap, .radio-others-wrap');
            if (selectWrap && selectWrap.id) {
                const fieldKey = selectWrap.id.replace(/^(select|radio)-others-/, '');
                const sel = document.querySelector('select[name="' + fieldKey + '"]');
                if (sel) pfSyncSelectCustomSizeHidden(sel);
            }
            const nestedPanel = input.closest('.pf-nested-custom-size, .pf-custom-size-panel');
            if (nestedPanel && nestedPanel.id && nestedPanel.id.indexOf('nested-dim-others-') === 0) {
                syncNestedDimension(nestedPanel.id.replace('nested-dim-others-', ''));
            }
        });
    });

    document.querySelectorAll('.pf-custom-size-panel').forEach(function(panel) {
        pfRefreshCustomSizePanelUnit(panel);
    });
    document.querySelectorAll('.pf-custom-size-unit-select').forEach(function(sel) {
        if (sel.dataset.pfServiceFieldBound === '1') return;
        sel.dataset.pfServiceFieldBound = '1';
        sel.addEventListener('change', function() {
            const panel = sel.closest('.pf-custom-size-panel');
            pfRefreshCustomSizePanelUnit(panel);
            pfValidateCustomSizePanel(panel, true);
            const row = panel ? panel.closest('.shopee-form-row') : null;
            if (row) syncDimensionToHidden(row);
            const wrap = panel ? panel.closest('.select-others-wrap, .radio-others-wrap') : null;
            if (wrap && wrap.id) {
                const fieldKey = wrap.id.replace(/^(select|radio)-others-/, '');
                const mainSel = document.querySelector('select[name="' + fieldKey + '"]');
                if (mainSel) pfSyncSelectCustomSizeHidden(mainSel);
            }
        });
    });
    
    // Run once on load to show initial state
    updateConditionalFields();
    initPfDesignUploadGroups();
    initPfFieldHelpTooltips(document);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initServiceFieldRenderer);
} else {
    initServiceFieldRenderer();
}
document.addEventListener('turbo:load', initServiceFieldRenderer);
JS;
    return '<style>' . $css . '</style><script>' . $js . '</script>';
}
