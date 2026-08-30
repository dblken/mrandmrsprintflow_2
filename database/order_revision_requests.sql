CREATE TABLE IF NOT EXISTS order_revision_requests (
    revision_request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    staff_id INT NOT NULL,
    customer_id INT NOT NULL,
    reason_code VARCHAR(64) NOT NULL,
    revision_reason VARCHAR(255) NOT NULL,
    staff_instruction TEXT NOT NULL,
    permitted_fields LONGTEXT NOT NULL,
    previous_values LONGTEXT NOT NULL,
    revised_values LONGTEXT DEFAULT NULL,
    request_status VARCHAR(40) NOT NULL DEFAULT 'Requested',
    active_flag TINYINT NULL DEFAULT 1,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resubmitted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (revision_request_id),
    UNIQUE KEY uq_order_active_revision (order_id, active_flag),
    KEY idx_revision_customer (customer_id, request_status),
    KEY idx_revision_staff (staff_id, request_status),
    KEY idx_revision_requested (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_item_revisions (
    revision_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    staff_id INT DEFAULT NULL,
    revision_reason TEXT,
    design_image LONGBLOB,
    design_image_name VARCHAR(255) DEFAULT NULL,
    design_image_mime VARCHAR(100) DEFAULT NULL,
    design_file VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (revision_id),
    KEY idx_item_revision_order (order_id),
    KEY idx_item_revision_item (order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_revision_permission_repairs (
    repair_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_request_id BIGINT UNSIGNED NOT NULL,
    order_id INT NOT NULL,
    previous_permitted_fields LONGTEXT NULL,
    repaired_permitted_fields LONGTEXT NOT NULL,
    repair_rule VARCHAR(100) NOT NULL,
    repaired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (repair_id),
    UNIQUE KEY uq_revision_permission_repair (revision_request_id),
    KEY idx_permission_repair_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
