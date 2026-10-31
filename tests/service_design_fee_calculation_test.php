<?php
declare(strict_types=1);

$testConfigs = [
    'layout' => [
        'visible' => true,
        'label' => 'Layout',
        'type' => 'radio',
        'options' => [
            ['value' => 'With Layout', 'price' => 0],
            ['value' => 'Without Layout', 'price' => 200],
            ['value' => 'Without Layout - Premium', 'price' => 350],
        ],
    ],
    'color' => [
        'visible' => true,
        'label' => 'Color',
        'type' => 'select',
        'options' => [['value' => 'Red', 'price' => 25]],
    ],
];

function get_service_field_config($serviceId) { global $testConfigs; return $testConfigs; }
function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array { return $customization; }
function printflow_service_field_normalize_layout_values(array $values, array $fieldConfigs): array { return $values; }
function printflow_service_field_is_active(array $config, array $fieldValues, string $key, array $fieldConfigs): bool { return true; }
function printflow_service_option_values_match(string $left, string $right): bool { return strcasecmp(trim($left), trim($right)) === 0; }
function db_query(string $sql, string $types = '', array $params = []): array {
    if (str_contains($sql, 'FROM services')) return [['base_price' => 100]];
    return [];
}

require_once __DIR__ . '/../includes/service_order_helper.php';

function expect_pricing(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$without = printflow_calculate_service_line_pricing(1, ['layout' => 'Without Layout', 'color' => 'Red'], 3, $testConfigs);
expect_pricing(!empty($without['ok']), 'Without Layout is priced from server config');
expect_pricing($without['unit_price'] === 125.0, 'base and ordinary per-unit option are included');
expect_pricing($without['design_fee'] === 200.0, 'configured fee is applied once');
expect_pricing($without['line_total'] === 575.0, 'quantity multiplies base/options, not one-time fee');

$configured = printflow_calculate_service_line_pricing(1, ['layout' => 'Without Layout - Premium', 'color' => 'Red'], 3, $testConfigs);
expect_pricing($configured['design_fee'] === 350.0, 'selected configured fee is applied once');
expect_pricing($configured['line_total'] === 725.0, 'configured fee is applied once to the complete line');

$withLayout = printflow_calculate_service_line_pricing(1, ['layout' => 'With Layout', 'color' => 'Red'], 3, $testConfigs);
expect_pricing($withLayout['design_fee'] === 0.0 && $withLayout['line_total'] === 375.0, 'With Layout has no fee');

$missingLayout = printflow_calculate_service_line_pricing(1, ['color' => 'Red'], 3, $testConfigs);
expect_pricing(empty($missingLayout['ok']), 'layout selection cannot be silently omitted');

$unpricedConfig = $testConfigs;
$unpricedConfig['layout']['options'][1] = ['value' => 'Without Layout'];
$unpriced = printflow_design_fee_option(['layout' => 'Without Layout'], $unpricedConfig);
expect_pricing(!empty($unpriced['ok']) && $unpriced['fee_cents'] === 0, 'Without Layout with no configured fee costs zero instead of inventing a default');

echo "Service design fee calculation tests passed.\n";
