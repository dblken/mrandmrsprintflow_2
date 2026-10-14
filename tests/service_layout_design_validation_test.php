<?php
/**
 * Layout option ↔ design upload requirement (with_layout / without_layout).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/service_field_renderer.php';

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
layout_test_assert(
    !printflow_service_field_is_active($uploadField, [], 'upload_design', $allConfigs),
    'upload stays inactive until With Layout is selected'
);
$uploadRules = printflow_service_field_build_conditional_rules('upload_design', $uploadField, $allConfigs);
layout_test_assert(
    count(array_filter($uploadRules, static fn(array $rule): bool => ($rule['mode'] ?? '') === 'show_when'
        && printflow_layout_option_canonical((string)($rule['value'] ?? '')) === 'with_layout')) === 1,
    'renderer receives a show-only-with-layout rule for design upload'
);
layout_test_assert(
    count(array_filter($uploadRules, static fn(array $rule): bool => ($rule['mode'] ?? '') === 'disable_when'
        && ($rule['customer_note'] ?? '') === 'Please coordinate with our staff regarding the layout for your order.')) === 1,
    'without-layout design rule includes the default staff coordination message'
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
$designFileRules = printflow_service_field_build_conditional_rules('design_file', $designFileField, $designFileConfigs);
layout_test_assert(
    count(array_filter($designFileRules, static fn(array $rule): bool => ($rule['mode'] ?? '') === 'disable_when'
        && ($rule['customer_note'] ?? '') === 'Please coordinate with our staff regarding the layout for your order.')) === 1,
    'aliased design upload receives the without-layout coordination message'
);

$layoutWithPrice = [
    'visible' => true,
    'type' => 'radio',
    'label' => 'Layout',
    'options' => [
        ['value' => 'With Layout', 'price' => 0],
        ['value' => 'Without Layout', 'price' => 200],
    ],
];
$optionTotal = 0.0;
foreach ($layoutWithPrice['options'] as $opt) {
    if (printflow_service_option_values_match('without_layout', (string)($opt['value'] ?? ''))) {
        $optionTotal += (float)($opt['price'] ?? 0);
    }
}
layout_test_assert($optionTotal === 200.0, 'without_layout slug earns Without Layout option price');

$renderedUpload = render_service_field('upload_design', $uploadField, [], [], $allConfigs);
layout_test_assert(
    str_contains($renderedUpload, '>Choose an option</option>'),
    'design mode uses the concise dropdown placeholder'
);
layout_test_assert(
    !str_contains($renderedUpload, 'Select Upload Design Option')
        && !str_contains($renderedUpload, 'How would you like to provide your design?'),
    'design mode omits duplicate label and question copy'
);

$withDesign = [
    'layout' => 'with_layout',
    'design_upload_files' => [['path' => '/uploads/orders/a.jpg', 'name' => 'a.jpg']],
    'design_upload_path' => '/uploads/orders/a.jpg',
    'design_external_link' => 'https://example.com/design.png',
    'Upload Design Link' => 'https://example.com/legacy.png',
];
$cleared = printflow_customization_clear_ephemeral_design_media($withDesign);
layout_test_assert(!isset($cleared['design_upload_files']), 'clear removes design_upload_files');
layout_test_assert(!isset($cleared['design_upload_path']), 'clear removes design_upload_path');
layout_test_assert(!isset($cleared['design_external_link']), 'clear removes design link keys');
layout_test_assert(!isset($cleared['Upload Design Link']), 'clear removes labeled design link keys');

layout_test_assert(
    printflow_customization_layout_is_without(['Layout' => 'Without Layout']),
    'detects without layout from label key'
);
layout_test_assert(
    !printflow_service_design_upload_is_active($allConfigs, ['layout' => 'without_layout']),
    'design upload inactive helper matches conditional rules'
);

$rendererJs = (string) file_get_contents(__DIR__ . '/../includes/service_field_renderer.php');
layout_test_assert(strpos($rendererJs, 'pfClearDesignUploadGroup') !== false, 'renderer exposes design clear on layout switch');
layout_test_assert(
    strpos($rendererJs, '.pf-hidden-conditional-note[hidden]') !== false,
    'hidden layout coordination notice cannot be forced visible by flex styling'
);
layout_test_assert(strpos($rendererJs, 'pfClearDesignUploadGroup(uploadGroup)') !== false, 'conditional fields clear uploads when layout disables design');
layout_test_assert(
    strpos($rendererJs, 'You can upload a maximum of ') !== false
        && strpos($rendererJs, 'group.pfDesignQueueError') !== false,
    'renderer displays an inline error when the design file limit is exceeded'
);
layout_test_assert(
    strpos($rendererJs, 'const visibleLimit = 2;') !== false
        && strpos($rendererJs, "'Show ' + hiddenCount + ' more'") !== false
        && strpos($rendererJs, "'Show less'") !== false,
    'renderer initially shows two design files and provides expand and collapse controls'
);
echo "All service_layout_design_validation tests passed.\n";
