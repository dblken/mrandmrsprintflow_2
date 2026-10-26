<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$validator = file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cart = file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$jobApi = file_get_contents($root . '/admin/job_orders_api.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($validator, "if ($" . "serviceId === 63)") && str_contains($validator, 'printflow_pos_mugs_release_material_rule'), 'Deployed Mugs service branch remains present.');
$assert(str_contains($validator, "if ($" . "serviceId === 40) return [];") && str_contains($validator, 'printflow_pos_tarpaulin_selection'), 'The new validator branch is scoped to Tarpaulin service 40.');
$assert(!str_contains($validator, 'earlyMappedServiceIds') && !str_contains($validator, 'requiresEarlyMapping'), 'No broad fail-closed service list was imported.');
$assert(!str_contains($validator, '$hasMultiplierColumn')
    && !str_contains($validator, 'No default consumption quantity can be assumed.')
    && str_contains($validator, 'SELECT r.item_id, r.rule_type, r.qty_multiplier'), 'Tarpaulin-only validation adds no generic missing-column guard and preserves baseline generic rule-query behavior.');
$tarpGuard = strpos($validator, 'if ($serviceId === 40) return [];');
$genericRuleQuery = strpos($validator, 'SELECT r.item_id, r.rule_type, r.qty_multiplier');
$assert($tarpGuard !== false && $genericRuleQuery !== false && $tarpGuard < $genericRuleQuery, 'Tarpaulin exits before the legacy generic multiplier query.');
foreach ([36, 39, 64, 67, 71] as $serviceId) {
    $assert(!str_contains($validator, "serviceId === $serviceId") && !str_contains($validator, "serviceId, $serviceId"), "Service $serviceId has no new special-case validation.");
}
$assert(str_contains($validator, 'printflow_pos_tarpaulin_open_rolls((int)$row[\'id\'], $branchId)')
    && str_contains($validator, 'no schema changes were attempted'), 'Tarpaulin availability uses branch-scoped open rolls without schema side effects.');
$assert(str_contains($validator, 'printflow_tarpaulin_panel_plan') && str_contains($validator, 'printflow_tarpaulin_allocate_whole_panels'), 'Tarpaulin demand uses the shared panel plan and physical-roll allocator.');
$assert(str_contains($validator, 'printflow_tarpaulin_allocate_cart_panels($tarpaulinPlans, $rollsByItem)')
    && !str_contains($validator, '$tarpaulinUsedByItemRoll[$itemId][$rollId]'), 'Combined Tarpaulin demand is allocated cart-wide instead of consuming rolls in cart order.');
$assert(str_contains($cart, 'pos_cart_new_tarpaulin_line_id') && str_contains($cart, "pos_tarpaulin_selection'] ="), 'Cart line IDs and server-resolved selections are stored.');
$assert(str_contains($cart, "unset(\$item['customization']['pos_tarpaulin_selection'])")
    && str_contains($cart, "unset(\$_SESSION['pos_cart'][\$index]['customization']['pos_tarpaulin_selection'])"), 'Tarpaulin cart quantity and material-price changes clear stale plans before server revalidation.');
$assert(str_contains($cart, "unset(\$incomingCustomization['pos_tarpaulin_selection'], \$incomingCustomization['pos_cart_line_id'])") && str_contains($cart, "pos_cart_service_validation_branch_id(\$candidateCart, 40)"), 'Tarpaulin price/customization cart edits cannot replace the server selection or skip revalidation.');
$assert(str_contains($validator, 'Tarpaulin service identity changed after stock validation.'), 'A client cannot relabel Tarpaulin cart line identity to evade validation.');
$assert(str_contains($checkout, 'pos_checkout_pair_tarpaulin_cart_lines') && str_contains($checkout, 'pos_cart_line_id'), 'Checkout binds Tarpaulin entries one-to-one to the server cart.');
$assert(strpos($checkout, 'pos_checkout_pair_tarpaulin_cart_lines($items') < strpos($checkout, 'printflow_pos_validate_service_stock($items, $checkout_branch_id)'), 'Checkout pairs lines before stock revalidation.');
$assert(str_contains($checkout, 'pos_checkout_contains_tarpaulin($items) || pos_checkout_contains_tarpaulin($sessionCart)'), 'A client cannot bypass validation by omitting a Tarpaulin line from the payload.');
$jobService = file_get_contents($root . '/includes/JobOrderService.php');
$assert(str_contains($jobService, 'RollService::deductWholePanelsFIFO') && str_contains($jobService, 'tarpaulin_panel_plan'), 'Only marked v2 Tarpaulin assignments use whole-panel completion deductions.');
$rollService = file_get_contents($root . '/includes/RollService.php');
$assert(str_contains($rollService, 'printflow_tarpaulin_allocate_whole_panels($rolls, $plan)')
    && str_contains($validator, 'printflow_tarpaulin_allocate_cart_panels'), 'Completion revalidates locked rolls through the same whole-panel feasibility allocator used by POS.');
$assert(str_contains($jobService, 'printflow_tarpaulin_panel_plans_match') && str_contains($jobApi, 'printflow_tarpaulin_panel_plans_match'), 'Set Price and completion reject plans that differ from the server-persisted selection.');
$assert(str_contains($validator, 'The POS service material rules could not be loaded.'), 'Existing generic query-failure handling remains unchanged.');
$retryBlock = strpos($checkout, 'if ($isPayMongo && !empty($sessionContext[\'pos_paymongo_checkouts\'][$checkoutToken]))');
$retryValidate = strpos($checkout, 'Tarpaulin stock changed or could not be verified. Revalidate the cart before retrying QR Ph.');
$retryPayment = strpos($checkout, "printflow_provider_payment_create_qrph(", $retryBlock === false ? 0 : $retryBlock);
$assert($retryBlock !== false && $retryValidate !== false && $retryPayment !== false && $retryBlock < $retryValidate && $retryValidate < $retryPayment, 'A QR Ph retry revalidates Tarpaulin stock before reusing payment flow.');
echo "Tarpaulin release isolation assertions passed.\n";
