CREATE TABLE IF NOT EXISTS receipt_printers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    name VARCHAR(120) NOT NULL DEFAULT 'XP-58H Receipt Printer',
    printer_model VARCHAR(80) NOT NULL DEFAULT 'XP-58H',
    paper_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 58,
    printable_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 50,
    columns_count TINYINT UNSIGNED NOT NULL DEFAULT 32,
    printer_driver_name VARCHAR(190) NOT NULL DEFAULT '',
    printing_mode VARCHAR(40) NOT NULL DEFAULT 'escpos_text',
    copies TINYINT UNSIGNED NOT NULL DEFAULT 1,
    auto_print TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    api_key_hash CHAR(64) DEFAULT NULL,
    api_key_prefix VARCHAR(20) DEFAULT NULL,
    api_key_last4 VARCHAR(8) DEFAULT NULL,
    api_key_created_at DATETIME DEFAULT NULL,
    pushy_device_token VARCHAR(255) DEFAULT NULL,
    pushy_registered_at DATETIME DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_receipt_printers_branch (branch_id, status, auto_print),
    KEY idx_receipt_printers_key_hash (api_key_hash),
    KEY idx_receipt_printers_default (is_default, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing installations are upgraded at runtime by
-- printflow_receipt_printer_ensure_schema() before printer operations run.

CREATE TABLE IF NOT EXISTS receipt_print_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_uuid CHAR(36) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    printer_id INT NOT NULL,
    branch_id INT NULL,
    order_id INT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'pos_receipt',
    receipt_number VARCHAR(60) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    copies TINYINT UNSIGNED NOT NULL DEFAULT 1,
    paper_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 58,
    columns_count TINYINT UNSIGNED NOT NULL DEFAULT 32,
    receipt_payload LONGTEXT NOT NULL,
    receipt_text LONGTEXT NOT NULL,
    escpos_base64 LONGTEXT NOT NULL,
    error_message TEXT NULL,
    claimed_at DATETIME NULL,
    printed_at DATETIME NULL,
    failed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipt_print_jobs_uuid (job_uuid),
    UNIQUE KEY uq_receipt_print_jobs_idempotency (idempotency_key),
    KEY idx_receipt_print_jobs_printer_status (printer_id, status, created_at),
    KEY idx_receipt_print_jobs_order (order_id),
    KEY idx_receipt_print_jobs_branch (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_print_job_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NOT NULL,
    status VARCHAR(20) NOT NULL,
    message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_receipt_print_job_events_job (job_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
