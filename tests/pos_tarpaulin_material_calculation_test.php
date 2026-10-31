<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$calc = static fn($w, $h, $q, $r) => printflow_tarpaulin_calculate_linear_length($w, $h, $q, $r);

$assert($calc(3, 5, 1, 3)['required_linear_ft'] === 5.0, '3x5 on 3FT uses 5 linear feet.');
$assert($calc(3, 5, 1, 5)['required_linear_ft'] === 3.0, '3x5 on 5FT rotates and uses 3 linear feet.');
$assert($calc(3, 5, 2, 5)['required_linear_ft'] === 6.0, 'Finished quantity multiplies length exactly once.');
$assert($calc(4, 6, 1, 4)['required_linear_ft'] === 6.0, '4x6 on 4FT uses 6 linear feet.');
$assert($calc(4, 6, 1, 6)['required_linear_ft'] === 4.0, '4x6 on 6FT rotates and uses 4 linear feet.');
$assert($calc(3, 5, 1, 5)['print_area_sqft'] === 15.0, 'Print area is reported separately in square feet.');

<<<<<<< HEAD
foreach ([[0, 5, 1, 5], [3, null, 1, 5], [3, 5, 0, 5], [3, 5, 1, 0]] as $invalid) {
=======
foreach ([[0, 5, 1, 5], [null, 5, 1, 5], [3, null, 1, 5], [3, 5, 0, 5], [3, 5, 1, 0]] as $invalid) {
>>>>>>> ae7544cddefcae1f0a01208cf60d4e9786fd9b78
    try {
        $calc(...$invalid);
        throw new RuntimeException('Missing, zero, or invalid dimensions and quantity must be rejected.');
    } catch (InvalidArgumentException $expected) {
        // Expected fail-closed behavior.
    }
}
try {
    $calc(3, 5, 1, 2);
    throw new RuntimeException('A roll too narrow for either orientation must be rejected.');
} catch (InvalidArgumentException $expected) {
}

$roll = static fn(int $id, string $name, float $available, int $branch = 1): array => [
    'id' => $id, 'name' => $name, 'status' => 'ACTIVE', 'track_by_roll' => 1,
    'available_ft' => $available, 'branch_id' => $branch,
];
$selection = printflow_tarpaulin_resolve_candidate([
    $roll(15, '5FT Tarpaulin', 6),
    $roll(16, '6FT Tarpaulin', 20),
    $roll(14, '4FT Tarpaulin', 20),
], 3, 5, 2);
$assert($selection['ok'] && $selection['selection']['item_id'] === 15, 'The picker preference chooses the smallest sufficient roll fitting the larger dimension.');
$assert($selection['selection']['required_linear_ft'] === 6.0, 'The selected roll demand matches orientation and quantity.');
<<<<<<< HEAD
=======
$twoByTwo = printflow_tarpaulin_resolve_candidate([$roll(18, '5FT Tarpaulin', 2)], 2, 2, 1);
$assert($twoByTwo['ok'] && $twoByTwo['selection']['required_linear_ft'] === 2.0,
    'A valid 2x2 ft, quantity 1 request passes when the selected branch has sufficient 5FT roll stock.');
$threeByFive = printflow_tarpaulin_resolve_candidate([$roll(19, '5FT Tarpaulin', 3)], 3, 5, 1);
$assert($threeByFive['ok'] && $threeByFive['selection']['required_linear_ft'] === 3.0,
    'A valid 3x5 ft, quantity 1 request uses the approved 5FT rotation and requires 3 linear feet.');
>>>>>>> ae7544cddefcae1f0a01208cf60d4e9786fd9b78
$short = printflow_tarpaulin_resolve_candidate([$roll(15, '5FT Tarpaulin', 5)], 3, 5, 2);
$assert(!$short['ok'] && $short['reason'] === 'insufficient', 'Insufficient branch roll stock fails validation.');
$assert(($short['shortage']['id'] ?? 0) === 15
    && ($short['shortage']['required_ft'] ?? 0) === 6.0
    && ($short['shortage']['available_ft'] ?? -1) === 5.0,
    'An insufficient resolver result retains the item and exact required/available feet for fail-closed aggregation.');
$isolated = printflow_tarpaulin_resolve_candidate([$roll(15, '5FT Tarpaulin', 20, 2)], 3, 5, 2);
$assert($isolated['ok'] && $isolated['selection']['branch_id'] === 2, 'The resolver carries the stock branch used for selection.');
$line = static function (string $id, float $width, float $height, int $qty, int $rollId): array {
    $selection = ['item_id' => $rollId, 'branch_id' => 2, 'width_ft' => $width, 'height_ft' => $height, 'required_linear_ft' => $height * $qty];
    return ['product_id' => 40, 'is_service' => true, 'qty' => $qty, 'pos_cart_line_id' => $id,
        'customization' => ['service_id' => 40, 'width' => $width, 'height' => $height, 'pos_tarpaulin_selection' => $selection]];
};
$twoServerLines = [$line('server-a', 3, 5, 1, 15), $line('server-b', 3, 5, 1, 16)];
$twoCheckoutLines = [
    ['id' => 40, 'is_service' => true, 'qty' => 1, 'pos_cart_line_id' => 'server-b', 'customization' => ['service_id' => 40, 'width' => 3, 'height' => 5, 'pos_tarpaulin_selection' => ['item_id' => 999]]],
    ['id' => 40, 'is_service' => true, 'qty' => 1, 'pos_cart_line_id' => 'server-a', 'customization' => ['service_id' => 40, 'width' => 3, 'height' => 5]],
];
$paired = printflow_tarpaulin_pair_cart_lines($twoCheckoutLines, $twoServerLines, 2);
$assert($paired['ok'] && $paired['items'][0]['customization']['pos_tarpaulin_selection']['item_id'] === 16
    && $paired['items'][1]['customization']['pos_tarpaulin_selection']['item_id'] === 15,
    'Identical service lines retain their own server-side selection after checkout reordering; client material data is replaced.');
$sameRollLines = [$line('same-a', 3, 5, 1, 15), $line('same-b', 3, 5, 1, 15)];
$sameRollItems = [
    ['id' => 40, 'is_service' => true, 'qty' => 1, 'pos_cart_line_id' => 'same-a', 'customization' => ['service_id' => 40, 'width' => 3, 'height' => 5]],
    ['id' => 40, 'is_service' => true, 'qty' => 1, 'pos_cart_line_id' => 'same-b', 'customization' => ['service_id' => 40, 'width' => 3, 'height' => 5]],
];
$assert(printflow_tarpaulin_pair_cart_lines($sameRollItems, $sameRollLines, 2)['ok'], 'Distinct cart IDs support identical dimensions and the same roll without line reuse.');
$duplicateIdLines = [$line('duplicate', 3, 5, 1, 15), $line('duplicate', 3, 5, 1, 15)];
$assert(!printflow_tarpaulin_pair_cart_lines($sameRollItems, $duplicateIdLines, 2)['ok'], 'Duplicate server identifiers fail safely.');
$removedLine = printflow_tarpaulin_pair_cart_lines([$sameRollItems[0]], $sameRollLines, 2);
$assert(!$removedLine['ok'], 'A checkout payload missing a removed/reordered cart line fails instead of borrowing another line.');
$forgedId = $sameRollItems;
$forgedId[0]['pos_cart_line_id'] = 'forged';
$assert(!printflow_tarpaulin_pair_cart_lines($forgedId, $sameRollLines, 2)['ok'], 'A forged line identifier is rejected.');
<<<<<<< HEAD
=======
$wrongProduct = $sameRollItems;
$wrongProduct[0]['id'] = 64;
$assert(!printflow_tarpaulin_pair_cart_lines($wrongProduct, $sameRollLines, 2)['ok'], 'A Tarpaulin line identifier cannot be reused for a different service.');
>>>>>>> ae7544cddefcae1f0a01208cf60d4e9786fd9b78
$combined = printflow_tarpaulin_resolve_candidate([$roll(15, '5FT Tarpaulin', 10)], 3, 5, 1, [15 => 8]);
$assert(!$combined['ok'] && $combined['reason'] === 'insufficient', 'Existing cart demand is subtracted from available roll feet.');
$firstLine = printflow_tarpaulin_resolve_candidate([$roll(15, '5FT Tarpaulin', 5)], 3, 5, 1);
$secondLine = printflow_tarpaulin_resolve_candidate([$roll(15, '5FT Tarpaulin', 5)], 3, 5, 1, [15 => $firstLine['selection']['required_linear_ft']]);
$assert($firstLine['ok'] && !$secondLine['ok'] && $secondLine['shortage']['required_ft'] === 3.0,
    'Two cart lines aggregate by actual roll item ID before accepting the second line.');
$ambiguous = printflow_tarpaulin_resolve_candidate([
    $roll(15, '5FT Tarpaulin', 20), $roll(17, 'TARPAULIN 5FT', 20),
], 3, 5, 1);
$assert(!$ambiguous['ok'] && $ambiguous['reason'] === 'ambiguous', 'Equally ranked roll materials are not chosen arbitrarily.');

$root = dirname(__DIR__);
$validation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$jobService = (string)file_get_contents($root . '/includes/JobOrderService.php');
$jobApi = (string)file_get_contents($root . '/admin/job_orders_api.php');
<<<<<<< HEAD
$assert(str_contains($validation, 'InventoryManager::getStockOnHand((int)$row[\'id\'], $branchId)'), 'Availability uses branch-scoped open-roll stock.');
=======
$assert(str_contains($validation, 'printflow_pos_tarpaulin_open_rolls((int)$row[\'id\'], $branchId)'), 'Availability uses open rolls scoped to the selected branch without schema side effects.');
>>>>>>> ae7544cddefcae1f0a01208cf60d4e9786fd9b78
$assert(str_contains($validation, 'tarpaulin_selections') && str_contains($cart, "['pos_tarpaulin_selection'] = \$selection"), 'The server stores its resolved selection in the POS session cart.');
$assert(str_contains($checkout, 'pos_checkout_pair_tarpaulin_cart_lines') && str_contains($checkout, 'pos_cart_line_id'), 'Checkout uses unique server-generated cart line IDs.');
$assert(strpos($checkout, 'pos_checkout_pair_tarpaulin_cart_lines($items') < strpos($checkout, 'printflow_pos_validate_service_stock($items, $checkout_branch_id)'), 'Checkout pairs lines before revalidating combined branch stock.');
$assert(str_contains($checkout, 'pos_checkout_parse_positive_quantity($item[\'qty\']') && str_contains($checkout, 'printflow_pos_authorized_branch_id'), 'Checkout quantity and authorized cart branch are validated server-side.');
<<<<<<< HEAD
$assert(str_contains($customizations, 'persistedPosTarpaulinSelection') && str_contains($customizations, 'linear_ft_v1'), 'Set Price reuses the saved selection and calculation marker.');
$assert(str_contains($customizations, 'isVersionedTarpaulinMaterial(itemId)') && str_contains($customizations, 'return serviceId === 40;'), 'Set Price creates the new calculation marker only for linked Tarpaulin service 40.');
$assert(str_contains($jobApi, 'printflow_tarpaulin_calculate_linear_length('), 'Set Price recalculates from server-side job dimensions and selected item.');
=======
$assert(str_contains($customizations, 'persistedPosTarpaulinSelection') && str_contains($customizations, 'panel_joining_v2'), 'Set Price reuses the saved panel plan and calculation marker.');
$assert(str_contains($customizations, 'isVersionedTarpaulinMaterial(itemId)') && str_contains($customizations, 'return serviceId === 40;'), 'Set Price creates the new calculation marker only for linked Tarpaulin service 40.');
$assert(str_contains($jobApi, 'printflow_tarpaulin_saved_panel_selection_for_job('), 'Set Price preserves the server-saved panel plan and selected item.');
>>>>>>> ae7544cddefcae1f0a01208cf60d4e9786fd9b78
$assert(str_contains($jobService, 'tarpaulin_calculation_version') && str_contains($jobService, 'legacy manual-assignment heuristic away'), 'New versioned assignments bypass legacy heuristics while legacy metadata remains on the old path.');
$assert(str_contains($jobApi, 'printflow_tarpaulin_assert_versioned_job') && str_contains($jobService, 'printflow_tarpaulin_assert_versioned_job'), 'The material-assignment API and deduction service require persisted Tarpaulin job identity before accepting the marker.');

echo "POS Tarpaulin material calculation tests passed.\n";
