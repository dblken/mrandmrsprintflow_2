<?php
declare(strict_types=1);

function get_service_field_config($serviceId): array
{
    return [
        'layout' => [
            'visible' => true,
            'label' => 'Layout',
            'type' => 'radio',
            'options' => [
                ['value' => 'With Layout', 'price' => 0],
                ['value' => 'Without Layout', 'price' => 200],
            ],
        ],
    ];
}

function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array
{
    return $customization;
}

function printflow_calculate_service_unit_price(int $serviceId, array $customization): array
{
    return ['ok' => false, 'unit_price' => 0.0, 'reason' => 'unconfigured', 'message' => 'No generic unit price'];
}

require_once __DIR__ . '/../includes/service_design_fee.php';

$item = [
    'type' => 'Service',
    'source_page' => 'services',
    'service_id' => 40,
    'price' => 120.00,
    'quantity' => 3,
    'customization' => ['layout' => 'Without Layout'],
];
$result = printflow_apply_service_line_pricing_to_cart_item($item);
if (empty($result['ok']) || $item['price'] !== 120.0 || $item['design_fee'] !== 200.0 || $item['estimated_price'] !== 560.0) {
    fwrite(STDERR, "FAIL: dedicated service unit price must be preserved and receive one fee per design line.\n");
    exit(1);
}

$item['customization']['layout'] = 'With Layout';
$result = printflow_apply_service_line_pricing_to_cart_item($item);
if (empty($result['ok']) || $item['design_fee'] !== 0.0 || $item['estimated_price'] !== 360.0) {
    fwrite(STDERR, "FAIL: With Layout must keep the existing unit price with no fee.\n");
    exit(1);
}

echo "Dedicated service cart fallback pricing tests passed.\n";
