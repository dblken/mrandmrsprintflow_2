CREATE TABLE IF NOT EXISTS `printflow_pending_unpaid_hidden_orders` (
  `order_id` INT UNSIGNED NOT NULL,
  `hidden_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
