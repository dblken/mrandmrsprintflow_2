<?php
require_once __DIR__ . '/../includes/product_pricing.php';

function pricing_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$noOptions = [];
$base = printflow_product_resolve_field_unit_price('100.00', $noOptions, []);
pricing_assert($base['ok'] && $base['unit_price'] === 100.0 && $base['mode'] === 'base', 'Base-only product resolves its base price.');
$missingBase = printflow_product_resolve_field_unit_price('', $noOptions, []);
pricing_assert(!$missingBase['ok'], 'Base-only product rejects an empty base price.');
foreach ([null, 'N/A', '0', '-1', 'abc', '1.001', 'INF'] as $invalidBase) {
    pricing_assert(!printflow_product_resolve_field_unit_price($invalidBase, [], [])['ok'], 'Invalid base price rejected: ' . var_export($invalidBase, true));
}

$optionConfig = [
    'size' => [
        'label' => 'Size',
        'type' => 'select',
        'visible' => true,
        'required' => false,
        'allow_others' => false,
        'options' => [
            ['value' => 'Small', 'price' => '150.00'],
            ['value' => 'Large', 'price' => '250.50'],
        ],
    ],
];
$blankBase = printflow_product_resolve_field_unit_price('', $optionConfig, ['Size' => 'Small']);
pricing_assert($blankBase['ok'] && $blankBase['unit_price'] === 150.0, 'Option-based product accepts blank base and uses selected option price alone.');
$naBase = printflow_product_resolve_field_unit_price('N/A', $optionConfig, ['Size' => 'Large']);
pricing_assert($naBase['ok'] && $naBase['unit_price'] === 250.5, 'N/A base is ignored and selected size price is used directly.');
$invalidSelection = printflow_product_resolve_field_unit_price('100.00', $optionConfig, []);
pricing_assert(!$invalidSelection['ok'], 'Option pricing requires a selected priced option.');

foreach ([null, '', '0', '-1.00', 'text', '2.001'] as $invalidOptionPrice) {
    $invalidConfig = $optionConfig;
    $invalidConfig['size']['options'][0]['price'] = $invalidOptionPrice;
    pricing_assert(!printflow_product_option_pricing_config($invalidConfig)['ok'], 'Invalid selectable option price rejected: ' . var_export($invalidOptionPrice, true));
}
$duplicateConfig = $optionConfig;
$duplicateConfig['size']['options'][1]['value'] = 'Small';
pricing_assert(!printflow_product_option_pricing_config($duplicateConfig)['ok'], 'Duplicate option values are rejected.');
$multipleConfig = $optionConfig + [
    'finish' => [
        'label' => 'Finish', 'type' => 'radio', 'visible' => true, 'allow_others' => false,
        'options' => [['value' => 'Gloss', 'price' => '10.00']],
    ],
];
pricing_assert(!printflow_product_option_pricing_config($multipleConfig)['ok'], 'Multiple price-driving fields are rejected as ambiguous.');
$othersConfig = $optionConfig;
$othersConfig['size']['allow_others'] = true;
pricing_assert(!printflow_product_option_pricing_config($othersConfig)['ok'], 'Unpriced custom Others choice cannot be enabled for option pricing.');

$variantResult = printflow_product_resolve_unit_price('100', [], [], [
    ['variant_id' => 7, 'price' => '175.00', 'status' => 'Active'],
    ['variant_id' => 8, 'price' => '225.00', 'status' => 'Active'],
], 7);
pricing_assert($variantResult['ok'] && $variantResult['unit_price'] === 175.0, 'Selected variant price is used directly.');
$badVariants = printflow_product_resolve_unit_price('100', [], [], [
    ['variant_id' => 7, 'price' => '0', 'status' => 'Active'],
], 7);
pricing_assert(!$badVariants['ok'], 'A selectable zero-priced variant invalidates the pricing setup.');
$ambiguous = printflow_product_resolve_unit_price('100', $optionConfig, ['Size' => 'Small'], [
    ['variant_id' => 7, 'price' => '175.00', 'status' => 'Active'],
], 7);
pricing_assert(!$ambiguous['ok'], 'Configured field prices and variants cannot be combined ambiguously.');

$adminBase = printflow_product_validate_admin_price('0.01', 'base');
$leadingZeroPrice = printflow_product_parse_positive_price('0001.25');
$adminOptionBlank = printflow_product_validate_admin_price('', 'option');
$adminOptionNa = printflow_product_validate_admin_price('N/A', 'option');
pricing_assert($adminBase['ok'] && $adminBase['stored'] === 0.01, 'Admin allows any positive cent price for base mode.');
pricing_assert($leadingZeroPrice === 1.25, 'Valid leading-zero decimal input is parsed without changing its amount.');
pricing_assert($adminOptionBlank['ok'] && $adminOptionBlank['stored'] === 0.0 && $adminOptionNa['ok'], 'Admin allows blank or N/A base price in option mode.');
pricing_assert(!printflow_product_validate_admin_price('0', 'option')['ok'], 'Admin rejects explicit zero in option mode.');
pricing_assert($blankBase['unit_price'] * 3 === 450.0, 'Quantity multiplies the resolved option unit price only.');

$contracts = [
    'customer/order_create.php' => ['printflow_product_resolve_unit_price', "'price'           => (float)\$pricingResult['unit_price']"],
    'customer/order_review.php' => ['printflow_product_resolve_catalog_unit_price', 'printflow_order_items_insert_line'],
    'customer/api_cart.php' => ['printflow_product_resolve_catalog_unit_price'],
    'customer/cart.php' => ['printflow_product_resolve_catalog_unit_price'],
    'customer/checkout.php' => ['printflow_product_resolve_catalog_unit_price', '(float)$unit_price'],
    'admin/products_management.php' => ['printflow_product_validate_admin_price', 'has_option_pricing'],
    'admin/product_field_config.php' => ['printflow_product_option_pricing_config'],
];
foreach ($contracts as $path => $needles) {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    pricing_assert(is_string($source), "Read source contract: {$path}");
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            pricing_assert(false, "Expected pricing contract in {$path}: {$needle}");
        }
    }
}
$apiCartSource = file_get_contents(__DIR__ . '/../customer/api_cart.php');
pricing_assert(strpos($apiCartSource, 'ORDER BY variant_id ASC LIMIT 1') === false, 'Cart API does not silently choose the first price variant.');

// Saved order rows remain snapshots: downstream views use the persisted unit_price.
$persistenceSource = file_get_contents(__DIR__ . '/../includes/order_items_persistence.php');
pricing_assert(is_string($persistenceSource) && strpos($persistenceSource, 'unit_price') !== false, 'Order-item persistence retains the resolved unit price snapshot.');
pricing_assert(strpos(file_get_contents(__DIR__ . '/../customer/checkout.php'), '(float)$unit_price') !== false, 'Checkout persists a unit price rather than a combined base plus option amount.');

echo "PASS product pricing contract cases\n";
