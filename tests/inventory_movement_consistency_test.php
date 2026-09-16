<?php
declare(strict_types=1);

$read = static function (string $relative): string {
    $contents = file_get_contents(__DIR__ . '/../' . $relative);
    if ($contents === false) throw new RuntimeException("Missing {$relative}");
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};

$productStock = $read('includes/product_branch_stock.php');
$optionStock = $read('includes/product_option_stock.php');
$rolls = $read('includes/RollService.php');
$variants = $read('includes/variant_functions.php');
$staffStatus = $read('staff/update_order_status_process.php');
$posCheckout = $read('staff/api/pos_checkout.php');
$providerPayments = $read('includes/provider_payments.php');
$adminStatus = $read('admin/api_update_order_status.php');
$jobs = $read('includes/JobOrderService.php');
$jobApi = $read('admin/job_orders_api.php');
$staffVerify = $read('staff/api_verify_payment.php');
$adminVerify = $read('admin/api_verify_job_payment.php');
$legacyService = $read('staff/api/service_order_api.php');
$ledger = $read('admin/inv_transactions_ledger.php');
$ledgerApi = $read('admin/inventory_transactions_api.php');

$assert(str_contains($productStock, 'function printflow_apply_product_order_item_inventory('), 'shared product movement helper exists');
$assert(str_contains($productStock, 'FOR UPDATE'), 'product order line is locked');
$assert(str_contains($productStock, 'ORDER-{$orderId}-ITEM-{$orderItemId}-OUT'), 'product movement uses deterministic order-line idempotency');
$assert(str_contains($productStock, 'Product stock changed but its ledger transaction could not be recorded.'), 'ledger failure aborts product movement');
$assert(str_contains($productStock, 'db_execute_affected_rows('), 'base and branch deductions require an affected stock row');
$assert(str_contains($optionStock, 'A valid stock option must be selected for this product.'), 'option-stock products cannot fall back to base stock');
$assert(str_contains($optionStock, '$affected === 1'), 'option deductions reject concurrent lost updates');

foreach ([
    'online completion' => $staffStatus,
    'cash POS' => $posCheckout,
    'PayMongo POS' => $providerPayments,
    'admin completion' => $adminStatus,
] as $flow => $source) {
    $assert(str_contains($source, 'printflow_apply_product_order_item_inventory('), "{$flow} uses shared product movement");
}
$assert(str_contains($providerPayments, "customization['service_id']") && str_contains($providerPayments, "customization['service_type']"), 'PayMongo POS excludes service placeholder lines from product stock');
$assert(str_contains($posCheckout, 'printflow_product_option_stock_prepare_cart_customization('), 'POS normalizes and validates selected option stock');
$assert(str_contains($optionStock, 'function printflow_product_option_stock_prepare_cart_customization('), 'shared helper can auto-select a single in-stock option');

$assert(str_contains($jobs, 'getScopedMaterials((int)$orderId, true, true)'), 'service deductions lock undeducted assignments');
$assert(str_contains($jobs, "['materials' => true, 'inks' => false]"), 'completion does not repeat production-stage ink usage');
$assert(str_contains($jobs, "SELECT id FROM inv_items WHERE id = ? FOR UPDATE"), 'material stock checks are serialized');
$assert(str_contains($jobs, 'SET deducted_at = NOW()'), 'assigned material and ink usage is stamped only after movement');
$assert(str_contains($rolls, '$wasInTransaction = printflow_db_in_transaction($conn);'), 'roll deductions preserve caller transaction ownership');
$assert(str_contains($variants, '$startedTransaction = !printflow_db_in_transaction($conn);'), 'legacy variant deductions preserve caller transaction ownership');
$assert(str_contains($variants, 'AND stock_quantity >= ?'), 'legacy variant stock uses an atomic insufficient-stock guard');
$assert(!str_contains($jobApi, 'JobOrderService::ensureStoreOrderProductionDeductions('), 'read endpoints do not mutate inventory');
$assert(!str_contains($jobApi, 'JobOrderService::ensureProductionDeductionsForJob('), 'read endpoints do not trigger job deductions');

$assert(!str_contains($staffVerify, "UPDATE job_orders SET status = 'IN_PRODUCTION', updated_at = NOW() WHERE id = ?"), 'staff payment verification does not force status after deduction failure');
$assert(!str_contains($adminVerify, "SET status = 'IN_PRODUCTION', updated_at = NOW()"), 'admin payment verification does not force status after deduction failure');
$assert(str_contains($legacyService, 'FOR UPDATE'), 'legacy service approval locks inventory work');
$assert(str_contains($legacyService, '$branchId'), 'legacy service deductions carry the service branch');
$assert(str_contains($legacyService, '$op = \'approve\';'), 'legacy Processing transitions use the authoritative deduction event');

foreach (['SERVICE_USAGE', 'PRODUCT_SALE'] as $group) {
    $assert(str_contains($ledger, "'{$group}'"), "ledger page supports {$group} filter");
    $assert(str_contains($ledgerApi, "'{$group}'"), "ledger API supports {$group} filter");
}

echo "Inventory movement consistency regression test passed.\n";
