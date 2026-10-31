<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/pos_branch_scope_policy.php';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(printflow_pos_branch_from_authorized_context(['selected_branch_id' => 2, 'allowed_branches' => 'all']) === 2, 'The selected authorized branch is used.');
try { printflow_pos_branch_from_authorized_context(['selected_branch_id' => 2, 'allowed_branches' => [1]]); throw new RuntimeException('Unauthorized branch unexpectedly passed.'); }
catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'not authorized'), 'Unauthorized branch is rejected.'); }
$cartSource = file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$checkoutSource = file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
$assert(str_contains($cartSource, 'return $hasTarpaulin ? printflow_pos_authorized_branch_id() : pos_cart_branch_id();'), 'Only carts containing Tarpaulin use the authorized selected-branch resolver.');
$assert(str_contains($checkoutSource, '$checkout_branch_id = printflow_pos_authorized_branch_id();'), 'Tarpaulin checkout revalidates using the authorized selected branch.');
echo "Tarpaulin branch policy tests passed.\n";
