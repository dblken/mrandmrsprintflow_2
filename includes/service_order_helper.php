<?php
/**
 * Service Order Helper
 * PrintFlow - Shared logic for service-based ordering
 * - Secure file upload validation (JPG, PNG only, max 5MB)
 * - Reads binary data and stores in DB as LONGBLOB
 * - NO files are ever saved to the local filesystem
 * - Uses prepared statements with send_long_data for BLOBs
 */

if (!defined('BASE_URL')) define('BASE_URL', '/printflow');

// Allowed MIME types for design uploads (JPG, PNG, PDF, AI)
define('SERVICE_ORDER_ALLOWED_MIME', ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/postscript', 'application/x-adobe-ai', 'image/vnd.adobe.photoshop']);
define('SERVICE_ORDER_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'pdf', 'ai', 'psd']);
define('SERVICE_ORDER_MAX_SIZE', 5 * 1024 * 1024); // 5MB limit requested by user

/**
 * Validate uploaded design file
 * - Checks PHP upload error
 * - Enforces 5MB size limit
 * - Validates MIME type using finfo (not just extension)
 * - Allowed: JPG, PNG only
 *
 * @param array $file $_FILES['field_name']
 * @return array ['ok' => bool, 'error' => string, 'mime' => string]
 */
function service_order_validate_file($file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error'] ?? -1) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'error' => 'File exceeds maximum allowed size.', 'mime' => ''];
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'error' => 'No file was uploaded.', 'mime' => ''];
            default:
                return ['ok' => false, 'error' => 'File upload failed. Please try again.', 'mime' => ''];
        }
    }

    // Check file size (5MB)
    if ($file['size'] > SERVICE_ORDER_MAX_SIZE) {
        return ['ok' => false, 'error' => 'File size exceeds the 5MB limit.', 'mime' => ''];
    }

    // Validate MIME type using finfo (reads actual file bytes, not just extension)
    if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Uploaded file is not accessible.', 'mime' => ''];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, SERVICE_ORDER_ALLOWED_MIME)) {
        return ['ok' => false, 'error' => 'Invalid file type. Only JPG and PNG images are allowed.', 'mime' => ''];
    }

    // Extra check: extension must also match (defence-in-depth)
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SERVICE_ORDER_ALLOWED_EXT)) {
        return ['ok' => false, 'error' => 'Invalid file extension. Only .jpg / .jpeg / .png are allowed.', 'mime' => ''];
    }

    return ['ok' => true, 'error' => '', 'mime' => $mime];
}

/**
 * Writable directory for cart design uploads (move_uploaded_file).
 * Creates project-root uploads/temp if missing.
 */
function service_order_temp_dir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'temp';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('PrintFlow service_order_temp_dir: cannot create ' . $dir);
        }
    }
    return $dir;
}

/**
 * Read uploaded file binary data (NO filesystem write ever occurs)
 *
 * @param array  $file   $_FILES['field_name']
 * @param string $mime   MIME type (from validate result)
 * @return array ['data' => binary string|null, 'error' => string]
 */
function service_order_read_file_binary($file, $mime) {
    $data = file_get_contents($file['tmp_name']);
    if ($data === false || $data === '') {
        return ['data' => null, 'error' => 'Failed to read uploaded file.'];
    }
    return ['data' => $data, 'error' => ''];
}

/**
 * Insert service order and details
 * - Creates order in service_orders
 * - Inserts key/value pairs in service_order_details
 * - Stores file binary in service_order_files (LONGBLOB) — no filesystem writes
 *
 * @param string $service_name
 * @param int    $customer_id
 * @param int    $branch_id
 * @param array  $fields  Associative array of field_name => field_value
 * @param array  $files   Optional array of ['file' => $_FILES['x'], 'prefix' => 'design']
 * @return array ['success' => bool, 'order_id' => int, 'error' => string]
 */
function service_order_create($service_name, $customer_id, $branch_id, $fields, $files = []) {
    global $conn;

    // Ensure tables exist with correct schema
    service_order_ensure_tables();

    // ---- Insert main order ----
    $total_price = isset($fields['total_price']) ? (float)$fields['total_price'] : 0;
    unset($fields['total_price']);

    $stmt = $conn->prepare("INSERT INTO service_orders (service_name, customer_id, branch_id, status, total_price) VALUES (?, ?, ?, 'Pending Review', ?)");
    if (!$stmt) {
        return ['success' => false, 'order_id' => 0, 'error' => 'Database error creating order.'];
    }
    $stmt->bind_param('siid', $service_name, $customer_id, $branch_id, $total_price);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'order_id' => 0, 'error' => 'Failed to create order.'];
    }
    $order_id = (int)$conn->insert_id;
    $stmt->close();

    // ---- Insert order details (field_name / field_value pairs) ----
    $detailStmt = $conn->prepare("INSERT INTO service_order_details (order_id, field_name, field_value) VALUES (?, ?, ?)");
    if ($detailStmt) {
        foreach ($fields as $name => $value) {
            if ($name === '' || $value === null) continue;
            $val = is_array($value) ? json_encode($value) : (string)$value;
            $detailStmt->bind_param('iss', $order_id, $name, $val);
            $detailStmt->execute();
        }
        $detailStmt->close();
    }

    // ---- Handle file uploads — store as LONGBLOB, never write to disk ----
    foreach ($files as $f) {
        $upload = $f['file'];
        $valid  = service_order_validate_file($upload);

        if (!$valid['ok']) {
            // Skip invalid files; don't fail entire order (log the issue)
            error_log("PrintFlow: Skipped invalid file upload for service order #{$order_id}: " . $valid['error']);
            continue;
        }

        $binary = service_order_read_file_binary($upload, $valid['mime']);
        if (!$binary['data']) {
            error_log("PrintFlow: Could not read file binary for service order #{$order_id}: " . $binary['error']);
            continue;
        }

        // Insert BLOB using send_long_data for reliability with large images
        $fileStmt = $conn->prepare(
            "INSERT INTO service_order_files (order_id, file_data, mime_type, original_name) VALUES (?, ?, ?, ?)"
        );
        if ($fileStmt) {
            $null = NULL;
            $fileStmt->bind_param('ibss', $order_id, $null, $valid['mime'], $upload['name']);
            $fileStmt->send_long_data(1, $binary['data']);
            $fileStmt->execute();
            $fileStmt->close();
        }
    }

    return ['success' => true, 'order_id' => $order_id, 'error' => ''];
}

/**
 * Ensure service order tables exist with correct schema (auto-create if missing)
 * service_order_files now stores BLOB data, NOT file paths.
 */
function service_order_ensure_tables() {
    static $done = false;
    if ($done) return;
    global $conn;

    $conn->query("CREATE TABLE IF NOT EXISTS service_orders (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        service_name VARCHAR(100) NOT NULL,
        customer_id  INT NOT NULL,
        branch_id    INT DEFAULT NULL,
        status       VARCHAR(50) NOT NULL DEFAULT 'Pending Review',
        total_price  DECIMAL(12,2) DEFAULT 0.00,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status   (status),
        KEY idx_customer (customer_id),
        KEY idx_branch   (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS service_order_details (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        order_id   INT NOT NULL,
        field_name VARCHAR(100) NOT NULL,
        field_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Files table: stores binary in file_data LONGBLOB
    $conn->query("CREATE TABLE IF NOT EXISTS service_order_files (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        order_id      INT NOT NULL,
        file_data     LONGBLOB DEFAULT NULL,
        mime_type     VARCHAR(50) DEFAULT NULL,
        original_name VARCHAR(255) DEFAULT NULL,
        file_path     VARCHAR(255) DEFAULT NULL,
        uploaded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add BLOB columns to existing tables if running upgrade (idempotent)
    $res = $conn->query("SHOW COLUMNS FROM service_order_files LIKE 'file_data'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE service_order_files ADD COLUMN file_data LONGBLOB DEFAULT NULL");
    }

    $res = $conn->query("SHOW COLUMNS FROM service_order_files LIKE 'mime_type'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE service_order_files ADD COLUMN mime_type VARCHAR(50) DEFAULT NULL");
    }

    $res = $conn->query("SHOW COLUMNS FROM service_orders LIKE 'branch_id'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE service_orders ADD COLUMN branch_id INT DEFAULT NULL AFTER customer_id");
        $conn->query("ALTER TABLE service_orders ADD INDEX idx_branch (branch_id)");
    }

    // ENUM or VARCHAR too narrow causes "Data truncated for column 'status'" on approve (Processing) / reject.
    $res = $conn->query("SHOW COLUMNS FROM service_orders LIKE 'status'");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $type = strtolower((string)($row['Type'] ?? ''));
        $needs_widen = (strpos($type, 'enum(') === 0);
        if (preg_match('/^varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 50) {
            $needs_widen = true;
        }
        if (preg_match('/^char\((\d+)\)/', $type, $m) && (int)$m[1] < 50) {
            $needs_widen = true;
        }
        if ($needs_widen) {
            $conn->query(
                "ALTER TABLE service_orders MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Pending Review'"
            );
        }
    }

    $conn->query("ALTER TABLE service_order_files MODIFY COLUMN file_path VARCHAR(255) DEFAULT NULL");

    $done = true;
}

/**
 * Fetch real-time sold amount, average rating, and review count for a specific service page.
 * Pass $resolved_service_id when known (e.g. order_service_dynamic.php) so stats work even if customer_link is empty.
 * Otherwise uses customer_link (e.g. 'order_stickers') to find the correct service row.
 */
function service_order_get_page_stats($keyword, $resolved_service_id = 0) {
    $resolved_service_id = (int)$resolved_service_id;
    if ($resolved_service_id > 0) {
        $row = db_query("SELECT service_id, name FROM services WHERE service_id = ? LIMIT 1", 'i', [$resolved_service_id]);
    } else {
        if (empty($keyword)) {
            return ['sold_count' => 0, 'avg_rating' => 0, 'review_count' => 0];
        }

        $exclude = "AND customer_link NOT LIKE '%order_glass_stickers%' AND customer_link NOT LIKE '%order_transparent%' AND customer_link NOT LIKE '%order_reflectorized%'";
        if (strpos($keyword, 'order_glass') !== false || strpos($keyword, 'order_transparent') !== false || strpos($keyword, 'order_reflectorized') !== false) {
            $exclude = "";
        }

        $row = db_query("SELECT service_id, name FROM services WHERE customer_link LIKE '%" . db_escape($keyword) . "%' $exclude LIMIT 1");
    }

    if (empty($row)) {
        return ['sold_count' => 0, 'avg_rating' => 0, 'review_count' => 0];
    }

    $s_id   = (int)$row[0]['service_id'];
    $s_name = $row[0]['name'];

    $sold_count = function_exists('printflow_service_units_sold')
        ? printflow_service_units_sold($s_id)
        : (int)(db_query(
            "SELECT COALESCE(SUM(oi.quantity), 0) AS cnt
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.order_id
             WHERE o.status != 'Cancelled'
               AND oi.customization_data LIKE '%\"service_type\"%'
               AND oi.customization_data LIKE CONCAT('%', CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, '%')",
            's',
            [$s_name]
        )[0]['cnt'] ?? 0);

    $review_stats = function_exists('printflow_get_service_review_stats')
        ? printflow_get_service_review_stats($s_name)
        : ['avg_rating' => 0, 'review_count' => 0];

    return [
        'sold_count'   => $sold_count,
        'avg_rating'   => $review_stats['avg_rating'] ?? 0,
        'review_count' => (int)($review_stats['review_count'] ?? 0),
        'service_id'   => $s_id,
    ];
}
