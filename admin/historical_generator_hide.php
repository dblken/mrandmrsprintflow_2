<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

function hgh(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function hgh_date(string $v, string $fallback): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));
    $e = DateTimeImmutable::getLastErrors();
    return $d && ($e === false || (!$e['warning_count'] && !$e['error_count'])) ? $d->format('Y-m-d') : $fallback;
}
function hgh_ready(): bool {
    $r = db_query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='historical_generator_hidden_orders'");
    return (int)($r[0]['n'] ?? 0) === 1;
}
function hgh_orders(string $start, string $end): array {
    return db_query(
        "SELECT o.order_id,o.order_date,o.reference_id,o.order_source,o.total_amount
         FROM orders o LEFT JOIN historical_generator_hidden_orders h ON h.order_id=o.order_id
         WHERE o.reference_id LIKE 'HGEN-%' AND h.order_id IS NULL
           AND o.order_date>=? AND o.order_date<DATE_ADD(?,INTERVAL 1 DAY)
         ORDER BY o.order_date,o.order_id", 'ss', [$start.' 00:00:00',$end.' 00:00:00']
    ) ?: [];
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$start = hgh_date((string)($_REQUEST['start_date'] ?? ''), $today);
$end = hgh_date((string)($_REQUEST['end_date'] ?? ''), $today);
$error = ''; $success = ''; $preview = $_SESSION['hgh_preview'] ?? null; $rows = [];
try {
    if (!hgh_ready()) throw new RuntimeException('Apply database/historical_generator_hidden_orders.sql before using reversible hiding.');
    if ($start > $end) throw new RuntimeException('Start Date cannot be after End Date.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Invalid security token.');
        if (($_POST['action'] ?? '') === 'preview') {
            $rows = hgh_orders($start, $end);
            $preview = ['start'=>$start, 'end'=>$end, 'orders'=>$rows, 'token'=>bin2hex(random_bytes(24))];
            $_SESSION['hgh_preview'] = $preview;
            $success = 'Hide preview generated. Nothing has been changed.';
        } elseif (($_POST['action'] ?? '') === 'hide') {
            if (!is_array($preview) || !hash_equals((string)($preview['token'] ?? ''), (string)($_POST['preview_token'] ?? ''))) throw new RuntimeException('Hide preview expired. Generate a new preview.');
            if ((string)($_POST['confirmation'] ?? '') !== 'HIDE HISTORICAL DATA') throw new RuntimeException('Type HIDE HISTORICAL DATA to confirm.');
            $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['order_ids'] ?? [])))));
            if (!$selected) throw new RuntimeException('Select at least one generated order to hide.');
            $eligible = hgh_orders((string)$preview['start'], (string)$preview['end']);
            $eligibleIds = array_map(static fn(array $row): int => (int)$row['order_id'], $eligible);
            if (count(array_diff($selected, $eligibleIds)) > 0) throw new RuntimeException('A selected order is no longer eligible. Generate a new preview.');
            global $conn;
            if (!$conn->begin_transaction()) throw new RuntimeException('Could not begin hide transaction.');
            try {
                $hidden = [];
                foreach ($eligible as $row) {
                    $id = (int)$row['order_id'];
                    if (!in_array($id, $selected, true)) continue;
                    if (db_execute('INSERT INTO historical_generator_hidden_orders (order_id,hidden_by,reason) VALUES (?,?,?) ON DUPLICATE KEY UPDATE order_id=order_id', 'iis', [$id,(int)get_user_id(),'Historical generator data hidden by Admin'])) $hidden[] = $id;
                }
                $conn->commit(); unset($_SESSION['hgh_preview']); $preview = null;
                $success = 'Hidden '.count($hidden).' generator-created transaction(s). Data was retained; inventory was not changed.';
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        }
    }
    if (!$rows && is_array($preview)) $rows = (array)($preview['orders'] ?? []);
} catch (Throwable $e) { $error = $e->getMessage(); }
$total = array_sum(array_map(static fn(array $row): float => (float)$row['total_amount'], $rows));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hide Historical Generated Data</title>
<style>body{font-family:Arial;background:#f4f7f9;color:#173047;margin:0}.wrap{max-width:1000px;margin:28px auto;padding:20px}.panel{background:#fff;border:1px solid #d9e2e8;border-radius:10px;padding:22px;margin:16px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{font-weight:700;display:flex;flex-direction:column;gap:6px}input{font:inherit;padding:10px;border:1px solid #b8c9d4;border-radius:6px}button{padding:11px 16px;border:0;border-radius:6px;background:#0b6174;color:#fff;font-weight:700;margin-top:16px}.notice{background:#eaf4ff;padding:14px}.success{background:#e5f8eb;color:#176b35;padding:14px}.error{background:#ffe6e6;color:#a21919;padding:14px}table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #e2e9ee;text-align:left}th{background:#f3f7f9}@media(max-width:600px){.grid{grid-template-columns:1fr}}</style></head>
<body><main class="wrap"><h1>Hide Historical Generated Data</h1><p>Reversible hiding for HGEN-marked historical transactions.</p>
<div class="notice"><strong>Only HGEN-marked generator orders are eligible.</strong> Hidden orders remain stored, retain inventory effects, and are excluded from shared order/report scopes after the hide table is installed.</div>
<?php if ($error): ?><div class="error"><?=hgh($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="success"><?=hgh($success)?></div><?php endif; ?>
<section class="panel"><form method="post"><?=csrf_field()?><div class="grid"><label>Start Date<input type="date" name="start_date" value="<?=hgh($start)?>" max="<?=hgh($today)?>" required></label><label>End Date<input type="date" name="end_date" value="<?=hgh($end)?>" max="<?=hgh($today)?>" required></label></div><button name="action" value="preview">Preview Hide</button></form></section>
<?php if (is_array($preview)): ?><section class="panel"><h2>Preview (nothing changed)</h2><p><strong><?=count($rows)?></strong> generator-created order(s), total PHP <?=number_format($total,2)?></p>
<?php if ($rows): ?><form method="post" id="hide-form"><?=csrf_field()?><input type="hidden" name="action" value="hide"><input type="hidden" name="preview_token" value="<?=hgh((string)$preview['token'])?>"><p><label>Target amount to hide (optional)<input id="target-amount" type="number" min="0.01" step="0.01" placeholder="e.g. 300000"></label></p><button type="button" id="select-to-target">Select Orders Toward Target</button><p><strong>Selected total: PHP <span id="selected-total">0.00</span></strong></p>
<table><tr><th>Select</th><th>Order</th><th>Date</th><th>Reference</th><th>Source</th><th>Total</th></tr><?php foreach ($rows as $row): ?><tr><td><input class="hide-order" type="checkbox" name="order_ids[]" value="<?=hgh((string)$row['order_id'])?>" data-total="<?=hgh((string)$row['total_amount'])?>"></td><td>#<?=hgh((string)$row['order_id'])?></td><td><?=hgh((string)$row['order_date'])?></td><td><?=hgh((string)$row['reference_id'])?></td><td><?=hgh((string)$row['order_source'])?></td><td>PHP <?=number_format((float)$row['total_amount'],2)?></td></tr><?php endforeach; ?></table>
<label>Type HIDE HISTORICAL DATA<input name="confirmation" required></label><button type="submit">Hide Selected Transactions</button></form><?php else: ?><p class="error">No HGEN-marked transactions were found in this date range. Nothing can be hidden safely by date alone.</p><?php endif; ?></section><?php endif; ?>
</main><script>
const hideChecks = Array.from(document.querySelectorAll('.hide-order'));
const selectedTotal = document.getElementById('selected-total');
function refreshSelectedTotal() {
    const total = hideChecks.filter(function (check) { return check.checked; }).reduce(function (sum, check) { return sum + Number(check.dataset.total || 0); }, 0);
    if (selectedTotal) selectedTotal.textContent = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
hideChecks.forEach(function (check) { check.addEventListener('change', refreshSelectedTotal); });
const selectToTarget = document.getElementById('select-to-target');
if (selectToTarget) selectToTarget.addEventListener('click', function () {
    const target = Number((document.getElementById('target-amount') || {}).value || 0);
    if (!(target > 0)) { alert('Enter the amount you want to hide first.'); return; }
    let total = 0;
    hideChecks.forEach(function (check) {
        check.checked = false;
        if (total < target) { check.checked = true; total += Number(check.dataset.total || 0); }
    });
    refreshSelectedTotal();
});
refreshSelectedTotal();
</script></body></html>
