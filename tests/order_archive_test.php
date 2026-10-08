<?php
function db_query($sql, $types = '', $params = []) { return []; }
require_once __DIR__ . '/../includes/order_archive.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$manifest = printflow_order_archive_manifest();
$assert(count($manifest) === 224, 'manifest contains exactly 224 IDs');
$assert(count(array_unique($manifest)) === 224, 'manifest IDs are unique');
$assert($manifest[0] === 11332 && end($manifest) === 12136, 'manifest endpoints match approved IDs');
$assert(!in_array(11494, $manifest, true) && !in_array(11497, $manifest, true), 'gaps between manifest blocks are protected');
$assert(printflow_order_archive_exclusion_sql('o.order_id') === '1=1' || str_contains(printflow_order_archive_exclusion_sql('o.order_id'), 'printflow_order_archive_map'), 'scope is inactive before migration or maps archived rows after migration');
$threw = false;
try { printflow_order_archive_exclusion_sql('o.order_id) OR 1=1 --'); } catch (InvalidArgumentException $e) { $threw = true; }
$assert($threw, 'SQL expression aliases are validated');

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/20261008_create_order_archive_tables.sql') ?: '';
$admin = file_get_contents($root . '/admin/archived_orders.php') ?: '';
$helper = file_get_contents($root . '/includes/order_archive.php') ?: '';
$orders = file_get_contents($root . '/admin/orders_management.php') ?: '';
$customer = file_get_contents($root . '/customer/api_customer_orders.php') ?: '';
$repo = file_get_contents($root . '/includes/CustomizationRepository.php') ?: '';
$assert(str_contains($migration, 'UNIQUE KEY `uq_printflow_order_archive_order`'), 'archive mapping has unique order key');
$assert(!preg_match('/\b(DELETE|DROP|TRUNCATE|ALTER\s+TABLE\s+orders)\b/i', $migration), 'migration does not delete or alter orders');
$assert(str_contains($admin, "require_role('Admin')") && str_contains($admin, 'verify_csrf_token') && str_contains($admin, 'begin_transaction') && str_contains($admin, 'rollback()'), 'archive and restore route is Admin, CSRF, and transaction protected');
$assert(str_contains($admin, "'ARCHIVE PRINTFLOW 224 ORDERS'") && str_contains($admin, "'RESTORE SELECTED ORDERS'"), 'typed confirmations are enforced');
$assert(str_contains($admin, 'SELECT order_id FROM orders WHERE order_id IN') && str_contains($admin, 'ORDER BY order_id FOR UPDATE'), 'archive locks and revalidates the exact ID manifest');
$assert(str_contains($admin, 'begin_transaction()') && str_contains($admin, 'commit()') && str_contains($admin, 'rollback()'), 'archive and restore are transactional');
$assert(str_contains($admin, 'printflow_order_archive_events') && str_contains($admin, 'manifest_order_ids') && str_contains($admin, 'related_record_counts'), 'audit contains exact order IDs and related count snapshot');
$assert(str_contains($helper, 'pf_oam.order_id = {$orderIdExpression}') && !str_contains($helper, 'order_date'), 'visibility is mapping-based and does not hide future same-date orders');
$assert(!preg_match('/\b(DELETE\s+FROM\s+(orders|order_items|customers|provider_payments|inventory_transactions)|UPDATE\s+(orders|customers|provider_payments|inventory_transactions)\s+SET)\b/i', $admin), 'archive route does not mutate transactional or master rows');
$assert(str_contains($orders, 'printflow_order_archive_scope_sql') && str_contains($repo, 'printflow_order_archive_scope_sql'), 'active order and customization lists use centralized scope');
$assert(str_contains($customer, 'printflow_order_archive_scope_sql'), 'customer orders API uses centralized scope');

if ($failures !== []) {
    fwrite(STDERR, "FAIL order_archive_test\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK order_archive_test\n";
