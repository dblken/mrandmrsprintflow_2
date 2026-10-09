<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_role('Admin');

global $conn;
$ids = printflow_order_archive_manifest();
[$in, $types, $ids] = printflow_order_archive_prepare($ids);
$ready = printflow_order_archive_tables_ready();
$notice = '';
$error = '';

function pf_archive_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
}

function pf_archive_child_count(string $sql, string $types, array $params): int
{
    $rows = db_query($sql, $types, $params);
    return (int)($rows[0]['n'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'The request token is invalid or expired.';
    } elseif (!$ready) {
        $error = 'Archive tables are not installed. Apply the reviewed migration before using this page.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'archive_manifest') {
            if (!hash_equals('ARCHIVE PRINTFLOW 224 ORDERS', trim((string)($_POST['confirmation'] ?? '')))) {
                $error = 'Type ARCHIVE PRINTFLOW 224 ORDERS to confirm.';
            } else {
                $transactionStarted = false;
                try {
                    if (!$conn->begin_transaction()) throw new RuntimeException('Database transaction could not be started.');
                    $transactionStarted = true;
                    $liveRows = db_query("SELECT order_id FROM orders WHERE order_id IN ({$in}) ORDER BY order_id FOR UPDATE", $types, $ids) ?: [];
                    $liveIds = array_map(static fn(array $row): int => (int)$row['order_id'], $liveRows);
                    if (count($liveIds) !== 224 || $liveIds !== $ids) throw new RuntimeException('Live manifest changed; expected exactly the 224 approved order IDs.');
                    $live = count($liveIds);
                    $existing = pf_archive_child_count("SELECT COUNT(*) AS n FROM printflow_order_archive_map WHERE order_id IN ({$in})", $types, $ids);
                    if ($existing !== 0) throw new RuntimeException('At least one manifest order already has archive history; no rows were archived.');

                    $actor = (int)get_user_id();
                    if ($actor <= 0 || get_user_type() !== 'Admin') throw new RuntimeException('Admin identity could not be verified.');
                    $batchId = 'pf_archive_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
                    $mapStmt = $conn->prepare("INSERT INTO printflow_order_archive_map (order_id, archive_batch_id, archived_by, archived_at, reason, status) VALUES (?, ?, ?, NOW(), ?, 'archived')");
                    $batchEventStmt = $conn->prepare("INSERT INTO printflow_order_archive_batch_events (archive_batch_id, actor_user_id, event_at, date_range_start, date_range_end, manifest_order_count, manifest_order_ids, protected_record_notes, operation_result, related_record_counts) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
                    if (!$mapStmt || !$batchEventStmt) throw new RuntimeException('Archive statements could not be prepared.');
                    $rangeStart = '2026-09-07 00:00:00';
                    $rangeEnd = '2026-10-08 23:59:59';
                    $reason = 'Exact approved 224-order manifest; business dates 2026-09-07 through 2026-10-08';
                    $auditCounts = ['orders' => $live];
                    foreach (['order_items','customizations','job_orders','order_messages','order_notes','order_revision_requests','change_item_requests','order_item_revisions','change_item_evidence','receipt_print_jobs','reviews','job_order_files','order_status_history'] as $table) {
                        if (db_table_has_column($table, 'order_id')) $auditCounts[$table] = pf_archive_child_count("SELECT COUNT(*) AS n FROM `{$table}` WHERE order_id IN ({$in})", $types, $ids);
                    }
                    if (db_table_has_column('provider_payments', 'subject_id')) $auditCounts['provider_payments'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM provider_payments WHERE subject_type='order' AND subject_id IN ({$in})", $types, $ids);
                    if (db_table_has_column('payment_submissions', 'order_id')) $auditCounts['payment_submissions'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM payment_submissions WHERE order_id IN ({$in})", $types, $ids);
                    if (db_table_has_column('notifications', 'data_id')) $auditCounts['direct_order_notifications'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM notifications WHERE type='Order' AND CAST(data_id AS UNSIGNED) IN ({$in})", $types, $ids);
                    if (db_table_has_column('job_order_materials', 'job_order_id')) $auditCounts['job_order_materials'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM job_order_materials jm JOIN job_orders jo ON jo.id=jm.job_order_id WHERE jo.order_id IN ({$in})", $types, $ids);
                    if (db_table_has_column('job_order_ink_usage', 'job_order_id')) $auditCounts['job_order_ink_usage'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM job_order_ink_usage ju JOIN job_orders jo ON jo.id=ju.job_order_id WHERE jo.order_id IN ({$in})", $types, $ids);
                    if (db_table_has_column('job_order_files', 'job_order_id')) $auditCounts['job_order_files'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM job_order_files jf JOIN job_orders jo ON jo.id=jf.job_order_id WHERE jo.order_id IN ({$in})", $types, $ids);
                    if (db_table_has_column('provider_payment_status_history', 'provider_payment_id') && db_table_has_column('provider_payments', 'id')) {
                        $auditCounts['provider_payment_status_history'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM provider_payment_status_history pph JOIN provider_payments pp ON pp.id=pph.provider_payment_id WHERE pp.subject_type='order' AND pp.subject_id IN ({$in})", $types, $ids);
                    }
                    if (db_table_has_column('inventory_movements', 'order_id')) $auditCounts['inventory_movements'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM inventory_movements WHERE order_id IN ({$in})", $types, $ids);
                    if (db_table_has_column('inventory_transactions', 'ref_id') && db_table_has_column('change_item_requests', 'change_item_id')) {
                        $auditCounts['inventory_transactions_order_path'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM inventory_transactions it WHERE (UPPER(it.ref_type) IN ('ORDER','ORDER_PRODUCT') AND it.ref_id IN ({$in})) OR (UPPER(it.ref_type)='JOB_ORDER' AND it.ref_id IN (SELECT id FROM job_orders WHERE order_id IN ({$in}))) OR (UPPER(it.ref_type)='CHANGE_ITEM' AND it.ref_id IN (SELECT change_item_id FROM change_item_requests WHERE order_id IN ({$in})))", str_repeat('i', 672), array_merge($ids, $ids, $ids));
                    }
                    $protectedNotes = 'Customers 25, 44, 288, and 306 are protected. This operation does not modify customer records, customer-only notifications, payments, inventory transactions, stock quantities, or ledger balances; it writes only exact order archive mappings and batch audit metadata.';
                    $manifestJson = pf_archive_json($ids);
                    $relatedCountsJson = pf_archive_json($auditCounts);
                    $operationResult = 'archived';
                    $insertedMappings = 0;
                    foreach ($ids as $orderId) {
                        $mapStmt->bind_param('isis', $orderId, $batchId, $actor, $reason);
                        if (!$mapStmt->execute() || $mapStmt->affected_rows !== 1) throw new RuntimeException('An archive mapping could not be written exactly once.');
                        $insertedMappings++;
                    }
                    $mapStmt->close();
                    if ($insertedMappings !== 224) throw new RuntimeException('Exactly 224 archive mappings were not written.');
                    $batchEventStmt->bind_param('sississss', $batchId, $actor, $rangeStart, $rangeEnd, $live, $manifestJson, $protectedNotes, $operationResult, $relatedCountsJson);
                    if (!$batchEventStmt->execute() || $batchEventStmt->affected_rows !== 1) throw new RuntimeException('The batch archive audit event could not be written.');
                    $batchEventStmt->close();
                    if (!$conn->commit()) throw new RuntimeException('Archive transaction commit failed.');
                    $transactionStarted = false;
                    $notice = 'Archive batch ' . $batchId . ' recorded for exactly 224 orders.';
                } catch (Throwable $e) {
                    if ($transactionStarted) $conn->rollback();
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'restore') {
            $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['order_ids'] ?? [])), static fn($id) => $id > 0)));
            if ($selected === [] || !hash_equals('RESTORE SELECTED ORDERS', trim((string)($_POST['confirmation'] ?? '')))) {
                $error = 'Select archived order(s) and type RESTORE SELECTED ORDERS.';
            } else {
                [$restoreIn, $restoreTypes, $selected] = printflow_order_archive_prepare($selected);
                $transactionStarted = false;
                try {
                    if (!$conn->begin_transaction()) throw new RuntimeException('Database transaction could not be started.');
                    $transactionStarted = true;
                    $actor = (int)get_user_id();
                    $rows = db_query("SELECT order_id, archive_batch_id FROM printflow_order_archive_map WHERE status='archived' AND order_id IN ({$restoreIn}) FOR UPDATE", $restoreTypes, $selected) ?: [];
                    if (count($rows) !== count($selected)) throw new RuntimeException('Selection changed or includes an order that is not archived.');
                    foreach ($rows as $row) {
                        $orderId = (int)$row['order_id'];
                        $batchId = (string)$row['archive_batch_id'];
                        if (db_execute("UPDATE printflow_order_archive_map SET status='restored', restored_at=NOW(), restored_by=? WHERE order_id=? AND status='archived'", 'ii', [$actor, $orderId]) === false) {
                            throw new RuntimeException('An archive mapping could not be restored.');
                        }
                        $details = pf_archive_json(['restore_scope' => 'selected order']);
                        if (db_execute("INSERT INTO printflow_order_archive_events (archive_batch_id, order_id, action, actor_user_id, event_at, details_json) VALUES (?, ?, 'restore', ?, NOW(), ?)", 'siis', [$batchId, $orderId, $actor, $details]) === false) {
                            throw new RuntimeException('A restore audit event could not be written.');
                        }
                    }
                    if (!$conn->commit()) throw new RuntimeException('Restore transaction commit failed.');
                    $transactionStarted = false;
                    $notice = 'Restored ' . count($selected) . ' order(s).';
                } catch (Throwable $e) {
                    if ($transactionStarted) $conn->rollback();
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$orders = db_query("SELECT order_id, order_date, customer_id FROM orders WHERE order_id IN ({$in}) ORDER BY order_id", $types, $ids) ?: [];
$archiveRows = $ready
    ? (db_query("SELECT m.order_id, m.archive_batch_id, m.archived_by, m.archived_at, m.reason, m.status, m.restored_at, o.order_date, o.customer_id FROM printflow_order_archive_map m LEFT JOIN orders o ON o.order_id=m.order_id ORDER BY m.archived_at DESC, m.order_id", '', []) ?: [])
    : [];
$batchAuditRows = $ready
    ? (db_query("SELECT archive_batch_id, actor_user_id, event_at, date_range_start, date_range_end, manifest_order_count, manifest_order_ids, protected_record_notes, operation_result FROM printflow_order_archive_batch_events ORDER BY event_at DESC", '', []) ?: [])
    : [];
$manifestHistory = $ready ? pf_archive_child_count("SELECT COUNT(*) AS n FROM printflow_order_archive_map WHERE order_id IN ({$in})", $types, $ids) : 0;
$manifestArchived = $ready ? pf_archive_child_count("SELECT COUNT(*) AS n FROM printflow_order_archive_map WHERE status='archived' AND order_id IN ({$in})", $types, $ids) : 0;
$counts = [];
if ($ready && count($orders) === 224) {
    $counts['orders'] = count($orders);
    foreach (['order_items','customizations','job_orders','order_messages','order_notes','order_revision_requests','change_item_requests','order_item_revisions','change_item_evidence','receipt_print_jobs','reviews','job_order_files','order_status_history'] as $table) {
        if (db_table_has_column($table, 'order_id')) $counts[$table] = pf_archive_child_count("SELECT COUNT(*) AS n FROM `{$table}` WHERE order_id IN ({$in})", $types, $ids);
    }
    if (db_table_has_column('provider_payments', 'subject_id')) $counts['provider_payments'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM provider_payments WHERE subject_type='order' AND subject_id IN ({$in})", $types, $ids);
    if (db_table_has_column('payment_submissions', 'order_id')) $counts['payment_submissions'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM payment_submissions WHERE order_id IN ({$in})", $types, $ids);
    if (db_table_has_column('notifications', 'data_id')) $counts['direct_order_notifications'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM notifications WHERE type='Order' AND CAST(data_id AS UNSIGNED) IN ({$in})", $types, $ids);
    if (db_table_has_column('order_status_history', 'order_id')) $counts['order_status_history'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM order_status_history WHERE order_id IN ({$in})", $types, $ids);
    if (db_table_has_column('job_order_materials', 'job_order_id')) $counts['job_order_materials'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM job_order_materials jm JOIN job_orders jo ON jo.id=jm.job_order_id WHERE jo.order_id IN ({$in})", $types, $ids);
    if (db_table_has_column('job_order_ink_usage', 'job_order_id')) $counts['job_order_ink_usage'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM job_order_ink_usage ju JOIN job_orders jo ON jo.id=ju.job_order_id WHERE jo.order_id IN ({$in})", $types, $ids);
    if (db_table_has_column('job_order_files', 'job_order_id')) $counts['job_order_files'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM job_order_files jf JOIN job_orders jo ON jo.id=jf.job_order_id WHERE jo.order_id IN ({$in})", $types, $ids);
    if (db_table_has_column('provider_payment_status_history', 'provider_payment_id') && db_table_has_column('provider_payments', 'id')) {
        $counts['provider_payment_status_history'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM provider_payment_status_history pph JOIN provider_payments pp ON pp.id=pph.provider_payment_id WHERE pp.subject_type='order' AND pp.subject_id IN ({$in})", $types, $ids);
    }
    if (db_table_has_column('inventory_movements', 'order_id')) $counts['inventory_movements'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM inventory_movements WHERE order_id IN ({$in})", $types, $ids);
    if (db_table_has_column('inventory_transactions', 'ref_id')) {
        $counts['inventory_transactions_order_path'] = pf_archive_child_count("SELECT COUNT(*) AS n FROM inventory_transactions it WHERE (UPPER(it.ref_type) IN ('ORDER','ORDER_PRODUCT') AND it.ref_id IN ({$in})) OR (UPPER(it.ref_type)='JOB_ORDER' AND it.ref_id IN (SELECT id FROM job_orders WHERE order_id IN ({$in}))) OR (UPPER(it.ref_type)='CHANGE_ITEM' AND it.ref_id IN (SELECT change_item_id FROM change_item_requests WHERE order_id IN ({$in})))", str_repeat('i', 672), array_merge($ids, $ids, $ids));
    }
}
$basePath = defined('BASE_PATH') ? BASE_PATH : '/printflow';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Archived Orders</title><link rel="stylesheet" href="<?php echo htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'); ?>/public/assets/css/output.css"></head><body>
<div class="dashboard-container"><?php include __DIR__ . '/../includes/admin_sidebar.php'; ?><main class="main-content" style="padding:24px;max-width:1200px;margin:auto">
<h1>Archived Orders</h1><p>Record-specific visibility archive. Original orders, payments, inventory ledgers, stock, customers, and notifications are not changed.</p>
<?php if ($notice !== ''): ?><div role="status" style="padding:12px;background:#ecfdf5;color:#065f46;margin:12px 0"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div role="alert" style="padding:12px;background:#fef2f2;color:#991b1b;margin:12px 0"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (!$ready): ?><div role="alert" style="padding:12px;background:#fff7ed;color:#9a3412">Archive storage migrations are required: <code>migrations/20261008_create_order_archive_tables.sql</code> and <code>migrations/20261008_create_order_archive_batch_events.sql</code>. No schema changes are applied automatically.</div><?php else: ?>
<section style="background:#fff;padding:18px;margin:16px 0;border:1px solid #ddd;border-radius:8px"><h2>Manifest preview</h2><p>Live order rows found: <strong><?php echo count($orders); ?>/224</strong>. Existing archive history rows: <strong><?php echo $manifestHistory; ?></strong> (currently archived: <?php echo $manifestArchived; ?>).</p>
<?php if ($counts): ?><table><thead><tr><th>Related table/path</th><th>Rows</th></tr></thead><tbody><?php foreach ($counts as $name=>$count): ?><tr><td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$count; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<details><summary>Exact order ID manifest</summary><p style="overflow-wrap:anywhere"><?php echo htmlspecialchars(implode(', ', $ids), ENT_QUOTES, 'UTF-8'); ?></p></details>
<p>Approved business-date range: <strong>2026-09-07 00:00:00 through 2026-10-08 23:59:59</strong>. Protected customer IDs: 25, 44, 288, 306. Direct order notifications are counted only where the notification type and data_id directly identify an order. Inventory transaction rows remain untouched.</p>
<?php if (count($orders) === 224 && $manifestHistory === 0): ?><form method="post" onsubmit="return confirm('Archive only the exact 224-order manifest? No rows will be deleted.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="archive_manifest"><label>Type <code>ARCHIVE PRINTFLOW 224 ORDERS</code> <input required name="confirmation" autocomplete="off"></label><button type="submit">Archive exact 224 orders</button></form><?php else: ?><p>Archive action unavailable: manifest must contain exactly 224 orders and no prior archive history.</p><?php endif; ?></section>
<section style="background:#fff;padding:18px;margin:16px 0;border:1px solid #ddd;border-radius:8px"><h2>Batch audit history</h2><table><thead><tr><th>Batch</th><th>Range</th><th>Order count</th><th>Admin user ID</th><th>Event time</th><th>Result</th></tr></thead><tbody>
<?php foreach ($batchAuditRows as $event): ?><tr><td><?php echo htmlspecialchars((string)$event['archive_batch_id'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$event['date_range_start'], ENT_QUOTES, 'UTF-8'); ?> – <?php echo htmlspecialchars((string)$event['date_range_end'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$event['manifest_order_count']; ?></td><td><?php echo (int)$event['actor_user_id']; ?></td><td><?php echo htmlspecialchars((string)$event['event_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$event['operation_result'], ENT_QUOTES, 'UTF-8'); ?></td></tr><tr><td colspan="6"><details><summary>Manifest and protected-record notes</summary><p style="overflow-wrap:anywhere"><?php echo htmlspecialchars((string)$event['manifest_order_ids'], ENT_QUOTES, 'UTF-8'); ?></p><p><?php echo htmlspecialchars((string)$event['protected_record_notes'], ENT_QUOTES, 'UTF-8'); ?></p></details></td></tr><?php endforeach; ?>
</tbody></table></section>
<section style="background:#fff;padding:18px;margin:16px 0;border:1px solid #ddd;border-radius:8px"><h2>Archive history</h2><p>Archived mappings: <?php echo count(array_filter($archiveRows, static fn($r)=>(string)$r['status']==='archived')); ?></p>
<form method="post" onsubmit="return confirm('Restore only the selected orders?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="restore"><table><thead><tr><th>Select</th><th>Order ID</th><th>Order date</th><th>Batch</th><th>Archived at</th><th>Admin user ID</th><th>Status</th></tr></thead><tbody>
<?php foreach ($archiveRows as $row): ?><tr><td><?php if ($row['status']==='archived'): ?><input type="checkbox" name="order_ids[]" value="<?php echo (int)$row['order_id']; ?>"><?php endif; ?></td><td><?php echo (int)$row['order_id']; ?></td><td><?php echo htmlspecialchars((string)$row['order_date'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$row['archive_batch_id'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$row['archived_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$row['archived_by']; ?></td><td><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
</tbody></table><label>Type <code>RESTORE SELECTED ORDERS</code> <input name="confirmation" autocomplete="off"></label><button type="submit">Restore selected</button></form></section>
<?php endif; ?></main></div></body></html>
