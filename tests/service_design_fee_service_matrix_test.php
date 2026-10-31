<?php
declare(strict_types=1);

$feeMatrixConfig = [];
function get_service_field_config($serviceId): array
{
    global $feeMatrixConfig;
    return $feeMatrixConfig[(int)$serviceId] ?? [];
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

function fee_matrix_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// Read-only production service_field_configs snapshot, queried on 2026-10-24
// from u618446170_printflow. Test fixtures only; no production rows are written.
$services = [
    33 => ['T-Shirt Printing', 20000],
    36 => ['Stickers /Decals', 20000],
    39 => ['Plates', 20000],
    40 => ['Tarpaulin', 20000],
    41 => ['Reflectorized Signage', 0],
    63 => ['Mugs', 20000],
    64 => ['Poster Printing', 20000],
    65 => ['Raffle Ticket Printing', 0],
    67 => ['Brochure', 20000],
    71 => ['Sintraboard Standees', 20000],
];

foreach ($services as $serviceId => [$name, $feeCents]) {
    $config = ['layout' => [
        'visible' => true,
        'label' => 'Layout',
        'type' => 'radio',
        'options' => [
            ['value' => 'With Layout', 'price' => 0],
            ['value' => 'Without Layout', 'price' => $feeCents / 100],
        ],
    ]];
    $feeMatrixConfig[$serviceId] = $config;

    foreach ([1, 3, 4, 10] as $quantity) {
        $with = printflow_design_fee_option(['layout' => 'With Layout'], $config);
        $without = printflow_design_fee_option(['layout' => 'Without Layout'], $config);
        fee_matrix_assert($with['ok'] && $with['fee_cents'] === 0, "{$serviceId} {$name}: With Layout is zero at quantity {$quantity}");
        fee_matrix_assert($without['ok'] && $without['fee_cents'] === $feeCents, "{$serviceId} {$name}: Without Layout uses configured fee at quantity {$quantity}");

        $withTotals = printflow_service_line_totals_from_cents(10000, (int)$with['fee_cents'], $quantity);
        $withoutTotals = printflow_service_line_totals_from_cents(10000, (int)$without['fee_cents'], $quantity);
        fee_matrix_assert($withTotals['line_total_cents'] === 10000 * $quantity, "{$serviceId} {$name}: no fee is multiplied or added for With Layout");
        fee_matrix_assert($withoutTotals['line_total_cents'] === (10000 * $quantity) + $feeCents, "{$serviceId} {$name}: one configured fee per design line, not per unit");
    }

    $perDesignLine = printflow_service_line_totals_from_cents(10000, $feeCents, 4)['line_total_cents'];
    $twoDistinctDesigns = $perDesignLine + $perDesignLine;
    fee_matrix_assert($twoDistinctDesigns === (2 * (40000 + $feeCents)), "{$serviceId} {$name}: two separate designs each retain one fee");

    foreach (['With Layout' => 0, 'Without Layout' => $feeCents / 100] as $layout => $expectedFee) {
        $cartLine = [
            'type' => 'Service', 'source_page' => 'services', 'service_id' => $serviceId,
            'price' => 100.00, 'quantity' => 3, 'customization' => ['layout' => $layout],
        ];
        $resolved = printflow_apply_service_line_pricing_to_cart_item($cartLine);
        fee_matrix_assert(!empty($resolved['ok']), "{$serviceId} {$name}: shared cart pricing resolves {$layout}");
        fee_matrix_assert($cartLine['price'] === 100.0, "{$serviceId} {$name}: shared cart pricing preserves the server-calculated unit price");
        fee_matrix_assert($cartLine['design_fee'] === (float)$expectedFee, "{$serviceId} {$name}: shared cart pricing applies the configured fee once");
        fee_matrix_assert($cartLine['estimated_price'] === (300.0 + (float)$expectedFee), "{$serviceId} {$name}: shared cart total is quantity times unit price plus one fee");
    }
}

$withoutLayoutConfig = [];
$notApplicable = printflow_design_fee_option([], $withoutLayoutConfig);
fee_matrix_assert($notApplicable['ok'] && $notApplicable['fee_cents'] === 0, 'service without a layout field has no design fee');

$feeMatrixConfig[999] = ['layout' => [
    'visible' => true, 'label' => 'Layout', 'type' => 'radio',
    'options' => [
        ['value' => 'With Layout', 'price' => 0],
        ['value' => 'Without Layout', 'price' => 350],
    ],
]];
$dynamicFeeItem = [
    'type' => 'Service', 'source_page' => 'services', 'service_id' => 999,
    'price' => 100.00, 'quantity' => 3, 'customization' => ['layout' => 'Without Layout'],
];
$dynamicFeeResult = printflow_apply_service_line_pricing_to_cart_item($dynamicFeeItem);
fee_matrix_assert(!empty($dynamicFeeResult['ok']) && $dynamicFeeItem['design_fee'] === 350.0, 'synthetic Admin-configured fee of ₱350 is read dynamically');
fee_matrix_assert($dynamicFeeItem['estimated_price'] === 650.0, 'synthetic ₱350 fee is charged once at quantity 3');

$changedAdminItem = [
    'type' => 'Service', 'source_page' => 'services', 'service_id' => 33,
    'price' => 100.00, 'quantity' => 3, 'customization' => ['layout' => 'Without Layout'],
];
$feeBeforeAdminChange = printflow_apply_service_line_pricing_to_cart_item($changedAdminItem);
fee_matrix_assert(!empty($feeBeforeAdminChange['ok']) && $changedAdminItem['design_fee'] === 200.0, 'existing service initially uses the current Admin-configured ₱200 option');
$feeMatrixConfig[33]['layout']['options'][1]['price'] = 350;
$feeAfterAdminChange = printflow_apply_service_line_pricing_to_cart_item($changedAdminItem);
fee_matrix_assert(!empty($feeAfterAdminChange['ok']) && $changedAdminItem['design_fee'] === 350.0, 'subsequent pricing reflects an Admin fee change without code edits');
fee_matrix_assert($changedAdminItem['estimated_price'] === 650.0, 'updated Admin fee is still charged once at quantity 3');
$savedHistoricalLine = ['quantity' => 3, 'unit_price' => 100.00, 'design_fee' => 200.00, 'line_total_override' => null];
fee_matrix_assert(printflow_order_item_calculated_line_total($savedHistoricalLine) === 500.0, 'historical saved fee remains ₱200 after the current Admin fee changes to ₱350');

echo "All 10 configured service layout-fee matrix checks plus zero/no-fee and dynamic ₱350 checks passed.\n";
