<?php
declare(strict_types=1);

/**
 * Mugs cart quantity changes scale Mug (1 pcs/mug) + Subli Paper (0.5 sheet/mug),
 * keep Set Price confirmation, accept exact half-sheet inventory usage, reject
 * insufficient stock clearly, and leave other services on whole-unit sheet rules.
 */

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
function db_query(string $sql, string $types = '', array $params = []): array
{
    if (str_contains($sql, 'FROM services')) {
        return [['base_price' => 100]];
    }
    return [];
}

require_once dirname(__DIR__) . '/includes/service_order_helper.php';
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_service_quantity_update.php';
require_once dirname(__DIR__) . '/includes/pos_mugs_release_rule.php';
require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';

function expect_mugs_qty(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$mugItem = [
    'id' => 37,
    'name' => 'Mug',
    'unit_of_measure' => 'pcs',
    'status' => 'ACTIVE',
];
$subliItem = [
    'id' => 55,
    'name' => 'Subli Paper',
    'unit_of_measure' => 'sheet',
    'status' => 'ACTIVE',
];
$vinylSheets = [
    'id' => 18,
    'name' => 'Vinyl Matte',
    'unit_of_measure' => 'sheets',
    'status' => 'ACTIVE',
];

expect_mugs_qty(printflow_pos_mugs_subli_sheets_per_mug() === 0.5, 'canonical Subli rate is 0.5 sheet per mug');
expect_mugs_qty(printflow_pos_mugs_canonical_subli_sheets(1) === 0.5, '1 mug → 0.5 Subli sheet');
expect_mugs_qty(printflow_pos_mugs_canonical_subli_sheets(2) === 1.0, '2 mugs → exactly 1 Subli sheet');
expect_mugs_qty(printflow_pos_mugs_canonical_subli_sheets(4) === 2.0, '4 mugs → exactly 2 Subli sheets');

expect_mugs_qty(printflow_pos_material_allows_fractional_sheet_usage($subliItem), 'Subli Paper sheets may be fractional');
expect_mugs_qty(!printflow_pos_material_allows_fractional_sheet_usage($vinylSheets), 'other sheet materials stay whole-unit');

expect_mugs_qty(
    printflow_pos_parse_positive_material_quantity(0.5, $subliItem) === 0.5,
    'parser accepts 0.5 sheet Subli Paper usage'
);
expect_mugs_qty(
    printflow_pos_parse_positive_material_quantity('1.00', $subliItem) === 1.0,
    'parser accepts whole Subli Paper sheet totals'
);
$genericSheetRejected = false;
try {
    printflow_pos_parse_positive_material_quantity(0.5, $vinylSheets);
} catch (InvalidArgumentException $error) {
    $genericSheetRejected = true;
}
expect_mugs_qty($genericSheetRejected, 'non-Subli sheet materials still reject 0.5');

expect_mugs_qty(printflow_pos_mugs_is_quantity_linked_material(37, 'Mug'), 'Mug #37 is quantity-linked');
expect_mugs_qty(printflow_pos_mugs_is_subli_paper_material(55, 'Subli Paper'), 'Subli Paper #55 is identified');
expect_mugs_qty(!printflow_pos_mugs_is_quantity_linked_material(56, 'Box Mug'), 'Box Mug stays off the linked scale rule');

// Canonical Actual Usage at qty 1, then increase 1 → 4.
$mugScaled = printflow_pos_resolve_scaled_material_quantity(1.0, 1, 4, $mugItem, 63, 37);
$subliScaled = printflow_pos_resolve_scaled_material_quantity(0.5, 1, 4, $subliItem, 63, 55);
expect_mugs_qty($mugScaled === 4.0, 'Mug usage scales 1→4 pcs when order qty 1→4');
expect_mugs_qty($subliScaled === 2.0, 'Subli Paper scales 0.5→2 sheets when order qty 1→4 (not rounded up per mug)');

// Decrease 4 → 2 from synced 0.5/mug assignments.
expect_mugs_qty(
    printflow_pos_resolve_scaled_material_quantity(4.0, 4, 2, $mugItem, 63, 37) === 2.0,
    'Mug usage scales down 4→2 when order qty decreases'
);
expect_mugs_qty(
    printflow_pos_resolve_scaled_material_quantity(2.0, 4, 2, $subliItem, 63, 55) === 1.0,
    'Subli Paper scales down 2→1 sheet when order qty 4→2'
);

// Midpoint qty 1 → 2.
expect_mugs_qty(
    printflow_pos_resolve_scaled_material_quantity(0.5, 1, 2, $subliItem, 63, 55) === 1.0,
    'Subli Paper scales 0.5→1 sheet when order qty 1→2'
);

// Intentional staff customization (1.0 sheet per mug) is preserved, not overwritten to 0.5.
expect_mugs_qty(
    printflow_pos_resolve_scaled_material_quantity(1.0, 1, 4, $subliItem, 63, 55) === 4.0,
    'intentional Subli Actual Usage rate (1 sheet/mug) is preserved on quantity change'
);

// Cart-row helper applies the same Mugs rule and leaves Box Mug proportional.
$assignments = printflow_pos_scale_material_assignment_rows([
    [
        'item_id' => 37,
        'quantity' => 1.0,
        'metadata' => ['material_usage' => ['actual' => 1.0, 'method' => 'manual', 'staff_confirmed' => true]],
    ],
    [
        'item_id' => 55,
        'name' => 'Subli Paper',
        'quantity' => 0.5,
        'metadata' => ['material_usage' => ['actual' => 0.5, 'method' => 'manual', 'staff_confirmed' => true]],
    ],
    [
        'item_id' => 56,
        'name' => 'Box Mug',
        'quantity' => 1.0,
        'metadata' => ['material_usage' => ['actual' => 1.0, 'method' => 'manual', 'staff_confirmed' => true]],
    ],
], 1, 4, 63);
expect_mugs_qty((float)$assignments[0]['quantity'] === 4.0, 'cart Mug assignment scales to 4');
expect_mugs_qty((float)$assignments[1]['quantity'] === 2.0, 'cart Subli Paper assignment scales to 2 sheets');
expect_mugs_qty((float)$assignments[2]['quantity'] === 4.0, 'Box Mug still uses proportional scale when 1:1 at baseline');
expect_mugs_qty(
    abs((float)$assignments[1]['metadata']['material_usage']['actual'] - 2.0) < 0.000001,
    'Subli Paper material_usage.actual tracks the scaled half-sheet assignment'
);

$boxOnly = printflow_pos_scale_material_assignment_rows([
    ['item_id' => 56, 'name' => 'Box Mug', 'quantity' => 1.0],
], 10, 5, 63);
expect_mugs_qty(abs((float)$boxOnly[0]['quantity'] - 0.5) < 0.000001, 'Box Mug remains proportional (not forced to order qty)');

// Other services: fractional whole-unit scale still rejected (not globally suppressed).
$otherRejected = false;
try {
    printflow_pos_resolve_scaled_material_quantity(1.0, 10, 11, $vinylSheets, 99, 18);
} catch (InvalidArgumentException $error) {
    $otherRejected = true;
}
expect_mugs_qty($otherRejected, 'non-Mugs sheet materials still reject non-whole quantity ratios');

$otherOk = printflow_pos_resolve_scaled_material_quantity(6.0, 3, 4, $vinylSheets, 99, 18);
expect_mugs_qty($otherOk === 8.0, 'non-Mugs whole-unit materials still scale when the ratio stays whole');

// Exact fractional inventory deduction contract: ledger stores the saved float qty.
$deductQty = printflow_pos_parse_positive_material_quantity(
    printflow_pos_mugs_canonical_subli_sheets(1),
    $subliItem
);
expect_mugs_qty($deductQty === 0.5, 'ledger-bound Subli deduction qty for 1 mug is exactly 0.5 sheet');
$deductTwo = printflow_pos_parse_positive_material_quantity(
    printflow_pos_mugs_canonical_subli_sheets(2),
    $subliItem
);
expect_mugs_qty($deductTwo === 1.0, 'ledger-bound Subli deduction qty for 2 mugs is exactly 1 sheet');

// Preserve Set Price confirmation + line total while scaling Mugs materials.
$cartItem = [
    'product_id' => 63,
    'service_id' => 63,
    'is_service' => true,
    'qty' => 1,
    'quantity' => 1,
    'price' => 100.0,
    'price_set' => true,
    'line_total_override' => 100.0,
    'design_fee' => 0.0,
    'pos_cart_line_id' => 'mugs-line-stable',
    'customization' => [
        'service_id' => 63,
        'layout' => 'With Layout',
        'pos_design_line_id' => 'mugs-design-stable',
    ],
    'pos_material_assignments' => [
        ['item_id' => 37, 'quantity' => 1.0, 'metadata' => ['material_usage' => ['actual' => 1.0]]],
        ['item_id' => 55, 'name' => 'Subli Paper', 'quantity' => 0.5, 'metadata' => ['material_usage' => ['actual' => 0.5]]],
    ],
];
$fromQty = 1;
$toQty = 4;
$wasPriceSet = true;
$previousOverride = $cartItem['line_total_override'];
$previousFee = (float)$cartItem['design_fee'];
$existingAssignments = $cartItem['pos_material_assignments'];
$cartItem['qty'] = $toQty;
$cartItem['quantity'] = $toQty;
$repriced = printflow_apply_service_line_pricing_to_cart_item($cartItem);
expect_mugs_qty(!empty($repriced['ok']), 'Mugs reprice succeeds after quantity increase');
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
    $toQty,
    63
);
expect_mugs_qty($cartItem['price_set'] === true, 'Mugs quantity increase keeps price_set confirmed');
expect_mugs_qty((float)$cartItem['line_total_override'] === 400.0, 'Mugs confirmed line total scales with qty (100×4)');
expect_mugs_qty((string)$cartItem['pos_cart_line_id'] === 'mugs-line-stable', 'Mugs cart line identity survives quantity change');
expect_mugs_qty((float)$cartItem['pos_material_assignments'][0]['quantity'] === 4.0, 'confirmed Mugs cart keeps Mug assignment at new qty');
expect_mugs_qty((float)$cartItem['pos_material_assignments'][1]['quantity'] === 2.0, 'confirmed Mugs cart keeps Subli at 2 sheets for qty 4');

$fromQty = 4;
$toQty = 2;
$previousOverride = $cartItem['line_total_override'];
$existingAssignments = $cartItem['pos_material_assignments'];
$cartItem['qty'] = $toQty;
$cartItem['quantity'] = $toQty;
printflow_apply_service_line_pricing_to_cart_item($cartItem);
printflow_pos_apply_quantity_change_to_confirmed_service_pricing(
    $cartItem,
    $fromQty,
    $toQty,
    true,
    $previousOverride,
    0.0
);
$cartItem['pos_material_assignments'] = printflow_pos_scale_material_assignment_rows(
    $existingAssignments,
    $fromQty,
    $toQty,
    63
);
expect_mugs_qty($cartItem['price_set'] === true, 'Mugs quantity decrease keeps price_set confirmed');
expect_mugs_qty((float)$cartItem['line_total_override'] === 200.0, 'Mugs confirmed total scales down with qty');
expect_mugs_qty((float)$cartItem['pos_material_assignments'][0]['quantity'] === 2.0, 'Mug pcs match decreased qty');
expect_mugs_qty((float)$cartItem['pos_material_assignments'][1]['quantity'] === 1.0, 'Subli sheets match decreased qty (exactly 1)');

// Stock rejection must name the material and available quantity.
$handlerPath = dirname(__DIR__) . '/staff/api/pos_cart_handler.php';
$handler = (string)file_get_contents($handlerPath);
expect_mugs_qty(str_contains($handler, 'Insufficient ')
    && str_contains($handler, 'Available: ')
    && str_contains($handler, 'Required: '),
    'stock rejection message identifies material availability');
expect_mugs_qty(
    (bool)preg_match(
        '/printflow_pos_sync_job_quantity_and_scale_materials\s*\(\s*\$linkedJobId\s*,\s*\$previousQty[^,]*,\s*\$qty\s*,\s*\$serviceId\s*\)/s',
        $handler
    ),
    'job material sync receives the service id for Mugs-aware scaling'
);
expect_mugs_qty(str_contains($handler, 'printflow_pos_validate_service_stock'),
    'quantity updates still run service stock validation after scaling');

$checkout = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
expect_mugs_qty(str_contains($checkout, 'validatePendingMaterialStockPublic($jobId, $quantity)'),
    'checkout still revalidates materials against the final cart quantity');
expect_mugs_qty(str_contains($checkout, 'pos_checkout_validate_assigned_service_materials'),
    'checkout validates assigned service materials including scaled Mugs usage');

$jobService = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
expect_mugs_qty(
    str_contains($jobService, 'printflow_pos_parse_positive_material_quantity')
    && str_contains($jobService, 'InventoryManager::issueStock'),
    'deduction re-parses saved actual usage then issues that exact quantity'
);

// Simulated insufficient Subli stock after a qty increase (0.5 → 5 sheets for qty 10).
$requiredAfterIncrease = printflow_pos_mugs_canonical_subli_sheets(10);
$availableSubli = 4.0;
expect_mugs_qty($requiredAfterIncrease === 5.0, 'stock check uses scaled Subli demand for new qty');
expect_mugs_qty(
    printflow_pos_service_stock_exceeds_available($requiredAfterIncrease, $availableSubli),
    'increased Subli demand beyond available stock is rejected'
);
$stockMessage = 'Insufficient Subli Paper stock. Available: '
    . rtrim(rtrim(number_format($availableSubli, 3, '.', ''), '0'), '.')
    . ' sheet. Required: '
    . rtrim(rtrim(number_format($requiredAfterIncrease, 3, '.', ''), '0'), '.')
    . ' sheet.';
expect_mugs_qty(
    str_contains($stockMessage, 'Subli Paper')
    && str_contains($stockMessage, 'Available: 4')
    && str_contains($stockMessage, 'Required: 5'),
    'insufficient-stock copy names Subli Paper and available quantity'
);

$customizations = (string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
expect_mugs_qty(
    str_contains($customizations, 'subliFractional')
    && str_contains($customizations, "name === 'subli paper'"),
    'Production Assignment UI allows fractional Subli Paper sheet quantities'
);

echo "POS Mugs quantity scale regression tests passed.\n";
