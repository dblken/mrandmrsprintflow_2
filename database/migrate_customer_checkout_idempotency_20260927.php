<?php
/**
 * Adds the durable customer checkout-attempt key used by order_review.php.
 *
 * CLI: php database/migrate_customer_checkout_idempotency_20260927.php
 */

require_once __DIR__ . '/../includes/db.php';

if (!db_table_has_column('orders', 'checkout_token')) {
    if (!db_execute(
        'ALTER TABLE orders ADD COLUMN checkout_token CHAR(64) NULL DEFAULT NULL AFTER order_source'
    )) {
        fwrite(STDERR, "Failed to add orders.checkout_token.\n");
        exit(1);
    }
}

$indexes = db_query("SHOW INDEX FROM orders WHERE Key_name = 'uq_orders_customer_checkout_token'") ?: [];
if ($indexes === []) {
    if (!db_execute(
        'ALTER TABLE orders ADD UNIQUE KEY uq_orders_customer_checkout_token (customer_id, checkout_token)'
    )) {
        fwrite(STDERR, "Failed to add checkout-token unique index.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Customer checkout idempotency migration is applied.\n");
