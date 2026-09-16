<?php

/**
 * Verifies POS API exposes base_price and shared estimated-price assets exist.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$apiSource = (string)file_get_contents(__DIR__ . '/../staff/api/pos_service_fields.php');
$assert(str_contains($apiSource, 'base_price'), 'POS service fields API selects base_price');
$assert(str_contains($apiSource, "'base_price'"), 'POS service fields API returns base_price in JSON');

$posSource = (string)file_get_contents(__DIR__ . '/../staff/pos.php');
$assert(str_contains($posSource, 'service_estimated_price.js'), 'POS loads shared estimated price script');
$assert(str_contains($posSource, 'printflowInitServiceEstimatedPrice'), 'POS initializes shared estimated price module');
$assert(str_contains($posSource, 'destroyPosEstimatedPrice'), 'POS tears down estimator on close');

$customerSource = (string)file_get_contents(__DIR__ . '/../customer/order_service_dynamic.php');
$assert(str_contains($customerSource, 'service_estimated_price.js'), 'Customer page loads shared estimated price script');
$assert(str_contains($customerSource, 'printflowInitServiceEstimatedPrice'), 'Customer page uses shared estimated price init');
$assert(!str_contains($customerSource, 'window.calculateEstimatedPrice = function()'), 'Customer inline duplicate calculator removed');

$sharedJs = (string)file_get_contents(__DIR__ . '/../public/assets/js/service_estimated_price.js');
$assert(str_contains($sharedJs, 'pricing-field'), 'Shared JS reads pricing-field data-price attributes');
$assert(str_contains($sharedJs, 'nested-fields-container'), 'Shared JS handles nested conditional fields');
$assert(str_contains($sharedJs, 'pf-service-quantity-input'), 'Shared JS multiplies by quantity input');

$rendererSource = (string)file_get_contents(__DIR__ . '/../includes/service_field_renderer.php');
$assert(str_contains($rendererSource, 'data-price'), 'Field renderer emits data-price on options');

echo PHP_EOL . 'All service estimated price wiring tests passed.' . PHP_EOL;
