<?php
/**
 * Change Item / Rework workflow — post-completion replacement on the original order.
 *
 * Does not create a new order, sale, or payment record. Inventory rework usage is
 * recorded separately with ref_type CHANGE_ITEM.
 */

require_once __DIR__ . '/functions.php';

function printflow_change_item_ensure_schema(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    global $conn;
    $ready = (bool) db_execute(
        "CREATE TABLE IF NOT EXISTS change_item_requests (
            change_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT NOT NULL,
            order_item_id INT NULL DEFAULT NULL,
            job_order_id INT NULL DEFAULT NULL,
            customer_id INT NULL DEFAULT NULL,
            sequence_no INT NOT NULL DEFAULT 1,
            source_channel VARCHAR(20) NOT NULL DEFAULT 'customer',
            created_by_user_id INT NULL DEFAULT NULL,
            created_by_role VARCHAR(20) NULL DEFAULT NULL,
            reason_code VARCHAR(64) NOT NULL,
            reason_label VARCHAR(255) NOT NULL,
            issue_description TEXT NOT NULL,
            staff_notes TEXT NULL,
            proof_path VARCHAR(512) NULL DEFAULT NULL,
            proof_original_name VARCHAR(255) NULL DEFAULT NULL,
            request_status VARCHAR(40) NOT NULL DEFAULT 'Requested',
            active_flag TINYINT NULL DEFAULT 1,
            rejection_reason TEXT NULL,
            reviewed_by_user_id INT NULL DEFAULT NULL,
            inventory_recorded_at DATETIME NULL DEFAULT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL DEFAULT NULL,
            approved_at DATETIME NULL DEFAULT NULL,
            rejected_at DATETIME NULL DEFAULT NULL,
            completed_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            idempotency_key VARCHAR(64) NULL DEFAULT NULL,
            PRIMARY KEY (change_item_id),
            UNIQUE KEY uq_order_active_change_item (order_id, active_flag),
            UNIQUE KEY uq_change_item_idempotency (idempotency_key),
            KEY idx_change_item_order (order_id, request_status),
            KEY idx_change_item_customer (customer_id, request_status),
            KEY idx_change_item_job (job_order_id),
            KEY idx_change_item_requested (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($ready) {
        printflow_change_item_upgrade_schema();
    }

    return $ready;
}

function printflow_change_item_upgrade_schema(): void
{
    global $conn;
    if (!$conn instanceof mysqli) {
        return;
    }

    $columns = [
        'order_item_id' => 'INT NULL DEFAULT NULL',
        'job_order_id' => 'INT NULL DEFAULT NULL',
        'customer_id' => 'INT NULL DEFAULT NULL',
        'sequence_no' => 'INT NOT NULL DEFAULT 1',
        'source_channel' => "VARCHAR(20) NOT NULL DEFAULT 'customer'",
        'created_by_user_id' => 'INT NULL DEFAULT NULL',
        'created_by_role' => 'VARCHAR(20) NULL DEFAULT NULL',
        'reason_code' => "VARCHAR(64) NOT NULL DEFAULT 'other'",
        'reason_label' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'issue_description' => "TEXT NULL",
        'staff_notes' => 'TEXT NULL',
        'proof_path' => 'VARCHAR(512) NULL DEFAULT NULL',
        'proof_original_name' => 'VARCHAR(255) NULL DEFAULT NULL',
        'request_status' => "VARCHAR(40) NOT NULL DEFAULT 'Requested'",
        'active_flag' => 'TINYINT NULL DEFAULT 1',
        'rejection_reason' => 'TEXT NULL',
        'reviewed_by_user_id' => 'INT NULL DEFAULT NULL',
        'inventory_recorded_at' => 'DATETIME NULL DEFAULT NULL',
        'requested_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'reviewed_at' => 'DATETIME NULL DEFAULT NULL',
        'approved_at' => 'DATETIME NULL DEFAULT NULL',
        'rejected_at' => 'DATETIME NULL DEFAULT NULL',
        'completed_at' => 'DATETIME NULL DEFAULT NULL',
        'updated_at' => 'DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        'idempotency_key' => 'VARCHAR(64) NULL DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!function_exists('db_table_has_column') || db_table_has_column('change_item_requests', $column)) {
            continue;
        }
        @$conn->query("ALTER TABLE `change_item_requests` ADD COLUMN `{$column}` {$definition}");
        if (function_exists('db_table_has_column')) {
            db_table_has_column('change_item_requests', $column, true);
        }
    }

    // Release legacy rows that still occupy the (order_id, active_flag=1) unique slot.
    @$conn->query(
        "UPDATE change_item_requests
         SET active_flag = NULL
         WHERE active_flag = 1
           AND UPPER(REPLACE(REPLACE(request_status, '-', ' '), ' ', '')) IN ('REJECTED', 'COMPLETED')"
    );
}

function printflow_change_item_release_inactive_slots(int $orderId): void
{
    $orderId = (int) $orderId;
    if ($orderId <= 0) {
        return;
    }

    db_execute(
        "UPDATE change_item_requests
         SET active_flag = NULL
         WHERE order_id = ?
           AND active_flag = 1
           AND UPPER(REPLACE(REPLACE(request_status, '-', ' '), ' ', '')) IN ('REJECTED', 'COMPLETED')",
        'i',
        [$orderId]
    );
}

function printflow_change_item_insert_row(array $row): int
{
    global $conn;

    $orderId = (int)($row['order_id'] ?? 0);
    $orderItemId = isset($row['order_item_id']) && (int)$row['order_item_id'] > 0 ? (int)$row['order_item_id'] : null;
    $jobOrderId = isset($row['job_order_id']) && (int)$row['job_order_id'] > 0 ? (int)$row['job_order_id'] : null;
    $customerId = isset($row['customer_id']) && (int)$row['customer_id'] > 0 ? (int)$row['customer_id'] : null;
    $sequenceNo = (int)($row['sequence_no'] ?? 1);
    $sourceChannel = (string)($row['source_channel'] ?? 'customer');
    $createdBy = isset($row['created_by_user_id']) && (int)$row['created_by_user_id'] > 0 ? (int)$row['created_by_user_id'] : null;
    $createdRole = trim((string)($row['created_by_role'] ?? ''));
    $reasonCode = (string)($row['reason_code'] ?? '');
    $reasonLabel = (string)($row['reason_label'] ?? '');
    $description = (string)($row['issue_description'] ?? '');
    $staffNotes = trim((string)($row['staff_notes'] ?? ''));
    $proofPath = trim((string)($row['proof_path'] ?? ''));
    $proofName = trim((string)($row['proof_original_name'] ?? ''));
    $requestStatus = (string)($row['request_status'] ?? 'Requested');
    $idempotencyKey = trim((string)($row['idempotency_key'] ?? ''));

    $pOrderItemId = $orderItemId;
    $pJobOrderId = $jobOrderId;
    $pCustomerId = $customerId;
    $pCreatedBy = $createdBy;
    $pCreatedRole = $createdRole !== '' ? $createdRole : null;
    $pStaffNotes = $staffNotes !== '' ? $staffNotes : null;
    $pProofPath = $proofPath !== '' ? $proofPath : null;
    $pProofName = $proofName !== '' ? $proofName : null;

    $sql = 'INSERT INTO change_item_requests (
                order_id, order_item_id, job_order_id, customer_id, sequence_no, source_channel,
                created_by_user_id, created_by_role, reason_code, reason_label, issue_description,
                staff_notes, proof_path, proof_original_name, request_status, active_flag, idempotency_key
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[change_item] INSERT prepare failed: ' . $conn->error);
        throw new RuntimeException('Unable to create Change Item request.');
    }

    // 16 placeholders: iiiii + s + i + s×9
    $types = 'iiiiisisssssssss';
    if (!$stmt->bind_param(
        $types,
        $orderId,
        $pOrderItemId,
        $pJobOrderId,
        $pCustomerId,
        $sequenceNo,
        $sourceChannel,
        $pCreatedBy,
        $pCreatedRole,
        $reasonCode,
        $reasonLabel,
        $description,
        $pStaffNotes,
        $pProofPath,
        $pProofName,
        $requestStatus,
        $idempotencyKey
    )) {
        $stmt->close();
        error_log('[change_item] INSERT bind failed: ' . $stmt->error);
        throw new RuntimeException('Unable to create Change Item request.');
    }

    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();
        error_log('[change_item] INSERT execute failed [' . $errno . ']: ' . $error);
        if ($errno === 1062) {
            if (stripos($error, 'uq_order_active_change_item') !== false) {
                throw new InvalidArgumentException('This order already has an active Change Item request.');
            }
            if (stripos($error, 'uq_change_item_idempotency') !== false) {
                throw new InvalidArgumentException('This Change Item request was already submitted.');
            }
        }
        throw new RuntimeException('Unable to create Change Item request.');
    }

    $changeItemId = (int)($stmt->insert_id ?: $conn->insert_id);
    $stmt->close();

    if ($changeItemId <= 0) {
        error_log('[change_item] INSERT succeeded but change_item_id is missing.');
        throw new RuntimeException('Unable to create Change Item request.');
    }

    return $changeItemId;
}

function printflow_change_item_reason_labels(): array
{
    return [
        'damaged_item' => 'Damaged Item',
        'print_quality' => 'Print/Output Quality Issue',
        'incorrect_spec' => 'Incorrect Item/Specification',
        'production_defect' => 'Production Defect',
        'other' => 'Other',
    ];
}

function printflow_change_item_reason_code(string $value): string
{
    $value = strtolower(trim($value));
    $aliases = [
        'damaged item' => 'damaged_item',
        'print/output quality issue' => 'print_quality',
        'print quality issue' => 'print_quality',
        'incorrect item/specification' => 'incorrect_spec',
        'incorrect specification' => 'incorrect_spec',
        'production defect' => 'production_defect',
        'other' => 'other',
    ];
    if (isset($aliases[$value])) {
        return $aliases[$value];
    }
    return array_key_exists($value, printflow_change_item_reason_labels()) ? $value : 'other';
}

function printflow_change_item_reason_label(string $code, string $fallback = ''): string
{
    $code = printflow_change_item_reason_code($code);
    $labels = printflow_change_item_reason_labels();
    if ($code === 'other' && trim($fallback) !== '') {
        return trim($fallback);
    }
    return $labels[$code] ?? ($fallback !== '' ? $fallback : 'Other');
}

function printflow_change_item_normalize_status(string $status): string
{
    $normalized = strtoupper(trim(str_replace(['-', ' '], '_', $status)));
    return trim($normalized, '_');
}

function printflow_change_item_status_label(string $status): string
{
    $map = [
        'REQUESTED' => 'Under Review',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'IN_REWORK' => 'In Production',
        'COMPLETED' => 'Completed',
    ];
    $key = printflow_change_item_normalize_status($status);
    return $map[$key] ?? ucwords(strtolower(str_replace('_', ' ', $key)));
}

function printflow_change_item_blocks_store_order_sync(int $orderId): bool
{
    $orderId = (int) $orderId;
    if ($orderId <= 0 || !printflow_change_item_ensure_schema()) {
        return false;
    }

    $active = printflow_change_item_get_active($orderId);
    if ($active === null) {
        return false;
    }

    $orderRows = db_query(
        'SELECT status FROM orders WHERE order_id = ? LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    if ($orderRows === []) {
        return false;
    }

    $job = printflow_change_item_get_job_for_order($orderId);
    return printflow_change_item_order_completed($orderRows[0], $job);
}

function printflow_change_item_get_job_for_order(int $orderId): ?array
{
    $orderId = (int) $orderId;
    if ($orderId <= 0) {
        return null;
    }
    $rows = db_query(
        "SELECT jo.*
         FROM job_orders jo
         WHERE jo.order_id = ?
           AND jo.status NOT IN ('CANCELLED')
         ORDER BY jo.id ASC
         LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    return $rows[0] ?? null;
}

function printflow_change_item_order_completed(array $orderRow, ?array $jobRow = null): bool
{
    $storeStatus = strtoupper(trim(str_replace(['–', '-'], ' ', (string)($orderRow['status'] ?? ''))));
    $storeCompleted = in_array($storeStatus, ['COMPLETED', 'TO RATE', 'RATED', 'FINISHED', 'RELEASED', 'CLAIMED'], true);
    if (!$storeCompleted && $jobRow === null) {
        return false;
    }
    if ($jobRow !== null) {
        $jobStatus = printflow_change_item_normalize_status((string)($jobRow['status'] ?? ''));
        return $storeCompleted || $jobStatus === 'COMPLETED';
    }
    return $storeCompleted;
}

function printflow_change_item_order_is_eligible(int $orderId, ?int $customerId = null): array
{
    if (!printflow_change_item_ensure_schema()) {
        return ['eligible' => false, 'reason' => 'Change Item storage is unavailable.'];
    }

    $orderId = (int) $orderId;
    if ($orderId <= 0) {
        return ['eligible' => false, 'reason' => 'Invalid order.'];
    }

    $orderRows = db_query(
        'SELECT order_id, customer_id, status, order_source, branch_id FROM orders WHERE order_id = ? LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    if ($orderRows === []) {
        return ['eligible' => false, 'reason' => 'Order not found.'];
    }
    $order = $orderRows[0];
    if ($customerId !== null && (int)($order['customer_id'] ?? 0) !== (int) $customerId) {
        return ['eligible' => false, 'reason' => 'You are not authorized to request a Change Item for this order.'];
    }

    $status = strtoupper(trim((string)($order['status'] ?? '')));
    if (in_array($status, ['CANCELLED', 'CANCELED', 'REJECTED', 'DELETED'], true)) {
        return ['eligible' => false, 'reason' => 'This order is not eligible for a Change Item.'];
    }

    $job = printflow_change_item_get_job_for_order($orderId);
    if (!printflow_change_item_order_completed($order, $job)) {
        return ['eligible' => false, 'reason' => 'Change Item requests are available only for completed orders.'];
    }

    $active = printflow_change_item_get_active($orderId);
    if ($active !== null && !in_array(printflow_change_item_normalize_status((string)($active['request_status'] ?? '')), ['REJECTED', 'COMPLETED'], true)) {
        return ['eligible' => false, 'reason' => 'This order already has an active Change Item request.'];
    }

    return [
        'eligible' => true,
        'reason' => '',
        'order' => $order,
        'job' => $job,
    ];
}

function printflow_change_item_get_active(int $orderId): ?array
{
    if (!printflow_change_item_ensure_schema()) {
        return null;
    }
    $rows = db_query(
        "SELECT cir.*,
                CONCAT_WS(' ', u.first_name, u.last_name) AS created_by_name,
                CONCAT_WS(' ', ru.first_name, ru.last_name) AS reviewed_by_name
         FROM change_item_requests cir
         LEFT JOIN users u ON u.user_id = cir.created_by_user_id
         LEFT JOIN users ru ON ru.user_id = cir.reviewed_by_user_id
         WHERE cir.order_id = ?
           AND cir.active_flag = 1
         ORDER BY cir.change_item_id DESC
         LIMIT 1",
        'i',
        [(int) $orderId]
    ) ?: [];
    return $rows[0] ?? null;
}

function printflow_change_item_get_history(int $orderId): array
{
    if (!printflow_change_item_ensure_schema()) {
        return [];
    }
    $rows = db_query(
        "SELECT cir.*,
                CONCAT_WS(' ', u.first_name, u.last_name) AS created_by_name,
                CONCAT_WS(' ', ru.first_name, ru.last_name) AS reviewed_by_name
         FROM change_item_requests cir
         LEFT JOIN users u ON u.user_id = cir.created_by_user_id
         LEFT JOIN users ru ON ru.user_id = cir.reviewed_by_user_id
         WHERE cir.order_id = ?
         ORDER BY cir.sequence_no ASC, cir.change_item_id ASC",
        'i',
        [(int) $orderId]
    ) ?: [];

    return array_map('printflow_change_item_public_record', $rows);
}

function printflow_change_item_public_record(array $row): array
{
    $status = (string)($row['request_status'] ?? 'Requested');
    return [
        'id' => (int)($row['change_item_id'] ?? 0),
        'sequence_no' => (int)($row['sequence_no'] ?? 1),
        'status' => $status,
        'status_label' => printflow_change_item_status_label($status),
        'reason_code' => (string)($row['reason_code'] ?? ''),
        'reason' => (string)($row['reason_label'] ?? ''),
        'description' => (string)($row['issue_description'] ?? ''),
        'staff_notes' => (string)($row['staff_notes'] ?? ''),
        'rejection_reason' => (string)($row['rejection_reason'] ?? ''),
        'source_channel' => (string)($row['source_channel'] ?? ''),
        'proof_url' => printflow_change_item_proof_url($row),
        'proof_is_image' => printflow_change_item_proof_is_image($row),
        'proof_original_name' => (string)($row['proof_original_name'] ?? ''),
        'requested_at' => (string)($row['requested_at'] ?? ''),
        'requested_at_display' => !empty($row['requested_at']) && function_exists('format_datetime')
            ? format_datetime($row['requested_at'])
            : (string)($row['requested_at'] ?? ''),
        'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
        'approved_at' => (string)($row['approved_at'] ?? ''),
        'rejected_at' => (string)($row['rejected_at'] ?? ''),
        'completed_at' => (string)($row['completed_at'] ?? ''),
        'processed_by' => trim((string)($row['reviewed_by_name'] ?? $row['created_by_name'] ?? '')),
        'created_by' => trim((string)($row['created_by_name'] ?? '')),
        'is_active' => (int)($row['active_flag'] ?? 0) === 1,
    ];
}

function printflow_change_item_proof_url(array $row): string
{
    $path = trim((string)($row['proof_path'] ?? ''));
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $base = function_exists('pf_app_base_path') ? rtrim((string) pf_app_base_path(), '/') : '';
    if (strpos($path, '/uploads/') === 0) {
        return $base . $path;
    }
    return $base . (strpos($path, '/') === 0 ? $path : '/' . $path);
}

function printflow_change_item_proof_is_image(array $row): bool
{
    $candidates = [
        (string)($row['proof_path'] ?? ''),
        (string)($row['proof_original_name'] ?? ''),
    ];
    foreach ($candidates as $candidate) {
        $candidate = strtolower(trim($candidate));
        if ($candidate === '') {
            continue;
        }
        if (preg_match('/\.(jpe?g|png|gif|webp|bmp|avif)(\?.*)?$/', $candidate)) {
            return true;
        }
    }
    return false;
}

function printflow_change_item_upload_proof(array $file, int $orderId): array
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => true, 'path' => '', 'original_name' => ''];
    }

    $maxBytes = 8 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Proof file must be 8 MB or smaller.');
    }

    $original = trim((string)($file['name'] ?? 'proof'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    if (!in_array($ext, $allowed, true)) {
        throw new InvalidArgumentException('Proof must be JPG, PNG, WEBP, GIF, or PDF.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('Invalid proof file type.');
    }

    $uploadRoot = dirname(__DIR__) . '/uploads/change_items';
    if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
        throw new RuntimeException('Unable to store proof upload.');
    }

    $storedName = 'change-item-' . (int) $orderId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadRoot . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save proof upload.');
    }

    return [
        'success' => true,
        'path' => '/uploads/change_items/' . $storedName,
        'original_name' => $original,
    ];
}

function printflow_change_item_next_sequence(int $orderId): int
{
    $rows = db_query(
        'SELECT COALESCE(MAX(sequence_no), 0) AS max_seq FROM change_item_requests WHERE order_id = ?',
        'i',
        [(int) $orderId]
    ) ?: [];
    return (int)($rows[0]['max_seq'] ?? 0) + 1;
}

function printflow_change_item_notify_customer(int $customerId, int $orderId, string $event, array $context = []): void
{
    if ($customerId <= 0) {
        return;
    }

    $ref = printflow_get_order_inventory_reference($orderId);
    $orderLabel = (string)($ref['label'] ?? ('Order #' . $orderId));

    $messages = [
        'submitted' => "Change Item request submitted for {$orderLabel}. Our team will review your request.",
        'approved' => "Your Change Item request for {$orderLabel} has been approved. Your replacement is now being processed.",
        'rejected' => "Your Change Item request for {$orderLabel} was not approved." . (!empty($context['reason']) ? ' Reason: ' . $context['reason'] : ''),
        'in_production' => "Your Change Item replacement for {$orderLabel} is now in production.",
        'ready' => "Your Change Item replacement for {$orderLabel} is ready for pickup.",
        'completed' => "Your Change Item for {$orderLabel} has been completed.",
    ];

    $message = $messages[$event] ?? '';
    if ($message === '') {
        return;
    }

    create_notification($customerId, 'Customer', $message, 'Order', false, false, $orderId);
    if (function_exists('printflow_send_order_update')) {
        printflow_send_order_update($orderId, 'view_status', 'view_status', '', '', [
            'change_item_event' => $event,
            'change_item_message' => $message,
        ]);
    }
}

function printflow_change_item_notify_staff(int $orderId, string $event, array $context = []): void
{
    if (!function_exists('notify_shop_users')) {
        return;
    }

    $ref = printflow_get_order_inventory_reference($orderId);
    $orderLabel = (string)($ref['label'] ?? ('Order #' . $orderId));
    $messages = [
        'submitted' => "Change Item request received for {$orderLabel}.",
        'counter_submitted' => "Counter Change Item submitted for {$orderLabel}.",
    ];
    $message = $messages[$event] ?? '';
    if ($message === '') {
        return;
    }
    notify_shop_users($message, 'Order', false, false, $orderId, ['Staff', 'Admin', 'Manager']);
}

function printflow_change_item_set_job_status(int $jobOrderId, string $jobStatus): void
{
    $jobOrderId = (int) $jobOrderId;
    if ($jobOrderId <= 0) {
        return;
    }

    db_execute(
        'UPDATE job_orders SET status = ?, updated_at = NOW() WHERE id = ?',
        'si',
        [$jobStatus, $jobOrderId]
    );
}

function printflow_change_item_set_order_status(
    int $orderId,
    int $jobOrderId,
    string $jobStatus,
    string $storeStatus,
    bool $preserveStoreStatus = false
): void {
    printflow_change_item_set_job_status($jobOrderId, $jobStatus);

    if ($preserveStoreStatus || printflow_change_item_blocks_store_order_sync($orderId)) {
        return;
    }

    db_execute(
        'UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?',
        'si',
        [$storeStatus, $orderId]
    );
    if (db_table_has_column('customizations', 'status')) {
        db_execute(
            'UPDATE customizations SET status = ?, updated_at = NOW() WHERE order_id = ?',
            'si',
            [$storeStatus, $orderId]
        );
    }
}

function printflow_change_item_process_inventory(int $changeItemId): bool
{
    $changeItemId = (int) $changeItemId;
    if ($changeItemId <= 0) {
        return false;
    }

    $rows = db_query(
        'SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1 FOR UPDATE',
        'i',
        [$changeItemId]
    ) ?: [];
    if ($rows === []) {
        throw new RuntimeException('Change Item record not found.');
    }
    $change = $rows[0];
    if (!empty($change['inventory_recorded_at'])) {
        return true;
    }

    $jobOrderId = (int)($change['job_order_id'] ?? 0);
    if ($jobOrderId <= 0) {
        $job = printflow_change_item_get_job_for_order((int)($change['order_id'] ?? 0));
        $jobOrderId = (int)($job['id'] ?? 0);
    }
    if ($jobOrderId <= 0) {
        throw new RuntimeException('No production job is linked to this Change Item.');
    }

    require_once __DIR__ . '/JobOrderService.php';
    require_once __DIR__ . '/InventoryManager.php';
    require_once __DIR__ . '/RollService.php';

    $branchId = JobOrderService::getJobBranchIdPublic($jobOrderId);
    if ($branchId === null || $branchId <= 0) {
        throw new RuntimeException('Unable to resolve inventory branch for Change Item deduction.');
    }

    $existing = db_query(
        "SELECT id FROM inventory_transactions
         WHERE UPPER(ref_type) = 'CHANGE_ITEM' AND ref_id = ?
         LIMIT 1",
        'i',
        [$changeItemId]
    ) ?: [];
    if ($existing !== []) {
        db_execute(
            'UPDATE change_item_requests SET inventory_recorded_at = NOW() WHERE change_item_id = ?',
            'i',
            [$changeItemId]
        );
        return true;
    }

    $materials = JobOrderService::getScopedMaterialsPublic($jobOrderId, false, true);
    $orderRef = printflow_get_order_inventory_reference((int)($change['order_id'] ?? 0));
    $orderLabel = (string)($orderRef['label'] ?? ('Order #' . (int)($change['order_id'] ?? 0)));
    $notePrefix = "Change Item / Rework for {$orderLabel}";

    foreach ($materials as $material) {
        $itemId = (int)($material['item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $item = InventoryManager::getItem($itemId);
        if (!$item) {
            throw new RuntimeException('An assigned inventory material no longer exists.');
        }

        if (!empty($item['track_by_roll'])) {
            $lengthNeeded = (float)(($material['computed_required_length_ft'] ?? 0) > 0
                ? $material['computed_required_length_ft']
                : ($material['quantity'] ?? 0));
            if ($lengthNeeded <= 0) {
                continue;
            }
            RollService::deductFIFO(
                $itemId,
                $lengthNeeded,
                'CHANGE_ITEM',
                $changeItemId,
                $notePrefix,
                $branchId
            );

            $metadata = is_string($material['metadata'] ?? null)
                ? json_decode((string)$material['metadata'], true)
                : ($material['metadata'] ?? null);
            if (is_array($metadata) && !empty($metadata['lamination_item_id']) && !empty($metadata['lamination_length_ft'])) {
                $lamItem = InventoryManager::getItem((int)$metadata['lamination_item_id']);
                if ($lamItem) {
                    if (!empty($lamItem['track_by_roll'])) {
                        RollService::deductFIFO(
                            (int)$lamItem['id'],
                            (float)$metadata['lamination_length_ft'],
                            'CHANGE_ITEM',
                            $changeItemId,
                            "{$notePrefix} (lamination)",
                            $branchId
                        );
                    } else {
                        InventoryManager::issueStock(
                            (int)$lamItem['id'],
                            (float)$metadata['lamination_length_ft'],
                            $lamItem['unit_of_measure'] ?? 'unit',
                            'CHANGE_ITEM',
                            $changeItemId,
                            "{$notePrefix} (lamination)",
                            false,
                            false,
                            $branchId
                        );
                    }
                }
            }
            continue;
        }

        InventoryManager::issueStock(
            $itemId,
            (float)($material['quantity'] ?? 0),
            (string)($material['uom'] ?? ($item['unit_of_measure'] ?? 'unit')),
            'CHANGE_ITEM',
            $changeItemId,
            $notePrefix,
            false,
            false,
            $branchId
        );
    }

    db_execute(
        'UPDATE change_item_requests SET inventory_recorded_at = NOW() WHERE change_item_id = ?',
        'i',
        [$changeItemId]
    );

    return true;
}

function printflow_change_item_create(array $input): array
{
    if (!printflow_change_item_ensure_schema()) {
        throw new RuntimeException('Change Item storage is unavailable.');
    }

    global $conn;
    $orderId = (int)($input['order_id'] ?? 0);
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $autoApprove = !empty($input['auto_approve']);
    $sourceChannel = strtolower(trim((string)($input['source_channel'] ?? 'customer')));
    if (!in_array($sourceChannel, ['customer', 'counter'], true)) {
        $sourceChannel = 'customer';
    }

    $eligibility = printflow_change_item_order_is_eligible($orderId, $customerId);
    if (empty($eligibility['eligible'])) {
        throw new InvalidArgumentException((string)($eligibility['reason'] ?? 'This order is not eligible for a Change Item.'));
    }

    $reasonCode = printflow_change_item_reason_code((string)($input['reason_code'] ?? ''));
    $reasonFallback = trim((string)($input['reason_label'] ?? ''));
    $reasonLabel = printflow_change_item_reason_label($reasonCode, $reasonFallback);
    $description = trim((string)($input['issue_description'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException('Please describe the issue.');
    }
    if (strlen($description) > 500) {
        throw new InvalidArgumentException('Issue description must be 500 characters or fewer.');
    }
    if ($reasonCode === 'other' && $reasonFallback === '') {
        throw new InvalidArgumentException('Please specify a reason for Other.');
    }

    $staffNotes = trim((string)($input['staff_notes'] ?? ''));
    if (strlen($staffNotes) > 2000) {
        throw new InvalidArgumentException('Staff notes must be 2000 characters or fewer.');
    }
    $createdBy = (int)($input['created_by_user_id'] ?? (function_exists('get_user_id') ? get_user_id() : 0));
    $createdRole = trim((string)($input['created_by_role'] ?? (function_exists('get_user_type') ? get_user_type() : '')));
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = hash('sha256', implode('|', [
            'change-item',
            $orderId,
            $createdBy,
            $reasonCode,
            substr($description, 0, 120),
            date('Y-m-d H:i'),
        ]));
    }

    $existingIdem = db_query(
        'SELECT change_item_id FROM change_item_requests WHERE idempotency_key = ? LIMIT 1',
        's',
        [$idempotencyKey]
    ) ?: [];
    if ($existingIdem !== []) {
        $existingId = (int)($existingIdem[0]['change_item_id'] ?? 0);
        $existing = db_query('SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1', 'i', [$existingId]) ?: [];
        if ($existing !== []) {
            return printflow_change_item_public_record($existing[0]) + ['duplicate' => true];
        }
    }

    $order = $eligibility['order'];
    $job = $eligibility['job'] ?? printflow_change_item_get_job_for_order($orderId);
    $jobOrderId = (int)($job['id'] ?? 0);
    $resolvedCustomerId = (int)($order['customer_id'] ?? 0);
    $orderItemId = (int)($input['order_item_id'] ?? 0);
    if ($orderItemId <= 0) {
        $itemRows = db_query(
            'SELECT order_item_id FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];
        $orderItemId = (int)($itemRows[0]['order_item_id'] ?? 0);
    }

    $proofPath = trim((string)($input['proof_path'] ?? ''));
    $proofName = trim((string)($input['proof_original_name'] ?? ''));

    $started = !printflow_db_in_transaction($conn);
    if ($started && !$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start Change Item transaction.');
    }

    try {
        db_query('SELECT order_id FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE', 'i', [$orderId]);

        printflow_change_item_release_inactive_slots($orderId);

        $active = printflow_change_item_get_active($orderId);
        if ($active !== null && !in_array(printflow_change_item_normalize_status((string)($active['request_status'] ?? '')), ['REJECTED', 'COMPLETED'], true)) {
            throw new InvalidArgumentException('This order already has an active Change Item request.');
        }

        $sequenceNo = printflow_change_item_next_sequence($orderId);
        // Always insert as Requested; auto-approve runs approve_internal next.
        $initialStatus = 'Requested';

        $changeItemId = printflow_change_item_insert_row([
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'job_order_id' => $jobOrderId,
            'customer_id' => $resolvedCustomerId,
            'sequence_no' => $sequenceNo,
            'source_channel' => $sourceChannel,
            'created_by_user_id' => $createdBy,
            'created_by_role' => $createdRole,
            'reason_code' => $reasonCode,
            'reason_label' => $reasonLabel,
            'issue_description' => $description,
            'staff_notes' => $staffNotes,
            'proof_path' => $proofPath,
            'proof_original_name' => $proofName,
            'request_status' => $initialStatus,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($autoApprove) {
            printflow_change_item_approve_internal($changeItemId, $createdBy, true);
        } else {
            printflow_change_item_notify_customer($resolvedCustomerId, $orderId, 'submitted');
            printflow_change_item_notify_staff($orderId, 'submitted');
        }

        if ($started) {
            $conn->commit();
        }

        $saved = db_query('SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1', 'i', [$changeItemId]) ?: [];
        return printflow_change_item_public_record($saved[0] ?? []) + ['duplicate' => false];
    } catch (Throwable $e) {
        if ($started) {
            $conn->rollback();
        }
        throw $e;
    }
}

function printflow_change_item_approve(int $changeItemId, int $staffUserId = 0): array
{
    global $conn;
    $started = !printflow_db_in_transaction($conn);
    if ($started && !$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start approval transaction.');
    }

    try {
        $record = printflow_change_item_approve_internal($changeItemId, $staffUserId, false);
        if ($started) {
            $conn->commit();
        }
        return $record;
    } catch (Throwable $e) {
        if ($started) {
            $conn->rollback();
        }
        throw $e;
    }
}

function printflow_change_item_approve_internal(int $changeItemId, int $staffUserId, bool $silentCustomerNotification): array
{
    $rows = db_query(
        'SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1 FOR UPDATE',
        'i',
        [(int) $changeItemId]
    ) ?: [];
    if ($rows === []) {
        throw new InvalidArgumentException('Change Item request not found.');
    }
    $change = $rows[0];
    $status = printflow_change_item_normalize_status((string)($change['request_status'] ?? ''));
    if ($status === 'IN_REWORK') {
        if (!empty($change['inventory_recorded_at'])) {
            return printflow_change_item_public_record($change);
        }
        // Recovery path: approved in status but inventory not recorded yet.
    } elseif (!in_array($status, ['REQUESTED', 'APPROVED'], true)) {
        throw new InvalidArgumentException('This Change Item request cannot be approved.');
    }

    $orderId = (int)($change['order_id'] ?? 0);
    $jobOrderId = (int)($change['job_order_id'] ?? 0);
    if ($jobOrderId <= 0) {
        $job = printflow_change_item_get_job_for_order($orderId);
        $jobOrderId = (int)($job['id'] ?? 0);
    }

    db_execute(
        "UPDATE change_item_requests
         SET request_status = 'In Rework',
             reviewed_by_user_id = ?,
             reviewed_at = NOW(),
             approved_at = NOW()
         WHERE change_item_id = ?",
        'ii',
        [$staffUserId > 0 ? $staffUserId : null, (int) $changeItemId]
    );

    printflow_change_item_set_order_status(
        $orderId,
        $jobOrderId,
        'IN_PRODUCTION',
        'In Production',
        true
    );
    if ($jobOrderId > 0) {
        printflow_change_item_on_job_status_change($jobOrderId, 'IN_PRODUCTION');
    }

    $customerId = (int)($change['customer_id'] ?? 0);
    if (!$silentCustomerNotification) {
        printflow_change_item_notify_customer($customerId, $orderId, 'approved');
        printflow_change_item_notify_customer($customerId, $orderId, 'in_production');
    } elseif ($customerId > 0) {
        printflow_change_item_notify_customer($customerId, $orderId, 'in_production');
    }

    if ($sourceChannel = (string)($change['source_channel'] ?? '')) {
        if ($sourceChannel === 'counter') {
            printflow_change_item_notify_staff($orderId, 'counter_submitted');
        }
    }

    $saved = db_query('SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1', 'i', [(int) $changeItemId]) ?: [];
    return printflow_change_item_public_record($saved[0] ?? []);
}

function printflow_change_item_reject(int $changeItemId, string $reason, int $staffUserId = 0): array
{
    global $conn;
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A rejection reason is required.');
    }

    $started = !printflow_db_in_transaction($conn);
    if ($started && !$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start rejection transaction.');
    }

    try {
        $rows = db_query(
            'SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1 FOR UPDATE',
            'i',
            [(int) $changeItemId]
        ) ?: [];
        if ($rows === []) {
            throw new InvalidArgumentException('Change Item request not found.');
        }
        $change = $rows[0];
        $status = printflow_change_item_normalize_status((string)($change['request_status'] ?? ''));
        if ($status !== 'REQUESTED') {
            throw new InvalidArgumentException('Only pending Change Item requests can be rejected.');
        }

        $orderId = (int)($change['order_id'] ?? 0);
        $jobOrderId = (int)($change['job_order_id'] ?? 0);
        if ($jobOrderId <= 0) {
            $job = printflow_change_item_get_job_for_order($orderId);
            $jobOrderId = (int)($job['id'] ?? 0);
        }

        db_execute(
            "UPDATE change_item_requests
             SET request_status = 'Rejected',
                 rejection_reason = ?,
                 reviewed_by_user_id = ?,
                 reviewed_at = NOW(),
                 rejected_at = NOW(),
                 active_flag = NULL
             WHERE change_item_id = ?",
            'sii',
            [$reason, $staffUserId > 0 ? $staffUserId : null, (int) $changeItemId]
        );

        printflow_change_item_notify_customer((int)($change['customer_id'] ?? 0), $orderId, 'rejected', ['reason' => $reason]);

        if ($started) {
            $conn->commit();
        }

        $saved = db_query('SELECT * FROM change_item_requests WHERE change_item_id = ? LIMIT 1', 'i', [(int) $changeItemId]) ?: [];
        return printflow_change_item_public_record($saved[0] ?? []);
    } catch (Throwable $e) {
        if ($started) {
            $conn->rollback();
        }
        throw $e;
    }
}

function printflow_change_item_on_job_status_change(int $jobOrderId, string $newStatus): void
{
    if (!printflow_change_item_ensure_schema()) {
        return;
    }

    $normalized = printflow_change_item_normalize_status($newStatus);
    $jobRows = db_query(
        'SELECT order_id FROM job_orders WHERE id = ? LIMIT 1',
        'i',
        [(int) $jobOrderId]
    ) ?: [];
    $orderId = (int)($jobRows[0]['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $active = printflow_change_item_get_active($orderId);
    if ($active === null) {
        return;
    }

    $changeItemId = (int)($active['change_item_id'] ?? 0);
    $requestStatus = printflow_change_item_normalize_status((string)($active['request_status'] ?? ''));

    if (
        in_array($normalized, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING'], true)
        && in_array($requestStatus, ['IN_REWORK', 'APPROVED'], true)
        && empty($active['inventory_recorded_at'])
    ) {
        try {
            printflow_change_item_process_inventory($changeItemId);
        } catch (Throwable $e) {
            error_log('[change_item] Inventory deduction failed for #' . $changeItemId . ': ' . $e->getMessage());
        }
    }

    if (in_array($normalized, ['TO_RECEIVE', 'READY_TO_COLLECT'], true) && $requestStatus === 'IN_REWORK') {
        printflow_change_item_notify_customer((int)($active['customer_id'] ?? 0), $orderId, 'ready');
        return;
    }

    if ($normalized === 'COMPLETED' && in_array($requestStatus, ['IN_REWORK', 'APPROVED'], true)) {
        db_execute(
            "UPDATE change_item_requests
             SET request_status = 'Completed',
                 completed_at = NOW(),
                 active_flag = NULL
             WHERE change_item_id = ?",
            'i',
            [$changeItemId]
        );
        printflow_change_item_notify_customer((int)($active['customer_id'] ?? 0), $orderId, 'completed');
    }
}

function printflow_change_item_summary_for_order(int $orderId): array
{
    $history = printflow_change_item_get_history($orderId);
    $active = printflow_change_item_get_active($orderId);
    $eligibility = printflow_change_item_order_is_eligible($orderId);

    return [
        'eligible' => !empty($eligibility['eligible']),
        'ineligible_reason' => (string)($eligibility['reason'] ?? ''),
        'active' => $active ? printflow_change_item_public_record($active) : null,
        'history' => $history,
        'has_history' => $history !== [],
        'change_item_count' => count($history),
        'show_badge' => $history !== [] || $active !== null,
        'badge_label' => 'Change Item',
    ];
}

function printflow_change_item_batch_summaries(array $orderIds): array
{
    if (!printflow_change_item_ensure_schema()) {
        return [];
    }

    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if ($orderIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));
    $rows = db_query(
        "SELECT order_id,
                SUM(CASE WHEN active_flag = 1 THEN 1 ELSE 0 END) AS active_count,
                COUNT(*) AS total_count,
                MAX(CASE WHEN active_flag = 1 THEN request_status ELSE NULL END) AS active_status,
                MAX(CASE WHEN active_flag = 1 THEN change_item_id ELSE NULL END) AS active_change_item_id
         FROM change_item_requests
         WHERE order_id IN ($placeholders)
         GROUP BY order_id",
        $types,
        $orderIds
    ) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $oid = (int)($row['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        $out[$oid] = [
            'has_change_item' => (int)($row['total_count'] ?? 0) > 0,
            'change_item_count' => (int)($row['total_count'] ?? 0),
            'change_item_active' => (int)($row['active_count'] ?? 0) > 0,
            'change_item_status' => (string)($row['active_status'] ?? ''),
            'change_item_request_id' => (int)($row['active_change_item_id'] ?? 0),
            'change_item_badge' => (int)($row['total_count'] ?? 0) > 0 ? 'Change Item' : '',
        ];
    }
    return $out;
}

function printflow_change_item_apply_to_row(array &$row, ?array $summary): void
{
    if (!is_array($summary)) {
        $row['has_change_item'] = false;
        $row['change_item_active'] = false;
        $row['change_item_count'] = 0;
        $row['change_item_badge'] = '';
        $row['change_item_status'] = '';
        $row['change_item_request_id'] = 0;
        return;
    }

    $row['has_change_item'] = !empty($summary['has_change_item']);
    $row['change_item_active'] = !empty($summary['change_item_active']);
    $row['change_item_count'] = (int)($summary['change_item_count'] ?? 0);
    $row['change_item_badge'] = (string)($summary['change_item_badge'] ?? '');
    $row['change_item_status'] = (string)($summary['change_item_status'] ?? '');
    $row['change_item_request_id'] = (int)($summary['change_item_request_id'] ?? 0);
}
