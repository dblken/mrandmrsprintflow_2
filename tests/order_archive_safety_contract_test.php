<?php
declare(strict_types=1);

$showTables = true;
function db_query($sql, $types = '', $params = [])
{
    global $showTables;
    if (str_contains((string)$sql, 'information_schema.tables')) {
        return $showTables ? [['n' => 1]] : [['n' => 0]];
    }
    return [];
}

require_once __DIR__ . '/../includes/order_archive.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$root = dirname(__DIR__);
$admin = (string)file_get_contents($root . '/admin/archived_orders.php');
$helper = (string)file_get_contents($root . '/includes/order_archive.php');
$migration = (string)file_get_contents($root . '/migrations/20261008_create_order_archive_tables.sql');
$batchMigration = (string)file_get_contents($root . '/migrations/20261008_create_order_archive_batch_events.sql');
$adminOrders = (string)file_get_contents($root . '/admin/orders_management.php');
$adminJobOrders = (string)file_get_contents($root . '/admin/job_orders.php');
$adminDashboard = (string)file_get_contents($root . '/admin/dashboard.php');
$managerDashboard = (string)file_get_contents($root . '/manager/dashboard.php');
$staffDashboard = (string)file_get_contents($root . '/staff/dashboard.php');
$staffOrders = (string)file_get_contents($root . '/staff/orders.php');
$staffJobOrders = (string)file_get_contents($root . '/staff/job_orders_management.php');
$customerOrders = (string)file_get_contents($root . '/customer/orders.php');
$customerOrdersAll = (string)file_get_contents($root . '/customer/orders_all.php');
$customerOrdersApi = (string)file_get_contents($root . '/customer/api_customer_orders.php');
$adminReports = (string)file_get_contents($root . '/admin/reports.php');
$adminPrintReports = (string)file_get_contents($root . '/admin/reports_print.php');
$staffReports = (string)file_get_contents($root . '/staff/reports.php');
$staffReviews = (string)file_get_contents($root . '/staff/reviews.php');
$notifications = (string)file_get_contents($root . '/public/api/notifications/list.php');

$ids = printflow_order_archive_manifest();
$assert(count($ids) === 224 && count(array_unique($ids)) === 224, 'archive mapping manifest is exactly 224 unique IDs');
$assert(printflow_order_archive_prepare($ids)[0] === implode(',', array_fill(0, 224, '?')), 'mapping statement has one placeholder per manifest ID');

$orderScope = printflow_order_archive_scope_sql('o');
$assert(str_contains($orderScope, 'NOT EXISTS') && str_contains($orderScope, 'pf_oam.order_id = o.order_id'), 'active order scope excludes only mapped archived IDs');
$assert(!preg_match('/order_date|created_at|2026-09-07|2026-10-07/i', $orderScope), 'same-date orders remain active unless explicitly mapped');
$notificationScope = printflow_order_archive_notification_exclusion_sql('n');
$assert(str_contains($notificationScope, "COALESCE(n.type, '') <> 'Order'") && str_contains($notificationScope, "COALESCE(n.data_id, '0')"), 'customer-only and non-order notifications remain visible');

$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `printflow_order_archive_map`') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `printflow_order_archive_events`'), 'migration creates both isolated tables idempotently');
$assert(str_contains($migration, '`order_id` BIGINT UNSIGNED NOT NULL') && str_contains($migration, 'UNIQUE KEY `uq_printflow_order_archive_order` (`order_id`)'), 'mapping has exact order key and duplicate protection');
$assert(str_contains($migration, '`restored_at`') && str_contains($migration, '`restored_by`') && str_contains($migration, '`status`'), 'mapping supports restore history');
$assert(!preg_match('/\b(DELETE|UPDATE|DROP|TRUNCATE|ALTER)\b/i', $migration), 'migration contains no destructive or data-changing SQL');
$assert(str_contains($batchMigration, 'CREATE TABLE IF NOT EXISTS `printflow_order_archive_batch_events`'), 'batch migration creates isolated metadata only');
$assert(str_contains($batchMigration, '`manifest_order_ids` LONGTEXT NOT NULL') && str_contains($batchMigration, '`protected_record_notes` TEXT NOT NULL') && str_contains($batchMigration, '`operation_result` VARCHAR(32) NOT NULL'), 'batch migration stores manifest, protected notes, and result');
$assert(!preg_match('/\b(DELETE|UPDATE|DROP|TRUNCATE|ALTER)\b/i', $batchMigration), 'batch migration has no destructive or data-changing SQL');

$requireAdmin = strpos($admin, "require_role('Admin')");
$postBranch = strpos($admin, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
$csrfCheck = strpos($admin, 'verify_csrf_token');
$assert($requireAdmin !== false && $postBranch !== false && $csrfCheck !== false && $requireAdmin < $postBranch && $postBranch < $csrfCheck, 'Admin authorization and CSRF validation guard all writes');
$assert(substr_count($admin, 'begin_transaction()') >= 2 && substr_count($admin, 'commit()') >= 2 && substr_count($admin, 'rollback()') >= 2, 'archive and restore both use transaction, commit, and rollback');
$assert(str_contains($admin, "'ARCHIVE PRINTFLOW 224 ORDERS'") && str_contains($admin, "'RESTORE SELECTED ORDERS'"), 'archive and restore require typed confirmation');
$assert(str_contains($admin, 'SELECT order_id FROM orders WHERE order_id IN') && str_contains($admin, 'ORDER BY order_id FOR UPDATE') && str_contains($admin, '$liveIds !== $ids'), 'archive locks and verifies the exact order manifest');
$assert(str_contains($admin, 'INSERT INTO printflow_order_archive_map') && str_contains($admin, 'foreach ($ids as $orderId)'), 'archive inserts mappings only for exact manifest IDs');
$assert(substr_count($admin, 'INSERT INTO printflow_order_archive_batch_events') === 1 && !str_contains(substr($admin, strpos($admin, 'if ($action === \'archive_manifest\')'), strpos($admin, '} elseif ($action === \'restore\')') - strpos($admin, 'if ($action === \'archive_manifest\')')), 'INSERT INTO printflow_order_archive_events'), 'archive creates one batch audit event and no per-order archive events');
$assert(str_contains($helper, "'printflow_order_archive_batch_events'"), 'archive readiness requires the batch audit table');
$assert(str_contains($admin, "UPDATE printflow_order_archive_map SET status='restored'") && str_contains($admin, "'restore'") && str_contains($admin, 'printflow_order_archive_events'), 'restore updates only mappings and writes an audit event');
$assert(!preg_match('/\bDELETE\s+FROM\s+(orders|order_items|customers|provider_payments|inventory_transactions)|\bUPDATE\s+(orders|customers|provider_payments|inventory_transactions)\s+SET/i', $admin), 'archive actions preserve original transactional and master rows');
$assert(str_contains($admin, '25, 44, 288, 306') && !str_contains($admin, 'UPDATE customers') && !str_contains($admin, 'DELETE FROM customers'), 'protected customers are displayed and never mutated');

$assert(str_contains($adminOrders, 'printflow_order_archive_scope_sql') && str_contains($staffOrders, 'printflow_order_archive_scope_sql'), 'Admin and Staff order lists use archive scope');
$assert(str_contains($adminJobOrders, 'printflow_order_archive_exclusion_sql') && str_contains($staffJobOrders, 'printflow_order_archive_exclusion_sql'), 'Admin and Staff job-order lists and counts exclude archived parent orders');
$assert(str_contains($adminDashboard, 'printflow_order_archive_scope_sql') && str_contains($managerDashboard, 'printflow_order_archive_scope_sql') && str_contains($staffDashboard, 'printflow_order_archive_scope_sql'), 'Admin, Manager, and Staff dashboards use archive scope');
$assert(str_contains($customerOrders, 'printflow_order_archive_scope_sql') && str_contains($customerOrdersAll, 'printflow_order_archive_scope_sql') && str_contains($customerOrdersApi, 'printflow_order_archive_scope_sql'), 'customer order pages and API use archive scope');
$assert(str_contains($adminReports, 'printflow_order_archive_scope_sql') && str_contains($adminPrintReports, 'printflow_order_archive_scope_sql') && str_contains($staffReports, 'printflow_order_archive_scope_sql'), 'active reports use archive scope');
$assert(str_contains($staffReviews, 'printflow_order_archive_scope_sql'), 'order-linked reviews are hidden through their parent while unlinked reviews remain visible');
$assert(str_contains($notifications, 'printflow_order_archive_notification_exclusion_sql'), 'order notifications use verified direct order relationship scope');

if ($failures !== []) {
    fwrite(STDERR, "FAIL order_archive_safety_contract_test\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK order_archive_safety_contract_test\n";
