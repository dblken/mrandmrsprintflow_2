<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');

function lgo_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function lgo_date(string $value, string $fallback): string {
    $value = trim($value);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return $fallback;
    return $parsed->format('Y-m-d');
}
function lgo_json_ids(array $rows, string $key): string {
    $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row[$key] ?? 0), $rows)));
    return $ids ? implode(', ', $ids) : 'None';
}
function lgo_order_audit(array $order): array {
    $orderId = (int)$order['order_id'];
    $jobs = db_query('SELECT id, status, service_type FROM job_orders WHERE order_id = ? ORDER BY id', 'i', [$orderId]) ?: [];
    $customizations = db_query('SELECT customization_id, service_type, status FROM customizations WHERE order_id = ? ORDER BY customization_id', 'i', [$orderId]) ?: [];
    $ledger = db_query(
        "SELECT id, ref_type, ref_id, item_id, direction, quantity, uom, transaction_date
         FROM inventory_transactions
         WHERE (UPPER(ref_type) IN ('ORDER', 'ORDER_PRODUCT') AND ref_id = ?)
            OR (UPPER(ref_type) = 'JOB_ORDER' AND ref_id IN (SELECT id FROM job_orders WHERE order_id = ?))
         ORDER BY id",
        'ii', [$orderId, $orderId]
    ) ?: [];
    $activity = db_query(
        "SELECT log_id, action, details, created_at
         FROM activity_logs
         WHERE details LIKE ? OR details LIKE ?
         ORDER BY created_at ASC, log_id ASC",
        'ss', ['%"orders":%' . $orderId . '%', '%Order #' . $orderId . '%']
    ) ?: [];
    $hasHgen = preg_match('/^HGEN-[a-f0-9]{48}-\d+$/i', trim((string)($order['reference_id'] ?? ''))) === 1;
    $generatorActivity = array_values(array_filter($activity, static function (array $row): bool {
        return stripos((string)($row['action'] ?? ''), 'historical transaction batch generated') !== false;
    }));
    if ($hasHgen) {
        $classification = 'Confirmed Generator';
        $evidence = 'Immutable reference_id matches HGEN-{preview-token}-{row-index}.';
    } elseif ($generatorActivity !== []) {
        $classification = 'Possible Legacy Generator';
        $evidence = 'Historical generator batch activity references this order, but no current HGEN marker is present.';
    } elseif ($activity !== [] || $jobs !== [] || $customizations !== []) {
        $classification = 'Unknown';
        $evidence = 'Linked records exist, but no generator-only marker or generator activity reference was found.';
    } else {
        $classification = 'Normal/Not Generator';
        $evidence = 'No generator-only marker, generator activity reference, job order, or customization evidence found.';
    }
    return [$classification, $evidence, $jobs, $customizations, $ledger, $activity];
}

$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$start = lgo_date((string)($_GET['start_date'] ?? ''), $today);
$end = lgo_date((string)($_GET['end_date'] ?? ''), $today);
$error = '';
$orders = [];
if ($start > $end) {
    $error = 'Start Date cannot be after End Date.';
} elseif (isset($_GET['audit'])) {
    $orders = db_query(
        "SELECT o.order_id, o.reference_id, o.order_source, o.order_date, o.customer_id,
                o.payment_status, o.payment_method, o.total_amount,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''), c.email, 'Unknown customer') AS customer_name,
                c.email AS customer_email
         FROM orders o
         LEFT JOIN customers c ON c.customer_id = o.customer_id
         WHERE o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY o.order_date ASC, o.order_id ASC
         LIMIT 500",
        'ss', [$start . ' 00:00:00', $end . ' 00:00:00']
    ) ?: [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Legacy Generated Order Audit - Admin</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7f9;color:#173047;margin:0}.wrap{max-width:1400px;margin:28px auto;padding:20px}.panel{background:#fff;border:1px solid #d9e2e8;border-radius:10px;padding:22px;margin:16px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}label{font-weight:700;font-size:13px;display:flex;flex-direction:column;gap:6px}input{font:inherit;padding:10px;border:1px solid #b8c9d4;border-radius:6px}button{padding:11px 16px;border:0;border-radius:6px;background:#0b6174;color:#fff;font-weight:700;margin-top:16px}.notice{background:#eaf4ff;padding:14px;border-radius:7px}.error{background:#ffe6e6;color:#a21919;padding:14px;border-radius:7px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px;min-width:1100px}th,td{padding:10px;border-bottom:1px solid #e2e9ee;text-align:left;vertical-align:top}th{background:#f3f7f9}.confirmed{color:#176b35;font-weight:700}.possible{color:#9a5b00;font-weight:700}.normal{color:#52616b;font-weight:700}.unknown{color:#7c3aed;font-weight:700}.muted{color:#657784}.ids{white-space:normal;max-width:230px}.safe-box{background:#fff7d6;padding:12px;border-radius:7px;margin-top:12px}@media(max-width:800px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="wrap">
    <h1>Legacy Generated Order Recovery Audit</h1>
    <p>Read-only Admin diagnostic for historical orders created before the durable HGEN marker.</p>
    <div class="notice"><strong>No database changes are performed.</strong> Date, customer, payment, and source values are evidence only; they do not prove generator ownership.</div>
    <?php if ($error !== ''): ?><div class="error"><?=lgo_h($error)?></div><?php endif; ?>
    <section class="panel">
        <form method="get">
            <div class="grid">
                <label>Start Date<input type="date" name="start_date" value="<?=lgo_h($start)?>" max="<?=lgo_h($today)?>" required></label>
                <label>End Date<input type="date" name="end_date" value="<?=lgo_h($end)?>" max="<?=lgo_h($today)?>" required></label>
                <label>Audit action<input type="hidden" name="audit" value="1"><span class="muted">Inspect up to 500 orders in this historical range.</span></label>
            </div>
            <button type="submit">Run Read-Only Audit</button>
        </form>
    </section>
    <?php if (isset($_GET['audit']) && $error === ''): ?>
        <section class="panel">
            <h2>Audit Results</h2>
            <p><strong><?=count($orders)?></strong> order(s) inspected from <?=lgo_h($start)?> through <?=lgo_h($end)?>.</p>
            <?php if (count($orders) >= 500): ?><div class="safe-box">The result is limited to 500 rows. Narrow the date range before making any recovery decision.</div><?php endif; ?>
            <?php if (!$orders): ?>
                <p class="muted">No orders were found in this date range.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Classification / evidence</th><th>Order</th><th>Business date</th><th>Customer</th><th>Source / payment</th><th>Linked jobs</th><th>Customizations</th><th>Inventory ledger</th><th>Activity references</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order): [$classification,$evidence,$jobs,$customizations,$ledger,$activity] = lgo_order_audit($order); $class = $classification === 'Confirmed Generator' ? 'confirmed' : ($classification === 'Possible Legacy Generator' ? 'possible' : ($classification === 'Unknown' ? 'unknown' : 'normal')); ?>
                        <tr>
                            <td><div class="<?=$class?>"><?=lgo_h($classification)?></div><div class="muted"><?=lgo_h($evidence)?></div></td>
                            <td><strong>#<?=lgo_h((string)$order['order_id'])?></strong><br>Reference: <?=lgo_h((string)($order['reference_id'] ?? ''))?><br>Total: PHP <?=number_format((float)$order['total_amount'],2)?></td>
                            <td><?=lgo_h((string)$order['order_date'])?></td>
                            <td><?=lgo_h((string)($order['customer_name'] ?? 'Unknown'))?><br><?=lgo_h((string)($order['customer_email'] ?? ''))?><br>Customer ID: <?=lgo_h((string)$order['customer_id'])?></td>
                            <td><?=lgo_h((string)($order['order_source'] ?? ''))?><br><?=lgo_h((string)($order['payment_status'] ?? ''))?> / <?=lgo_h((string)($order['payment_method'] ?? ''))?></td>
                            <td class="ids"><?=lgo_h(lgo_json_ids($jobs, 'id'))?><br><?=lgo_h(implode('; ', array_map(static fn(array $j): string => (string)($j['service_type'] ?? '') . ' [' . (string)($j['status'] ?? '') . ']', $jobs)))?></td>
                            <td class="ids"><?=lgo_h(lgo_json_ids($customizations, 'customization_id'))?></td>
                            <td class="ids"><?=count($ledger)?> movement(s)<br><?=lgo_h(implode(', ', array_map(static fn(array $row): string => '#' . (string)$row['id'] . ' ' . (string)$row['direction'] . ' item ' . (string)$row['item_id'], $ledger)))?></td>
                            <td class="ids"><?=count($activity)?> log(s)<br><?=lgo_h(implode('; ', array_map(static fn(array $row): string => '#' . (string)$row['log_id'] . ' ' . (string)$row['action'], $activity)))?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
