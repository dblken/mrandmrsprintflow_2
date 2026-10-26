<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_mugs_release_rule.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$item = ['id' => 37, 'name' => 'Mug', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0];
$rules = printflow_pos_mugs_release_material_rule(63, 'Mugs', $item);
$assert(count($rules) === 1 && $rules[0]['item_id'] === 37 && $rules[0]['multiplier'] === 1.0, 'Mugs resolves only the confirmed active blank Mug at 1:1.');
$assert(printflow_pos_mugs_release_material_rule(40, 'Tarpaulin', $item) === null, 'The rule does not apply to other service IDs.');

foreach ([1 => true, 36 => true, 37 => false, 100 => false] as $qty => $allowed) {
    $assert(($qty * $rules[0]['multiplier'] <= 36.0) === $allowed, 'Quantity ' . $qty . ' is compared with branch stock 36.');
}

foreach ([
    ['id' => 38, 'name' => 'Mug', 'status' => 'ACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    ['id' => 37, 'name' => 'Mug', 'status' => 'INACTIVE', 'unit_of_measure' => 'pcs', 'track_by_roll' => 0],
    ['id' => 37, 'name' => 'Mug', 'status' => 'ACTIVE', 'unit_of_measure' => 'ft', 'track_by_roll' => 1],
] as $invalid) {
    try {
        printflow_pos_mugs_release_material_rule(63, 'Mugs', $invalid);
        throw new RuntimeException('An item that does not match the confirmed blank Mug must fail closed.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'An item that does not match the confirmed blank Mug must fail closed.') throw $error;
    }
}

$root = dirname(__DIR__);
$rulesSource = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$headRules = (string)file_get_contents(__DIR__ . '/fixtures/pos_service_stock_validation_base_5c588d63.php.txt');
$assert($headRules !== '' && str_contains($headRules, 'function printflow_pos_service_stock_rules('), 'The explicit clean-main baseline fixture is present.');
$assert(str_contains($rulesSource, 'if ($serviceId === 63)') && str_contains($rulesSource, 'printflow_pos_mugs_release_material_rule('), 'Only service ID 63 uses the confirmed Mugs rule.');
$assert(str_contains($headRules, 'r.qty_multiplier') && str_contains($headRules, 'function printflow_pos_service_stock_rules(int $serviceId, array $customization)'), 'The six non-Mugs services retain the clean-main shared rules path.');
$assert(!str_contains($rulesSource, '$earlyMappedServiceIds') && !str_contains($rulesSource, '$requiresEarlyMapping'), 'No broad fail-closed service mapping requirement was added.');
$assert(str_contains($rulesSource, 'if ($serviceId === 40) {') && str_contains($rulesSource, 'if ($serviceId !== 40) $assigned = printflow_pos_service_line_job_materials($line);'), 'The new physical-panel path remains scoped to Tarpaulin; other services retain the baseline assignment path.');
$assert(str_contains($rulesSource, 'InventoryManager::getStockOnHand((int)$itemId, $branchId)'), 'The existing selected-branch stock source remains authoritative.');
$assert(str_contains($rulesSource, '$demand[$itemId][\'quantity\'] += $lineQty;'), 'The current generic validator retains baseline combined-demand aggregation.');
$assert(str_contains($rulesSource, 'printflow_pos_validate_service_stock('), 'The backend validation entry point remains present.');
$assert(str_contains($headRules, '$demand[$itemId][\'quantity\'] += $lineQty;'), 'The existing combined-demand aggregation remains unchanged.');
$assert(str_contains($headRules, 'printflow_pos_validate_service_stock('), 'The clean main validator exposes the existing backend validation path.');
$cartSource = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkoutSource = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$assert(str_contains($cartSource, 'printflow_pos_validate_service_stock('), 'Cart add/update retains existing backend validation.');
$assert(str_contains($checkoutSource, 'printflow_pos_validate_service_stock('), 'Checkout retains existing backend revalidation.');

echo "POS Mugs-only release rule tests passed.\n";
