<?php
declare(strict_types=1);

// Load pure validation helpers without requiring a configured local database.
require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$mug = [
    'id' => 37,
    'name' => 'Mug',
    'status' => 'ACTIVE',
    'unit_of_measure' => 'pcs',
    'track_by_roll' => 0,
];
$rules = printflow_pos_confirmed_mugs_material_rule(63, 'Mugs', $mug);
$assert(is_array($rules) && count($rules) === 1, 'Mugs has exactly the confirmed blank-Mug rule.');
$assert((int)$rules[0]['item_id'] === 37 && (float)$rules[0]['multiplier'] === 1.0, 'One Mug requires one unit of item 37.');
$assert(printflow_pos_confirmed_mugs_material_rule(40, 'Tarpaulin', $mug) === null, 'The Mugs rule does not leak to another service.');

foreach ([1 => true, 36 => true, 100 => false] as $quantity => $shouldFit) {
    $required = printflow_pos_service_required_material_quantity($quantity, $rules[0]['multiplier']);
    $fits = !printflow_pos_service_stock_exceeds_available($required, 36.0);
    $assert($fits === $shouldFit, 'Quantity ' . $quantity . ' is checked against mocked branch stock 36.');
}

$combined = [];
printflow_pos_service_stock_add_demand($combined, 37, 20.0, 'Mug', 'pcs');
printflow_pos_service_stock_add_demand($combined, 37, 16.0, 'Mug', 'pcs');
$assert($combined[37]['quantity'] === 36.0, 'Combined Mug demand aggregates across cart lines.');
$assert(!printflow_pos_service_stock_exceeds_available($combined[37]['quantity'], 36.0), 'Combined demand equal to stock passes.');
printflow_pos_service_stock_add_demand($combined, 37, 1.0, 'Mug', 'pcs');
$assert(printflow_pos_service_stock_exceeds_available($combined[37]['quantity'], 36.0), 'Combined demand beyond stock fails.');

foreach ([
    ['id' => 38, 'name' => 'Mug', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    ['id' => 37, 'name' => 'Mug', 'status' => 'INACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    ['id' => 37, 'name' => 'Mug', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft', 'track_by_roll' => 1],
] as $invalidItem) {
    try {
        printflow_pos_confirmed_mugs_material_rule(63, 'Mugs', $invalidItem);
        throw new RuntimeException('An invalid Mug inventory item must fail closed.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'An invalid Mug inventory item must fail closed.') throw $error;
    }
}

$root = dirname(__DIR__);
$validationSource = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cartSource = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkoutSource = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$assert(str_contains($validationSource, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'), 'Stock is read through the existing branch-scoped inventory source.');
$assert(str_contains($cartSource, 'printflow_pos_validate_service_stock('), 'POS cart operations use backend service stock validation.');
$assert(str_contains($checkoutSource, 'printflow_pos_validate_service_stock('), 'Checkout revalidates service stock on the backend.');

echo "POS confirmed Mugs stock rule tests passed.\n";
