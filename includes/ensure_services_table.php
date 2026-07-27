<?php
/**
 * Ensures `services` table exists (idempotent) and adds customer visibility columns.
 */
function ensure_services_extra_columns(): void {
    global $conn;
    $columns = [
        'visible_to_customer' => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=show on customer Services page'",
        'customer_link' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'e.g. order_tarpaulin.php'",
        'hero_image' => "VARCHAR(512) NULL DEFAULT NULL COMMENT 'Image path for customer card'",
        'customer_modal_text' => "TEXT NULL DEFAULT NULL COMMENT 'Copy shown in customer service detail modal'",
    ];
    foreach ($columns as $col => $def) {
        $chk = @$conn->query("SHOW COLUMNS FROM `services` LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows === 0) {
            $sql = "ALTER TABLE `services` ADD COLUMN `$col` $def";
            if (!$conn->query($sql)) {
                error_log('ensure_services_extra_columns ' . $col . ': ' . $conn->error);
            }
        }
        if ($chk) {
            $chk->free();
        }
    }
}

/**
 * If services table is empty, seed defaults so admin visibility works out of the box.
 */
function seed_default_customer_services_if_empty(): void {
    global $conn;
    require_once __DIR__ . '/customer_service_catalog.php';
    $cnt = $conn->query("SELECT COUNT(*) AS c FROM services");
    if (!$cnt) {
        return;
    }
    $row = $cnt->fetch_assoc();
    $cnt->free();
    if ((int) ($row['c'] ?? 0) > 0) {
        return;
    }
    $defaults = printflow_default_customer_service_catalog();
    $stmt = $conn->prepare(
        'INSERT INTO services (name, category, description, price, duration, status, visible_to_customer, customer_link, hero_image, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, 1, ?, ?, NOW(), NOW())'
    );
    if (!$stmt) {
        error_log('seed_default_customer_services_if_empty prepare: ' . $conn->error);
        return;
    }
    $desc = 'Order flow for this service.';
    $price = 0.0;
    $status = 'Activated';
    foreach ($defaults as $d) {
        $name = $d['name'];
        $cat = $d['category'];
        $link = $d['link'];
        $img = $d['img'];
        $stmt->bind_param('sssdsss', $name, $cat, $desc, $price, $status, $link, $img);
        if (!$stmt->execute()) {
            error_log('seed_default_customer_services_if_empty insert: ' . $stmt->error);
        }
    }
    $stmt->close();
}

/**
 * Insert any default catalog services that are not yet in the DB (by name, case-insensitive).
 * Keeps Admin in sync when the customer page was previously driven only by legacy/static tiles.
 */
function sync_default_catalog_services_missing_rows(): void {
    global $conn;
    require_once __DIR__ . '/customer_service_catalog.php';
    $defaults = printflow_default_customer_service_catalog();
    $check = $conn->prepare('SELECT 1 FROM services WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1');
    $insert = $conn->prepare(
        'INSERT INTO services (name, category, description, price, duration, status, visible_to_customer, customer_link, hero_image, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, 1, ?, ?, NOW(), NOW())'
    );
    if (!$check || !$insert) {
        error_log('sync_default_catalog_services_missing_rows prepare: ' . $conn->error);
        return;
    }
    $desc = 'Order flow for this service.';
    $price = 0.0;
    $status = 'Activated';
    foreach ($defaults as $d) {
        $name = trim($d['name']);
        $check->bind_param('s', $name);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->free_result();
        if ($exists) {
            continue;
        }
        $cat = $d['category'];
        $link = $d['link'];
        $img = $d['img'];
        $insert->bind_param('sssdsss', $name, $cat, $desc, $price, $status, $link, $img);
        if (!$insert->execute()) {
            error_log('sync_default_catalog_services_missing_rows insert ' . $name . ': ' . $insert->error);
        }
    }
    $check->close();
    $insert->close();
}

/**
 * Fill customer_link / hero_image from the default catalog when the DB row matches by name but fields are empty.
 * Makes Admin show the same links/images the customer page resolves via fallback.
 */
function backfill_catalog_fields_for_matching_services(): void {
    global $conn;
    require_once __DIR__ . '/customer_service_catalog.php';
    $defaults = printflow_default_customer_service_catalog();
    $stmtLink = $conn->prepare(
        'UPDATE services SET customer_link = ? WHERE LOWER(TRIM(name)) = LOWER(?) AND (customer_link IS NULL OR TRIM(customer_link) = \'\')'
    );
    $stmtImg = $conn->prepare(
        'UPDATE services SET hero_image = ? WHERE LOWER(TRIM(name)) = LOWER(?) AND (hero_image IS NULL OR TRIM(hero_image) = \'\')'
    );
    if (!$stmtLink || !$stmtImg) {
        error_log('backfill_catalog_fields_for_matching_services prepare: ' . $conn->error);
        return;
    }
    foreach ($defaults as $d) {
        $name = trim($d['name']);
        $link = $d['link'];
        $img = $d['img'];
        $stmtLink->bind_param('ss', $link, $name);
        $stmtLink->execute();
        $stmtImg->bind_param('ss', $img, $name);
        $stmtImg->execute();
    }
    $stmtLink->close();
    $stmtImg->close();
}

function ensure_services_table(): void {
    global $conn;
    $sql = "CREATE TABLE IF NOT EXISTS `services` (
        `service_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` varchar(150) NOT NULL,
        `category` varchar(80) DEFAULT NULL,
        `description` text,
        `price` decimal(10,2) NOT NULL DEFAULT 0.00,
        `duration` varchar(100) DEFAULT NULL COMMENT 'e.g. 2-3 business days',
        `status` enum('Activated','Deactivated','Archived') NOT NULL DEFAULT 'Activated',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`service_id`),
        UNIQUE KEY `uniq_service_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('ensure_services_table: ' . $conn->error);
    }
    ensure_services_extra_columns();
    seed_default_customer_services_if_empty();
    sync_default_catalog_services_missing_rows();
    backfill_catalog_fields_for_matching_services();
}
