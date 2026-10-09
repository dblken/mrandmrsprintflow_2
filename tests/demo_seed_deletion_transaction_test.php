<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/demo_seed_deletion.php';

/** Real SQLite transactions/FKs exercise the same planner and delete executor as MySQL. */
final class DemoSeedSqliteTestDatabase implements DemoSeedDeletionDatabase
{
    public PDO $pdo;
    public ?string $failTable = null;
    public ?string $wrongCountTable = null;
    public array $engineOverrides = [];
    public array $externalLinkTables = [];
    public function __construct(string $databaseFile = ':memory:')
    {
        $this->pdo = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function write(string $sql, array $params = []): int
    {
        if ($this->failTable && str_starts_with($sql, 'DELETE FROM `' . $this->failTable . '`')) throw new RuntimeException('Simulated failure on ' . $this->failTable);
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        if ($this->wrongCountTable && str_starts_with($sql, 'DELETE FROM `' . $this->wrongCountTable . '`')) return 0;
        return $statement->rowCount();
    }
    public function schema(): array
    {
        $tables = $links = [];
        foreach ($this->rows("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $name = $table['name']; $columns = $pk = [];
            foreach ($this->rows('PRAGMA table_info(`' . $name . '`)') as $column) {
                $columns[] = $column['name']; if ($column['pk']) $pk[(int)$column['pk']] = $column['name'];
            }
            ksort($pk);
            $tables[$name] = ['columns' => $columns, 'pk' => array_values($pk), 'engine' => 'sqlite'];
            foreach ($this->rows('PRAGMA foreign_key_list(`' . $name . '`)') as $link) $links[] = ['table' => $name, 'column' => $link['from'], 'parent' => $link['table'], 'parent_column' => $link['to'], 'constraint' => 'sqlite_' . $name . '_' . $link['id']];
        }
        foreach ($this->engineOverrides as $table => $engine) $tables[$table]['engine'] = $engine;
        foreach ($links as &$link) if (in_array($link['table'], $this->externalLinkTables, true)) $link['external'] = true;
        unset($link);
        return ['tables' => $tables, 'foreign_keys' => $links];
    }
    public function begin(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { $this->pdo->rollBack(); }
    public function lockClause(): string { return ''; }
}

function fixture(string $databaseFile = ':memory:'): DemoSeedSqliteTestDatabase
{
    $db = new DemoSeedSqliteTestDatabase($databaseFile);
    $db->pdo->exec(<<<'SQL'
CREATE TABLE users (user_id INTEGER PRIMARY KEY);
CREATE TABLE products (product_id INTEGER PRIMARY KEY);
CREATE TABLE materials (id INTEGER PRIMARY KEY);
CREATE TABLE customers (customer_id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE orders (order_id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES customers(customer_id), order_date TEXT);
CREATE TABLE order_items (order_item_id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id), product_id INTEGER REFERENCES products(product_id), customization_data TEXT);
CREATE TABLE customizations (customization_id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id), order_item_id INTEGER REFERENCES order_items(order_item_id), customer_id INTEGER REFERENCES customers(customer_id), customization_details TEXT);
CREATE TABLE job_orders (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id), order_item_id INTEGER REFERENCES order_items(order_item_id), customer_id INTEGER REFERENCES customers(customer_id));
CREATE TABLE notifications (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id));
CREATE TABLE notification_history (id INTEGER PRIMARY KEY, notification_id INTEGER REFERENCES notifications(id));
CREATE TABLE order_status_history (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id));
CREATE TABLE payment_submissions (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id), batch_id TEXT);
CREATE TABLE inventory_transactions (id INTEGER PRIMARY KEY, job_order_id INTEGER REFERENCES job_orders(id), metadata TEXT);
CREATE TABLE job_order_materials (id INTEGER PRIMARY KEY, job_order_id INTEGER REFERENCES job_orders(id), material_id INTEGER REFERENCES materials(id), deducted_at TEXT);
CREATE TABLE demo_seed_batches (id INTEGER PRIMARY KEY, batch_id TEXT UNIQUE, total_orders INTEGER, status TEXT, imported_at TEXT, rolled_back_by INTEGER, rolled_back_at TEXT);
CREATE TABLE demo_seed_rows (id INTEGER PRIMARY KEY, batch_id TEXT, seed_row_key TEXT, order_id INTEGER, order_item_id INTEGER, customization_id INTEGER, job_order_id INTEGER, customer_id INTEGER, customer_created INTEGER);
CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(user_id), action TEXT, details TEXT, created_at TEXT);
INSERT INTO users VALUES(1);
INSERT INTO products VALUES(90);
INSERT INTO materials VALUES(50);
INSERT INTO customers VALUES(1,'Same name'),(2,'Same name');
INSERT INTO orders VALUES(1,1,'2026-10-01'),(2,2,'2026-10-01');
INSERT INTO order_items VALUES(10,1,90,'{"_seed_batch_id":"meet_20261001_v1"}'),(20,2,90,'{}');
INSERT INTO customizations VALUES(11,1,10,1,'{"_seed_batch_id":"meet_20261001_v1"}');
INSERT INTO job_orders VALUES(12,1,10,1);
INSERT INTO notifications VALUES(13,1);
INSERT INTO notification_history VALUES(14,13);
INSERT INTO order_status_history VALUES(15,1);
INSERT INTO job_order_materials VALUES(16,12,50,NULL);
INSERT INTO demo_seed_batches VALUES(1,'meet_20261001_v1',1,'active','2026-10-01',NULL,NULL);
INSERT INTO demo_seed_rows VALUES(17,'meet_20261001_v1','seed-one',1,10,11,12,1,1);
SQL);
    return $db;
}
function assertTrue(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function expectFailure(callable $action, string $text): void
{
    try { $action(); } catch (Throwable $error) { assertTrue(str_contains($error->getMessage(), $text), 'Unexpected error: ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected failure: ' . $text);
}
function countTable(DemoSeedSqliteTestDatabase $db, string $table): int { return (int)$db->rows('SELECT COUNT(*) AS c FROM `' . $table . '`')[0]['c']; }
function businessSnapshot(DemoSeedSqliteTestDatabase $db): array
{
    $snapshot = [];
    foreach (array_keys($db->schema()['tables']) as $table) if ($table !== 'activity_logs') $snapshot[$table] = $db->rows('SELECT * FROM `' . $table . '`');
    return $snapshot;
}

if (defined('DEMO_SEED_TEST_SUPPORT_ONLY')) return;
$tests = [];
$tests['successful deletion, exact counts and nested FK order'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $before = businessSnapshot($db); $plan = $tool->preview('meet_20261001_v1');
    assertTrue($plan['safe_to_delete'], 'Expected safe plan.');
    assertTrue(businessSnapshot($db) === $before, 'Dry run wrote data.');
    assertTrue(array_search('notification_history', $plan['delete_order'], true) < array_search('notifications', $plan['delete_order'], true), 'Nested dependency order incorrect.');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
    assertTrue(!$result['partial'] && $result['deleted_counts']['orders'] === 1 && $result['deleted_counts']['notifications'] === 1, 'Incorrect deleted counts.');
    assertTrue(countTable($db, 'orders') === 1 && $db->rows('SELECT order_id FROM orders')[0]['order_id'] === 2, 'Real order removed.');
    assertTrue(countTable($db, 'customers') === 1 && countTable($db, 'products') === 1 && countTable($db, 'materials') === 1 && countTable($db, 'users') === 1, 'Shared data removed.');
    assertTrue(countTable($db, 'demo_seed_rows') === 0 && $tool->preview('meet_20261001_v1')['no_records'], 'Post-delete preview not empty.');
    assertTrue(countTable($db, 'activity_logs') === 1, 'Success audit missing.');
};
$tests['wrong batch ID including case'] = static function (): void {
    $tool = new DemoSeedDeletionTool(fixture());
    expectFailure(fn() => $tool->preview('meet_20261001_v2'), 'not found');
    expectFailure(fn() => $tool->preview('MEET_20261001_V1'), 'not found');
};
foreach ([['Staff', true, DemoSeedDeletionTool::CONFIRMATION, true, 'Admin'], ['Admin', false, DemoSeedDeletionTool::CONFIRMATION, true, 'CSRF'], ['Admin', true, 'DELETING MEETING DATA', true, 'exact phrase'], ['Admin', true, ' DELETE MEETING DATA', true, 'exact phrase'], ['Admin', true, DemoSeedDeletionTool::CONFIRMATION, false, 'backup']] as $case) {
    $tests['authorization/confirmation: ' . $case[4]] = static fn() => expectFailure(fn() => DemoSeedDeletionTool::authorize($case[0], $case[1], $case[2], $case[3]), $case[4]);
}
$tests['shared customer and existing customer protection'] = static function (): void {
    foreach (['INSERT INTO orders VALUES(3,1,\'2026-10-01\')', 'UPDATE demo_seed_rows SET customer_created=0'] as $change) {
        $db = fixture(); $db->pdo->exec($change); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
        assertTrue(!isset($plan['tables']['customers']) && isset($plan['protected']['customers']), 'Shared/existing customer included.');
        $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
        assertTrue(countTable($db, 'customers') === 2, 'Protected customer removed.');
    }
};
$tests['unmarked payment/inventory protection and verified-only partial result'] = static function (): void {
    $db = fixture(); $db->pdo->exec("INSERT INTO payment_submissions VALUES(30,1,NULL); INSERT INTO inventory_transactions VALUES(31,12,'{}')");
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
    assertTrue(!$plan['safe_to_delete'] && isset($plan['protected']['inventory_transactions'], $plan['protected']['payment_submissions']), 'Unmarked financial rows eligible.');
    $plan = $tool->preview('meet_20261001_v1', true);
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue($result['partial'] && $result['remaining_registry_rows'] === 1, 'Partial result falsely reported complete.');
    $batch = $db->rows('SELECT status, rolled_back_at FROM demo_seed_batches')[0];
    assertTrue($batch['status'] === 'partial' && $batch['rolled_back_at'] === null, 'Partial batch was marked fully rolled back.');
    assertTrue(countTable($db, 'orders') === 2 && countTable($db, 'inventory_transactions') === 1 && countTable($db, 'payment_submissions') === 1, 'Protected rows changed.');
};
$tests['exact markers allow related demo payment/inventory only'] = static function (): void {
    $db = fixture(); $db->pdo->exec("INSERT INTO payment_submissions VALUES(30,1,'meet_20261001_v1'); INSERT INTO inventory_transactions VALUES(31,12,'{\"_seed_batch_id\":\"meet_20261001_v1\"}')");
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
    assertTrue($result['deleted_counts']['payment_submissions'] === 1 && $result['deleted_counts']['inventory_transactions'] === 1, 'Proven exact batch children not deleted.');
};
$tests['incomplete registry recovers IDs from real relationships'] = static function (): void {
    $db = fixture(); $db->pdo->exec('UPDATE demo_seed_rows SET order_item_id=NULL, customization_id=NULL, job_order_id=NULL');
    $tool = new DemoSeedDeletionTool($db); $blocked = $tool->preview('meet_20261001_v1');
    assertTrue(!$blocked['safe_to_delete'] && $blocked['requires_verified_only'], 'Incomplete registry did not require explicit choice.');
    $plan = $tool->preview('meet_20261001_v1', true);
    $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue(countTable($db, 'orders') === 1 && countTable($db, 'job_orders') === 0, 'Verified missing registry links not recovered.');
};
$tests['missing registry row recovered by exact hidden batch marker'] = static function (): void {
    $db = fixture(); $db->pdo->exec('DELETE FROM demo_seed_rows');
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue(countTable($db, 'orders') === 1 && countTable($db, 'customers') === 2, 'Marker recovery guessed customer creation ownership.');
};
$tests['conflicting batch marker protects real target'] = static function (): void {
    $db = fixture(); $db->write('UPDATE order_items SET customization_data=? WHERE order_item_id=10', ['{"_seed_batch_id":"another_batch"}']);
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    assertTrue(!isset($plan['tables']['orders']) && isset($plan['protected']['orders']), 'Conflicting marker order was targeted.');
};
$tests['foreign-key operation failure rolls back everything'] = static function (): void {
    $db = fixture(); $db->pdo->exec("CREATE TABLE unexpected_dependency (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id) ON DELETE RESTRICT); CREATE TRIGGER fail_delete BEFORE DELETE ON orders BEGIN INSERT INTO unexpected_dependency VALUES(500,OLD.order_id); END");
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1'); $before = businessSnapshot($db);
    expectFailure(fn() => $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false), 'FOREIGN KEY constraint failed');
    assertTrue(businessSnapshot($db) === $before, 'Failure left partial deletes or batch updates.');
    $audit = json_decode($db->rows('SELECT details FROM activity_logs')[0]['details'], true);
    assertTrue($audit['rolled_back'] && $audit['failure_reason'] !== null, 'Failure audit missing.');
};
$tests['simulated mid-transaction failure rolls back deleted children'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1'); $before = businessSnapshot($db); $db->failTable = 'orders';
    expectFailure(fn() => $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false), 'Simulated failure');
    assertTrue(businessSnapshot($db) === $before, 'Partial deletion did not roll back.');
};
$tests['repeated deletion is safe and reports no records'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
    $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false); $before = businessSnapshot($db);
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
    assertTrue($result['no_records'] && $result['deleted_counts'] === [] && businessSnapshot($db) === $before, 'Repeated request changed real data.');
};
$tests['stale dry run is rejected'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
    $db->pdo->exec('INSERT INTO notifications VALUES(99,1)'); $before = businessSnapshot($db);
    expectFailure(fn() => $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false), 'changed after');
    assertTrue(businessSnapshot($db) === $before, 'Stale dry run changed data.');
};
$tests['audit failure rolls back business deletion'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1'); $before = businessSnapshot($db);
    expectFailure(fn() => $tool->delete('meet_20261001_v1', 999, $plan['fingerprint'], false), 'audit could not');
    assertTrue(businessSnapshot($db) === $before, 'Audit failure left deleted data.');
};
$tests['protected CASCADE ledger child cannot be silently deleted'] = static function (): void {
    $db = fixture(); $db->pdo->exec('CREATE TABLE cash_ledger (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(order_id) ON DELETE CASCADE); INSERT INTO cash_ledger VALUES(80,1)');
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    assertTrue(!isset($plan['tables']['orders']) && isset($plan['protected']['cash_ledger']), 'Protected CASCADE child did not protect its parent.');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue($result['partial'] && countTable($db, 'cash_ledger') === 1 && countTable($db, 'orders') === 2, 'CASCADE changed protected data.');
};
$tests['polymorphic notification numeric match is protected'] = static function (): void {
    $db = fixture(); $db->pdo->exec('ALTER TABLE notifications ADD COLUMN data_id INTEGER; INSERT INTO notifications VALUES(80,2,1)');
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
    assertTrue($plan['protected']['notifications'][0]['id'] === 80, 'Ambiguous notification was not protected.');
    $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
    assertTrue(countTable($db, 'notifications') === 1 && $db->rows('SELECT order_id FROM notifications')[0]['order_id'] === 2, 'Real notification removed by numeric ID collision.');
};
$tests['already missing order recovers its proven orphan dependencies'] = static function (): void {
    $db = fixture(); $db->pdo->exec('PRAGMA foreign_keys=OFF; DELETE FROM orders WHERE order_id=1; PRAGMA foreign_keys=ON');
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    assertTrue(isset($plan['tables']['notifications'], $plan['tables']['order_status_history']), 'Missing-parent registry links lost their dependencies.');
    $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue(countTable($db, 'notifications') === 0 && countTable($db, 'demo_seed_rows') === 0 && $db->rows('PRAGMA foreign_key_check') === [], 'Orphan dependencies remained after reported completion.');
};
$tests['missing parent with unverified financial child retains registry evidence'] = static function (): void {
    $db = fixture(); $db->pdo->exec('PRAGMA foreign_keys=OFF; DELETE FROM orders WHERE order_id=1; INSERT INTO payment_submissions VALUES(80,1,NULL); PRAGMA foreign_keys=ON');
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    assertTrue(isset($plan['protected']['demo_seed_rows'], $plan['protected']['payment_submissions']), 'Missing-parent evidence was discarded.');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue($result['partial'] && countTable($db, 'demo_seed_rows') === 1 && countTable($db, 'payment_submissions') === 1, 'Protected orphan lost registry evidence.');
};
$tests['late exact marker discovery inspects every child before CASCADE'] = static function (): void {
    $db = fixture(); $db->pdo->exec("CREATE TABLE batch_attachment (id INTEGER PRIMARY KEY, order_id INTEGER, metadata TEXT); CREATE TABLE attachment_events (id INTEGER PRIMARY KEY, attachment_id INTEGER REFERENCES batch_attachment(id) ON DELETE CASCADE); INSERT INTO batch_attachment VALUES(80,1,'{\"_seed_batch_id\":\"meet_20261001_v1\"}'); INSERT INTO attachment_events VALUES(81,80)");
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1');
    assertTrue(isset($plan['tables']['attachment_events']), 'A discovered marker row had uninspected cascade children.');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
    assertTrue($result['deleted_counts']['attachment_events'] === 1 && $result['deleted_counts']['batch_attachment'] === 1, 'Implicit cascade was omitted from counts.');
};
$tests['composite primary keys are protected instead of guessed'] = static function (): void {
    $db = fixture(); $db->pdo->exec('CREATE TABLE compound_child (order_id INTEGER REFERENCES orders(order_id), sequence INTEGER, PRIMARY KEY(order_id,sequence)); INSERT INTO compound_child VALUES(1,1)');
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    assertTrue(isset($plan['protected']['compound_child']) && !isset($plan['tables']['orders']), 'Composite record was guessed.');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue($result['partial'] && countTable($db, 'compound_child') === 1, 'Composite protected row changed.');
};
$tests['nontransactional registry disables deletion'] = static function (): void {
    $db = fixture(); $db->engineOverrides['demo_seed_batches'] = 'MyISAM'; $before = businessSnapshot($db);
    expectFailure(fn() => (new DemoSeedDeletionTool($db))->preview('meet_20261001_v1'), 'support transactions');
    assertTrue(businessSnapshot($db) === $before, 'Nontransactional preview wrote data.');
};
$tests['affected count mismatch rolls back every deletion'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1'); $before = businessSnapshot($db); $db->wrongCountTable = 'orders';
    expectFailure(fn() => $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false), 'count mismatch');
    assertTrue(businessSnapshot($db) === $before, 'Count mismatch left a partial delete.');
};
$tests['cross-database FK numbers are not local ownership proof'] = static function (): void {
    $db = fixture(); $db->externalLinkTables = ['notifications'];
    $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1', true);
    assertTrue(isset($plan['protected']['notifications']) && !isset($plan['tables']['notifications'], $plan['tables']['orders']), 'External FK was treated as a local seed relationship.');
    $result = $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], true);
    assertTrue($result['partial'] && countTable($db, 'notifications') === 1 && countTable($db, 'notification_history') === 1, 'External/protected relationship changed.');
};

foreach ($tests as $name => $test) { $test(); echo 'PASS: ', $name, PHP_EOL; }
echo 'Demo deletion transaction tests: ', count($tests), ' passed (SQLite fixtures; no production connection).', PHP_EOL;
