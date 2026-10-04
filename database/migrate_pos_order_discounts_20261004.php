<?php
/**
 * Add POS order-level discount audit columns.
 * Safe to run more than once.
 */
require_once __DIR__ . '/../includes/db.php';

$columns = [
    'pos_subtotal_amount' => "ALTER TABLE orders ADD COLUMN pos_subtotal_amount DECIMAL(12,2) DEFAULT NULL AFTER total_amount",
    'pos_discount_type' => "ALTER TABLE orders ADD COLUMN pos_discount_type VARCHAR(20) DEFAULT NULL AFTER pos_subtotal_amount",
    'pos_discount_value' => "ALTER TABLE orders ADD COLUMN pos_discount_value DECIMAL(12,2) DEFAULT NULL AFTER pos_discount_type",
    'pos_discount_amount' => "ALTER TABLE orders ADD COLUMN pos_discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pos_discount_value",
    'pos_discount_reason' => "ALTER TABLE orders ADD COLUMN pos_discount_reason VARCHAR(120) DEFAULT NULL AFTER pos_discount_amount",
    'pos_discount_notes' => "ALTER TABLE orders ADD COLUMN pos_discount_notes TEXT DEFAULT NULL AFTER pos_discount_reason",
    'pos_discount_applied_by' => "ALTER TABLE orders ADD COLUMN pos_discount_applied_by INT DEFAULT NULL AFTER pos_discount_notes",
    'pos_discount_applied_at' => "ALTER TABLE orders ADD COLUMN pos_discount_applied_at DATETIME DEFAULT NULL AFTER pos_discount_applied_by",
];

foreach ($columns as $column => $sql) {
    if (!db_table_has_column('orders', $column, true)) {
        if (!db_execute($sql)) {
            fwrite(STDERR, "Failed to add orders.{$column}\n");
            exit(1);
        }
        db_table_has_column('orders', $column, true);
        echo "Added orders.{$column}\n";
    } else {
        echo "orders.{$column} already exists\n";
    }
}

echo "POS discount migration complete.\n";
