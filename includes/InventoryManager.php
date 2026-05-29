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
            return [" AND ({$column} = ? OR {$column} IS NULL)", 'i', [$resolvedBranchId]];
        }

        return [" AND {$column} = ?", 'i', [$resolvedBranchId]];
    }

    /**
     * Records a new inventory transaction and enforces idempotency.
     */
    public static function recordTransaction($itemId, $direction, $quantity, $uom, $refType, $refId, $rollId = null, $notes = '', $userId = null, $date = null, $branchId = null) {
        global $conn;

        self::ensureBranchScopedSchema();

        $date = $date ?: date('Y-m-d');
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
        $stmt->bind_param($types, ...$values);
        
        try {
            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close();
                return $id;
            }
        } catch (Exception $e) {
            // Error 1062 is Duplicate Entry
            if (isset($conn->errno) && $conn->errno == 1062) {
                return true; 
            }
            throw new Exception("Ledger recording failed: " . $e->getMessage());
        }
        return false;
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
    public static function issueStock($itemId, $quantity, $uom = null, $refType = 'ADJUSTMENT', $refId = null, $notes = '', $ignoreRollCheck = false, $allowNegativeBypass = false, $branchId = null) {
        self::ensureBranchScopedSchema();

        $item = self::getItem($itemId);
        if (!$item) throw new Exception("Item not found.");
        $branchId = $branchId ?: self::getCurrentBranchId();
        
        // For roll-tracked items, route through FIFO deduction
        if ($item['track_by_roll'] && !$ignoreRollCheck) {
            require_once __DIR__ . '/RollService.php';
            return RollService::deductFIFO($itemId, $quantity, $refType, $refId, $notes, $branchId);
        }

        $soh = self::getStockOnHand($itemId, $branchId);
        // For roll-based items used with ignoreRollCheck, skip the SOH check since stock lives in inv_rolls
        $skipSohCheck = ($item['track_by_roll'] && $ignoreRollCheck) || $allowNegativeBypass;
        if (!$skipSohCheck && $soh < $quantity && !$item['allow_negative_stock']) {
            throw new Exception("Insufficient stock for '{$item['name']}'. Have: $soh, Need: $quantity");
        }

        $result = self::recordTransaction($itemId, 'OUT', $quantity, $uom ?: $item['unit_of_measure'], $refType, $refId, null, $notes, null, null, $branchId);

        // Fire stock alerts when SOH crosses critical or reorder thresholds
        if ($result && function_exists('notify_shop_users')) {
            $newSoh = self::getStockOnHand($itemId, $branchId);
            $reorder = printflow_item_reorder_level($item);
            $critical = printflow_item_critical_level($item);
            $status = printflow_resolve_stock_status($newSoh, $reorder, $critical);
            if ($status['key'] === 'critical' || $status['key'] === 'out') {
                $msg = "Critical stock: {$item['name']} is at {$newSoh} {$item['unit_of_measure']} (critical at {$critical})";
                notify_shop_users($msg, 'Stock', false, false, $itemId, ['Admin', 'Manager']);
            } elseif ($status['key'] === 'low') {
                $msg = "Low stock: {$item['name']} is at {$newSoh} {$item['unit_of_measure']} (reorder at {$reorder})";
                notify_shop_users($msg, 'Stock', false, false, $itemId, ['Admin', 'Manager']);
            }
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
            $sql = "SELECT SUM(IF(direction='IN', quantity, -quantity)) as soh FROM inventory_transactions WHERE item_id = ?{$branchSql}";
            $res = db_query($sql, 'i' . $branchTypes, array_merge([$itemId], $branchParams));
            return (float)($res[0]['soh'] ?? 0);
        }
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
        $orderId = $item[0]['order_id'] ?? 0;
        
        return RollService::deductFromRoll($rollId, $requiredLength, $orderId, null);
    }
}
