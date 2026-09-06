<?php
/**
 * Roll Service
 * Specialized logic for roll-based material tracking.
 * PrintFlow v2
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class RollService {

    /**
     * Create a new roll record.
     */
    public static function createRoll($itemId, $totalLength, $rollCode = null, $supplier = null, $widthFt = 0, $branchId = null) {
        global $conn;

        require_once __DIR__ . '/InventoryManager.php';
        InventoryManager::ensureBranchScopedSchema();
        $branchId = $branchId ?: InventoryManager::getCurrentBranchId();

        $sql = "INSERT INTO inv_rolls (item_id, branch_id, roll_code, width_ft, total_length_ft, remaining_length_ft, status, supplier, received_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'OPEN', ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisddds", $itemId, $branchId, $rollCode, $widthFt, $totalLength, $totalLength, $supplier);
        
        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            $stmt->close();
            return $id;
        }
        
        throw new Exception("Failed to create roll: " . $stmt->error);
    }

    /**
     * Deduct length from a specific roll.
     * Uses SELECT ... FOR UPDATE for concurrency safety.
     * 
     * @param int $rollId
     * @param float $lengthToDeduct
     * @param int $jobOrderId The ID of the job order for ledger reference
     * @param int|null $jobOrderMaterialId To mark as deducted (idempotency)
     * @return bool
     */
    public static function deductFromRoll($rollId, $lengthToDeduct, $jobOrderId, $jobOrderMaterialId = null) {
        global $conn;

        // 1. Lock the roll row
        $stmt = $conn->prepare("SELECT id, item_id, remaining_length_ft, status FROM inv_rolls WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $rollId);
        $stmt->execute();
        $roll = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$roll) throw new Exception("Roll ID #{$rollId} not found.");
        if ($roll['status'] !== 'OPEN') throw new Exception("Roll is no longer open.");
        
        if ($roll['remaining_length_ft'] < $lengthToDeduct) {
            throw new Exception("Insufficient length in roll. Available: {$roll['remaining_length_ft']}ft, Requested: {$lengthToDeduct}ft");
        }

        // 2. Perform Deduction
        $newRemaining = (float)$roll['remaining_length_ft'] - (float)$lengthToDeduct;
        
        // Auto-finish if remaining length is negligible
        $newStatus = ($newRemaining <= 0.01) ? 'FINISHED' : 'OPEN';
        $finAt = ($newStatus === 'FINISHED') ? date('Y-m-d H:i:s') : null;

        $update = $conn->prepare("UPDATE inv_rolls SET remaining_length_ft = ?, status = ?, finished_at = ? WHERE id = ?");
        $update->bind_param("dssi", $newRemaining, $newStatus, $finAt, $rollId);
        if (!$update->execute()) throw new Exception("Failed to update roll balance: " . $update->error);
        $update->close();

        // 3. Record in Ledger (InventoryManager handles the insertion)
        require_once __DIR__ . '/InventoryManager.php';
        $jobRef = printflow_get_job_inventory_reference((int)$jobOrderId);
        $jobLabel = $jobRef['label'] ?? ('Job #' . printflow_format_job_code((int)$jobOrderId));
        InventoryManager::recordTransaction(
            $roll['item_id'], 
            'OUT', 
            $lengthToDeduct, 
            'ft', 
            'JOB_ORDER', 
            $jobOrderId, 
            $rollId, 
            "Deducted for {$jobLabel}"
        );

        // 4. Update Job Order Material status (if ID provided) to mark as deducted
        if ($jobOrderMaterialId) {
            $stmt = $conn->prepare("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $jobOrderMaterialId);
            $stmt->execute();
            $stmt->close();
        }

        return true;
    }

    /**
     * Get available rolls for an item.
     */
    public static function getAvailableRolls($itemId, $branchId = null) {
        require_once __DIR__ . '/InventoryManager.php';
        InventoryManager::ensureBranchScopedSchema();
        [$branchSql, $branchTypes, $branchParams] = InventoryManager::branchClause('branch_id', $branchId);
        return db_query(
            "SELECT * FROM inv_rolls WHERE item_id = ? AND status = 'OPEN' AND remaining_length_ft > 0{$branchSql} ORDER BY received_at ASC",
            'i' . $branchTypes,
            array_merge([$itemId], $branchParams)
        ) ?: [];
    }

    /**
     * FIFO deduction across multiple rolls.
     * Deducts from the oldest available roll first, continuing to the next if needed.
     *
     * @param int $itemId The inventory item ID
     * @param float $totalLength Total length to deduct
     * @param string $refType Reference type for ledger (e.g. 'ADJUSTMENT', 'JOB_ORDER')
     * @param int|null $refId Reference ID (e.g. job order ID)
     * @param string $notes Notes for ledger entries
     * @return array Details of deductions made from each roll
     * @throws Exception If insufficient roll inventory
     */
    public static function deductFIFO($itemId, $totalLength, $refType = 'ADJUSTMENT', $refId = null, $notes = '', $branchId = null, $transactionDate = null) {
        global $conn;

        $totalLength = abs((float)$totalLength);
        if ($totalLength <= 0) {
            throw new Exception("Deduction quantity must be greater than zero.");
        }

        // 1. Get available rolls ordered by oldest first (FIFO)
        $rolls = self::getAvailableRolls($itemId, $branchId);

        // 2. Safety check: ensure enough total stock across all rolls
        $totalAvailable = 0;
        foreach ($rolls as $r) {
            $totalAvailable += (float)$r['remaining_length_ft'];
        }
        if ($totalAvailable < $totalLength) {
            throw new Exception("Insufficient roll inventory. Available: {$totalAvailable} ft, Requested: {$totalLength} ft");
        }

        // 3. Begin transaction for atomicity
        $wasInTransaction = $conn->in_transaction ?? false;
        if (!$wasInTransaction) {
            $conn->begin_transaction();
        }

        try {
            require_once __DIR__ . '/InventoryManager.php';

            $remaining = $totalLength;
            $deductions = [];

            foreach ($rolls as $roll) {
                if ($remaining <= 0) break;

                $rollId = (int)$roll['id'];
                $availableInRoll = (float)$roll['remaining_length_ft'];
                $deductAmt = min($remaining, $availableInRoll);

                if ($deductAmt <= 0) continue;

                // Lock the roll row for safe concurrent access
                $stmt = $conn->prepare("SELECT remaining_length_ft, status FROM inv_rolls WHERE id = ? FOR UPDATE");
                $stmt->bind_param("i", $rollId);
                $stmt->execute();
                $locked = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$locked || $locked['status'] !== 'OPEN') continue;

                // Re-check with locked data
                $lockedAvailable = (float)$locked['remaining_length_ft'];
                $deductAmt = min($remaining, $lockedAvailable);
                if ($deductAmt <= 0) continue;

                $newRemaining = $lockedAvailable - $deductAmt;
                $newStatus = ($newRemaining <= 0.01) ? 'FINISHED' : 'OPEN';
                $finAt = ($newStatus === 'FINISHED') ? date('Y-m-d H:i:s') : null;

                // Update roll
                $update = $conn->prepare("UPDATE inv_rolls SET remaining_length_ft = ?, status = ?, finished_at = ? WHERE id = ?");
                $update->bind_param("dssi", $newRemaining, $newStatus, $finAt, $rollId);
                if (!$update->execute()) {
                    throw new Exception("Failed to update roll #{$rollId}: " . $update->error);
                }
                $update->close();

                // Record ledger entry for this roll deduction
                $rollNote = $notes ?: "Manual FIFO deduction";
                $rollCode = $roll['roll_code'] ?? "Roll #{$rollId}";
                $ledgerNote = "{$rollNote} (Roll: {$rollCode})";

                InventoryManager::recordTransaction(
                    $itemId,
                    'OUT',
                    $deductAmt,
                    'ft',
                    $refType,
                    $refId,
                    $rollId,
                    $ledgerNote,
                    null,
                    $transactionDate,
                    $branchId
                );

                $deductions[] = [
                    'roll_id'     => $rollId,
                    'roll_code'   => $rollCode,
                    'deducted'    => $deductAmt,
                    'was'         => $lockedAvailable,
                    'now'         => $newRemaining,
                    'status'      => $newStatus
                ];

                $remaining -= $deductAmt;
            }

            if ($remaining > 0.01) {
                throw new Exception("FIFO deduction incomplete. Could not deduct {$remaining} ft from available rolls.");
            }

            if (!$wasInTransaction) {
                $conn->commit();
            }

            return $deductions;

        } catch (Throwable $e) {
            if (!$wasInTransaction && $conn->in_transaction) {
                $conn->rollback();
            }
            throw $e;
        }
    }
    
    /**
     * Mark a roll as void.
     */
    public static function voidRoll($rollId, $notes = '') {
        return db_execute(
            "UPDATE inv_rolls SET status = 'VOID', notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?",
            'si',
            ["\nVoided: " . $notes, $rollId]
        );
    }
}
