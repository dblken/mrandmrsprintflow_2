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
        'verification_status' => "VARCHAR(20) NULL DEFAULT NULL",
        'customer_notes' => 'TEXT NULL',
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

    printflow_change_item_ensure_evidence_schema();
}

function printflow_change_item_ensure_evidence_schema(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = (bool) db_execute(
        "CREATE TABLE IF NOT EXISTS change_item_evidence (
            evidence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            change_item_id BIGINT UNSIGNED NOT NULL,
            media_kind VARCHAR(10) NOT NULL,
            storage_path VARCHAR(512) NOT NULL,
            original_name VARCHAR(255) NULL DEFAULT NULL,
            file_size INT UNSIGNED NULL DEFAULT NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (evidence_id),
            KEY idx_change_item_evidence (change_item_id, sort_order, media_kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    return $ready;
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
        'other' => 'Others',
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

function printflow_change_item_column_exists(string $column): bool
{
    return function_exists('db_table_has_column')
        && db_table_has_column('change_item_requests', $column);
}

function printflow_change_item_set_column_value(int $changeItemId, string $column, ?string $value): void
{
    $changeItemId = (int) $changeItemId;
    if ($changeItemId <= 0 || !printflow_change_item_column_exists($column)) {
        return;
    }

    db_execute(
        "UPDATE change_item_requests SET {$column} = ? WHERE change_item_id = ?",
        'si',
        [$value, $changeItemId]
    );
}

function printflow_change_item_format_code(int $changeItemId): string
{
    $changeItemId = (int) $changeItemId;
    return $changeItemId > 0
        ? ('CI-' . str_pad((string) $changeItemId, 6, '0', STR_PAD_LEFT))
        : '';
}

function printflow_change_item_normalize_source_channel(string $value): string
{
    $value = strtolower(trim($value));
    $inStore = ['counter', 'in_store', 'in-store', 'instore', 'walkin', 'walk-in', 'pos', 'staff'];
    if (in_array($value, $inStore, true)) {
        return 'counter';
    }

    return 'customer';
}

function printflow_change_item_is_in_store_channel(string $channel): bool
{
    return printflow_change_item_normalize_source_channel($channel) === 'counter';
}

function printflow_change_item_source_label(string $channel): string
{
    return printflow_change_item_is_in_store_channel($channel) ? 'In-Store' : 'Online';
}

function printflow_change_item_request_source_key(string $channel): string
{
    return printflow_change_item_is_in_store_channel($channel) ? 'IN_STORE' : 'ONLINE';
}

function printflow_change_item_resolve_verification_status(array $row): string
{
    if (printflow_change_item_column_exists('verification_status')) {
        $stored = strtoupper(trim((string)($row['verification_status'] ?? '')));
        if (in_array($stored, ['PENDING', 'VERIFIED', 'REJECTED'], true)) {
            return $stored;
        }
    }

    $requestStatus = printflow_change_item_normalize_status((string)($row['request_status'] ?? ''));
    if ($requestStatus === 'REJECTED') {
        return 'REJECTED';
    }
    if (in_array($requestStatus, ['IN_REWORK', 'COMPLETED'], true)) {
        return 'VERIFIED';
    }
    if (
        printflow_change_item_is_in_store_channel((string)($row['source_channel'] ?? ''))
        && $requestStatus !== 'REQUESTED'
    ) {
        return 'VERIFIED';
    }

    return 'PENDING';
}

function printflow_change_item_verification_status_label(string $status): string
{
    $map = [
        'PENDING' => 'Pending Review',
        'VERIFIED' => 'Verified',
        'REJECTED' => 'Rejected',
    ];
    $key = strtoupper(trim($status));
    return $map[$key] ?? ucwords(strtolower(str_replace('_', ' ', $key)));
}

function printflow_change_item_resolve_change_status(array $row): string
{
    $requestStatus = printflow_change_item_normalize_status((string)($row['request_status'] ?? ''));
    $map = [
        'REQUESTED' => 'PENDING_REVIEW',
        'APPROVED' => 'APPROVED',
        'REJECTED' => 'REJECTED',
        'IN_REWORK' => 'APPROVED',
        'COMPLETED' => 'COMPLETED',
    ];

    return $map[$requestStatus] ?? 'PENDING_REVIEW';
}

function printflow_change_item_change_status_label(string $status): string
{
    $map = [
        'PENDING_REVIEW' => 'Pending Review',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'COMPLETED' => 'Completed',
    ];
    $key = strtoupper(trim(str_replace(['-', ' '], '_', $status)));
    return $map[$key] ?? ucwords(strtolower(str_replace('_', ' ', $key)));
}

function printflow_change_item_is_pending_review(array $row): bool
{
    if ((int)($row['active_flag'] ?? 0) !== 1) {
        return false;
    }

    return printflow_change_item_normalize_status((string)($row['request_status'] ?? '')) === 'REQUESTED';
}

function printflow_change_item_normalize_status(string $status): string
{
    $normalized = strtoupper(trim(str_replace(['-', ' '], '_', $status)));
    return trim($normalized, '_');
}

function printflow_change_item_status_label(string $status): string
{
    $map = [
        'REQUESTED' => 'Pending Review',
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
    $changeItemId = (int)($row['change_item_id'] ?? 0);
    $sourceChannel = (string)($row['source_channel'] ?? '');
    $verificationStatus = printflow_change_item_resolve_verification_status($row);
    $changeStatus = printflow_change_item_resolve_change_status($row);

    $record = [
        'id' => $changeItemId,
        'change_item_code' => printflow_change_item_format_code($changeItemId),
        'sequence_no' => (int)($row['sequence_no'] ?? 1),
        'status' => $status,
        'status_label' => printflow_change_item_status_label($status),
        'change_status' => $changeStatus,
        'change_status_label' => printflow_change_item_change_status_label($changeStatus),
        'verification_status' => $verificationStatus,
        'verification_status_label' => printflow_change_item_verification_status_label($verificationStatus),
        'request_source' => printflow_change_item_request_source_key($sourceChannel),
        'request_source_label' => printflow_change_item_source_label($sourceChannel),
        'original_order_id' => (int)($row['order_id'] ?? 0),
        'original_order_item_id' => (int)($row['order_item_id'] ?? 0),
        'reason_code' => (string)($row['reason_code'] ?? ''),
        'reason' => (string)($row['reason_label'] ?? ''),
        'description' => (string)($row['issue_description'] ?? ''),
        'issue_description' => (string)($row['issue_description'] ?? ''),
        'customer_notes' => (string)($row['customer_notes'] ?? ''),
        'staff_notes' => (string)($row['staff_notes'] ?? ''),
        'rejection_reason' => (string)($row['rejection_reason'] ?? ''),
        'source_channel' => $sourceChannel,
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
        'is_pending_review' => printflow_change_item_is_pending_review($row),
        'evidence_photo_count' => 0,
        'has_video_evidence' => false,
        'has_customer_evidence' => trim((string)($row['proof_path'] ?? '')) !== '',
    ];
    if ($changeItemId > 0) {
        $evidence = printflow_change_item_evidence_for_api($changeItemId, $row);
        $record['evidence'] = $evidence;
        $record['evidence_photo_count'] = count($evidence['photos'] ?? []);
        $record['has_video_evidence'] = !empty($evidence['video']);
        $record['has_customer_evidence'] = $record['evidence_photo_count'] > 0
            || $record['has_video_evidence']
            || trim((string)($row['proof_path'] ?? '')) !== '';
    }

    return $record;
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
        'file_size' => (int)($file['size'] ?? 0),
        'media_kind' => 'photo',
    ];
}

function printflow_change_item_storage_path_to_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $base = function_exists('pf_app_base_path') ? rtrim((string) pf_app_base_path(), '') : '';
    if (strpos($path, '/uploads/') === 0) {
        return $base . $path;
    }
    return $base . (strpos($path, '/') === 0 ? $path : '/' . $path);
}

function printflow_change_item_collect_uploaded_files(string $field): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $bucket = $_FILES[$field];
    if (!isset($bucket['name'])) {
        return [];
    }

    if (!is_array($bucket['name'])) {
        if ((int)($bucket['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [[
            'name' => (string)($bucket['name'] ?? ''),
            'type' => (string)($bucket['type'] ?? ''),
            'tmp_name' => (string)($bucket['tmp_name'] ?? ''),
            'error' => (int)($bucket['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($bucket['size'] ?? 0),
        ]];
    }

    $files = [];
    $count = count($bucket['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = (int)($bucket['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => (string)($bucket['name'][$i] ?? ''),
            'type' => (string)($bucket['type'][$i] ?? ''),
            'tmp_name' => (string)($bucket['tmp_name'][$i] ?? ''),
            'error' => $error,
            'size' => (int)($bucket['size'][$i] ?? 0),
        ];
    }

    return $files;
}

function printflow_change_item_collect_single_upload(string $field): ?array
{
    $files = printflow_change_item_collect_uploaded_files($field);
    if ($files === []) {
        return null;
    }
    return $files[0];
}

function printflow_change_item_validate_customer_evidence(array $photoFiles, ?array $videoFile): void
{
    $photoFiles = array_values(array_filter($photoFiles, static function (array $file): bool {
        return (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }));

    if ($photoFiles === [] && $videoFile === null) {
        throw new InvalidArgumentException('Please upload at least one photo or one video as proof.');
    }
    if (count($photoFiles) > 5) {
        throw new InvalidArgumentException('You can upload up to 5 photos.');
    }

    foreach ($photoFiles as $file) {
        printflow_change_item_assert_upload_ok($file, 'photo');
    }
    if ($videoFile !== null) {
        printflow_change_item_assert_upload_ok($videoFile, 'video');
    }
}

function printflow_change_item_assert_upload_ok(array $file, string $kind): void
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload failed. Please try again.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Invalid upload.');
    }

    $size = (int)($file['size'] ?? 0);
    $original = trim((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    if ($kind === 'photo') {
        if ($size > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Image is too large. Maximum size is 5 MB.');
        }
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt, true)) {
            throw new InvalidArgumentException('Unsupported image format. Please use JPG, PNG, or WEBP.');
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    } else {
        if ($size > 20 * 1024 * 1024) {
            throw new InvalidArgumentException('Video is too large. Maximum size is 20 MB.');
        }
        $allowedExt = ['mp4', 'mov', 'webm'];
        if (!in_array($ext, $allowedExt, true)) {
            throw new InvalidArgumentException('Unsupported video format. Please use MP4, MOV, or WEBM.');
        }
        $allowedMimes = ['video/mp4', 'video/quicktime', 'video/webm'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException($kind === 'photo'
            ? 'Unsupported image format. Please use JPG, PNG, or WEBP.'
            : 'Unsupported video format. Please use MP4, MOV, or WEBM.');
    }
}

function printflow_change_item_upload_customer_photo(array $file, int $orderId): array
{
    printflow_change_item_assert_upload_ok($file, 'photo');
    return printflow_change_item_store_upload($file, $orderId, 'photo');
}

function printflow_change_item_upload_customer_video(array $file, int $orderId): array
{
    printflow_change_item_assert_upload_ok($file, 'video');
    return printflow_change_item_store_upload($file, $orderId, 'video');
}

function printflow_change_item_store_upload(array $file, int $orderId, string $kind): array
{
    $original = trim((string)($file['name'] ?? ($kind === 'photo' ? 'photo.jpg' : 'video.mp4')));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($kind === 'photo') {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    } else {
        $allowed = ['mp4', 'mov', 'webm'];
    }
    if (!in_array($ext, $allowed, true)) {
        $ext = $kind === 'photo' ? 'jpg' : 'mp4';
    }

    $uploadRoot = dirname(__DIR__) . '/uploads/change_items';
    if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
        throw new RuntimeException('Unable to store proof upload.');
    }

    $prefix = $kind === 'video' ? 'change-item-video' : 'change-item';
    $storedName = $prefix . '-' . (int) $orderId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadRoot . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save upload.');
    }

    return [
        'success' => true,
        'path' => '/uploads/change_items/' . $storedName,
        'original_name' => $original,
        'file_size' => (int)($file['size'] ?? 0),
        'media_kind' => $kind === 'video' ? 'video' : 'photo',
    ];
}

function printflow_change_item_attach_evidence(int $changeItemId, array $photoUploads, ?array $videoUpload = null): void
{
    if ($changeItemId <= 0 || !printflow_change_item_ensure_evidence_schema()) {
        return;
    }

    $sort = 0;
    foreach ($photoUploads as $upload) {
        $path = trim((string)($upload['path'] ?? ''));
        if ($path === '') {
            continue;
        }
        db_execute(
            'INSERT INTO change_item_evidence (change_item_id, media_kind, storage_path, original_name, file_size, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)',
            'isssii',
            [
                $changeItemId,
                'photo',
                $path,
                trim((string)($upload['original_name'] ?? '')) ?: null,
                (int)($upload['file_size'] ?? 0) ?: null,
                $sort,
            ]
        );
        $sort++;
    }

    if ($videoUpload !== null) {
        $path = trim((string)($videoUpload['path'] ?? ''));
        if ($path !== '') {
            db_execute(
                'INSERT INTO change_item_evidence (change_item_id, media_kind, storage_path, original_name, file_size, sort_order)
                 VALUES (?, ?, ?, ?, ?, 0)',
                'isssi',
                [
                    $changeItemId,
                    'video',
                    $path,
                    trim((string)($videoUpload['original_name'] ?? '')) ?: null,
                    (int)($videoUpload['file_size'] ?? 0) ?: null,
                ]
            );
        }
    }
}

function printflow_change_item_evidence_rows(int $changeItemId): array
{
    if ($changeItemId <= 0 || !printflow_change_item_ensure_evidence_schema()) {
        return [];
    }

    return db_query(
        'SELECT evidence_id, media_kind, storage_path, original_name, file_size, sort_order
         FROM change_item_evidence
         WHERE change_item_id = ?
         ORDER BY media_kind ASC, sort_order ASC, evidence_id ASC',
        'i',
        [$changeItemId]
    ) ?: [];
}

function printflow_change_item_evidence_for_api(int $changeItemId, array $requestRow = []): array
{
    $photos = [];
    $video = null;
    $rows = printflow_change_item_evidence_rows($changeItemId);

    foreach ($rows as $row) {
        $kind = strtolower((string)($row['media_kind'] ?? ''));
        $path = trim((string)($row['storage_path'] ?? ''));
        if ($path === '') {
            continue;
        }
        $payload = [
            'id' => (int)($row['evidence_id'] ?? 0),
            'url' => printflow_change_item_storage_path_to_url($path),
            'path' => $path,
            'original_name' => (string)($row['original_name'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
        ];
        if ($kind === 'video') {
            $video = $payload + ['mime' => printflow_change_item_guess_video_mime($path)];
        } else {
            $photos[] = $payload;
        }
    }

    if ($photos === [] && $video === null) {
        $legacyPath = trim((string)($requestRow['proof_path'] ?? ''));
        if ($legacyPath !== '' && printflow_change_item_proof_is_image($requestRow)) {
            $photos[] = [
                'id' => 0,
                'url' => printflow_change_item_storage_path_to_url($legacyPath),
                'path' => $legacyPath,
                'original_name' => (string)($requestRow['proof_original_name'] ?? ''),
                'file_size' => 0,
            ];
        }
    }

    return [
        'photos' => $photos,
        'video' => $video,
    ];
}

function printflow_change_item_guess_video_mime(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];
    return $map[$ext] ?? 'video/mp4';
}

function printflow_change_item_format_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
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
    $sourceChannel = printflow_change_item_normalize_source_channel((string)($input['source_channel'] ?? 'customer'));
    $autoApprove = !empty($input['auto_approve']) || printflow_change_item_is_in_store_channel($sourceChannel);

    $eligibility = printflow_change_item_order_is_eligible($orderId, $customerId);
    if (empty($eligibility['eligible'])) {
        throw new InvalidArgumentException((string)($eligibility['reason'] ?? 'This order is not eligible for a Change Item.'));
    }

    $reasonCode = printflow_change_item_reason_code((string)($input['reason_code'] ?? ''));
    $reasonFallback = trim((string)($input['reason_label'] ?? ''));
    $reasonLabel = printflow_change_item_reason_label($reasonCode, $reasonFallback);
    $description = trim((string)($input['issue_description'] ?? ''));
    $customerNotes = trim((string)($input['customer_notes'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException('Please describe the issue.');
    }
    if (strlen($description) > 500) {
        throw new InvalidArgumentException('Issue description must be 500 characters or fewer.');
    }
    $staffNotes = trim((string)($input['staff_notes'] ?? ''));
    if (strlen($staffNotes) > 2000) {
        throw new InvalidArgumentException('Staff notes must be 2000 characters or fewer.');
    }
    if (strlen($customerNotes) > 2000) {
        throw new InvalidArgumentException('Customer notes must be 2000 characters or fewer.');
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

        if (!$autoApprove) {
            printflow_change_item_set_column_value($changeItemId, 'verification_status', 'PENDING');
        }
        if ($customerNotes !== '') {
            printflow_change_item_set_column_value($changeItemId, 'customer_notes', $customerNotes);
        }

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
    printflow_change_item_set_column_value((int) $changeItemId, 'verification_status', 'VERIFIED');

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
        printflow_change_item_set_column_value((int) $changeItemId, 'verification_status', 'REJECTED');

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

/**
 * Dashboard rows for pending online Change Item review.
 * Ensures completed orders with pending requests appear even when pagination would omit them.
 *
 * @return array<int,array<string,mixed>>
 */
function printflow_change_item_list_pending_dashboard_rows(?int $branchId = null, string $listSource = 'all'): array
{
    if (!printflow_change_item_ensure_schema()) {
        return [];
    }

    $sql = "SELECT
                o.order_id AS id,
                o.order_id,
                o.customer_id,
                c.first_name,
                c.last_name,
                c.profile_picture AS customer_profile_picture,
                c.customer_type,
                c.transaction_count,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_full_name,
                TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_contact,
                'ORDER' AS order_type,
                CASE
                    WHEN o.status IN ('Pending', 'Pending Review', 'Pending Approval', 'For Revision') THEN 'PENDING'
                    WHEN o.status IN ('Design Approved', 'Approved') THEN 'APPROVED'
                    WHEN o.status IN ('Pending Verification', 'Downpayment Submitted', 'To Verify') THEN 'VERIFY_PAY'
                    WHEN o.status IN ('To Pay') THEN 'TO_PAY'
                    WHEN o.status = 'Payment Confirmed' THEN 'PAYMENT_CONFIRMED'
                    WHEN o.status IN ('Paid – In Process', 'Paid - In Process', 'Processing', 'In Production', 'Printing') THEN 'IN_PRODUCTION'
                    WHEN o.status = 'Change Item Request' THEN 'CHANGE_ITEM_REQUEST'
                    WHEN o.status = 'Ready for Pickup' THEN 'TO_RECEIVE'
                    WHEN o.status = 'Completed' THEN 'COMPLETED'
                    WHEN o.status = 'Rejected' THEN 'REJECTED'
                    WHEN o.status = 'Cancelled' THEN 'CANCELLED'
                    ELSE 'COMPLETED'
                END AS status,
                'VERIFIED' AS payment_proof_status,
                'NO' AS payment_status,
                '' AS materials,
                COALESCE(ci.requested_at, o.order_date) AS created_at,
                GREATEST(COALESCE(ci.requested_at, o.updated_at), o.updated_at) AS updated_at,
                o.order_date,
                NULL AS due_date,
                NULL AS priority,
                o.total_amount AS estimated_total,
                (SELECT MIN(jo.id) FROM job_orders jo WHERE jo.order_id = o.order_id) AS job_order_id,
                COALESCE(o.order_source, 'customer') AS order_source,
                ci.change_item_id,
                ci.requested_at AS change_item_requested_at,
                ci.reason_label AS change_item_reason_label
            FROM change_item_requests ci
            INNER JOIN orders o ON o.order_id = ci.order_id
            LEFT JOIN customers c ON c.customer_id = o.customer_id
            WHERE ci.active_flag = 1
              AND UPPER(REPLACE(REPLACE(ci.request_status, '-', ' '), ' ', '')) = 'REQUESTED'
              AND (o.order_type IS NULL OR o.order_type = 'product' OR o.order_type = 'custom')
              AND COALESCE(o.order_source, '') NOT IN ('pos_merged', 'pos_draft')";

    $types = '';
    $params = [];
    if ($branchId !== null) {
        $sql .= ' AND o.branch_id = ?';
        $types = 'i';
        $params[] = $branchId;
    }
    $sql .= ' ORDER BY ci.requested_at DESC, ci.change_item_id DESC';

    $rows = $types !== ''
        ? (db_query($sql, $types, $params) ?: [])
        : (db_query($sql) ?: []);

    if ($rows === []) {
        return [];
    }

    $orderIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['order_id'] ?? 0),
        $rows
    ))));
    $payloads = class_exists('JobOrderService')
        ? JobOrderService::getStoreOrderItemSummariesBatch($orderIds, true)
        : [];
    $summaries = printflow_change_item_batch_summaries($orderIds);

    $out = [];
    foreach ($rows as $row) {
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        $row['readiness'] = 'READY';
        $row['estimated_cost'] = 0;
        $row['order_code'] = function_exists('printflow_format_order_code')
            ? printflow_format_order_code($orderId, '')
            : ('ORD-' . $orderId);

        $payload = $payloads[$orderId] ?? null;
        if (empty($payload) || empty($payload['items'])) {
            continue;
        }
        if (class_exists('JobOrderService')) {
            JobOrderService::enrichStaffJobRowFromStorePayload($row, $payload);
        } elseif (!empty($row['change_item_reason_label'])) {
            $row['service_type'] = (string)($payload['service_type'] ?? 'Custom Order');
            $row['job_title'] = (string)$row['change_item_reason_label'];
        }

        printflow_change_item_apply_to_row($row, $summaries[$orderId] ?? null);
        $out[] = $row;
    }

    return $out;
}

function printflow_change_item_merge_pending_dashboard_rows(array $rows, ?int $branchId = null, string $listSource = 'all'): array
{
    $pendingRows = printflow_change_item_list_pending_dashboard_rows($branchId, $listSource);
    if ($pendingRows === []) {
        return $rows;
    }

    if ($listSource !== 'all' && function_exists('jo_api_source_matches') && function_exists('jo_api_resolve_order_sources_batch')) {
        $sourceMap = jo_api_resolve_order_sources_batch($pendingRows);
        $pendingRows = array_values(array_filter(
            $pendingRows,
            static function (array $row) use ($sourceMap, $listSource): bool {
                $orderId = (int)($row['order_id'] ?? 0);
                $source = $sourceMap[$orderId] ?? (string)($row['order_source'] ?? 'customer');
                return jo_api_source_matches($source, $listSource);
            }
        ));
    }

    if ($pendingRows === []) {
        return $rows;
    }

    $indexByOrderId = [];
    foreach ($rows as $idx => $row) {
        $oid = (int)($row['order_id'] ?? 0);
        if ($oid > 0) {
            $indexByOrderId[$oid] = $idx;
        }
    }

    foreach ($pendingRows as $pendingRow) {
        $oid = (int)($pendingRow['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if (isset($indexByOrderId[$oid])) {
            $existingIdx = $indexByOrderId[$oid];
            $existingTs = strtotime((string)($rows[$existingIdx]['updated_at'] ?? '')) ?: 0;
            $pendingTs = strtotime((string)($pendingRow['updated_at'] ?? '')) ?: 0;
            if ($pendingTs >= $existingTs) {
                $rows[$existingIdx]['updated_at'] = $pendingRow['updated_at'];
            }
            printflow_change_item_apply_to_row(
                $rows[$existingIdx],
                printflow_change_item_batch_summaries([$oid])[$oid] ?? null
            );
            continue;
        }
        $rows[] = $pendingRow;
    }

    usort($rows, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['updated_at'] ?? $a['created_at'] ?? $a['order_date'] ?? 'now')) ?: 0;
        $tb = strtotime((string)($b['updated_at'] ?? $b['created_at'] ?? $b['order_date'] ?? 'now')) ?: 0;
        return $tb <=> $ta;
    });

    return $rows;
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
                MAX(CASE WHEN active_flag = 1 THEN change_item_id ELSE NULL END) AS active_change_item_id,
                MAX(CASE WHEN active_flag = 1 THEN source_channel ELSE NULL END) AS active_source_channel
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
        $activeStatus = (string)($row['active_status'] ?? '');
        $pendingReview = (int)($row['active_count'] ?? 0) > 0
            && printflow_change_item_normalize_status($activeStatus) === 'REQUESTED';
        $activeSource = (string)($row['active_source_channel'] ?? '');

        $out[$oid] = [
            'has_change_item' => (int)($row['total_count'] ?? 0) > 0,
            'change_item_count' => (int)($row['total_count'] ?? 0),
            'change_item_active' => (int)($row['active_count'] ?? 0) > 0,
            'change_item_status' => $activeStatus,
            'change_item_request_id' => (int)($row['active_change_item_id'] ?? 0),
            'change_item_badge' => (int)($row['total_count'] ?? 0) > 0 ? 'Change Item' : '',
            'change_item_pending_review' => $pendingReview,
            'change_item_code' => printflow_change_item_format_code((int)($row['active_change_item_id'] ?? 0)),
            'change_item_request_source' => printflow_change_item_request_source_key($activeSource),
            'change_item_request_source_label' => printflow_change_item_source_label($activeSource),
            'change_item_verification_status' => $pendingReview ? 'PENDING' : (
                printflow_change_item_normalize_status($activeStatus) === 'REJECTED' ? 'REJECTED' : (
                    (int)($row['active_count'] ?? 0) > 0 ? 'VERIFIED' : ''
                )
            ),
            'change_item_change_status' => $pendingReview ? 'PENDING_REVIEW' : (
                printflow_change_item_normalize_status($activeStatus) === 'REJECTED' ? 'REJECTED' : (
                    printflow_change_item_normalize_status($activeStatus) === 'COMPLETED' ? 'COMPLETED' : (
                        (int)($row['active_count'] ?? 0) > 0 ? 'APPROVED' : ''
                    )
                )
            ),
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
        $row['change_item_pending_review'] = false;
        $row['change_item_code'] = '';
        $row['change_item_request_source'] = '';
        $row['change_item_request_source_label'] = '';
        $row['change_item_verification_status'] = '';
        $row['change_item_change_status'] = '';
        return;
    }

    $row['has_change_item'] = !empty($summary['has_change_item']);
    $row['change_item_active'] = !empty($summary['change_item_active']);
    $row['change_item_count'] = (int)($summary['change_item_count'] ?? 0);
    $row['change_item_badge'] = (string)($summary['change_item_badge'] ?? '');
    $row['change_item_status'] = (string)($summary['change_item_status'] ?? '');
    $row['change_item_request_id'] = (int)($summary['change_item_request_id'] ?? 0);
    $row['change_item_pending_review'] = !empty($summary['change_item_pending_review']);
    $row['change_item_code'] = (string)($summary['change_item_code'] ?? '');
    $row['change_item_request_source'] = (string)($summary['change_item_request_source'] ?? '');
    $row['change_item_request_source_label'] = (string)($summary['change_item_request_source_label'] ?? '');
    $row['change_item_verification_status'] = (string)($summary['change_item_verification_status'] ?? '');
    $row['change_item_change_status'] = (string)($summary['change_item_change_status'] ?? '');
}
