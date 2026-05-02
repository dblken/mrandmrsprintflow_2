<?php
/**
 * Job Order Service
 * Handles life cycle of job orders (create, assign, deduct).
 * PrintFlow v2
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/InventoryManager.php';
require_once __DIR__ . '/RollService.php';
require_once __DIR__ . '/NotificationService.php';

class JobOrderService {
    private static array $columnExistsCache = [];

    private static function tableHasColumn(string $table, string $column): bool {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$columnExistsCache)) {
            return self::$columnExistsCache[$cacheKey];
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return self::$columnExistsCache[$cacheKey] = false;
        }

        $rows = db_query(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            'ss',
            [$safeTable, $safeColumn]
        ) ?: [];
        return self::$columnExistsCache[$cacheKey] = !empty($rows);
    }

    private static function normalizeWorkflowStatus(string $status): string {
        $normalized = strtoupper(trim($status));
        $normalized = str_replace(['–', '-'], '_', $normalized);
        $normalized = preg_replace('/\s+/', '_', $normalized);
        return trim((string)$normalized, '_');
    }

    private static function resolveBranchIdForOrderData(array $orderData): ?int {
        $branchId = (int)($orderData['branch_id'] ?? 0);
        if ($branchId > 0) {
            return $branchId;
        }

        $linkedOrderId = (int)($orderData['order_id'] ?? 0);
        if ($linkedOrderId > 0) {
            $row = db_query(
                "SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1",
                'i',
                [$linkedOrderId]
            );
            $resolved = (int)($row[0]['branch_id'] ?? 0);
            if ($resolved > 0) {
                return $resolved;
            }
        }

        return null;
    }

    private static function getJobBranchId(int $orderId): ?int {
        $row = db_query(
            "SELECT COALESCE(jo.branch_id, ord.branch_id) AS branch_id
             FROM job_orders jo
             LEFT JOIN orders ord ON ord.order_id = jo.order_id
             WHERE jo.id = ?
             LIMIT 1",
            'i',
            [$orderId]
        );
        $branchId = (int)($row[0]['branch_id'] ?? 0);
        return $branchId > 0 ? $branchId : null;
    }

    private static function getLinkedStoreOrderId(int $jobId): int {
        $row = db_query(
            "SELECT order_id FROM job_orders WHERE id = ? LIMIT 1",
            'i',
            [$jobId]
        );
        return (int)($row[0]['order_id'] ?? 0);
    }

    private static function getScopedMaterials(int $jobId, bool $onlyUndeducted = false): array {
        $storeOrderId = self::getLinkedStoreOrderId($jobId);
        $sql = "SELECT *
                FROM job_order_materials
                WHERE (job_order_id = ?";
        $params = [$jobId];
        $types = 'i';

        if ($storeOrderId > 0 && self::tableHasColumn('job_order_materials', 'std_order_id')) {
            $sql .= " OR (std_order_id = ? AND (job_order_id IS NULL OR job_order_id = 0))";
            $params[] = $storeOrderId;
            $types .= 'i';
        }

        $sql .= ")";

        if ($onlyUndeducted) {
            $sql .= " AND (deducted_at IS NULL OR deducted_at = '' OR deducted_at = '0000-00-00 00:00:00')";
        }

        return db_query($sql, $types, $params) ?: [];
    }

    private static function getScopedInkUsage(int $jobId): array {
        $storeOrderId = self::getLinkedStoreOrderId($jobId);
        $sql = "SELECT *
                FROM job_order_ink_usage
                WHERE (job_order_id = ?";
        $params = [$jobId];
        $types = 'i';

        if ($storeOrderId > 0 && self::tableHasColumn('job_order_ink_usage', 'std_order_id')) {
            $sql .= " OR (std_order_id = ? AND (job_order_id IS NULL OR job_order_id = 0))";
            $params[] = $storeOrderId;
            $types .= 'i';
        }

        $sql .= ")";

        return db_query($sql, $types, $params) ?: [];
    }

    private static function syncStoreOrderAssignmentsIfNeeded(int $storeOrderId, array $options = []): void {
        if ($storeOrderId <= 0) {
            return;
        }

        $jobs = db_query(
            "SELECT id, status
             FROM job_orders
             WHERE order_id = ?
             ORDER BY id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];

        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            $status = self::normalizeWorkflowStatus((string)($job['status'] ?? ''));
            if ($jobId <= 0) {
                continue;
            }
            if (!in_array($status, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING', 'TO_RECEIVE', 'COMPLETED'], true)) {
                continue;
            }

            self::processDeductions($jobId, $options);
        }
    }

    /**
     * Ensure inventory deductions are applied for store orders that are already
     * in production-ready states (idempotent: only undeducted rows are processed).
     */
    public static function ensureStoreOrderProductionDeductions(int $storeOrderId): void {
        if ($storeOrderId <= 0) {
            return;
        }

        $orderRows = db_query(
            "SELECT status FROM orders WHERE order_id = ? LIMIT 1",
            'i',
            [$storeOrderId]
        ) ?: [];
        $orderStatus = self::normalizeWorkflowStatus((string)($orderRows[0]['status'] ?? ''));
        if (!in_array($orderStatus, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING', 'TO_RECEIVE', 'COMPLETED'], true)) {
            return;
        }

        self::ensureJobsForStoreOrder($storeOrderId);

        $jobs = db_query(
            "SELECT id
             FROM job_orders
             WHERE order_id = ?
               AND status <> 'CANCELLED'
             ORDER BY id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];

        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }
            self::processDeductions($jobId, ['materials' => true, 'inks' => false]);
        }
    }

    /**
     * Force production-stage deductions from the context of a linked job row.
     * This bypasses stale order status text by trusting the live job status that
     * currently drives staff "In Production" workflow.
     */
    public static function ensureProductionDeductionsForJob(int $jobId): void {
        if ($jobId <= 0) {
            return;
        }

        $jobRows = db_query(
            "SELECT order_id, status
             FROM job_orders
             WHERE id = ?
             LIMIT 1",
            'i',
            [$jobId]
        ) ?: [];
        if (empty($jobRows)) {
            return;
        }

        $status = self::normalizeWorkflowStatus((string)($jobRows[0]['status'] ?? ''));
        if (!in_array($status, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING', 'TO_RECEIVE', 'COMPLETED'], true)) {
            return;
        }

        // Deduct for the active job record itself.
        self::processDeductions($jobId, ['materials' => true, 'inks' => false]);

        $storeOrderId = (int)($jobRows[0]['order_id'] ?? 0);
        if ($storeOrderId <= 0) {
            return;
        }

        // Also run on sibling jobs for the same order to avoid split-row drift.
        $siblingRows = db_query(
            "SELECT id
             FROM job_orders
             WHERE order_id = ?
               AND status <> 'CANCELLED'
             ORDER BY id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];
        foreach ($siblingRows as $sibling) {
            $siblingId = (int)($sibling['id'] ?? 0);
            if ($siblingId <= 0 || $siblingId === $jobId) {
                continue;
            }
            self::processDeductions($siblingId, ['materials' => true, 'inks' => false]);
        }
    }

    private static function cleanupLegacyAutoAssignedMaterials(int $jobId, int $storeOrderId = 0, string $serviceType = ''): void {
        if ($jobId <= 0 || $storeOrderId <= 0 || $serviceType === '' || !self::tableHasColumn('job_order_materials', 'std_order_id')) {
            return;
        }

        $orderRows = db_query(
            "SELECT order_source, status FROM orders WHERE order_id = ? LIMIT 1",
            'i',
            [$storeOrderId]
        ) ?: [];
        $orderSource = strtolower(trim((string)($orderRows[0]['order_source'] ?? '')));
        $orderStatus = strtolower(trim((string)($orderRows[0]['status'] ?? '')));
        if (!in_array($orderSource, ['pos', 'walk-in'], true)) {
            return;
        }
        if (in_array($orderStatus, ['processing', 'in production', 'printing', 'ready for pickup', 'completed'], true)) {
            return;
        }

        $rules = db_query(
            "SELECT item_id FROM service_material_rules WHERE service_type = ?",
            's',
            [$serviceType]
        ) ?: [];
        $ruleItemIds = array_values(array_filter(array_unique(array_map(static fn(array $row): int => (int)($row['item_id'] ?? 0), $rules))));
        if (empty($ruleItemIds)) {
            return;
        }

        $materials = db_query(
            "SELECT id, item_id, roll_id, notes, metadata, deducted_at
             FROM job_order_materials
             WHERE (job_order_id = ? OR (std_order_id = ? AND (job_order_id IS NULL OR job_order_id = 0)))",
            'ii',
            [$jobId, $storeOrderId]
        ) ?: [];
        if (empty($materials)) {
            return;
        }

        $candidateIds = [];
        foreach ($materials as $material) {
            $itemId = (int)($material['item_id'] ?? 0);
            $rollId = (int)($material['roll_id'] ?? 0);
            $notes = trim((string)($material['notes'] ?? ''));
            $metadata = trim((string)($material['metadata'] ?? ''));
            $deductedAt = trim((string)($material['deducted_at'] ?? ''));
            $looksAutoAssigned =
                in_array($itemId, $ruleItemIds, true) &&
                $rollId === 0 &&
                $notes === '' &&
                ($metadata === '' || strtolower($metadata) === 'null') &&
                $deductedAt === '';

            if ($looksAutoAssigned) {
                $candidateIds[] = (int)$material['id'];
            }
        }

        if (empty($candidateIds) || count($candidateIds) !== count($materials)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $types = str_repeat('i', count($candidateIds));
        db_execute("DELETE FROM job_order_materials WHERE id IN ($placeholders)", $types, $candidateIds);
        error_log(sprintf(
            'PrintFlow Material Cleanup: removed %d legacy auto-assigned rows for job %d / order %d',
            count($candidateIds),
            $jobId,
            $storeOrderId
        ));
    }

    private static function purgePreApprovalPosAutoAssignments(int $storeOrderId, array &$materials): void {
        if ($storeOrderId <= 0 || empty($materials)) {
            return;
        }

        $orderRows = db_query(
            "SELECT order_source, status FROM orders WHERE order_id = ? LIMIT 1",
            'i',
            [$storeOrderId]
        ) ?: [];
        $orderSource = strtolower(trim((string)($orderRows[0]['order_source'] ?? '')));
        $orderStatus = strtolower(trim((string)($orderRows[0]['status'] ?? '')));
        if (!in_array($orderSource, ['pos', 'walk-in'], true) || $orderStatus !== 'approved') {
            return;
        }

        $invalidIds = [];
        $filtered = [];
        foreach ($materials as $material) {
            $metadata = $material['metadata'] ?? null;
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true);
            }
            $manualAssignment = is_array($metadata) && !empty($metadata['manual_assignment']);
            $deductedAt = trim((string)($material['deducted_at'] ?? ''));

            if (!$manualAssignment && $deductedAt === '') {
                $invalidIds[] = (int)($material['id'] ?? 0);
                continue;
            }

            $material['metadata'] = $metadata;
            $filtered[] = $material;
        }

        $invalidIds = array_values(array_filter($invalidIds));
        if (!empty($invalidIds)) {
            $placeholders = implode(',', array_fill(0, count($invalidIds), '?'));
            db_execute(
                "DELETE FROM job_order_materials WHERE id IN ($placeholders) AND deducted_at IS NULL",
                str_repeat('i', count($invalidIds)),
                $invalidIds
            );
            error_log(sprintf(
                'PrintFlow POS cleanup: removed %d pre-approval auto-assigned material rows from order %d',
                count($invalidIds),
                $storeOrderId
            ));
        }

        $materials = $filtered;
    }

    private static function itemUsesLiters(array $item): bool {
        $uom = strtolower(trim((string)($item['unit_of_measure'] ?? '')));
        return $uom === 'l' || $uom === 'liter' || $uom === 'liters' || (strpos($uom, 'liter') !== false) || (strpos($uom, '(l)') !== false);
    }

    private static function convertInkMlToItemUom(float $quantityMl, array $item): float {
        if ($quantityMl <= 0) return 0.0;
        return self::itemUsesLiters($item) ? ($quantityMl / 1000) : $quantityMl;
    }

    private static function formatInkNeedForError(float $quantityMl, array $item): string {
        if (self::itemUsesLiters($item)) {
            $liters = $quantityMl / 1000;
            return number_format($liters, 4) . ' L (' . number_format($quantityMl, 0) . ' ml)';
        }
        return number_format($quantityMl, 4) . ' ' . ($item['unit_of_measure'] ?? 'ml');
    }

    /**
     * Create a new job order with materials.
     */
    public static function createOrder($orderData, $materials = []) {
        global $conn;
        
        $conn->begin_transaction();
        try {
            $branchId = self::resolveBranchIdForOrderData((array)$orderData) ?? (int)($_SESSION['branch_id'] ?? 0);
            // 1. Insert Job Order (with explicit PENDING status)
            $requiredPayment = self::calculateRequiredPayment($orderData['customer_id'], $orderData['estimated_total']);
            $sql = "INSERT INTO job_orders (order_id, customer_id, branch_id, job_title, service_type, width_ft, height_ft, quantity, total_sqft, price_per_sqft, price_per_piece, estimated_total, required_payment, notes, due_date, priority, artwork_path, status, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiisssddiddddssssi", 
                $orderData['order_id'],
                $orderData['customer_id'], 
                $branchId,
                $orderData['job_title'],
                $orderData['service_type'], 
                $orderData['width_ft'], 
                $orderData['height_ft'], 
                $orderData['quantity'], 
                $orderData['total_sqft'], 
                $orderData['price_per_sqft'], 
                $orderData['price_per_piece'], 
                $orderData['estimated_total'], 
                $requiredPayment,
                $orderData['notes'], 
                $orderData['due_date'],
                $orderData['priority'],
                $orderData['artwork_path'], 
                $orderData['created_by']
            );
            
            if (!$stmt->execute()) throw new Exception("Failed to create job order.");
            $orderId = $stmt->insert_id;
            $stmt->close();

            // 2. Insert Required Materials (placeholders + capture unit cost)
            if (!empty($materials)) {
                $materialSql = "INSERT INTO job_order_materials (job_order_id, item_id, quantity, uom, computed_required_length_ft, unit_cost_at_assignment) VALUES (?, ?, ?, ?, ?, ?)";
                $mStmt = $conn->prepare($materialSql);
                foreach ($materials as $m) {
                    $item = InventoryManager::getItem($m['item_id']);
                    $cost = $item['unit_cost'] ?? 0;
                    $mStmt->bind_param("iidsdd", $orderId, $m['item_id'], $m['quantity'], $m['uom'], $m['computed_len'], $cost);
                    $mStmt->execute();
                }
                $mStmt->close();
            }

            $conn->commit();
            return $orderId;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /**
     * Same service_type mapping as customer/checkout.php when creating jobs from cart line items.
     */
    public static function inferServiceTypeFromProduct(string $category, string $name): string {
        $cat_lower = strtolower($category . ' ' . $name);
        if (strpos($cat_lower, 'tarpaulin') !== false) {
            return 'Tarpaulin Printing';
        }
        if (strpos($cat_lower, 't-shirt') !== false || strpos($cat_lower, 'shirt') !== false) {
            return 'T-shirt Printing';
        }
        if (strpos($cat_lower, 'reflectorized') !== false) {
            return 'Reflectorized (Subdivision Stickers/Signages)';
        }
        if (strpos($cat_lower, 'transparent') !== false) {
            return 'Transparent Stickers';
        }
        if (strpos($cat_lower, 'glass') !== false || strpos($cat_lower, 'wall') !== false || strpos($cat_lower, 'frosted') !== false) {
            return 'Glass Stickers / Wall / Frosted Stickers';
        }
        if (strpos($cat_lower, 'sintraboard') !== false && (strpos($cat_lower, 'standee') !== false || strpos($cat_lower, 'stand') !== false)) {
            return 'Sintraboard Standees';
        }
        if (strpos($cat_lower, 'sintraboard') !== false) {
            return 'Stickers on Sintraboard';
        }
        if (strpos($cat_lower, 'sticker') !== false || strpos($cat_lower, 'decal') !== false) {
            return 'Decals/Stickers (Print/Cut)';
        }
        if (strpos($cat_lower, 'souvenir') !== false) {
            return 'Souvenirs';
        }
        if (strpos($cat_lower, 'layout') !== false) {
            return 'Layouts';
        }
        return 'Tarpaulin Printing';
    }

    /**
     * If a store order has line items but no job_orders (e.g. checkout job step failed), create jobs like checkout does.
     * Returns first job_orders.id or null if order/items missing.
     */
    public static function ensureJobsForStoreOrder(int $orderId): ?int {
        $existing = db_query('SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1', 'i', [$orderId]);
        if (!empty($existing)) {
            return (int)$existing[0]['id'];
        }
        $order = db_query('SELECT * FROM orders WHERE order_id = ?', 'i', [$orderId]);
        if (empty($order)) {
            return null;
        }
        $order = $order[0];
        $customerId = (int)$order['customer_id'];
        $notes = $order['notes'] ?? '';
        $branchId = (int)($order['branch_id'] ?? 0);

        $items = db_query(
            "SELECT oi.*, p.name AS product_name, p.category AS product_category
             FROM order_items oi
             LEFT JOIN products p ON oi.product_id = p.product_id
             WHERE oi.order_id = ?
             ORDER BY oi.order_item_id ASC",
            'i',
            [$orderId]
        ) ?: [];

        if (empty($items)) {
            return null;
        }

        $firstJobId = null;
        foreach ($items as $item) {
            $custom = printflow_decode_modal_customization_payload((string)($item['customization_data'] ?? ''));
            $pname = (string)($item['product_name'] ?? '');
            $pcat = (string)($item['product_category'] ?? '');
            $service_type = get_service_name_from_customization($custom, '');
            if ($service_type === '') {
                $service_type = self::inferServiceTypeFromProduct($pcat, $pname);
            }

            $dimensions = $custom['dimensions'] ?? $custom['Size'] ?? '';
            $width_ft = 0.0;
            $height_ft = 0.0;
            if ($dimensions && (strpos($dimensions, 'x') !== false || strpos($dimensions, '×') !== false)) {
                $d_parts = preg_split('/[x×]/u', strtolower($dimensions));
                $width_ft = (float)(trim($d_parts[0] ?? 0));
                $height_ft = (float)(trim($d_parts[1] ?? 0));
            }

            $job_title = get_service_name_from_customization($custom, $pname !== '' ? $pname : $service_type);
            $job_qty = (int)($item['quantity'] ?? 1);
            $unit_price = (float)($item['unit_price'] ?? 0);

            // Do not auto-assign production materials during store/POS job creation.
            // Materials must only be attached through explicit staff actions later.
            $autoMaterials = [];
            error_log(sprintf(
                'PrintFlow Material Assignment: skipped auto-assignment for store order %d (service: %s)',
                $orderId,
                $service_type
            ));

            try {
                $jid = self::createOrder([
                    'order_id'        => $orderId,
                    'customer_id'     => $customerId,
                    'branch_id'       => $branchId,
                    'job_title'       => $job_title,
                    'service_type'    => $service_type,
                    'width_ft'        => $width_ft,
                    'height_ft'       => $height_ft,
                    'quantity'        => $job_qty,
                    'total_sqft'      => $width_ft * $height_ft * $job_qty,
                    'price_per_sqft'  => null,
                    'price_per_piece' => null,
                    'estimated_total' => $unit_price * $job_qty,
                    'notes'           => $notes,
                    'due_date'        => null,
                    'priority'        => 'NORMAL',
                    'artwork_path'    => null,
                    'created_by'      => null,
                ], $autoMaterials);
                if ($firstJobId === null) {
                    $firstJobId = (int)$jid;
                }
            } catch (Throwable $e) {
                error_log('PrintFlow ensureJobsForStoreOrder: order ' . $orderId . ' — ' . $e->getMessage());
            }
        }

        return $firstJobId;
    }

    /**
     * Ensure a store order has linked job orders, then move every active job to
     * the requested status through the same status pipeline used by online
     * payment approval and production flows.
     *
     * @return int[] Updated job order IDs
     */
    public static function syncStoreOrderToStatus(int $storeOrderId, string $targetStatus, ?int $machineId = null, string $reason = '', bool $silent = false): array {
        if ($storeOrderId <= 0 || trim($targetStatus) === '') {
            return [];
        }

        self::ensureJobsForStoreOrder($storeOrderId);

        $jobs = db_query(
            "SELECT id
             FROM job_orders
             WHERE order_id = ?
               AND status NOT IN ('COMPLETED', 'CANCELLED')
             ORDER BY id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];

        $updatedJobIds = [];
        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            self::updateStatus($jobId, $targetStatus, $machineId, $reason, $silent);
            $updatedJobIds[] = $jobId;
        }

        return $updatedJobIds;
    }

    /**
     * Assign a specific roll to a job order material item.
     */
    public static function assignRoll($jomId, $rollId) {
        $sql = "UPDATE job_order_materials SET roll_id = ? WHERE id = ? AND deducted_at IS NULL";
        return db_execute($sql, 'ii', [$rollId, $jomId]);
    }

    /**
     * Add a material to a job order with advanced metadata.
     */
    public static function addMaterial($orderId, $itemId, $qty, $uom, $rollId = null, $notes = '', $metadata = null, $orderType = null) {
        $liveJobId = null;
        if ($orderType === null) {
            // Auto-detect based on existence in job_orders table
            $isJob = db_query("SELECT id FROM job_orders WHERE id = ?", 'i', [$orderId]);
            $orderType = (!empty($isJob)) ? 'JOB' : 'ORDER';
        }
        $qty = (float)$qty;
        if ($qty < 1) {
            throw new Exception("Material quantity must be at least 1.");
        }
        
        $item = InventoryManager::getItem($itemId);
        if (!$item) throw new Exception("Item not found.");
        
        $cost = $item['unit_cost'] ?? 0;
        $track_by_roll = $item['track_by_roll'];
        
        // Calculate computed length if track_by_roll
        $computed_len = 0;
        if ($track_by_roll) {
            // For roll-based, if uom is ft, quantity is often the length
            // But we might have separate height/qty in metadata
            if (isset($metadata['height_ft']) && (float)$metadata['height_ft'] > 0) {
                $computed_len = (float)$metadata['height_ft'] * $qty;
            } else {
                $computed_len = ($uom === 'ft') ? $qty : 0;
            }

            // Fallback to job dimensions if still 0
            if ($computed_len <= 0 && ($orderType === 'JOB' || $colId === 'job_order_id')) {
                $job = db_query("SELECT height_ft, width_ft, quantity FROM job_orders WHERE id = ?", 'i', [$orderId]);
                if (!empty($job)) {
                    $jH = (float)($job[0]['height_ft'] ?? 0);
                    $jW = (float)($job[0]['width_ft'] ?? 0);
                    $jQ = (float)($job[0]['quantity'] ?? 1);
                    // Use the larger dimension as length if height is not specifically set
                    $computed_len = max($jH, $jW) * $jQ;
                }
            }
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadata['manual_assignment'] = true;
        $metaJson = !empty($metadata) ? json_encode($metadata) : null;

        $colId = 'job_order_id';
        if ($orderType === 'ORDER' && self::tableHasColumn('job_order_materials', 'std_order_id')) {
            $colId = 'std_order_id';
            $liveJobId = self::ensureJobsForStoreOrder((int)$orderId);
        } elseif ($orderType === 'ORDER') {
            $resolvedJobId = self::ensureJobsForStoreOrder((int)$orderId);
            if ($resolvedJobId) {
                $liveJobId = (int)$resolvedJobId;
                $orderId = $resolvedJobId;
            }
        } else {
            $liveJobId = (int)$orderId;
        }

        // Check for duplicates
        if ($rollId) {
            $exists = db_query("SELECT id FROM job_order_materials WHERE $colId = ? AND item_id = ? AND roll_id = ?", 'iii', [$orderId, $itemId, $rollId]);
        } else {
            $exists = db_query("SELECT id FROM job_order_materials WHERE $colId = ? AND item_id = ? AND roll_id IS NULL", 'ii', [$orderId, $itemId]);
        }
        
        if (!empty($exists)) {
            if ($orderType === 'ORDER') {
                self::syncStoreOrderAssignmentsIfNeeded((int)$orderId, ['materials' => true, 'inks' => false]);
            } else {
                self::syncLiveJobMaterialsIfNeeded((int)$liveJobId);
            }
            return $exists[0]['id']; // Return existing ID instead of creating duplicate
        }

        $sql = "INSERT INTO job_order_materials ($colId, item_id, roll_id, quantity, uom, computed_required_length_ft, unit_cost_at_assignment, notes, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertedId = db_execute($sql, 'iiidsddss', [$orderId, $itemId, $rollId, $qty, $uom, $computed_len, $cost, $notes, $metaJson]);
        if ($orderType === 'ORDER') {
            self::syncStoreOrderAssignmentsIfNeeded((int)$orderId, ['materials' => true, 'inks' => false]);
        } else {
            self::syncLiveJobMaterialsIfNeeded((int)$liveJobId);
        }
        return $insertedId;
    }

    /**
     * Preview the impact on a specific roll or item before assignment.
     */
    public static function previewImpact($itemId, $rollId = null, $qty = 0, $height = 0) {
        $item = InventoryManager::getItem($itemId);
        if (!$item) return null;

        $totalStock = InventoryManager::getStockOnHand($itemId);
        $required = 0;

        if ($item['track_by_roll']) {
            $required = $height > 0 ? ($height * $qty) : $qty;
            if ($rollId) {
                $roll = RollService::getRoll($rollId);
                return [
                    'item_name' => $item['name'],
                    'roll_code' => $roll['roll_code'] ?? "#$rollId",
                    'before' => $roll['remaining_length_ft'],
                    'required' => $required,
                    'after' => $roll['remaining_length_ft'] - $required,
                    'is_sufficient' => ($roll['remaining_length_ft'] >= $required)
                ];
            }
        } else {
            $required = $qty;
        }

        return [
            'item_name' => $item['name'],
            'before' => $totalStock,
            'required' => $required,
            'after' => $totalStock - $required,
            'is_sufficient' => ($totalStock >= $required)
        ];
    }

    /**
     * Remove a material from a job order.
     */
    public static function removeMaterial($jomId) {
        // Can only remove if not yet deducted
        $sql = "DELETE FROM job_order_materials WHERE id = ? AND deducted_at IS NULL";
        return db_execute($sql, 'i', [$jomId]);
    }

    /**
     * Get material readiness status for an order.
     */
    public static function getMaterialReadiness($orderId) {
        $order = self::getOrder($orderId);
        if (!$order) return 'MISSING';
        $branchId = self::getJobBranchId((int)$orderId);

        $status = 'READY';
        foreach ($order['materials'] as $m) {
            $stock = InventoryManager::getStockOnHand($m['item_id'], $branchId);
            $required = $m['track_by_roll'] ? $m['computed_required_length_ft'] : $m['quantity'];
            
            if ($stock <= 0) {
                return 'MISSING';
            } elseif ($stock < $required) {
                $status = 'LOW';
            }

            // Check lamination stock readiness if printing sticker
            if (isset($m['metadata']['lamination_item_id']) && $m['metadata']['lamination_length_ft'] > 0) {
                $lamStock = InventoryManager::getStockOnHand($m['metadata']['lamination_item_id'], $branchId);
                if ($lamStock <= 0) {
                    return 'MISSING';
                } elseif ($lamStock < $m['metadata']['lamination_length_ft']) {
                    $status = 'LOW';
                }
            }
        }

        if (isset($order['ink_usage']) && !empty($order['ink_usage'])) {
            // Check ink stock readiness
            foreach ($order['ink_usage'] as $ink) {
                $inkItem = InventoryManager::getItem((int)$ink['item_id']);
                if (!$inkItem) {
                    return 'MISSING';
                }
                $stock = InventoryManager::getStockOnHand($ink['item_id'], $branchId);
                $requiredInk = self::convertInkMlToItemUom((float)$ink['quantity_used'], $inkItem);
                if ($stock < $requiredInk) {
                    return 'MISSING'; // Missing ink should block completion
                }
            }
        }

        return $status;
    }

    /**
     * Calculate internal material cost for a job.
     */
    public static function calculateJobCost($orderId) {
        $materials = self::getScopedMaterials((int)$orderId, false);
        $total = 0.0;
        foreach ($materials as $m) {
            $qty = (float)((float)($m['computed_required_length_ft'] ?? 0) > 0 ? $m['computed_required_length_ft'] : $m['quantity']);
            $total += $qty * (float)($m['unit_cost_at_assignment'] ?? 0);
        }
        return $total;
    }

    /**
     * Set job order status and trigger logic.
     */
    public static function updateStatus($orderId, $newStatus, $machineId = null, $reason = '', $silent = false) {
        global $conn;
        
        $order = db_query("SELECT * FROM job_orders WHERE id = ?", 'i', [$orderId]);
        if (!$order) throw new Exception("Order not found.");
        $order = $order[0];

        $wasInTransaction = $conn->in_transaction ?? false;
        if (!$wasInTransaction) {
            $conn->begin_transaction();
        }
        try {
            $normalizedNewStatus = self::normalizeWorkflowStatus((string)$newStatus);
            // Materials are now handled once the job is live in production.
            if (in_array($normalizedNewStatus, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING'], true)) {
                // Deduct materials when moving to production
                self::processDeductions($orderId);
            }

            if ($normalizedNewStatus === 'COMPLETED') {
                // For POS orders (walk-in) or orders already in TO_RECEIVE status, skip payment check
                $currentStatus = self::normalizeWorkflowStatus((string)($order['status'] ?? ''));
                $isPOSOrder = !empty($order['order_id']) && db_query(
                    "SELECT 1 FROM orders WHERE order_id = ? AND order_type = 'custom' AND payment_method IN ('Cash', 'GCash', 'Maya') LIMIT 1",
                    'i',
                    [$order['order_id']]
                );
                
                // Allow completion if: already in TO_RECEIVE (Ready for Pickup), or is a POS order, or payment is PAID
                $payment = strtoupper((string)($order['payment_status'] ?? ''));
                $canComplete = ($currentStatus === 'TO_RECEIVE') || !empty($isPOSOrder) || ($payment === 'PAID');
                
                if (!$canComplete) {
                    throw new Exception('Cannot mark as Completed: payment must be Paid. Current payment status: ' . ($order['payment_status'] ?? 'Unpaid'));
                }
                
                // Process deductions again (idempotent - will skip already deducted items)
                self::processDeductions($orderId);
                if ($order['customer_id']) {
                    self::updateCustomerStatus($order['customer_id']);
                }
            }

            $sql = "UPDATE job_orders SET status = ?, machine_id = ? WHERE id = ?";
            db_execute($sql, 'sii', [$newStatus, $machineId ?: $order['machine_id'], $orderId]);

            // Sync status back to standard orders table
            if (!empty($order['order_id'])) {
                // Map job status → orders table status
                $order_status_map = [
                    'PENDING'       => 'Pending Approval',
                    'APPROVED'      => 'Approved',
                    'TO_PAY'        => 'To Pay',
                    'VERIFY_PAY'    => 'To Verify',
                    'IN_PRODUCTION' => 'In Production',
                    'PROCESSING'    => 'In Production',
                    'PRINTING'      => 'In Production',
                    'TO_RECEIVE'    => 'Ready for Pickup',
                    'COMPLETED'     => 'Completed',
                    'CANCELLED'     => 'Cancelled',
                    'FOR REVISION'  => 'For Revision',
                ];
                $storeStatus = $order_status_map[$normalizedNewStatus] ?? $newStatus;

                $sql_parts = ["status = ?", "updated_at = NOW()"];
                $params = [$storeStatus];
                $types = "s";

                if (strtoupper($newStatus) === 'APPROVED') {
                    $sql_parts[] = "design_status = 'Approved'";
                    $sql_parts[] = "revision_reason = ''";
                }

                // When revision is requested, store the reason on the order record
                if (strtoupper($newStatus) === 'FOR REVISION' && !empty($reason)) {
                    $sql_parts[] = "design_status = 'Revision Requested'";
                    $sql_parts[] = "revision_reason = ?";
                    $params[] = $reason;
                    $types .= "s";
                }

                $params[] = $order['order_id'];
                $types .= "i";

                db_execute("UPDATE orders SET " . implode(', ', $sql_parts) . " WHERE order_id = ?", $types, $params);
            }

            // Send real-time notification to customer on every status change
            if (!$silent && !empty($order['customer_id'])) {
                NotificationService::sendJobOrderNotification(
                    (int)$order['customer_id'],
                    $orderId,
                    $newStatus,
                    null,
                    $reason
                );
            }

            // Also send a chat update card if linked to a store order
            if (!$silent && !empty($order['order_id'])) {
                $step = strtolower((string)$newStatus);
                // Map job status to notification steps
                $step_map = [
                    'pending'       => 'approved', // Inquiry approved, but maybe not priced
                    'approved'      => 'approved',
                    'to_pay'        => 'send_to_payment',
                    'verify_pay'    => 'view_only',
                    'in_production' => 'in_production',
                    'for revision'  => 'for_revision',
                    'to_receive'    => 'ready_to_pickup',
                    'completed'     => 'completed',
                    'cancelled'     => 'cancelled',
                ];
                $notif_step = $step_map[$step] ?? 'view_only';
                $chat_meta = [];
                if ($notif_step === 'for_revision' && !empty($reason)) {
                    $chat_meta['reason'] = $reason;
                }
                // Signature: printflow_send_order_update($order_id, $message, $action_type, $thumbnail, $action_url, $meta)
                printflow_send_order_update((int)$order['order_id'], $notif_step, 'view_status', '', '', $chat_meta);
            }

            if (!$wasInTransaction) {
                $conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if (!$wasInTransaction && ($conn->in_transaction ?? false)) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    /**
     * Idempotent deduction for all materials in an order.
     */
    private static function processDeductions($orderId, array $options = []) {
        $processMaterials = $options['materials'] ?? true;
        $processInks = $options['inks'] ?? true;
        $branchId = self::getJobBranchId((int)$orderId);
        $jobRef = printflow_get_job_inventory_reference((int)$orderId);
        $jobLabel = $jobRef['label'] ?? ('Job #' . printflow_format_job_code((int)$orderId));
        $materials = $processMaterials ? self::getScopedMaterials((int)$orderId, true) : [];
        
        if ($materials) {
            foreach ($materials as $m) {
                $item = InventoryManager::getItem($m['item_id']);
                if (!$item) continue;

                if ($item['track_by_roll']) {
                    $lengthNeeded = (float)($m['computed_required_length_ft'] ?: $m['quantity']);

                    if ($lengthNeeded <= 0) {
                        // Nothing to deduct — mark as processed and continue
                        db_execute("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?", 'i', [$m['id']]);
                        continue;
                    }

                    try {
                        // Use unified FIFO deduction logic
                        RollService::deductFIFO(
                            $m['item_id'],
                            $lengthNeeded,
                            'JOB_ORDER',
                            $orderId,
                            "Deducted for {$jobLabel}",
                            $branchId
                        );
                    } catch (Exception $e) {
                        // FIFO failed (e.g. insufficient rolls) — propagate error to prevent
                        // silent inventory corruption. Staff must add roll stock first.
                        throw new Exception(
                            "Cannot complete Job #{$orderId}: Roll stock depleted for '{$item['name']}'. " .
                            "Please receive new stock before marking complete. (" . $e->getMessage() . ")"
                        );
                    }
                    
                    // --- PRINTED STICKER: LAMINATION DEDUCTION ---
                    $metadata = is_string($m['metadata']) ? json_decode($m['metadata'], true) : $m['metadata'];
                    if (is_array($metadata) && isset($metadata['lamination_item_id']) && !empty($metadata['lamination_length_ft'])) {
                        $lamItem = InventoryManager::getItem($metadata['lamination_item_id']);
                        if ($lamItem) {
                            try {
                                if ($lamItem['track_by_roll']) {
                                    RollService::deductFIFO(
                                        $lamItem['id'],
                                        $metadata['lamination_length_ft'],
                                        'JOB_ORDER',
                                        $orderId,
                                        "Lamination deducted for {$jobLabel}",
                                        $branchId
                                    );
                                } else {
                                    InventoryManager::issueStock(
                                        $lamItem['id'], 
                                        $metadata['lamination_length_ft'], 
                                        $lamItem['unit_of_measure'], 
                                        'JOB_ORDER', 
                                        $orderId, 
                                        "Lamination deducted for {$jobLabel}",
                                        false,
                                        false,
                                        $branchId
                                    );
                                }
                            } catch (Exception $e) {
                                throw new Exception(
                                    "Cannot complete Job #{$orderId}: Lamination stock depleted for '{$lamItem['name']}'. " .
                                    "Please receive new stock before marking complete. (" . $e->getMessage() . ")"
                                );
                            }
                        }
                    }

                    db_execute("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?", 'i', [$m['id']]);
                } else {
                    // Non-roll deduction
                    InventoryManager::issueStock(
                        $m['item_id'], 
                        $m['quantity'], 
                        $m['uom'], 
                        'JOB_ORDER', 
                        $orderId, 
                        "Deducted for {$jobLabel}",
                        false,
                        false,
                        $branchId
                    );
                    // Mark as deducted
                    db_execute("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?", 'i', [$m['id']]);
                }
            }
        }

        // Process Ink Deductions
        $inks = $processInks ? self::getScopedInkUsage((int)$orderId) : [];
        if ($inks) {
            foreach ($inks as $ink) {
                // Determine item from inventory
                $inkItem = InventoryManager::getItem($ink['item_id']);
                if (!$inkItem) continue;
                $inkQtyForInventory = self::convertInkMlToItemUom((float)$ink['quantity_used'], $inkItem);

                InventoryManager::issueStock(
                    $ink['item_id'],
                    $inkQtyForInventory,
                    $inkItem['unit_of_measure'] ?? 'bottle',
                    'JOB_ORDER',
                    $orderId,
                    "{$ink['ink_color']} ink used for {$jobLabel}",
                    false,
                    false,
                    $branchId
                );
            }
        }
    }

    private static function syncLiveJobMaterialsIfNeeded(int $jobId): void {
        if ($jobId <= 0) {
            return;
        }

        $jobRows = db_query(
            "SELECT status FROM job_orders WHERE id = ? LIMIT 1",
            'i',
            [$jobId]
        ) ?: [];
        $status = self::normalizeWorkflowStatus((string)($jobRows[0]['status'] ?? ''));
        if (!in_array($status, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING', 'TO_RECEIVE', 'COMPLETED'], true)) {
            return;
        }

        self::processDeductions($jobId, ['materials' => true, 'inks' => false]);
    }

    private static function isServiceStoreOrderItem(array $item, array $custom): bool {
        $productType = strtolower(trim((string)($item['product_type'] ?? '')));
        $category = strtolower(trim((string)($item['category'] ?? '')));
        $sourcePage = strtolower(trim((string)($custom['source_page'] ?? '')));
        $formType = strtolower(trim((string)($custom['form_type'] ?? '')));
        
        // If category contains 'service', it's a service order
        if (strpos($category, 'service') !== false) {
            return true;
        }

        if (in_array($sourcePage, ['services', 'service', 'dynamic_form'], true)) {
            return true;
        }

        if ($formType === 'dynamic') {
            return true;
        }

        if (in_array($productType, ['fixed', 'fixed product'], true)) {
            return false;
        }
        if (in_array($productType, ['custom', 'service'], true)) {
            return true;
        }
        return get_service_name_from_customization($custom, '') !== '';
    }

    private static function buildStoreOrderItemAssetMeta(array $item, int $fallbackDesignOrderItemId = 0, ?array $fallbackDesignMeta = null): array {
        $lineOrderItemId = (int)($item['order_item_id'] ?? 0);
        $hasOwnDesign = !empty($item['design_image']) || trim((string)($item['design_file'] ?? '')) !== '';
        $designServeId = $hasOwnDesign
            ? $lineOrderItemId
            : (($lineOrderItemId === 0 && $fallbackDesignOrderItemId > 0) ? $fallbackDesignOrderItemId : 0);
        $designOpenUrl = $designServeId > 0
            ? BASE_PATH . '/public/serve_design.php?type=order_item&id=' . $designServeId
            : null;

        $designName = trim((string)($item['design_image_name'] ?? ''));
        $designIsImage = false;
        if ($hasOwnDesign) {
            $designIsImage = function_exists('printflow_order_item_has_previewable_design')
                ? printflow_order_item_has_previewable_design($item)
                : false;
            if ($designName === '' && !empty($item['design_file'])) {
                $designPath = parse_url((string)$item['design_file'], PHP_URL_PATH);
                $designName = basename(is_string($designPath) && $designPath !== '' ? $designPath : (string)$item['design_file']);
            }
        } elseif ($designServeId > 0 && is_array($fallbackDesignMeta)) {
            $fallbackDesignPath = (string)($fallbackDesignMeta['design_file'] ?? '');
            $fallbackDesignName = trim((string)($fallbackDesignMeta['design_image_name'] ?? ''));
            $designIsImage = function_exists('printflow_media_path_looks_like_image')
                ? printflow_media_path_looks_like_image($fallbackDesignPath, $fallbackDesignName)
                : false;
            if ($designName === '') {
                $designName = $fallbackDesignName;
            }
            if ($designName === '' && $fallbackDesignPath !== '') {
                $fallbackDesignParsedPath = parse_url($fallbackDesignPath, PHP_URL_PATH);
                $designName = basename(is_string($fallbackDesignParsedPath) && $fallbackDesignParsedPath !== ''
                    ? $fallbackDesignParsedPath
                    : $fallbackDesignPath);
            }
        }

        $referenceOpenUrl = ($lineOrderItemId > 0 && !empty($item['reference_image_file']))
            ? BASE_PATH . '/public/serve_design.php?type=order_item&id=' . $lineOrderItemId . '&field=reference'
            : null;
        $referenceIsImage = !empty($item['reference_image_file']) && function_exists('printflow_media_path_looks_like_image')
            ? printflow_media_path_looks_like_image((string)($item['reference_image_file'] ?? ''))
            : false;
        $referenceName = '';
        if (!empty($item['reference_image_file'])) {
            $referencePath = parse_url((string)$item['reference_image_file'], PHP_URL_PATH);
            $referenceName = basename(is_string($referencePath) && $referencePath !== '' ? $referencePath : (string)$item['reference_image_file']);
        }

        return [
            'design_url' => $designIsImage ? $designOpenUrl : null,
            'design_open_url' => $designOpenUrl,
            'design_is_image' => $designIsImage,
            'design_name' => $designName !== '' ? $designName : null,
            'reference_url' => $referenceIsImage ? $referenceOpenUrl : null,
            'reference_open_url' => $referenceOpenUrl,
            'reference_is_image' => $referenceIsImage,
            'reference_name' => $referenceName !== '' ? $referenceName : null,
        ];
    }

    private static function normalizeStoreOrderModalServiceSpecs(array $custom, array $order, array $item, bool $isServiceOrder): array {
        if (!$isServiceOrder) {
            return $custom;
        }

        require_once __DIR__ . '/service_field_config_helper.php';

        $serviceId = (int)($custom['service_id'] ?? 0);
        if ($serviceId <= 0) {
            $ot = strtolower(trim((string)($order['order_type'] ?? '')));
            if (in_array($ot, ['custom', 'product'], true)) {
                $serviceId = (int)($order['reference_id'] ?? 0);
            }
        }

        $branchLabel = 'Branch';
        $quantityLabel = 'Quantity';
        if ($serviceId > 0 && function_exists('get_service_field_config')) {
            $configs = get_service_field_config($serviceId);
            if (is_array($configs)) {
                foreach ($configs as $fieldKey => $cfg) {
                    if (!is_array($cfg)) {
                        continue;
                    }
                    $label = trim((string)($cfg['label'] ?? ''));
                    $display = $label !== '' ? $label : trim((string)$fieldKey);
                    if ($fieldKey === 'branch') {
                        $branchLabel = $display;
                    }
                    if (($cfg['type'] ?? '') === 'quantity') {
                        $quantityLabel = $display;
                    }
                }
            }
        }

        if (array_key_exists('branch', $custom)) {
            $legacyBranch = trim((string)$custom['branch']);
            unset($custom['branch']);
            if ($legacyBranch !== '') {
                $alreadyHasBranch = false;
                foreach ($custom as $key => $value) {
                    if (strcasecmp(trim((string)$key), $branchLabel) === 0 && trim((string)$value) !== '') {
                        $alreadyHasBranch = true;
                        break;
                    }
                }
                if (!$alreadyHasBranch) {
                    $custom[$branchLabel] = $legacyBranch;
                }
            }
        }

        $hasBranchSpec = false;
        foreach ($custom as $key => $value) {
            $keyText = strtolower(trim((string)$key));
            $valueText = is_scalar($value) || $value === null ? trim((string)$value) : '';
            if ($valueText === '') {
                continue;
            }
            if (strcasecmp($keyText, strtolower($branchLabel)) === 0 || str_contains($keyText, 'branch') || str_contains($keyText, 'pickup')) {
                $hasBranchSpec = true;
                break;
            }
        }

        $orderBranch = trim((string)($order['branch_name'] ?? ''));
        if (!$hasBranchSpec && $orderBranch !== '') {
            $custom[$branchLabel] = $orderBranch;
        }

        $quantityValue = max(1, (int)($item['quantity'] ?? 0));
        $hasQuantity = false;
        foreach (array_keys($custom) as $key) {
            $keyText = trim((string)$key);
            if (strcasecmp($keyText, $quantityLabel) === 0 || strcasecmp($keyText, 'quantity') === 0) {
                $hasQuantity = true;
                break;
            }
        }
        if (!$hasQuantity && $quantityValue > 0) {
            $custom[$quantityLabel] = (string)$quantityValue;
        }

        return $custom;
    }

    private static function sortStoreOrderModalCustomizationByServiceConfig(array $custom, int $serviceId): array {
        $serviceId = (int)$serviceId;
        if ($serviceId <= 0) {
            return $custom;
        }

        require_once __DIR__ . '/service_field_config_helper.php';
        if (!function_exists('get_service_field_config')) {
            return $custom;
        }

        $configs = get_service_field_config($serviceId);
        if (!is_array($configs) || $configs === []) {
            return $custom;
        }

        $normalizeFieldName = static function (string $value): string {
            return strtolower(preg_replace('/[\s_\-]+/', '', trim($value)));
        };

        $ordered = [];
        $used = [];
        foreach ($configs as $fieldKey => $cfg) {
            if (!is_array($cfg) || empty($cfg['visible'])) {
                continue;
            }
            $label = trim((string)($cfg['label'] ?? ''));
            $displayLabel = $label !== '' ? $label : trim((string)$fieldKey);
            $displayNorm = $normalizeFieldName($displayLabel);
            $fieldNorm = $normalizeFieldName((string)$fieldKey);

            foreach ($custom as $customKey => $customValue) {
                if (!empty($used[$customKey])) {
                    continue;
                }

                $customKeyText = trim((string)$customKey);
                $customNorm = $normalizeFieldName($customKeyText);
                if (
                    strcasecmp($customKeyText, $displayLabel) === 0
                    || ($displayNorm !== '' && $customNorm === $displayNorm)
                    || ($fieldNorm !== '' && $customNorm === $fieldNorm)
                ) {
                    $ordered[$customKey] = $customValue;
                    $used[$customKey] = true;
                    break;
                }
            }
        }

        foreach ($custom as $customKey => $customValue) {
            if (empty($used[$customKey])) {
                $ordered[$customKey] = $customValue;
            }
        }

        return $ordered;
    }

    /**
     * Store order line items + design URLs for staff modal (same shape as job_orders_api get_regular_order).
     */
    public static function getStoreOrderItemsPayload(int $storeOrderId, bool $serviceOnly = false, bool $detailMode = false): array {
        if ($storeOrderId <= 0) {
            return ['items' => [], 'width_ft' => '1', 'height_ft' => '1', 'service_type' => ''];
        }
        $items = db_query(
            "SELECT oi.*, p.name as product_name, p.category, p.product_type
             FROM order_items oi
             LEFT JOIN products p ON oi.product_id = p.product_id
             WHERE oi.order_id = ?
             ORDER BY oi.order_item_id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];
        require_once __DIR__ . '/order_ui_helper.php';
        if (!$detailMode) {
        $items_out = [];
        $first_custom = [];
        $total_qty = 0;
        $width_ft = '1';
        $height_ft = '1';
        foreach ($items as $item) {
            $custom = customer_orders_decode_customization_payload((string)($item['customization_data'] ?? ''));
            if ($serviceOnly && !self::isServiceStoreOrderItem($item, $custom)) {
                continue;
            }
            if (empty($first_custom)) {
                $first_custom = $custom;
            }
            $total_qty += (int)$item['quantity'];
            if (!empty($custom['width']) && !empty($custom['height'])) {
                $width_ft = (string)$custom['width'];
                $height_ft = (string)$custom['height'];
            } elseif (!empty($custom['dimensions'])) {
                $d = $custom['dimensions'];
                if (is_string($d) && preg_match('/^(\d+)\s*[x×]\s*(\d+)$/i', $d, $m)) {
                    $width_ft = $m[1];
                    $height_ft = $m[2];
                } else {
                    $width_ft = (string)$d;
                    $height_ft = '';
                }
            }
            $name = $item['product_name'] ?: 'Custom Order';
            if (!empty($custom['sintra_type'])) {
                $name = 'Sintra Board - ' . $custom['sintra_type'];
            } elseif (!empty($custom['Sintra Type'])) {
                $name = 'Sintra Board - ' . $custom['Sintra Type'];
            } elseif (!empty($custom['tarp_size'])) {
                $name = 'Tarpaulin Printing - ' . $custom['tarp_size'];
            } elseif (!empty($custom['Tarp Size'])) {
                $name = 'Tarpaulin Printing - ' . $custom['Tarp Size'];
            } elseif (isset($custom['width']) && isset($custom['height']) && (isset($custom['finish']) || isset($custom['with_eyelets']))) {
                $name = 'Tarpaulin Printing (' . $custom['width'] . 'x' . $custom['height'] . ' ft)';
            } elseif (isset($custom['vinyl_type']) || isset($custom['print_placement'])) {
                $name = 'T-Shirt Printing';
            } elseif (isset($custom['sticker_type']) || isset($custom['Sticker Type'])) {
                $name = 'Decals/Stickers (Print/Cut)';
            } else if (empty($item['product_name']) || in_array(strtolower(trim((string)$name)), ['custom order', 'customer order', 'service order', 'order item', 'sticker pack'])) {
                $name = get_service_name_from_customization($custom, $item['product_name'] ?: 'Custom Order');
            }
            $items_out[] = [
                'order_item_id'   => $item['order_item_id'],
                'product_name'    => $name,
                'product_type'    => $item['product_type'] ?? 'custom',
                'quantity'        => (int)$item['quantity'],
                'customization'   => $custom,
                'design_url'      => (!empty($item['design_image']) || !empty($item['design_file']))
                    ? BASE_PATH . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'] : null,
                'reference_url'   => !empty($item['reference_image_file'])
                    ? BASE_PATH . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'] . '&field=reference' : null,
            ];
        }
        $service_name = get_service_name_from_customization($first_custom, $items_out[0]['product_name'] ?? 'Custom Order');
        return [
            'items'        => $items_out,
            'width_ft'     => $width_ft,
            'height_ft'    => $height_ft,
            'service_type' => $service_name,
            'line_qty'     => $total_qty,
        ];
        }

        $orderRows = db_query(
            "SELECT o.order_id, o.order_type, o.reference_id, o.total_amount, o.estimated_price,
                    b.branch_name,
                    IFNULL((
                        SELECT jo.job_title
                        FROM job_orders jo
                        WHERE jo.order_id = o.order_id
                        ORDER BY jo.id ASC
                        LIMIT 1
                    ), '') AS first_job_title,
                    IFNULL((
                        SELECT jo.service_type
                        FROM job_orders jo
                        WHERE jo.order_id = o.order_id
                        ORDER BY jo.id ASC
                        LIMIT 1
                    ), '') AS first_job_service_type
             FROM orders o
             LEFT JOIN branches b ON b.id = o.branch_id
             WHERE o.order_id = ?
             LIMIT 1",
            'i',
            [$storeOrderId]
        ) ?: [];
        $order = $orderRows[0] ?? [
            'order_id' => $storeOrderId,
            'order_type' => '',
            'reference_id' => 0,
            'total_amount' => 0,
            'estimated_price' => 0,
            'branch_name' => '',
            'first_job_title' => '',
            'first_job_service_type' => '',
        ];

        $validOrderItemIds = [];
        foreach ($items as $item) {
            $orderItemId = (int)($item['order_item_id'] ?? 0);
            if ($orderItemId > 0) {
                $validOrderItemIds[$orderItemId] = true;
            }
        }

        $customizationRows = db_query(
            "SELECT customization_id, order_item_id, service_type, customization_details
             FROM customizations
             WHERE order_id = ?
             ORDER BY customization_id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];

        $customizationByItemId = [];
        $orphanCustomizationDetails = [];
        $firstCustomizationPayload = [];
        $firstCustomizationServiceType = '';
        foreach ($customizationRows as $customizationRow) {
            $serviceType = trim((string)($customizationRow['service_type'] ?? ''));
            $details = customer_orders_decode_customization_payload((string)($customizationRow['customization_details'] ?? ''));
            if ($serviceType !== '' && empty($details['service_type'])) {
                $details['service_type'] = $serviceType;
            }

            if ((empty($firstCustomizationPayload) && !empty($details)) || ($firstCustomizationServiceType === '' && $serviceType !== '')) {
                if (!empty($details)) {
                    $firstCustomizationPayload = $details;
                }
                if ($serviceType !== '') {
                    $firstCustomizationServiceType = $serviceType;
                }
            }

            $orderItemId = (int)($customizationRow['order_item_id'] ?? 0);
            if ($orderItemId <= 0 || !isset($validOrderItemIds[$orderItemId])) {
                $orphanCustomizationDetails = printflow_overlay_nonempty_assoc($orphanCustomizationDetails, $details);
                if ($serviceType !== '' && trim((string)($orphanCustomizationDetails['service_type'] ?? '')) === '') {
                    $orphanCustomizationDetails['service_type'] = $serviceType;
                }
                continue;
            }

            if (!isset($customizationByItemId[$orderItemId])) {
                $customizationByItemId[$orderItemId] = [
                    'details' => [],
                    'service_type' => '',
                ];
            }
            $customizationByItemId[$orderItemId]['details'] = printflow_overlay_nonempty_assoc(
                $customizationByItemId[$orderItemId]['details'],
                $details
            );
            if ($serviceType !== '' && trim((string)$customizationByItemId[$orderItemId]['service_type']) === '') {
                $customizationByItemId[$orderItemId]['service_type'] = $serviceType;
            }
        }

        $firstItemCustomization = [];
        if (!empty($items)) {
            $firstItemCustomization = customer_orders_decode_customization_payload((string)($items[0]['customization_data'] ?? ''));
        }
        $firstItemCustomization = printflow_overlay_nonempty_assoc(
            printflow_overlay_nonempty_assoc($orphanCustomizationDetails, $firstCustomizationPayload),
            $firstItemCustomization
        );
        $firstFallbackService = $firstCustomizationServiceType !== ''
            ? $firstCustomizationServiceType
            : trim((string)($orphanCustomizationDetails['service_type'] ?? ''));
        if ($firstFallbackService !== '' && empty($firstItemCustomization['service_type'])) {
            $firstItemCustomization['service_type'] = $firstFallbackService;
        }

        $firstItemCustomization = customer_orders_sanitize_generic_service_labels($firstItemCustomization);

        $orderTypeNormalized = strtolower(trim((string)($order['order_type'] ?? '')));
        $firstSourcePage = strtolower(trim((string)($firstItemCustomization['source_page'] ?? '')));
        $firstTableSvc = trim((string)$firstCustomizationServiceType);
        if (customer_orders_is_generic_item_name($firstTableSvc)) {
            $firstTableSvc = '';
        }
        $isServiceOrder =
            !empty($firstItemCustomization['service_type'])
            || $firstTableSvc !== ''
            || (int)($firstItemCustomization['service_id'] ?? 0) > 0
            || in_array($firstSourcePage, ['services', 'service', 'dynamic_form'], true)
            || strtolower(trim((string)($firstItemCustomization['form_type'] ?? ''))) === 'dynamic'
            || (function_exists('printflow_order_item_has_service_marker') && printflow_order_item_has_service_marker($firstItemCustomization));
        if (!$isServiceOrder && $orderTypeNormalized === 'custom') {
            $isServiceOrder = !(
                function_exists('customer_orders_custom_order_is_catalog_product')
                && customer_orders_custom_order_is_catalog_product($firstItemCustomization)
            );
        }

        $jobOrdersList = db_query(
            "SELECT job_title, service_type, width_ft, height_ft, notes, total_sqft
             FROM job_orders
             WHERE order_id = ?
             ORDER BY id ASC",
            'i',
            [$storeOrderId]
        ) ?: [];

        if (!$isServiceOrder) {
            foreach ($items as $probeItem) {
                $probeCustom = customer_orders_decode_customization_payload((string)($probeItem['customization_data'] ?? ''));
                $probeSourcePage = strtolower(trim((string)($probeCustom['source_page'] ?? '')));
                if (
                    !empty($probeCustom['service_type'])
                    || (int)($probeCustom['service_id'] ?? 0) > 0
                    || in_array($probeSourcePage, ['services', 'service', 'dynamic_form'], true)
                    || strtolower(trim((string)($probeCustom['form_type'] ?? ''))) === 'dynamic'
                    || (function_exists('printflow_order_item_has_service_marker') && printflow_order_item_has_service_marker($probeCustom))
                ) {
                    $isServiceOrder = true;
                    break;
                }
            }
        }
        if (
            !$isServiceOrder
            && $orderTypeNormalized === 'product'
            && (int)($order['reference_id'] ?? 0) > 0
            && (
                !function_exists('customer_orders_custom_order_is_catalog_product')
                || !customer_orders_custom_order_is_catalog_product($firstItemCustomization)
            )
        ) {
            $isServiceOrder = true;
        }

        if (
            !$isServiceOrder
            && $orderTypeNormalized === 'custom'
            && $jobOrdersList !== []
            && (
                !function_exists('customer_orders_custom_order_is_catalog_product')
                || !customer_orders_custom_order_is_catalog_product($firstItemCustomization)
            )
        ) {
            $isServiceOrder = true;
        }

        if ($items === []) {
            if (
                $isServiceOrder
                || $orderTypeNormalized === 'custom'
                || $customizationRows !== []
                || $firstItemCustomization !== []
                || $jobOrdersList !== []
            ) {
                $serviceName = '';
                $serviceCategory = '';
                $referenceId = (int)($order['reference_id'] ?? 0);
                if ($referenceId > 0) {
                    $serviceRows = db_query(
                        'SELECT name, category FROM services WHERE service_id = ? LIMIT 1',
                        'i',
                        [$referenceId]
                    ) ?: [];
                    if (!empty($serviceRows)) {
                        $serviceName = (string)($serviceRows[0]['name'] ?? '');
                        $serviceCategory = (string)($serviceRows[0]['category'] ?? '');
                    }
                }

                $totalAmount = (float)($order['total_amount'] ?? 0);
                $estimatedAmount = (float)($order['estimated_price'] ?? 0);
                $unitPrice = $totalAmount > 0 ? $totalAmount : ($estimatedAmount > 0 ? $estimatedAmount : 0.0);
                $items = [[
                    'order_item_id' => 0,
                    'product_id' => $referenceId,
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'customization_data' => null,
                    'product_name' => $serviceName,
                    'category' => $serviceCategory,
                    'product_type' => 'custom',
                    'design_image' => null,
                    'design_image_name' => null,
                    'design_image_mime' => null,
                    'design_file' => null,
                    'reference_image_file' => null,
                ]];
            }
        }

        $firstOrderItemId = null;
        foreach ($items as $item) {
            $orderItemId = (int)($item['order_item_id'] ?? 0);
            if ($orderItemId > 0) {
                $firstOrderItemId = $firstOrderItemId === null ? $orderItemId : min($firstOrderItemId, $orderItemId);
            }
        }

        $anyDesignOrderItemId = 0;
        foreach ($items as $item) {
            $orderItemId = (int)($item['order_item_id'] ?? 0);
            if ($orderItemId <= 0) {
                continue;
            }
            if (!empty($item['design_image']) || trim((string)($item['design_file'] ?? '')) !== '') {
                $anyDesignOrderItemId = $orderItemId;
                break;
            }
        }
        if ($anyDesignOrderItemId <= 0) {
            $designRow = db_query(
                "SELECT order_item_id
                 FROM order_items
                 WHERE order_id = ?
                   AND (design_image IS NOT NULL OR (design_file IS NOT NULL AND TRIM(COALESCE(design_file, '')) != ''))
                 ORDER BY order_item_id ASC
                 LIMIT 1",
                'i',
                [$storeOrderId]
            ) ?: [];
            if (!empty($designRow)) {
                $anyDesignOrderItemId = (int)($designRow[0]['order_item_id'] ?? 0);
            }
        }

        $fallbackDesignMeta = null;
        if ($anyDesignOrderItemId > 0) {
            $fallbackMetaRows = db_query(
                'SELECT design_image_name, design_image_mime, design_file FROM order_items WHERE order_item_id = ? LIMIT 1',
                'i',
                [$anyDesignOrderItemId]
            ) ?: [];
            if (!empty($fallbackMetaRows)) {
                $fallbackDesignMeta = $fallbackMetaRows[0];
            }
        }

        $items_out = [];
        $first_custom = [];
        $total_qty = 0;
        $width_ft = '1';
        $height_ft = '1';
        $itemCount = count($items);
        foreach ($items as $lineIndex => $item) {
            // Match customer/get_order_items.php: decode without early printflow_normalize so merges match the storefront modal.
            $custom = customer_orders_decode_customization_payload((string)($item['customization_data'] ?? ''));
            $itemCustomizationFallback = $customizationByItemId[(int)($item['order_item_id'] ?? 0)] ?? ['details' => [], 'service_type' => ''];
            $mergedTableDetails = printflow_overlay_nonempty_assoc(
                $orphanCustomizationDetails,
                (array)($itemCustomizationFallback['details'] ?? [])
            );
            $orphanServiceType = trim((string)($orphanCustomizationDetails['service_type'] ?? ''));
            $itemTableServiceType = trim((string)($itemCustomizationFallback['service_type'] ?? ''));
            $fallbackServiceType = $itemTableServiceType !== '' ? $itemTableServiceType : $orphanServiceType;
            $custom = printflow_overlay_nonempty_assoc($mergedTableDetails, $custom);
            if ($fallbackServiceType !== '' && empty($custom['service_type'])) {
                $custom['service_type'] = $fallbackServiceType;
            }
            if (empty($custom['service_type']) && !empty($firstItemCustomization['service_type'])) {
                $custom['service_type'] = (string)$firstItemCustomization['service_type'];
            }
            $custom = customer_orders_sanitize_generic_service_labels($custom);

            if ($isServiceOrder && $jobOrdersList !== []) {
                $jobCount = count($jobOrdersList);
                $jobRow = null;
                if ($itemCount === 1 && $jobCount >= 1) {
                    $jobRow = $jobOrdersList[0];
                } elseif ($jobCount === $itemCount && isset($jobOrdersList[$lineIndex])) {
                    $jobRow = $jobOrdersList[$lineIndex];
                } elseif (isset($jobOrdersList[$lineIndex])) {
                    $jobRow = $jobOrdersList[$lineIndex];
                }
                if (is_array($jobRow)) {
                    $custom = customer_orders_merge_job_order_row_into_customization($custom, $jobRow);
                }
            }

            $custom = customer_orders_enrich_line_customization($custom, $order);
            unset($custom['design_upload'], $custom['reference_upload']);

            $custom = self::normalizeStoreOrderModalServiceSpecs($custom, $order, $item, $isServiceOrder);

            if ($isServiceOrder && function_exists('printflow_resolve_service_catalog_service_id_for_order_line')) {
                $resolvedServiceId = printflow_resolve_service_catalog_service_id_for_order_line($custom, $order, $item);
                if ($resolvedServiceId > 0 && (int)($custom['service_id'] ?? 0) <= 0) {
                    $custom['service_id'] = $resolvedServiceId;
                }
                if (trim((string)($custom['service_type'] ?? '')) === '') {
                    $serviceId = (int)($custom['service_id'] ?? 0);
                    if ($serviceId > 0 && function_exists('customer_orders_resolve_service_name_by_id')) {
                        $resolvedServiceName = customer_orders_resolve_service_name_by_id($serviceId);
                        if ($resolvedServiceName !== '') {
                            $custom['service_type'] = $resolvedServiceName;
                        }
                    }
                }
            }

            if ($isServiceOrder) {
                $serviceIdForSort = (int)($custom['service_id'] ?? 0);
                if ($serviceIdForSort <= 0) {
                    $ot = strtolower(trim((string)($order['order_type'] ?? '')));
                    if (in_array($ot, ['custom', 'product'], true)) {
                        $serviceIdForSort = (int)($order['reference_id'] ?? 0);
                    }
                }
                if ($serviceIdForSort <= 0 && function_exists('printflow_resolve_service_catalog_service_id')) {
                    $serviceIdForSort = printflow_resolve_service_catalog_service_id((string)($custom['service_type'] ?? ''));
                }
                $custom = self::sortStoreOrderModalCustomizationByServiceConfig($custom, $serviceIdForSort);
            }

            if ($serviceOnly && !self::isServiceStoreOrderItem($item, $custom) && !$isServiceOrder) {
                continue;
            }
            if (empty($first_custom)) {
                $first_custom = $custom;
            }

            $quantity = max(1, (int)($item['quantity'] ?? 0));
            $total_qty += $quantity;

            if (!empty($custom['width']) && !empty($custom['height'])) {
                $width_ft = (string)$custom['width'];
                $height_ft = (string)$custom['height'];
            } elseif (!empty($custom['dimensions'])) {
                $d = $custom['dimensions'];
                if (is_string($d) && preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/iu', $d, $m)) {
                    $width_ft = $m[1];
                    $height_ft = $m[2];
                } else {
                    $width_ft = is_string($d) ? (string)$d : '';
                    $height_ft = '';
                }
            } elseif (!empty($custom['width_ft']) && !empty($custom['height_ft'])) {
                $width_ft = (string)$custom['width_ft'];
                $height_ft = (string)$custom['height_ft'];
            }

            $useJobLineFallback = ($itemCount === 1)
                || ($firstOrderItemId !== null && (int)($item['order_item_id'] ?? 0) === (int)$firstOrderItemId);
            $orderLike = [
                'order_type' => $order['order_type'] ?? '',
                'reference_id' => $order['reference_id'] ?? null,
                'first_product_name' => (string)($item['product_name'] ?? ''),
                'first_customization_service_type' => (string)$fallbackServiceType,
                'first_job_title' => (string)($order['first_job_title'] ?? ''),
                'first_job_service_type' => (string)($order['first_job_service_type'] ?? ''),
                '_merged_customization' => $custom,
                '_use_job_title_fallback' => $useJobLineFallback,
            ];
            $name = customer_orders_primary_item_name($orderLike);
            $customForPayload = function_exists('printflow_flatten_customization_for_customer_order_modal')
                ? printflow_flatten_customization_for_customer_order_modal($custom, $quantity, true)
                : $custom;
            if (is_array($custom) && $custom !== [] && function_exists('printflow_modal_customization_fallback_flatten_for_staff')) {
                $staffExtra = printflow_modal_customization_fallback_flatten_for_staff($custom, $quantity);
                if (is_array($staffExtra) && $staffExtra !== []) {
                    foreach ($staffExtra as $k => $v) {
                        if (!array_key_exists($k, $customForPayload) || trim((string)($customForPayload[$k] ?? '')) === '') {
                            $customForPayload[$k] = $v;
                        }
                    }
                }
            }
            if (is_array($custom) && $custom !== []) {
                $flatCount = is_array($customForPayload) ? count($customForPayload) : 0;
                if ($flatCount < 2) {
                    $fallbackFlat = printflow_modal_customization_fallback_flatten_for_staff($custom, $quantity);
                    if (count($fallbackFlat) > $flatCount) {
                        $customForPayload = $fallbackFlat;
                    }
                }
            }

            $items_out[] = array_merge([
                'order_item_id' => (int)($item['order_item_id'] ?? 0),
                'product_name' => $name,
                'product_type' => $item['product_type'] ?? 'custom',
                'category' => $item['category'] ?? '',
                'quantity' => $quantity,
                'customization' => $customForPayload,
            ], self::buildStoreOrderItemAssetMeta($item, $anyDesignOrderItemId, $fallbackDesignMeta));
        }

        $service_name = '';
        if (!empty($items_out[0]['product_name'])) {
            $service_name = (string)$items_out[0]['product_name'];
        }
        if ($service_name === '') {
            $service_name = get_service_name_from_customization($first_custom, 'Custom Order');
        }

        return [
            'items' => $items_out,
            'width_ft' => $width_ft,
            'height_ft' => $height_ft,
            'service_type' => $service_name,
            'line_qty' => $total_qty,
        ];
    }

    /**
     * Prefer store-checkout dimensions and line quantity over job_orders placeholders (often 1×1).
     *
     * @param array<string,mixed> $targetRow
     * @param array{items?:array,width_ft?:string,height_ft?:string,line_qty?:int} $payload
     */
    public static function overlayStorePayloadDisplayMetrics(array &$targetRow, array $payload): void {
        $pw = trim((string)($payload['width_ft'] ?? ''));
        $ph = trim((string)($payload['height_ft'] ?? ''));
        $jw = trim((string)($targetRow['width_ft'] ?? ''));
        $jh = trim((string)($targetRow['height_ft'] ?? ''));
        $dimNonTrivial = static function (string $v): bool {
            return $v !== '' && $v !== '0' && $v !== '1';
        };
        $payloadHasSize = $dimNonTrivial($pw) || $dimNonTrivial($ph);
        $jobIsPlaceholder =
            (!$dimNonTrivial($jw) || $jw === '1')
            && (!$dimNonTrivial($jh) || $jh === '1');
        if ($payloadHasSize || $jobIsPlaceholder) {
            if ($pw !== '' && $pw !== '0') {
                $targetRow['width_ft'] = $pw;
            }
            if ($ph !== '' && $ph !== '0') {
                $targetRow['height_ft'] = $ph;
            }
        }
        $lq = (int)($payload['line_qty'] ?? 0);
        if ($lq > 0) {
            $targetRow['quantity'] = $lq;
            return;
        }
        if (!empty($payload['items']) && is_array($payload['items'])) {
            $sum = 0;
            foreach ($payload['items'] as $li) {
                $sum += max(0, (int)($li['quantity'] ?? 0));
            }
            if ($sum > 0) {
                $targetRow['quantity'] = $sum;
            }
        }
    }

    /**
     * Staff list rows: match customer checkout summary (size, qty, line items, title).
     *
     * @param array<string,mixed> $jo
     * @param array{items?:array,service_type?:string,width_ft?:string,height_ft?:string,line_qty?:int} $payload
     */
    public static function enrichStaffJobRowFromStorePayload(array &$jo, array $payload): void {
        self::overlayStorePayloadDisplayMetrics($jo, $payload);
        if (!empty($payload['items']) && is_array($payload['items'])) {
            $jo['items'] = $payload['items'];
        }
        if (trim((string)($payload['service_type'] ?? '')) !== ''
            && strcasecmp(trim((string)($payload['service_type'] ?? '')), 'Custom Order') !== 0) {
            $jo['service_type'] = trim((string)$payload['service_type']);
        }
        $titleParts = [];
        foreach ($payload['items'] ?? [] as $it) {
            $name = trim((string)($it['product_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $titleParts[] = $name . ' - ' . max(1, (int)($it['quantity'] ?? 0)) . 'pcs';
        }
        if ($titleParts !== []) {
            $jo['job_title'] = implode(', ', array_unique($titleParts));
        } elseif (
            trim((string)($payload['service_type'] ?? '')) !== ''
            && strcasecmp(trim((string)($payload['service_type'] ?? '')), 'Custom Order') !== 0
        ) {
            $jo['job_title'] = trim((string)$payload['service_type']);
        }
    }

    public static function getOrder($id) {
        $sql = "SELECT jo.*, 
                       c.customer_type, c.transaction_count,
                       CONCAT(c.first_name, ' ', c.last_name) as customer_full_name,
                       CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                       c.email as customer_email,
                       c.profile_picture,
                       c.profile_picture AS customer_profile_picture,
                       COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(c.email), '')) AS customer_contact,
                       TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_address,
                       COALESCE(jo.branch_id, ord.branch_id) AS branch_display_id,
                       b.branch_name AS branch_name
                FROM job_orders jo
                LEFT JOIN customers c ON jo.customer_id = c.customer_id
                LEFT JOIN orders ord ON ord.order_id = jo.order_id
                LEFT JOIN branches b ON b.id = COALESCE(jo.branch_id, ord.branch_id)
                WHERE jo.id = ?";
        
        $order = db_query($sql, 'i', [$id]);
        if (!$order) return null;
        $order = $order[0];
        
        // Format customer picture with full path
        if (!empty($order['profile_picture'])) {
            $order['customer_picture'] = BASE_PATH . '/public/assets/uploads/profiles/' . $order['profile_picture'];
        } else {
            $order['customer_picture'] = '';
        }
        $order['customer_profile_picture'] = $order['customer_profile_picture'] ?? ($order['profile_picture'] ?? '');

        $storeOid = (int)($order['order_id'] ?? 0);
        if ($storeOid > 0) {
            $payload = self::getStoreOrderItemsPayload($storeOid, false, true);
            self::enrichStaffJobRowFromStorePayload($order, $payload);
            self::cleanupLegacyAutoAssignedMaterials((int)$id, $storeOid, (string)($payload['service_type'] ?? $order['service_type'] ?? ''));
            $st = db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1', 'i', [$storeOid]);
            if (!empty($st)) {
                $row = $st[0];
                $proof = $row['payment_proof_path'] ?? $row['payment_proof'] ?? '';
                if ($proof !== '' && $proof !== null) {
                    $order['payment_proof_path'] = $proof;
                }
                $order['payment_submitted_amount'] = (float)($row['downpayment_amount'] ?? 0);
                $order['payment_proof_uploaded_at'] = $row['payment_submitted_at'] ?? null;
                $order['store_order_notes'] = (string)($row['notes'] ?? '');
                $order['revision_reason'] = (string)($row['revision_reason'] ?? '');
                $order['design_status'] = (string)($row['design_status'] ?? '');
                if (empty($order['estimated_total']) || (float)$order['estimated_total'] <= 0) {
                    $order['estimated_total'] = (float)($row['total_amount'] ?? 0);
                }
                $order['amount_paid'] = (float)($row['amount_paid'] ?? 0);
                if (strtoupper((string)($row['payment_status'] ?? '')) === 'PAID') {
                    $order['amount_paid'] = (float)($row['total_amount'] ?? $order['amount_paid']);
                }
            }
        } else {
            $order['items'] = [];
        }

        $storeOrderId = (int)($order['order_id'] ?? 0);
        $materialSql = "SELECT m.*, i.name as item_name, i.track_by_roll, i.category_id, r.roll_code,
                               (SELECT SUM(IF(direction='IN', quantity, -quantity)) FROM inventory_transactions WHERE item_id = m.item_id) as total_stock
                        FROM job_order_materials m
                        JOIN inv_items i ON m.item_id = i.id
                        LEFT JOIN inv_rolls r ON m.roll_id = r.id
                        WHERE (m.job_order_id = ?";
        $materialParams = [$id];
        $materialTypes = 'i';
        if ($storeOrderId > 0) {
            if (self::tableHasColumn('job_order_materials', 'std_order_id')) {
                $materialSql .= " OR (m.std_order_id = ? AND (m.job_order_id IS NULL OR m.job_order_id = 0))";
                $materialParams[] = $storeOrderId;
                $materialTypes .= 'i';
            }
        }
        $materialSql .= ")";
        $order['materials'] = db_query($materialSql, $materialTypes, $materialParams) ?: [];

        $fallbackSiblingJobs = [];
        $fallbackSiblingJobIds = [];
        if (empty($order['materials']) && $storeOrderId > 0) {
            $orderSourceRows = db_query(
                "SELECT order_source FROM orders WHERE order_id = ? LIMIT 1",
                'i',
                [$storeOrderId]
            ) ?: [];
            $orderSource = strtolower(trim((string)($orderSourceRows[0]['order_source'] ?? '')));
            $isPosSource = in_array($orderSource, ['pos', 'walk-in'], true);

            if ($isPosSource) {
                // POS service jobs can reopen on a sibling job row after checkout. In that case,
                // show the order-wide assigned materials the same way the online store-order modal does.
                $fallbackSiblingJobs = db_query(
                    "SELECT id
                     FROM job_orders
                     WHERE order_id = ?
                       AND id <> ?
                     ORDER BY id ASC",
                    'ii',
                    [$storeOrderId, $id]
                ) ?: [];
            } else {
                $fallbackSiblingJobs = db_query(
                    "SELECT id
                     FROM job_orders
                     WHERE order_id = ?
                       AND id <> ?
                       AND (
                            (service_type IS NOT NULL AND service_type <> '' AND service_type = ?)
                            OR (job_title IS NOT NULL AND job_title <> '' AND job_title = ?)
                       )
                     ORDER BY id ASC",
                    'iiss',
                    [
                        $storeOrderId,
                        $id,
                        (string)($order['service_type'] ?? ''),
                        (string)($order['job_title'] ?? '')
                    ]
                ) ?: [];
            }

            $fallbackSiblingJobIds = array_values(array_filter(array_map(static function ($row) {
                return (int)($row['id'] ?? 0);
            }, $fallbackSiblingJobs)));

            if (!empty($fallbackSiblingJobIds)) {
                $siblingPlaceholders = implode(',', array_fill(0, count($fallbackSiblingJobIds), '?'));
                $fallbackMaterialSql = "SELECT m.*, i.name as item_name, i.track_by_roll, i.category_id, r.roll_code,
                                               (SELECT SUM(IF(direction='IN', quantity, -quantity)) FROM inventory_transactions WHERE item_id = m.item_id) as total_stock
                                        FROM job_order_materials m
                                        JOIN inv_items i ON m.item_id = i.id
                                        LEFT JOIN inv_rolls r ON m.roll_id = r.id
                                        WHERE m.job_order_id IN ($siblingPlaceholders)";
                $fallbackMaterialParams = $fallbackSiblingJobIds;
                $fallbackMaterialTypes = str_repeat('i', count($fallbackSiblingJobIds));
                if (self::tableHasColumn('job_order_materials', 'std_order_id')) {
                    $fallbackMaterialSql .= " OR (m.std_order_id = ? AND (m.job_order_id IS NULL OR m.job_order_id = 0))";
                    $fallbackMaterialParams[] = $storeOrderId;
                    $fallbackMaterialTypes .= 'i';
                }
                $order['materials'] = db_query($fallbackMaterialSql, $fallbackMaterialTypes, $fallbackMaterialParams) ?: [];
            }
        }

        // Parse JSON metadata for each material
        foreach ($order['materials'] as &$m) {
            $m['metadata'] = $m['metadata'] ? json_decode($m['metadata'], true) : null;
        }
        unset($m);
        self::purgePreApprovalPosAutoAssignments($storeOrderId, $order['materials']);

        $order['files'] = db_query(
            "SELECT id, file_path, file_name, file_type, uploaded_at FROM job_order_files WHERE job_order_id = ?",
            'i', [$id]
        ) ?: [];

        $inkSql = "SELECT u.*, i.name as item_name
                   FROM job_order_ink_usage u
                   JOIN inv_items i ON u.item_id = i.id
                   WHERE (u.job_order_id = ?";
        $inkParams = [$id];
        $inkTypes = 'i';
        if ($storeOrderId > 0) {
            if (self::tableHasColumn('job_order_ink_usage', 'std_order_id')) {
                $inkSql .= " OR (u.std_order_id = ? AND (u.job_order_id IS NULL OR u.job_order_id = 0))";
                $inkParams[] = $storeOrderId;
                $inkTypes .= 'i';
            }
        }
        $inkSql .= ")";
        $order['ink_usage'] = db_query($inkSql, $inkTypes, $inkParams) ?: [];

        if (empty($order['ink_usage']) && $storeOrderId > 0) {
            if (empty($fallbackSiblingJobs)) {
                $fallbackSiblingJobs = db_query(
                    "SELECT id
                     FROM job_orders
                     WHERE order_id = ?
                       AND id <> ?
                       AND (
                            (service_type IS NOT NULL AND service_type <> '' AND service_type = ?)
                            OR (job_title IS NOT NULL AND job_title <> '' AND job_title = ?)
                       )
                     ORDER BY id ASC",
                    'iiss',
                    [
                        $storeOrderId,
                        $id,
                        (string)($order['service_type'] ?? ''),
                        (string)($order['job_title'] ?? '')
                    ]
                ) ?: [];
                $fallbackSiblingJobIds = array_values(array_filter(array_map(static function ($row) {
                    return (int)($row['id'] ?? 0);
                }, $fallbackSiblingJobs)));
            }

            if (!empty($fallbackSiblingJobIds)) {
                $siblingPlaceholders = implode(',', array_fill(0, count($fallbackSiblingJobIds), '?'));
                $fallbackInkSql = "SELECT u.*, i.name as item_name
                                   FROM job_order_ink_usage u
                                   JOIN inv_items i ON u.item_id = i.id
                                   WHERE u.job_order_id IN ($siblingPlaceholders)";
                $fallbackInkParams = $fallbackSiblingJobIds;
                $fallbackInkTypes = str_repeat('i', count($fallbackSiblingJobIds));
                if (self::tableHasColumn('job_order_ink_usage', 'std_order_id')) {
                    $fallbackInkSql .= " OR (u.std_order_id = ? AND (u.job_order_id IS NULL OR u.job_order_id = 0))";
                    $fallbackInkParams[] = $storeOrderId;
                    $fallbackInkTypes .= 'i';
                }
                $order['ink_usage'] = db_query($fallbackInkSql, $fallbackInkTypes, $fallbackInkParams) ?: [];
            }
        }

        return $order;
    }

    /**
     * Calculate required payment based on customer classification.
     */
    public static function calculateRequiredPayment($customerId, $totalAmount) {
        if (!$customerId) return $totalAmount; // Walk-in is 100%

        $res = db_query("SELECT customer_type FROM customers WHERE customer_id = ?", 'i', [$customerId]);
        if (!$res) return $totalAmount;

        $type = $res[0]['customer_type'];
        if ($type === 'REGULAR') {
            return $totalAmount * 0.5; // 50% for regulars
        }
        return $totalAmount; // 100% for new
    }

    /**
     * Update customer stats and classification.
     */
    public static function updateCustomerStatus($customerId) {
        // Increment count
        db_execute("UPDATE customers SET transaction_count = transaction_count + 1 WHERE customer_id = ?", 'i', [$customerId]);
        
        // Check for upgrade
        $res = db_query("SELECT transaction_count FROM customers WHERE customer_id = ?", 'i', [$customerId]);
        if ($res && $res[0]['transaction_count'] >= 5) {
            db_execute("UPDATE customers SET customer_type = 'REGULAR' WHERE customer_id = ?", 'i', [$customerId]);
        }
    }

    /**
     * Pause production for a job order.
     */
    public static function pauseProduction($orderId, $notes = '') {
        $sql = "UPDATE job_orders SET status = 'PENDING', notes = CONCAT(IFNULL(notes, ''), '\n[PAUSED] ', ?) WHERE id = ?";
        return db_execute($sql, 'si', [$notes, $orderId]);
    }

    /**
     * Cancel a job order.
     */
    public static function cancelOrder($orderId, $reason = '') {
        $sql = "UPDATE job_orders SET status = 'CANCELLED', notes = CONCAT(IFNULL(notes, ''), '\n[CANCELLED] ', ?) WHERE id = ?";
        return db_execute($sql, 'si', [$reason, $orderId]);
    }

    /**
     * Save Ink Usage for an Order
     */
    public static function saveInkUsage($orderId, $inkData, $orderType = null) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn) return false;
        $syncOrderId = (int)$orderId;

        if ($orderType === null) {
            // Auto-detect based on existence in job_orders table
            $isJob = db_query("SELECT id FROM job_orders WHERE id = ?", 'i', [$orderId]);
            $orderType = (!empty($isJob)) ? 'JOB' : 'ORDER';
        }

        $colId = 'job_order_id';
        if ($orderType === 'ORDER' && self::tableHasColumn('job_order_ink_usage', 'std_order_id')) {
            $colId = 'std_order_id';
        } elseif ($orderType === 'ORDER') {
            $resolvedJobId = self::ensureJobsForStoreOrder((int)$orderId);
            if ($resolvedJobId) {
                $orderId = $resolvedJobId;
            }
        }

        $conn->begin_transaction();
        try {
            // Remove existing ink records for easy replace strategy
            db_execute("DELETE FROM job_order_ink_usage WHERE $colId = ?", 'i', [$orderId]);

            if (!empty($inkData) && is_array($inkData)) {
                $sql = "INSERT INTO job_order_ink_usage ($colId, item_id, ink_color, quantity_used) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    foreach ($inkData as $ink) {
                        $itemId = (int)($ink['item_id'] ?? 0);
                        $color = sanitize($ink['color'] ?? '');
                        $qty = (float)($ink['quantity'] ?? 0);

                        if ($itemId > 0 && $qty > 0 && !empty($color)) {
                            $stmt->bind_param('iisd', $orderId, $itemId, $color, $qty);
                            $stmt->execute();
                        }
                    }
                    $stmt->close();
                }
            }
            $conn->commit();
            if ($orderType === 'ORDER') {
                self::syncStoreOrderAssignmentsIfNeeded($syncOrderId, ['materials' => false, 'inks' => true]);
            } else {
                self::syncLiveJobMaterialsIfNeeded((int)$orderId);
            }
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
