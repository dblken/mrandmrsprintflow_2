<?php
/**
 * Smoke tests for admin-configurable conditional service fields.
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
        ['value' => 'Without Layout', 'price' => 200],
    ],
];

$uploadFieldHideWhen = [
    'visible' => true,
    'required' => true,
    'type' => 'file',
    'label' => 'Upload Design',
    'parent_field_key' => 'layout',
    'parent_value' => 'Without Layout',
    'conditional_mode' => 'hide_when',
];

$uploadFieldShowWhen = [
    'visible' => true,
    'required' => true,
    'type' => 'file',
    'label' => 'Upload Design',
    'parent_field_key' => 'layout',
    'parent_value' => 'With Layout',
    'conditional_mode' => 'show_when',
];

$withLayout = ['layout' => 'With Layout'];
$withoutLayout = ['layout' => 'Without Layout'];

expect_true(!printflow_service_field_is_active($uploadFieldHideWhen, $withoutLayout), 'hide_when inactive when trigger selected');
expect_true(printflow_service_field_is_active($uploadFieldHideWhen, $withLayout), 'hide_when active when trigger not selected');

expect_true(printflow_service_field_is_active($uploadFieldShowWhen, $withLayout), 'show_when active when trigger selected');
expect_true(!printflow_service_field_is_active($uploadFieldShowWhen, $withoutLayout), 'show_when inactive when trigger not selected');

$configs = [
    'layout' => $layoutField,
    'upload_design' => $uploadFieldHideWhen,
];
$normalized = $uploadFieldHideWhen;
printflow_normalize_service_field_conditional_config(1, 'upload_design', $normalized, $configs);
expect_true($normalized['parent_field_key'] === 'layout', 'normalize keeps valid parent key');
expect_true($normalized['parent_value'] === 'Without Layout', 'normalize keeps valid option value');

$bad = $uploadFieldHideWhen;
$bad['parent_value'] = 'Not An Option';
printflow_normalize_service_field_conditional_config(1, 'upload_design', $bad, $configs);
expect_true($bad['parent_field_key'] === null, 'normalize clears invalid option');

$posValues = printflow_service_field_values_from_customization(
    ['Layout' => 'Without Layout'],
    ['layout' => $layoutField]
);
expect_true(($posValues['layout'] ?? '') === 'Without Layout', 'customization maps label to field key');

expect_true(!printflow_service_field_is_active($uploadFieldHideWhen, $posValues), 'POS payload skips upload when hidden');

echo "All service_field_conditional tests passed.\n";
