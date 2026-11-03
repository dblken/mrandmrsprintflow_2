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

// Parser fixtures use option examples observed in the read-only service
// configuration; this test does not connect to or modify production data.
$dimensionFixtures = [
    71 => ['key' => 'sizes', 'label' => 'Dimensions', 'type' => 'select', 'option' => '1 Ft x 2 Ft', 'unit' => 'ft'],
    36 => ['key' => 'dimensions', 'label' => 'Dimensions And Finish', 'type' => 'select', 'option' => 'Matte Sticker Cut Out (1ft X 2ft)', 'unit' => 'ft'],
    67 => ['type' => 'select', 'option' => '25pcs A4 (8 x 11 Inch)', 'unit' => 'ft'],
];
foreach ($dimensionFixtures as $serviceId => $fixture) {
    $key = $fixture['key'] ?? 'sizes';
    $field = [[
        'field_key' => $key,
        'field_label' => $fixture['label'] ?? 'Dimensions',
        'field_type' => $fixture['type'],
        'unit' => $fixture['unit'],
        'allow_others' => 0,
        'is_visible' => 1,
        '_options' => [['value' => $fixture['option'], 'price' => 17.50]],
    ]];
    $selection = printflow_pos_resolve_service_dimension_selection($field, [$key => $fixture['option']]);
    $expected = printflow_pos_dimension_explicit_unit($fixture['option']);
    $assert($selection['field_key'] === $key && $selection['unit'] === $expected,
        "service {$serviceId} {$fixture['type']} fixture resolves its configured field and explicit option unit");
    $assert($field[0]['_options'][0]['price'] === 17.50,
        "service {$serviceId} option price is not changed by dimension parsing");
    $demandForFixture = printflow_pos_material_demand_for_area(
        $selection['width'], $selection['height'], $selection['unit'], 2, 1.25
    );
    $assert($demandForFixture['rounded_demand'] >= 1,
        "service {$serviceId} resolved dimensions and unit reach area demand");
}
$plateFields = [[
    'field_key' => 'type_of_plates', 'field_label' => 'Type Of Plates', 'field_type' => 'radio',
    'unit' => 'ft', 'allow_others' => 0, 'is_visible' => 1,
    '_options' => [
        ['value' => 'Motor Cycle Plate', 'price' => 250],
        ['value' => 'New Plate', 'price' => 250],
        ['value' => 'Car And Euro Plate', 'price' => 250],
    ],
]];
$assert(printflow_pos_service_dimension_descriptor($plateFields) === null,
    'live Plates Type Of Plates radio choices are not misclassified as physical dimensions');
$plateRejected = false;
try {
    printflow_pos_resolve_service_dimension_selection($plateFields, ['type_of_plates' => 'Motor Cycle Plate']);
} catch (RuntimeException $error) {
    $plateRejected = str_contains($error->getMessage(), 'physical dimension');
}
$assert($plateRejected, 'the dimension parser still refuses to invent physical dimensions from a Plate type label');
$assert(printflow_pos_material_selection_consumption_basis(39) === 'PER_ITEM'
    && !printflow_pos_material_selection_requires_dimensions(39), 'standard Plates bypass area dimensions and use the dedicated quantity-based material path');
$assert(printflow_pos_material_selection_requires_dimensions(67)
    && printflow_pos_material_selection_requires_dimensions(71)
    && printflow_pos_material_selection_requires_dimensions(36), 'area-based services retain their explicit physical dimension requirement');
$assert(printflow_pos_dimension_explicit_unit('8 x 11 Inch') === 'in', 'unit parser recognizes Inch in option labels');
$assert(printflow_pos_dimension_explicit_unit('30 x 40 cm') === 'cm', 'unit parser recognizes centimeters supported by area demand');
$assert(printflow_pos_dimension_explicit_unit('12 x 18 mm') === 'mm', 'unit parser recognizes millimeters supported by area demand');
$sintraSelection = printflow_pos_resolve_service_dimension_selection([[
    'field_key' => 'sizes', 'field_label' => 'Dimensions', 'field_type' => 'select', 'unit' => 'ft',
    'is_visible' => 1, '_options' => [['value' => '1 Ft x 2 Ft']],
]], ['sizes' => '1 Ft x 2 Ft']);
$assert($sintraSelection['width'] === '1' && $sintraSelection['height'] === '2' && $sintraSelection['unit'] === 'ft',
    'dimensions with unit labels on both axes resolve correctly');
$unsupportedUnit = false;
try {
    printflow_pos_resolve_service_dimension_selection([[
        'field_key' => 'size', 'field_label' => 'Size', 'field_type' => 'select', 'unit' => 'ft',
        'is_visible' => 1, '_options' => [['value' => '8 x 11 m']],
    ]], ['size' => '8 x 11 m']);
} catch (RuntimeException $error) {
    $unsupportedUnit = str_contains($error->getMessage(), 'unit');
}
$assert($unsupportedUnit, 'unsupported units are rejected rather than treated as feet');
$assert(printflow_pos_dimension_pair_from_option('8 x 11 x 14 Inch') === null,
    'three-axis labels are rejected as ambiguous physical dimensions');
$validatorSource = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$resolveAt = strpos($validatorSource, '$resolvedDimensions = printflow_pos_resolve_service_dimension_selection(');
$demandAt = strpos($validatorSource, 'printflow_pos_material_demand_for_area(', $resolveAt === false ? 0 : $resolveAt);
$widthAssignmentAt = strpos($validatorSource, '$customization[\'width\'] = $resolvedDimensions[\'width\'];', $resolveAt === false ? 0 : $resolveAt);
$assert($resolveAt !== false && $demandAt > $resolveAt && $widthAssignmentAt !== false && $widthAssignmentAt < $demandAt,
    'the authoritative selected dimensions are applied before backend area demand is calculated');
$plateBasisAt = strpos($validatorSource, "if (\$consumptionBasis === 'AREA_SQFT')");
$plateDemandAt = strpos($validatorSource, 'printflow_pos_material_demand_for_items($quantity, $rule[\'multiplier\'])');
$assert($plateBasisAt !== false && $plateDemandAt !== false
    && str_contains($validatorSource, "'multiplier_basis' => 'inventory_units_per_item'")
    && str_contains($validatorSource, "'multiplier_approved_basis' =>"),
    'the stock validator skips dimension resolution for Plates and records the approved per-item quantity basis');

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
