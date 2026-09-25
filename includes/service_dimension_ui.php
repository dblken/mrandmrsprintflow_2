<?php
/**
 * Shared custom-size UX for service dimension fields (customer-facing).
 */

const PRINTFLOW_CUSTOM_SIZE_LABEL = 'Custom Size';

/** Default max edge length when not overridden in field config. */
const PRINTFLOW_SERVICE_DIMENSION_MAX_FT = 100.0;
const PRINTFLOW_SERVICE_DIMENSION_MAX_IN = 1200.0;

function printflow_service_dimension_custom_size_label(): string
{
    return PRINTFLOW_CUSTOM_SIZE_LABEL;
}

function printflow_service_dimension_normalize_unit(?string $unit): string
{
    $u = strtolower(trim((string) $unit));
    if (in_array($u, ['in', 'inch', 'inches', '"'], true)) {
        return 'in';
    }
    return 'ft';
}

/**
 * @return array{code:string,label:string,short:string,example_w:string,example_h:string,max:float}
 */
function printflow_service_dimension_unit_meta(string $unit, ?array $fieldConfig = null): array
{
    $code = printflow_service_dimension_normalize_unit($unit);
    $max = printflow_service_dimension_max_for_unit($code, $fieldConfig);
    if ($code === 'in') {
        return [
            'code' => 'in',
            'label' => 'Inches',
            'short' => 'in',
            'example_w' => '8.5',
            'example_h' => '11',
            'max' => $max,
        ];
    }
    return [
        'code' => 'ft',
        'label' => 'Feet',
        'short' => 'ft',
        'example_w' => '2',
        'example_h' => '3',
        'max' => $max,
    ];
}

function printflow_service_dimension_max_for_unit(string $unit, ?array $fieldConfig = null): float
{
    $code = printflow_service_dimension_normalize_unit($unit);
    if (is_array($fieldConfig)) {
        if ($code === 'in' && isset($fieldConfig['max_dimension_in']) && is_numeric($fieldConfig['max_dimension_in'])) {
            return (float) $fieldConfig['max_dimension_in'];
        }
        if ($code === 'ft' && isset($fieldConfig['max_dimension_ft']) && is_numeric($fieldConfig['max_dimension_ft'])) {
            return (float) $fieldConfig['max_dimension_ft'];
        }
        if (isset($fieldConfig['max_dimension']) && is_numeric($fieldConfig['max_dimension'])) {
            return (float) $fieldConfig['max_dimension'];
        }
    }
    return $code === 'in' ? PRINTFLOW_SERVICE_DIMENSION_MAX_IN : PRINTFLOW_SERVICE_DIMENSION_MAX_FT;
}

function printflow_service_dimension_parse_pair(?string $saved): array
{
    $saved_width = '';
    $saved_height = '';
    if (!$saved) {
        return ['width' => '', 'height' => ''];
    }
    $dim_value = preg_replace('/\s*(ft|in|cm|m|feet|foot|inches|inch)\s*$/iu', '', trim((string) $saved));
    $dim_value = str_replace(['×', 'X', '*', '-'], 'x', $dim_value);
    $parts = explode('x', $dim_value);
    if (count($parts) === 2) {
        $saved_width = trim($parts[0]);
        $saved_height = trim($parts[1]);
    }
    return ['width' => $saved_width, 'height' => $saved_height];
}

function printflow_service_dimension_is_preset(array $config, string $width, string $height): bool
{
    $width = trim($width);
    $height = trim($height);
    if ($width === '' || $height === '') {
        return false;
    }
    foreach ($config['options'] ?? [] as $option) {
        $option_value = is_array($option) ? ($option['value'] ?? '') : $option;
        $option_value = trim((string) $option_value);
        if ($option_value === '' || strcasecmp($option_value, 'Others') === 0) {
            continue;
        }
        $opt_normalized = str_replace(['×', 'X', '*', '-'], 'x', $option_value);
        $parts = explode('x', $opt_normalized);
        if (count($parts) !== 2) {
            continue;
        }
        $w = trim($parts[0]);
        $h = trim($parts[1]);
        if ($w !== '' && $h !== '' && (float) $w === (float) $width && (float) $h === (float) $height) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{ok:bool,message?:string,width?:string,height?:string}
 */
function printflow_service_dimension_validate(?string $width, ?string $height, string $unit, ?array $fieldConfig = null): array
{
    $w = trim((string) $width);
    $h = trim((string) $height);
    if ($w === '' || $h === '') {
        return ['ok' => false, 'message' => 'Please enter width and height for your custom size.'];
    }
    if (!is_numeric($w) || (float) $w <= 0) {
        return ['ok' => false, 'message' => 'Width must be a positive number.'];
    }
    if (!is_numeric($h) || (float) $h <= 0) {
        return ['ok' => false, 'message' => 'Height must be a positive number.'];
    }
    $meta = printflow_service_dimension_unit_meta($unit, $fieldConfig);
    $max = (float) $meta['max'];
    $wf = (float) $w;
    $hf = (float) $h;
    if ($wf > $max || $hf > $max) {
        $short = $meta['short'];
        return ['ok' => false, 'message' => 'Maximum allowed size is ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . ' ' . $short . '.'];
    }
    return ['ok' => true, 'width' => $w, 'height' => $h];
}

function printflow_service_dimension_format_storage(string $width, string $height, string $unit, bool $isCustom): string
{
    $meta = printflow_service_dimension_unit_meta($unit);
    $w = trim($width);
    $h = trim($height);
    if ($isCustom) {
        return printflow_service_dimension_custom_size_label() . ' — ' . $w . ' × ' . $h . ' ' . $meta['short'];
    }
    return $w . '×' . $h . ' ' . $meta['code'];
}

/**
 * Select/radio fields labeled as dimensions/sizes with "Others" use the guided custom-size panel.
 */
function printflow_service_field_uses_custom_size_panel(string $field_key, array $config): bool
{
    $type = $config['type'] ?? '';
    if (!in_array($type, ['select', 'radio'], true) || $field_key === 'branch') {
        return false;
    }
    $hasOthers = !empty($config['allow_others']);
    if (!$hasOthers) {
        foreach ($config['options'] ?? [] as $opt) {
            $v = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            if (strcasecmp(trim((string) $v), 'Others') === 0) {
                $hasOthers = true;
                break;
            }
        }
    }
    if (!$hasOthers) {
        return false;
    }
    $key = strtolower($field_key);
    $label = strtolower(trim((string) ($config['label'] ?? '')));
    $blob = $key . ' ' . $label;
    if (preg_match('/\b(finish|lamination|laminate|material|surface|color|colour)\b/', $blob)
        && !preg_match('/\b(dimension|size)\b/', $blob)) {
        return false;
    }
    if (preg_match('/\b(dimension|dimensions|size|sizes)\b/', $blob)) {
        return true;
    }
    if (!empty($config['unit']) && ($type === 'select' || $type === 'radio')) {
        return preg_match('/\b(dimension|size)\b/', $blob) === 1;
    }
    return false;
}

function printflow_service_field_resolved_unit(string $field_key, array $config): string
{
    $label = strtolower(trim((string) ($config['label'] ?? '')));
    if (str_contains($label, '(ft)') || str_contains($label, 'feet') || str_contains($label, 'foot')) {
        return 'ft';
    }
    if (str_contains($label, '(in)') || str_contains($label, 'inch')) {
        return 'in';
    }
    foreach ($config['options'] ?? [] as $opt) {
        $v = strtolower(trim(is_array($opt) ? (string) ($opt['value'] ?? '') : (string) $opt));
        if ($v === '' || strcasecmp($v, 'others') === 0) {
            continue;
        }
        if (preg_match('/\b(a4|a3|a5|letter|legal|pcs|mm)\b/', $v)) {
            return 'in';
        }
    }
    $fromConfig = printflow_service_dimension_normalize_unit($config['unit'] ?? 'ft');
    if (printflow_service_field_uses_custom_size_panel($field_key, $config) && $fromConfig === 'ft') {
        if (!str_contains($label, 'tarp') && !str_contains($label, 'large-format') && !str_contains($label, 'large format')) {
            return 'in';
        }
    }
    return $fromConfig;
}

function printflow_service_custom_size_option_label(string $optionValue, string $field_key, array $config): string
{
    if (strcasecmp(trim($optionValue), 'Others') === 0 && printflow_service_field_uses_custom_size_panel($field_key, $config)) {
        return printflow_service_dimension_custom_size_label();
    }
    return $optionValue;
}

/**
 * @return array{width:string,height:string}
 */
function printflow_service_parse_custom_size_saved_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return ['width' => '', 'height' => ''];
    }
    if (preg_match('/^Custom Size\s*[—–-]\s*(.+)$/iu', $text, $m)) {
        $text = trim($m[1]);
    }
    return printflow_service_dimension_parse_pair($text);
}

function printflow_render_field_custom_size_others_wrap(
    string $field_key,
    array $config,
    bool $show,
    string $savedOtherText,
    string $wrapClass,
    string $wrapId
): string {
    $unit = printflow_service_field_resolved_unit($field_key, $config);
    $unitMeta = printflow_service_dimension_unit_meta($unit, $config);
    $parsed = printflow_service_parse_custom_size_saved_text($savedOtherText);
    $html = '<div class="' . htmlspecialchars($wrapClass, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') . '" style="margin-top:12px;display:' . ($show ? 'block' : 'none') . ';">';
    $html .= printflow_render_service_custom_size_panel([
        'field_key' => $field_key,
        'unit_meta' => $unitMeta,
        'visible' => true,
        'saved_width' => $parsed['width'],
        'saved_height' => $parsed['height'],
        'fixed_unit' => true,
        'selected_unit' => $unitMeta['code'],
        'container_class' => 'dim-others-inputs pf-custom-size-in-select',
        'width_input_class' => 'custom-dim-width pf-custom-size-width',
        'height_input_class' => 'custom-dim-height pf-custom-size-height',
    ]);
    $html .= '<input type="hidden" name="' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '_other" class="pf-custom-size-other-hidden" value="' . htmlspecialchars($savedOtherText, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<input type="hidden" name="' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '_width" data-dimension-role="width" data-dimension-key="' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($parsed['width'], ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<input type="hidden" name="' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '_height" data-dimension-role="height" data-dimension-key="' . htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($parsed['height'], ENT_QUOTES, 'UTF-8') . '">';
    $html .= '</div>';
    return $html;
}

function printflow_resolve_custom_size_from_post(string $field_key, array $config): array
{
    $w = trim((string) ($_POST[$field_key . '_width'] ?? ''));
    $h = trim((string) ($_POST[$field_key . '_height'] ?? ''));
    if ($w === '' && $h === '') {
        $legacy = trim((string) ($_POST[$field_key . '_other'] ?? ''));
        $parsed = printflow_service_parse_custom_size_saved_text($legacy);
        $w = $parsed['width'];
        $h = $parsed['height'];
    }
    $unit = printflow_service_field_resolved_unit($field_key, $config);
    $check = printflow_service_dimension_validate($w, $h, $unit, $config);
    if (!$check['ok']) {
        return $check;
    }
    return [
        'ok' => true,
        'value' => printflow_service_dimension_format_storage($check['width'] ?? $w, $check['height'] ?? $h, $unit, true),
        'width' => $check['width'] ?? $w,
        'height' => $check['height'] ?? $h,
    ];
}

/**
 * Guided custom-size panel HTML (hidden until Custom Size is selected).
 *
 * @param array<string,mixed> $options
 */
function printflow_render_service_custom_size_panel(array $options): string
{
    $fieldKey = (string) ($options['field_key'] ?? '');
    $unitMeta = $options['unit_meta'] ?? printflow_service_dimension_unit_meta('ft');
    if (!is_array($unitMeta)) {
        $unitMeta = printflow_service_dimension_unit_meta('ft');
    }
    $visible = !empty($options['visible']);
    $savedW = trim((string) ($options['saved_width'] ?? ''));
    $savedH = trim((string) ($options['saved_height'] ?? ''));
    $containerClass = trim((string) ($options['container_class'] ?? 'dim-others-inputs'));
    $containerId = trim((string) ($options['container_id'] ?? ''));
    $widthInputClass = trim((string) ($options['width_input_class'] ?? 'custom-dim-width'));
    $heightInputClass = trim((string) ($options['height_input_class'] ?? 'custom-dim-height'));
    $widthInputId = trim((string) ($options['width_input_id'] ?? ''));
    $heightInputId = trim((string) ($options['height_input_id'] ?? ''));
    $unitFieldName = trim((string) ($options['unit_field_name'] ?? 'unit'));
    $fixedUnit = !empty($options['fixed_unit']);
    $selectedUnit = printflow_service_dimension_normalize_unit((string) ($options['selected_unit'] ?? ($unitMeta['code'] ?? 'ft')));

    $max = (float) ($unitMeta['max'] ?? PRINTFLOW_SERVICE_DIMENSION_MAX_FT);
    $short = htmlspecialchars((string) ($unitMeta['short'] ?? 'ft'), ENT_QUOTES, 'UTF-8');
    $example = htmlspecialchars(
        ($unitMeta['example_w'] ?? '2') . ' × ' . ($unitMeta['example_h'] ?? '3') . ' ' . ($unitMeta['short'] ?? 'ft'),
        ENT_QUOTES,
        'UTF-8'
    );

    $idAttr = $containerId !== '' ? ' id="' . htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $display = $visible ? 'block' : 'none';

    $html = '<div class="pf-custom-size-panel ' . htmlspecialchars($containerClass, ENT_QUOTES, 'UTF-8') . '"' . $idAttr;
    $html .= ' style="display:' . $display . ';"';
    $html .= ' data-dimension-key="' . htmlspecialchars($fieldKey, ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' data-dimension-max="' . htmlspecialchars((string) $max, ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' data-dimension-unit="' . htmlspecialchars($selectedUnit, ENT_QUOTES, 'UTF-8') . '">';

    $html .= '<div class="pf-custom-size-heading">' . htmlspecialchars(printflow_service_dimension_custom_size_label(), ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<p class="pf-custom-size-lead">What size do you need?</p>';

    $html .= '<div class="pf-custom-size-grid">';
    $html .= '<div class="pf-custom-size-field pf-custom-size-field--unit">';
    $html .= '<label class="pf-custom-size-label">Unit</label>';
    if ($fixedUnit) {
        $html .= '<div class="pf-custom-size-unit-fixed">' . htmlspecialchars((string) ($unitMeta['label'] ?? 'Feet'), ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<input type="hidden" name="' . htmlspecialchars($unitFieldName, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($selectedUnit, ENT_QUOTES, 'UTF-8') . '" class="pf-custom-size-unit-input">';
    } else {
        $html .= '<select name="' . htmlspecialchars($unitFieldName, ENT_QUOTES, 'UTF-8') . '" class="input-field pf-custom-size-unit-select" aria-label="Measurement unit">';
        $html .= '<option value="ft"' . ($selectedUnit === 'ft' ? ' selected' : '') . '>Feet</option>';
        $html .= '<option value="in"' . ($selectedUnit === 'in' ? ' selected' : '') . '>Inches</option>';
        $html .= '</select>';
    }
    $html .= '</div>';

    $wId = $widthInputId !== '' ? ' id="' . htmlspecialchars($widthInputId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $hId = $heightInputId !== '' ? ' id="' . htmlspecialchars($heightInputId, ENT_QUOTES, 'UTF-8') . '"' : '';

    $html .= '<div class="pf-custom-size-field">';
    $html .= '<label class="pf-custom-size-label">Width (' . $short . ')</label>';
    $html .= '<input type="text" inputmode="decimal"' . $wId;
    $html .= ' class="input-field ' . htmlspecialchars($widthInputClass, ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' data-dimension-key="' . htmlspecialchars($fieldKey, ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' autocomplete="off" placeholder="e.g. ' . htmlspecialchars((string) ($unitMeta['example_w'] ?? ''), ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' value="' . htmlspecialchars($savedW, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '</div>';

    $html .= '<div class="pf-custom-size-field">';
    $html .= '<label class="pf-custom-size-label">Height (' . $short . ')</label>';
    $html .= '<input type="text" inputmode="decimal"' . $hId;
    $html .= ' class="input-field ' . htmlspecialchars($heightInputClass, ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' data-dimension-key="' . htmlspecialchars($fieldKey, ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' autocomplete="off" placeholder="e.g. ' . htmlspecialchars((string) ($unitMeta['example_h'] ?? ''), ENT_QUOTES, 'UTF-8') . '"';
    $html .= ' value="' . htmlspecialchars($savedH, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<p class="pf-custom-size-example">Example: ' . $example . '</p>';
    $html .= '<p class="pf-custom-size-help"><span class="pf-custom-size-info" aria-hidden="true">ⓘ</span> Enter the width and height of your desired print size.</p>';
    $html .= '<div class="pf-custom-size-error" role="alert" hidden></div>';
    $html .= '</div>';

    return $html;
}

function printflow_service_custom_size_styles(): string
{
    return <<<'CSS'
.pf-custom-size-panel {
    border-top: 1px dashed #e5e7eb;
    padding-top: 1rem;
    margin-top: 1rem;
    max-width: 100%;
}
.pf-custom-size-heading {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #475569;
    margin: 0 0 0.35rem;
}
.pf-custom-size-lead {
    margin: 0 0 0.75rem;
    font-size: 0.875rem;
    color: #334155;
    font-weight: 600;
}
.pf-custom-size-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 0.75rem;
    max-width: 420px;
}
.pf-custom-size-field--unit { grid-column: 1 / -1; }
.pf-custom-size-label {
    display: block;
    margin-bottom: 0.35rem;
    font-size: 0.72rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
}
.pf-custom-size-unit-fixed {
    padding: 8px 11px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f8fafc;
    font-size: 0.875rem;
    color: #0f172a;
    font-weight: 600;
}
.pf-custom-size-example,
.pf-custom-size-help {
    margin: 0.65rem 0 0;
    font-size: 0.8125rem;
    color: #64748b;
    line-height: 1.45;
}
.pf-custom-size-info { color: #0d9488; margin-right: 0.25rem; }
.pf-custom-size-error {
    margin-top: 0.5rem;
    font-size: 0.8125rem;
    color: #b91c1c;
    font-weight: 600;
}
@media (max-width: 640px) {
    .pf-custom-size-grid { grid-template-columns: 1fr; max-width: 100%; }
}
CSS;
}
