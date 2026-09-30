<?php
/**
 * Idempotent schema for customer checkout idempotency (orders.checkout_token).
 * Used by customer/order_review.php duplicate-order protection.
 */
function printflow_ensure_customer_checkout_idempotency_schema(): bool
{
    static $attempted = false;
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if ($attempted) {
        return db_table_has_column('orders', 'checkout_token', true);
    }
    $attempted = true;

    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $ready = false;
        return false;
    }

    $tableCheck = @$conn->query("SHOW TABLES LIKE 'orders'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        if ($tableCheck) {
            $tableCheck->free();
        }
        error_log('printflow_ensure_customer_checkout_idempotency_schema: orders table missing');
        $ready = false;
        return false;
    }
    $tableCheck->free();

    if (!db_table_has_column('orders', 'checkout_token')) {
        $afterClause = db_table_has_column('orders', 'order_source')
            ? ' AFTER order_source'
            : '';
        $sql = 'ALTER TABLE orders ADD COLUMN checkout_token CHAR(64) NULL DEFAULT NULL' . $afterClause;
        if (!@$conn->query($sql)) {
            error_log('printflow_ensure_customer_checkout_idempotency_schema: add checkout_token failed: ' . $conn->error);
            $ready = false;
            return false;
        }
        db_table_has_column('orders', 'checkout_token', true);
    }

    $indexes = db_query("SHOW INDEX FROM orders WHERE Key_name = 'uq_orders_customer_checkout_token'") ?: [];
    if ($indexes === []) {
        if (!db_table_has_column('orders', 'customer_id')) {
            error_log('printflow_ensure_customer_checkout_idempotency_schema: customer_id missing; cannot add unique index');
            $ready = db_table_has_column('orders', 'checkout_token', true);
            return $ready;
        }
        if (!@$conn->query(
            'ALTER TABLE orders ADD UNIQUE KEY uq_orders_customer_checkout_token (customer_id, checkout_token)'
        )) {
            error_log('printflow_ensure_customer_checkout_idempotency_schema: unique index failed: ' . $conn->error);
            $ready = db_table_has_column('orders', 'checkout_token', true);
            return $ready;
        }
    }

    $ready = db_table_has_column('orders', 'checkout_token', true);
    return $ready;
}

function printflow_customer_checkout_idempotency_schema_ready(): bool
{
    if (printflow_ensure_customer_checkout_idempotency_schema()) {
        return true;
    }
    return db_table_has_column('orders', 'checkout_token', true);
}
