<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

echo "Starting database migration...\n\n";

// 1. Alter orders table
echo "Updating `orders` table...\n";
$alter_orders = [
    "ADD COLUMN IF NOT EXISTS notes TEXT NULL",
    "ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(100) NULL",
    "ADD COLUMN IF NOT EXISTS cancel_reason TEXT NULL",
    "ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL",
    "ADD COLUMN IF NOT EXISTS design_status VARCHAR(50) DEFAULT 'Pending'",
    "ADD COLUMN IF NOT EXISTS revision_reason TEXT NULL",
    "ADD COLUMN IF NOT EXISTS estimated_completion DATE NULL"
];
foreach ($alter_orders as $alter) {
    try {
        $conn->query("ALTER TABLE orders $alter");
    } catch (Exception $e) {
        // Ignore duplicate column errors if any
    }
}
echo "Done.\n\n";

// 2. Alter order_items table
echo "Updating `order_items` table...\n";
printflow_ensure_order_items_columns();
$alter_order_items = [
    "ADD COLUMN IF NOT EXISTS customization_data LONGTEXT NULL",
    "ADD COLUMN IF NOT EXISTS design_image LONGBLOB NULL",
    "ADD COLUMN IF NOT EXISTS design_image_mime VARCHAR(128) NULL",
    "ADD COLUMN IF NOT EXISTS design_image_name VARCHAR(255) NULL",
    "ADD COLUMN IF NOT EXISTS design_file VARCHAR(512) NULL",
    "ADD COLUMN IF NOT EXISTS reference_image_file VARCHAR(512) NULL",
    "ADD COLUMN IF NOT EXISTS specifications LONGTEXT NULL",
];
foreach ($alter_order_items as $alter) {
    try {
        $conn->query("ALTER TABLE order_items $alter");
    } catch (Exception $e) {
    }
}
echo "Done.\n\n";

// 3. Create missing tables
echo "Creating missing tables...\n";

$tables = [
    "CREATE TABLE IF NOT EXISTS `job_orders` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `order_id` int(11) DEFAULT NULL,
      `customer_id` int(11) DEFAULT NULL,
      `job_title` varchar(255) DEFAULT NULL,
      `service_type` varchar(100) DEFAULT NULL,
      `width_ft` decimal(10,2) DEFAULT NULL,
      `height_ft` decimal(10,2) DEFAULT NULL,
      `quantity` int(11) DEFAULT '1',
      `total_sqft` decimal(10,2) DEFAULT NULL,
      `price_per_sqft` decimal(10,2) DEFAULT NULL,
      `price_per_piece` decimal(10,2) DEFAULT NULL,
      `estimated_total` decimal(10,2) DEFAULT NULL,
      `required_payment` decimal(10,2) DEFAULT NULL,
      `status` varchar(50) DEFAULT 'PENDING',
      `payment_status` varchar(50) DEFAULT 'UNPAID',
      `notes` text,
      `due_date` date DEFAULT NULL,
      `priority` varchar(20) DEFAULT 'NORMAL',
      `artwork_path` varchar(255) DEFAULT NULL,
      `machine_id` int(11) DEFAULT NULL,
      `created_by` int(11) DEFAULT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `job_order_materials` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `job_order_id` int(11) NOT NULL,
      `item_id` int(11) NOT NULL,
      `roll_id` int(11) DEFAULT NULL,
      `quantity` decimal(10,2) NOT NULL,
      `uom` varchar(20) NOT NULL,
      `computed_required_length_ft` decimal(10,2) DEFAULT '0.00',
      `unit_cost_at_assignment` decimal(10,2) DEFAULT '0.00',
      `notes` text,
      `metadata` json DEFAULT NULL,
      `assigned_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      `deducted_at` timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `job_order_ink_usage` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `job_order_id` int(11) NOT NULL,
      `item_id` int(11) NOT NULL,
      `ink_color` varchar(50) NOT NULL,
      `quantity_used` decimal(10,2) NOT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `job_order_files` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `job_order_id` int(11) NOT NULL,
      `file_path` varchar(255) NOT NULL,
      `file_name` varchar(255) NOT NULL,
      `file_type` varchar(50) DEFAULT NULL,
      `uploaded_by` int(11) DEFAULT NULL,
      `uploaded_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `inv_categories` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `description` text,
      `sort_order` int(11) DEFAULT '0',
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `inv_items` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `category_id` int(11) DEFAULT NULL,
      `name` varchar(150) NOT NULL,
      `unit_of_measure` varchar(20) NOT NULL,
      `track_by_roll` tinyint(1) DEFAULT '0',
      `default_roll_length_ft` decimal(10,2) DEFAULT NULL,
      `unit_cost` decimal(10,2) DEFAULT '0.00',
      `reorder_level` decimal(10,2) DEFAULT '0.00',
      `critical_level` decimal(10,2) NOT NULL DEFAULT '1.00',
      `status` varchar(20) DEFAULT 'ACTIVE',
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `inv_rolls` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `item_id` int(11) NOT NULL,
      `roll_code` varchar(50) NOT NULL,
      `width_ft` decimal(10,2) DEFAULT '0.00',
      `total_length_ft` decimal(10,2) NOT NULL,
      `remaining_length_ft` decimal(10,2) NOT NULL,
      `status` varchar(20) DEFAULT 'OPEN',
      `supplier` varchar(150) DEFAULT NULL,
      `notes` text,
      `received_at` timestamp NULL DEFAULT NULL,
      `finished_at` timestamp NULL DEFAULT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `inventory_transactions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `item_id` int(11) NOT NULL,
      `transaction_id` varchar(50) DEFAULT NULL,
      `roll_id` int(11) DEFAULT NULL,
      `direction` varchar(10) NOT NULL,
      `quantity` decimal(10,2) NOT NULL,
      `uom` varchar(20) NOT NULL,
      `ref_type` varchar(50) NOT NULL,
      `ref_id` int(11) DEFAULT NULL,
      `notes` text,
      `created_by` int(11) DEFAULT NULL,
      `transaction_date` date DEFAULT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_unique_txn` (`transaction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `machines` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `type` varchar(50) DEFAULT NULL,
      `status` varchar(20) DEFAULT 'ACTIVE',
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS `service_material_rules` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `service_type` varchar(100) NOT NULL,
      `item_id` int(11) NOT NULL,
      `rule_type` varchar(50) DEFAULT 'REQUIRED',
      `qty_multiplier` decimal(10,2) DEFAULT '1.00',
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

foreach ($tables as $sql) {
    if (!$conn->query($sql)) {
        echo "Error creating table: " . $conn->error . "\n";
    }
}
echo "Done.\n";

echo "\nRepairing custom orders missing order_items...\n";
if (function_exists('printflow_repair_all_orders_missing_line_items')) {
    $repair = printflow_repair_all_orders_missing_line_items(2000);
    echo 'Scanned: ' . (int)($repair['scanned'] ?? 0) . ', repaired: ' . (int)($repair['repaired'] ?? 0) . ', failed: ' . (int)($repair['failed'] ?? 0) . "\n";
}

echo "\nMigration completed successfully.\n";
