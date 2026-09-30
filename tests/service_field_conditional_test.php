<?php
/**
 * Smoke tests for generic option conditional (disable_field) behavior.
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
        [
            'value' => 'Without Layout',
            'price' => 200,
            'option_conditional' => [
                'action' => 'disable_field',
                'target_field_key' => 'upload_design',
                'customer_note' => 'Please coordinate with staff for layout.',
            ],
        ],
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
    'upload active when source option has no conditional rule'
);
expect_true(
    !printflow_service_field_is_active($uploadField, $withoutLayout, 'upload_design', $allConfigs),
    'upload inactive when selected option disables it'
);

$rules = printflow_service_field_build_conditional_rules('upload_design', $uploadField, $allConfigs);
expect_true(count($rules) === 1 && $rules[0]['mode'] === 'disable_when', 'rules use disable_when mode');
expect_true(
    ($rules[0]['customer_note'] ?? '') === 'Please coordinate with staff for layout.',
    'customer note included in rule payload'
);

$legacyLayout = [
    'visible' => true,
    'type' => 'radio',
    'options' => [
        [
            'value' => 'Without Layout',
            'staff_creates_layout' => 1,
            'hide_field_key' => 'upload_design',
            'customer_note' => 'Legacy note.',
        ],
    ],
];
$legacyAll = ['layout' => $legacyLayout, 'upload_design' => $uploadField];
$normalizedLegacy = $legacyLayout;
printflow_normalize_service_field_source_option_conditionals('layout', $normalizedLegacy, $legacyAll);
$stored = $normalizedLegacy['options'][0]['option_conditional'] ?? null;
expect_true(
    is_array($stored)
    && ($stored['action'] ?? '') === 'disable_field'
    && ($stored['target_field_key'] ?? '') === 'upload_design'
    && ($stored['customer_note'] ?? '') === 'Legacy note.',
    'normalize migrates legacy hide_field_key to option_conditional'
);
expect_true(
    !isset($normalizedLegacy['options'][0]['staff_creates_layout'])
    && !isset($normalizedLegacy['options'][0]['hide_field_key']),
    'normalize strips legacy keys after migration'
);

$posValues = printflow_service_field_values_from_customization(
    ['Layout' => 'Without Layout'],
    ['layout' => $layoutField]
);
expect_true(
    !printflow_service_field_is_active($uploadField, $posValues, 'upload_design', $allConfigs),
    'POS payload skips upload when option disables it'
);

echo "All service_field_conditional tests passed.\n";
