<?php
declare(strict_types=1);
define('DEMO_SEED_TEST_SUPPORT_ONLY', true);
require_once __DIR__ . '/demo_seed_deletion_transaction_test.php';
require_once __DIR__ . '/../includes/demo_seed_audit.php';
$tests = [];
$tests['exact batch IDs and linked per-table counts with no writes'] = static function (): void {
    $db = fixture(); $before = businessSnapshot($db); $logs = countTable($db, 'activity_logs');
    $report = (new DemoSeedReadOnlyAudit($db))->report(); $batch = $report['batches'][0];
    assertTrue($report['read_only'] && $batch['batch_id'] === 'meet_20261001_v1', 'Wrong database batch.');
    assertTrue($batch['registry_count'] === 1 && $batch['per_table']['orders']['related_total'] === 1 && $batch['per_table']['order_items']['related_total'] === 1, 'Wrong exact related counts.');
    assertTrue($batch['per_table']['notification_history']['related_total'] === 1 && $batch['per_table']['customers']['related_total'] === 1, 'Missing descendants/customers.');
    assertTrue(businessSnapshot($db) === $before && countTable($db, 'activity_logs') === $logs, 'Audit wrote data.');
};
$tests['zero registry does not establish fully deleted or hide batch'] = static function (): void {
    $db = fixture(); $db->pdo->exec('DELETE FROM demo_seed_rows');
    $batch = (new DemoSeedReadOnlyAudit($db))->report()['batches'][0];
    assertTrue($batch['registry_count'] === 0 && $batch['per_table']['orders']['related_total'] === 1, 'Hidden marker recovery missing.');
    assertTrue(!$batch['safe_to_remove_dropdown_entry'] && $batch['deletion_status'] !== 'Fully Deleted', 'Zero registry was used as complete deletion proof.');
};
$tests['orphan financial marker remains discoverable outside preview roots'] = static function (): void {
    $db = fixture(); $db->pdo->exec("INSERT INTO payment_submissions VALUES(100,NULL,'meet_20261001_v1')");
    $batch = (new DemoSeedReadOnlyAudit($db))->report()['batches'][0];
    assertTrue($batch['per_table']['payment_submissions']['protected_or_unverified'] === 1, 'Orphan payment was missed.');
    assertTrue(countTable($db, 'payment_submissions') === 1, 'Orphan payment changed.');
};
$tests['substring batch markers are not attributed and real rows remain'] = static function (): void {
    $db = fixture(); $db->pdo->exec("INSERT INTO payment_submissions VALUES(100,NULL,'meet_20261001_v10')");
    $batch = (new DemoSeedReadOnlyAudit($db))->report()['batches'][0];
    assertTrue($batch['per_table']['payment_submissions']['related_total'] === 0 && countTable($db, 'orders') === 2, 'Substring/real record attributed.');
};
$tests['empty batch is explicitly unproven after witness removal'] = static function (): void {
    $db = fixture(); $tool = new DemoSeedDeletionTool($db); $plan = $tool->preview('meet_20261001_v1'); $tool->delete('meet_20261001_v1', 1, $plan['fingerprint'], false);
    $before = businessSnapshot($db); $batch = (new DemoSeedReadOnlyAudit($db))->report()['batches'][0];
    assertTrue($batch['deletion_status'] === 'Not Found' && !$batch['safe_to_remove_dropdown_entry'], 'Empty discovery was incorrectly called proven deletion.');
    assertTrue(businessSnapshot($db) === $before, 'Empty audit changed metadata.');
};
$tests['shared customer and unmarked inventory are reported protected'] = static function (): void {
    $db = fixture(); $db->pdo->exec("UPDATE orders SET customer_id=1 WHERE order_id=2; INSERT INTO inventory_transactions VALUES(100,12,'{}')");
    $batch = (new DemoSeedReadOnlyAudit($db))->report()['batches'][0];
    assertTrue($batch['per_table']['customers']['protected_or_unverified'] === 1 && $batch['per_table']['inventory_transactions']['protected_or_unverified'] === 1, 'Shared customer/inventory protection missing.');
    assertTrue(countTable($db, 'orders') === 2 && countTable($db, 'inventory_transactions') === 1, 'Real data changed.');
};
$tests['guard rejects writes transactions and locking reads'] = static function (): void {
    $db = new DemoSeedReadOnlyDatabase(fixture());
    expectFailure(fn() => $db->write('DELETE FROM orders'), 'cannot write');
    expectFailure(fn() => $db->begin(), 'cannot start');
    expectFailure(fn() => $db->rows('SELECT * FROM orders FOR UPDATE'), 'SELECT queries only');
};
foreach ($tests as $name => $test) { $test(); echo 'PASS: ', $name, PHP_EOL; }
echo 'Read-only audit tests: ', count($tests), ' passed (SQLite fixtures; no production connection).', PHP_EOL;
