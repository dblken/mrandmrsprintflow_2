<?php
/**
 * Adds the durable customer checkout-attempt key used by order_review.php.
 *
 * CLI: php database/migrate_customer_checkout_idempotency_20260927.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ensure_customer_checkout_idempotency_schema.php';

if (!printflow_ensure_customer_checkout_idempotency_schema()) {
    fwrite(STDERR, "Customer checkout idempotency migration failed.\n");
    exit(1);
}

fwrite(STDOUT, "Customer checkout idempotency migration is applied.\n");
