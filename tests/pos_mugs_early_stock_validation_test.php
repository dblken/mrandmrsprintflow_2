<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_mugs_release_rule.php';

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$mug = [
    'id' => 37,
    'name' => 'Mug',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'pcs',
    'track_by_roll' => 0,
];
$rules = printflow_pos_mugs_release_material_rule(63, 'Mugs', $mug);
$expect(is_array($rules) && count($rules) === 1, 'Mugs maps to exactly one release rule');
$expect((int)$rules[0]['item_id'] === 37, 'Authoritative Mug inventory item is #37');
$expect((float)$rules[0]['multiplier'] === 1.0, 'Consumption rule is one Mug per service quantity');
$expect(strtolower((string)$rules[0]['uom']) === 'pcs', 'Mug inventory UOM is pcs');

$root = dirname(__DIR__);
$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$helpers = (string)file_get_contents($root . '/includes/pos_service_stock_helpers.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');

$expect(str_contains($validation, 'printflow_pos_mugs_release_material_rule('), 'Validator uses the owner-confirmed Mugs release rule');
$expect(str_contains($validation, 'InventoryManager::getItem(37)'), 'Early Mugs rule resolves inventory item 37, not a name search');
$expect(
    (bool)preg_match('/if \(\$serviceId === 63\) \{\s*\$rules = printflow_pos_service_stock_rules\(/s', $validation),
    'Early Mug demand runs before Add to Order when assignments are absent'
);
$expect(
    str_contains($validation, "if (\$serviceId !== 63) {\n        return [];")
        || str_contains($validation, 'if ($serviceId !== 63)'),
    'Early mapped demand stays scoped to Mugs only'
);
$expect(str_contains($validation, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'), 'Branch stock comes from InventoryManager::getStockOnHand');
$expect(str_contains($helpers, '$demand[$itemId][\'quantity\'] += $quantity;'), 'Combined cart demand aggregates Mug usage');

$expect(str_contains($cart, "case 'validate_service_stock':"), 'Form open/qty edits call validate_service_stock');
$expect(str_contains($cart, "case 'add':"), 'Add to Order rechecks through the cart handler');
$expect(
    str_contains($cart, "in_array(\$action, ['add', 'update', 'update_service_link', 'validate_stock'], true)")
        && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Server rejects insufficient Mug stock on add/update after the form opens'
);

$expect(str_contains($pos, 'Available ${mugMaterial.name || \'Mug\'}'), 'Form shows available Mug quantity');
$expect(str_contains($pos, 'Number(serviceId) === 63 && !validation.tracked'), 'Unknown Mug stock is not treated as unlimited');
$expect(str_contains($pos, 'input.dataset.posMugAvailable'), 'Available Mug stock is retained for quantity limits');
$expect(str_contains($pos, 'qty > maxAllowed'), 'Quantity above available Mug stock blocks Add to Order');
$expect(str_contains($pos, 'Number(serviceId) !== 63'), 'Incomplete-form deferral still skips stock for non-Mugs services');

// Pure quantity/stock math against the confirmed 1:1 rule.
foreach ([
    [0.0, 1, true],
    [0.0, 100, true],
    [36.0, 36, false],
    [36.0, 37, true],
    [36.0, 100, true],
    [100.0, 100, false],
] as [$available, $qty, $shouldExceed]) {
    $required = printflow_pos_service_required_material_quantity($qty, $rules[0]['multiplier']);
    $exceeds = printflow_pos_service_stock_exceeds_available($required, $available);
    $expect($exceeds === $shouldExceed, "qty {$qty} vs stock {$available} exceed=" . ($shouldExceed ? 'yes' : 'no'));
}

$combined = [];
printflow_pos_service_stock_add_demand($combined, 37, 20.0, 'Mug', 'pcs');
printflow_pos_service_stock_add_demand($combined, 37, 17.0, 'Mug', 'pcs');
$expect($combined[37]['quantity'] === 37.0, 'Later cart lines accumulate Mug demand for the same branch item');
$expect(printflow_pos_service_stock_exceeds_available($combined[37]['quantity'], 36.0), 'Stock that changed after the form opened fails once cart demand exceeds availability');

if ($failures !== []) {
    fwrite(STDERR, "pos_mugs_early_stock_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_mugs_early_stock_validation_test passed.\n";
