<?php
declare(strict_types=1);

/**
 * Ensures order_item_design_files exists for multi-design uploads per order line.
 */
function printflow_ensure_order_item_design_files_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $conn;
    if (!($conn instanceof mysqli)) {
        return;
    }

    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS order_item_design_files (
    file_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL DEFAULT 0,
    customer_id INT UNSIGNED NOT NULL DEFAULT 0,
    customization_id INT UNSIGNED NULL DEFAULT NULL,
    file_role VARCHAR(32) NOT NULL DEFAULT 'design',
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    mime_type VARCHAR(127) NOT NULL DEFAULT 'application/octet-stream',
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    storage_path VARCHAR(512) NOT NULL DEFAULT '',
    upload_sort INT UNSIGNED NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    UNIQUE KEY uq_order_item_design_file_idempotency (idempotency_key),
    KEY idx_order_item_design_files_item_sort (order_item_id, upload_sort, file_id),
    KEY idx_order_item_design_files_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    if (!@$conn->query($sql)) {
        error_log('printflow_ensure_order_item_design_files_schema: ' . $conn->error);
    }
}
