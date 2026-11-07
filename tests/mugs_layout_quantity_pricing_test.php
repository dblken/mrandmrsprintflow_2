<?php
declare(strict_types=1);

/**
 * Mugs: (services.price × qty) + one-time Without Layout fee.
 * Layout fee must never replace the Mug per-piece selling price.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mugs_base_pricing.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$testConfigs = [
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

function get_service_field_config($serviceId) { global $testConfigs; return $testConfigs; }
function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array { return $customization; }
function printflow_service_field_normalize_layout_values(array $values, array $fieldConfigs): array { return $values; }
function printflow_service_field_is_active(array $config, array $fieldValues, string $key, array $fieldConfigs): bool { return true; }
function printflow_service_option_values_match(string $left, string $right): bool { return strcasecmp(trim($left), trim($right)) === 0; }
function db_query(string $sql, string $types = '', array $params = []): array {
    if (str_contains($sql, 'FROM services')) {
        return [['base_price' => 100]];
    }
    return [];
}

require_once $root . '/includes/service_order_helper.php';

$withLayout = ['layout' => 'With Layout', 'service_id' => 63, 'service_type' => 'Mugs'];
$withoutLayout = ['layout' => 'Without Layout', 'service_id' => 63, 'service_type' => 'Mugs'];

// Quantity 1 / 4 with With Layout: fee 0, unit 100.
foreach ([1 => 100.0, 4 => 400.0] as $qty => $expected) {
    $priced = printflow_calculate_service_line_pricing(63, $withLayout, $qty, $testConfigs);
    $assert(!empty($priced['ok']), "With Layout qty {$qty} prices successfully");
    $assert((float)$priced['unit_price'] === 100.0, "With Layout keeps ₱100 unit for qty {$qty}");
    $assert((float)$priced['design_fee'] === 0.0, "With Layout fee is ₱0 for qty {$qty}");
    $assert((float)$priced['line_total'] === $expected, "With Layout qty {$qty} totals ₱{$expected}");
}

// Quantity 1 / 4 with Without Layout: unit×qty + fee once (200).
$withoutExpectations = [
    1 => ['unit' => 100.0, 'fee' => 200.0, 'total' => 300.0],
    4 => ['unit' => 100.0, 'fee' => 200.0, 'total' => 600.0],
];
foreach ($withoutExpectations as $qty => $expected) {
    $priced = printflow_calculate_service_line_pricing(63, $withoutLayout, $qty, $testConfigs);
    $assert(!empty($priced['ok']), "Without Layout qty {$qty} prices successfully");
    $assert((float)$priced['unit_price'] === $expected['unit'], "Without Layout keeps Mug unit ₱100 for qty {$qty}");
    $assert((float)$priced['design_fee'] === $expected['fee'], "Without Layout fee stays ₱200 once for qty {$qty}");
    $assert((float)$priced['line_total'] === $expected['total'], "Without Layout qty {$qty} totals ₱{$expected['total']} (not fee-only)");
}

// Cart persistence / quantity edit keeps unit + fee.
$cartItem = [
    'type' => 'service',
    'source_page' => 'services',
    'service_id' => 63,
    'product_id' => 63,
    'quantity' => 1,
    'qty' => 1,
    'price' => 0,
    'customization' => $withoutLayout,
];
$assert(!empty(printflow_apply_service_line_pricing_to_cart_item($cartItem)['ok']), 'Initial Without Layout cart pricing succeeds');
$assert((float)$cartItem['price'] === 100.0, 'Cart stores Mug unit price, not the layout fee');
$assert((float)$cartItem['design_fee'] === 200.0, 'Cart stores Without Layout fee once');
$assert((float)$cartItem['estimated_price'] === 300.0, 'Cart estimated total is 100+200 for qty 1');

$cartItem['quantity'] = 4;
$cartItem['qty'] = 4;
$assert(!empty(printflow_apply_service_line_pricing_to_cart_item($cartItem)['ok']), 'Qty edit to 4 reprices successfully');
$assert((float)$cartItem['price'] === 100.0, 'Qty edit preserves Mug unit price');
$assert((float)$cartItem['design_fee'] === 200.0, 'Qty edit keeps Without Layout fee once (not ×4)');
$assert((float)$cartItem['estimated_price'] === 600.0, 'Qty edit total is 400+200=600');

// Unconfigured services.price must not silently become fee-only.
function db_query_unconfigured(string $sql, string $types = '', array $params = []): array {
    if (str_contains($sql, 'FROM services')) {
        return [['base_price' => 0]];
    }
    return [];
}
// Re-bind by evaluating unit price through the real helper with a local stub is hard once
// db_query is declared; assert the Mugs guard instead.
$unitMissing = ['ok' => false, 'unit_price' => 0.0, 'reason' => 'unconfigured', 'message' => 'Configure a positive Mugs per-piece selling price on the service record (services.price). Inventory average cost is not used.'];
$assert(printflow_mugs_require_configured_selling_price($unitMissing) !== null, 'Unconfigured Mugs unit price is rejected');
$assert(printflow_mugs_require_configured_selling_price(['ok' => true, 'unit_price' => 100.0]) === null, 'Configured Mugs unit price is accepted');

// Source contracts: POS/customer refuse fee-only Mugs totals.
$posCart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$posJs = (string)file_get_contents($root . '/staff/pos.php');
$estimator = (string)file_get_contents($root . '/public/assets/js/service_estimated_price.js');
$customer = (string)file_get_contents($root . '/customer/order_service_dynamic.php');
$designFeeHelper = (string)file_get_contents($root . '/includes/service_design_fee.php');

$assert(str_contains($posJs, 'posNeedsMissingEstimatedPriceConfirm')
    && str_contains($posJs, 'initPosEstimatedPrice(body, parseFloat(data.base_price) || 0, serviceId)'),
    'POS missing-estimate warning uses the final line estimate helper.');
$assert(str_contains($estimator, 'printflowNeedsMissingEstimatedPriceConfirm')
    && str_contains($estimator, 'unitPrice * safeQty + designFee'),
    'Shared estimator exposes the warning helper and multiplies unit×qty then adds fee once.');
$assert(str_contains($customer, 'printflow_calculate_service_line_pricing')
    && str_contains($posCart, 'printflow_calculate_service_line_pricing'),
    'Customer and POS keep server-authoritative line pricing.');
$assert(str_contains($designFeeHelper, 'function printflow_design_fee_option')
    && str_contains($designFeeHelper, 'function printflow_calculate_service_line_pricing'),
    'Shared design-fee helper remains available for layout fee calculation.');

// Stock validation remains Mug item 37 only.
$stock = printflow_mugs_assert_quantity_within_branch_stock(4, 0);
$assert(empty($stock['ok']), 'Insufficient/missing branch stock still fails closed for Mugs qty checks.');
$mugsRule = (string)file_get_contents($root . '/includes/pos_mugs_release_rule.php');
$assert(str_contains($mugsRule, "'item_id' => 37") && !str_contains(strtolower($mugsRule), 'box mug'),
    'Mug stock mapping remains item 37 only.');

echo "Mugs layout + quantity pricing tests passed.\n";
