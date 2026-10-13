<?php
declare(strict_types=1);

/** Opt-in visibility predicates for customer-specific exclusions. */
if (!function_exists('printflow_customer_exclusion_schema_ready')) {
    function printflow_customer_exclusion_schema_ready(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        $rows = db_query(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            's',
            ['customer_exclusion']
        );
        return $ready = (int)($rows[0]['n'] ?? 0) === 1;
    }
}

if (!function_exists('printflow_customer_exclusion_set')) {
    /** Idempotently activate/deactivate one exact customer, order, or related-row exclusion. */
    function printflow_customer_exclusion_set(
        int $customerId,
        string $entityType,
        int $recordId,
        int $actorUserId,
        string $reason,
        ?int $orderId = null,
        string $recordTable = '',
        string $status = 'active'
    ): bool {
        if ($customerId <= 0 || $recordId <= 0 || $actorUserId <= 0) throw new InvalidArgumentException('Customer, record, and actor IDs must be positive.');
        $entityType = strtoupper(trim($entityType));
        $status = strtolower(trim($status));
        if (!in_array($entityType, ['CUSTOMER', 'ORDER', 'RECORD'], true) || !in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Invalid customer exclusion entity or status.');
        }
        if ($entityType === 'RECORD' && !preg_match('/^[A-Za-z0-9_]+$/D', $recordTable)) throw new InvalidArgumentException('A valid record table is required.');
        if ($entityType !== 'RECORD') $recordTable = '';
        if ($entityType === 'CUSTOMER') {
            $recordId = $customerId;
            $orderId = null;
        } elseif ($entityType === 'ORDER') {
            $orderId = $orderId ?: $recordId;
            if ($orderId !== $recordId) throw new InvalidArgumentException('Order exclusion IDs must match exactly.');
        } elseif ($orderId !== null && $orderId <= 0) {
            throw new InvalidArgumentException('Related order ID must be positive when provided.');
        }
        if (!printflow_customer_exclusion_schema_ready()) throw new RuntimeException('Customer exclusion migration is not ready.');
        $reason = trim($reason);
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($reason === '' || $reasonLength > 255) throw new InvalidArgumentException('Exclusion reason must contain 1 to 255 characters.');
        return (bool)db_execute(
            "INSERT INTO customer_exclusion (customer_id, entity_type, order_id, record_table, record_id, status, reason, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL)
             ON DUPLICATE KEY UPDATE order_id = VALUES(order_id), status = VALUES(status), reason = VALUES(reason), created_by = VALUES(created_by), updated_at = CURRENT_TIMESTAMP",
            'isisissi',
            [$customerId, $entityType, $orderId, $recordTable, $recordId, $status, $reason, $actorUserId]
        );
    }
}

if (!function_exists('printflow_customer_exclusion_valid_expression')) {
    function printflow_customer_exclusion_valid_expression(string $expression): bool
    {
        return preg_match('/^(?:[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)?|CAST\([A-Za-z][A-Za-z0-9_]*\.[A-Za-z][A-Za-z0-9_]* AS UNSIGNED\))$/D', $expression) === 1;
    }
}

if (!function_exists('printflow_customer_exclusion_order_scope_sql')) {
    /** SQL expressions must be application-owned identifiers, never user input. */
    function printflow_customer_exclusion_order_scope_sql(string $orderIdExpression, ?string $customerIdExpression = null): string
    {
        foreach (array_filter([$orderIdExpression, $customerIdExpression]) as $expression) {
            if (!printflow_customer_exclusion_valid_expression($expression)) {
                throw new InvalidArgumentException('Invalid customer exclusion expression.');
            }
        }
        if (!printflow_customer_exclusion_schema_ready()) return '1=1';
        $customerPredicate = $customerIdExpression !== null
            ? "ce.entity_type = 'CUSTOMER' AND ce.customer_id = {$customerIdExpression}"
            : "ce.entity_type = 'CUSTOMER' AND ce.customer_id = (SELECT ce_owner.customer_id FROM orders ce_owner WHERE ce_owner.order_id = {$orderIdExpression} LIMIT 1)";
        return "NOT EXISTS (SELECT 1 FROM customer_exclusion ce WHERE ce.status = 'active' AND (
            (ce.entity_type = 'ORDER' AND (ce.order_id = {$orderIdExpression} OR ce.record_id = {$orderIdExpression})) OR ({$customerPredicate})
        ))";
    }
}

if (!function_exists('printflow_customer_exclusion_customer_scope_sql')) {
    function printflow_customer_exclusion_customer_scope_sql(string $customerIdExpression): string
    {
        if (!printflow_customer_exclusion_valid_expression($customerIdExpression) || strpos($customerIdExpression, '.') === false) throw new InvalidArgumentException('A qualified customer exclusion expression is required.');
        if (!printflow_customer_exclusion_schema_ready()) return '1=1';
        return "NOT EXISTS (SELECT 1 FROM customer_exclusion ce_customer_only WHERE ce_customer_only.status='active' AND ce_customer_only.entity_type='CUSTOMER' AND ce_customer_only.customer_id={$customerIdExpression})";
    }
}

if (!function_exists('printflow_customer_exclusion_customer_is_active')) {
    function printflow_customer_exclusion_customer_is_active(int $customerId): bool
    {
        if ($customerId <= 0 || !printflow_customer_exclusion_schema_ready()) return false;
        $rows = db_query("SELECT 1 AS active FROM customer_exclusion WHERE status='active' AND entity_type='CUSTOMER' AND customer_id=? LIMIT 1", 'i', [$customerId]) ?: [];
        return !empty($rows);
    }
}

if (!function_exists('printflow_customer_exclusion_notification_scope_sql')) {
    function printflow_customer_exclusion_notification_scope_sql(string $alias = 'n'): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Invalid notification alias.');
        if (!printflow_customer_exclusion_schema_ready()) return '1=1';
        // Customer notifications store the recipient on customer_id; staff rows use user_id.
        // Do not reference notifications.user_type (column does not exist) or staff user_id
        // when evaluating customer archive exclusions.
        $customerPredicate = printflow_customer_exclusion_customer_scope_sql($alias . '.customer_id');
        $orderPredicate = printflow_customer_exclusion_order_scope_sql('CAST(' . $alias . '.data_id AS UNSIGNED)');
        $customerRowGuard = "({$alias}.customer_id IS NOT NULL)";
        $orderTypeGuard = "(LOWER(TRIM(COALESCE({$alias}.type, ''))) IN ('order', 'message')
            AND CAST(COALESCE({$alias}.data_id, '0') AS UNSIGNED) > 0)";
        return "((NOT {$customerRowGuard} OR {$customerPredicate}) AND (NOT {$orderTypeGuard} OR {$orderPredicate}))";
    }
}

if (!function_exists('printflow_customer_exclusion_payment_scope_sql')) {
    /** Exact parent/record exclusion for payment lists; no provider configuration is involved. */
    function printflow_customer_exclusion_payment_scope_sql(string $table, string $alias, string $idExpression, ?string $orderIdExpression = null, ?string $customerIdExpression = null): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Invalid payment exclusion scope.');
        $predicates = [printflow_customer_exclusion_record_scope_sql($table, $idExpression, $orderIdExpression)];
        if ($customerIdExpression !== null) $predicates[] = printflow_customer_exclusion_customer_scope_sql($customerIdExpression);
        return '(' . implode(' AND ', $predicates) . ')';
    }
}

if (!function_exists('printflow_customer_exclusion_record_scope_sql')) {
    /** Exclude a specific related row and, when supplied, inherit its order exclusion. */
    function printflow_customer_exclusion_record_scope_sql(string $tableName, string $recordIdExpression, ?string $orderIdExpression = null, ?string $customerIdExpression = null): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $tableName)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)?$/D', $recordIdExpression)
            || ($orderIdExpression !== null && !printflow_customer_exclusion_valid_expression($orderIdExpression))
            || ($customerIdExpression !== null && !printflow_customer_exclusion_valid_expression($customerIdExpression))) {
            throw new InvalidArgumentException('Invalid customer exclusion record scope.');
        }
        if (!printflow_customer_exclusion_schema_ready()) return '1=1';
        $predicates = ["NOT EXISTS (SELECT 1 FROM customer_exclusion ce_row WHERE ce_row.status = 'active' AND ce_row.entity_type = 'RECORD' AND ce_row.record_table = '{$tableName}' AND ce_row.record_id = {$recordIdExpression})"];
        if ($orderIdExpression !== null) $predicates[] = printflow_customer_exclusion_order_scope_sql($orderIdExpression);
        if ($customerIdExpression !== null) $predicates[] = printflow_customer_exclusion_customer_scope_sql($customerIdExpression);
        return '(' . implode(' AND ', $predicates) . ')';
    }
}

if (!function_exists('printflow_customer_exclusion_job_scope_sql')) {
    function printflow_customer_exclusion_job_scope_sql(string $jobAlias): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $jobAlias)) throw new InvalidArgumentException('Invalid job alias.');
        return '(' . printflow_customer_exclusion_order_scope_sql($jobAlias . '.order_id', $jobAlias . '.customer_id')
            . ' AND ' . printflow_customer_exclusion_record_scope_sql('job_orders', $jobAlias . '.id', $jobAlias . '.order_id') . ')';
    }
}

if (!function_exists('printflow_customer_exclusion_inventory_scope_sql')) {
    /** ORDER_PRODUCT is deliberately excluded from inference because ref_id is a shared product ID. */
    function printflow_customer_exclusion_inventory_scope_sql(string $ledgerAlias = 'it', string $tableName = 'inventory_transactions'): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $ledgerAlias) || !in_array($tableName, ['inventory_transactions', 'inventory_movements'], true)) throw new InvalidArgumentException('Invalid inventory scope.');
        if (!printflow_customer_exclusion_schema_ready()) return '1=1';
        $refType = $tableName === 'inventory_transactions' ? $ledgerAlias . '.ref_type' : $ledgerAlias . '.reference_type';
        $refId = $tableName === 'inventory_transactions' ? $ledgerAlias . '.ref_id' : $ledgerAlias . '.reference_id';
        return '(' . printflow_customer_exclusion_record_scope_sql($tableName, $ledgerAlias . '.id')
            . " AND NOT EXISTS (SELECT 1 FROM customer_exclusion ce_order WHERE ce_order.status='active' AND ce_order.entity_type='ORDER' AND UPPER(TRIM(COALESCE({$refType},'')))='ORDER' AND (ce_order.order_id={$refId} OR ce_order.record_id={$refId}))"
            . " AND NOT EXISTS (SELECT 1 FROM orders ce_order_owner JOIN customer_exclusion ce_customer ON ce_customer.status='active' AND ce_customer.entity_type='CUSTOMER' AND ce_customer.customer_id=ce_order_owner.customer_id WHERE UPPER(TRIM(COALESCE({$refType},'')))='ORDER' AND ce_order_owner.order_id={$refId})"
            . " AND NOT EXISTS (SELECT 1 FROM job_orders ce_job WHERE UPPER(TRIM(COALESCE({$refType},'')))='JOB_ORDER' AND ce_job.id={$refId} AND NOT " . printflow_customer_exclusion_job_scope_sql('ce_job') . ')'
            . " AND NOT EXISTS (SELECT 1 FROM change_item_requests ce_change WHERE UPPER(TRIM(COALESCE({$refType},'')))='CHANGE_ITEM' AND ce_change.change_item_id={$refId} AND NOT " . printflow_customer_exclusion_order_scope_sql('ce_change.order_id') . ')'
            . " AND NOT EXISTS (SELECT 1 FROM service_orders ce_service WHERE UPPER(TRIM(COALESCE({$refType},'')))='SERVICE_ORDER' AND ce_service.id={$refId} AND (EXISTS (SELECT 1 FROM customer_exclusion ce_service_customer WHERE ce_service_customer.status='active' AND ce_service_customer.entity_type='CUSTOMER' AND ce_service_customer.customer_id=ce_service.customer_id) OR EXISTS (SELECT 1 FROM customer_exclusion ce_service_record WHERE ce_service_record.status='active' AND ce_service_record.entity_type='RECORD' AND ce_service_record.record_table='service_orders' AND ce_service_record.record_id=ce_service.id)))" . ')';
    }
}
