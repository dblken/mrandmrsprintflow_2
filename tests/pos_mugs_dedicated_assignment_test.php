<?php
declare(strict_types=1);

/**
 * Mugs Production Assignment / Confirm Set Price must require dedicated Mug #37
 * among assigned rows, while allowing shared mapped materials (Subli Paper,
 * Box Mug) that staff actually used. The previous inverted check rejected any
 * non-#37 row and blocked valid Mug + Subli Paper + Box Mug assignments.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/pos_mugs_release_rule.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$passes = static function (array $assigned) use ($assert): void {
    printflow_pos_mugs_assert_dedicated_mug_assigned($assigned);
    $assert(true, 'assignment passed');
};

$fails = static function (array $assigned, string $label) use ($assert): void {
    try {
        printflow_pos_mugs_assert_dedicated_mug_assigned($assigned);
        throw new RuntimeException($label . ' unexpectedly passed without dedicated Mug #37.');
    } catch (RuntimeException $error) {
        $assert(
            str_contains($error->getMessage(), 'The dedicated Mug material must be assigned for this service.'),
            $label . ' must use the dedicated Mug error message.'
        );
    }
};

// Exact reported case: Mug + Subli Paper + Box Mug with valid usage rows.
$passes([
    ['item_id' => 37, 'quantity' => 4, 'name' => 'Mug'],
    ['item_id' => 55, 'quantity' => 4, 'name' => 'Subli Paper'],
    ['item_id' => 56, 'quantity' => 4, 'name' => 'Box Mug'],
]);

// Dedicated Mug alone remains valid.
$passes([
    ['item_id' => 37, 'quantity' => 1, 'name' => 'Mug'],
]);

// Shared materials without Mug still fail.
$fails([
    ['item_id' => 55, 'quantity' => 4, 'name' => 'Subli Paper'],
    ['item_id' => 56, 'quantity' => 4, 'name' => 'Box Mug'],
], 'Subli Paper + Box Mug only');

$fails([], 'empty assignment list');

$fails([
    ['item_id' => 55, 'quantity' => 1, 'name' => 'Subli Paper'],
], 'Subli Paper alone (shared, not a Mug substitute)');

// String IDs from JSON payloads still recognize dedicated Mug.
$passes([
    ['item_id' => '37', 'quantity' => '4'],
    ['item_id' => '55', 'quantity' => '4'],
]);

// Validator wiring: Confirm Set Price / validate_service_materials uses the helper.
$validator = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$assert(
    str_contains($validator, 'printflow_pos_mugs_assert_dedicated_mug_assigned($assigned)'),
    'POS stock validation calls the dedicated-Mug presence helper for service 63.'
);
$assert(
    !preg_match('/foreach \(\$assigned as \$material\) \{\s*if \(\(int\)\$material\[\'item_id\'\] !== 37\)/', $validator),
    'Inverted non-#37 rejection loop is removed.'
);

$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$assert(
    str_contains($cart, "case 'validate_service_materials':")
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Confirm Set Price still revalidates materials through printflow_pos_validate_service_stock.'
);

$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$assert(
    str_contains($customizations, "action: 'validate_service_materials'")
    && str_contains($customizations, 'Confirm Set Price'),
    'Confirm Set Price UI still posts validate_service_materials before completing.'
);

// Other services' alternative-group rules remain unchanged.
require_once $root . '/includes/pos_material_selection.php';
$assert(printflow_production_assignment_selection_errors(67, [212, 211]) !== [], 'Brochure alternative conflict still rejected.');
$assert(printflow_production_assignment_selection_errors(40, [13, 14]) !== [], 'Tarpaulin roll alternative conflict still rejected.');
$assert(printflow_production_assignment_selection_errors(63, [37, 55, 56]) === [], 'Mugs stays outside shared alternative-group rejection for Mug + shared materials.');

echo "Mugs dedicated assignment regression tests passed.\n";
