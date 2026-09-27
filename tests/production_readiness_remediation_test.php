<?php

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$source = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$review = $source('customer/order_review.php');
$migration = $source('database/migrate_customer_checkout_idempotency_20260927.php');
$productInventory = $source('includes/product_branch_stock.php');
$changeItems = $source('includes/change_item_workflow.php');
$status = $source('staff/update_order_status_process.php');
$repository = $source('includes/CustomizationRepository.php');
$service = $source('includes/CustomizationService.php');
$session = $source('includes/session_manager.php');

$check(str_contains($migration, 'uq_orders_customer_checkout_token'), 'checkout token has a database unique constraint');
$check(str_contains($review, 'review_checkout_existing_order'), 'duplicate checkout can recover the original order');
$check(str_contains($review, 'checkout_token'), 'checkout token is submitted and persisted');
$check(str_contains($review, '$conn->begin_transaction()') && str_contains($review, '$conn->commit()'), 'checkout owns an order transaction');
$check(str_contains($review, 'checkout_rolled_back') && str_contains($review, '$conn->rollback()'), 'checkout failures roll back');
$check(str_contains($review, 'printflow_apply_product_order_item_inventory('), 'customer checkout uses the shared stock primitive');
$check(str_contains($productInventory, 'stock_quantity >= ?'), 'shared stock primitive retains conditional stock protection');
$check(!str_contains($review, "error_log('POST data:"), 'full checkout POST bodies are not logged');
$check(str_contains($review, 'get_product_field_config($product_id)'), 'product option pricing is recalculated from server configuration');
$check(!str_contains($review, 'return $raw_price;'), 'product checkout does not fall back to cart price');

$check(str_contains($changeItems, '$startedTransaction = !printflow_db_in_transaction($conn);'), 'change-item inventory establishes transaction ownership');
$check(str_contains($status, "expected_status") && str_contains($status, 'current_status'), 'staff status endpoint rejects stale state');

foreach ([
    'staff/api/pos_cart_handler.php',
    'staff/api/pos_update_cart_price.php',
    'staff/api/pos_add_customer.php',
    'staff/api/pos_upload_design.php',
    'customer/api_profile.php',
    'customer/customer_order_api.php',
    'customer/api_reflectorized_order.php',
] as $endpoint) {
    $check(str_contains($source($endpoint), 'verify_csrf_token'), "{$endpoint} verifies CSRF");
}

$check(str_contains($repository, 'getOrderItemSummariesForOrders'), 'staff list has a lean batch query');
$check(str_contains($repository, 'NULL AS customization_data'), 'staff list does not return full customization payloads');
$check(str_contains($service, 'buildSummaryItemView'), 'staff list avoids detail parsing');
$check(!str_contains(
    substr($service, strpos($service, 'function listOrderSummaries'), strpos($service, '/** @param array<int,int>', strpos($service, 'function listOrderSummaries')) - strpos($service, 'function listOrderSummaries')),
    'resolveRawItems('
), 'staff list avoids per-order detail fallback');

$check(str_contains($session, 'PRINTFLOW_SESSION_HMAC_SECRET must be configured in production'), 'production rejects a missing session HMAC secret');
$check(str_contains($session, "printflow_env_bool('PRINTFLOW_ENFORCE_FINGERPRINT', false)"), 'fingerprint enforcement stays deployment-configurable');

if ($failures !== []) {
    fwrite(STDERR, "Production readiness remediation checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Production readiness remediation checks passed.\n");
