-- Additive visibility-exclusion metadata; no existing business rows are changed.
CREATE TABLE IF NOT EXISTS `customer_exclusion` (
  `exclusion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(16) NOT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `record_table` VARCHAR(64) NOT NULL DEFAULT '',
  `record_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `reason` VARCHAR(255) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`exclusion_id`),
  UNIQUE KEY `uq_customer_exclusion_record` (`customer_id`,`entity_type`,`record_table`,`record_id`),
  KEY `ix_customer_exclusion_order` (`status`,`order_id`),
  KEY `ix_customer_exclusion_customer` (`status`,`customer_id`),
  KEY `ix_customer_exclusion_table_record` (`status`,`record_table`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
