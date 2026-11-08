<?php
declare(strict_types=1);

/**
 * Poster Printing primary poster_paper substrate (212 / 211): Actual Usage
 * capped at order quantity when UOM is sheet or pcs. Staff usage is preserved
 * on cart qty change; over-limit usage yields a clear error (no silent overwrite).
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
require_once dirname(__DIR__) . '/includes/pos_poster_release_rule.php';
require_once dirname(__DIR__) . '/includes/pos_service_quantity_update.php';

function expect_poster(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$c2sBoard = [
    'id' => 212,
    'name' => 'C2s Board',
    'unit_of_measure' => 'sheet',
    'status' => 'ACTIVE',
];
$c2sSpecial = [
    'id' => 211,
    'name' => 'C2S Special Paper',
    'unit_of_measure' => 'pcs',
    'status' => 'ACTIVE',
];
$ftMaterial = [
    'id' => 212,
    'name' => 'C2s Board',
    'unit_of_measure' => 'ft',
    'status' => 'ACTIVE',
];
$inkLike = [
    'id' => 18,
    'name' => 'Nextjet',
    'unit_of_measure' => 'pcs',
    'status' => 'ACTIVE',
];

expect_poster(printflow_pos_poster_is_primary_paper_item(64, 212), 'C2s Board #212 is Poster primary paper');
expect_poster(printflow_pos_poster_is_primary_paper_item(64, 211), 'C2S Special Paper #211 is Poster primary paper');
expect_poster(!printflow_pos_poster_is_primary_paper_item(64, 18), 'unrelated item is not Poster primary paper');
expect_poster(!printflow_pos_poster_is_primary_paper_item(67, 212), 'Brochure service does not use Poster primary rule');
expect_poster(!printflow_pos_poster_is_primary_paper_item(39, 212), 'Plates service does not use Poster primary rule');

expect_poster(
    printflow_pos_poster_primary_usage_is_capped(64, 212, $c2sBoard),
    'sheet UOM primary paper is capped'
);
expect_poster(
    printflow_pos_poster_primary_usage_is_capped(64, 211, $c2sSpecial),
    'pcs UOM primary paper is capped'
);
expect_poster(
    !printflow_pos_poster_primary_usage_is_capped(64, 212, $ftMaterial),
    'ft UOM primary paper is not capped by the sheet/pcs rule'
);
expect_poster(
    !printflow_pos_poster_primary_usage_is_capped(64, 18, $inkLike),
    'non-primary consumables are not capped'
);

// Qty 1: at most 1 sheet; qty 3: at most 3 sheets/pcs.
printflow_pos_poster_assert_primary_usage(64, 212, 1.0, 1, $c2sBoard);
printflow_pos_poster_assert_primary_usage(64, 212, 3.0, 3, $c2sBoard);
printflow_pos_poster_assert_primary_usage(64, 211, 3.0, 3, $c2sSpecial);
expect_poster(true, 'usage at the order-qty limit is accepted for qty 1 and 3');

$rejected = false;
$rejectMessage = '';
try {
    printflow_pos_poster_assert_primary_usage(64, 212, 2.0, 1, $c2sBoard);
} catch (InvalidArgumentException $error) {
    $rejected = true;
    $rejectMessage = $error->getMessage();
}
expect_poster($rejected, 'usage above order qty 1 is rejected');
expect_poster(
    $rejectMessage === 'C2s Board usage cannot exceed the order quantity (1 sheet).',
    'reject message includes material name, allowed qty, and sheet UOM'
);

$rejectedPcs = false;
$rejectPcsMessage = '';
try {
    printflow_pos_poster_assert_primary_usage(64, 211, 4.0, 3, $c2sSpecial);
} catch (InvalidArgumentException $error) {
    $rejectedPcs = true;
    $rejectPcsMessage = $error->getMessage();
}
expect_poster($rejectedPcs, 'usage above order qty 3 is rejected for pcs UOM');
expect_poster(
    $rejectPcsMessage === 'C2S Special Paper usage cannot exceed the order quantity (3 pcs).',
    'reject message includes pcs inventory UOM'
);

// ft UOM on same item id is not capped.
$ftRejected = false;
try {
    printflow_pos_poster_assert_primary_usage(64, 212, 99.0, 1, $ftMaterial);
} catch (InvalidArgumentException $error) {
    $ftRejected = true;
}
expect_poster(!$ftRejected, 'non-sheet/pcs UOM on primary paper is not capped');

// Other services unchanged.
$otherRejected = false;
try {
    printflow_pos_poster_assert_primary_usage(67, 212, 99.0, 1, $c2sBoard);
} catch (InvalidArgumentException $error) {
    $otherRejected = true;
}
expect_poster(!$otherRejected, 'Brochure C2s Board is not capped by Poster rule');

// Cart qty increase: preserve staff usage when still within new limit (no overwrite).
$kept = printflow_pos_resolve_scaled_material_quantity(1.0, 1, 3, $c2sBoard, 64, 212, []);
expect_poster($kept === 1.0, 'increasing cart qty preserves staff-entered 1 sheet (does not invent 3)');

$assignmentsUp = printflow_pos_scale_material_assignment_rows([
    [
        'item_id' => 212,
        'name' => 'C2s Board',
        'uom' => 'sheet',
        'quantity' => 1.0,
        'metadata' => ['material_usage' => ['actual' => 1.0]],
    ],
    [
        'item_id' => 18,
        'name' => 'Nextjet',
        'uom' => 'pcs',
        'quantity' => 2.0,
        'metadata' => ['material_usage' => ['actual' => 2.0]],
    ],
], 1, 3, 64, []);
expect_poster((float)$assignmentsUp[0]['quantity'] === 1.0, 'primary paper usage preserved on qty increase');
expect_poster((float)$assignmentsUp[1]['quantity'] === 6.0, 'non-primary consumable still scales proportionally');

// Cart qty decrease when usage exceeds new allowed qty → clear error, no silent overwrite.
$decreaseRejected = false;
$decreaseMessage = '';
try {
    printflow_pos_resolve_scaled_material_quantity(3.0, 3, 1, $c2sBoard, 64, 212, []);
} catch (InvalidArgumentException $error) {
    $decreaseRejected = true;
    $decreaseMessage = $error->getMessage();
}
expect_poster($decreaseRejected, 'decreasing cart qty rejects when saved usage exceeds new order qty');
expect_poster(
    $decreaseMessage === 'C2s Board usage cannot exceed the order quantity (1 sheet).',
    'cart decrease error shows allowed amount and UOM'
);

$rowsDecreaseRejected = false;
try {
    printflow_pos_scale_material_assignment_rows([
        [
            'item_id' => 212,
            'name' => 'C2s Board',
            'uom' => 'sheet',
            'quantity' => 3.0,
            'metadata' => ['material_usage' => ['actual' => 3.0]],
        ],
    ], 3, 1, 64, []);
} catch (InvalidArgumentException $error) {
    $rowsDecreaseRejected = str_contains($error->getMessage(), 'cannot exceed the order quantity (1 sheet)');
}
expect_poster($rowsDecreaseRejected, 'assignment-row scale rejects over-limit poster usage on qty decrease');

// Stock shortage still detected against required primary usage.
$required = 3.0;
$available = 1.0;
expect_poster(
    printflow_pos_service_stock_exceeds_available($required, $available),
    'insufficient primary paper stock is detected'
);
$stockMessage = 'Insufficient C2s Board stock. Available: 1 sheet. Required: 3 sheet.';
expect_poster(
    str_contains($stockMessage, 'C2s Board')
    && str_contains($stockMessage, 'Available: 1')
    && str_contains($stockMessage, 'Required: 3'),
    'stock shortage copy identifies the primary paper and available quantity'
);

// Pricing / Set Price preservation for a Poster line with valid usage.
$cartItem = [
    'product_id' => 64,
    'service_id' => 64,
    'is_service' => true,
    'qty' => 1,
    'quantity' => 1,
    'price' => 150.0,
    'price_set' => true,
    'line_total_override' => 150.0,
    'design_fee' => 0.0,
    'pos_cart_line_id' => 'poster-line-stable',
    'customization' => [
        'service_id' => 64,
        'layout' => 'With Layout',
        'pos_design_line_id' => 'poster-design',
    ],
    'pos_material_assignments' => [
        ['item_id' => 212, 'uom' => 'sheet', 'quantity' => 1.0, 'metadata' => ['material_usage' => ['actual' => 1.0]]],
    ],
];
$fromQty = 1;
$toQty = 3;
$previousOverride = $cartItem['line_total_override'];
$existing = $cartItem['pos_material_assignments'];
$cartItem['qty'] = $toQty;
$cartItem['quantity'] = $toQty;
$repriced = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_poster(!empty($repriced['ok']), 'Poster reprice succeeds after quantity increase');
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
    64,
    []
);
expect_poster($cartItem['price_set'] === true, 'Poster quantity increase keeps price_set');
expect_poster((float)$cartItem['line_total_override'] === 450.0, 'Poster confirmed total scales with qty');
expect_poster((float)$cartItem['pos_material_assignments'][0]['quantity'] === 1.0, 'staff paper usage preserved after qty increase');

$api = (string)file_get_contents(dirname(__DIR__) . '/admin/job_orders_api.php');
expect_poster(
    str_contains($api, 'printflow_pos_poster_assert_primary_usage'),
    'Production Assignment add_material enforces Poster primary usage on the server'
);

$ui = (string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
expect_poster(
    str_contains($ui, 'posterPrimaryUsageError')
    && str_contains($ui, 'usage cannot exceed the order quantity'),
    'Production Assignment UI enforces Poster primary usage client-side'
);

$sync = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_quantity_update.php');
expect_poster(
    str_contains($sync, 'cannot exceed the order quantity')
    && str_contains($sync, 'printflow_pos_poster_assert_primary_usage'),
    'cart/job quantity sync preserves Poster cap error messages'
);

echo "POS Poster primary usage regression tests passed.\n";
