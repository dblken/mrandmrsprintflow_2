<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_service_dimensions.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $assertions++;
    echo 'PASS: ', $message, PHP_EOL;
};

$brochureConfig = [[
    'field_key' => 'sizes',
    'field_label' => 'Dimensions',
    'field_type' => 'select',
    'unit' => 'ft',
    'allow_others' => 1,
    'is_visible' => 1,
    '_options' => [
        ['value' => '25pcs A4 (8 x 11 Inch)', 'price' => 250],
        ['value' => 'Custom Size', 'price' => 0],
    ],
]];

$descriptor = printflow_pos_service_dimension_descriptor($brochureConfig);
$assert($descriptor !== null, 'visible configured Dimensions select is an area dimension source');
$resolved = printflow_pos_resolve_service_dimension_selection($brochureConfig, [
    'sizes' => '25pcs A4 (8 x 11 Inch)',
]);
$assert($resolved['field_key'] === 'sizes', 'selected preset is bound to its configured field key');
$assert($resolved['width'] === '8' && $resolved['height'] === '11', 'physical width and height are parsed from the selected option label');
$assert($resolved['unit'] === 'in', 'explicit option unit overrides the field fallback unit');
$assert($brochureConfig[0]['_options'][0]['price'] === 250, 'option price remains unchanged in the configuration');
$demand = printflow_pos_material_demand_for_area($resolved['width'], $resolved['height'], $resolved['unit'], 2, 3.25);
$assert(abs($demand['area_sqft'] - (88 / 144)) < 0.000001, 'the resolved inch dimensions produce the correct square-foot area');
$assert($demand['rounded_demand'] === 4, 'the selected preset dimensions and order quantity reach area demand with fractional demand rounded up');
$validatorSource = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$resolveAt = strpos($validatorSource, '$resolvedDimensions = printflow_pos_resolve_service_dimension_selection(');
$demandAt = strpos($validatorSource, 'printflow_pos_material_demand_for_area(', $resolveAt === false ? 0 : $resolveAt);
$widthAssignmentAt = strpos($validatorSource, '$customization[\'width\'] = $resolvedDimensions[\'width\'];', $resolveAt === false ? 0 : $resolveAt);
$assert($resolveAt !== false && $demandAt > $resolveAt && $widthAssignmentAt !== false && $widthAssignmentAt < $demandAt,
    'the authoritative selected dimensions are applied before backend area demand is calculated');

$unconfiguredSelection = false;
try {
    printflow_pos_resolve_service_dimension_selection($brochureConfig, ['sizes' => 'not a configured option']);
} catch (RuntimeException $error) {
    $unconfiguredSelection = str_contains($error->getMessage(), 'not present');
}
$assert($unconfiguredSelection, 'unconfigured or tampered size values fail closed');

$nonPhysicalOptions = [[
    'field_key' => 'sizes', 'field_label' => 'Dimensions', 'field_type' => 'select', 'unit' => 'ft',
    'is_visible' => 1, '_options' => [['value' => 'Small', 'price' => 250]],
]];
$assert(printflow_pos_service_dimension_descriptor($nonPhysicalOptions) === null, 'nominal size labels alone do not enable area consumption');

$finishOptions = [[
    'field_key' => 'finish', 'field_label' => 'Dimensions Finish', 'field_type' => 'select', 'unit' => 'ft',
    'is_visible' => 1, '_options' => [['value' => 'Gloss 8 x 11 Inch', 'price' => 250]],
]];
$assert(printflow_pos_service_dimension_descriptor($finishOptions) === null, 'finish select is not misidentified as a dimension field');

echo "POS service dimension tests passed: {$assertions} assertions.\n";
