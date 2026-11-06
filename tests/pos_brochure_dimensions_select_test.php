<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/service_dimension_ui.php';
require_once dirname(__DIR__) . '/includes/service_field_renderer.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$config = [
    'type' => 'select',
    'label' => 'Dimensions',
    'required' => true,
    'visible' => true,
    'allow_others' => 1,
    'unit' => 'ft',
    'options' => [
        ['value' => '25pcs A4 (8 x 11 Inch)', 'price' => 250],
        ['value' => 'Custom Size', 'price' => 0],
    ],
];

$assert(printflow_service_field_is_custom_size_option_value('Custom Size'), 'Custom Size is recognized as the custom-size option');
$assert(printflow_service_field_is_custom_size_option_value('Others'), 'Others remains a custom-size option');
$assert(printflow_service_field_custom_size_option_value($config) === 'Custom Size',
    'existing Custom Size option wins over injected Others');
$assert(printflow_service_field_uses_custom_size_panel('sizes', $config),
    'Brochure Dimensions select uses the guided custom-size panel');

$html = render_service_field('sizes', $config, [], []);
$assert(str_contains($html, 'Select Dimensions') || str_contains($html, 'Select sizes'),
    'placeholder invites staff to select dimensions');
$assert(str_contains($html, 'data-other-option="Custom Size"'),
    'select data-other-option points at Custom Size so the panel can open');
preg_match_all('/<option[^>]*value="([^"]*)"/', $html, $optionMatches);
$optionValues = $optionMatches[1] ?? [];
$customSizeOptions = array_values(array_filter($optionValues, static function (string $value): bool {
    return printflow_service_field_is_custom_size_option_value($value);
}));
$assert(count($customSizeOptions) === 1 && $customSizeOptions[0] === 'Custom Size',
    'Custom Size / Others is rendered once, not duplicated');
$assert(str_contains($html, '25pcs A4 (8 x 11 Inch)'),
    'configured preset dimension options remain available');
$assert(str_contains($html, 'pf-custom-size-panel') || str_contains($html, 'select-others-sizes'),
    'custom size width/height panel markup is present for Brochure dimensions');

$scripts = get_service_field_scripts();
$assert(str_contains($scripts, 'function pfSyncSelectOthersWrap'),
    'select sync helper is available for Dimensions changes');
$assert(str_contains($scripts, 'pfIsCustomSizeOptionValue'),
    'Custom Size option values open the guided panel');

$pos = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
$assert(str_contains($pos, 'overflow: visible !important')
    && str_contains($pos, '#service-modal-overlay select.shopee-opt-btn'),
    'POS modal CSS keeps native dimension selects interactive');

echo "POS Brochure dimensions select tests passed.\n";
