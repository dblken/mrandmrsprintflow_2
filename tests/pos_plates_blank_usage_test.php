<?php
declare(strict_types=1);

/**
 * Plates blank Actual Usage: 1 plate_media blank per ordered quantity for
 * New Plate (and other blank-consuming types). Ink/other consumables uncapped.
 */

$testConfigs = [
    'layout' => [
        'visible' => true,
        'label' => 'Layout',
        'type' => 'radio',
        'options' => [['value' => 'With Layout', 'price' => 0]],
    ],
];

function get_service_field_config($serviceId) { global $testConfigs; return $testConfigs; }
function printflow_service_field_values_from_customization(array $customization, array $fieldConfigs): array { return $customization; }
function printflow_service_field_normalize_layout_values(array $values, array $fieldConfigs): array { return $values; }
function printflow_service_field_is_active(array $config, array $fieldValues, string $key, array $fieldConfigs): bool { return true; }
function printflow_service_option_values_match(string $left, string $right): bool { return strcasecmp(trim($left), trim($right)) === 0; }
function db_query(string $sql, string $types = '', array $params = []): array
{
    if (str_contains($sql, 'FROM services')) {
        return [['base_price' => 100]];
    }
    return [];
}

require_once dirname(__DIR__) . '/includes/service_order_helper.php';
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_plates_release_rule.php';
require_once dirname(__DIR__) . '/includes/pos_service_quantity_update.php';

function expect_plates(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$newPlate = ['type_of_plates' => 'New Plate', 'service_id' => 39];
$motorCycle = ['type_of_plates' => 'Motor Cycle Plate', 'service_id' => 39];
$reprint = ['type_of_plates' => 'Reprint Existing Plate', 'service_id' => 39];

expect_plates(printflow_pos_plates_is_blank_item(39, 1), 'AC MC (#1) is a Plates blank');
expect_plates(printflow_pos_plates_is_blank_item(39, 7), 'SP MC (#7) is a Plates blank');
expect_plates(!printflow_pos_plates_is_blank_item(39, 18), 'Nextjet is not a Plates blank');
expect_plates(!printflow_pos_plates_is_blank_item(63, 1), 'blank rule does not leak to Mugs');

expect_plates(printflow_pos_plates_order_consumes_blank($newPlate), 'New Plate consumes a physical blank');
expect_plates(printflow_pos_plates_order_consumes_blank($motorCycle), 'Motor Cycle Plate also consumes a blank');
expect_plates(!printflow_pos_plates_order_consumes_blank($reprint), 'reprint/existing workflows do not consume a new blank');

expect_plates(printflow_pos_plates_canonical_blank_usage(1) === 1.0, 'qty 1 → 1 blank');
expect_plates(printflow_pos_plates_canonical_blank_usage(2) === 2.0, 'qty 2 → 2 blanks');

// Accept exact usage for qty 1 and 2.
printflow_pos_plates_assert_blank_usage(39, 1, 1.0, 1, $newPlate);
printflow_pos_plates_assert_blank_usage(39, 1, 2.0, 2, $newPlate);
expect_plates(true, 'exact blank usage for qty 1 and 2 is accepted');

// Reject usage above ordered quantity.
$rejected = false;
$rejectMessage = '';
try {
    printflow_pos_plates_assert_blank_usage(39, 1, 3.0, 2, $newPlate);
} catch (InvalidArgumentException $error) {
    $rejected = true;
    $rejectMessage = $error->getMessage();
}
expect_plates($rejected, 'blank usage above order qty is rejected');
expect_plates(
    $rejectMessage === 'Plate usage cannot exceed the order quantity (2 plates).',
    'reject message names the order quantity'
);

$rejectedOne = false;
try {
    printflow_pos_plates_assert_blank_usage(39, 2, 2.0, 1, $newPlate);
} catch (InvalidArgumentException $error) {
    $rejectedOne = str_contains($error->getMessage(), '(1 plate)');
}
expect_plates($rejectedOne, 'singular plate label used when order qty is 1');

// Ink / non-blank consumables are not capped by the plate blank rule.
$inkRejected = false;
try {
    printflow_pos_plates_assert_blank_usage(39, 18, 50.0, 2, $newPlate);
} catch (InvalidArgumentException $error) {
    $inkRejected = true;
}
expect_plates(!$inkRejected, 'non-blank mapped consumables are not capped by plate blank rule');

// Reprint type does not enforce blank cap even for plate_media items.
$reprintRejected = false;
try {
    printflow_pos_plates_assert_blank_usage(39, 1, 5.0, 2, $reprint);
} catch (InvalidArgumentException $error) {
    $reprintRejected = true;
}
expect_plates(!$reprintRejected, 'reprint workflows skip the new-blank usage cap');

// Cart quantity increase/decrease scales blank to match order qty; preserves price_set.
$acMc = [
    'id' => 1,
    'name' => 'AC MC',
    'unit_of_measure' => 'pcs',
    'status' => 'ACTIVE',
];
expect_plates(
    printflow_pos_resolve_scaled_material_quantity(1.0, 1, 2, $acMc, 39, 1, $newPlate) === 2.0,
    'blank usage scales 1→2 when cart qty increases'
);
expect_plates(
    printflow_pos_resolve_scaled_material_quantity(2.0, 2, 1, $acMc, 39, 1, $newPlate) === 1.0,
    'blank usage scales 2→1 when cart qty decreases'
);

$nextjet = [
    'id' => 18,
    'name' => 'Nextjet',
    'unit_of_measure' => 'pcs',
    'status' => 'ACTIVE',
];
// Other services / non-blank rows keep proportional whole-unit scaling.
expect_plates(
    printflow_pos_resolve_scaled_material_quantity(6.0, 3, 4, $nextjet, 36, 18, []) === 8.0,
    'other services keep proportional material scaling'
);

$assignments = printflow_pos_scale_material_assignment_rows([
    [
        'item_id' => 1,
        'name' => 'AC MC',
        'quantity' => 1.0,
        'metadata' => ['material_usage' => ['actual' => 1.0, 'method' => 'manual']],
    ],
    [
        'item_id' => 18,
        'name' => 'Nextjet',
        'quantity' => 3.0,
        'metadata' => ['material_usage' => ['actual' => 3.0, 'method' => 'manual']],
    ],
], 1, 2, 39, $newPlate);
expect_plates((float)$assignments[0]['quantity'] === 2.0, 'cart blank assignment scales to new order qty');
expect_plates((float)$assignments[1]['quantity'] === 6.0, 'non-blank consumable scales proportionally, not forced to order qty');
expect_plates(
    abs((float)$assignments[0]['metadata']['material_usage']['actual'] - 2.0) < 0.000001,
    'blank material_usage.actual tracks scaled order qty'
);

$cartItem = [
    'product_id' => 39,
    'service_id' => 39,
    'is_service' => true,
    'qty' => 1,
    'quantity' => 1,
    'price' => 250.0,
    'price_set' => true,
    'line_total_override' => 250.0,
    'design_fee' => 0.0,
    'pos_cart_line_id' => 'plates-line-stable',
    'customization' => $newPlate + ['layout' => 'With Layout', 'pos_design_line_id' => 'plates-design'],
    'pos_material_assignments' => [
        ['item_id' => 1, 'quantity' => 1.0, 'metadata' => ['material_usage' => ['actual' => 1.0]]],
    ],
];
$fromQty = 1;
$toQty = 2;
$previousOverride = $cartItem['line_total_override'];
$existing = $cartItem['pos_material_assignments'];
$cartItem['qty'] = $toQty;
$cartItem['quantity'] = $toQty;
$repriced = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_plates(!empty($repriced['ok']), 'Plates reprice succeeds after quantity increase');
printflow_pos_apply_quantity_change_to_confirmed_service_pricing(
    $cartItem,
    $fromQty,
    $toQty,
    true,
    $previousOverride,
    0.0
);
$cartItem['pos_material_assignments'] = printflow_pos_scale_material_assignment_rows(
    $existing,
    $fromQty,
    $toQty,
    39,
    $newPlate
);
expect_plates($cartItem['price_set'] === true, 'Plates quantity increase keeps price_set');
expect_plates((float)$cartItem['line_total_override'] === 500.0, 'Plates confirmed total scales with qty');
expect_plates((float)$cartItem['pos_material_assignments'][0]['quantity'] === 2.0, 'blank matches increased cart qty');

// Stock shortage contract (demand vs available) for scaled blank usage.
$required = printflow_pos_plates_canonical_blank_usage(2);
$available = 1.0;
expect_plates(
    printflow_pos_service_stock_exceeds_available($required, $available),
    'insufficient plate blank stock is detected after qty increase'
);
$stockMessage = 'Insufficient AC MC stock. Available: 1 pcs. Required: 2 pcs.';
expect_plates(
    str_contains($stockMessage, 'AC MC')
    && str_contains($stockMessage, 'Available: 1')
    && str_contains($stockMessage, 'Required: 2'),
    'stock shortage copy identifies the blank and available quantity'
);

$api = (string)file_get_contents(dirname(__DIR__) . '/admin/job_orders_api.php');
expect_plates(
    str_contains($api, 'printflow_pos_plates_assert_blank_usage'),
    'Production Assignment add_material enforces plate blank usage'
);

$handler = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
expect_plates(
    str_contains($handler, 'printflow_pos_sync_job_quantity_and_scale_materials')
    && str_contains($handler, '$serviceCustomization'),
    'cart quantity updates pass customization into material scaling'
);

$ui = (string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
expect_plates(
    str_contains($ui, 'platesBlankUsageError')
    && str_contains($ui, 'Plate usage cannot exceed the order quantity'),
    'Production Assignment UI surfaces the plate blank usage message'
);

echo "POS Plates blank usage regression tests passed.\n";
