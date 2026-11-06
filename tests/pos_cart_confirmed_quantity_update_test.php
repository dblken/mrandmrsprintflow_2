<?php
declare(strict_types=1);

/**
 * Confirmed POS service lines must keep Set Price + materials when quantity changes.
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
require_once dirname(__DIR__) . '/includes/pos_service_quantity_update.php';

function expect_confirmed_qty(bool $ok, string $message): void
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
    'Material Selection' => 'Vinyl Matte',
    'pos_design_line_id' => 'design-line-stable',
];

// Confirmed Set Price at qty 3: (150 * 3) + 200 = 650
$cartItem = [
    'product_id' => 99,
    'service_id' => 99,
    'is_service' => true,
    'qty' => 3,
    'quantity' => 3,
    'price' => 650.0,
    'price_set' => true,
    'line_total_override' => 650.0,
    'design_fee' => 200.0,
    'pos_cart_line_id' => 'cart-line-stable',
    'customization' => $customization,
    'pos_material_assignments' => [
        [
            'item_id' => 18,
            'quantity' => 6.0,
            'metadata' => [
                'actual_usage_version' => 'manual_v1',
                'material_usage' => ['actual' => 6.0, 'method' => 'manual', 'staff_confirmed' => true],
            ],
        ],
    ],
];

$fromQty = 3;
$toQty = 4;
$wasPriceSet = true;
$previousOverride = $cartItem['line_total_override'];
$previousFee = (float)$cartItem['design_fee'];
$existingAssignments = $cartItem['pos_material_assignments'];

$cartItem['qty'] = $toQty;
$cartItem['quantity'] = $toQty;
$repriced = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_confirmed_qty(!empty($repriced['ok']), 'authoritative reprice succeeds after quantity increase');
printflow_pos_apply_quantity_change_to_confirmed_service_pricing(
    $cartItem,
    $fromQty,
    $toQty,
    $wasPriceSet,
    $previousOverride,
    $previousFee
);
$cartItem['pos_material_assignments'] = printflow_pos_scale_material_assignment_rows(
    $existingAssignments,
    $fromQty,
    $toQty
);

expect_confirmed_qty($cartItem['price_set'] === true, 'increasing qty keeps price_set confirmed');
expect_confirmed_qty((float)$cartItem['line_total_override'] === 800.0, 'qty 4 total = (150 * 4) + one-time 200');
expect_confirmed_qty((float)$cartItem['design_fee'] === 200.0, 'Without Layout fee remains once after increase');
expect_confirmed_qty((string)$cartItem['customization']['layout'] === 'Without Layout', 'layout mode survives quantity increase');
expect_confirmed_qty((string)$cartItem['customization']['pos_design_line_id'] === 'design-line-stable', 'design-line id survives quantity increase');
expect_confirmed_qty((string)$cartItem['customization']['Material Selection'] === 'Vinyl Matte', 'selected material label is unchanged');
expect_confirmed_qty((int)$cartItem['pos_material_assignments'][0]['item_id'] === 18, 'same material item_id is kept');
expect_confirmed_qty(abs((float)$cartItem['pos_material_assignments'][0]['quantity'] - 8.0) < 0.000001, 'material demand scales 6→8 when qty 3→4');
expect_confirmed_qty(
    abs((float)$cartItem['pos_material_assignments'][0]['metadata']['material_usage']['actual'] - 8.0) < 0.000001,
    'material usage metadata scales with the assignment'
);

$fromQty = 4;
$toQty = 2;
$previousOverride = $cartItem['line_total_override'];
$previousFee = (float)$cartItem['design_fee'];
$existingAssignments = $cartItem['pos_material_assignments'];
$cartItem['qty'] = $toQty;
$cartItem['quantity'] = $toQty;
$repriced = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_confirmed_qty(!empty($repriced['ok']), 'authoritative reprice succeeds after quantity decrease');
printflow_pos_apply_quantity_change_to_confirmed_service_pricing(
    $cartItem,
    $fromQty,
    $toQty,
    true,
    $previousOverride,
    $previousFee
);
$cartItem['pos_material_assignments'] = printflow_pos_scale_material_assignment_rows(
    $existingAssignments,
    $fromQty,
    $toQty
);
expect_confirmed_qty($cartItem['price_set'] === true, 'decreasing qty keeps price_set confirmed');
expect_confirmed_qty((float)$cartItem['line_total_override'] === 500.0, 'qty 2 total = (150 * 2) + one-time 200');
expect_confirmed_qty(abs((float)$cartItem['pos_material_assignments'][0]['quantity'] - 4.0) < 0.000001, 'material demand scales down 8→4 when qty 4→2');
expect_confirmed_qty((string)$cartItem['pos_cart_line_id'] === 'cart-line-stable', 'cart line identity remains intact');

$withLayout = [
    'product_id' => 99,
    'is_service' => true,
    'qty' => 2,
    'quantity' => 2,
    'price' => 300.0,
    'price_set' => true,
    'line_total_override' => 300.0,
    'design_fee' => 0.0,
    'customization' => [
        'service_id' => 99,
        'layout' => 'With Layout',
        'finish' => 'Matte',
        'Material Selection' => 'Vinyl Matte',
        'pos_design_line_id' => 'with-layout-line',
    ],
];
$withPrev = $withLayout['line_total_override'];
$withLayout['qty'] = 5;
$withLayout['quantity'] = 5;
printflow_apply_service_line_pricing_to_cart_item($withLayout);
printflow_pos_apply_quantity_change_to_confirmed_service_pricing($withLayout, 2, 5, true, $withPrev, 0.0);
expect_confirmed_qty((float)$withLayout['design_fee'] === 0.0, 'With Layout keeps zero design fee');
expect_confirmed_qty((float)$withLayout['line_total_override'] === 750.0, 'With Layout confirmed total scales by unit * qty only');
expect_confirmed_qty($withLayout['price_set'] === true, 'With Layout quantity edit keeps confirmation');

$unitCents = printflow_pos_confirmed_unit_cents_from_line_total(650, 200, 3);
expect_confirmed_qty($unitCents === 15000, 'confirmed unit cents recover 150.00 from override');
$scaledShortage = printflow_pos_scale_amount_by_quantity_ratio(10.0, 2, 5);
expect_confirmed_qty(abs($scaledShortage - 25.0) < 0.000001, 'stock demand ratio helper scales required quantity');

$handler = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
expect_confirmed_qty(!str_contains($handler, "\$item['price_set'] = false"),
    'cart quantity update no longer clears price_set');
expect_confirmed_qty(str_contains($handler, 'printflow_pos_apply_quantity_change_to_confirmed_service_pricing'),
    'cart quantity update restores confirmed pricing after reprice');
expect_confirmed_qty(str_contains($handler, 'printflow_pos_sync_job_quantity_and_scale_materials')
    && str_contains($handler, 'printflow_pos_scale_material_assignment_rows'),
    'cart quantity update scales job/cart material assignments');
expect_confirmed_qty(str_contains($handler, 'printflow_pos_restore_job_quantity_materials'),
    'failed quantity updates roll back job material scaling');
expect_confirmed_qty(str_contains($handler, 'printflow_pos_validate_service_stock'),
    'stock validation remains wired for quantity updates');

$checkout = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
expect_confirmed_qty(str_contains($checkout, 'validatePendingMaterialStockPublic($jobId, $qty)'),
    'checkout revalidates materials against the cart quantity');
expect_confirmed_qty(str_contains($checkout, 'pos_checkout_validate_service_item_prices'),
    'checkout still validates service prices server-side');

$jobService = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
expect_confirmed_qty(str_contains($jobService, 'Service quantity changed after materials were assigned'),
    'job material validator still rejects unsynced quantity drift');

$productPath = dirname(__DIR__) . '/staff/api/pos_cart_handler.php';
expect_confirmed_qty(str_contains((string)file_get_contents($productPath), 'printflow_product_option_stock_prepare_cart_customization'),
    'unrelated product cart quantity path remains unchanged');

echo "POS confirmed cart quantity update regression tests passed.\n";
