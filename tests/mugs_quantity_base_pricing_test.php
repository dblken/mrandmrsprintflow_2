<?php
declare(strict_types=1);

/**
 * Mugs quantity-based selling price: services.price × quantity for customer + POS.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mugs_base_pricing.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$assert(PRINTFLOW_MUGS_SERVICE_ID === 63, 'Mugs service id remains 63.');
$assert(printflow_is_mugs_service(63) && !printflow_is_mugs_service(40), 'Only service 63 is treated as Mugs.');

$assert(printflow_parse_mugs_selling_price_input('100') === 100.0, 'Configured ₱100 parses.');
$assert(printflow_parse_mugs_selling_price_input('100.00') === 100.0, 'Configured ₱100.00 parses.');
$assert(printflow_parse_mugs_selling_price_input('0') === null, 'Zero selling price is rejected.');
$assert(printflow_parse_mugs_selling_price_input('-1') === null, 'Negative selling price is rejected.');
$assert(printflow_parse_mugs_selling_price_input('avg-cost') === null, 'Non-numeric inventory-cost-like input is rejected.');

foreach ([1 => 100.0, 2 => 200.0, 4 => 400.0] as $qty => $expected) {
    $line = printflow_mugs_line_totals_from_unit_price(100.0, $qty);
    $assert(!empty($line['ok']) && (float)$line['unit_price'] === 100.0 && (float)$line['line_subtotal'] === $expected,
        "Qty {$qty} must total ₱{$expected} from configured ₱100/piece.");
}

$unconfigured = printflow_mugs_line_totals_from_unit_price(0.0, 4);
$assert(empty($unconfigured['ok']) && str_contains((string)$unconfigured['message'], 'services.price'),
    'Unconfigured Mugs price fails closed without inventing inventory average cost.');

// Shared server line pricing (customer + POS authority).
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
];
function get_service_field_config($serviceId) { global $testConfigs; return $testConfigs; }
function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array { return $customization; }
function printflow_service_field_normalize_layout_values(array $values, array $fieldConfigs): array { return $values; }
function printflow_service_field_is_active(array $config, array $fieldValues, string $key, array $fieldConfigs): bool { return true; }
function printflow_service_option_values_match(string $left, string $right): bool { return strcasecmp(trim($left), trim($right)) === 0; }
function db_query(string $sql, string $types = '', array $params = []): array {
    if (str_contains($sql, 'FROM services')) {
        $serviceId = (int)($params[0] ?? 0);
        if ($serviceId === 63) {
            return [['base_price' => 100]];
        }
        return [['base_price' => 0]];
    }
    return [];
}

require_once $root . '/includes/service_order_helper.php';

$customization = ['layout' => 'With Layout', 'service_id' => 63, 'service_type' => 'Mugs'];
foreach ([1 => 100.0, 2 => 200.0, 4 => 400.0] as $qty => $expected) {
    $priced = printflow_calculate_service_line_pricing(63, $customization, $qty, $testConfigs);
    $assert(!empty($priced['ok']), "Server pricing succeeds for Mugs qty {$qty}");
    $assert((float)$priced['unit_price'] === 100.0, "Server unit price stays ₱100 for qty {$qty}");
    $assert((float)$priced['line_total'] === $expected, "Server line total is ₱{$expected} for qty {$qty}");
}

$unit = printflow_calculate_service_unit_price(63, $customization);
$assert(!empty($unit['ok']) && (float)$unit['unit_price'] === 100.0, 'Unit price reads services.price, not inventory average cost.');

// Cart quantity edit rebuild uses the same authoritative helper.
$cartItem = [
    'type' => 'service',
    'source_page' => 'services',
    'service_id' => 63,
    'product_id' => 63,
    'quantity' => 2,
    'qty' => 2,
    'price' => 0,
    'customization' => $customization,
];
$repriced = printflow_apply_service_line_pricing_to_cart_item($cartItem);
$assert(!empty($repriced['ok']), 'Cart reprice succeeds after quantity edit.');
$assert((float)$cartItem['price'] === 100.0, 'Cart stores per-piece unit price after reprice.');
$assert((float)$cartItem['estimated_price'] === 200.0, 'Cart estimated total follows qty × unit for qty 2.');
$cartItem['quantity'] = 4;
$cartItem['qty'] = 4;
$repriced4 = printflow_apply_service_line_pricing_to_cart_item($cartItem);
$assert(!empty($repriced4['ok']) && (float)$cartItem['estimated_price'] === 400.0, 'Cart estimated total becomes ₱400 after qty edit to 4.');

// Source contracts: customer + POS keep server authority and Mug stock (item 37), not Box Mug.
$admin = (string)file_get_contents($root . '/admin/services_management.php');
$customerOrder = (string)file_get_contents($root . '/customer/order_service_dynamic.php');
$customerCartApi = (string)file_get_contents($root . '/customer/api_cart.php');
$customerCart = (string)file_get_contents($root . '/customer/cart.php');
$customerReview = (string)file_get_contents($root . '/customer/order_review.php');
$posFields = (string)file_get_contents($root . '/staff/api/pos_service_fields.php');
$posCart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$posCheckout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$helper = (string)file_get_contents($root . '/includes/service_order_helper.php');
$mugsRule = (string)file_get_contents($root . '/includes/pos_mugs_release_rule.php');

$assert(str_contains($admin, 'printflow_is_mugs_service($service_id)')
    && str_contains($admin, 'printflow_parse_mugs_selling_price_input')
    && str_contains($admin, 'fg-mugs-base-price'),
    'Admin persists Mugs services.price and exposes the per-piece selling-price field.');
$assert(str_contains($helper, 'printflow_is_mugs_service($serviceId)')
    && str_contains($helper, 'Inventory average cost is not used'),
    'Unit pricing refuses unconfigured Mugs without falling back to average cost.');
$assert(str_contains($customerOrder, 'printflow_mugs_assert_quantity_within_branch_stock')
    && str_contains($customerOrder, 'printflow_calculate_service_line_pricing'),
    'Customer add-to-cart recalculates server totals and rechecks Mug stock.');
$assert(str_contains($customerCartApi, 'printflow_apply_service_line_pricing_to_cart_item')
    && str_contains($customerCartApi, 'printflow_mugs_assert_quantity_within_branch_stock'),
    'Customer cart quantity edits reprice Mugs and recheck Mug stock.');
$assert(str_contains($customerCart, 'printflow_apply_service_line_pricing_to_cart_item')
    && str_contains($customerReview, 'printflow_mugs_assert_quantity_within_branch_stock')
    && str_contains($customerReview, 'printflow_calculate_service_line_pricing'),
    'Customer cart load and checkout revalidate Mugs price and stock.');
$assert(str_contains($posFields, 'COALESCE(price, 0) AS base_price')
    && str_contains($posCart, 'printflow_calculate_service_line_pricing')
    && str_contains($posCheckout, 'printflow_calculate_service_line_pricing'),
    'POS fields, cart, and checkout use services.price via server line pricing.');
$assert(str_contains($mugsRule, "'item_id' => 37")
    && str_contains($mugsRule, "!== 'mug'")
    && !str_contains(strtolower($mugsRule), 'box mug'),
    'Mug stock mapping stays item 37 Mug only; Box Mug is excluded.');

// Insufficient stock helper (no DB): invalid branch fails closed.
$noBranch = printflow_mugs_assert_quantity_within_branch_stock(4, 0);
$assert(empty($noBranch['ok']) && str_contains((string)$noBranch['message'], 'branch'),
    'Mugs stock validation requires a selected branch.');

echo "Mugs quantity base pricing tests passed.\n";
