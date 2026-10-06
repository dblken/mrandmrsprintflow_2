<?php
/**
 * Layout option ↔ design upload requirement (with_layout / without_layout).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/service_field_config_helper.php';

function layout_test_assert(bool $cond, string $msg): void
{
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

layout_test_assert(
    printflow_layout_option_canonical('Without Layout') === 'without_layout',
    'canonical without layout label'
);
layout_test_assert(
    printflow_layout_option_canonical('with_layout') === 'with_layout',
    'canonical with_layout slug'
);
layout_test_assert(
    printflow_service_option_values_match('without_layout', 'Without Layout'),
    'slug matches label for conditionals'
);

$slugValues = printflow_service_field_normalize_layout_values(['layout' => 'Without Layout'], $allConfigs);
layout_test_assert(
    !printflow_service_field_is_active($uploadField, $slugValues, 'upload_design', $allConfigs),
    'upload inactive when layout stored as label then normalized'
);

$customization = ['Layout' => 'Without Layout', 'quantity' => '2'];
printflow_apply_layout_canonical_to_customization($customization, $allConfigs);
layout_test_assert(
    ($customization['Layout'] ?? '') === 'without_layout',
    'customization stores without_layout'
);

$withSlug = ['layout' => 'with_layout'];
layout_test_assert(
    printflow_service_field_is_active($uploadField, $withSlug, 'upload_design', $allConfigs),
    'upload required for with_layout slug'
);

$designFileField = $uploadField;
$designFileConfigs = [
    'layout' => $layoutField,
    'design_file' => $designFileField,
];
layout_test_assert(
    printflow_service_field_conditional_target_matches_field('upload_design', 'design_file', $designFileConfigs),
    'disable_field target upload_design matches design_file field key'
);
$withoutLayoutValues = ['layout' => 'without_layout'];
layout_test_assert(
    !printflow_service_field_is_active($designFileField, $withoutLayoutValues, 'design_file', $designFileConfigs),
    'design_file inactive when layout option targets upload_design alias'
);

echo "All service_layout_design_validation tests passed.\n";
