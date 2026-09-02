<?php
/**
 * Unified Chat Schema Enforcement
 * Ensures all columns and tables required for the Chat system are present.
 * Included by list_conversations.php, fetch_messages.php, etc.
 */

if (!function_exists('db_query')) return;

if (session_status() === PHP_SESSION_ACTIVE) {
    $schema_checked_at = (int)($_SESSION['pf_schema_chat_checked_at'] ?? 0);
    if ($schema_checked_at > 0 && (time() - $schema_checked_at) < 900) {
        return;
    }
}

global $conn;

// 1. Ensure order_messages columns
require_once __DIR__ . '/ensure_order_messages.php';

// 2. Ensure order_items columns (customization_data)
if (!db_table_has_column('order_items', 'customization_data')) {
    @$conn->query("ALTER TABLE order_items ADD COLUMN customization_data TEXT NULL");
}

// 3. Ensure customers columns (profile_picture, online_status)
if (!db_table_has_column('customers', 'profile_picture')) {
    @$conn->query("ALTER TABLE customers ADD COLUMN profile_picture VARCHAR(255) NULL AFTER last_name");
}
if (!db_table_has_column('customers', 'online_status')) {
    @$conn->query("ALTER TABLE customers ADD COLUMN online_status ENUM('online', 'offline', 'in-call') DEFAULT 'offline'");
}

// 4. Ensure users columns (profile_picture, online_status)
if (!db_table_has_column('users', 'profile_picture')) {
    @$conn->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER last_name");
}
if (!db_table_has_column('users', 'online_status')) {
    @$conn->query("ALTER TABLE users ADD COLUMN online_status ENUM('online', 'offline', 'in-call') DEFAULT 'offline'");
}

// 5. Ensure branch compatibility. Historical is_archived columns, when
// present, are retained but no longer created or used to hide conversations.
if (!db_table_has_column('orders', 'branch_id')) {
    @$conn->query("ALTER TABLE orders ADD COLUMN branch_id INT DEFAULT NULL");
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['pf_schema_chat_checked_at'] = time();
}
