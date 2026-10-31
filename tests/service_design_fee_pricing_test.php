<?php
require_once __DIR__ . '/../includes/service_design_fee.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';

function assert_fee_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}; expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

foreach ([1, 2, 4, 10, 100] as $qty) {
    $parts = printflow_service_line_totals_from_cents(1250, 20000, $qty);
    assert_fee_same(20000, $parts['design_fee_cents'], "one fee for quantity {$qty}");
    assert_fee_same((1250 * $qty) + 20000, $parts['line_total_cents'], "correct line total for quantity {$qty}");
}

$layoutConfig = ['layout' => [
    'visible' => true,
    'label' => 'Layout',
    'type' => 'radio',
    'options' => [
        ['value' => 'With Layout', 'price' => 0],
        ['value' => 'Without Layout', 'price' => 200],
        ['value' => 'Without Layout Plus', 'price' => 350],
    ],
]];
assert_fee_same(20000, printflow_design_fee_option(['layout' => 'Without Layout'], $layoutConfig)['fee_cents'], 'configured Without Layout fee is read from its option');
assert_fee_same(35000, printflow_design_fee_option(['layout' => 'Without Layout Plus'], $layoutConfig)['fee_cents'], 'selected option uses its own configured fee');
assert_fee_same(0, printflow_design_fee_option(['layout' => 'With Layout'], $layoutConfig)['fee_cents'], 'With Layout is free');
$nonzeroWithLayoutConfig = ['layout' => [
    'visible' => true, 'label' => 'Layout', 'type' => 'radio',
    'options' => [['value' => 'With Layout', 'price' => 99], ['value' => 'Without Layout', 'price' => 200]],
]];
assert_fee_same(0, printflow_design_fee_option(['layout' => 'With Layout'], $nonzeroWithLayoutConfig)['fee_cents'], 'With Layout stays free even if an Admin option value is nonzero');
assert_fee_same(false, printflow_design_fee_option([], $layoutConfig)['ok'], 'missing layout selection fails closed');
$unpricedLayoutConfig = $layoutConfig;
$unpricedLayoutConfig['layout']['options'][1] = ['value' => 'Without Layout'];
assert_fee_same(0, printflow_design_fee_option(['layout' => 'Without Layout'], $unpricedLayoutConfig)['fee_cents'], 'missing configured fee resolves to zero, never a hard-coded default');
assert_fee_same(false, printflow_design_fee_option(['layout' => 'Unconfigured Layout'], $layoutConfig)['ok'], 'unlisted option fails closed');

foreach ([1, 2, 10, 100] as $qty) {
    $parts = printflow_service_line_totals_from_cents(1250, 0, $qty);
    assert_fee_same(1250 * $qty, $parts['line_total_cents'], "With Layout has no fee at quantity {$qty}");
}

$configured200 = printflow_service_line_totals_from_cents(1250, 20000, 10);
$configured350 = printflow_service_line_totals_from_cents(1250, 35000, 10);
assert_fee_same(32500, $configured200['line_total_cents'], 'configured 200 fee is charged once per line');
assert_fee_same(47500, $configured350['line_total_cents'], 'configured 350 fee is charged once per line');
$distinctDesigns = printflow_service_line_totals_from_cents(1250, 20000, 10)['line_total_cents'] * 2;
assert_fee_same(65000, $distinctDesigns, 'two design lines each pay one fee');

$exactStaffTotal = printflow_order_item_calculated_line_total([
    'quantity' => 3,
    'unit_price' => 333.33,
    'design_fee' => 200.00,
    'line_total_override' => 1000.00,
]);
assert_fee_same(1000.00, $exactStaffTotal, 'exact Set Price line override wins without adding fee twice');
assert_fee_same(1000.00, pf_order_ui_item_estimated_total([
    'quantity' => 3,
    'unit_price' => 333.33,
    'design_fee' => 200.00,
    'line_total_override' => 1000.00,
    'estimated_price' => 1200.00,
    'approved_total' => 1200.00,
    'price_is_final' => true,
], true), 'order UI prefers exact line override over stale approved and estimated totals');
assert_fee_same(1199.99, printflow_order_item_calculated_line_total([
    'quantity' => 3, 'unit_price' => 333.33, 'design_fee' => 200.00,
]), 'calculated line total adds one design fee');

$migrationSource = (string)file_get_contents(__DIR__ . '/../database/migrate_order_items_design_fee_20261024.php');
assert_fee_same(true, str_contains($migrationSource, "'design_fee' =>"), 'migration adds design_fee column');
assert_fee_same(true, str_contains($migrationSource, "'line_total_override' =>"), 'migration adds exact line-total override column');
assert_fee_same(false, str_contains($migrationSource, 'UPDATE order_items'), 'migration does not backfill or rewrite historical rows');
$designFeeHelperSource = (string)file_get_contents(__DIR__ . '/../includes/service_design_fee.php');
assert_fee_same(false, str_contains($designFeeHelperSource, '200'), 'shared design-fee pricing helper contains no hard-coded ₱200 fallback');
assert_fee_same(true, str_contains($designFeeHelperSource, '$selectedOption[\'price\']'), 'Without Layout pricing reads the selected Admin option price');

$posCartSource = (string)file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
assert_fee_same(true, str_contains($posCartSource, "['pos_design_line_id'] = bin2hex(random_bytes(16))"), 'each POS customized service addition gets a distinct design-line identity');
$posCheckoutSource = (string)file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
assert_fee_same(true, str_contains($posCheckoutSource, 'pos_checkout_pair_design_fee_lines'), 'POS checkout pairs service pricing to server session');
assert_fee_same(true, str_contains($posCheckoutSource, 'line_total_override'), 'POS checkout preserves exact Set Price total');
$customerReviewSource = (string)file_get_contents(__DIR__ . '/../customer/order_review.php');
assert_fee_same(true, str_contains($customerReviewSource, 'printflow_apply_service_line_pricing_to_cart_item'), 'customer order review recalculates pricing from server configuration');
assert_fee_same(true, str_contains($customerReviewSource, '$design_fee,'), 'customer order review persists design fee with order line');
$customerCheckoutSource = (string)file_get_contents(__DIR__ . '/../customer/checkout.php');
assert_fee_same(true, str_contains($customerCheckoutSource, 'printflow_apply_service_line_pricing_to_cart_item'), 'customer checkout recalculates authoritative service pricing');
assert_fee_same(true, str_contains($customerCheckoutSource, '$design_fee,'), 'customer checkout persists design fee with order line');
$reportSource = (string)file_get_contents(__DIR__ . '/../includes/reports_dashboard_queries.php');
assert_fee_same(true, str_contains($reportSource, 'oi.line_total_override'), 'revenue queries use exact line overrides');
assert_fee_same(true, str_contains($reportSource, 'COALESCE(oi.design_fee, 0)'), 'revenue queries include one-time design fee');
$customerCheckoutPageSource = (string)file_get_contents(__DIR__ . '/../customer/checkout.php');
assert_fee_same(true, str_contains($customerCheckoutPageSource, "'estimated_total' => (float)(\$item['estimated_price'] ??"), 'customer job order estimate uses fee-inclusive server-calculated line total');
$setPriceSource = (string)file_get_contents(__DIR__ . '/../admin/job_orders_api.php');
assert_fee_same(true, str_contains($setPriceSource, 'UPDATE order_items SET line_total_override = ?'), 'Set Price stores the complete final amount against its uniquely linked line');
assert_fee_same(true, str_contains($setPriceSource, 'Apply the approved order item pricing migration before using Set Price.'), 'Set Price fails closed until additive exact-total schema is installed');
assert_fee_same(true, str_contains($setPriceSource, 'The linked order line could not be identified uniquely.'), 'Set Price does not update an ambiguous order line');
$receiptSource = (string)file_get_contents(__DIR__ . '/../includes/pos_receipt.php');
assert_fee_same(true, str_contains($receiptSource, 'printflow_order_item_calculated_line_total($row)'), 'POS receipts render the exact saved line total');

try {
    printflow_service_line_totals_from_cents(1, 0, 0);
    fwrite(STDERR, "FAIL: zero quantity should be rejected.\n");
    exit(1);
} catch (InvalidArgumentException $expected) {
}

echo "All service design fee pricing tests passed.\n";
