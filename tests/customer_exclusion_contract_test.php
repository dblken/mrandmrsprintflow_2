<?php
declare(strict_types=1);

$GLOBALS['pf_test_customer_exclusion_ready'] = true;
function db_query($sql, $types = '', $params = [])
{
    return str_contains($sql, 'information_schema.tables')
        ? [['n' => (int)$GLOBALS['pf_test_customer_exclusion_ready']]]
        : [];
}
function db_execute($sql, $types = '', $params = [])
{
    $GLOBALS['pf_test_customer_exclusion_execute'] = [$sql, $types, $params];
    return true;
}
require_once __DIR__ . '/../includes/customer_exclusion.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };

$orderSql = printflow_customer_exclusion_order_scope_sql('o.order_id', 'o.customer_id');
$check(str_contains($orderSql, "ce.status = 'active'") && str_contains($orderSql, "ce.entity_type = 'ORDER'") && str_contains($orderSql, "ce.entity_type = 'CUSTOMER'"), 'order scope excludes active customer and exact-order entries');
$recordSql = printflow_customer_exclusion_record_scope_sql('order_messages', 'm.message_id', 'm.order_id');
$check(str_contains($recordSql, "ce_row.record_table = 'order_messages'") && str_contains($recordSql, 'm.message_id') && str_contains($recordSql, 'm.order_id'), 'related-record scope matches exact table, ID, and inherited order');
$notificationSql = printflow_customer_exclusion_notification_scope_sql('n');
$check(str_contains($notificationSql, 'n.user_type') && str_contains($notificationSql, 'n.data_id'), 'notification scope handles customer and order-targeted notifications');
$inventorySql = printflow_customer_exclusion_inventory_scope_sql('it');
$check(str_contains($inventorySql, "'JOB_ORDER'") && str_contains($inventorySql, "'CHANGE_ITEM'") && str_contains($inventorySql, "ce_order_owner.customer_id") && !str_contains($inventorySql, "'ORDER_PRODUCT'"), 'inventory scope follows verified customer/order/job/change-item references and never infers shared product ownership');
$check(str_contains($inventorySql, 'AND NOT EXISTS') && str_contains($inventorySql, 'AND NOT (NOT EXISTS'), 'inventory predicate retains ordinary rows and suppresses only rows with a matching exclusion');
$movementSql = printflow_customer_exclusion_inventory_scope_sql('im', 'inventory_movements');
$check(str_contains($movementSql, 'im.reference_type') && str_contains($movementSql, 'im.reference_id'), 'inventory movement scope uses its distinct live relationship column names');
$invalidAliasRejected = false;
try { printflow_customer_exclusion_order_scope_sql('o.order_id) OR 1=1 --'); } catch (InvalidArgumentException $e) { $invalidAliasRejected = true; }
$check($invalidAliasRejected, 'order SQL expression is allowlist validated');

$migration = (string)file_get_contents(__DIR__ . '/../migrations/20261012_create_customer_exclusion.sql');
foreach (['customer_id','order_id','record_table','record_id','status','reason','created_at','updated_at'] as $column) {
    $check(str_contains($migration, '`' . $column . '`'), 'migration contains ' . $column);
}
$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `customer_exclusion`'), 'migration is additive and idempotent');
$check(!preg_match('/\b(DELETE|UPDATE|DROP|TRUNCATE)\b/i', $migration), 'migration does not change existing records');

$check(printflow_customer_exclusion_set(25, 'CUSTOMER', 25, 7, 'Authorized customer-specific visibility exclusion'), 'customer exclusion upsert succeeds');
$bound = $GLOBALS['pf_test_customer_exclusion_execute'] ?? [];
$check(($bound[1] ?? '') === 'isisissi', 'upsert binds all eight values with the expected types');
$check(($bound[2] ?? []) === [25, 'CUSTOMER', null, '', 25, 'active', 'Authorized customer-specific visibility exclusion', 7], 'customer exclusion binds exact owner, entity, audit reason, and actor');
$orderRejected = false;
try { printflow_customer_exclusion_set(25, 'ORDER', 123, 7, 'test', 124); } catch (InvalidArgumentException $e) { $orderRejected = true; }
$check($orderRejected, 'mismatched order and record IDs are rejected');

if ($failures !== []) {
    fwrite(STDERR, "FAIL customer_exclusion_contract_test\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK customer_exclusion_contract_test\n";
