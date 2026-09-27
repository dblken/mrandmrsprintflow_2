<?php
/**
 * Smoke tests for option-based conditional service fields.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/service_field_config_helper.php';

function expect_true(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

$layoutField = [
    'visible' => true,
    'type' => 'radio',
    'label' => 'Layout',
    'options' => [
        ['value' => 'With Layout', 'price' => 0],
        ['value' => 'Without Layout', 'price' => 200, 'hide_field_key' => 'upload_design'],
    ],
];

$uploadField = [
    'visible' => true,
    'required' => true,
    'type' => 'file',
    'label' => 'Upload Design',
];

$allConfigs = [
    'layout' => $layoutField,
    'upload_design' => $uploadField,
];

$withLayout = ['layout' => 'With Layout'];
$withoutLayout = ['layout' => 'Without Layout'];

expect_true(
    printflow_service_field_is_active($uploadField, $withLayout, 'upload_design', $allConfigs),
    'upload active when layout option has no hide rule'
);
expect_true(
    !printflow_service_field_is_active($uploadField, $withoutLayout, 'upload_design', $allConfigs),
    'upload inactive when selected option hides it'
);

$rules = printflow_service_field_build_conditional_rules('upload_design', $uploadField, $allConfigs);
expect_true(count($rules) === 1 && $rules[0]['mode'] === 'hide_when', 'rules built from option hide_field_key');

$normalizedLayout = $layoutField;
printflow_normalize_service_field_source_option_conditionals('layout', $normalizedLayout, $allConfigs);
expect_true(
    ($normalizedLayout['options'][1]['hide_field_key'] ?? '') === 'upload_design',
    'normalize keeps valid hide_field_key on option'
);

$badLayout = $layoutField;
$badLayout['options'][1]['hide_field_key'] = 'missing_field';
printflow_normalize_service_field_source_option_conditionals('layout', $badLayout, $allConfigs);
expect_true(!isset($badLayout['options'][1]['hide_field_key']), 'normalize clears invalid hide_field_key');

$posValues = printflow_service_field_values_from_customization(
    ['Layout' => 'Without Layout'],
    ['layout' => $layoutField]
);
expect_true(($posValues['layout'] ?? '') === 'Without Layout', 'customization maps label to field key');
expect_true(
    !printflow_service_field_is_active($uploadField, $posValues, 'upload_design', $allConfigs),
    'POS payload skips upload when option hides it'
);

$legacyUpload = $uploadField + [
    'parent_field_key' => 'layout',
    'parent_value' => 'Without Layout',
    'conditional_mode' => 'hide_when',
];
expect_true(
    !printflow_service_field_is_active($legacyUpload, $withoutLayout, 'upload_design', []),
    'legacy target-side hide_when still works without allConfigs'
);

echo "All service_field_conditional tests passed.\n";
