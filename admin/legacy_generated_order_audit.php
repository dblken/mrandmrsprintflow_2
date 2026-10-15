<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/historical_admin_tools_guard.php';
printflow_block_historical_admin_tool();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

const LGO_ACTION = 'Historical transaction batch generated';
const LGO_CONFIRM = 'REPAIR LEGACY GENERATOR MARKERS';

function lgo_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function lgo_date(string $value, string $fallback): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    $errors = DateTimeImmutable::getLastErrors();
    return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) ? $date->format('Y-m-d') : $fallback;
}
function lgo_hgen(string $reference): bool {
    $reference = trim($reference);
    return preg_match('/^HGEN-[a-f0-9]{48}-\d+$/i', $reference) === 1
        || preg_match('/^HGEN-LEGACY-\d+-\d+$/i', $reference) === 1;
}
function lgo_ids(array $rows, string $key): string {
    $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row[$key] ?? 0), $rows)));
    return $ids ? implode(', ', $ids) : 'None';
}
function lgo_exact_activity(array $row, int $orderId): bool {
    if (trim((string)($row['action'] ?? '')) !== LGO_ACTION) return false;
    $details = trim((string)($row['details'] ?? ''));
    $json = json_decode($details, true);
    if (is_array($json['orders'] ?? null)) {
        foreach ($json['orders'] as $id) if ((int)$id === $orderId) return true;
    }
    return preg_match('/(?:^|[^0-9])Order\s*#\s*' . preg_quote((string)$orderId, '/') . '(?:[^0-9]|$)/i', $details) === 1;
}
function lgo_audit(array $order): array {
    $id = (int)$order['order_id'];
    $jobs = db_query('SELECT id, status, service_type FROM job_orders WHERE order_id = ? ORDER BY id', 'i', [$id]) ?: [];
    $custom = db_query('SELECT customization_id, service_type, status FROM customizations WHERE order_id = ? ORDER BY customization_id', 'i', [$id]) ?: [];
    $ledger = db_query("SELECT id, ref_type, ref_id, item_id, direction, quantity, uom, transaction_date FROM inventory_transactions WHERE (UPPER(ref_type) IN ('ORDER','ORDER_PRODUCT') AND ref_id = ?) OR (UPPER(ref_type)='JOB_ORDER' AND ref_id IN (SELECT id FROM job_orders WHERE order_id = ?)) ORDER BY id", 'ii', [$id, $id]) ?: [];
    $activity = db_query('SELECT log_id, action, details, created_at FROM activity_logs WHERE details LIKE ? OR details LIKE ? ORDER BY created_at, log_id', 'ss', ['%"orders":%' . $id . '%', '%Order #' . $id . '%']) ?: [];
    $generator = array_values(array_filter($activity, static fn(array $row): bool => lgo_exact_activity($row, $id)));
    if (lgo_hgen((string)($order['reference_id'] ?? ''))) $class = 'Confirmed Generator';
    elseif ($generator) $class = 'Possible Legacy Generator';
    elseif ($activity || $jobs || $custom) $class = 'Unknown';
    else $class = 'Normal/Not Generator';
    $evidence = $class === 'Possible Legacy Generator'
        ? 'Exact generator action references this order ID; no current HGEN marker is present.'
        : ($class === 'Unknown' ? 'Related records exist, but no generator-only marker or exact generator activity reference was found.' : 'Date, customer, payment, and source are not generator proof.');
    return [$class, $evidence, $jobs, $custom, $ledger, $activity, $generator];
}
function lgo_orders(string $start, string $end): array {
    return db_query(
        "SELECT o.order_id, o.reference_id, o.order_source, o.order_date, o.customer_id,
                o.payment_status, o.payment_method, o.total_amount,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''), c.email, 'Unknown customer') customer_name,
                c.email customer_email
         FROM orders o LEFT JOIN customers c ON c.customer_id=o.customer_id
         WHERE o.order_date>=? AND o.order_date<DATE_ADD(?,INTERVAL 1 DAY)
         ORDER BY o.order_date,o.order_id LIMIT 500",
        'ss', [$start . ' 00:00:00', $end . ' 00:00:00']
    ) ?: [];
}
function lgo_capacity(): int {
    $rows = db_query("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'reference_id' LIMIT 1") ?: [];
    $type = strtolower((string)($rows[0]['DATA_TYPE'] ?? $rows[0]['data_type'] ?? ''));
    $length = $rows[0]['CHARACTER_MAXIMUM_LENGTH'] ?? $rows[0]['character_maximum_length'] ?? null;
    if (in_array($type, ['varchar', 'char'], true) && is_numeric($length)) return (int)$length;
    if (in_array($type, ['tinytext', 'text', 'mediumtext', 'longtext'], true)) return $type === 'tinytext' ? 255 : ($type === 'text' ? 65535 : ($type === 'mediumtext' ? 16777215 : 4294967295));
    throw new RuntimeException('Unable to verify reference_id capacity. No marker repair was prepared.');
}
function lgo_proven(array $orders): array {
    $result = [];
    foreach ($orders as $order) {
        [$class, $evidence, $jobs, $custom, $ledger, $activity, $generator] = lgo_audit($order);
        if ($class === 'Possible Legacy Generator' && $generator) {
            $result[] = ['order'=>$order, 'activity_id'=>(int)$generator[0]['log_id'], 'evidence'=>$evidence, 'jobs'=>$jobs, 'custom'=>$custom, 'ledger'=>$ledger, 'activity'=>$activity, 'generator'=>$generator];
        }
    }
    return $result;
}
function lgo_markers(array $rows): array {
    $capacity = lgo_capacity(); $seen = [];
    foreach ($rows as &$row) {
        $id = (int)$row['order']['order_id'];
        $marker = 'HGEN-LEGACY-' . $row['activity_id'] . '-' . $id;
        if (strlen($marker) > $capacity) throw new RuntimeException('A proposed marker exceeds reference_id capacity. No repair was prepared.');
        $collision = db_query('SELECT order_id FROM orders WHERE reference_id=? LIMIT 1', 's', [$marker]) ?: [];
        if ($collision && (int)$collision[0]['order_id'] !== $id) throw new RuntimeException('A proposed marker collides with another order. No repair was prepared.');
        if (isset($seen[$marker])) throw new RuntimeException('Proposed markers collide. No repair was prepared.');
        $seen[$marker] = true; $row['marker'] = $marker;
    }
    unset($row);
    return [$rows, $capacity];
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$start = lgo_date((string)($_REQUEST['start_date'] ?? ''), $today);
$end = lgo_date((string)($_REQUEST['end_date'] ?? ''), $today);
$error = ''; $success = ''; $orders = []; $repair = $_SESSION['lgo_repair'] ?? null;
$requested = isset($_GET['audit']) || $_SERVER['REQUEST_METHOD'] === 'POST';
try {
    if ($start > $end) throw new RuntimeException('Start Date cannot be after End Date.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Invalid CSRF token.');
        if (($_POST['action'] ?? '') === 'prepare') {
            $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['legacy_order_ids'] ?? [])))));
            if (!$selected) throw new RuntimeException('Select at least one proven legacy generator order.');
            $proven = lgo_proven(lgo_orders($start, $end));
            $rows = array_values(array_filter($proven, static fn(array $row): bool => in_array((int)$row['order']['order_id'], $selected, true)));
            if (count($rows) !== count($selected)) throw new RuntimeException('A selected order is no longer proven by exact generator activity. Refresh the audit.');
            [$rows, $capacity] = lgo_markers($rows);
            $repair = ['token'=>bin2hex(random_bytes(24)), 'expires'=>time()+900, 'start'=>$start, 'end'=>$end, 'capacity'=>$capacity, 'rows'=>array_map(static fn(array $row): array => ['order_id'=>(int)$row['order']['order_id'], 'order_date'=>(string)$row['order']['order_date'], 'reference_id'=>(string)($row['order']['reference_id'] ?? ''), 'total'=>(float)$row['order']['total_amount'], 'activity_id'=>$row['activity_id'], 'marker'=>$row['marker']], $rows)];
            $_SESSION['lgo_repair'] = $repair; $success = 'Recovery preview prepared. Nothing has been changed.';
        } elseif (($_POST['action'] ?? '') === 'repair') {
            $posted = (string)($_POST['repair_token'] ?? '');
            if (!is_array($repair) || !hash_equals((string)($repair['token'] ?? ''), $posted) || (int)($repair['expires'] ?? 0) < time()) throw new RuntimeException('The repair preview is missing or expired. Prepare a new preview.');
            if ((string)($_POST['confirmation'] ?? '') !== LGO_CONFIRM) throw new RuntimeException('Type the exact confirmation text before repairing markers.');
            global $conn; $capacity = lgo_capacity();
            if (!$conn->begin_transaction()) throw new RuntimeException('Could not begin the repair transaction.');
            try {
                $changed = [];
                foreach ($repair['rows'] as $row) {
                    $id = (int)$row['order_id'];
                    $current = db_query('SELECT order_id, reference_id FROM orders WHERE order_id=? FOR UPDATE', 'i', [$id]) ?: [];
                    if (count($current)!==1) throw new RuntimeException('Order #'.$id.' is no longer eligible. Nothing was repaired.');
                    $currentReference = trim((string)$current[0]['reference_id']);
                    $marker = (string)$row['marker'];
                    if (hash_equals($marker, $currentReference)) {
                        $changed[] = ['order_id'=>$id, 'reference_id'=>$marker, 'activity_id'=>(int)$row['activity_id'], 'already_present'=>true];
                        continue;
                    }
                    if (lgo_hgen($currentReference)) throw new RuntimeException('Order #'.$id.' already has a different protected HGEN marker. Nothing was repaired.');
                    $fresh = array_values(array_filter(lgo_orders((string)$repair['start'], (string)$repair['end']), static fn(array $item): bool => (int)$item['order_id']===$id));
                    $proof = lgo_proven($fresh);
                    if (count($proof)!==1 || (int)$proof[0]['activity_id'] !== (int)$row['activity_id']) throw new RuntimeException('Generator evidence changed for order #'.$id.'. Nothing was repaired.');
                    if (strlen($marker)>$capacity) throw new RuntimeException('Marker capacity validation failed.');
                    if (db_query('SELECT order_id FROM orders WHERE reference_id=? AND order_id<>? LIMIT 1', 'si', [$marker,$id])) throw new RuntimeException('Marker collision detected. Nothing was repaired.');
                    if (db_execute_affected_rows("UPDATE orders SET reference_id=? WHERE order_id=? AND (reference_id IS NULL OR reference_id NOT LIKE 'HGEN-%')", 'si', [$marker,$id]) !== 1) throw new RuntimeException('Order #'.$id.' was not updated. Nothing was repaired.');
                    $changed[] = ['order_id'=>$id, 'reference_id'=>$marker, 'activity_id'=>(int)$row['activity_id']];
                }
                $conn->commit(); unset($_SESSION['lgo_repair']); $repair=null; $success=count($changed).' proven legacy marker(s) repaired. No orders were deleted.';
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        }
    }
    if ($requested) $orders = lgo_orders($start, $end);
} catch (Throwable $e) { $error = $e->getMessage(); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Legacy Generated Order Audit - Admin</title>
<style>body{font-family:Arial,sans-serif;background:#f4f7f9;color:#173047;margin:0}.wrap{max-width:1400px;margin:28px auto;padding:20px}.panel{background:#fff;border:1px solid #d9e2e8;border-radius:10px;padding:22px;margin:16px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}label{font-weight:700;font-size:13px;display:flex;flex-direction:column;gap:6px}input{font:inherit;padding:10px;border:1px solid #b8c9d4;border-radius:6px}button{padding:11px 16px;border:0;border-radius:6px;background:#0b6174;color:#fff;font-weight:700;margin-top:16px;cursor:pointer}button:disabled{background:#9aaab2;cursor:not-allowed}.notice{background:#eaf4ff;padding:14px;border-radius:7px}.success{background:#e7f7e9;color:#176b35;padding:14px;border-radius:7px}.error{background:#ffe6e6;color:#a21919;padding:14px;border-radius:7px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px;min-width:1200px}th,td{padding:10px;border-bottom:1px solid #e2e9ee;text-align:left;vertical-align:top}th{background:#f3f7f9}.confirmed{color:#176b35;font-weight:700}.possible{color:#9a5b00;font-weight:700}.unknown{color:#7c3aed;font-weight:700}.muted{color:#657784}.ids{max-width:230px}.repair{background:#f0fbf5;border:1px solid #a8d8b7;padding:14px;border-radius:7px}@media(max-width:800px){.grid{grid-template-columns:1fr}}</style></head>
<body><main class="wrap">
<h1>Legacy Generated Order Recovery Audit</h1>
<p>Admin-only diagnostic and one-time marker repair for historical orders created before the durable HGEN marker.</p>
<div class="notice"><strong>Read-only audit:</strong> date, customer, payment, and source values do not prove ownership. Marker repair is separate and never deletes orders or restores inventory.</div>
<?php if ($error): ?><div class="error"><?=lgo_h($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="success"><?=lgo_h($success)?></div><?php endif; ?>
<section class="panel"><form method="get"><div class="grid">
<label>Start Date<input type="date" name="start_date" value="<?=lgo_h($start)?>" max="<?=lgo_h($today)?>" required></label>
<label>End Date<input type="date" name="end_date" value="<?=lgo_h($end)?>" max="<?=lgo_h($today)?>" required></label>
<label>Audit action<input type="hidden" name="audit" value="1"><span class="muted">Inspect up to 500 orders.</span></label>
</div><button type="submit">Run Read-Only Audit</button></form></section>
<?php if ($requested && !$error):
    $rows = []; $possible = 0; $unknown = 0; $provenSales = 0.0;
    foreach ($orders as $order) $rows[] = [$order, ...lgo_audit($order)];
    foreach ($rows as $row) { if ($row[1] === 'Possible Legacy Generator') { $possible++; $provenSales += (float)$row[0]['total_amount']; } if ($row[1] === 'Unknown') $unknown++; }
?>
<section class="panel"><h2>Audit Results</h2>
<p><strong><?=count($orders)?></strong> order(s) inspected. <strong>Proven legacy:</strong> <?=$possible?> &nbsp; <strong>Unknown excluded:</strong> <?=$unknown?> &nbsp; <strong>Proven sales:</strong> PHP <?=number_format($provenSales, 2)?></p>
<?php if (!$orders): ?><p class="muted">No orders found in this date range.</p><?php else: ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="start_date" value="<?=lgo_h($start)?>"><input type="hidden" name="end_date" value="<?=lgo_h($end)?>">
<button type="button" id="select-all-proven">Select All Proven</button>
<div class="table-wrap"><table><thead><tr><th>Repair</th><th>Classification / evidence</th><th>Order / total</th><th>Date</th><th>Customer</th><th>Source / payment</th><th>Jobs</th><th>Customizations</th><th>Ledger</th><th>Activity</th></tr></thead><tbody>
<?php foreach ($rows as [$order,$class,$evidence,$jobs,$custom,$ledger,$activity,$generator]): $style = $class === 'Confirmed Generator' ? 'confirmed' : ($class === 'Possible Legacy Generator' ? 'possible' : ($class === 'Unknown' ? 'unknown' : 'muted')); ?>
<tr><td><?php if ($class === 'Possible Legacy Generator'): ?><label><input class="proven-order" type="checkbox" name="legacy_order_ids[]" value="<?=lgo_h((string)$order['order_id'])?>"> proven</label><?php else: ?><span class="muted">Excluded</span><?php endif; ?></td>
<td><strong class="<?=$style?>"><?=lgo_h($class)?></strong><br><span class="muted"><?=lgo_h($evidence)?></span></td>
<td>#<?=lgo_h((string)$order['order_id'])?><br>Ref: <?=lgo_h((string)($order['reference_id'] ?? ''))?><br>PHP <?=number_format((float)$order['total_amount'], 2)?></td>
<td><?=lgo_h((string)$order['order_date'])?></td><td><?=lgo_h((string)($order['customer_name'] ?? ''))?><br><?=lgo_h((string)($order['customer_email'] ?? ''))?><br>ID <?=lgo_h((string)$order['customer_id'])?></td>
<td><?=lgo_h((string)($order['order_source'] ?? ''))?><br><?=lgo_h((string)($order['payment_status'] ?? ''))?> / <?=lgo_h((string)($order['payment_method'] ?? ''))?></td>
<td class="ids"><?=lgo_h(lgo_ids($jobs, 'id'))?></td><td class="ids"><?=lgo_h(lgo_ids($custom, 'customization_id'))?></td>
<td class="ids"><?=count($ledger)?> movement(s)<br><?=lgo_h(implode(', ', array_map(static fn(array $r): string => '#' . $r['id'] . ' ' . $r['direction'] . ' item ' . $r['item_id'], $ledger)))?></td>
<td class="ids"><?=count($activity)?> log(s)<br><?=lgo_h(implode('; ', array_map(static fn(array $r): string => '#' . $r['log_id'] . ' ' . $r['action'], $activity)))?><br>Exact generator: <?=lgo_h(lgo_ids($generator, 'log_id'))?></td></tr>
<?php endforeach; ?></tbody></table></div><button name="action" value="prepare" type="submit">Prepare Marker Repair Preview</button></form><?php endif; ?></section><?php endif; ?>
<?php if (is_array($repair) && !empty($repair['rows'])): ?><section class="panel repair"><h2>Marker Repair Preview</h2><p><strong>Nothing has been changed.</strong> Only exact generator activity evidence is listed. Verified reference_id capacity: <?=lgo_h((string)$repair['capacity'])?> characters.</p>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Current reference</th><th>Activity log</th><th>Proposed reference</th><th>Total</th></tr></thead><tbody>
<?php foreach ($repair['rows'] as $row): ?><tr><td>#<?=lgo_h((string)$row['order_id'])?></td><td><?=lgo_h((string)$row['order_date'])?></td><td><?=lgo_h((string)$row['reference_id'])?></td><td>#<?=lgo_h((string)$row['activity_id'])?></td><td><?=lgo_h((string)$row['marker'])?></td><td>PHP <?=number_format((float)$row['total'], 2)?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post"><fieldset><legend>Explicit confirmation</legend><?=csrf_field()?><input type="hidden" name="action" value="repair"><input type="hidden" name="repair_token" value="<?=lgo_h((string)$repair['token'])?>"><label>Type <?=lgo_h(LGO_CONFIRM)?><input id="repair-confirmation" name="confirmation" autocomplete="off" required></label><button id="repair-button" type="submit" disabled>Repair Proven Legacy Markers</button></fieldset></form></section><script>document.getElementById('repair-confirmation').addEventListener('input',function(){document.getElementById('repair-button').disabled=this.value!==<?=json_encode(LGO_CONFIRM)?>;});</script><?php endif; ?>
</main><script>
const selectAllProven = document.getElementById('select-all-proven');
if (selectAllProven) {
    selectAllProven.addEventListener('click', function () {
        document.querySelectorAll('input.proven-order').forEach(function (checkbox) {
            checkbox.checked = true;
        });
    });
}
</script></body></html>
