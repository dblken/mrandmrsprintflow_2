<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$validator = file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cart = file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($validator, "if ($" . "serviceId === 63)") && str_contains($validator, 'printflow_pos_mugs_release_material_rule'), 'Deployed Mugs service branch remains present.');
$assert(str_contains($validator, "if ($" . "serviceId === 40) return [];") && str_contains($validator, 'printflow_pos_tarpaulin_selection'), 'The new validator branch is scoped to Tarpaulin service 40.');
$assert(!str_contains($validator, 'earlyMappedServiceIds') && !str_contains($validator, 'requiresEarlyMapping'), 'No broad fail-closed service list was imported.');
$assert(str_contains($validator, 'SELECT r.item_id, r.rule_type, r.qty_multiplier'), 'The baseline generic rule query remains unchanged; no new multiplier schema dependency was introduced.');
$tarpGuard = strpos($validator, 'if ($serviceId === 40) return [];');
$genericRuleQuery = strpos($validator, 'SELECT r.item_id, r.rule_type, r.qty_multiplier');
$assert($tarpGuard !== false && $genericRuleQuery !== false && $tarpGuard < $genericRuleQuery, 'Tarpaulin exits before the legacy generic multiplier query.');
foreach ([36, 39, 64, 67, 71] as $serviceId) {
    $assert(!str_contains($validator, "serviceId === $serviceId") && !str_contains($validator, "serviceId, $serviceId"), "Service $serviceId has no new special-case validation.");
}
$assert(str_contains($validator, 'InventoryManager::getStockOnHand((int)$row[\'id\'], $branchId)'), 'Tarpaulin availability uses branch-scoped stock.');
$assert(str_contains($validator, 'printflow_tarpaulin_calculate_linear_length'), 'Tarpaulin demand uses the shared linear-foot calculator.');
$assert(str_contains($cart, 'pos_cart_new_tarpaulin_line_id') && str_contains($cart, "pos_tarpaulin_selection'] ="), 'Cart line IDs and server-resolved selections are stored.');
$assert(str_contains($cart, "unset(\$incomingCustomization['pos_tarpaulin_selection'], \$incomingCustomization['pos_cart_line_id'])") && str_contains($cart, "pos_cart_service_validation_branch_id(\$candidateCart, 40)"), 'Tarpaulin price/customization cart edits cannot replace the server selection or skip revalidation.');
$assert(str_contains($validator, 'Tarpaulin service identity changed after stock validation.'), 'A client cannot relabel Tarpaulin cart line identity to evade validation.');
$assert(str_contains($checkout, 'pos_checkout_pair_tarpaulin_cart_lines') && str_contains($checkout, 'pos_cart_line_id'), 'Checkout binds Tarpaulin entries one-to-one to the server cart.');
$assert(strpos($checkout, 'pos_checkout_pair_tarpaulin_cart_lines($items') < strpos($checkout, 'printflow_pos_validate_service_stock($items, $checkout_branch_id)'), 'Checkout pairs lines before stock revalidation.');
$assert(str_contains($checkout, 'pos_checkout_contains_tarpaulin($items) || pos_checkout_contains_tarpaulin($sessionCart)'), 'A client cannot bypass validation by omitting a Tarpaulin line from the payload.');
$retryBlock = strpos($checkout, 'if ($isPayMongo && !empty($sessionContext[\'pos_paymongo_checkouts\'][$checkoutToken]))');
$retryValidate = strpos($checkout, 'Tarpaulin stock changed or could not be verified. Revalidate the cart before retrying QR Ph.');
$retryPayment = strpos($checkout, "printflow_provider_payment_create_qrph(", $retryBlock === false ? 0 : $retryBlock);
$assert($retryBlock !== false && $retryValidate !== false && $retryPayment !== false && $retryBlock < $retryValidate && $retryValidate < $retryPayment, 'A QR Ph retry revalidates Tarpaulin stock before reusing payment flow.');
echo "Tarpaulin release isolation assertions passed.\n";
