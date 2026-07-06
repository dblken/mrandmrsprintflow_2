<?php
/**
 * Setup Customization Indexes
 * Run this script to add performance optimization indexes to the database.
 */
require_once __DIR__ . '/includes/db.php';

echo "<h2>Adding Database Optimization Indexes...</h2>";

$queries = [
    // Indexes for job_orders
    "ALTER TABLE `job_orders` ADD INDEX IF NOT EXISTS `idx_jo_order_id` (`order_id`)",
    "ALTER TABLE `job_orders` ADD INDEX IF NOT EXISTS `idx_jo_customer_id` (`customer_id`)",
    "ALTER TABLE `job_orders` ADD INDEX IF NOT EXISTS `idx_jo_status` (`status`)",
    
    // Indexes for job_order_materials
    "ALTER TABLE `job_order_materials` ADD INDEX IF NOT EXISTS `idx_jom_job_order` (`job_order_id`)",
    "ALTER TABLE `job_order_materials` ADD INDEX IF NOT EXISTS `idx_jom_item` (`item_id`)",
    
    // Indexes for job_order_ink_usage
    "ALTER TABLE `job_order_ink_usage` ADD INDEX IF NOT EXISTS `idx_joiu_job_order` (`job_order_id`)",
    "ALTER TABLE `job_order_ink_usage` ADD INDEX IF NOT EXISTS `idx_joiu_item` (`item_id`)"
];

global $conn;

foreach ($queries as $sql) {
    echo "<p>Running: <code>$sql</code> ... ";
    try {
        if ($conn->query($sql)) {
            echo "<span style='color:green;font-weight:bold;'>✓ Success</span>";
        } else {
            // IF NOT EXISTS might not be supported in older MySQL versions, so we handle duplicate index errors gracefully
            $error = $conn->error;
            if (strpos($error, 'Duplicate key name') !== false) {
                echo "<span style='color:blue;'>✓ Already exists</span>";
            } else {
                echo "<span style='color:red;font-weight:bold;'>✗ Failed: $error</span>";
            }
        }
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "<span style='color:blue;'>✓ Already exists</span>";
        } else {
            echo "<span style='color:red;font-weight:bold;'>✗ Failed: " . $e->getMessage() . "</span>";
        }
    }
    echo "</p>";
}

echo "<h3>✅ Database Optimization Complete!</h3>";
