<?php
/**
 * Service Field Configuration Helper
 * Extracts and manages dynamic field configurations for services
 */

/** Maximum length for select/radio option labels configured in Admin. */
function printflow_service_field_option_max_length(): int {
    return 64;
}

/** Maximum length for optional per-field customer help tooltip text. */
function printflow_service_field_help_text_max_length(): int {
    return 500;
}

/**
 * Ensure help_text column exists (idempotent; safe on repeated calls).
 */
function printflow_ensure_service_field_configs_help_text_column(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $rows = db_query("SHOW COLUMNS FROM service_field_configs LIKE 'help_text'");
    if (!empty($rows)) {
        return;
    }
    db_execute(
        "ALTER TABLE service_field_configs ADD COLUMN help_text TEXT NULL COMMENT 'Optional customer-facing info tooltip' AFTER field_label"
    );
}

/** Conditional field modes (used with parent_field_key + parent_value). */
function printflow_service_field_conditional_modes(): array {
    return ['show_when', 'hide_when'];
}

function printflow_ensure_service_field_conditional_mode_column(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $rows = db_query("SHOW COLUMNS FROM service_field_configs LIKE 'conditional_mode'");
    if (!empty($rows)) {
        return;
    }
    db_execute(
        "ALTER TABLE service_field_configs ADD COLUMN conditional_mode VARCHAR(20) NULL DEFAULT NULL COMMENT 'show_when|hide_when with parent_field_key' AFTER parent_value"
    );
}

function printflow_service_field_conditional_mode(array $config): string {
    $mode = strtolower(trim((string)($config['conditional_mode'] ?? '')));
    if ($mode === 'hide_when') {
        return 'hide_when';
    }
    return 'show_when';
}

/**
 * Field types allowed to drive conditional visibility.
 *
 * @return list<string>
 */
function printflow_service_field_conditional_source_types(): array {
    return ['radio', 'select'];
}

/**
 * Normalize conditional config on save; clears invalid combinations.
 */
function printflow_normalize_service_field_conditional_config(
    int $serviceId,
    string $fieldKey,
    array &$config,
    array $allConfigs
): void {
    unset($serviceId);
    $parentKey = trim((string)($config['parent_field_key'] ?? ''));
    $parentValue = trim((string)($config['parent_value'] ?? ''));
    if ($parentKey === '' || $parentValue === '') {
        $config['parent_field_key'] = null;
        $config['parent_value'] = null;
        $config['conditional_mode'] = null;
        return;
    }
    if ($parentKey === $fieldKey) {
        $config['parent_field_key'] = null;
        $config['parent_value'] = null;
        $config['conditional_mode'] = null;
        return;
    }
    $source = $allConfigs[$parentKey] ?? null;
    if (!is_array($source)) {
        $config['parent_field_key'] = null;
        $config['parent_value'] = null;
        $config['conditional_mode'] = null;
        return;
    }
    $sourceType = strtolower(trim((string)($source['type'] ?? '')));
    if (!in_array($sourceType, printflow_service_field_conditional_source_types(), true)) {
        $config['parent_field_key'] = null;
        $config['parent_value'] = null;
        $config['conditional_mode'] = null;
        return;
    }
    $optionValues = printflow_service_field_option_values_list($source);
    $matched = false;
    foreach ($optionValues as $optVal) {
        if (strcasecmp($optVal, $parentValue) === 0) {
            $config['parent_value'] = $optVal;
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $config['parent_field_key'] = null;
        $config['parent_value'] = null;
        $config['conditional_mode'] = null;
        return;
    }
    $mode = strtolower(trim((string)($config['conditional_mode'] ?? '')));
    $config['conditional_mode'] = ($mode === 'hide_when') ? 'hide_when' : 'show_when';
}

/** Target field key hidden when a source option is selected (stored on the option in field_options JSON). */
function printflow_service_field_option_hide_field_key(array $option): string
{
    return trim((string)($option['hide_field_key'] ?? ''));
}

/**
 * Conditional visibility rules affecting a target field (option-based + legacy target-side config).
 *
 * @return list<array{source:string,value:string,mode:string}>
 */
function printflow_service_field_build_conditional_rules(string $targetFieldKey, array $targetConfig, array $allConfigs): array
{
    $rules = [];
    foreach ($allConfigs as $sourceKey => $sourceCfg) {
        if (!is_string($sourceKey) || $sourceKey === '' || !is_array($sourceCfg)) {
            continue;
        }
        $sourceType = strtolower(trim((string)($sourceCfg['type'] ?? '')));
        if (!in_array($sourceType, printflow_service_field_conditional_source_types(), true)) {
            continue;
        }
        foreach (($sourceCfg['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $hideKey = printflow_service_field_option_hide_field_key($option);
            if ($hideKey === '' || $hideKey !== $targetFieldKey || $hideKey === $sourceKey) {
                continue;
            }
            $optVal = trim((string)($option['value'] ?? ''));
            if ($optVal === '') {
                continue;
            }
            $rules[] = [
                'source' => $sourceKey,
                'value' => $optVal,
                'mode' => 'hide_when',
            ];
        }
    }

    $parentKey = trim((string)($targetConfig['parent_field_key'] ?? ''));
    $parentValue = trim((string)($targetConfig['parent_value'] ?? ''));
    if ($parentKey !== '' && $parentValue !== '') {
        $rules[] = [
            'source' => $parentKey,
            'value' => $parentValue,
            'mode' => printflow_service_field_conditional_mode($targetConfig),
        ];
    }

    return $rules;
}

function printflow_service_field_hidden_by_option_rules(string $targetFieldKey, array $allConfigs, array $values): bool
{
    foreach ($allConfigs as $sourceKey => $sourceCfg) {
        if (!is_string($sourceKey) || $sourceKey === '' || !is_array($sourceCfg)) {
            continue;
        }
        $sourceType = strtolower(trim((string)($sourceCfg['type'] ?? '')));
        if (!in_array($sourceType, printflow_service_field_conditional_source_types(), true)) {
            continue;
        }
        $selected = printflow_service_field_resolve_parent_value($sourceKey, $values);
        if ($selected === '') {
            continue;
        }
        foreach (($sourceCfg['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $hideKey = printflow_service_field_option_hide_field_key($option);
            if ($hideKey === '' || $hideKey !== $targetFieldKey) {
                continue;
            }
            $optVal = trim((string)($option['value'] ?? ''));
            if ($optVal === '') {
                continue;
            }
            if (strcasecmp($selected, $optVal) === 0) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Validate hide_field_key on each option for radio/select source fields.
 */
function printflow_normalize_service_field_source_option_conditionals(string $sourceFieldKey, array &$config, array $allConfigs): void
{
    $type = strtolower(trim((string)($config['type'] ?? '')));
    if (!in_array($type, printflow_service_field_conditional_source_types(), true)) {
        return;
    }
    if (empty($config['options']) || !is_array($config['options'])) {
        return;
    }
    foreach ($config['options'] as &$option) {
        if (!is_array($option)) {
            continue;
        }
        $hideKey = printflow_service_field_option_hide_field_key($option);
        if ($hideKey === '' || $hideKey === $sourceFieldKey || !isset($allConfigs[$hideKey])) {
            unset($option['hide_field_key']);
            continue;
        }
        $targetCfg = $allConfigs[$hideKey];
        if (!is_array($targetCfg) || empty($targetCfg['visible'])) {
            unset($option['hide_field_key']);
            continue;
        }
        $option['hide_field_key'] = $hideKey;
    }
    unset($option);
}

/**
 * Drop legacy parent_field_key on targets when the same rule is stored on a source option.
 */
function printflow_clear_redundant_legacy_target_conditionals(array &$allConfigs): void
{
    $optionHideRules = [];
    foreach ($allConfigs as $sourceKey => $sourceCfg) {
        if (!is_array($sourceCfg)) {
            continue;
        }
        foreach (($sourceCfg['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $hideKey = printflow_service_field_option_hide_field_key($option);
            $optVal = trim((string)($option['value'] ?? ''));
            if ($hideKey === '' || $optVal === '') {
                continue;
            }
            $optionHideRules[$hideKey][] = [
                'source' => (string)$sourceKey,
                'value' => $optVal,
            ];
        }
    }

    foreach ($allConfigs as $targetKey => &$targetCfg) {
        if (!is_array($targetCfg)) {
            continue;
        }
        $parentKey = trim((string)($targetCfg['parent_field_key'] ?? ''));
        $parentValue = trim((string)($targetCfg['parent_value'] ?? ''));
        if ($parentKey === '' || $parentValue === '') {
            continue;
        }
        if (printflow_service_field_conditional_mode($targetCfg) !== 'hide_when') {
            continue;
        }
        foreach ($optionHideRules[$targetKey] ?? [] as $rule) {
            if ($rule['source'] === $parentKey && strcasecmp($rule['value'], $parentValue) === 0) {
                $targetCfg['parent_field_key'] = null;
                $targetCfg['parent_value'] = null;
                $targetCfg['conditional_mode'] = null;
                break;
            }
        }
    }
    unset($targetCfg);
}

/**
 * @return list<string>
 */
function printflow_service_field_option_values_list(array $fieldConfig): array {
    $out = [];
    foreach (($fieldConfig['options'] ?? []) as $option) {
        if (is_array($option)) {
            $val = trim((string)($option['value'] ?? ''));
        } else {
            $val = trim((string)$option);
        }
        if ($val !== '') {
            $out[] = $val;
        }
    }
    return $out;
}

/**
 * Read a parent/source field value from POST or customization payload.
 */
function printflow_service_field_resolve_parent_value(string $parentKey, array $values): string {
    if ($parentKey === 'branch') {
        $branch = $values['branch_id'] ?? $values['branch'] ?? '';
        return trim((string)$branch);
    }
    if (array_key_exists($parentKey, $values)) {
        return trim((string)$values[$parentKey]);
    }
    foreach ($values as $label => $val) {
        if (!is_string($label) || !is_scalar($val)) {
            continue;
        }
        if (strcasecmp(trim($label), $parentKey) === 0) {
            return trim((string)$val);
        }
    }
    return '';
}

/**
 * Whether a configured field is active (visible + subject to validation) for submitted values.
 *
 * @param array<string, array> $allConfigs Full service field map when evaluating option-based rules.
 */
function printflow_service_field_is_active(array $config, array $values, string $fieldKey = '', array $allConfigs = []): bool {
    if (empty($config['visible'])) {
        return false;
    }
    if ($fieldKey !== '' && $allConfigs !== []) {
        if (printflow_service_field_hidden_by_option_rules($fieldKey, $allConfigs, $values)) {
            return false;
        }
    }
    $parentKey = trim((string)($config['parent_field_key'] ?? ''));
    $triggerValue = trim((string)($config['parent_value'] ?? ''));
    if ($parentKey === '' || $triggerValue === '') {
        return true;
    }
    $parentVal = printflow_service_field_resolve_parent_value($parentKey, $values);
    if ($parentVal === '') {
        if (printflow_service_field_conditional_mode($config) === 'hide_when') {
            return true;
        }
        return false;
    }
    $matches = strcasecmp($parentVal, $triggerValue) === 0;
    if (printflow_service_field_conditional_mode($config) === 'hide_when') {
        return !$matches;
    }
    return $matches;
}

function printflow_service_field_values_from_post(array $post): array {
    $values = $post;
    if (isset($post['branch_id'])) {
        $values['branch'] = $post['branch_id'];
    }
    return $values;
}

/**
 * Map customization payload (often label-keyed) back to field keys for conditional rules.
 */
function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array {
    $values = $customization;
    foreach ($fieldConfigs as $fieldKey => $config) {
        if (!is_string($fieldKey) || $fieldKey === '') {
            continue;
        }
        if (isset($values[$fieldKey]) && trim((string)$values[$fieldKey]) !== '') {
            continue;
        }
        $label = trim((string)($config['label'] ?? ''));
        if ($label !== '' && array_key_exists($label, $customization)) {
            $values[$fieldKey] = $customization[$label];
        }
    }
    if (isset($customization['branch_id'])) {
        $values['branch'] = $customization['branch_id'];
    }
    return $values;
}

function printflow_normalize_service_field_help_text(?string $text): string {
    $text = trim(preg_replace("/\r\n|\r/", "\n", (string) $text));
    if ($text === '') {
        return '';
    }
    $max = printflow_service_field_help_text_max_length();
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    if (strlen($text) > $max) {
        return substr($text, 0, $max);
    }
    return $text;
}

/**
 * Enforce option label length when saving service field configs (presentation metadata only).
 *
 * @param mixed $options
 * @return mixed
 */
function printflow_normalize_service_field_options($options) {
    if (!is_array($options)) {
        return $options;
    }

    if (!function_exists('printflow_normalize_service_field_staff_flags')) {
        require_once __DIR__ . '/service_field_priority_helper.php';
    }

    $max = printflow_service_field_option_max_length();
    $trimToMax = static function (string $value) use ($max): string {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $max) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }

        return $value;
    };

    $out = [];
    foreach ($options as $option) {
        if (is_array($option)) {
            $row = $option;
            if (isset($row['value']) && is_scalar($row['value'])) {
                $row['value'] = $trimToMax((string)$row['value']);
            }
            if (!empty($row['nested_fields']) && is_array($row['nested_fields'])) {
                foreach ($row['nested_fields'] as $idx => $nested) {
                    if (is_array($nested) && array_key_exists('options', $nested)) {
                        $row['nested_fields'][$idx]['options'] = printflow_normalize_service_field_options($nested['options']);
                    }
                }
            }
            if (isset($row['staff_flags']) && is_array($row['staff_flags'])) {
                $row['staff_flags'] = printflow_normalize_service_field_staff_flags($row['staff_flags']);
            }
            $out[] = $row;
            continue;
        }
        if (is_string($option) || is_numeric($option)) {
            $out[] = $trimToMax((string)$option);
            continue;
        }
        $out[] = $option;
    }

    return $out;
}

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
    printflow_ensure_service_field_configs_help_text_column();
    printflow_ensure_service_field_conditional_mode_column();
    $configs = db_query(
        "SELECT * FROM service_field_configs WHERE service_id = ? ORDER BY display_order ASC",
        'i',
        [$service_id]
    );
    
    $result = [];
    foreach ($configs as $config) {
        $result[$config['field_key']] = [
            'label' => $config['field_label'],
            'help_text' => printflow_normalize_service_field_help_text($config['help_text'] ?? ''),
            'type' => $config['field_type'],
            'options' => $config['field_options'] ? json_decode($config['field_options'], true) : null,
            'visible' => (bool)$config['is_visible'],
            'required' => (bool)$config['is_required'],
            'default' => $config['default_value'],
            'unit' => $config['unit'] ?? 'ft',
            'allow_others' => isset($config['allow_others']) ? (bool)$config['allow_others'] : true,
            'order' => (int)$config['display_order'],
            'parent_field_key' => $config['parent_field_key'] ?? null,
            'parent_value' => $config['parent_value'] ?? null,
            'conditional_mode' => $config['conditional_mode'] ?? null,
        ];
    }
    
    return $result;
}

/**
 * Save field configuration for a service
 */
function save_service_field_config($service_id, $field_key, $config) {
    printflow_ensure_service_field_configs_help_text_column();
    printflow_ensure_service_field_conditional_mode_column();
    $existing = db_query(
        "SELECT config_id FROM service_field_configs WHERE service_id = ? AND field_key = ?",
        'is',
        [$service_id, $field_key]
    );
    
    if (isset($config['options'])) {
        $config['options'] = printflow_normalize_service_field_options($config['options']);
    }
    $options_json = isset($config['options']) ? json_encode($config['options']) : null;
    $unit = $config['unit'] ?? 'ft';
    $allow_others = array_key_exists('allow_others', $config)
        ? ($config['allow_others'] ? 1 : 0)
        : 1;
    $help_text = printflow_normalize_service_field_help_text($config['help_text'] ?? '');
    $conditional_mode = $config['conditional_mode'] ?? null;
    if ($conditional_mode !== null && $conditional_mode !== '') {
        $conditional_mode = printflow_service_field_conditional_mode(['conditional_mode' => $conditional_mode]);
    } else {
        $conditional_mode = null;
    }
    
    if (!empty($existing)) {
        db_execute(
            "UPDATE service_field_configs SET 
                field_label = ?, 
                help_text = ?,
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
                conditional_mode = ?,
                updated_at = NOW()
            WHERE service_id = ? AND field_key = ?",
            'ssssiissiisssis',
            [
                $config['label'],
                $help_text !== '' ? $help_text : null,
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
                $conditional_mode,
                $service_id,
                $field_key
            ]
        );
    } else {
        db_execute(
            "INSERT INTO service_field_configs 
                (service_id, field_key, field_label, help_text, field_type, field_options, is_visible, is_required, default_value, unit, allow_others, display_order, parent_field_key, parent_value, conditional_mode) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'isssssiissiisss',
            [
                $service_id,
                $field_key,
                $config['label'],
                $help_text !== '' ? $help_text : null,
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
                $conditional_mode
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
