<?php
/**
 * Roll Service
 * Specialized logic for roll-based material tracking.
 * PrintFlow v2
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/roll_branch_scope.php';

class RollService {

    /**
     * Correct only the physical width metadata for an existing open roll.
     * Length balances and all inventory transactions are deliberately untouched.
     */
    public static function correctRollWidth(int $rollId, float $widthFt, int $actorUserId, string $verificationNote, int $expectedBranchId = 0): array {
        global $conn;
        require_once __DIR__ . '/InventoryManager.php';
        $verificationNote = trim($verificationNote);
        if ($rollId <= 0 || !is_finite($widthFt) || $widthFt <= 0 || $widthFt > 100 || $actorUserId <= 0 || $verificationNote === '') {
            throw new InvalidArgumentException('A valid roll, verified physical width, Admin identity, and verification note are required.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, item_id, branch_id, roll_code, width_ft, total_length_ft, remaining_length_ft, status FROM inv_rolls WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$stmt) throw new RuntimeException('Could not verify the selected roll.');
            $stmt->bind_param('i', $rollId);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Could not verify the selected roll.'); }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) throw new RuntimeException('The selected roll no longer exists.');
            if (strtoupper((string)$row['status']) !== 'OPEN') throw new RuntimeException('Only an open roll can have its physical width corrected.');
            if ($expectedBranchId > 0 && (int)$row['branch_id'] !== $expectedBranchId) throw new RuntimeException('The roll is outside the selected branch.');
            if ((float)$row['remaining_length_ft'] <= 0) throw new RuntimeException('The selected roll has no remaining length.');
            $item = InventoryManager::getItem((int)$row['item_id']);
            if (!$item || (int)($item['track_by_roll'] ?? 0) !== 1) throw new RuntimeException('The selected inventory item is not configured for roll tracking.');
            $oldWidthFt = (float)$row['width_ft'];
            if (abs($oldWidthFt - $widthFt) < 0.000001) {
                $conn->commit();
                return ['roll_id' => $rollId, 'old_width_ft' => $oldWidthFt, 'new_width_ft' => $widthFt, 'changed' => false];
            }

            $update = $conn->prepare("UPDATE inv_rolls SET width_ft = ? WHERE id = ? AND status = 'OPEN'");
            if (!$update) throw new RuntimeException('Could not prepare the roll-width correction.');
            $update->bind_param('di', $widthFt, $rollId);
            if (!$update->execute() || $update->affected_rows !== 1) { $update->close(); throw new RuntimeException('The roll width was not changed; refresh and try again.'); }
            $update->close();

            $verify = $conn->prepare("SELECT width_ft, total_length_ft, remaining_length_ft FROM inv_rolls WHERE id = ? LIMIT 1");
            if (!$verify) throw new RuntimeException('Could not verify the saved roll-width correction.');
            $verify->bind_param('i', $rollId);
            if (!$verify->execute()) { $verify->close(); throw new RuntimeException('Could not verify the saved roll-width correction.'); }
            $saved = $verify->get_result()->fetch_assoc();
            $verify->close();
            if (!$saved
                || abs((float)$saved['width_ft'] - $widthFt) > 0.000001
                || abs((float)$saved['total_length_ft'] - (float)$row['total_length_ft']) > 0.000001
                || abs((float)$saved['remaining_length_ft'] - (float)$row['remaining_length_ft']) > 0.000001) {
                throw new RuntimeException('Saved roll values failed verification; the correction was rolled back.');
            }

            $auditDetails = json_encode([
                'roll_id' => $rollId,
                'item_id' => (int)$row['item_id'],
                'branch_id' => (int)$row['branch_id'],
                'roll_code' => (string)($row['roll_code'] ?? ''),
                'old_width_ft' => $oldWidthFt,
                'new_width_ft' => (float)$saved['width_ft'],
                'physical_width_verification_note' => $verificationNote,
                'total_length_ft_preserved' => (float)$row['total_length_ft'],
                'remaining_length_ft_preserved' => (float)$row['remaining_length_ft'],
            ], JSON_THROW_ON_ERROR);
            $audit = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) SELECT user_id, 'Inventory roll width corrected', ?, NOW() FROM users WHERE user_id = ? AND role = 'Admin' LIMIT 1");
            if (!$audit) throw new RuntimeException('Could not prepare the correction audit record.');
            $audit->bind_param('si', $auditDetails, $actorUserId);
            if (!$audit->execute() || $audit->affected_rows !== 1) { $audit->close(); throw new RuntimeException('Admin audit verification failed; the width correction was rolled back.'); }
            $audit->close();
            $conn->commit();
            return ['roll_id' => $rollId, 'old_width_ft' => $oldWidthFt, 'new_width_ft' => $widthFt, 'changed' => true];
        } catch (Throwable $e) {
            if ($conn->in_transaction) $conn->rollback();
            throw $e;
        }
    }

    /**
     * Create a new roll record.
     */
    public static function createRoll($itemId, $totalLength, $rollCode = null, $supplier = null, $widthFt = 0, $branchId = null) {
        global $conn;
        require_once __DIR__ . '/roll_width.php';
        // Every intake path must supply a measured width; never create a roll
        // with a sentinel/default width when an older caller omits it.
        $widthFt = printflow_roll_width_to_feet($widthFt, 'ft');
        if (!is_numeric($totalLength) || !is_finite((float)$totalLength) || (float)$totalLength <= 0) {
            throw new InvalidArgumentException('Roll length must be a positive number in feet.');
        }

        require_once __DIR__ . '/InventoryManager.php';
        InventoryManager::ensureBranchScopedSchema();
        $branchId = $branchId ?: InventoryManager::getCurrentBranchId();
        if ((int)$branchId <= 0) throw new RuntimeException('A positive branch must be selected before receiving a roll.');

        $sql = "INSERT INTO inv_rolls (item_id, branch_id, roll_code, width_ft, total_length_ft, remaining_length_ft, status, supplier, received_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'OPEN', ?, NOW())";
        
        $ownsTransaction = !printflow_db_in_transaction($conn);
        if ($ownsTransaction && !$conn->begin_transaction()) throw new RuntimeException('Roll receipt transaction could not be started.');
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new RuntimeException('Roll receipt could not be prepared.');
            $stmt->bind_param("iisddds", $itemId, $branchId, $rollCode, $widthFt, $totalLength, $totalLength, $supplier);
            if (!$stmt->execute()) throw new RuntimeException('Roll receipt could not be saved.');
            $id = $stmt->insert_id;
            $stmt->close();
            $verify = $conn->prepare('SELECT item_id, branch_id, width_ft, total_length_ft, remaining_length_ft FROM inv_rolls WHERE id = ?');
            if (!$verify) throw new RuntimeException('Saved roll receipt could not be verified.');
            $verify->bind_param('i', $id);
            if (!$verify->execute()) throw new RuntimeException('Saved roll receipt could not be verified.');
            $saved = $verify->get_result()->fetch_assoc();
            $verify->close();
            printflow_assert_received_roll_values($saved ?: [], (int)$itemId, (int)$branchId, $widthFt, (float)$totalLength);
            if ($ownsTransaction && !$conn->commit()) throw new RuntimeException('Roll receipt could not be committed.');
            return $id;
        } catch (Throwable $error) {
            if ($ownsTransaction && printflow_db_in_transaction($conn)) $conn->rollback();
            throw $error;
        }
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
    public static function deductFromRoll($rollId, $lengthToDeduct, $jobOrderId, $jobOrderMaterialId = null, $transactionDate = null, $branchId = null) {
        global $conn;
        $wasInTransaction = printflow_db_in_transaction($conn);
        if (!$wasInTransaction) {
            $conn->begin_transaction();
        }

        try {
        // 1. Lock the roll row
        $stmt = $conn->prepare("SELECT id, item_id, branch_id, remaining_length_ft, status FROM inv_rolls WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $rollId);
        $stmt->execute();
        $roll = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($transactionDate === null || $transactionDate === '') {
            $jobRows = db_query('SELECT order_id FROM job_orders WHERE id = ? LIMIT 1', 'i', [(int)$jobOrderId]) ?: [];
            $linkedOrderId = $jobRows !== []
                ? (int)($jobRows[0]['order_id'] ?? 0)
                : (int)$jobOrderId;
            if ($linkedOrderId > 0 && function_exists('printflow_store_order_ledger_date')) {
                $transactionDate = printflow_store_order_ledger_date($linkedOrderId);
            }
        }
        if (function_exists('printflow_normalize_business_datetime') && $transactionDate !== null && $transactionDate !== '') {
            $transactionDate = printflow_normalize_business_datetime((string)$transactionDate) ?: $transactionDate;
        }

        if (!$roll) throw new Exception("Roll ID #{$rollId} not found.");
        if ($roll['status'] !== 'OPEN') throw new Exception("Roll is no longer open.");
        if ((int)$branchId <= 0 || !printflow_roll_belongs_to_branch($roll['branch_id'] ?? null, (int)$branchId)) {
            throw new RuntimeException('The selected roll is not assigned to this order branch. No roll stock was deducted.');
        }
        
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
            "Deducted for {$jobLabel}",
            null,
            $transactionDate,
            (int)($roll['branch_id'] ?? 0)
        );

        // 4. Update Job Order Material status (if ID provided) to mark as deducted
        if ($jobOrderMaterialId) {
            $stmt = $conn->prepare("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $jobOrderMaterialId);
            $stmt->execute();
            $stmt->close();
        }

        if (!$wasInTransaction) {
            $conn->commit();
        }
        return true;
        } catch (Throwable $e) {
            if (!$wasInTransaction && printflow_db_in_transaction($conn)) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    /**
     * Get available rolls for an item.
     */
    public static function getAvailableRolls($itemId, $branchId = null) {
        require_once __DIR__ . '/InventoryManager.php';
        InventoryManager::ensureBranchScopedSchema();
        $branchId = $branchId !== null ? (int)$branchId : InventoryManager::getCurrentBranchId();
        [$branchSql, $branchTypes, $branchParams] = printflow_roll_branch_sql('branch_id', $branchId);
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
        require_once __DIR__ . '/InventoryManager.php';
        $branchId = $branchId !== null ? (int)$branchId : InventoryManager::getCurrentBranchId();
        if ($branchId <= 0) throw new RuntimeException('A positive authorized branch is required before roll deduction.');

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
        $wasInTransaction = printflow_db_in_transaction($conn);
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
                $stmt = $conn->prepare("SELECT branch_id, remaining_length_ft, status FROM inv_rolls WHERE id = ? FOR UPDATE");
                $stmt->bind_param("i", $rollId);
                $stmt->execute();
                $locked = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$locked || $locked['status'] !== 'OPEN') continue;
                if (!printflow_roll_belongs_to_branch($locked['branch_id'] ?? null, $branchId)) {
                    throw new RuntimeException('A locked roll is no longer assigned to the requested branch. No roll stock was deducted.');
                }

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
            if (!$wasInTransaction && printflow_db_in_transaction($conn)) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    /** Deduct actual Tarpaulin usage only from the physical rolls in its validated POS plan. */
    public static function deductFromValidatedAllocation($itemId, array $allocationPlan, $actualLength, $refType = 'JOB_ORDER', $refId = null, $notes = '', $branchId = null, $transactionDate = null) {
        global $conn;
        require_once __DIR__ . '/InventoryManager.php';
        $itemId = (int)$itemId;
        $branchId = $branchId !== null ? (int)$branchId : InventoryManager::getCurrentBranchId();
        $actualLength = (float)$actualLength;
        $entries = array_values((array)$allocationPlan);
        if ($itemId <= 0 || $branchId <= 0 || !is_finite($actualLength) || $actualLength <= 0 || $entries === []) {
            throw new InvalidArgumentException('A valid Tarpaulin item, branch, usage, and validated roll allocation are required.');
        }
        $plannedByRoll = [];
        foreach ($entries as $entry) {
            $rollId = (int)($entry['roll_id'] ?? 0);
            $length = (float)($entry['length_ft'] ?? 0);
            if ($rollId <= 0 || !is_finite($length) || $length <= 0 || isset($plannedByRoll[$rollId])) {
                throw new RuntimeException('The saved Tarpaulin roll allocation is invalid or duplicated.');
            }
            $plannedByRoll[$rollId] = ['length_ft' => $length, 'roll_code' => (string)($entry['roll_code'] ?? ('Roll #' . $rollId)), 'roll_width_ft' => (float)($entry['roll_width_ft'] ?? 0)];
        }
        $plannedTotal = array_sum(array_column($plannedByRoll, 'length_ft'));
        if ($actualLength > $plannedTotal + 0.001) {
            throw new RuntimeException('Actual Tarpaulin usage exceeds the saved POS roll allocation. Revalidate the job before deduction.');
        }

        // Tarpaulin fit was planned using the linked inventory item's
        // configured width. Re-read that active item as the authoritative
        // width source; inv_rolls.width_ft is not a Tarpaulin fit input.
        $itemRows = db_query("SELECT id, name, status, track_by_roll, unit_of_measure, material_width, material_dimension_unit, category_id FROM inv_items WHERE id = ? LIMIT 1", 'i', [$itemId]);
        if (!is_array($itemRows) || count($itemRows) !== 1
            || strtoupper(trim((string)($itemRows[0]['status'] ?? ''))) !== 'ACTIVE') {
            throw new RuntimeException('The configured Tarpaulin inventory item is no longer active.');
        }
        $item = $itemRows[0];
        $categoryRows = db_query('SELECT name FROM inv_categories WHERE id = ? LIMIT 1', 'i', [(int)($item['category_id'] ?? 0)]) ?: [];
        $item['category_name'] = (string)($categoryRows[0]['name'] ?? '');
        require_once __DIR__ . '/tarpaulin_material_calculation.php';
        $configuredWidthFt = printflow_tarpaulin_parse_nominal_width($item);
        if ($configuredWidthFt <= 0) throw new RuntimeException('The Tarpaulin item has no valid configured width.');
        foreach ($plannedByRoll as $rollPlan) {
            if (abs((float)$rollPlan['roll_width_ft'] - $configuredWidthFt) > 0.01) {
                throw new RuntimeException('The saved Tarpaulin allocation width no longer matches the active item configuration. Revalidate the job.');
            }
        }

        [$branchSql, $branchTypes, $branchParams] = printflow_roll_branch_sql('branch_id', $branchId);
        $ids = array_keys($plannedByRoll);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $wasInTransaction = printflow_db_in_transaction($conn);
        if (!$wasInTransaction && !$conn->begin_transaction()) throw new RuntimeException('Unable to start the validated Tarpaulin deduction transaction.');
        try {
            $rolls = db_query(
                "SELECT id, item_id, branch_id, roll_code, width_ft, remaining_length_ft, status FROM inv_rolls WHERE item_id = ? AND id IN ({$placeholders}) AND status = 'OPEN' AND remaining_length_ft > 0{$branchSql} ORDER BY received_at ASC, id ASC FOR UPDATE",
                'i' . str_repeat('i', count($ids)) . $branchTypes,
                array_merge([$itemId], $ids, $branchParams)
            );
            if (!is_array($rolls) || count($rolls) !== count($ids)) throw new RuntimeException('One or more rolls in the validated Tarpaulin plan are no longer open at this branch. No other roll was used.');
            $byId = [];
            foreach ($rolls as $roll) $byId[(int)$roll['id']] = $roll;
            foreach ($plannedByRoll as $rollId => $rollPlan) {
                if (!isset($byId[$rollId])) throw new RuntimeException('A roll in the validated Tarpaulin plan is no longer available at this branch.');
                $roll = $byId[$rollId];
                // Width is item-configured for Tarpaulin. This row remains
                // authoritative for identity, branch, OPEN status and footage.
            }

            if ($transactionDate === null || $transactionDate === '') {
                $jobRows = db_query('SELECT order_id FROM job_orders WHERE id = ? LIMIT 1', 'i', [(int)$refId]) ?: [];
                $linkedOrderId = $jobRows !== [] ? (int)($jobRows[0]['order_id'] ?? 0) : (int)$refId;
                if ($linkedOrderId > 0 && function_exists('printflow_store_order_ledger_date')) $transactionDate = printflow_store_order_ledger_date($linkedOrderId);
            }
            if (function_exists('printflow_normalize_business_datetime') && $transactionDate !== null && $transactionDate !== '') {
                $transactionDate = printflow_normalize_business_datetime((string)$transactionDate) ?: $transactionDate;
            }

            $remaining = $actualLength;
            $deductions = [];
            foreach ($entries as $entry) {
                if ($remaining <= 0.000001) break;
                $rollId = (int)$entry['roll_id'];
                $roll = $byId[$rollId];
                $deduct = min($remaining, (float)$plannedByRoll[$rollId]['length_ft']);
                if ($deduct > (float)$roll['remaining_length_ft'] + 0.000001) throw new RuntimeException('A roll in the validated Tarpaulin plan has insufficient remaining footage. No other roll was used.');
                $newRemaining = max(0.0, (float)$roll['remaining_length_ft'] - $deduct);
                $newStatus = $newRemaining <= 0.01 ? 'FINISHED' : 'OPEN';
                $finishedAt = $newStatus === 'FINISHED' ? date('Y-m-d H:i:s') : null;
                $update = $conn->prepare("UPDATE inv_rolls SET remaining_length_ft = ?, status = ?, finished_at = ? WHERE id = ? AND item_id = ? AND status = 'OPEN'");
                if (!$update) throw new RuntimeException('Unable to prepare the validated Tarpaulin roll update.');
                $update->bind_param('dssii', $newRemaining, $newStatus, $finishedAt, $rollId, $itemId);
                if (!$update->execute() || $update->affected_rows !== 1) { $update->close(); throw new RuntimeException('Unable to update a roll in the validated Tarpaulin allocation.'); }
                $update->close();
                InventoryManager::recordTransaction($itemId, 'OUT', $deduct, 'ft', $refType, $refId, $rollId,
                    ($notes !== '' ? $notes : 'Tarpaulin usage') . ' (Roll: ' . (string)($roll['roll_code'] ?? ('Roll #' . $rollId)) . ')',
                    null, $transactionDate, $branchId);
                $deductions[] = ['roll_id' => $rollId, 'roll_code' => (string)($roll['roll_code'] ?? ('Roll #' . $rollId)),
                    'deducted' => $deduct, 'was' => (float)$roll['remaining_length_ft'], 'now' => $newRemaining, 'status' => $newStatus];
                $remaining -= $deduct;
            }
            if ($remaining > 0.001) throw new RuntimeException('The saved Tarpaulin plan could not cover the requested actual usage.');
            if (!$wasInTransaction && !$conn->commit()) throw new RuntimeException('Unable to commit the validated Tarpaulin deduction.');
            return $deductions;
        } catch (Throwable $error) {
            if (!$wasInTransaction && printflow_db_in_transaction($conn)) $conn->rollback();
            throw $error;
        }
    }

    /** Tarpaulin panel deduction: lock FIFO rolls and allocate each whole panel to one roll. */
    public static function deductWholePanelsFIFO($itemId, array $plan, $refType = 'JOB_ORDER', $refId = null, $notes = '', $branchId = null, $transactionDate = null) {
        global $conn;
        require_once __DIR__ . '/tarpaulin_material_calculation.php';
        $itemId = (int)$itemId;
        require_once __DIR__ . '/InventoryManager.php';
        $branchId = $branchId !== null ? (int)$branchId : InventoryManager::getCurrentBranchId();
        [$branchSql, $branchTypes, $branchParams] = printflow_roll_branch_sql('branch_id', $branchId);
        $panelCount = (int)($plan['panel_count_per_piece'] ?? 0) * (int)($plan['finished_quantity'] ?? 0);
        $panelLength = (float)($plan['run_length_per_panel_ft'] ?? 0);
        if ($itemId <= 0 || $panelCount < 1 || $panelCount > 100000 || !is_finite($panelLength) || $panelLength <= 0) {
            throw new InvalidArgumentException('The saved Tarpaulin panel plan is invalid.');
        }
        $wasInTransaction = printflow_db_in_transaction($conn);
        if (!$wasInTransaction && !$conn->begin_transaction()) throw new RuntimeException('Unable to start the Tarpaulin roll deduction transaction.');
        try {
            // The branch column must already exist; never broaden to unassigned rolls.
            $rolls = db_query(
                "SELECT id, roll_code, remaining_length_ft, received_at, status FROM inv_rolls WHERE item_id = ? AND status = 'OPEN' AND remaining_length_ft > 0{$branchSql} ORDER BY received_at ASC, id ASC FOR UPDATE",
                'i' . $branchTypes,
                array_merge([$itemId], $branchParams)
            );
            if (!is_array($rolls)) throw new RuntimeException('Open Tarpaulin rolls could not be locked for completion.');
            $allocation = printflow_tarpaulin_allocate_whole_panels($rolls, $plan);
            if (($allocation['reason'] ?? '') === 'search_limit') throw new RuntimeException('Safe Tarpaulin roll allocation reached its calculation limit; no inventory was deducted. Retry with a smaller job quantity.');
            if (empty($allocation['ok'])) throw new RuntimeException('Current open rolls cannot supply every Tarpaulin panel as one continuous piece.');
            $rollById = [];
            foreach ($rolls as $roll) $rollById[(int)$roll['id']] = $roll;
            $deductions = [];
            foreach ($allocation['allocations'] as $entry) {
                $rollId = (int)$entry['roll_id'];
                $roll = $rollById[$rollId] ?? null;
                $deduct = (float)$entry['length_ft'];
                if (!$roll || $deduct <= 0 || $deduct > (float)$roll['remaining_length_ft'] + 1.0e-9) throw new RuntimeException('The locked Tarpaulin roll allocation is no longer valid.');
                $newRemaining = max(0.0, (float)$roll['remaining_length_ft'] - $deduct);
                $newStatus = $newRemaining <= 0.01 ? 'FINISHED' : 'OPEN';
                $finishedAt = $newStatus === 'FINISHED' ? date('Y-m-d H:i:s') : null;
                $update = $conn->prepare("UPDATE inv_rolls SET remaining_length_ft = ?, status = ?, finished_at = ? WHERE id = ? AND status = 'OPEN'");
                if (!$update) throw new RuntimeException('Unable to prepare the Tarpaulin roll update.');
                $update->bind_param('dssi', $newRemaining, $newStatus, $finishedAt, $rollId);
                if (!$update->execute() || $update->affected_rows !== 1) { $error = $update->error; $update->close(); throw new RuntimeException('Unable to update the locked Tarpaulin roll: ' . $error); }
                $update->close();
                InventoryManager::recordTransaction($itemId, 'OUT', $deduct, 'ft', $refType, $refId, $rollId, ($notes ?: 'Tarpaulin panel material') . ' (Roll: ' . ($roll['roll_code'] ?? ('Roll #' . $rollId)) . ')', null, $transactionDate, $branchId);
                $deductions[] = ['roll_id' => $rollId, 'roll_code' => (string)($roll['roll_code'] ?? ('Roll #' . $rollId)), 'panels' => (int)$entry['panels'], 'deducted' => $deduct, 'was' => (float)$roll['remaining_length_ft'], 'now' => $newRemaining, 'status' => $newStatus];
            }
            if (!$wasInTransaction && !$conn->commit()) throw new RuntimeException('Unable to commit the Tarpaulin panel deduction.');
            return $deductions;
        } catch (Throwable $error) {
            if (!$wasInTransaction && printflow_db_in_transaction($conn)) $conn->rollback();
            throw $error;
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
