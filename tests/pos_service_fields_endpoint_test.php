<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/staff/api/pos_service_fields.php';
$source = (string) file_get_contents($path);

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

$output = [];
$lintExit = 0;
exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $lintExit);
$assert($lintExit === 0, 'pos_service_fields.php has valid PHP syntax');

$assert(
    !preg_match('/echo\s+[\'"][^\'"]*date\s*\(\s*[\'"]Y-m-d[\'"]/s', $source)
        && str_contains($source, '$needed_date_min = !empty($GLOBALS[\'printflow_allow_past_needed_date\']) ? \'\' : date(\'Y-m-d\');')
        && str_contains($source, '$needed_date_min_attr = $needed_date_min !== \'\' ? \' min="\''),
    'needed_date min defaults to today but honors the existing POS allow-past-date workflow'
);
$assert(
    str_contains($source, 'catch (Throwable $renderError)')
        && str_contains($source, "'success' => false"),
    'render failures return JSON instead of a bare fatal error'
);
$assert(
    str_contains($source, 'render_service_fields($service_id')
        && str_contains($source, 'service_has_field_config($service_id)'),
    'configured services use the shared field renderer'
);
$assert(
    str_contains($source, 'printflow_pos_service_dimension_configs($service_id)')
        && str_contains($source, "'material_dimension_field_key' =>")
        && str_contains($source, "'material_dimension_unit_per_option' =>")
        && str_contains($source, "\$dimensionDescriptor['field_type'] === 'dimension'"),
    'area-based POS services return field identity and allow validated select/radio options to carry their own units'
);
$assert(
    str_contains($source, '$needsMaterialDimensions = is_array($selectionPolicy)')
        && str_contains($source, 'printflow_pos_material_selection_requires_dimensions($service_id)')
        && str_contains($source, "'material_consumption_basis' => printflow_pos_material_selection_consumption_basis(\$service_id)"),
    'the service-fields endpoint does not demand dimensions for quantity-based Plates and reports its explicit consumption basis'
);
$assert(
    str_contains($source, 'if ($service_id !== 67)')
        && str_contains($source, "if (\$service_id === 71 && \$groupKey === 'sintra_thickness') continue;")
        && str_contains($source, '$fields_html .= $materialSelectionHtml;'),
    'Brochure omits internal paper choices, Sintraboard omits the duplicate thickness picker but retains optional materials'
);
$assert(
    str_contains($source, "'material_selection_field_map' => printflow_pos_material_selection_field_map(\$service_id)")
        && str_contains($source, 'printflow_pos_material_selection_field_map'),
    'the endpoint returns the server-owned exact Sintra thickness-to-item ID map for POS payload construction'
);
$assert(
    str_contains($source, 'printflow_pos_material_selection_group_label($service_id, $groupKey)')
        && str_contains($source, 'printflow_pos_material_selection_display_name($service_id, $id, $expectedNames[$id])')
        && str_contains($source, '$mode = $groupKey === \'decal_finishes\' ? \'checkbox\' : \'radio\';')
        && str_contains($source, '$checked = \'\';')
        && str_contains($source, 'data-group-required="\' . ($requiredGroup ? \'1\' : \'0\')'),
    'Sintraboard renders an unselected required Board Thickness radio group with explicit Sintra 3mm and Sintra 5mm labels'
);
$assert(
    str_contains($source, 'printflow_pos_sintraboard_rule_rows_for_display($ruleRows ?: [])')
        && str_contains($source, 'strict resolver to the complete rule set after a choice is made')
        && str_contains($source, 'if ($service_id === 71) continue; // display projection de-duplicates only.'),
    'Sintraboard conflicts cannot abort initial form rendering; selection-time stock validation remains strict.'
);
$assert(
    !str_contains($source, 'Demand is calculated')
        && !str_contains($source, 'inventory-units-per-square-foot multiplier')
        && !str_contains($source, "htmlspecialchars(\$choice['uom'], ENT_QUOTES, 'UTF-8') . '</span><small")
        && str_contains($source, "htmlspecialchars(\$choice['display_name'], ENT_QUOTES, 'UTF-8') . '</span></label>'"),
    'material picker rows expose selectable names without consumption explanations, UOM availability, or multiplier setup text'
);

echo "pos_service_fields endpoint test: {$passed} assertions passed.\n";
