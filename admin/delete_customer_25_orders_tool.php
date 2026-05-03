<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role('Admin');

const PF_DELETE_CUSTOMER_ID = 25;
const PF_DCO_BACKUP_TABLE = 'maintenance_customer_25_order_delete_backup';
const PF_DCO_TOOL_VERSION = '2026-05-03 v5';

function pf_dco_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pf_dco_table_exists(string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') {
        return false;
    }

    $rows = db_query("SHOW TABLES LIKE ?", 's', [$safe]);
    $cache[$table] = !empty($rows);
    return $cache[$table];
}

function pf_dco_table_columns(string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!pf_dco_table_exists($table)) {
        return [];
    }

    $rows = db_query("SHOW COLUMNS FROM `{$table}`") ?: [];
    $cols = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $cols[] = $field;
        }
    }

    $cache[$table] = $cols;
    return $cols;
}

function pf_dco_exec_affected(string $sql, string $types = '', array $params = []): int
{
    global $conn;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function pf_dco_exec_raw(string $sql): void
{
    global $conn;

    if (!$conn->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $conn->error);
    }
}

function pf_dco_exec_raw_affected(string $sql): int
{
    global $conn;

    if (!$conn->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $conn->error);
    }

    return (int)$conn->affected_rows;
}

function pf_dco_begin_transaction(): void
{
    global $conn;

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Could not start the delete transaction: ' . $conn->error);
    }
}

function pf_dco_commit_transaction(): void
{
    global $conn;

    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit the delete transaction: ' . $conn->error);
    }
}

function pf_dco_rollback_transaction(): void
{
    global $conn;

    if (!$conn->rollback()) {
        error_log('delete_customer_25_orders_tool rollback warning: ' . $conn->error);
    }
}

function pf_dco_prepare_temp_order_ids(array $orderIds): void
{
    if ($orderIds === []) {
        return;
    }

    $values = [];
    foreach ($orderIds as $orderId) {
        $values[] = '(' . (int)$orderId . ')';
    }

    pf_dco_exec_raw("DROP TEMPORARY TABLE IF EXISTS tmp_pf_dco_order_ids");
    pf_dco_exec_raw(
        "CREATE TEMPORARY TABLE tmp_pf_dco_order_ids (
            order_id INT NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB"
    );
    pf_dco_exec_raw(
        "INSERT INTO tmp_pf_dco_order_ids (order_id) VALUES " . implode(', ', $values)
    );
}

function pf_dco_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function pf_dco_quote_sql_string(string $value): string
{
    global $conn;

    return "'" . $conn->real_escape_string($value) . "'";
}

function pf_dco_ensure_backup_table(): void
{
    $sql = "CREATE TABLE IF NOT EXISTS " . PF_DCO_BACKUP_TABLE . " (
        id BIGINT NOT NULL AUTO_INCREMENT,
        batch_id VARCHAR(80) NOT NULL,
        customer_id INT NOT NULL,
        table_name VARCHAR(64) NOT NULL,
        row_position INT NOT NULL,
        payload LONGTEXT NOT NULL,
        deleted_by INT NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        restored_by INT NULL,
        restored_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_batch_id (batch_id),
        KEY idx_customer_batch (customer_id, batch_id),
        KEY idx_restore_state (restored_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!db_execute($sql)) {
        throw new RuntimeException('Could not create or verify rollback backup table.');
    }
}

function pf_dco_order_id_select_sql(): string
{
    return 'SELECT order_id FROM orders WHERE customer_id = ' . PF_DELETE_CUSTOMER_ID;
}

function pf_dco_order_item_id_select_sql(): string
{
    return 'SELECT order_item_id FROM order_items WHERE order_id IN (' . pf_dco_order_id_select_sql() . ')';
}

function pf_dco_review_id_select_sql(): string
{
    return 'SELECT id FROM reviews WHERE order_id IN (' . pf_dco_order_id_select_sql() . ')';
}

function pf_dco_service_order_id_select_sql(): string
{
    return 'SELECT id FROM service_orders WHERE customer_id = ' . PF_DELETE_CUSTOMER_ID;
}

function pf_dco_base_scopes(): array
{
    $orderSql = pf_dco_order_id_select_sql();
    $orderItemSql = pf_dco_order_item_id_select_sql();
    $reviewSql = pf_dco_review_id_select_sql();
    $serviceOrderSql = pf_dco_service_order_id_select_sql();

    return [
        'orders' => [
            'table' => 'orders',
            'depth' => 0,
            'conditions' => [
                'customer_id = ' . PF_DELETE_CUSTOMER_ID,
            ],
        ],
        'service_orders' => [
            'table' => 'service_orders',
            'depth' => 0,
            'conditions' => [
                'customer_id = ' . PF_DELETE_CUSTOMER_ID,
            ],
        ],
        'job_orders' => [
            'table' => 'job_orders',
            'depth' => 1,
            'conditions' => [
                'customer_id = ' . PF_DELETE_CUSTOMER_ID,
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'order_items' => [
            'table' => 'order_items',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'order_designs' => [
            'table' => 'order_designs',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'order_messages' => [
            'table' => 'order_messages',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'order_notes' => [
            'table' => 'order_notes',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'order_status_history' => [
            'table' => 'order_status_history',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'reviews' => [
            'table' => 'reviews',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
            ],
        ],
        'review_images' => [
            'table' => 'review_images',
            'depth' => 2,
            'conditions' => [
                'review_id IN (' . $reviewSql . ')',
            ],
        ],
        'review_replies' => [
            'table' => 'review_replies',
            'depth' => 2,
            'conditions' => [
                'review_id IN (' . $reviewSql . ')',
            ],
        ],
        'service_order_details' => [
            'table' => 'service_order_details',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $serviceOrderSql . ')',
            ],
        ],
        'service_order_files' => [
            'table' => 'service_order_files',
            'depth' => 1,
            'conditions' => [
                'order_id IN (' . $serviceOrderSql . ')',
            ],
        ],
        'customizations' => [
            'table' => 'customizations',
            'depth' => 2,
            'conditions' => [
                'customer_id = ' . PF_DELETE_CUSTOMER_ID,
                'order_id IN (' . $orderSql . ')',
                'order_item_id IN (' . $orderItemSql . ')',
            ],
        ],
        'order_item_revisions' => [
            'table' => 'order_item_revisions',
            'depth' => 2,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
                'order_item_id IN (' . $orderItemSql . ')',
            ],
        ],
        'order_tarp_details' => [
            'table' => 'order_tarp_details',
            'depth' => 2,
            'conditions' => [
                'order_item_id IN (' . $orderItemSql . ')',
            ],
        ],
        'material_usage_logs' => [
            'table' => 'material_usage_logs',
            'depth' => 2,
            'conditions' => [
                'order_id IN (' . $orderSql . ')',
                'order_item_id IN (' . $orderItemSql . ')',
            ],
        ],
    ];
}

function pf_dco_fk_children(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $rows = db_query(
        "SELECT
            TABLE_NAME AS child_table,
            COLUMN_NAME AS child_column,
            REFERENCED_TABLE_NAME AS parent_table,
            REFERENCED_COLUMN_NAME AS parent_column
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME ASC, COLUMN_NAME ASC"
    ) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        $childTable = (string)($row['child_table'] ?? '');
        $parentTable = (string)($row['parent_table'] ?? '');
        if ($childTable === '' || $parentTable === '' || $childTable === $parentTable) {
            continue;
        }
        $grouped[$parentTable][] = [
            'child_table' => $childTable,
            'child_column' => (string)($row['child_column'] ?? ''),
            'parent_column' => (string)($row['parent_column'] ?? ''),
        ];
    }

    $cache = $grouped;
    return $cache;
}

function pf_dco_scope_where(array $conditions): string
{
    $conditions = array_values(array_unique(array_filter(array_map('strval', $conditions), static fn(string $value): bool => trim($value) !== '')));
    if ($conditions === []) {
        return '1 = 0';
    }
    if (count($conditions) === 1) {
        return $conditions[0];
    }
    return '(' . implode(') OR (', $conditions) . ')';
}

function pf_dco_register_scope(array &$scopes, string $table, array $conditions, int $depth): bool
{
    if (!pf_dco_table_exists($table) || $table === PF_DCO_BACKUP_TABLE) {
        return false;
    }

    $changed = false;
    if (!isset($scopes[$table])) {
        $scopes[$table] = [
            'table' => $table,
            'depth' => $depth,
            'conditions' => [],
            'where' => '1 = 0',
        ];
        $changed = true;
    }

    foreach ($conditions as $condition) {
        $condition = trim((string)$condition);
        if ($condition === '' || in_array($condition, $scopes[$table]['conditions'], true)) {
            continue;
        }
        $scopes[$table]['conditions'][] = $condition;
        $changed = true;
    }

    if ($depth > (int)$scopes[$table]['depth']) {
        $scopes[$table]['depth'] = $depth;
        $changed = true;
    }

    $where = pf_dco_scope_where($scopes[$table]['conditions']);
    if ($where !== $scopes[$table]['where']) {
        $scopes[$table]['where'] = $where;
        $changed = true;
    }

    return $changed;
}

function pf_dco_scope_plan(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $scopes = [];
    $queue = [];

    foreach (pf_dco_base_scopes() as $table => $scope) {
        if (pf_dco_register_scope($scopes, $table, $scope['conditions'], (int)$scope['depth'])) {
            $queue[] = $table;
        }
    }

    $fkChildren = pf_dco_fk_children();
    while ($queue !== []) {
        $parentTable = array_shift($queue);
        $parentScope = $scopes[$parentTable] ?? null;
        if ($parentScope === null) {
            continue;
        }

        foreach ($fkChildren[$parentTable] ?? [] as $relation) {
            $childTable = (string)$relation['child_table'];
            $childColumn = (string)$relation['child_column'];
            $parentColumn = (string)$relation['parent_column'];
            if ($childTable === '' || $childColumn === '' || $parentColumn === '') {
                continue;
            }

            $condition = pf_dco_quote_identifier($childColumn)
                . ' IN (SELECT DISTINCT '
                . pf_dco_quote_identifier($parentColumn)
                . ' FROM '
                . pf_dco_quote_identifier($parentTable)
                . ' WHERE '
                . $parentScope['where']
                . ')';

            if (pf_dco_register_scope($scopes, $childTable, [$condition], (int)$parentScope['depth'] + 1)) {
                $queue[] = $childTable;
            }
        }
    }

    $cache = $scopes;
    return $cache;
}

function pf_dco_sorted_scopes(bool $forDelete): array
{
    $scopes = array_values(pf_dco_scope_plan());
    usort($scopes, static function (array $a, array $b) use ($forDelete): int {
        $depthCompare = $forDelete
            ? ((int)$b['depth'] <=> (int)$a['depth'])
            : ((int)$a['depth'] <=> (int)$b['depth']);
        if ($depthCompare !== 0) {
            return $depthCompare;
        }
        return strcmp((string)$a['table'], (string)$b['table']);
    });
    return $scopes;
}

function pf_dco_scope_table_names(): array
{
    return array_map(static fn(array $scope): string => (string)$scope['table'], pf_dco_sorted_scopes(false));
}

function pf_dco_select_rows_for_backup(array $scope): array
{
    $table = (string)$scope['table'];
    if (!pf_dco_table_exists($table)) {
        return [];
    }

    return db_query(
        'SELECT * FROM ' . pf_dco_quote_identifier($table) . ' WHERE ' . $scope['where']
    ) ?: [];
}

function pf_dco_capture_backup_batch(string $batchId, int $adminId): array
{
    $captured = [];
    $rowsSaved = 0;

    foreach (pf_dco_sorted_scopes(false) as $scope) {
        $table = (string)$scope['table'];
        $rows = pf_dco_select_rows_for_backup($scope);
        $captured[$table] = count($rows);
        $position = 0;

        foreach ($rows as $row) {
            $position++;
            $payload = base64_encode(serialize($row));
            $result = db_execute(
                "INSERT INTO " . PF_DCO_BACKUP_TABLE . "
                    (batch_id, customer_id, table_name, row_position, payload, deleted_by)
                 VALUES (?, ?, ?, ?, ?, ?)",
                'sisisi',
                [$batchId, PF_DELETE_CUSTOMER_ID, $table, $position, $payload, $adminId]
            );
            if ($result === false) {
                throw new RuntimeException('Backup insert failed for table ' . $table . ' row ' . $position . '.');
            }
            $rowsSaved++;
        }
    }

    return [
        'counts' => $captured,
        'rows_saved' => $rowsSaved,
    ];
}

function pf_dco_quote_sql_value($value): string
{
    global $conn;

    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function pf_dco_restore_row(string $table, array $row): void
{
    global $conn;

    $currentColumns = array_flip(pf_dco_table_columns($table));
    $cols = [];
    $vals = [];

    foreach ($row as $column => $value) {
        if (!isset($currentColumns[$column])) {
            continue;
        }
        $cols[] = "`{$column}`";
        $vals[] = pf_dco_quote_sql_value($value);
    }

    if ($cols === []) {
        return;
    }

    $sql = "INSERT INTO `{$table}` (" . implode(', ', $cols) . ")
            VALUES (" . implode(', ', $vals) . ")";

    if (!$conn->query($sql)) {
        throw new RuntimeException("Restore insert failed for {$table}: " . $conn->error);
    }
}

function pf_dco_latest_active_batch(): ?array
{
    pf_dco_ensure_backup_table();

    $rows = db_query(
        "SELECT
            batch_id,
            COUNT(*) AS row_count,
            MAX(deleted_at) AS deleted_at
         FROM " . PF_DCO_BACKUP_TABLE . "
         WHERE customer_id = ?
           AND restored_at IS NULL
         GROUP BY batch_id
         ORDER BY MAX(deleted_at) DESC
         LIMIT 1",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    ) ?: [];

    return $rows[0] ?? null;
}

function pf_dco_recent_batches(): array
{
    pf_dco_ensure_backup_table();

    return db_query(
        "SELECT
            batch_id,
            COUNT(*) AS row_count,
            MAX(deleted_at) AS deleted_at,
            MAX(restored_at) AS restored_at
         FROM " . PF_DCO_BACKUP_TABLE . "
         WHERE customer_id = ?
         GROUP BY batch_id
         ORDER BY MAX(deleted_at) DESC
         LIMIT 8",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    ) ?: [];
}

function pf_dco_count_scope_rows(array $scope): int
{
    $table = (string)$scope['table'];
    if (!pf_dco_table_exists($table)) {
        return 0;
    }

    $rows = db_query(
        'SELECT COUNT(*) AS c FROM ' . pf_dco_quote_identifier($table) . ' WHERE ' . $scope['where']
    );

    return (int)($rows[0]['c'] ?? 0);
}

function pf_dco_delete_verification_summary(): array
{
    $remaining = [];
    foreach (pf_dco_sorted_scopes(false) as $scope) {
        $count = pf_dco_count_scope_rows($scope);
        if ($count > 0) {
            $remaining[(string)$scope['table']] = $count;
        }
    }
    return $remaining;
}

function pf_dco_preview(): array
{
    $customer = db_query(
        "SELECT customer_id, first_name, last_name, email, contact_number
         FROM customers
         WHERE customer_id = ?
         LIMIT 1",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    );

    $orders = db_query(
        "SELECT order_id, order_date, status, total_amount
         FROM orders
         WHERE customer_id = ?
         ORDER BY order_date DESC, order_id DESC",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    ) ?: [];

    $counts = [
        'orders' => count($orders),
    ];
    foreach (pf_dco_sorted_scopes(false) as $scope) {
        $table = (string)$scope['table'];
        if ($table === 'orders') {
            continue;
        }
        $counts[$table] = pf_dco_count_scope_rows($scope);
    }
    return [
        'customer' => $customer[0] ?? null,
        'orders' => $orders,
        'counts' => $counts,
    ];
}

function pf_dco_delete_customer_orders(): array
{
    $deleted = array_fill_keys(pf_dco_scope_table_names(), 0);
    $backupCounts = [];
    $adminId = (int)get_user_id();
    $batchId = 'dco25_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

    $orders = db_query("SELECT order_id FROM orders WHERE customer_id = ?", 'i', [PF_DELETE_CUSTOMER_ID]) ?: [];
    if ($orders === []) {
        return [
            'success' => true,
            'message' => 'Customer 25 has no regular orders to delete.',
            'deleted' => $deleted,
            'backup_batch_id' => null,
            'backup_counts' => [],
        ];
    }

    try {
        pf_dco_ensure_backup_table();
        global $conn;
        pf_dco_begin_transaction();

        $backup = pf_dco_capture_backup_batch($batchId, $adminId);
        $backupCounts = (array)($backup['counts'] ?? []);
        $backupRowsSaved = (int)($backup['rows_saved'] ?? 0);

        foreach (pf_dco_sorted_scopes(true) as $scope) {
            $table = (string)$scope['table'];
            if (!pf_dco_table_exists($table)) {
                $deleted[$table] = 0;
                continue;
            }

            $deleted[$table] = pf_dco_exec_raw_affected(
                'DELETE FROM ' . pf_dco_quote_identifier($table) . ' WHERE ' . $scope['where']
            );
        }

        $remaining = pf_dco_delete_verification_summary();
        if ($remaining !== []) {
            $parts = [];
            foreach ($remaining as $table => $count) {
                $parts[] = $table . '=' . $count;
            }
            throw new RuntimeException('Delete verification failed. Remaining rows: ' . implode(', ', $parts));
        }

        $backupRowCheckSql = "SELECT COUNT(*) AS c
             FROM " . PF_DCO_BACKUP_TABLE . "
             WHERE batch_id = " . pf_dco_quote_sql_string($batchId) . "
               AND customer_id = " . PF_DELETE_CUSTOMER_ID;
        $backupRows = db_query($backupRowCheckSql);
        $backupRowCount = (int)($backupRows[0]['c'] ?? 0);
        if ($backupRowCount <= 0) {
            throw new RuntimeException(
                'Delete verification failed. No backup rows were saved for batch ' . $batchId
                . '. Insert attempts: ' . $backupRowsSaved . '.'
            );
        }

        pf_dco_commit_transaction();

        log_activity(
            $adminId,
            'Delete Customer Orders',
            'Deleted order transaction data for customer ID 25. Backup batch: ' . $batchId
        );

        return [
            'success' => true,
            'message' => 'Deleted order transaction data for customer ID 25.',
            'deleted' => $deleted,
            'backup_batch_id' => $batchId,
            'backup_counts' => $backupCounts,
        ];
    } catch (Throwable $e) {
        pf_dco_rollback_transaction();
        error_log('delete_customer_25_orders_tool delete failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Delete failed: ' . $e->getMessage(),
            'deleted' => $deleted,
            'backup_batch_id' => null,
            'backup_counts' => $backupCounts,
        ];
    }
}

function pf_dco_rollback_latest_batch(): array
{
    global $conn;

    $restored = array_fill_keys(pf_dco_scope_table_names(), 0);
    $adminId = (int)get_user_id();
    $batch = pf_dco_latest_active_batch();

    if (!$batch) {
        return [
            'success' => false,
            'message' => 'No rollback batch is currently available.',
            'restored' => $restored,
            'batch_id' => null,
        ];
    }

    $batchId = (string)$batch['batch_id'];

    try {
        pf_dco_begin_transaction();

        $rows = db_query(
            "SELECT id, table_name, row_position, payload
             FROM " . PF_DCO_BACKUP_TABLE . "
             WHERE batch_id = ?
               AND customer_id = ?
               AND restored_at IS NULL
             ORDER BY id ASC",
            'si',
            [$batchId, PF_DELETE_CUSTOMER_ID]
        ) ?: [];

        $rank = [];
        foreach (pf_dco_sorted_scopes(false) as $index => $scope) {
            $rank[(string)$scope['table']] = $index;
        }
        usort($rows, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['table_name']] ?? 999;
            $rb = $rank[$b['table_name']] ?? 999;
            if ($ra === $rb) {
                return ((int)$a['row_position']) <=> ((int)$b['row_position']);
            }
            return $ra <=> $rb;
        });

        foreach ($rows as $row) {
            $table = (string)$row['table_name'];
            if (!pf_dco_table_exists($table)) {
                continue;
            }
            if (!isset($restored[$table])) {
                $restored[$table] = 0;
            }

            $payload = base64_decode((string)$row['payload'], true);
            if ($payload === false) {
                throw new RuntimeException('Rollback payload decode failed for batch ' . $batchId . '.');
            }

            $data = unserialize($payload);
            if (!is_array($data)) {
                throw new RuntimeException('Rollback payload data is invalid for batch ' . $batchId . '.');
            }

            pf_dco_restore_row($table, $data);
            $restored[$table] = ($restored[$table] ?? 0) + 1;
        }

        pf_dco_exec_affected(
            "UPDATE " . PF_DCO_BACKUP_TABLE . "
             SET restored_at = NOW(), restored_by = ?
             WHERE batch_id = ?
               AND customer_id = ?
               AND restored_at IS NULL",
            'isi',
            [$adminId, $batchId, PF_DELETE_CUSTOMER_ID]
        );

        pf_dco_commit_transaction();

        log_activity(
            $adminId,
            'Rollback Customer Orders Delete',
            'Rolled back deleted order transaction data for customer ID 25. Batch: ' . $batchId
        );

        return [
            'success' => true,
            'message' => 'Rollback completed for customer ID 25.',
            'restored' => $restored,
            'batch_id' => $batchId,
        ];
    } catch (Throwable $e) {
        pf_dco_rollback_transaction();
        error_log('delete_customer_25_orders_tool rollback failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Rollback failed: ' . $e->getMessage(),
            'restored' => $restored,
            'batch_id' => $batchId,
        ];
    }
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $result = [
            'success' => false,
            'message' => 'Invalid CSRF token.',
            'mode' => 'error',
        ];
    } elseif (isset($_POST['delete_customer_25_orders'])) {
        $result = pf_dco_delete_customer_orders();
        $result['mode'] = 'delete';
    } elseif (isset($_POST['rollback_customer_25_orders'])) {
        $result = pf_dco_rollback_latest_batch();
        $result['mode'] = 'rollback';
    }
}

$preview = pf_dco_preview();
$customer = $preview['customer'];
$orders = $preview['orders'];
$counts = $preview['counts'];
$hasDeleteTargets = (($counts['orders'] ?? 0) > 0) || (($counts['service_orders'] ?? 0) > 0);
$activeBatch = pf_dco_latest_active_batch();
$recentBatches = pf_dco_recent_batches();

$page_title = 'Delete Customer 25 Orders Tool';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-5xl mx-auto">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Delete Customer 25 Orders Tool</h1>
                <p class="text-sm text-gray-600 mt-2">
                    Temporary admin maintenance page for removing the order transaction records of customer ID <strong>25</strong>, including regular and service order records.
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    Tool version: <span class="font-mono"><?php echo pf_dco_h(PF_DCO_TOOL_VERSION); ?></span>
                </p>
            </div>

            <div class="p-6 space-y-6">
                <?php if ($result !== null): ?>
                    <div class="<?php echo !empty($result['success']) ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?> border rounded-xl px-4 py-3">
                        <div class="font-semibold"><?php echo pf_dco_h($result['message'] ?? 'Action completed.'); ?></div>
                        <?php if (!empty($result['backup_batch_id'])): ?>
                            <div class="text-sm mt-2">Backup batch: <span class="font-mono"><?php echo pf_dco_h($result['backup_batch_id']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($result['batch_id']) && ($result['mode'] ?? '') === 'rollback'): ?>
                            <div class="text-sm mt-2">Rolled back batch: <span class="font-mono"><?php echo pf_dco_h($result['batch_id']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($result['deleted']) && is_array($result['deleted'])): ?>
                            <div class="text-sm mt-2">
                                <?php foreach ($result['deleted'] as $label => $count): ?>
                                    <div><?php echo pf_dco_h($label); ?>: <?php echo (int)$count; ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($result['restored']) && is_array($result['restored'])): ?>
                            <div class="text-sm mt-2">
                                <?php foreach ($result['restored'] as $label => $count): ?>
                                    <div><?php echo pf_dco_h($label); ?>: <?php echo (int)$count; ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-4">
                    <div class="font-semibold text-amber-900">Warning</div>
                    <p class="text-sm text-amber-800 mt-1">
                        Delete permanently removes customer 25 order rows from <code>orders</code> and <code>service_orders</code> plus linked records like order items, messages, notes, reviews, job orders, and service order files/details. The rollback button restores the latest saved delete batch from this page.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Customer Preview</h2>
                        <?php if ($customer): ?>
                            <div class="space-y-1 text-sm text-gray-700">
                                <div><strong>ID:</strong> <?php echo (int)$customer['customer_id']; ?></div>
                                <div><strong>Name:</strong> <?php echo pf_dco_h(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))); ?></div>
                                <div><strong>Email:</strong> <?php echo pf_dco_h($customer['email'] ?? ''); ?></div>
                                <div><strong>Contact:</strong> <?php echo pf_dco_h($customer['contact_number'] ?? ''); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-red-700">Customer ID 25 was not found.</div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Linked Row Counts</h2>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm text-gray-700">
                            <?php foreach ($counts as $label => $count): ?>
                                <div class="font-medium"><?php echo pf_dco_h($label); ?></div>
                                <div><?php echo (int)$count; ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Rollback Status</h2>
                        <?php if ($activeBatch): ?>
                            <div class="space-y-1 text-sm text-gray-700">
                                <div><strong>Latest active batch:</strong> <span class="font-mono"><?php echo pf_dco_h($activeBatch['batch_id']); ?></span></div>
                                <div><strong>Saved rows:</strong> <?php echo (int)$activeBatch['row_count']; ?></div>
                                <div><strong>Deleted at:</strong> <?php echo pf_dco_h($activeBatch['deleted_at'] ?? ''); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-gray-600">No rollback batch is pending right now.</div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Recent Batches</h2>
                        <?php if ($recentBatches === []): ?>
                            <div class="text-sm text-gray-600">No delete batches have been recorded yet.</div>
                        <?php else: ?>
                            <div class="space-y-2 text-sm text-gray-700">
                                <?php foreach ($recentBatches as $batch): ?>
                                    <div class="border border-gray-200 rounded-lg px-3 py-2 bg-white">
                                        <div class="font-mono text-xs text-gray-900"><?php echo pf_dco_h($batch['batch_id'] ?? ''); ?></div>
                                        <div>Rows: <?php echo (int)($batch['row_count'] ?? 0); ?></div>
                                        <div>Deleted: <?php echo pf_dco_h($batch['deleted_at'] ?? ''); ?></div>
                                        <div>Restored: <?php echo pf_dco_h($batch['restored_at'] ?? 'Not yet'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <h2 class="text-lg font-semibold text-gray-900 mb-3">Orders To Be Deleted</h2>
                    <?php if (empty($orders)): ?>
                        <div class="text-sm text-gray-600">No regular <code>orders</code> currently found for customer 25.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 border-b border-gray-200">
                                        <th class="py-2 pr-4">Order ID</th>
                                        <th class="py-2 pr-4">Date</th>
                                        <th class="py-2 pr-4">Status</th>
                                        <th class="py-2 pr-4">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr class="border-b border-gray-100">
                                            <td class="py-2 pr-4 font-medium text-gray-900">#<?php echo (int)$order['order_id']; ?></td>
                                            <td class="py-2 pr-4 text-gray-700"><?php echo pf_dco_h($order['order_date'] ?? ''); ?></td>
                                            <td class="py-2 pr-4 text-gray-700"><?php echo pf_dco_h($order['status'] ?? ''); ?></td>
                                            <td class="py-2 pr-4 text-gray-700"><?php echo pf_dco_h((string)($order['total_amount'] ?? '0.00')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap gap-3">
                    <form method="post" onsubmit="return confirm('Delete all regular and service order transaction data for customer ID 25? A rollback batch will be saved first.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="delete_customer_25_orders" value="1">
                        <button
                            type="submit"
                            class="inline-flex items-center px-5 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition"
                            <?php echo (!$customer || !$hasDeleteTargets) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                        >
                            Delete Customer 25 Orders and Service Orders
                        </button>
                    </form>

                    <form method="post" onsubmit="return confirm('Rollback the latest delete batch for customer ID 25?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="rollback_customer_25_orders" value="1">
                        <button
                            type="submit"
                            class="inline-flex items-center px-5 py-3 rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-semibold transition"
                            <?php echo !$activeBatch ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                        >
                            Rollback Latest Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
