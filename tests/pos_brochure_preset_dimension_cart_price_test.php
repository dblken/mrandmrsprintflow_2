<?php
declare(strict_types=1);

/**
 * Brochure (and similar select+dimension services): preset size option value must
 * reach server pricing. Overwriting with "WxH" drops option price → unit base only.
 */

$root = dirname(__DIR__);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$brochurePresetLabel = '1pcs A3(11 X 16 Inch)';

$testConfigs = [
    'layout' => [
        'visible' => true,
        'label' => 'Layout',
        'type' => 'radio',
        'options' => [
            ['value' => 'With Layout', 'price' => 0],
            ['value' => 'Without Layout', 'price' => 0],
        ],
    ],
    'sizes' => [
        'visible' => true,
        'label' => 'Dimensions',
        'type' => 'select',
        'options' => [
            ['value' => $brochurePresetLabel, 'price' => 100.0],
            ['value' => 'Others', 'price' => 0],
        ],
    ],
];

function get_service_field_config($serviceId)
{
    global $testConfigs;
    return $testConfigs;
}

function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array
{
    return $customization;
}

function printflow_service_field_normalize_layout_values(array $values, array $fieldConfigs): array
{
    return $values;
}

function printflow_service_field_is_active(array $config, array $fieldValues, string $key, array $fieldConfigs): bool
{
    return true;
}

function printflow_service_option_values_match(string $left, string $right): bool
{
    return strcasecmp(trim($left), trim($right)) === 0;
}

function db_query(string $sql, string $types = '', array $params = []): array
{
    if (str_contains($sql, 'FROM services')) {
        return [['base_price' => 25]];
    }
    return [];
}

require_once $root . '/includes/service_order_helper.php';

$presetCustomization = [
    'service_id' => 67,
    'layout' => 'Without Layout',
    'sizes' => $brochurePresetLabel,
    'sizes_width' => '11',
    'sizes_height' => '16',
    'width' => '11',
    'height' => '16',
    'branch_id' => 1,
];

$brokenCustomization = $presetCustomization;
$brokenCustomization['sizes'] = '11x16';

$qty2Preset = printflow_calculate_service_line_pricing(67, $presetCustomization, 2, $testConfigs);
$assert(!empty($qty2Preset['ok']), 'Brochure preset sizes prices successfully');
$assert((float) $qty2Preset['unit_price'] === 125.0, 'Preset A3 option adds to base (₱25 + ₱100 unit)');
$assert((float) $qty2Preset['line_total'] === 250.0, 'Qty 2 line total matches modal estimate ₱250');

$qty2Broken = printflow_calculate_service_line_pricing(67, $brokenCustomization, 2, $testConfigs);
$assert(!empty($qty2Broken['ok']), 'Broken WxH-only sizes still returns a price');
$assert((float) $qty2Broken['unit_price'] === 25.0, 'WxH overwrite drops option price (regression signal)');
$assert((float) $qty2Broken['line_total'] === 50.0, 'Wrong payload undercharges vs modal');

$cartItem = [
    'type' => 'service',
    'source_page' => 'services',
    'service_id' => 67,
    'product_id' => 67,
    'quantity' => 2,
    'qty' => 2,
    'price' => 0,
    'customization' => $presetCustomization,
];
$applied = printflow_apply_service_line_pricing_to_cart_item($cartItem);
$assert(!empty($applied['ok']), 'Cart item reprices with preserved preset sizes');
$assert((float) $cartItem['estimated_price'] === 250.0, 'Cart estimated_price is line total ₱250');
$assert((float) $cartItem['price'] === 125.0, 'Cart stores authoritative unit ₱125');

// Quantity scaling on the same Brochure config must not change unit math.
foreach ([1 => 125.0, 4 => 500.0] as $qty => $expectedLine) {
    $priced = printflow_calculate_service_line_pricing(67, $presetCustomization, $qty, $testConfigs);
    $assert(!empty($priced['ok']), "Brochure preset qty {$qty} reprices");
    $assert((float) $priced['unit_price'] === 125.0, "Brochure unit stays ₱125 for qty {$qty}");
    $assert((float) $priced['line_total'] === $expectedLine, "Brochure qty {$qty} line total ₱{$expectedLine}");
}

$posSource = (string) file_get_contents($root . '/staff/pos.php');
$assert(str_contains($posSource, 'hasPricedPresetSelect'), 'POS preserves priced preset select before WxH overwrite');
$assert(str_contains($posSource, 'Estimated Price: ${formatMoney(confirmedLineTotal)}'),
    'Cart estimate label shows line total like the modal');

echo PHP_EOL . 'All Brochure preset dimension cart price tests passed.' . PHP_EOL;
