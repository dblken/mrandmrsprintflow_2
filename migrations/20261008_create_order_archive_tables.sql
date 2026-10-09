-- Record-specific archive metadata only. Apply once before deploying pages that use it.
-- No existing transactional, master, or configuration rows are changed.
CREATE TABLE IF NOT EXISTS `printflow_order_archive_map` (
  `archive_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `archive_batch_id` VARCHAR(80) NOT NULL,
  `archived_by` BIGINT UNSIGNED NOT NULL,
  `archived_at` DATETIME NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `restored_at` DATETIME NULL DEFAULT NULL,
  `restored_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'archived',
  PRIMARY KEY (`archive_id`),
  UNIQUE KEY `uq_printflow_order_archive_order` (`order_id`),
  KEY `ix_printflow_order_archive_status` (`status`, `archived_at`),
  KEY `ix_printflow_order_archive_batch` (`archive_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `printflow_order_archive_events` (
  `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `archive_batch_id` VARCHAR(80) NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `actor_user_id` BIGINT UNSIGNED NOT NULL,
  `event_at` DATETIME NOT NULL,
  `details_json` LONGTEXT NULL,
  PRIMARY KEY (`event_id`),
  KEY `ix_printflow_order_archive_event_order` (`order_id`, `event_at`),
  KEY `ix_printflow_order_archive_event_batch` (`archive_batch_id`, `event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
