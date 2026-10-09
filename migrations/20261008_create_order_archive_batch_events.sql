-- One immutable, batch-level audit record per archive operation.
-- The existing per-order event table remains unchanged for order-specific restore history.
-- This migration creates only isolated archive metadata and does not rewrite existing rows.
CREATE TABLE IF NOT EXISTS `printflow_order_archive_batch_events` (
  `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `archive_batch_id` VARCHAR(80) NOT NULL,
  `actor_user_id` BIGINT UNSIGNED NOT NULL,
  `event_at` DATETIME NOT NULL,
  `date_range_start` DATETIME NOT NULL,
  `date_range_end` DATETIME NOT NULL,
  `manifest_order_count` INT UNSIGNED NOT NULL,
  `manifest_order_ids` LONGTEXT NOT NULL,
  `protected_record_notes` TEXT NOT NULL,
  `operation_result` VARCHAR(32) NOT NULL,
  `related_record_counts` LONGTEXT NULL,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uq_printflow_order_archive_batch_event` (`archive_batch_id`),
  KEY `ix_printflow_order_archive_batch_event_at` (`event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
