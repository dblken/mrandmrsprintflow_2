<?php
declare(strict_types=1);

/**
 * Dimension-enabled POS services must open a working dimension picker.
 * Root cause covered: native <select class="shopee-opt-btn"> used display:inline-flex,
 * which prevents Chromium from opening the native menu inside the service modal.
 */

require_once dirname(__DIR__) . '/includes/service_dimension_ui.php';
require_once dirname(__DIR__) . '/includes/service_field_renderer.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$scripts = get_service_field_scripts();
$assert(str_contains($scripts, 'function pfInitDimensionSelectPickers'),
    'dimension select picker initializer exists');
$assert(str_contains($scripts, 'pfInitDimensionSelectPickers(scope)'),
    'service field renderer initializes dimension pickers on each form bind');
$assert(str_contains($scripts, "dataset.pfDimPickerBound === '1'"),
    'picker binding is idempotent when the service modal is reopened');
$assert(str_contains($scripts, 'Retry loading dimensions'),
    'empty option lists surface a retryable error instead of a fake clickable control');
$assert(str_contains($scripts, "event.key === 'ArrowDown'")
    && str_contains($scripts, "event.key === 'Enter'"),
    'dimension picker supports keyboard open/navigation');

$pos = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
$assert(str_contains($pos, 'select.shopee-opt-btn')
    && str_contains($pos, 'display: inline-block !important'),
    'POS CSS stops applying flex layout to native selects');
$assert(str_contains($pos, 'pf-dim-select-shell')
    && str_contains($pos, 'pf-dim-select-menu'),
    'POS modal styles the custom dimension listbox');

$dimensionServices = [];
foreach (printflow_pos_material_selection_registry() as $serviceId => $_policy) {
    if (printflow_pos_material_selection_requires_dimensions((int)$serviceId)) {
        $dimensionServices[] = (int)$serviceId;
    }
}
$assert($dimensionServices !== [], 'registry exposes dimension-enabled services');
$assert(in_array(67, $dimensionServices, true), 'Brochure remains dimension-enabled');
$assert(in_array(64, $dimensionServices, true), 'Poster Printing remains dimension-enabled');
$assert(in_array(71, $dimensionServices, true), 'Sintraboard remains dimension-enabled');
$assert(in_array(36, $dimensionServices, true), 'Stickers/Decals remain dimension-enabled');
$assert(!in_array(39, $dimensionServices, true), 'Plates stay quantity-based without a dimensions picker');

foreach ([
    67 => ['key' => 'sizes', 'label' => 'Dimensions', 'option' => '25pcs A4 (8 x 11 Inch)'],
    64 => ['key' => 'sizes', 'label' => 'Dimensions', 'option' => '25pcs A4 (8 x 11 Inch)'],
    71 => ['key' => 'sizes', 'label' => 'Dimensions', 'option' => '1 Ft x 2 Ft'],
    36 => ['key' => 'dimensions', 'label' => 'Dimensions And Finish', 'option' => 'Matte Sticker Cut Out (1ft X 2ft)'],
] as $serviceId => $fixture) {
    $config = [
        'type' => 'select',
        'label' => $fixture['label'],
        'required' => true,
        'visible' => true,
        'allow_others' => 1,
        'unit' => 'ft',
        'options' => [
            ['value' => $fixture['option'], 'price' => 100],
            ['value' => 'Custom Size', 'price' => 0],
        ],
    ];
    $html = render_service_field($fixture['key'], $config, [], []);
    $assert(str_contains($html, 'name="' . $fixture['key'] . '"'),
        "service {$serviceId} renders its dimension select field");
    $assert(str_contains($html, htmlspecialchars($fixture['option'], ENT_QUOTES, 'UTF-8'))
        || str_contains($html, $fixture['option']),
        "service {$serviceId} exposes configured dimension options to the picker");
    $assert(str_contains($html, 'pf-select-with-others') || str_contains($html, 'pricing-field'),
        "service {$serviceId} dimension select keeps pricing/custom-size hooks");
}

$assert(str_contains($pos, 'customization.dimensions')
    || str_contains($pos, "customization['Dimensions']")
    || str_contains($pos, 'posCollectServiceStockCustomization')
    || str_contains($pos, 'confirmServiceModal'),
    'POS still collects selected dimensions into the cart customization path');
$assert(str_contains($pos, 'printflow_pos_validate_service_stock')
    || str_contains((string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php'), 'printflow_pos_validate_service_stock'),
    'material/stock validation remains wired after dimension selection');
$assert(str_contains((string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php'), 'printflow_pos_tarpaulin_selection'),
    'Tarpaulin roll-fit path remains intact');

echo "POS dimension select picker regression tests passed.\n";
