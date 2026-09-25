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
