<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$groupPage = file_get_contents($root . '/customer/product_group.php');
$cartApi = file_get_contents($root . '/customer/api_cart.php');
if ($groupPage === false || $cartApi === false) {
    throw new RuntimeException('Unable to read product group sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($groupPage, 'printflow_get_branch_product_stock') !== false, 'group page loads branch variant stock');
$assert(strpos($groupPage, 'PF_GROUP_PRODUCT_STOCK') !== false, 'group page exposes variant stock to JS');
$assert(strpos($groupPage, 'id="pf-group-variant-options"') !== false, 'group page renders size picker container');
$assert(strpos($groupPage, 'id="pf-group-qty"') !== false, 'group page renders quantity control');
$assert(strpos($groupPage, 'customization: customization') !== false, 'group cart requests send customization payload');

$assert(strpos($cartApi, 'function cart_key(int $product_id, ?int $variant_id, array $customization = [])') !== false, 'cart keys include customization');
$assert(strpos($cartApi, "printflow_product_option_stock_validate(\$product_id, \$branch_id, \$customization") !== false, 'cart add validates option stock');
$assert(strpos($cartApi, "'customization'=> \$customization") !== false, 'cart add stores customization on line items');

echo "OK product_group_variant_quantity_test\n";
