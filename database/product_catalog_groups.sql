-- Customer catalog product groups (display layer only; orders still use products.product_id)
-- Applied automatically via printflow_ensure_product_catalog_groups_schema() on first use.

CREATE TABLE IF NOT EXISTS product_catalog_groups (
    group_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    cover_image VARCHAR(255) NULL,
    description TEXT NULL,
    status ENUM('Activated','Deactivated') NOT NULL DEFAULT 'Activated',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_catalog_group_status (status, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_catalog_group_members (
    member_id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    product_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_catalog_member_product (product_id),
    UNIQUE KEY uq_catalog_group_product (group_id, product_id),
    KEY idx_catalog_group_sort (group_id, sort_order, member_id),
    CONSTRAINT fk_catalog_member_group FOREIGN KEY (group_id) REFERENCES product_catalog_groups (group_id) ON DELETE CASCADE,
    CONSTRAINT fk_catalog_member_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
