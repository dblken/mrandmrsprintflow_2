<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_role('Admin');

global $conn;
$ids = printflow_order_archive_manifest();
[$in, $types, $ids] = printflow_order_archive_prepare($ids);
$ready = printflow_order_archive_tables_ready();
$jennyArchiveReady = printflow_customer_exclusion_schema_ready();
$basePath = defined('BASE_PATH') ? BASE_PATH : '/printflow';
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
<?php if (!$jennyArchiveReady): ?><div role="alert" style="padding:12px;background:#fff7ed;color:#9a3412;margin:12px 0">Customer archive storage is not ready. No change was made; contact an Admin to verify the customer exclusion migration.</div><?php else: ?>
<section class="pf-customer-archive" aria-labelledby="pfCustomerArchiveHeading">
<style>
.pf-customer-archive{background:#fff;padding:20px;margin:16px 0;border:1px solid #d8e1eb;border-radius:12px;box-shadow:0 4px 18px rgba(15,35,55,.06)}.pf-customer-archive h2{margin:0 0 8px}.pf-ca-muted{color:#536579}.pf-ca-search{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:16px 0}.pf-ca-search label{display:grid;gap:6px;min-width:min(100%,320px);font-weight:600}.pf-ca-search input,.pf-ca-search select{border:1px solid #cbd5e1;border-radius:7px;padding:10px;background:white}.pf-ca-actions{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.pf-ca-button{border:0;border-radius:7px;padding:10px 16px;font-weight:700;cursor:pointer}.pf-ca-button:disabled{opacity:.45;cursor:not-allowed}.pf-ca-primary{background:#0b6675;color:#fff}.pf-ca-restore{background:#177245;color:white}.pf-ca-box{padding:12px;border-radius:8px;background:#f2f7fb;margin:12px 0;white-space:pre-wrap}.pf-ca-box.error{background:#fff1f2;color:#9f1239}.pf-ca-box.success{background:#ecfdf3;color:#166534}.pf-ca-scroll{max-height:350px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px}.pf-ca-table{width:100%;border-collapse:collapse}.pf-ca-table th,.pf-ca-table td{text-align:left;padding:9px 10px;border-bottom:1px solid #e2e8f0;white-space:nowrap}.pf-ca-table th{position:sticky;top:0;background:#f8fafc;z-index:1}.pf-ca-chip{display:inline-block;padding:3px 8px;border-radius:99px;background:#e0f2fe;color:#075985;font-size:12px;font-weight:700}.pf-ca-dialog{border:0;border-radius:12px;max-width:520px;width:calc(100% - 30px);padding:22px;box-shadow:0 20px 65px rgba(0,0,0,.25)}.pf-ca-dialog::backdrop{background:rgba(15,23,42,.55)}.pf-ca-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
</style>
<h2 id="pfCustomerArchiveHeading">Customer order archive</h2>
<p class="pf-ca-muted">This temporary archive control is limited to Jenny Ferr, customer ID 25. It hides records from application views; it does not delete or edit orders, payments, inventory, files, webhooks, audit rows, or customer accounts. An Admin can restore visibility here.</p>
<div class="pf-ca-search"><label for="pfCaSearch">Search customer by name, email, or customer ID<input id="pfCaSearch" type="search" autocomplete="off" placeholder="Jenny Ferr, email, or 25"></label><label for="pfCaCustomer">Matching customer<select id="pfCaCustomer" disabled><option value="">Search to find the authorized account</option></select></label></div>
<div id="pfCaMessage" class="pf-ca-box" role="status">Search for Jenny Ferr and select the matching account to load the live preview.</div>
<div id="pfCaCustomerSummary" hidden></div>
<div class="pf-ca-actions"><button id="pfCaHide" class="pf-ca-button pf-ca-primary" type="button" disabled>Hide Jenny Ferr’s Orders</button><button id="pfCaRestore" class="pf-ca-button pf-ca-restore" type="button" disabled>Restore Jenny Ferr’s Orders</button></div>
<h3>Records that will be hidden</h3><div id="pfCaScope" class="pf-ca-scroll"><table class="pf-ca-table"><tbody><tr><td>Load a customer preview to see exact counts.</td></tr></tbody></table></div>
<h3>Jenny Ferr orders and current archive status</h3><div id="pfCaOrders" class="pf-ca-scroll"><table class="pf-ca-table"><tbody><tr><td>Select the customer to list current order codes and dates.</td></tr></tbody></table></div>
<h3>Archived customers and orders</h3><div id="pfCaArchives" class="pf-ca-scroll"><table class="pf-ca-table"><tbody><tr><td>Loading archive history…</td></tr></tbody></table></div>
<dialog id="pfCaDialog" class="pf-ca-dialog"><h2 id="pfCaDialogTitle">Confirm archive</h2><p id="pfCaDialogText"></p><div class="pf-ca-dialog-actions"><button id="pfCaDialogCancel" class="pf-ca-button" type="button">Cancel</button><button id="pfCaDialogConfirm" class="pf-ca-button pf-ca-primary" type="button">Confirm</button></div></dialog>
<script>
(() => {
 const api='api_jenny_customer_exclusion.php', previewApi='api_jenny_cleanup_preview.php';
 const search=document.getElementById('pfCaSearch'), select=document.getElementById('pfCaCustomer'), message=document.getElementById('pfCaMessage'), summary=document.getElementById('pfCaCustomerSummary'), scope=document.getElementById('pfCaScope'), orders=document.getElementById('pfCaOrders'), archives=document.getElementById('pfCaArchives'), hide=document.getElementById('pfCaHide'), restore=document.getElementById('pfCaRestore'), dialog=document.getElementById('pfCaDialog');
 let selectedId=0, currentStatus='inactive', csrf=document.querySelector('meta[name="csrf-token"]')?.content||'', ready=false, pendingAction=''; let timer;
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const setMessage=(text,kind='')=>{message.className='pf-ca-box'+(kind?' '+kind:'');message.textContent=text;};
 async function jsonFetch(url,options={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});let body;try{body=await r.json()}catch{throw new Error(`Server returned unreadable JSON (HTTP ${r.status}).`)}if(!r.ok||body.success!==true)throw new Error(body.error||`Request failed (HTTP ${r.status}).`);return body;}
 async function findCustomer(){clearTimeout(timer);timer=setTimeout(async()=>{select.disabled=true;select.innerHTML='<option value="">Searching…</option>';const q=search.value.trim();try{const data=await jsonFetch(`${api}?search=${encodeURIComponent(q)}`);select.innerHTML='<option value="">Select Jenny Ferr</option>';for(const c of data.customers||[]){const opt=document.createElement('option');opt.value=String(c.customer_id);opt.textContent=`${c.name} (#${c.customer_id}) — ${c.email}`;select.appendChild(opt);}select.disabled=false;setMessage((data.customers||[]).length?'Select Jenny Ferr to load the live order preview.':'No matching Jenny Ferr account. This archive control is restricted to customer ID 25.');}catch(e){select.innerHTML='<option value="">Search unavailable</option>';setMessage(e.message,'error');}},220);}
 async function loadPreview(){ready=false;hide.disabled=true;restore.disabled=true;summary.hidden=true;scope.innerHTML='<table class="pf-ca-table"><tbody><tr><td>Loading exact counts…</td></tr></tbody></table>';orders.innerHTML='<table class="pf-ca-table"><tbody><tr><td>Loading orders…</td></tr></tbody></table>';setMessage('Verifying customer ownership and loading current records…');try{const [control,preview]=await Promise.all([jsonFetch(api),jsonFetch(previewApi)]);if(control.customer_id!==25||preview.target?.customer_id!==25||preview.database?.name!=='u618446170_printflow')throw new Error('The live target or database did not match customer ID 25.');csrf=control.csrf_token||csrf;currentStatus=control.exclusion?.status||'inactive';const c=control.customer||{};summary.hidden=false;summary.innerHTML=`<strong>${esc(c.first_name)} ${esc(c.last_name)} (#${control.customer_id})</strong> · ${esc(c.email||'')} · ${Number(control.orders_currently_owned)||0} orders · Archive status: <span class="pf-ca-chip">${esc(currentStatus==='active'?'Archived':'Visible')}</span>${control.archive_reference_id?` · Reference: <code>${esc(control.archive_reference_id)}</code>`:''}`;
 const groups=Object.entries(preview.records||{});scope.innerHTML=`<table class="pf-ca-table"><thead><tr><th>Record group</th><th>Count</th><th>Disposition</th></tr></thead><tbody>${groups.map(([name,item])=>`<tr><td>${esc(name)}</td><td>${Number(item.count)||0}</td><td>${esc(item.classification||'Hidden from views; source retained')}</td></tr>`).join('')||'<tr><td colspan="3">No related record counts returned.</td></tr>'}</tbody></table>`;
 const rows=control.orders||[];orders.innerHTML=`<table class="pf-ca-table"><thead><tr><th>Order code</th><th>Order date</th><th>Order status</th><th>Archive status</th></tr></thead><tbody>${rows.map(o=>`<tr><td>${esc(o.order_code)}</td><td>${esc(o.order_date)}</td><td>${esc(o.status)}</td><td>${esc(o.archive_status)}</td></tr>`).join('')||'<tr><td colspan="4">No current orders found.</td></tr>'}</tbody></table>`;
 ready=true;hide.disabled=currentStatus==='active';restore.disabled=currentStatus!=='active';setMessage(`Verified live database ${preview.database.name}; preview ${preview.diagnostic_version}. ${rows.length} current orders checked. No transaction records were changed.`,'success');await loadArchiveList();
 }catch(e){ready=false;setMessage(`Preview unavailable: ${e.message}. Archive actions are disabled.`,'error');}}
 async function loadArchiveList(){try{const data=await jsonFetch(`${api}?list=1`);const rows=data.archives||[];archives.innerHTML=`<table class="pf-ca-table"><thead><tr><th>Customer</th><th>Customer ID</th><th>Hidden orders</th><th>Date archived</th><th>Archived by</th><th>Status</th><th>Reference</th><th>Action</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.first_name)} ${esc(r.last_name)}</td><td>${Number(r.customer_id)}</td><td>${r.status==='active'?Number(r.total_orders)||0:0}</td><td>${esc(r.archived_at)}</td><td>${esc(r.archived_by_name||`User #${r.archived_by}`)}</td><td>${esc(r.status)}</td><td>${esc(r.archive_reference_id||'—')}</td><td>${Number(r.customer_id)===25&&r.status==='active'?'<button type="button" class="pf-ca-button pf-ca-restore" data-restore="25">Restore</button>':'—'}</td></tr>`).join('')||'<tr><td colspan="8">No customers are currently archived.</td></tr>'}</tbody></table>`;archives.querySelectorAll('[data-restore]').forEach(b=>b.addEventListener('click',()=>openDialog('restore')));}catch(e){archives.innerHTML=`<p class="pf-ca-box error">Archive list unavailable: ${esc(e.message)}</p>`;}}
 function openDialog(action){if(!ready||selectedId!==25)return;pendingAction=action;document.getElementById('pfCaDialogTitle').textContent=action==='activate'?'Hide Jenny Ferr’s Orders?':'Restore Jenny Ferr’s Orders?';document.getElementById('pfCaDialogText').textContent=action==='activate'?'This will hide Jenny’s orders from Admin, Manager, Staff, Customer, reports, dashboards, search, notifications, payments, inventory summaries, and order lists. It does not delete database records or change payment, inventory, file, webhook, audit, or account records. An Admin can restore them later.':'This will restore Jenny’s orders to application views. It changes only Jenny’s customer and exact order exclusion records; it does not edit orders, payments, inventory, files, webhooks, audit rows, or other customers.';dialog.showModal();}
 document.getElementById('pfCaDialogCancel').addEventListener('click',()=>dialog.close());document.getElementById('pfCaDialogConfirm').addEventListener('click',async()=>{if(!ready||!pendingAction)return;dialog.close();hide.disabled=true;restore.disabled=true;setMessage('Saving the reversible customer archive…');try{const result=await jsonFetch(api,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:pendingAction,csrf_token:csrf})});const c=result.customer_name||'Jenny Ferr';setMessage(pendingAction==='activate'?`${Number(result.orders_hidden)||0} orders hidden for ${c} (#25). Archive reference: ${result.archive_reference_id||'recorded'}.`:`Jenny Ferr’s orders are restored. Archive reference: ${result.archive_reference_id||'retained'}.`,'success');await loadPreview();}catch(e){setMessage(`Archive action failed: ${e.message}`,'error');await loadPreview();}});
 search.addEventListener('input',findCustomer);select.addEventListener('change',()=>{selectedId=Number(select.value)||0;if(selectedId===25)loadPreview();else{ready=false;hide.disabled=true;restore.disabled=true;summary.hidden=true;}});hide.addEventListener('click',()=>openDialog('activate'));restore.addEventListener('click',()=>openDialog('restore'));findCustomer();loadArchiveList();
})();
</script>
</section>
<?php endif; ?>
<?php if (!$ready): ?><div role="alert" style="padding:12px;background:#fff7ed;color:#9a3412">Archive storage migrations are required: <code>migrations/20261008_create_order_archive_tables.sql</code> and <code>migrations/20261008_create_order_archive_batch_events.sql</code>. No schema changes are applied automatically.</div><?php else: ?><details style="background:#fff;padding:18px;margin:16px 0;border:1px solid #ddd;border-radius:8px"><summary style="font-weight:700;cursor:pointer">Other order archive tools — exact approved 224-order manifest</summary>
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
</details><?php endif; ?></main></div></body></html>
