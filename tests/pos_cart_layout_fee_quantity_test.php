<?php
declare(strict_types=1);

/**
 * POS cart quantity edits must keep Without Layout design fee once per line
 * while recalculating per-unit charges from the authoritative server model.
 */

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
    'finish' => [
        'visible' => true,
        'label' => 'Finish',
        'type' => 'select',
        'options' => [
            ['value' => 'Matte', 'price' => 50],
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

require_once dirname(__DIR__) . '/includes/service_order_helper.php';

function expect_layout_qty(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$customization = [
    'service_id' => 99,
    'layout' => 'Without Layout',
    'finish' => 'Matte',
    'pos_design_line_id' => 'design-line-abc',
];

$qty1 = printflow_calculate_service_line_pricing(99, $customization, 1, $testConfigs);
expect_layout_qty(!empty($qty1['ok']), 'Without Layout qty 1 prices successfully');
expect_layout_qty((float)$qty1['unit_price'] === 150.0, 'unit price is base + finish only (100 + 50)');
expect_layout_qty((float)$qty1['design_fee'] === 200.0, 'Without Layout fee comes from configured option price once');
expect_layout_qty((float)$qty1['line_total'] === 350.0, 'qty 1 total = unit + one-time fee');
expect_layout_qty((string)$qty1['layout_mode'] === 'without_layout', 'layout mode is preserved as without_layout');

$qty3 = printflow_calculate_service_line_pricing(99, $customization, 3, $testConfigs);
expect_layout_qty((float)$qty3['design_fee'] === 200.0, 'increasing quantity does not multiply the one-time fee');
expect_layout_qty((float)$qty3['line_total'] === 650.0, 'qty 3 total = (150 * 3) + 200');
expect_layout_qty((float)$qty3['unit_price'] === 150.0, 'unit price stays stable across quantity edits');

$qty2 = printflow_calculate_service_line_pricing(99, $customization, 2, $testConfigs);
expect_layout_qty((float)$qty2['design_fee'] === 200.0, 'decreasing quantity keeps the one-time fee once');
expect_layout_qty((float)$qty2['line_total'] === 500.0, 'qty 2 total = (150 * 2) + 200');

$withLayout = printflow_calculate_service_line_pricing(99, [
    'service_id' => 99,
    'layout' => 'With Layout',
    'finish' => 'Matte',
], 4, $testConfigs);
expect_layout_qty((float)$withLayout['design_fee'] === 0.0, 'With Layout has no design fee');
expect_layout_qty((float)$withLayout['line_total'] === 600.0, 'With Layout qty edits multiply unit charges only');
expect_layout_qty((string)$withLayout['layout_mode'] === 'with_layout', 'With Layout mode is preserved');

$cartItem = [
    'product_id' => 99,
    'service_id' => 99,
    'is_service' => true,
    'qty' => 1,
    'price' => 150.0,
    'customization' => $customization,
];
$applied = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_layout_qty(!empty($applied['ok']), 'cart item helper applies authoritative line pricing');
expect_layout_qty((float)$cartItem['design_fee'] === 200.0, 'cart item stores design fee once');
expect_layout_qty((float)$cartItem['estimated_price'] === 350.0, 'cart item estimated total matches qty 1 formula');
expect_layout_qty((string)($cartItem['customization']['pos_design_line_id'] ?? '') === 'design-line-abc',
    'design line id survives the initial cart pricing helper');

$cartItem['qty'] = 3;
$cartItem['quantity'] = 3;
$reloaded = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_layout_qty(!empty($reloaded['ok']), 'cart reload/reprice succeeds after quantity change');
expect_layout_qty((float)$cartItem['design_fee'] === 200.0, 'reload does not duplicate the one-time fee');
expect_layout_qty((float)$cartItem['estimated_price'] === 650.0, 'reload total matches displayed qty 3 total');
expect_layout_qty((float)$cartItem['price'] === 150.0, 'reload keeps unit price separate from design fee');
expect_layout_qty((string)$cartItem['customization']['layout'] === 'Without Layout', 'layout selection survives cart reprice');
expect_layout_qty((string)($cartItem['customization']['pos_design_line_id'] ?? '') === 'design-line-abc',
    'design line id is not regenerated during quantity reprice');

$checkoutTotal = ((float)$cartItem['price'] * (int)$cartItem['qty']) + (float)$cartItem['design_fee'];
expect_layout_qty(abs($checkoutTotal - (float)$cartItem['estimated_price']) < 0.001,
    'checkout total formula matches displayed cart estimated total');

$handler = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
expect_layout_qty(str_contains($handler, "case 'update':")
    && str_contains($handler, 'printflow_apply_service_line_pricing_to_cart_item'),
    'POS cart quantity update recalculates service line pricing server-side');
expect_layout_qty(str_contains($handler, 'pos_design_line_id'),
    'quantity update preserves the design-line identity');
expect_layout_qty(str_contains($handler, "\$item['price_set'] = false"),
    'quantity change clears a stale Set Price override so totals stay authoritative');

$pos = (string)file_get_contents(dirname(__DIR__) . '/staff/pos.php');
expect_layout_qty(str_contains($pos, 'posCartItemDisplayedLineTotal')
    && str_contains($pos, 'Never divide a line total that'),
    'POS client no longer spreads the one-time fee across units via lineTotal/qty');
expect_layout_qty(str_contains($pos, 'posCartItemDisplayedLineTotal(item)'),
    'cart render uses the shared line-total helper matching server math');

$checkout = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
expect_layout_qty(str_contains($checkout, 'printflow_calculate_service_line_pricing'),
    'checkout remains authoritative with the same line-pricing helper');
expect_layout_qty(str_contains($checkout, 'pos_checkout_validate_service_item_prices')
    || str_contains($checkout, 'printflow_calculate_service_unit_price'),
    'checkout still validates service prices server-side');

$stock = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
expect_layout_qty(str_contains($stock, 'printflow_pos_tarpaulin_selection')
    && str_contains($handler, 'printflow_pos_validate_service_stock'),
    'Tarpaulin material/stock validation remains wired on cart mutations');

echo "POS cart layout fee quantity regression tests passed.\n";
