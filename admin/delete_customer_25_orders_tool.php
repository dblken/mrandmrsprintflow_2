<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role('Admin');

const PF_DELETE_CUSTOMER_ID = 25;
const PF_DCO_BACKUP_TABLE = 'maintenance_customer_25_order_delete_backup';
const PF_DCO_TOOL_VERSION = '2026-05-03 v3';

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

function pf_dco_int_csv(array $ids): string
{
    $ints = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ints[] = $id;
        }
    }

    if ($ints === []) {
        return '0';
    }

    return implode(', ', array_unique($ints));
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

function pf_dco_backup_tables(): array
{
    return [
        'orders',
        'job_orders',
        'order_items',
        'order_designs',
        'order_messages',
        'order_notes',
        'order_status_history',
        'reviews',
        'review_images',
        'review_replies',
    ];
}

function pf_dco_delete_tables(): array
{
    return [
        'review_images',
        'review_replies',
        'reviews',
        'order_status_history',
        'order_notes',
        'order_messages',
        'order_designs',
        'order_items',
        'job_orders',
        'orders',
    ];
}

function pf_dco_select_rows_for_backup(string $table): array
{
    if (!pf_dco_table_exists($table)) {
        return [];
    }

    switch ($table) {
        case 'orders':
            return db_query(
                "SELECT * FROM orders WHERE customer_id = ? ORDER BY order_id ASC",
                'i',
                [PF_DELETE_CUSTOMER_ID]
            ) ?: [];

        case 'job_orders':
            return db_query(
                "SELECT * FROM job_orders
                 WHERE customer_id = ?
                    OR order_id IN (SELECT order_id FROM orders WHERE customer_id = ?)
                 ORDER BY id ASC",
                'ii',
                [PF_DELETE_CUSTOMER_ID, PF_DELETE_CUSTOMER_ID]
            ) ?: [];

        case 'review_images':
        case 'review_replies':
            if (!pf_dco_table_exists('reviews')) {
                return [];
            }
            return db_query(
                "SELECT * FROM `{$table}`
                 WHERE review_id IN (
                     SELECT id
                     FROM reviews
                     WHERE order_id IN (
                         SELECT order_id FROM orders WHERE customer_id = ?
                     )
                 )",
                'i',
                [PF_DELETE_CUSTOMER_ID]
            ) ?: [];

        default:
            return db_query(
                "SELECT * FROM `{$table}`
                 WHERE order_id IN (
                     SELECT order_id FROM orders WHERE customer_id = ?
                 )",
                'i',
                [PF_DELETE_CUSTOMER_ID]
            ) ?: [];
    }
}

function pf_dco_capture_backup_batch(string $batchId, int $adminId): array
{
    $captured = [];

    foreach (pf_dco_backup_tables() as $table) {
        $rows = pf_dco_select_rows_for_backup($table);
        $captured[$table] = count($rows);
        $position = 0;

        foreach ($rows as $row) {
            $position++;
            $payload = base64_encode(serialize($row));
            pf_dco_exec_affected(
                "INSERT INTO " . PF_DCO_BACKUP_TABLE . "
                    (batch_id, customer_id, table_name, row_position, payload, deleted_by)
                 VALUES (?, ?, ?, ?, ?, ?)",
                'sisisi',
                [$batchId, PF_DELETE_CUSTOMER_ID, $table, $position, $payload, $adminId]
            );
        }
    }

    return $captured;
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

function pf_dco_count_orders_child(string $table, string $orderColumn = 'order_id'): int
{
    if (!pf_dco_table_exists($table)) {
        return 0;
    }

    $rows = db_query(
        "SELECT COUNT(*) AS c
         FROM `{$table}`
         WHERE `{$orderColumn}` IN (
             SELECT order_id FROM orders WHERE customer_id = ?
         )",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    );

    return (int)($rows[0]['c'] ?? 0);
}

function pf_dco_count_job_orders(): int
{
    if (!pf_dco_table_exists('job_orders')) {
        return 0;
    }

    $rows = db_query(
        "SELECT COUNT(*) AS c
         FROM job_orders
         WHERE customer_id = ?
            OR order_id IN (SELECT order_id FROM orders WHERE customer_id = ?)",
        'ii',
        [PF_DELETE_CUSTOMER_ID, PF_DELETE_CUSTOMER_ID]
    );

    return (int)($rows[0]['c'] ?? 0);
}

function pf_dco_count_review_children(string $table): int
{
    if (!pf_dco_table_exists('reviews') || !pf_dco_table_exists($table)) {
        return 0;
    }

    $rows = db_query(
        "SELECT COUNT(*) AS c
         FROM `{$table}`
         WHERE review_id IN (
             SELECT id FROM reviews
             WHERE order_id IN (
                 SELECT order_id FROM orders WHERE customer_id = ?
             )
         )",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    );

    return (int)($rows[0]['c'] ?? 0);
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

    return [
        'customer' => $customer[0] ?? null,
        'orders' => $orders,
        'counts' => [
            'orders' => count($orders),
            'order_items' => pf_dco_count_orders_child('order_items'),
            'order_designs' => pf_dco_count_orders_child('order_designs'),
            'order_messages' => pf_dco_count_orders_child('order_messages'),
            'order_notes' => pf_dco_count_orders_child('order_notes'),
            'order_status_history' => pf_dco_count_orders_child('order_status_history'),
            'reviews' => pf_dco_count_orders_child('reviews'),
            'review_images' => pf_dco_count_review_children('review_images'),
            'review_replies' => pf_dco_count_review_children('review_replies'),
            'job_orders' => pf_dco_count_job_orders(),
            'service_orders' => pf_dco_table_exists('service_orders')
                ? (int)((db_query("SELECT COUNT(*) AS c FROM service_orders WHERE customer_id = ?", 'i', [PF_DELETE_CUSTOMER_ID])[0]['c'] ?? 0))
                : 0,
        ],
    ];
}

function pf_dco_delete_customer_orders(): array
{
    $deleted = array_fill_keys(pf_dco_delete_tables(), 0);
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

    $orderIds = array_values(array_unique(array_map(static function (array $row): int {
        return (int)($row['order_id'] ?? 0);
    }, $orders)));
    $orderIds = array_values(array_filter($orderIds, static fn(int $id): bool => $id > 0));
    $orderIdCsv = pf_dco_int_csv($orderIds);

    try {
        pf_dco_ensure_backup_table();
        global $conn;
        $conn->begin_transaction();

        $backupCounts = pf_dco_capture_backup_batch($batchId, $adminId);

        $reviewRows = pf_dco_table_exists('reviews')
            ? (db_query("SELECT id FROM reviews WHERE order_id IN ({$orderIdCsv})") ?: [])
            : [];
        $reviewIds = array_values(array_filter(array_map(static function (array $row): int {
            return (int)($row['id'] ?? 0);
        }, $reviewRows), static fn(int $id): bool => $id > 0));
        $reviewIdCsv = pf_dco_int_csv($reviewIds);

        foreach (pf_dco_delete_tables() as $table) {
            switch ($table) {
                case 'review_images':
                case 'review_replies':
                    if (!pf_dco_table_exists($table) || $reviewIds === []) {
                        $deleted[$table] = 0;
                        break;
                    }
                    $deleted[$table] = pf_dco_exec_raw_affected(
                        "DELETE FROM `{$table}` WHERE review_id IN ({$reviewIdCsv})"
                    );
                    break;

                case 'job_orders':
                    if (!pf_dco_table_exists('job_orders')) {
                        $deleted[$table] = 0;
                        break;
                    }
                    $deleted[$table] = pf_dco_exec_raw_affected(
                        "DELETE FROM job_orders
                         WHERE customer_id = " . PF_DELETE_CUSTOMER_ID . "
                            OR order_id IN ({$orderIdCsv})"
                    );
                    break;

                case 'orders':
                    $deleted[$table] = pf_dco_exec_raw_affected(
                        "DELETE FROM orders WHERE order_id IN ({$orderIdCsv})"
                    );
                    break;

                default:
                    if (!pf_dco_table_exists($table)) {
                        $deleted[$table] = 0;
                        break;
                    }
                    $deleted[$table] = pf_dco_exec_raw_affected(
                        "DELETE FROM `{$table}` WHERE order_id IN ({$orderIdCsv})"
                    );
                    break;
            }
        }

        $conn->commit();

        log_activity(
            $adminId,
            'Delete Customer Orders',
            'Deleted regular order transaction data for customer ID 25. Backup batch: ' . $batchId
        );

        return [
            'success' => true,
            'message' => 'Deleted regular order transaction data for customer ID 25.',
            'deleted' => $deleted,
            'backup_batch_id' => $batchId,
            'backup_counts' => $backupCounts,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
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

    $restored = array_fill_keys(pf_dco_backup_tables(), 0);
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
        $conn->begin_transaction();

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

        $rank = array_flip(pf_dco_backup_tables());
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

        $conn->commit();

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
        $conn->rollback();
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
                    Temporary admin maintenance page for removing the regular order transaction records of customer ID <strong>25</strong>.
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
                        Delete permanently removes regular <code>orders</code> rows for customer 25 plus linked records like order items, messages, notes, reviews, and job orders. The rollback button restores the latest saved delete batch from this page.
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
                        <?php if (($counts['service_orders'] ?? 0) > 0): ?>
                            <p class="text-xs text-gray-500 mt-3">
                                <code>service_orders</code> are shown for awareness only and are not deleted by this tool.
                            </p>
                        <?php endif; ?>
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
                        <div class="text-sm text-gray-600">No regular orders currently found for customer 25.</div>
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
                    <form method="post" onsubmit="return confirm('Delete all regular order transaction data for customer ID 25? A rollback batch will be saved first.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="delete_customer_25_orders" value="1">
                        <button
                            type="submit"
                            class="inline-flex items-center px-5 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition"
                            <?php echo (!$customer || empty($orders)) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                        >
                            Delete Customer 25 Order Transactions
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
