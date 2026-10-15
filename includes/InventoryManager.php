<?php
/**
 * Inventory Manager (v2)
 * Unified service for all PrintFlow inventory types.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory_stock_status.php';

class InventoryManager {
    private static $branchSchemaEnsured = false;

    /**
     * Ensure branch-aware columns exist for inventory transactions and rolls.
     */
    public static function ensureBranchScopedSchema(): void {
        if (self::$branchSchemaEnsured) {
            return;
        }
        global $conn;
        if (printflow_db_in_transaction($conn)) {
            // Schema changes implicitly commit MySQL transactions. Inventory
            // movements must fail on missing schema instead of splitting atomic work.
            self::$branchSchemaEnsured = true;
            return;
        }

        try {
            $txnCols = db_query("SHOW COLUMNS FROM inventory_transactions LIKE 'branch_id'") ?: [];
            if (empty($txnCols)) {
                db_execute("ALTER TABLE inventory_transactions ADD COLUMN branch_id INT NULL AFTER item_id");
                db_execute("ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_branch_item_date (branch_id, item_id, transaction_date)");
            }
        } catch (Throwable $e) {
            // Ignore schema race/permission issues here and let downstream queries fail loudly if needed.
        }

        try {
            $rollCols = db_query("SHOW COLUMNS FROM inv_rolls LIKE 'branch_id'") ?: [];
            if (empty($rollCols)) {
                db_execute("ALTER TABLE inv_rolls ADD COLUMN branch_id INT NULL AFTER item_id");
                db_execute("ALTER TABLE inv_rolls ADD INDEX idx_inv_rolls_branch_item_status (branch_id, item_id, status)");
            }
        } catch (Throwable $e) {
            // Ignore schema race/permission issues here and let downstream queries fail loudly if needed.
        }

        self::$branchSchemaEnsured = true;
    }

    /**
     * Resolve the active operational branch for branch-scoped inventory pages.
     */
    public static function getCurrentBranchId(): int {
        if (function_exists('printflow_branch_filter_for_user')) {
            $locked = printflow_branch_filter_for_user();
            if ($locked !== null && (int)$locked > 0) {
                return (int)$locked;
            }
        }

        $selected = $_SESSION['selected_branch_id'] ?? null;
        if ($selected !== null && $selected !== 'all' && (int)$selected > 0) {
            return (int)$selected;
        }

        if (function_exists('printflow_get_default_admin_branch_id')) {
            return (int)printflow_get_default_admin_branch_id();
        }

        $sessionBranch = (int)($_SESSION['branch_id'] ?? 0);
        return $sessionBranch > 0 ? $sessionBranch : 1;
    }

    /**
     * Legacy inventory rows without a branch belong to the main branch.
     */
    public static function isMainBranch(int $branchId): bool {
        if ($branchId <= 0 || !function_exists('printflow_get_default_admin_branch_id')) {
            return false;
        }
        return $branchId === (int)printflow_get_default_admin_branch_id();
    }

    /**
     * Build a branch filter SQL fragment for branch-scoped inventory tables.
     *
     * @return array{0:string,1:string,2:array}
     */
    public static function branchClause(string $column, ?int $branchId = null): array {
        self::ensureBranchScopedSchema();

        if ($branchId !== null && (int)$branchId <= 0) {
            return ['', '', []];
        }

        $resolvedBranchId = $branchId ?? self::getCurrentBranchId();
        if ($resolvedBranchId <= 0) {
            return ['', '', []];
        }

        if (self::isMainBranch($resolvedBranchId)) {
            return [" AND ({$column} = ? OR {$column} IS NULL OR {$column} <= 0)", 'i', [$resolvedBranchId]];
        }

        return [" AND {$column} = ?", 'i', [$resolvedBranchId]];
    }

    /** Evaluate stored policy thresholds and notify only when alert tier changes. */
    private static function notifyStockLevelIfNeeded(array $item, int $itemId, ?int $branchId = null): void {
        $branchId = $branchId ?: self::getCurrentBranchId();
        $newSoh = self::getStockOnHand($itemId, $branchId);
        printflow_evaluate_stock_alert_notification($item, $itemId, $newSoh);
    }

    /**
     * Records a new inventory transaction and enforces idempotency.
     */
    public static function recordTransaction($itemId, $direction, $quantity, $uom, $refType, $refId, $rollId = null, $notes = '', $userId = null, $date = null, $branchId = null) {
        global $conn;

        self::ensureBranchScopedSchema();

        // $date is an optional final parameter on all public inventory calls.
        // Preserve a supplied POS transaction timestamp; legacy callers still
        // receive the current server timestamp by default.
        if (is_string($date) && trim($date) !== '' && function_exists('printflow_normalize_business_datetime')) {
            $date = printflow_normalize_business_datetime($date) ?: trim($date);
        } else {
            $date = $date ?: date('Y-m-d H:i:s');
        }
        $quantity = abs((float)$quantity);
        $userId = $userId ?: ($_SESSION['user_id'] ?? null);
        $branchId = $branchId ?: self::getCurrentBranchId();

        // Core fields
        $fields = [
            'item_id'          => ['type' => 'i', 'val' => $itemId],
            'branch_id'        => ['type' => 'i', 'val' => $branchId],
            'direction'        => ['type' => 's', 'val' => $direction],
            'quantity'         => ['type' => 's', 'val' => (string)$quantity],
            'uom'              => ['type' => 's', 'val' => $uom],
            'ref_type'         => ['type' => 's', 'val' => $refType],
            'notes'            => ['type' => 's', 'val' => $notes],
            'transaction_date' => ['type' => 's', 'val' => $date]
        ];

        // Optional fields
        if ($rollId !== null) $fields['roll_id'] = ['type' => 'i', 'val' => $rollId];
        if ($refId !== null)  $fields['ref_id']  = ['type' => 'i', 'val' => $refId];
        if ($userId !== null) $fields['created_by'] = ['type' => 'i', 'val' => $userId];

        $cols = array_keys($fields);
        $placeholders = array_fill(0, count($fields), '?');
        $types = implode('', array_column($fields, 'type'));
        $values = array_column($fields, 'val');

        $sql = "INSERT INTO inventory_transactions (" . implode(', ', $cols) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Ledger prepare failed: " . $conn->error);
        }
        $stmt->bind_param($types, ...$values);
        
        try {
            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close();
                return $id;
            }

            $error = $stmt->error ?: $conn->error;
            $errno = $stmt->errno ?: $conn->errno;
            $stmt->close();
            if ($errno == 1062 && strtoupper((string)$refType) !== 'JOB_ORDER') {
                return true;
            }
            throw new Exception("Ledger insert failed: " . $error);
        } catch (Exception $e) {
            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                @$stmt->close();
            }
            // Error 1062 is Duplicate Entry
            if (isset($conn->errno) && $conn->errno == 1062 && strtoupper((string)$refType) !== 'JOB_ORDER') {
                return true; 
            }
            throw new Exception("Ledger recording failed: " . $e->getMessage());
        }
    }

    /**
     * Receives new stock (IN).
     */
    public static function receiveStock($itemId, $quantity, $uom = null, $rollData = null, $refType = 'PURCHASE', $refId = null, $notes = '', $transactionDate = null, $branchId = null) {
        global $conn;

        self::ensureBranchScopedSchema();

        $item = self::getItem($itemId);
        if (!$item) throw new Exception("Item not found.");
        $branchId = $branchId ?: self::getCurrentBranchId();

        $conn->begin_transaction();
        try {
            $uom = $uom ?: $item['unit_of_measure'];
            $rollId = null;

            // Handle roll creation if it's a roll item
            if ($item['track_by_roll']) {
                require_once __DIR__ . '/RollService.php';
                $rollDataSafe = $rollData ?? [];
                $rollCode = $rollDataSafe['roll_code'] ?? '';
                if (empty($rollCode)) {
                    $rollCode = 'AUTO-' . strtoupper(substr($item['name'], 0, 3)) . '-' . date('YmdHis');
                }
                $rollId = RollService::createRoll(
                    $itemId, 
                    $quantity, // For new reception, total length = quantity received
                    $rollCode, 
                    $rollDataSafe['supplier'] ?? null,
                    $rollDataSafe['width_ft'] ?? 0,
                    $branchId
                );
            }

            // Record transaction
            $refType = strtoupper($refType ?: 'PURCHASE');
            self::recordTransaction($itemId, 'IN', $quantity, $uom, $refType, $refId, $rollId, $notes, null, $transactionDate, $branchId);

            $conn->commit();
            self::notifyStockLevelIfNeeded($item, $itemId, $branchId);
            return true;
        } catch (Throwable $e) {
            if ($conn->in_transaction) $conn->rollback();
            throw $e;
        }
    }

    /**
     * Issues stock (OUT).
     * For roll-tracked items, uses FIFO deduction across rolls automatically.
     * For non-roll items, records a simple OUT transaction.
     */
    public static function issueStock($itemId, $quantity, $uom = null, $refType = 'ADJUSTMENT', $refId = null, $notes = '', $ignoreRollCheck = false, $allowNegativeBypass = false, $branchId = null, $transactionDate = null) {
        self::ensureBranchScopedSchema();

        $item = self::getItem($itemId);
        if (!$item) throw new Exception("Item not found.");
        $branchId = $branchId ?: self::getCurrentBranchId();
        
        // For roll-tracked items, route through FIFO deduction
        if ($item['track_by_roll'] && !$ignoreRollCheck) {
            require_once __DIR__ . '/RollService.php';
            $result = RollService::deductFIFO($itemId, $quantity, $refType, $refId, $notes, $branchId, $transactionDate);
            if ($result) {
                self::notifyStockLevelIfNeeded($item, $itemId, $branchId);
            }
            return $result;
        }

        $soh = self::getStockOnHand($itemId, $branchId);
        // For roll-based items used with ignoreRollCheck, skip the SOH check since stock lives in inv_rolls
        $skipSohCheck = ($item['track_by_roll'] && $ignoreRollCheck) || $allowNegativeBypass;
        if (!$skipSohCheck && $soh < $quantity && !$item['allow_negative_stock']) {
            throw new Exception("Insufficient stock for '{$item['name']}'. Have: $soh, Need: $quantity");
        }

        $result = self::recordTransaction($itemId, 'OUT', $quantity, $uom ?: $item['unit_of_measure'], $refType, $refId, null, $notes, null, $transactionDate, $branchId);

        if ($result) {
            self::notifyStockLevelIfNeeded($item, $itemId, $branchId);
        }

        return $result;
    }

    /**
     * Gets accurate Stock On Hand based on v2 rules.
     */
    public static function getStockOnHand($itemId, $branchId = null) {
        self::ensureBranchScopedSchema();

        $item = self::getItem($itemId);
        if (!$item) return 0;
        if ($branchId === null) {
            $branchId = self::getCurrentBranchId();
        }

        if ($item['track_by_roll']) {
            // Stock is the sum of remaining lengths of all OPEN rolls
            [$branchSql, $branchTypes, $branchParams] = self::branchClause('branch_id', $branchId);
            $sql = "SELECT SUM(remaining_length_ft) as soh FROM inv_rolls WHERE item_id = ? AND status = 'OPEN'{$branchSql}";
            $res = db_query($sql, 'i' . $branchTypes, array_merge([$itemId], $branchParams));
            return (float)($res[0]['soh'] ?? 0);
        } else {
            // Stock is the sum of IN - sum of OUT transactions
            [$branchSql, $branchTypes, $branchParams] = self::branchClause('branch_id', $branchId);
            $sql = "SELECT SUM(IF(direction='IN', quantity, -quantity)) as soh FROM inventory_transactions WHERE item_id = ? AND UPPER(COALESCE(ref_type, '')) NOT IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER'){$branchSql}";
            $res = db_query($sql, 'i' . $branchTypes, array_merge([$itemId], $branchParams));
            return (float)($res[0]['soh'] ?? 0);
        }
    }

    /**
     * Stock On Hand for many items in a few grouped queries.
     *
     * Read-only. Applies exactly the same rules as getStockOnHand(): roll items
     * sum OPEN roll lengths, other items sum IN minus OUT ledger movements with
     * the same ref_type exclusions and branch clause. Items with no rows map to 0.
     *
     * @param int[] $itemIds
     * @return array<int, float> item_id => stock on hand
     */
    public static function getStockOnHandBulk(array $itemIds, $branchId = null): array {
        self::ensureBranchScopedSchema();

        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        if ($branchId === null) {
            $branchId = self::getCurrentBranchId();
        }

        $result = array_fill_keys($ids, 0.0);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $idTypes = str_repeat('i', count($ids));

        $items = db_query("SELECT id, track_by_roll FROM inv_items WHERE id IN ({$placeholders})", $idTypes, $ids) ?: [];
        $rollIds = [];
        $ledgerIds = [];
        foreach ($items as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            if (!empty($row['track_by_roll'])) {
                $rollIds[] = $id;
            } else {
                $ledgerIds[] = $id;
            }
        }

        [$branchSql, $branchTypes, $branchParams] = self::branchClause('branch_id', $branchId);

        if ($rollIds !== []) {
            $ph = implode(',', array_fill(0, count($rollIds), '?'));
            $rows = db_query(
                "SELECT item_id, SUM(remaining_length_ft) AS soh FROM inv_rolls WHERE item_id IN ({$ph}) AND status = 'OPEN'{$branchSql} GROUP BY item_id",
                str_repeat('i', count($rollIds)) . $branchTypes,
                array_merge($rollIds, $branchParams)
            ) ?: [];
            foreach ($rows as $row) {
                $result[(int)$row['item_id']] = (float)($row['soh'] ?? 0);
            }
        }

        if ($ledgerIds !== []) {
            $ph = implode(',', array_fill(0, count($ledgerIds), '?'));
            $rows = db_query(
                "SELECT item_id, SUM(IF(direction='IN', quantity, -quantity)) AS soh FROM inventory_transactions WHERE item_id IN ({$ph}) AND UPPER(COALESCE(ref_type, '')) NOT IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER'){$branchSql} GROUP BY item_id",
                str_repeat('i', count($ledgerIds)) . $branchTypes,
                array_merge($ledgerIds, $branchParams)
            ) ?: [];
            foreach ($rows as $row) {
                $result[(int)$row['item_id']] = (float)($row['soh'] ?? 0);
            }
        }

        return $result;
    }

    /**
     * Get item details.
     */
    public static function getItem($id) {
        $res = db_query("SELECT * FROM inv_items WHERE id = ?", 'i', [$id]);
        return $res[0] ?? null;
    }

    /**
     * Convenience method for roll deduction (used by TarpaulinService).
     */
    public static function deductRollMaterial($orderItemId, $rollId, $requiredLength) {
        require_once __DIR__ . '/RollService.php';
        // We use orderItemId as the jobOrderId for now, or we might need to look up the order_id
        $item = db_query("SELECT order_id FROM order_items WHERE order_item_id = ?", 'i', [$orderItemId]);
        $orderId = (int)($item[0]['order_id'] ?? 0);
        $transactionDate = ($orderId > 0 && function_exists('printflow_store_order_ledger_date'))
            ? printflow_store_order_ledger_date($orderId)
            : null;

        return RollService::deductFromRoll($rollId, $requiredLength, $orderId, null, $transactionDate);
    }
}
