<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_branch_scope_policy.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(printflow_pos_branch_from_authorized_context(['selected_branch_id' => 2, 'allowed_branches' => 'all']) === 2,
    'An Admin-selected branch wins over the session default branch.');
$assert(printflow_pos_branch_from_authorized_context(['selected_branch_id' => 3, 'allowed_branches' => [3]]) === 3,
    'Counter Staff remains bound to its assigned branch.');
try {
    printflow_pos_branch_from_authorized_context(['selected_branch_id' => 2, 'allowed_branches' => [1]]);
    throw new RuntimeException('An unauthorized branch must be rejected.');
} catch (RuntimeException $expected) {
    $assert(str_contains($expected->getMessage(), 'not authorized'), 'Unauthorized branch selection is explicitly rejected.');
}

$_SESSION = ['pos_cart' => [['product_id' => 40]], 'pos_cart_branch_id' => 1];
try {
    printflow_pos_assert_cart_branch(2);
    throw new RuntimeException('A branch change with an open cart must be rejected.');
} catch (RuntimeException $expected) {
    $assert(str_contains($expected->getMessage(), 'branch changed'), 'Branch changes cannot silently reuse the old cart.');
}
$_SESSION = ['pos_cart' => [], 'pos_cart_branch_id' => 1];
printflow_pos_assert_cart_branch(2);
$assert((int)$_SESSION['pos_cart_branch_id'] === 2, 'An empty cart can adopt the newly selected authorized branch.');

$checkoutSource = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
$cartSource = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$assert(str_contains($checkoutSource, "'branch_id' => \$branchId")
    && str_contains($checkoutSource, '$branch_id = $posBranchId')
    && str_contains($checkoutSource, '$checkout_branch_id = (int)$sessionContext[\'branch_id\']'),
    'Pending and final orders use the same authorized selected branch carried by stock validation.');
$assert(str_contains($cartSource, 'printflow_pos_assert_cart_branch($posBranchId)'),
    'Cart actions reject a branch switch while retaining a cart validated under another branch.');

echo "POS authorized branch scope tests passed.\n";
