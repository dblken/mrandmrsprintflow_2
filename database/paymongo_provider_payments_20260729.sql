CREATE TABLE IF NOT EXISTS `provider_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type` ENUM('order','job_order') NOT NULL,
  `subject_id` INT NOT NULL,
  `order_id` INT DEFAULT NULL,
  `job_order_id` INT DEFAULT NULL,
  `customer_id` INT NOT NULL,
  `branch_id` INT DEFAULT NULL,
  `channel` ENUM('online','pos') NOT NULL,
  `provider` VARCHAR(30) NOT NULL DEFAULT 'paymongo',
  `mode` ENUM('test') NOT NULL DEFAULT 'test',
  `amount_centavos` INT UNSIGNED NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'PHP',
  `status` ENUM('generating','awaiting_payment','paid','failed','expired','cancelled') NOT NULL,
  `link_id` VARCHAR(100) DEFAULT NULL,
  `checkout_url` VARCHAR(500) DEFAULT NULL,
  `provider_payment_id` VARCHAR(100) DEFAULT NULL,
  `last_error_code` VARCHAR(100) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `last_reconciled_at` DATETIME DEFAULT NULL,
  `fulfillment_applied_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_payment_subject` (`subject_type`,`subject_id`,`channel`,`provider`,`mode`),
  UNIQUE KEY `uq_provider_payment_link` (`link_id`),
  UNIQUE KEY `uq_provider_payment_transaction` (`provider_payment_id`),
  KEY `idx_provider_payment_order` (`order_id`),
  KEY `idx_provider_payment_customer` (`customer_id`,`status`),
  KEY `idx_provider_payment_branch` (`branch_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(30) NOT NULL DEFAULT 'paymongo',
  `event_id` VARCHAR(100) NOT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `mode` ENUM('test') NOT NULL DEFAULT 'test',
  `status` ENUM('processing','processed','ignored','failed') NOT NULL,
  `provider_payment_id` BIGINT UNSIGNED DEFAULT NULL,
  `raw_event_json` LONGTEXT DEFAULT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_webhook_event` (`provider`,`event_id`),
  KEY `idx_provider_webhook_status` (`status`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `orders`
  MODIFY `payment_status` VARCHAR(40) NOT NULL DEFAULT 'Unpaid';
