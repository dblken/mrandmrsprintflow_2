-- Apply before enabling the temporary Jenny-only cleanup button.
-- Audit rows contain counts and exact target IDs, never payment secrets or file contents.
CREATE TABLE IF NOT EXISTS customer_testing_data_cleanup_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    actor_customer_id INT NOT NULL,
    status VARCHAR(24) NOT NULL,
    details LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_customer_testing_cleanup (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
