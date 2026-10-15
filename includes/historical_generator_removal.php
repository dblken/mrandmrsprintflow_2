<?php
declare(strict_types=1);

function htg_removal_dates(string $start, string $end): array {
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
        throw new RuntimeException('Enter a valid removal date range.');
    }
    if ($startDate > $endDate) throw new RuntimeException('Removal Start Date cannot be after End Date.');
    return [$startDate->format('Y-m-d 00:00:00'), $endDate->modify('+1 day')->format('Y-m-d 00:00:00')];
}

function htg_removal_orders(string $start = '', string $end = '', bool $lock = false, ?string $batchToken = null): array {
    global $conn;
    if (!db_table_has_column('orders', 'reference_id')) {
        throw new RuntimeException('Generator ownership markers are unavailable; removal is blocked for safety.');
    }
    $params = [];
    $types = '';
    $sql = "SELECT order_id, reference_id, order_date, branch_id, total_amount
            FROM orders
            WHERE TRIM(CAST(reference_id AS CHAR)) LIKE ?";
    $params[] = $batchToken !== null ? 'HGEN-' . $batchToken . '-%' : 'HGEN-%';
    $types .= 's';
    if ($batchToken === null) {
        [$from, $until] = htg_removal_dates($start, $end);
        // Match the historical business calendar date regardless of whether
        // order_date is stored with a time component or as a date value.
        $sql .= ' AND DATE(order_date) >= DATE(?) AND DATE(order_date) < DATE(?)';
        $params[] = $from;
        $params[] = $until;
        $types .= 'ss';
    }
    $sql .= " ORDER BY order_date ASC, order_id ASC";
    if ($lock) $sql .= ' FOR UPDATE';
    return db_query($sql, $types, $params) ?: [];
}

function htg_latest_generator_token(): ?string {
    $rows = db_query("SELECT reference_id FROM orders WHERE TRIM(CAST(reference_id AS CHAR)) LIKE 'HGEN-%' ORDER BY order_id DESC LIMIT 1") ?: [];
    $reference = (string)($rows[0]['reference_id'] ?? '');
    if (!preg_match('/^HGEN-([a-f0-9]{48})-(\d+)$/i', $reference, $match)) return null;
    return $match[1];
}

function htg_removal_preview(string $start, string $end, ?string $batchToken = null): array {
    $orders = htg_removal_orders($start, $end, false, $batchToken);
    $orderIds = array_values(array_map(static fn(array $row): int => (int)$row['order_id'], $orders));
    $items = $jobs = $customizations = $ledger = $productRestore = $materialRestore = [];
    $total = 0.0;
    foreach ($orders as $order) {
        $orderId = (int)$order['order_id'];
        $total += (float)$order['total_amount'];
        $orderItems = db_query('SELECT order_item_id, product_id, quantity FROM order_items WHERE order_id = ?', 'i', [$orderId]) ?: [];
        foreach ($orderItems as $item) {
            $items[] = $item + ['order_id' => $orderId];
            $productRestore[] = ['order_id' => $orderId, 'product_id' => (int)$item['product_id'], 'quantity' => (int)$item['quantity'], 'branch_id' => (int)$order['branch_id']];
        }
        $customizations = array_merge($customizations, db_query('SELECT order_id, order_item_id FROM customizations WHERE order_id = ?', 'i', [$orderId]) ?: []);
        $jobRows = db_query('SELECT id, order_id FROM job_orders WHERE order_id = ?', 'i', [$orderId]) ?: [];
        $jobs = array_merge($jobs, $jobRows);
        $jobIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $jobRows));
        if ($jobIds) {
            $marks = implode(',', array_fill(0, count($jobIds), '?'));
            $types = str_repeat('i', count($jobIds));
            $ledgerRows = db_query("SELECT item_id, roll_id, quantity, uom, ref_id FROM inventory_transactions WHERE UPPER(ref_type)='JOB_ORDER' AND ref_id IN ($marks) AND UPPER(direction)='OUT'", $types, $jobIds) ?: [];
            foreach ($ledgerRows as $row) {
                $ledger[] = $row + ['kind' => 'material'];
                $materialRestore[] = $row;
            }
        }
        $productRows = db_query("SELECT item_id, product_id, quantity, uom, ref_id FROM inventory_transactions WHERE UPPER(ref_type) IN ('ORDER','ORDER_PRODUCT') AND ref_id = ? AND UPPER(direction)='OUT'", 'i', [$orderId]) ?: [];
        foreach ($productRows as $row) $ledger[] = $row + ['kind' => 'product'];
    }
    return compact('orders', 'items', 'jobs', 'customizations', 'ledger', 'productRestore', 'materialRestore', 'total');
}

function htg_restore_generated_product(array $item, array $order): void {
    $productId = (int)$item['product_id'];
    $branchId = (int)$order['branch_id'];
    $quantity = (int)$item['quantity'];
    $row = db_query('SELECT customization_data FROM order_items WHERE order_item_id = ? LIMIT 1', 'i', [(int)($item['order_item_id'] ?? 0)]);
    $customization = json_decode((string)($row[0]['customization_data'] ?? ''), true);
    $customization = is_array($customization) ? $customization : [];
    if (function_exists('printflow_product_option_stock_has_rows') && printflow_product_option_stock_has_rows($productId, $branchId)) {
        $selection = printflow_product_option_stock_find_selected_value($productId, $customization);
        if ($selection === null) throw new RuntimeException('Cannot safely restore a generated product option stock row.');
        if (db_execute('UPDATE product_option_stock SET stock_quantity = stock_quantity + ? WHERE product_id = ? AND branch_id = ? AND field_key = ? AND option_value = ?', 'iiiss', [$quantity, $productId, $branchId, $selection['field_key'], $selection['option_value']]) === false) throw new RuntimeException('Product option stock restoration failed.');
        return;
    }
    if (printflow_product_branch_uses_base_stock($branchId)) {
        if (db_execute('UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?', 'ii', [$quantity, $productId]) === false) throw new RuntimeException('Product stock restoration failed.');
        return;
    }
    if (!printflow_product_branch_stock_set_quantity($productId, $branchId, (int)(printflow_product_effective_stock($productId, $branchId)[0] + $quantity))) throw new RuntimeException('Branch product stock restoration failed.');
}

function htg_remove_generated_range(string $start, string $end, ?string $batchToken = null): array {
    global $conn;
    $conn->begin_transaction();
    try {
        $orders = htg_removal_orders($start, $end, true, $batchToken);
        if (!$orders) {
            $conn->commit();
            return ['orders' => 0, 'total' => 0.0];
        }
        foreach ($orders as $order) {
            $orderId = (int)$order['order_id'];
            $items = db_query('SELECT order_item_id, product_id, quantity FROM order_items WHERE order_id = ? FOR UPDATE', 'i', [$orderId]) ?: [];
            $productLedger = db_query("SELECT id FROM inventory_transactions WHERE UPPER(ref_type) IN ('ORDER','ORDER_PRODUCT') AND ref_id = ? AND UPPER(direction)='OUT' FOR UPDATE", 'i', [$orderId]) ?: [];
            foreach ($items as $item) htg_restore_generated_product($item, $order);
            foreach ($productLedger as $movement) if (db_execute('DELETE FROM inventory_transactions WHERE id = ?', 'i', [(int)$movement['id']]) === false) throw new RuntimeException('Product inventory ledger removal failed.');
            $jobs = db_query('SELECT id FROM job_orders WHERE order_id = ? FOR UPDATE', 'i', [$orderId]) ?: [];
            foreach ($jobs as $job) {
                $jobId = (int)$job['id'];
                $materialRows = db_query("SELECT id, roll_id, quantity FROM inventory_transactions WHERE UPPER(ref_type)='JOB_ORDER' AND ref_id = ? AND UPPER(direction)='OUT' FOR UPDATE", 'i', [$jobId]) ?: [];
                foreach ($materialRows as $movement) {
                    $rollId = (int)($movement['roll_id'] ?? 0);
                    if ($rollId > 0 && db_execute("UPDATE inv_rolls SET remaining_length_ft = remaining_length_ft + ?, status = 'OPEN', finished_at = NULL WHERE id = ?", 'di', [(float)$movement['quantity'], $rollId]) === false) throw new RuntimeException('Roll inventory restoration failed.');
                    if (db_execute('DELETE FROM inventory_transactions WHERE id = ?', 'i', [(int)$movement['id']]) === false) throw new RuntimeException('Material inventory ledger removal failed.');
                }
                foreach (['job_order_ink_usage' => 'job_order_id', 'job_order_materials' => 'job_order_id', 'job_order_files' => 'job_order_id'] as $table => $column) if (db_table_has_column($table, $column) && db_execute("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$jobId]) === false) throw new RuntimeException('Job record removal failed.');
                if (db_execute('DELETE FROM job_orders WHERE id = ?', 'i', [$jobId]) === false) throw new RuntimeException('Job order removal failed.');
            }
            foreach (['customizations' => 'order_id', 'order_items' => 'order_id', 'order_status_history' => 'order_id', 'order_messages' => 'order_id'] as $table => $column) if (db_table_has_column($table, $column) && db_execute("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$orderId]) === false) throw new RuntimeException('Linked order record removal failed.');
            if (db_execute('DELETE FROM orders WHERE order_id = ?', 'i', [$orderId]) === false) throw new RuntimeException('Generated order removal failed.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        if (printflow_db_in_transaction($conn)) $conn->rollback();
        throw $e;
    }
    return ['orders' => count($orders), 'total' => round(array_sum(array_map(static fn(array $row): float => (float)$row['total_amount'], $orders)), 2)];
}
