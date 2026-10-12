<?php
declare(strict_types=1);

/** Read-only, Admin-only Jenny Ferr cleanup scope audit. This endpoint never mutates data. */
require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_admin() || (int)($_SESSION['user_id'] ?? 0) < 1) {
    printflow_json_response(['success' => false, 'error' => 'Admin authorization is required.'], 403);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    printflow_json_response(['success' => false, 'error' => 'This audit endpoint accepts GET only.'], 405);
}

const PF_JENNY_AUDIT_CUSTOMER_ID = 25;
const PF_JENNY_AUDIT_VERSION = 'jenny-admin-preview-20261012-v3';

function pf_jenny_audit_log(string $stage, string $table, int $errno, string $errorType): void
{
    error_log('[jenny_cleanup_audit] ' . json_encode([
        'stage' => preg_replace('/[^a-z0-9_.-]/i', '', $stage),
        'table' => preg_match('/^[a-z0-9_]+$/i', $table) ? $table : 'unknown',
        'customer_id' => PF_JENNY_AUDIT_CUSTOMER_ID,
        'db_errno' => max(0, $errno),
        'error_type' => preg_replace('/[^a-z0-9_.-]/i', '', $errorType),
    ], JSON_UNESCAPED_SLASHES));
}

/** Retry only transient database failures. Return sanitized stage metadata on failure. */
function pf_jenny_audit_query(string $stage, string $table, string $sql, string $types = '', array $params = []): array
{
    $transient = [1205, 1213, 2006, 2013];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $before = count($GLOBALS['printflow_db_errors'] ?? []);
        try {
            $rows = db_query($sql, $types, $params);
            $errors = $GLOBALS['printflow_db_errors'] ?? [];
            if (is_array($rows) && count($errors) === $before) {
                return ['rows' => $rows, 'failure' => null, 'attempts' => $attempt + 1];
            }
            $last = $errors ? end($errors) : [];
            $errno = (int)($last['errno'] ?? 0);
            $type = 'database_query_failure';
            if ($attempt === 0 && in_array($errno, $transient, true)) {
                usleep(100000);
                continue;
            }
            pf_jenny_audit_log($stage, $table, $errno, $type);
            return ['rows' => [], 'failure' => ['stage' => $stage, 'table' => $table, 'db_error_code' => $errno, 'error_type' => $type, 'attempts' => $attempt + 1]];
        } catch (Throwable $e) {
            $errno = (int)$e->getCode();
            $type = (new ReflectionClass($e))->getShortName();
            if ($attempt === 0 && in_array($errno, $transient, true)) {
                usleep(100000);
                continue;
            }
            pf_jenny_audit_log($stage, $table, $errno, $type);
            return ['rows' => [], 'failure' => ['stage' => $stage, 'table' => $table, 'db_error_code' => $errno, 'error_type' => $type, 'attempts' => $attempt + 1]];
        }
    }
    return ['rows' => [], 'failure' => ['stage' => $stage, 'table' => $table, 'db_error_code' => 0, 'error_type' => 'retry_exhausted', 'attempts' => 2]];
}

function pf_jenny_audit_ident(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) throw new RuntimeException('Invalid schema identifier.');
    return '`' . $identifier . '`';
}

// The names below are table names only. Record IDs and scope are always derived from the live DB.
$relationships = [
    'order_items' => ['order_id', 'orders'], 'customizations' => ['order_id', 'orders'],
    'job_orders' => ['order_id', 'orders'], 'order_messages' => ['order_id', 'orders'],
    'order_notes' => ['order_id', 'orders'], 'order_status_history' => ['order_id', 'orders'],
    'order_item_revisions' => ['order_id', 'orders'], 'order_revision_requests' => ['order_id', 'orders'],
    'change_item_requests' => ['order_id', 'orders'], 'reviews' => ['order_id', 'orders'],
    'order_designs' => ['order_id', 'orders'], 'order_tarp_details' => ['order_item_id', 'order_items'],
    'material_usage_logs' => ['order_id', 'orders'],
    'receipt_print_jobs' => ['order_id', 'orders'], 'printflow_order_archive_map' => ['order_id', 'orders'],
    'printflow_order_archive_events' => ['order_id', 'orders'],
    'maintenance_synthetic_demo_order_rows' => ['order_id', 'orders'],
    'service_order_details' => ['order_id', 'service_orders'], 'service_order_files' => ['order_id', 'service_orders'],
    'job_order_materials' => ['job_order_id', 'job_orders'], 'job_order_ink_usage' => ['job_order_id', 'job_orders'],
    'job_order_files' => ['job_order_id', 'job_orders'],
    'change_item_evidence' => ['change_item_id', 'change_item_requests'],
    'review_images' => ['review_id', 'reviews'], 'review_replies' => ['review_id', 'reviews'],
    'review_helpful' => ['review_id', 'reviews'],
    'message_reactions' => ['message_id', 'order_messages'],
    'notification_logs' => ['notification_id', 'notifications'], 'push_notification_queue' => ['notification_id', 'notifications'],
    'chatbot_messages' => ['conversation_id', 'chatbot_conversations'],
    'provider_payment_status_history' => ['provider_payment_id', 'provider_payments'],
    'provider_webhook_events' => ['provider_payment_id', 'provider_payments'],
    'receipt_print_job_events' => ['job_id', 'receipt_print_jobs'],
];
$candidates = array_values(array_unique(array_merge(
    ['orders', 'service_orders', 'customer_cart', 'chatbot_conversations', 'chatbot_inquiries', 'payment_submissions', 'provider_payments', 'notifications', 'notification_logs', 'inventory_transactions', 'inventory_movements', 'customer_review_prompt_dismissals', 'reviews', 'review_images', 'review_replies', 'review_helpful', 'receipt_print_jobs', 'receipt_print_job_events', 'review_cleanup_audit', 'customer_testing_data_cleanup_audit'],
    array_keys($relationships)
)));

$failures = [];
$records = [];
$schema = [];
$tableExists = [];
$primaryKey = [];
$scopeIds = [];
$scopeIds['orders'] = [];
$scopeIds['service_orders'] = [];
$foreignKeyEdges = [];
$foreignKeyFailures = [];

// First verify which candidate tables exist using the selected application's database.
$tableNames = array_map(static fn($name) => (string)$name, $candidates);
$tableMarks = implode(',', array_fill(0, count($tableNames), '?'));
$tableCheck = pf_jenny_audit_query('schema_table_inventory', 'INFORMATION_SCHEMA.TABLES',
    'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $tableMarks . ')', str_repeat('s', count($tableNames)), $tableNames);
if ($tableCheck['failure'] !== null) {
    $failures[] = $tableCheck['failure'];
    $presentTables = [];
} else {
    $presentTables = array_fill_keys(array_map(static fn($r) => (string)$r['TABLE_NAME'], $tableCheck['rows']), true);
}

// Discover additional tables using ownership/relationship columns from live schema metadata.
$relationshipColumns = ['customer_id','order_id','order_item_id','job_order_id','message_id','review_id','notification_id','conversation_id','provider_payment_id','change_item_id','job_id','ref_type','ref_id','reference_type','reference_id'];
$columnMarks = implode(',', array_fill(0, count($relationshipColumns), '?'));
$schemaInventory = pf_jenny_audit_query('schema_relationship_discovery', 'INFORMATION_SCHEMA.COLUMNS',
    'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN (' . $columnMarks . ')', str_repeat('s', count($relationshipColumns)), $relationshipColumns);
if ($schemaInventory['failure'] !== null) {
    $failures[] = $schemaInventory['failure'];
} else {
    foreach ($schemaInventory['rows'] as $columnRow) {
        $name = (string)($columnRow['TABLE_NAME'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/D', $name)) $candidates[] = $name;
    }
    $candidates = array_values(array_unique($candidates));
    $tableNames = $candidates;
    $tableMarks = implode(',', array_fill(0, count($tableNames), '?'));
    $tableCheck = pf_jenny_audit_query('schema_table_inventory', 'INFORMATION_SCHEMA.TABLES',
        'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $tableMarks . ')', str_repeat('s', count($tableNames)), $tableNames);
    if ($tableCheck['failure'] !== null) {
        $failures[] = $tableCheck['failure'];
        $presentTables = [];
    } else {
        $presentTables = array_fill_keys(array_map(static fn($r) => (string)$r['TABLE_NAME'], $tableCheck['rows']), true);
    }
}

// Inspect each candidate independently so one schema/query failure cannot hide the rest.
foreach ($candidates as $table) {
    if (!isset($presentTables[$table])) {
        $tableExists[$table] = false;
        continue;
    }
    $tableQ = $table;
    $q = pf_jenny_audit_query('schema_inspection', $table, "SHOW COLUMNS FROM `{$tableQ}`");
    if ($q['failure'] !== null) {
        $failures[] = $q['failure'];
        $tableExists[$table] = false;
        continue;
    }
    if (!$q['rows']) {
        $tableExists[$table] = false;
        continue;
    }
    $tableExists[$table] = true;
    $schema[$table] = array_values(array_map(static fn($r) => (string)$r['Field'], $q['rows']));
    $pk = array_values(array_map(static fn($r) => (string)$r['Field'], array_filter($q['rows'], static fn($r) => (string)($r['Key'] ?? '') === 'PRI')));
    $primaryKey[$table] = count($pk) === 1 ? $pk[0] : null;
}

// Add additional discovered child edges only when the child column and parent key exist in this live schema.
$knownParentByFk = [
    'order_id' => 'orders', 'order_item_id' => 'order_items', 'job_order_id' => 'job_orders',
    'message_id' => 'order_messages', 'review_id' => 'reviews', 'notification_id' => 'notifications',
    'conversation_id' => 'chatbot_conversations', 'provider_payment_id' => 'provider_payments',
    'change_item_id' => 'change_item_requests', 'job_id' => 'receipt_print_jobs',
];
foreach ($schema as $table => $columns) {
    foreach ($knownParentByFk as $fk => $parent) {
        if ($table !== $parent && in_array($fk, $columns, true) && isset($schema[$parent]) && $primaryKey[$table]) {
            $relationships[$table] ??= [$fk, $parent];
            break;
        }
    }
}

// Read real declared foreign-key relationships so descendants are not limited
// to the hand-maintained relationship list above. Only edges whose referenced
// key is the parent's inspected primary key can be followed automatically.
$fkQuery = pf_jenny_audit_query('schema_foreign_key_discovery', 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE',
    'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
       FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY REFERENCED_TABLE_NAME, TABLE_NAME, COLUMN_NAME');
if ($fkQuery['failure'] !== null) {
    $failures[] = $fkQuery['failure'];
} else {
    foreach ($fkQuery['rows'] as $edge) {
        $child = (string)($edge['TABLE_NAME'] ?? '');
        $fk = (string)($edge['COLUMN_NAME'] ?? '');
        $parent = (string)($edge['REFERENCED_TABLE_NAME'] ?? '');
        $parentColumn = (string)($edge['REFERENCED_COLUMN_NAME'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/D', $child)
            && preg_match('/^[A-Za-z0-9_]+$/D', $fk)
            && preg_match('/^[A-Za-z0-9_]+$/D', $parent)
            && preg_match('/^[A-Za-z0-9_]+$/D', $parentColumn)) {
            $foreignKeyEdges[] = [$child, $fk, $parent, $parentColumn];
        }
    }
}

// Seed the exact customer scope before traversing FK edges. The later
// verification pass repeats these reads after relationship discovery so the
// final response still reflects the normal audit stages.
foreach (['orders' => 'order_id', 'service_orders' => 'id'] as $root => $pk) {
    if (!isset($schema[$root]) || !in_array($pk, $schema[$root], true)) continue;
    $q = pf_jenny_audit_query('fk_seed_root_customer_scope', $root,
        'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM ' . pf_jenny_audit_ident($root) . ' WHERE customer_id = ? ORDER BY ' . pf_jenny_audit_ident($pk), 'i', [PF_JENNY_AUDIT_CUSTOMER_ID]);
    if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
    $scopeIds[$root] = array_values(array_map('intval', array_column($q['rows'], 'record_id')));
}
foreach ($schema as $table => $columns) {
    if ($table === 'customers' || !in_array('customer_id', $columns, true) || !$primaryKey[$table]) continue;
    $pk = $primaryKey[$table];
    $q = pf_jenny_audit_query('fk_seed_direct_customer_scope', $table,
        'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM ' . pf_jenny_audit_ident($table) . ' WHERE customer_id = ? ORDER BY ' . pf_jenny_audit_ident($pk), 'i', [PF_JENNY_AUDIT_CUSTOMER_ID]);
    if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
    $scopeIds[$table] = array_values(array_unique(array_merge($scopeIds[$table] ?? [], array_map('intval', array_column($q['rows'], 'record_id')))));
}
// Provider payment rows have an explicit polymorphic order relationship in
// addition to customer_id. Reconcile both sources before following history and
// webhook descendants; this remains a read-only exact-ID lookup.
if (!empty($schema['provider_payments']) && $primaryKey['provider_payments']
    && in_array('subject_type', $schema['provider_payments'], true)
    && in_array('subject_id', $schema['provider_payments'], true)) {
    $paymentIds = [];
    foreach (array_chunk($scopeIds['orders'] ?? [], 80) as $batchNo => $batch) {
        $marks = implode(',', array_fill(0, count($batch), '?'));
        $pk = $primaryKey['provider_payments'];
        $q = pf_jenny_audit_query('provider_payment_order_subject_batch_' . ($batchNo + 1), 'provider_payments',
            'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM `provider_payments` WHERE LOWER(`subject_type`) = ? AND `subject_id` IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk),
            's' . str_repeat('i', count($batch)), array_merge(['order'], $batch));
        if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
        $paymentIds = array_merge($paymentIds, array_map('intval', array_column($q['rows'], 'record_id')));
    }
    $scopeIds['provider_payments'] = array_values(array_unique(array_merge($scopeIds['provider_payments'] ?? [], $paymentIds)));
}

// Resolve newly reachable FK tables lazily and query each edge in small batches.
// This finds real child paths (including nonstandard FK names) without scanning
// unrelated rows or guessing relationship column names.
$fkEdgeSeen = [];
$fkProcessedParentIds = [];
for ($pass = 0; $pass < count($foreignKeyEdges) + 2; $pass++) {
    $changed = false;
    foreach ($foreignKeyEdges as [$child, $fk, $parent, $parentColumn]) {
        if (empty($scopeIds[$parent])) continue;
        $edgeKey = $child . '.' . $fk . '->' . $parent . '.' . $parentColumn;
        $pendingParentIds = array_values(array_diff($scopeIds[$parent], $fkProcessedParentIds[$edgeKey] ?? []));
        if (!$pendingParentIds) continue;
        if (!isset($schema[$child])) {
            $tableQ = pf_jenny_audit_ident($child);
            $q = pf_jenny_audit_query('foreign_key_child_schema', $child, "SHOW COLUMNS FROM {$tableQ}");
            if ($q['failure'] !== null) {
                $failures[] = $q['failure'];
                $fkProcessedParentIds[$edgeKey] = array_values(array_unique(array_merge($fkProcessedParentIds[$edgeKey] ?? [], $pendingParentIds)));
                continue;
            }
            if (!$q['rows']) {
                $fkProcessedParentIds[$edgeKey] = array_values(array_unique(array_merge($fkProcessedParentIds[$edgeKey] ?? [], $pendingParentIds)));
                continue;
            }
            $schema[$child] = array_values(array_map(static fn($r) => (string)$r['Field'], $q['rows']));
            $pk = array_values(array_map(static fn($r) => (string)$r['Field'], array_filter($q['rows'], static fn($r) => (string)($r['Key'] ?? '') === 'PRI')));
            $primaryKey[$child] = count($pk) === 1 ? $pk[0] : null;
            $tableExists[$child] = true;
        }
        if (!$primaryKey[$child] || !in_array($fk, $schema[$child], true)) {
            $foreignKeyFailures[$edgeKey] = ['table' => $child, 'column' => $fk, 'parent_table' => $parent, 'parent_column' => $parentColumn, 'reason' => 'child_schema_not_safely_queryable'];
            $fkProcessedParentIds[$edgeKey] = array_values(array_unique(array_merge($fkProcessedParentIds[$edgeKey] ?? [], $pendingParentIds)));
            continue;
        }
        if (!isset($schema[$parent]) || $primaryKey[$parent] !== $parentColumn) {
            $foreignKeyFailures[$edgeKey] = ['table' => $child, 'column' => $fk, 'parent_table' => $parent, 'parent_column' => $parentColumn, 'reason' => 'referenced_key_not_in_audited_parent_scope'];
            $fkProcessedParentIds[$edgeKey] = array_values(array_unique(array_merge($fkProcessedParentIds[$edgeKey] ?? [], $pendingParentIds)));
            continue;
        }
        $foreignKeyEdgesForOutput[$edgeKey] = true;
        $beforeCount = count($scopeIds[$child] ?? []);
        foreach (array_chunk($pendingParentIds, 80) as $batchNo => $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $pk = $primaryKey[$child];
            $q = pf_jenny_audit_query('foreign_key_scope_batch_' . ($batchNo + 1), $child,
                'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM ' . pf_jenny_audit_ident($child) . ' WHERE ' . pf_jenny_audit_ident($fk) . ' IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk), str_repeat('i', count($batch)), $batch);
            if ($q['failure'] !== null) {
                $failures[] = $q['failure'];
                $fkProcessedParentIds[$edgeKey] = array_values(array_unique(array_merge($fkProcessedParentIds[$edgeKey] ?? [], $batch)));
                continue;
            }
            $scopeIds[$child] = array_values(array_unique(array_merge($scopeIds[$child] ?? [], array_map('intval', array_column($q['rows'], 'record_id')))));
            $fkProcessedParentIds[$edgeKey] = array_values(array_unique(array_merge($fkProcessedParentIds[$edgeKey] ?? [], $batch)));
        }
        if (count($scopeIds[$child] ?? []) > $beforeCount) $changed = true;
        $fkEdgeSeen[$edgeKey] = true;
    }
    if (!$changed) break;
}

$directCustomerTables = [];
foreach ($schema as $table => $columns) {
    if (in_array('customer_id', $columns, true)) $directCustomerTables[] = $table;
}

// Direct ownership query per table, no customer names/emails or broad text matching.
foreach ($directCustomerTables as $table) {
    if ($table === 'customers' || !$primaryKey[$table]) continue;
    $pk = $primaryKey[$table];
    $q = pf_jenny_audit_query('direct_customer_scope', $table,
        'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM ' . pf_jenny_audit_ident($table) . ' WHERE `customer_id` = ? ORDER BY ' . pf_jenny_audit_ident($pk), 'i', [PF_JENNY_AUDIT_CUSTOMER_ID]);
    if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
    $ids = array_values(array_map('intval', array_column($q['rows'], 'record_id')));
    $scopeIds[$table] = array_values(array_unique(array_merge($scopeIds[$table] ?? [], $ids)));
}

// Seed roots from exact authenticated customer ID, then resolve each child edge in small ID batches.
foreach (['orders' => 'order_id', 'service_orders' => 'id'] as $root => $pk) {
    if (!isset($schema[$root]) || !in_array($pk, $schema[$root], true)) continue;
    $q = pf_jenny_audit_query('root_customer_scope', $root,
        'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM ' . pf_jenny_audit_ident($root) . ' WHERE customer_id = ? ORDER BY ' . pf_jenny_audit_ident($pk), 'i', [PF_JENNY_AUDIT_CUSTOMER_ID]);
    if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
    $scopeIds[$root] = array_values(array_map('intval', array_column($q['rows'], 'record_id')));
}

// Iterate the declared relationship graph until stable. Edge columns are used only if present in live schema.
for ($pass = 0; $pass < 4; $pass++) {
    $changed = false;
    foreach ($relationships as $child => [$fk, $parent]) {
        if (empty($schema[$child]) || empty($schema[$parent]) || !$primaryKey[$child] || !in_array($fk, $schema[$child], true)) continue;
        $parentIds = $scopeIds[$parent] ?? [];
        if (!$parentIds) continue;
        $found = [];
        foreach (array_chunk($parentIds, 80) as $batchNo => $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $pk = $primaryKey[$child];
            $q = pf_jenny_audit_query('relationship_scope_batch_' . ($batchNo + 1), $child,
                'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM ' . pf_jenny_audit_ident($child) . ' WHERE ' . pf_jenny_audit_ident($fk) . ' IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk), str_repeat('i', count($batch)), $batch);
            if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
            $found = array_merge($found, array_map('intval', array_column($q['rows'], 'record_id')));
        }
        $new = array_values(array_unique(array_merge($scopeIds[$child] ?? [], $found)));
        if (count($new) !== count($scopeIds[$child] ?? [])) $changed = true;
        $scopeIds[$child] = $new;
    }
    if (!$changed) break;
}

// Specialized transaction lookup: restrict order and job references by exact typed IDs.
if (!empty($schema['inventory_transactions'])) {
    foreach ([['ORDER', 'orders'], ['ORDER_PRODUCT', 'orders'], ['JOB_ORDER', 'job_orders'], ['CHANGE_ITEM', 'change_item_requests']] as [$refType, $parent]) {
        $parentIds = $scopeIds[$parent] ?? [];
        if (!$parentIds || !in_array('ref_type', $schema['inventory_transactions'], true) || !in_array('ref_id', $schema['inventory_transactions'], true) || !$primaryKey['inventory_transactions']) continue;
        $ids = [];
        foreach (array_chunk($parentIds, 80) as $batchNo => $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $pk = $primaryKey['inventory_transactions'];
            $q = pf_jenny_audit_query('typed_inventory_reference_batch_' . ($batchNo + 1), 'inventory_transactions',
                'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM `inventory_transactions` WHERE `ref_type` = ? AND `ref_id` IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk), 's' . str_repeat('i', count($batch)), array_merge([$refType], $batch));
            if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
            $ids = array_merge($ids, array_map('intval', array_column($q['rows'], 'record_id')));
        }
        $scopeIds['inventory_transactions'] = array_values(array_unique(array_merge($scopeIds['inventory_transactions'] ?? [], $ids)));
    }
}
if (!empty($schema['inventory_movements']) && $primaryKey['inventory_movements'] && in_array('reference_type', $schema['inventory_movements'], true) && in_array('reference_id', $schema['inventory_movements'], true)) {
    foreach ([['ORDER', 'orders'], ['ORDER_PRODUCT', 'orders'], ['JOB_ORDER', 'job_orders'], ['CHANGE_ITEM', 'change_item_requests']] as [$refType, $parent]) {
        $parentIds = $scopeIds[$parent] ?? [];
        if (!$parentIds) continue;
        $ids = [];
        foreach (array_chunk($parentIds, 80) as $batchNo => $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $pk = $primaryKey['inventory_movements'];
            $q = pf_jenny_audit_query('typed_inventory_movement_batch_' . ($batchNo + 1), 'inventory_movements',
                'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM `inventory_movements` WHERE `reference_type` = ? AND `reference_id` IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk), 's' . str_repeat('i', count($batch)), array_merge([$refType], $batch));
            if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
            $ids = array_merge($ids, array_map('intval', array_column($q['rows'], 'record_id')));
        }
        $scopeIds['inventory_movements'] = array_values(array_unique(array_merge($scopeIds['inventory_movements'] ?? [], $ids)));
    }
}

// Notifications link to orders polymorphically through type/data_id, not a foreign key.
if (!empty($schema['notifications']) && $primaryKey['notifications']
    && in_array('type', $schema['notifications'], true) && in_array('data_id', $schema['notifications'], true)) {
    $notificationIds = [];
    foreach (array_chunk($scopeIds['orders'] ?? [], 80) as $batchNo => $batch) {
        $marks = implode(',', array_fill(0, count($batch), '?'));
        $pk = $primaryKey['notifications'];
        $q = pf_jenny_audit_query('polymorphic_order_notification_batch_' . ($batchNo + 1), 'notifications',
            'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM `notifications` WHERE `type` = ? AND CAST(`data_id` AS UNSIGNED) IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk),
            's' . str_repeat('i', count($batch)), array_merge(['Order'], $batch));
        if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
        $notificationIds = array_merge($notificationIds, array_map('intval', array_column($q['rows'], 'record_id')));
    }
    $scopeIds['notifications'] = array_values(array_unique(array_merge($scopeIds['notifications'] ?? [], $notificationIds)));
    if (!empty($schema['push_notification_queue']) && $primaryKey['push_notification_queue']
        && in_array('notification_id', $schema['push_notification_queue'], true)) {
        $queueIds = [];
        foreach (array_chunk($scopeIds['notifications'], 80) as $batchNo => $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $pk = $primaryKey['push_notification_queue'];
            $q = pf_jenny_audit_query('push_queue_notification_batch_' . ($batchNo + 1), 'push_notification_queue',
                'SELECT ' . pf_jenny_audit_ident($pk) . ' AS record_id FROM `push_notification_queue` WHERE `notification_id` IN (' . $marks . ') ORDER BY ' . pf_jenny_audit_ident($pk),
                str_repeat('i', count($batch)), $batch);
            if ($q['failure'] !== null) { $failures[] = $q['failure']; continue; }
            $queueIds = array_merge($queueIds, array_map('intval', array_column($q['rows'], 'record_id')));
        }
        $scopeIds['push_notification_queue'] = array_values(array_unique(array_merge($scopeIds['push_notification_queue'] ?? [], $queueIds)));
    }
}

foreach ($scopeIds as $table => $ids) {
    $isMaintenanceRecord = (bool)preg_match('/(?:^|_)(?:backup|audit)(?:_|$)/i', $table)
        || in_array($table, ['maintenance_customer_25_order_delete_backup', 'customer_testing_data_cleanup_audit', 'review_cleanup_audit'], true);
    $isProtectedLedger = in_array($table, ['inventory_transactions', 'inventory_movements', 'provider_payments', 'provider_payment_status_history', 'provider_webhook_events', 'payment_submissions', 'printflow_order_archive_map'], true);
    $classification = ($isMaintenanceRecord || $isProtectedLedger)
        ? 'protected_preserve'
        : 'uncertain_exclusive_candidate_requires_review';
    // Maintenance backups and audit trails can be large; report their exact count but do not
    // emit thousands of unrelated maintenance row identifiers in an Admin response.
    $includeIds = !$isMaintenanceRecord || count($ids) <= 100;
    $records[$table] = ['count' => count($ids), 'classification' => $classification];
    if ($includeIds) $records[$table]['ids'] = $ids;
    else $records[$table]['ids_omitted'] = 'Protected maintenance rows; count only.';
}

$dbIdentity = pf_jenny_audit_query('database_identity', 'DATABASE()', 'SELECT DATABASE() AS db_name');
if ($dbIdentity['failure'] !== null) $failures[] = $dbIdentity['failure'];
$dbName = (string)($dbIdentity['rows'][0]['db_name'] ?? '');
printflow_json_response([
    'success' => count($failures) === 0,
    'diagnostic_version' => PF_JENNY_AUDIT_VERSION,
    'database' => ['name' => $dbName, 'host' => (string)$conn->host_info],
    'target' => ['customer_id' => PF_JENNY_AUDIT_CUSTOMER_ID],
    'scope_queries_complete' => count($failures) === 0,
    'scope_ownership_complete' => false,
    'preview_safe_for_deletion' => false,
    'records' => $records,
    'schema_checked_tables' => array_keys($schema),
    'verified_foreign_key_edges' => array_keys($foreignKeyEdgesForOutput ?? []),
    'unresolved_foreign_key_edges' => $foreignKeyFailures,
    'tables_not_present' => array_values(array_diff($candidates, array_keys($presentTables))),
    'query_failures' => $failures,
    'uncertain_checks' => [
        'inventory_reversal' => 'Requires application-level stock/roll reversal review; no inventory rows are marked deletable by this audit.',
        'external_payment_webhooks' => 'Provider-side/external audit semantics must be verified before any deletion.',
        'shared_file_references' => 'Physical file reference scan across all file-bearing tables is not implemented; preserve every file.',
        'permanent_visibility_exclusion' => 'No global exclusion mechanism is enabled by this read-only endpoint.',
    ],
    'deletion_enabled' => false,
    'note' => 'Read-only audit only. Financial, inventory, archive, synthetic, and physical-file changes remain protected until separately reviewed.',
], count($failures) === 0 ? 200 : 503);
