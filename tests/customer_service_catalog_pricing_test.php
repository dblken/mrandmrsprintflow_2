<?php

if (!defined('BASE_PATH')) define('BASE_PATH', '');
require_once __DIR__ . '/../includes/customer_service_catalog.php';

$failures = [];
function catalog_pricing_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$fixtures = [
    'Raffle Ticket Printing' => [],
    'Reflectorized Signage' => [[
        'is_visible' => 1,
        'field_options' => json_encode([
            ['value' => 'Standard', 'price' => 0],
            ['value' => 'Other size', 'price' => null],
        ]),
    ]],
    'Sticker Decals' => [[
        'is_visible' => 1,
        'field_options' => json_encode([
            ['value' => 'Disabled legacy choice', 'price' => 10, 'enabled' => false],
            ['value' => 'Deleted legacy choice', 'price' => 20, 'deleted_at' => '2026-07-01 10:00:00'],
            ['value' => 'Historic placeholder', 'price' => 1],
            ['value' => 'Small decal', 'price' => 50],
            ['value' => 'Large decal', 'price' => 125],
        ]),
    ]],
    'T-Shirt Printing' => [[
        'is_visible' => 0,
        'field_options' => json_encode([
            ['value' => 'Hidden old price', 'price' => 25],
        ]),
    ]],
    'Tarpaulin' => [[
        'is_visible' => 1,
        'field_options' => json_encode([
            ['value' => '3x4', 'price' => 0],
            ['value' => 'Custom dimensions', 'price' => 1],
        ]),
    ]],
];

$actual = [];
foreach ($fixtures as $serviceName => $fieldRows) {
    $actual[$serviceName] = printflow_catalog_pricing_metadata_from_fields($fieldRows);
}

catalog_pricing_assert($actual['Raffle Ticket Printing'] === [
    'pricing_type' => 'custom',
    'display_price' => null,
    'minimum_price' => null,
    'price_label' => 'Custom Pricing',
], 'Raffle Ticket Printing should be Custom Pricing.');
catalog_pricing_assert($actual['Reflectorized Signage']['price_label'] === 'Price after review', 'Reflectorized Signage should require review.');
catalog_pricing_assert($actual['Sticker Decals']['pricing_type'] === 'options', 'Sticker Decals should use option pricing.');
catalog_pricing_assert($actual['Sticker Decals']['minimum_price'] === 50.0, 'Sticker Decals should exclude disabled, deleted, zero, and 1.00 choices.');
catalog_pricing_assert($actual['Sticker Decals']['price_label'] === 'Starts at ₱50.00', 'Sticker Decals should display Starts at ₱50.00.');
catalog_pricing_assert($actual['T-Shirt Printing']['price_label'] === 'Custom Pricing', 'Hidden T-Shirt prices must not affect the catalog.');
catalog_pricing_assert($actual['Tarpaulin']['price_label'] === 'Price after review', 'Tarpaulin placeholder choices must require review.');

$nested = printflow_catalog_pricing_metadata_from_fields([[
    'is_visible' => 1,
    'field_options' => json_encode([[
        'value' => 'Printed',
        'price' => 80,
        'nested_fields' => [[
            'label' => 'Finishing',
            'options' => [
                ['value' => 'Disabled finish', 'price' => 5, 'is_active' => 0],
                ['value' => 'Laminate', 'price' => 35],
            ],
        ]],
    ]]),
]]);
catalog_pricing_assert($nested['minimum_price'] === 35.0, 'Enabled nested option prices should participate in the minimum.');

$servicesPage = (string)file_get_contents(__DIR__ . '/../customer/services.php');
$adminServices = (string)file_get_contents(__DIR__ . '/../admin/services_management.php');
$seedServices = (string)file_get_contents(__DIR__ . '/../includes/ensure_services_table.php');
$catalogApi = (string)file_get_contents(__DIR__ . '/../customer/api_services.php');
$paymentPage = (string)file_get_contents(__DIR__ . '/../customer/payment.php');
$staffPriceApi = (string)file_get_contents(__DIR__ . '/../admin/job_orders_api.php');

catalog_pricing_assert(strpos($servicesPage, "\$row['price']") === false, 'Customer catalog must not read services.price.');
catalog_pricing_assert(strpos($servicesPage, 'price_label') !== false, 'Service cards must use explicit price labels.');
catalog_pricing_assert(
    strpos($servicesPage, 'Customize Now') !== false
        && strpos($servicesPage, 'order_service_dynamic.php?service_id=') !== false,
    'Quote-only services must remain orderable.'
);
catalog_pricing_assert(strpos($adminServices, '$price = 1.0;') === false, 'Admin must not write the 1.00 placeholder.');
catalog_pricing_assert(strpos($seedServices, '$price = 1.0;') === false, 'Service seeds must not write the 1.00 placeholder.');
foreach (['pricing_type', 'display_price', 'minimum_price', 'price_label'] as $key) {
    catalog_pricing_assert(strpos($catalogApi, "'{$key}'") !== false, "Catalog API is missing {$key}.");
}
catalog_pricing_assert(strpos($staffPriceApi, 'UPDATE services SET price') === false, 'Staff quotation actions must not update the service catalog.');
catalog_pricing_assert(
    strpos($paymentPage, '$total_amount = (float)($order[\'total_amount\'] ?? 0);') !== false
        && strpos($paymentPage, 'if ($total_amount <= 0) {') !== false
        && strpos($paymentPage, '$total_amount = $calculated_total;') !== false,
    'Payment page must continue reading the order-specific staff-approved total.'
);

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

foreach ($actual as $serviceName => $metadata) {
    echo $serviceName . ': ' . $metadata['price_label'] . PHP_EOL;
}
echo "Customer service catalog pricing tests passed.\n";
